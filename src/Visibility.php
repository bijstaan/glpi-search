<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use CommonDBTM;
use CommonITILObject;
use Session;

/**
 * Who may see what — filtered cheaply in Meilisearch, decided in GLPI.
 *
 * This is the part of the plugin that has to be right. A search engine sitting
 * beside GLPI knows nothing about profiles, about a technician who may only see
 * tickets they are assigned to, or about a knowledge article restricted to one
 * group. If the index were allowed to have the last word, every one of those
 * would become a record shown to somebody not entitled to it — and it would
 * look like a feature working, not like a bug.
 *
 * So the work is split by what each side is good at:
 *
 *  - **Meilisearch filters on entity**, which is a numeric comparison it does
 *    for free and which removes almost everything on a real multi-tenant
 *    instance. It is a performance filter. It is not a permission check.
 *  - **GLPI decides**, by loading each surviving candidate and asking
 *    `canViewItem()` — the same method the record's own page calls. Anything it
 *    says no to is dropped, whatever the index thought.
 *
 * The cost of being right is one primary-key read per hit shown. Candidates are
 * walked in score order and the walk stops as soon as enough have survived, so
 * the usual case — a technician who can see what they searched for — costs
 * exactly as many reads as there are rows on screen.
 */
final class Visibility
{
    /**
     * How many candidates to ask for, per row eventually shown.
     *
     * Only matters when the viewer cannot see most of what matched. Too low and
     * a restricted technician gets a short list with more results sitting
     * unshown behind it; too high and every search drags documents nobody will
     * ever be allowed to see across the network.
     */
    public const OVERFETCH = 4;

    /**
     * The Meilisearch filter expression for the current user, or null when the
     * search should not run at all.
     *
     * Null rather than an empty filter, deliberately: an empty filter means
     * "everything", and a session with no active entity would go from seeing
     * nothing to seeing all of it.
     */
    public static function filterFor(string $itemtype): ?string
    {
        if (!Schema::isEntityScoped($itemtype)) {
            // Nothing useful to pre-filter on. Every hit goes to GLPI.
            return sprintf('entities_id = %d', Schema::UNSCOPED_ENTITY);
        }

        $active = self::ids('glpiactiveentities');

        if ($active === []) {
            return null;
        }

        $clauses = [sprintf('entities_id IN [%s]', implode(', ', $active))];

        // An item marked recursive is visible from its entity *and* everything
        // below it. GLPI expresses that by also matching the ancestors of the
        // entities the session is active in, which is what `glpiparententities`
        // holds and why this clause exists rather than only the one above.
        $parents = self::ids('glpiparententities');
        if ($parents !== []) {
            $clauses[] = sprintf('(is_recursive = 1 AND entities_id IN [%s])', implode(', ', $parents));
        }

        return implode(' OR ', $clauses);
    }

    /**
     * May this session be handed facet counts for records {@see keep()} has
     * not vetted?
     *
     * Facet distributions come back from Meilisearch counted *before* the
     * rights check, and a distribution is not only numbers: the value is the
     * key, so it is a requester's login, a location, a group name, a
     * supervisor. Whether that discloses anything depends entirely on whether
     * the entity pre-filter and GLPI's answer agree for this session:
     *
     *  - They agree when the session sees everything its entities hold. Then
     *    the counts describe exactly the records it could have listed anyway.
     *  - They disagree when its rights are narrower than its entities — a
     *    technician on READMY rather than READALL, an assignable asset it
     *    neither owns nor is assigned. Then the counts name people and places
     *    attached to records it may not open, and the row list's silence about
     *    them is undone by the sidebar.
     *
     * Unscoped types are always false. Their filter is `entities_id = -1`,
     * which matches the whole index — there is no entity half to fall back on,
     * so their facets would be counted across every tenant in the instance.
     */
    public static function mayCountAll(string $itemtype): bool
    {
        if (!Schema::isEntityScoped($itemtype)) {
            return false;
        }

        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM) {
            return false;
        }

