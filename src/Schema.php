<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use Change;
use CommonITILObject;
use Computer;
use Glpi\Cache\CacheManager;
use KnowbaseItem;
use Psr\SimpleCache\CacheInterface;
use Problem;
use Software;
use Ticket;
use User;

/**
 * What gets indexed, under what name, and how Meilisearch should treat it.
 *
 * One index per itemtype rather than one index with an `itemtype` filter. The
 * reason is `searchableAttributes`, which is an *ordered* list — earlier
 * attributes rank higher — and the right order genuinely differs by type. A
 * serial number is the most identifying thing a Computer has and means nothing
 * on a Ticket; a Ticket's long description is worth searching and would drown
 * a User whose whole document is four short fields. One index would force a
 * single order onto all of them, and that order would be wrong for most.
 *
 * Multi-search puts them back in one round trip, so the cost of the split is
 * paid at configuration time rather than at query time.
 */
final class Schema
{
    /**
     * Text fields per itemtype, most identifying first.
     *
     * The order is the ranking order. Anything not named here falls back to
     * {@see self::GENERIC}, which is the set of columns GLPI puts on almost
     * every asset.
     */
    private const FIELDS = [
        Ticket::class       => ['name', 'content'],
        Change::class       => ['name', 'content'],
        Problem::class      => ['name', 'content'],
        KnowbaseItem::class => ['name', 'answer'],
        // `email` is not a column on glpi_users — Documents assembles it from
        // glpi_useremails — but it belongs high in this list all the same,
        // because an address is how people actually look somebody up.
        User::class         => ['name', 'realname', 'firstname', 'email', 'phone', 'mobile', 'registration_number'],
        Computer::class     => ['name', 'serial', 'otherserial', 'contact', 'comment'],
        Software::class     => ['name', 'comment'],
    ];

    /** For every type without an entry above. */
    private const GENERIC = ['name', 'serial', 'otherserial', 'contact', 'comment'];

    /**
     * Types whose `entities_id` column is not what decides who may see them.
     *
     * Both of these have one, and on both it means something other than
     * visibility: a user's is their *default* entity, while what they may be
     * seen from comes out of `glpi_profiles_users`; a knowledge article's is
     * overridden by its own visibility rows, which can make it public, or
     * restricted to a profile or a group.
     *
     * Filtering these on `entities_id` would be too *narrow* — it would hide
     * records the viewer is entitled to see — and a filter that loses results
     * is as wrong as one that leaks them. So they are indexed unscoped and
     * every hit is settled by GLPI, which is the only thing that knows.
     */
    private const UNSCOPED = [User::class, KnowbaseItem::class];

    /** Entity id stored for a document the entity filter must not judge. */
    public const UNSCOPED_ENTITY = -1;

    private const EMBEDDER_CACHE_KEY = 'glpisearch:embedders';

    /** Long enough to stay off the keystroke path, short enough to notice. */
    private const EMBEDDER_CACHE_TTL = 300;

    /** @var array<string,string|null>|null discovered embedders, per request */
    private static ?array $embedders = null;

    /** @var array<string,string[]> facet attributes, per itemtype, per request */
    private static array $facets = [];

    /** @var string[]|null itemtypes whose names get copied into documents */
    private static ?array $referenced = null;

    /** Facet values returned per attribute. Sorted by count, so these are the top ones. */
    public const MAX_FACET_VALUES = 20;

    /**
     * Meilisearch's own ranking rules, in its own order.
     *
     * Written out rather than fetched so that adding a tiebreaker cannot
     * accidentally drop one of these — a settings push replaces the whole list,
     * and omitting `typo` would quietly turn off the feature this plugin exists
     * for.
     */
    private const DEFAULT_RANKING = [
        'words', 'typo', 'proximity', 'attributeRank', 'sort', 'wordPosition', 'exactness',
    ];

    /** Does this type's `entities_id` actually decide visibility? */
    public static function isEntityScoped(string $itemtype): bool
    {
        return !in_array($itemtype, self::UNSCOPED, true);
    }

