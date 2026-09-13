<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use CommonDBTM;
use CommonITILActor;
use CommonITILObject;
use Dropdown;
use Glpi\RichText\RichText;
use Toolbox;
use User;

/**
 * Turning a GLPI record into a Meilisearch document.
 *
 * A document carries two different kinds of field and they are not the same
 * job. The raw columns — `name`, `content`, `serial` — are what gets *searched*,
 * in the order {@see Schema} ranks them. `title` and `subtitle` are what gets
 * *shown*, already resolved and already plain text, so that rendering a result
 * needs no second trip to the database.
 *
 * Nothing else goes in. It would be easy to put the whole record in the
 * document and never load the object again, and that would make the index a
 * second copy of GLPI: one to keep in step, one to reason about when somebody
 * asks what is stored about them, and one to get wrong.
 */
final class Documents
{
    /** Timeline text everyone who can see the record may read. */
    public const NOTES = 'notes';

    /** Followups marked private: needs ITILFollowup::SEEPRIVATE. */
    public const NOTES_PRIVATE = 'notes_private';

    /** Tasks marked private: needs CommonITILTask::SEEPRIVATE, a different right. */
    public const TASKS_PRIVATE = 'tasks_private';

    /** Per-bucket ceiling on timeline text. */
    public const MAX_TIMELINE = 20000;

    /** 1 while a ticket-like record is still open. Ranked on, not searched. */
    public const IS_OPEN = 'is_open';

    /**
     * Foreign keys that are bookkeeping rather than meaning.
     *
     * `entities_id` is already carried as a number for the visibility filter,
     * and the rest are audit columns: who last touched the record is not what
     * anybody is searching for, and indexing them would put the same handful of
     * administrator names on every document in the instance.
     *
     * Public because {@see Schema::facetsFor()} has to skip exactly the same
     * columns. The two derive their attribute names from the same table, and a
     * facet declared for a column this one drops would be a filter that matches
     * nothing, forever, with no error to notice.
     */
    public const SKIP_RELATIONS = [
        'entities_id',
        'users_id_lastupdater',
        'users_id_editor',
        'users_id_recipient',
    ];

    /**
     * The document for one record, or null if it should not be indexed.
     *
     * @return array<string,mixed>|null
     */
    public static function forItem(CommonDBTM $item): ?array
    {
        $itemtype = $item::class;
        $id       = (int) $item->getID();

        if ($id <= 0) {
            return null;
        }

        // A template is a form with a name, not a record anybody is searching
        // for, and the trash is where GLPI's own search stops looking.
        if ((int) ($item->fields['is_template'] ?? 0) === 1) {
            return null;
        }
        if ((int) ($item->fields['is_deleted'] ?? 0) === 1) {
            return null;
        }

        $document = [
            'id'           => self::idFor($itemtype, $id),
            'itemtype'     => $itemtype,
            'items_id'     => $id,
            'entities_id'  => Schema::isEntityScoped($itemtype)
                ? (int) ($item->fields['entities_id'] ?? 0)
                : Schema::UNSCOPED_ENTITY,
            'is_recursive' => (int) ($item->fields['is_recursive'] ?? 0),
            'ref'          => '#' . $id,
            'date_mod'     => strtotime((string) ($item->fields['date_mod'] ?? '')) ?: 0,
        ];

        $budget = Schema::MAX_TEXT;

        foreach (Schema::fieldsFor($itemtype) as $field) {
            $raw = $item->fields[$field] ?? null;
            if (!is_string($raw) && !is_int($raw)) {
                continue;
            }

            $text = self::plain((string) $raw);
            if ($text === '') {
                continue;
            }

            // Spent in field order, so a long description cannot crowd out a
            // serial number that comes after it.
            $text   = Toolbox::substr($text, 0, max(0, $budget));
            $budget -= strlen($text);

            $document[$field] = $text;
        }

        // Everything the record points at, resolved to names. This is what
        // makes "sarah chen" find her ticket and what gives the facets
        // something to count — see the note on relations() for why it is done
        // here rather than with a join.
        $relations = self::relations($item);

        foreach ($relations as $attribute => $value) {
            $document[$attribute] = $value;
        }

        foreach (self::labels($item, $itemtype) as $attribute => $value) {
            $document[$attribute] = $value;
        }

        if ($item instanceof CommonITILObject) {
            foreach (self::actors($item) as $attribute => $value) {
                $document[$attribute] = $value;
            }
        }

        if ($itemtype === User::class) {
            $emails = self::emails($id);
            if ($emails !== []) {
                $document['email'] = $emails;
            }
        }

        if ($item instanceof CommonITILObject) {
            foreach (self::timeline($item) as $attribute => $text) {
                $document[$attribute] = $text;
            }
        }

        $document['title']    = self::title($item, $itemtype, $id);
        $document['subtitle'] = self::subtitle($item, $itemtype, $relations);

        return $document;
    }