        $right = (string) ($item::$rightname ?? '');
        if ($right === '') {
            return false;
        }

        // READALL is the ITIL right that means "every ticket in the entity",
        // as opposed to READMY/READGROUP which are the narrower ones the row
        // list would enforce per record.
        if (is_subclass_of($itemtype, CommonITILObject::class)) {
            return (bool) Session::haveRight($right, CommonITILObject::READALL);
        }

        // Elsewhere plain READ is the "all of it" right: the narrower ones are
        // READ_ASSIGNED / READ_OWNED, which AssignableItem falls back to only
        // when READ is absent.
        return (bool) Session::haveRight($right, READ);
    }

    /**
     * Drop every hit the current user may not see, keeping score order.
     *
     * @param array<int,array<string,mixed>> $hits Meilisearch hits, best first
     * @return array<int,array<string,mixed>> at most $limit, still best first
     */
    public static function keep(string $itemtype, array $hits, int $limit): array
    {
        $item = getItemForItemtype($itemtype);

        if (!$item instanceof CommonDBTM || !$item->canView()) {
            // No right to the type at all. Nothing below this could rescue it.
            return [];
        }

        $kept = [];

        foreach ($hits as $hit) {
            if (count($kept) >= $limit) {
                break;
            }

            $items_id = (int) ($hit['items_id'] ?? 0);
            if ($items_id <= 0) {
                continue;
            }

            // A document whose record has gone is a document the queue has not
            // caught up with yet. Silently skipping it is right: the index is
            // eventually consistent by design, and the alternative is showing a
            // row that 404s when clicked.
            if (!$item->getFromDB($items_id)) {
                continue;
            }

            // The authority. Everything above this line is an optimisation.
            if (!$item->canViewItem()) {
                continue;
            }

            $kept[] = $hit + ['url' => $item->getLinkURL()];
        }

        return $kept;
    }

    /**
     * The attributes this session is allowed to match against.
     *
     * Null means "no restriction", which is the common case and the one worth
     * keeping cheap — a technician holding both private rights searches
     * everything and Meilisearch is given no extra work.
     *
     * The restriction exists because a ticket's private followups are readable
     * only with `SEEPRIVATE`, and private tasks are gated on a *different*
     * right again. Without this, somebody lacking those rights could find a
     * ticket by a phrase that appears nowhere they are allowed to read — which
     * does not show them the text, but does tell them it exists on that ticket,
     * and is the sort of leak that reads as the search simply working well.
     *
     * Note this is the one place where the index is trusted with something
     * closer to a permission than a hint. It is safe because it can only ever
     * *narrow* what is matched: the record still has to survive
     * {@see self::keep()}, and a bug here costs recall rather than privacy.
     *
     * @return string[]|null
     */
    public static function searchableAttributes(string $itemtype): ?array
    {
        $timeline = Schema::timelineAttributes($itemtype);

        if ($timeline === []) {
            return null;
        }

        $blocked = [];

        if (!Session::haveRight(\ITILFollowup::$rightname, \ITILFollowup::SEEPRIVATE)) {
            $blocked[] = Documents::NOTES_PRIVATE;
        }

        if (!Session::haveRight(\TicketTask::$rightname, \CommonITILTask::SEEPRIVATE)) {
            $blocked[] = Documents::TASKS_PRIVATE;
        }

        if ($blocked === []) {
            return null;
        }

        // Everything the index declares as searchable, minus what this session
        // may not read. Spelled out rather than expressed as an exclusion
        // because Meilisearch takes a list of what to search, not what to skip.
        $allowed = array_values(array_diff(
            Schema::searchableFor($itemtype),
            $blocked
        ));

        return $allowed === [] ? null : $allowed;
    }

    /** @return int[] a session entity list, cleaned of anything non-numeric */
    private static function ids(string $key): array
    {
        $raw = $_SESSION[$key] ?? [];

        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $out[] = (int) $value;
            }
        }

        return array_values(array_unique($out));
    }
}