    /**
     * A single document's text budget, in bytes.
     *
     * Meilisearch will happily take more. The limit is about ranking rather
     * than capacity: a 200KB ticket with forty quoted email replies matches
     * almost any query on sheer surface area, and pushes the short, precise
     * records people are actually looking for down the list.
     */
    public const MAX_TEXT = 8000;

    /**
     * Types beyond `globalsearch_types` that are worth offering.
     *
     * Core's list is what *its* global search covers, which is not the same
     * question as what is worth finding by name. Knowledge articles are the
     * clearest omission — somebody typing a symptom into the header box is
     * hoping for exactly one of these — and the rest are records an MSP refers
     * to constantly and currently cannot reach without knowing which menu they
     * live under.
     */
    private const EXTRA_TYPES = [
        'KnowbaseItem', 'Entity', 'Location', 'ITILCategory', 'TaskCategory',
        'SoftwareVersion', 'Ticket_Ticket', 'Item_DeviceSimcard',
        'Notification', 'SLA', 'OLA', 'RequestType', 'SolutionType',
        'DocumentCategory', 'Manufacturer', 'State', 'Network', 'Profile',
        'PlanningExternalEvent', 'Reminder', 'RSSFeed',
    ];

    /**
     * Every itemtype this plugin can index.
     *
     * The narrow list — core's own globally searchable types plus the few
     * obvious omissions — unless `index_all` is on, in which case it is every
     * concrete GLPI itemtype with a table and a name to search. That option
     * exists because "can I find it by typing part of its name" has no good
     * reason to be limited to thirty types, and the cost of an extra index that
     * nobody queries is a few hundred kilobytes.
     *
     * @return string[]
     */
    public static function indexable(): array
    {
        /** @var array<string,mixed> $CFG_GLPI */
        global $CFG_GLPI;

        if (Settings::flag('index_all')) {
            return self::everything();
        }

        $types = (array) ($CFG_GLPI['globalsearch_types'] ?? []);

        foreach (self::EXTRA_TYPES as $extra) {
            $types[] = $extra;
        }

        $out = [];
        foreach ($types as $itemtype) {
            if (is_string($itemtype) && class_exists($itemtype) && self::isIndexable($itemtype)) {
                $out[] = $itemtype;
            }
        }

        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * Every concrete itemtype with a table and something to search.
     *
     * Derived from what GLPI actually has rather than from a list here, so a
     * plugin that registers its own itemtypes gets indexed too without this
     * file knowing about it.
     *
     * @return string[]
     */
    private static function everything(): array
    {
        /** @var array<string,mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $candidates = [];

        // Everything core groups into a menu, plus the dropdowns and device
        // types, is a good approximation of "records a person refers to".
        foreach (['globalsearch_types', 'asset_types', 'itemdevices_types',
                  'helpdesk_visible_types', 'ticket_types', 'dictionnary_types',
                  'directconnect_types', 'contract_types', 'document_types',
                  'infocom_types', 'link_types', 'networkport_instantiations',
                  'project_asset_types', 'rackable_types', 'socket_types'] as $group) {
            foreach ((array) ($CFG_GLPI[$group] ?? []) as $itemtype) {
                $candidates[] = $itemtype;
            }
        }

        foreach ((array) ($CFG_GLPI['dropdowntypes'] ?? []) as $itemtype) {
            $candidates[] = $itemtype;
        }

        foreach (self::EXTRA_TYPES as $extra) {
            $candidates[] = $extra;
        }

        $out = [];
        foreach ($candidates as $itemtype) {
            if (is_string($itemtype) && class_exists($itemtype) && self::isIndexable($itemtype)) {
                $out[] = $itemtype;
            }
        }

        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * Can this type be indexed at all?
     *
     * A table it can be read from, a `name` column to search, and an id. An
     * itemtype failing any of those would produce documents with a title of
     * "Thing #4" and nothing to match on, which is worse than being absent.
     */
    private static function isIndexable(string $itemtype): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!is_subclass_of($itemtype, \CommonDBTM::class)) {
            return false;
        }

        try {
            $table = getTableForItemType($itemtype);
        } catch (\Throwable) {
            return false;
        }

        return $table !== '' && $DB->tableExists($table) && $DB->fieldExists($table, 'name');
    }