    /**
     * Foreign keys, resolved to the names they point at.
     *
     * Denormalised rather than joined, and that is a decision worth recording
     * because Meilisearch does now have joins. They were measured against this
     * exact case and they do two of the four things needed:
     *
     *   - hydration works — a related document appears inline in results;
     *   - `_foreign()` filtering works;
     *   - **the joined text is not searchable** — with a requester joined onto
     *     a ticket, searching that requester's name finds nothing;
     *   - **the joined fields are not facetable** — `facets` over a joined
     *     attribute returns an empty distribution.
     *
     * The last two are most of what anyone wants from search over a helpdesk:
     * find the ticket by the name of the person who raised it, and count
     * tickets by category. Both need the value *in* the document, so it is put
     * there — and once it is there, a join would add nothing except a second
     * mechanism, an experimental feature flag, and one index per dropdown
     * table.
     *
     * The cost is staleness: renaming a category leaves every ticket carrying
     * the old name until it is re-indexed. That is what the dropdown hooks in
     * hook.php are for.
     *
     * @return array<string,string>
     */
    private static function relations(CommonDBTM $item): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];

        foreach ($item->fields as $field => $value) {
            if (preg_match('/_id(_.*)?$/', (string) $field) !== 1) {
                continue;
            }

            if (in_array($field, self::SKIP_RELATIONS, true)) {
                continue;
            }

            if (!is_numeric($value) || (int) $value <= 0) {
                continue;
            }

            $table = getTableNameForForeignKeyField((string) $field);
            if ($table === '' || !$DB->tableExists($table)) {
                continue;
            }

            $name = self::dropdownName($table, (int) $value);
            if ($name === '') {
                continue;
            }

            $out[self::attributeFor((string) $field)] = $name;
        }

        return $out;
    }

    /**
     * The attribute a foreign key is stored under.
     *
     * Derived from the *column*, not from the itemtype it points at, because
     * two columns routinely point at the same table — a Computer has both
     * `users_id` and `users_id_tech` — and naming them after the target would
     * silently collapse the owner and the technician into one field.
     */
    public static function attributeFor(string $field): string
    {
        return (string) preg_replace('/_id(_.*)?$/', '$1', $field);
    }

    /**
     * A dropdown's display name, memoised for the run.
     *
     * A first index resolves the same few hundred categories, locations and
     * models across tens of thousands of records. Without this that is one
     * query per foreign key per document; with it, it is one per distinct
     * value.
     *
     * @var array<string,string>
     */
    private static array $names = [];

    private static function dropdownName(string $table, int $id): string
    {
        $key = $table . '#' . $id;

        if (isset(self::$names[$key])) {
            return self::$names[$key];
        }

        $name = Dropdown::getDropdownName($table, $id);
        $name = is_string($name) ? trim($name) : '';

        // GLPI returns a non-breaking space for "nothing", which would
        // otherwise become a facet value that every record shares.
        if ($name === '&nbsp;' || $name === '-') {
            $name = '';
        }

        return self::$names[$key] = $name;
    }

    /** Forget the memoised dropdown names. */
    public static function forgetNames(): void
    {
        self::$names = [];
    }

    /**
     * Coded fields, as the words a human would search for.
     *
     * A status of `2` is not something anybody types. Storing the label makes
     * it both searchable and facetable, which is the difference between a
     * filter somebody has to learn and one they can see.
     *
     * @return array<string,string|int>
     */
    private static function labels(CommonDBTM $item, string $itemtype): array
    {
        $out = [];

        if (is_subclass_of($itemtype, CommonITILObject::class)) {
            if (isset($item->fields['status'])) {
                $out['status'] = (string) $itemtype::getStatus((int) $item->fields['status']);
            }

            foreach (['priority', 'urgency', 'impact'] as $field) {
                if (isset($item->fields[$field])) {
                    $out[$field] = (string) CommonITILObject::getPriorityName((int) $item->fields[$field]);
                }
            }

            if (isset($item->fields['type']) && method_exists($itemtype, 'getTicketTypeName')) {
                $out['type'] = (string) $itemtype::getTicketTypeName((int) $item->fields['type']);
            }
        }

        if (is_subclass_of($itemtype, CommonITILObject::class) && isset($item->fields['status'])) {
            // A number, because ranking rules sort and cannot read a label.
            // Still open is the only distinction worth making here: a
            // technician looking something up almost always wants the live one,
            // and finer gradations than that start encoding opinions about
            // workflow that are not this plugin's to hold.
            $out[self::IS_OPEN] = in_array(
                (int) $item->fields['status'],
                $itemtype::getClosedStatusArray(),
                true
            ) ? 0 : 1;
        }

        if (isset($item->fields['is_deleted'])) {
            // Not indexed when deleted, so this is always "no" — but declaring
            // it keeps the attribute list stable across types.
            $out['trashed'] = ((int) $item->fields['is_deleted']) === 1 ? 'yes' : 'no';
        }

        return $out;
    }

    /**
     * Who is on a ticket: requesters, assignees, observers, and their groups.
     *
     * The single most valuable thing to denormalise. "Find Sarah's printer
     * ticket" is how people actually search, and the requester is not a column
     * on the ticket — it is a row in a link table, which is exactly the shape
     * no search engine can reach on its own.
     *
     * @return array<string,string[]>
     */
    private static function actors(CommonITILObject $item): array
    {
        $roles = [
            CommonITILActor::REQUESTER => 'requester',
            CommonITILActor::ASSIGN    => 'assignee',
            CommonITILActor::OBSERVER  => 'observer',
        ];

        $out = [];

        foreach ($roles as $role => $label) {
            $names = [];

            foreach ($item->getUsers($role) as $row) {
                $name = self::dropdownName('glpi_users', (int) ($row['users_id'] ?? 0));
                if ($name !== '') {
                    $names[] = $name;
                }
                // An unregistered requester leaves an email rather than a user.
                $alt = trim((string) ($row['alternative_email'] ?? ''));
                if ($alt !== '') {
                    $names[] = $alt;
                }
            }

            foreach ($item->getGroups($role) as $row) {
                $name = self::dropdownName('glpi_groups', (int) ($row['groups_id'] ?? 0));
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            if ($names !== []) {
                // An array, so that each actor is a separate facet value rather
                // than one string nobody can count.
                $out[$label] = array_values(array_unique($names));
            }
        }

        return $out;
    }

    /**
     * The document id.
     *
     * Meilisearch primary keys allow letters, digits, hyphens and underscores
     * only, which rules out a class name with backslashes — so plugin itemtypes
     * are flattened rather than passed through. The pair still has to be unique
     * across everything in one index, and since an index only ever holds one
     * itemtype, the id alone would do; the type is kept in for legibility when
     * somebody is reading raw documents at three in the morning.
     */
    public static function idFor(string $itemtype, int $items_id): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $itemtype) . '-' . $items_id;
    }

    private static function title(CommonDBTM $item, string $itemtype, int $id): string
    {
        $name = trim((string) ($item->fields['name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        // getFriendlyName() is what GLPI shows for a record with no name of its
        // own — a user's real name, an asset's serial.
        $friendly = trim((string) $item->getFriendlyName());

        return $friendly !== '' ? $friendly : sprintf('%s #%d', $itemtype::getTypeName(1), $id);
    }

    /**
     * The line under the title: enough to tell two similar records apart.
     *
     * Status and entity, which is the pair that disambiguates in practice —
     * "Printer offline" for three different entities is the case this exists
     * for.
     */
    /**
     * @param array<string,string> $relations already-resolved names, to avoid
     *                                         asking the database twice
     */
    private static function subtitle(CommonDBTM $item, string $itemtype, array $relations): string
    {
        $bits = [];

        if (is_subclass_of($itemtype, CommonITILObject::class) && isset($item->fields['status'])) {
            $bits[] = (string) $itemtype::getStatus((int) $item->fields['status']);
        }

        // The entity, but only where the entity is what governs the record.
        //
        // This used to print `entities_id` for everything, which was wrong in
        // exactly the cases {@see Schema::UNSCOPED_ENTITY} exists for. On a
        // User that column is the *default entity for records they create* —
        // not where they belong, and not what they can see, both of which come
        // out of `glpi_profiles_users`. A user whose profile is on the root
        // entity and whose default is an entity's would be displayed as
        // belonging to that entity, which is a claim nobody made.
        //
        // The rule is the same one the filter uses: if the column is not good
        // enough to decide who may see the record, it is not good enough to
        // print underneath it either.
        if (Schema::isEntityScoped($itemtype)) {
            $entity = self::dropdownName('glpi_entities', (int) ($item->fields['entities_id'] ?? 0));
            if ($entity !== '') {
                $bits[] = $entity;
            }
        } else {
            foreach (self::identifying($item, $itemtype, $relations) as $bit) {
                $bits[] = $bit;
            }
        }

        return implode(' · ', $bits);
    }

    /**
     * Everything written on a ticket after it was opened.
     *
     * This is where the answer usually is. On the instance this was built
     * against, ticket titles and descriptions came to 3,200 characters and the
     * followups on those same tickets came to 25,000 — eight times as much, and
     * none of it was searchable. "It was the stale DFS referral" is written in a
     * followup, never in the description, because nobody knows the cause when
     * they open the ticket.
     *
     * Split into three buckets rather than one, because they are not equally
     * visible. A private followup is readable only by a technician holding
     * `SEEPRIVATE`, and folding it in with the public text would let somebody
     * without that right find a ticket by words they are not allowed to read —
     * which tells them the phrase exists on that ticket, and is precisely the
     * kind of leak that looks like the search working. {@see Finder} restricts
     * which of these are searched, per session.
     *
     * @return array<string,string>
     */
    private static function timeline(CommonITILObject $item): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $id      = (int) $item->getID();
        $public  = [];
        $private = [];
        $tasks   = [];

        foreach (
            $DB->request([
                'SELECT' => ['content', 'is_private'],
                'FROM'   => 'glpi_itilfollowups',
                'WHERE'  => ['itemtype' => $item::class, 'items_id' => $id],
                'ORDER'  => 'date_creation ASC',
            ]) as $row
        ) {
            $text = self::plain((string) $row['content']);
            if ($text === '') {
                continue;
            }

            if ((int) $row['is_private'] === 1) {
                $private[] = $text;
            } else {
                $public[] = $text;
            }
        }

        $task_table = $item::class . 'Task';
        if (class_exists($task_table)) {
            $task_table = $task_table::getTable();

            if ($DB->tableExists($task_table)) {
                $fk = getForeignKeyFieldForItemType($item::class);

                foreach (
                    $DB->request([
                        'SELECT' => ['content', 'is_private'],
                        'FROM'   => $task_table,
                        'WHERE'  => [$fk => $id],
                        'ORDER'  => 'date_creation ASC',
                    ]) as $row
                ) {
                    $text = self::plain((string) $row['content']);
                    if ($text === '') {
                        continue;
                    }

                    // Private tasks are gated on a *different* right from
                    // private followups, so they get their own bucket rather
                    // than being lumped in with them.
                    if ((int) $row['is_private'] === 1) {
                        $tasks[] = $text;
                    } else {
                        $public[] = $text;
                    }
                }
            }
        }

        // Solutions have no private flag — a solution is the answer given, and
        // GLPI shows it to whoever can see the ticket.
        foreach (
            $DB->request([
                'SELECT' => ['content'],
                'FROM'   => 'glpi_itilsolutions',
                'WHERE'  => ['itemtype' => $item::class, 'items_id' => $id],
                'ORDER'  => 'date_creation ASC',
            ]) as $row
        ) {
            $text = self::plain((string) $row['content']);
            if ($text !== '') {
                $public[] = $text;
            }
        }

        $out = [];

        foreach ([
            self::NOTES         => $public,
            self::NOTES_PRIVATE => $private,
            self::TASKS_PRIVATE => $tasks,
        ] as $attribute => $parts) {
            if ($parts === []) {
                continue;
            }

            // Budgeted separately from the record's own fields, and generously:
            // a long thread is exactly the case this exists to serve, and
            // truncating it to the same allowance as a title would throw away
            // the part with the answer in it.
            $out[$attribute] = Toolbox::substr(implode(' ', $parts), 0, self::MAX_TIMELINE);
        }

        return $out;
    }

    /**
     * A user's email addresses.
     *
     * Not a column — GLPI keeps them in their own table, one row per address —
     * which is why nothing above picks them up and why they were missing
     * entirely until somebody noticed a user had nothing under their name. It
     * is also, in practice, how people search for a user: the address is what
     * appears in the mail client they are looking at when they come to raise
     * the ticket.
     *
     * @return string[] the default address first
     */
    private static function emails(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_useremails')) {
            return [];
        }

        $out = [];

        foreach (
            $DB->request([
                'SELECT' => ['email'],
                'FROM'   => 'glpi_useremails',
                'WHERE'  => ['users_id' => $users_id],
                'ORDER'  => 'is_default DESC',
            ]) as $row
        ) {
            $email = trim((string) $row['email']);
            if ($email !== '') {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * What tells two records of an unscoped type apart, when the entity cannot.
     *
     * A subtitle earns its place by disambiguating — three results called
     * "Printer offline" need something under them. For the entity-scoped types
     * the entity is that something; for these it has to be found elsewhere.
     *
     * @param array<string,string> $relations
     * @return string[]
     */
    private static function identifying(CommonDBTM $item, string $itemtype, array $relations): array
    {
        $bits = [];

        if ($itemtype === User::class) {
            // The human name, when the title is a login — and the login when
            // the title is already the human name. Either way the subtitle
            // carries the half the title does not.
            $real = trim(implode(' ', array_filter([
                (string) ($item->fields['realname'] ?? ''),
                (string) ($item->fields['firstname'] ?? ''),
            ])));

            $login = trim((string) ($item->fields['name'] ?? ''));

            if ($real !== '' && $real !== $login) {
                $bits[] = $real;
            } elseif ($login !== '' && $real !== '') {
                $bits[] = $login;
            }

            // The address, when there is nothing better. For most directories
            // this is the only thing that distinguishes two people with the
            // same name, and it is what somebody is usually reading when they
            // come to look the person up.
            $emails = self::emails((int) $item->getID());
            if ($emails !== [] && !in_array($emails[0], $bits, true)) {
                $bits[] = $emails[0];
            }
        }

        // Where they are is the next most useful thing, and it is already
        // resolved — a location is a foreign key like any other.
        foreach (['locations', 'usertitles', 'groups'] as $attribute) {
            if (($relations[$attribute] ?? '') !== '') {
                $bits[] = $relations[$attribute];
                break;
            }
        }

        return $bits;
    }

    /**
     * HTML and entities out, one line in.
     *
     * GLPI stores rich text for ticket descriptions and knowledge articles.
     * Indexing the markup would make `<div class="rte">` a searchable term on
     * every ticket, which is both useless and slightly ruinous for ranking.
     */
    private static function plain(string $html): string
    {
        $text = RichText::getTextFromHtml($html, true, false);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