    /**
     * Every itemtype the indexed types point at.
     *
     * The set whose *names* end up copied into documents, which is what makes
     * renaming one of them a search problem. Wider than the indexed types
     * themselves and narrower than everything: nobody searches for a
     * Manufacturer by name, and every Computer carries one.
     *
     * @return string[]
     */
    public static function referenced(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$referenced !== null) {
            return self::$referenced;
        }

        $out = [];

        foreach (Settings::itemtypes() as $itemtype) {
            try {
                $table = getTableForItemType($itemtype);
            } catch (\Throwable) {
                continue;
            }

            if (!$DB->tableExists($table)) {
                continue;
            }

            foreach (array_keys($DB->listFields($table)) as $column) {
                if (preg_match('/_id(_.*)?$/', (string) $column) !== 1) {
                    continue;
                }

                if (in_array($column, ['entities_id'], true)) {
                    continue;
                }

                $target = getItemtypeForForeignKeyField((string) $column);

                if (is_string($target) && $target !== '' && class_exists($target)) {
                    $out[] = $target;
                }
            }
        }

        sort($out);

        return self::$referenced = array_values(array_unique($out));
    }

    /**
     * The Meilisearch index name for an itemtype.
     *
     * Prefixed, because one Meilisearch may serve more than one GLPI and two
     * instances quietly sharing a `ticket` index would show each other's
     * records — a failure that looks like a search bug and is a data leak.
     */
    public static function indexFor(string $itemtype): string
    {
        $prefix = trim(Settings::get('index_prefix'));
        $prefix = $prefix === '' ? 'glpi' : $prefix;

        // Meilisearch index uids are limited to letters, digits, - and _.
        $safe = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $itemtype) ?? '');

        return preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) . '_' . $safe;
    }

    /** The itemtype an index name came back as, or null if it is not ours. */
    public static function itemtypeFor(string $index): ?string
    {
        foreach (Settings::itemtypes() as $itemtype) {
            if (self::indexFor($itemtype) === $index) {
                return $itemtype;
            }
        }

        return null;
    }

    /** @return string[] the text columns indexed for this itemtype */
    public static function fieldsFor(string $itemtype): array
    {
        return self::FIELDS[$itemtype] ?? self::GENERIC;
    }

    /**
     * The searchable attributes for an itemtype, in ranking order.
     *
     * Order *is* ranking order in Meilisearch, and this is the order:
     *
     *  1. the record's own fields — a ticket whose title says "printer" beats
     *     one merely filed under a printer category, which is what somebody
     *     typing "printer" meant;
     *  2. what was written on it afterwards, which is where the answer usually
     *     is but is also the longest text and would otherwise dominate;
     *  3. the names of things it points at;
     *  4. `ref`, holding "#42" so a bare number finds the ticket — last,
     *     because it must never outrank a real word.
     *
     * Shared with {@see Visibility::searchableAttributes()}, which subtracts
     * from this list per session. Two definitions of "what is searchable" would
     * mean a session being restricted to attributes the index does not have.
     *
     * @return string[]
     */
    public static function searchableFor(string $itemtype): array
    {
        return array_values(array_unique(array_merge(
            self::fieldsFor($itemtype),
            self::timelineAttributes($itemtype),
            self::facetsFor($itemtype),
            ['ref']
        )));
    }

    /**
     * The timeline buckets an itemtype has, most readable first.
     *
     * Empty for anything that is not a ticket-like object. Declared as
     * searchable even where a particular session may not search them — what a
     * session is allowed to read is decided per query by
     * {@see Visibility::searchableAttributes()}, not by the index settings,
     * because the settings are shared by everybody.
     *
     * @return string[]
     */
    public static function timelineAttributes(string $itemtype): array
    {
        if (!is_subclass_of($itemtype, CommonITILObject::class)) {
            return [];
        }

        return [Documents::NOTES, Documents::NOTES_PRIVATE, Documents::TASKS_PRIVATE];
    }

    /**
     * The attributes worth filtering and counting by, for one itemtype.
     *
     * Derived from the table's own foreign keys rather than listed here, so a
     * type nobody anticipated still gets facets for whatever it points at, and
     * a GLPI that adds a column gets one more facet without this file changing.
     *
     * These are what {@see Documents::relations()} will have written, computed
     * the same way from the same columns — which is why the two must agree on
     * {@see Documents::attributeFor()} rather than each deriving a name.
     *
     * @return string[]
     */
    public static function facetsFor(string $itemtype): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (isset(self::$facets[$itemtype])) {
            return self::$facets[$itemtype];
        }

        $facets = [];

        try {
            $table = getTableForItemType($itemtype);
        } catch (\Throwable) {
            return self::$facets[$itemtype] = [];
        }

        if ($DB->tableExists($table)) {
            foreach (array_keys($DB->listFields($table)) as $column) {
                if (preg_match('/_id(_.*)?$/', (string) $column) !== 1) {
                    continue;
                }

                // The same exclusions the writer applies. Declaring a facet
                // for a column Documents drops would be a filter that matches
                // nothing, forever, with nothing to raise an error about it.
                if (in_array($column, Documents::SKIP_RELATIONS, true)) {
                    continue;
                }

                $attribute = Documents::attributeFor((string) $column);
                if ($attribute === '') {
                    continue;
                }

                $linked = getTableNameForForeignKeyField((string) $column);
                if ($linked === '' || !$DB->tableExists($linked)) {
                    continue;
                }

                $facets[] = $attribute;
            }
        }

        // The coded fields Documents::labels() resolves. Declared for every
        // type rather than only the ITIL ones: Meilisearch is content to have a
        // filterable attribute no document carries, and the alternative is this
        // list disagreeing with what was actually written.
        foreach (['status', 'priority', 'urgency', 'impact', 'type'] as $label) {
            $facets[] = $label;
        }

        if (is_subclass_of($itemtype, CommonITILObject::class)) {
            foreach (['requester', 'assignee', 'observer'] as $actor) {
                $facets[] = $actor;
            }
        }

        sort($facets);

        return self::$facets[$itemtype] = array_values(array_unique($facets));
    }

    /**
     * The index settings for one itemtype.
     *
     * @return array<string,mixed>
     */
    public static function settingsFor(string $itemtype): array
    {
        $related    = self::facetsFor($itemtype);
        $searchable = self::searchableFor($itemtype);

        // Ranked on, in this order: an open ticket beats a closed one, and
        // among equals the more recently touched wins. Both are tiebreakers —
        // see the ranking rules below.
        $sortable = is_subclass_of($itemtype, CommonITILObject::class)
            ? [Documents::IS_OPEN, 'date_mod']
            : ['date_mod'];

        $settings = [
            'searchableAttributes' => $searchable,

            // Everything the caller needs to build a result row without going
            // back to the database — and nothing else. A document that carried
            // the whole record would make the index a second copy of GLPI to
            // keep in step and to delete on request.
            'displayedAttributes'  => [
                'id', 'itemtype', 'items_id', 'entities_id', 'is_recursive',
                'title', 'subtitle', 'ref',
            ],

            // The three the visibility filter is built from, plus everything
            // worth counting. Meilisearch refuses to filter on anything not
            // named here, which is a useful property: a filter that silently
            // did nothing would be a leak rather than a bug.
            'filterableAttributes' => array_values(array_unique(array_merge(
                ['itemtype', 'entities_id', 'is_recursive'],
                $related
            ))),

            'sortableAttributes'   => $sortable,

            // Meilisearch's defaults, then two tiebreakers of our own.
            //
            // Appended rather than inserted, and that is the whole design: text
            // relevance still decides, and these only separate results the
            // engine considers equally good. Putting recency above relevance
            // produces a search that cannot find last year's answer, which is
            // usually the one somebody is looking for.
            'rankingRules' => array_merge(self::DEFAULT_RANKING, array_map(
                static fn(string $attribute): string => $attribute . ':desc',
                $sortable
            )),

            'synonyms' => Settings::synonyms(),

            'faceting' => [
                // Enough to draw a facet list without paging it, and low enough
                // that a high-cardinality field — every requester in the
                // instance — cannot turn one search into a megabyte of counts.
                'maxValuesPerFacet' => self::MAX_FACET_VALUES,
                'sortFacetValuesBy' => ['*' => 'count'],
            ],

            'typoTolerance' => [
                'enabled'             => true,
                'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                // A serial number one character out is a different machine, and
                // an asset tag one character out is a different asset. Typo
                // tolerance is a help on prose and a hazard on identifiers.
                'disableOnAttributes' => array_values(array_intersect(
                    $searchable,
                    ['serial', 'otherserial', 'registration_number', 'ref']
                )),
                'disableOnNumbers'    => true,
            ],
        ];

        // Note what is *not* here: `embedders`. A settings push that carried
        // one would overwrite whatever the operator configured on the server,
        // and this plugin has no business having an opinion about a model it
        // does not call. Omitting the key leaves an existing embedder alone —
        // Meilisearch's PATCH only touches what it is given.
        return $settings;
    }

    /**
     * The embedder to search an index with, or null for keyword only.
     *
     * If an index has an embedder, searches against it are hybrid. There is no
     * switch: an embedder that exists is an embedder somebody configured and
     * paid to run over their corpus, and a plugin that ignored it until told
     * twice would be a plugin whose semantic search silently does not work.
     *
     * Meilisearch has no index-level default for hybrid, so the parameter has
     * to be sent per query — which is why this exists at all rather than the
     * server simply doing it.
     *
     * Cached, because this would otherwise be an extra round trip per index per
     * keystroke, and the answer changes when an administrator reconfigures
     * Meilisearch — which is to say, almost never.
     */
    public static function embedderFor(string $itemtype): ?string
    {
        $known = self::embedders();

        return $known[$itemtype] ?? null;
    }

    /**
     * Every index's embedder, by itemtype. Empty string means "none".
     *
     * @return array<string,string|null>
     */
    private static function embedders(): array
    {
        if (self::$embedders !== null) {
            return self::$embedders;
        }

        $cache  = self::cache();
        $cached = $cache?->get(self::EMBEDDER_CACHE_KEY);

        if (is_array($cached)) {
            return self::$embedders = $cached;
        }

        $client = Client::fromSettings();
        if ($client === null) {
            return self::$embedders = [];
        }

        $found = [];

        foreach (Settings::itemtypes() as $itemtype) {
            try {
                $configured = $client->embedders(self::indexFor($itemtype));
            } catch (SearchException) {
                // Unreachable. Deliberately not cached as "none": a Meilisearch
                // that comes back up should resume hybrid search on the next
                // request, not five minutes later.
                return self::$embedders = [];
            }

            if ($configured === []) {
                continue;
            }

            // `default` wins when it is there, otherwise the first by name — so
            // that two indexes with the same embedders always resolve the same
            // way, rather than depending on hash order.
            $names = array_keys($configured);
            sort($names);

            $found[$itemtype] = in_array('default', $names, true) ? 'default' : $names[0];
        }

        $cache?->set(self::EMBEDDER_CACHE_KEY, $found, self::EMBEDDER_CACHE_TTL);

        return self::$embedders = $found;
    }

    /** Forget the discovered embedders. Called when the configuration changes. */
    public static function forgetEmbedders(): void
    {
        self::$embedders = null;
        self::cache()?->delete(self::EMBEDDER_CACHE_KEY);
    }

    private static function cache(): ?CacheInterface
    {
        try {
            return (new CacheManager())->getCacheInstance('plugin:glpisearch');
        } catch (\Throwable) {
            // A GLPI without a usable cache backend still has to search.
            return null;
        }
    }
}
