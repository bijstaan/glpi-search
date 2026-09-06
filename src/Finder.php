<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use CommonDBTM;

/**
 * The search API. Everything that shows results goes through here.
 *
 * One round trip to Meilisearch for every itemtype at once, then GLPI's own
 * rights check over the candidates. The grouped shape it returns is the one
 * both consumers want — the header dropdown and glpi-palette each render
 * results under a heading per type — which is also why the multi-search is not
 * federated: merging everything into one ranked list would only mean taking it
 * apart again.
 */
final class Finder
{
    /** Is there any point calling {@see self::search()}? */
    public static function isAvailable(): bool
    {
        return Settings::isLive() && Settings::itemtypes() !== [];
    }

    /**
     * Search several itemtypes at once.
     *
     * Returns an empty array rather than throwing when Meilisearch is
     * unreachable. That is the whole contract with the callers: a search box
     * whose backend is down falls back to what GLPI shipped with, and the
     * technician gets slower results rather than an error page.
     *
     * `$degraded` is how a caller tells the two empty results apart. "No
     * matches" and "the backend is gone" are both an empty array, and a caller
     * that cannot distinguish them will either show nothing when it could have
     * fallen back, or fall back pointlessly on every query that genuinely
     * matched nothing. It is also set when this refuses to search at all — a
     * query below the minimum length is not an answer either.
     *
     * `$facetFilters` narrows by the values a facet reported — `['status' =>
     * ['New', 'Assigned']]` — and `$facets` comes back holding what each
     * remaining facet would count. Values within one attribute are OR'd and
     * different attributes are AND'd, which is what every faceted search in
     * the world does and therefore what people expect without being told.
     *
     * @param string[] $itemtypes empty means "everything configured"
     * @param bool|null $degraded set true when the result is not an answer
     * @param array<string,string[]> $facetFilters
     * @param array<string,array<string,int>>|null $facets counts, per attribute
     * @return array<int,array{itemtype:string,type_label:string,icon:string,items:array<int,array<string,mixed>>}>
     */
    public static function search(
        string $term,
        array $itemtypes = [],
        int $perType = 5,
        ?bool &$degraded = null,
        array $facetFilters = [],
        ?array &$facets = null
    ): array {
        $degraded = false;
        $facets   = [];

        $term = trim($term);

        if (!self::isAvailable()) {
            $degraded = true;

            return [];
        }

        // A facet filter is a query in its own right. "Every open ticket in
        // this category" is a perfectly good thing to ask for and contains no
        // words at all, so the minimum length applies to typing, not to asking.
        $browsing = $facetFilters !== [];

        if (!$browsing && $term === '') {
            $degraded = true;

            return [];
        }

        if (!$browsing && mb_strlen($term) < max(1, (int) Settings::get('min_chars'))) {
            $degraded = true;

            return [];
        }

        $client = Client::fromSettings();
        if ($client === null) {
            $degraded = true;

            return [];
        }

        $configured = Settings::itemtypes();
        $wanted     = $itemtypes === []
            ? $configured
            : array_values(array_intersect($itemtypes, $configured));

        $perType   = max(1, $perType);
        $overfetch = max(1, (int) Settings::get('overfetch'));

        // Built alongside the queries rather than looked up afterwards: the
        // response comes back with an `indexUid`, and mapping that back to an
        // itemtype by re-deriving index names would break the moment somebody
        // changed the prefix between the request and the response.
        // Each entry describes one query in the batch so the response can be
        // put back together: which itemtype it was for, and whether it is the
        // results query or a facet-only query standing in for one attribute.
        $queries = [];
        $plan    = [];

        foreach ($wanted as $itemtype) {
            $filter = Visibility::filterFor($itemtype);

            // Null means this session may see nothing of this type. Sending the
            // query unfiltered would show all of it.
            if ($filter === null) {
                continue;
            }

            $wanted = Schema::facetsFor($itemtype);

            // The visibility filter and the user's facet choices are AND'd, and
            // the visibility half is written first and never optional. A facet
            // filter narrows what somebody may already see; it can never widen
            // it.
            $chosen = self::facetFilter($facetFilters, $wanted);

            $query = [
                'indexUid'             => Schema::indexFor($itemtype),
                'q'                    => $term,
                'filter'               => $chosen === '' ? $filter : '(' . $filter . ') AND ' . $chosen,
                'limit'                => $perType * $overfetch,
                'attributesToRetrieve' => ['id', 'itemtype', 'items_id', 'title', 'subtitle', 'ref'],
            ];

            if (Settings::flag('facets_enabled') && $wanted !== []) {
                $query['facets'] = $wanted;
            }

            // Private followups and private tasks are readable only with the
            // rights for them, so a session without those must not be able to
            // match on their text. Null means "everything", which is the
            // ordinary case and costs Meilisearch nothing.
            $searchable = Visibility::searchableAttributes($itemtype);
            if ($searchable !== null) {
                $query['attributesToSearchOn'] = $searchable;
            }

            // Hybrid follows from the index having an embedder, not from a
            // switch here. Meilisearch will not apply one on its own — a plain
            // query is keyword-only however well configured the index is — so
            // the parameter still has to be sent; what the plugin does not do
            // is make somebody ask for it twice.
            $embedder = Schema::embedderFor($itemtype);

            if ($embedder !== null) {
                $query['hybrid'] = [
                    'embedder'      => $embedder,
                    'semanticRatio' => (float) Settings::get('semantic_ratio'),
                ];
            }

            $plan[count($queries)] = ['itemtype' => $itemtype, 'facet' => null];
            $queries[]             = $query;

            // A facet counted against the filtered set collapses to the value
            // that was chosen, which makes every *other* value in it vanish —
            // so the sidebar loses the alternatives at the exact moment
            // somebody is looking for them. The fix is one extra query per
            // filtered attribute, counting it as though it alone were not
            // filtered. They ride along in the same batch, so it stays one
            // round trip.
            if (!Settings::flag('facets_enabled')) {
                continue;
            }

            foreach (array_keys($facetFilters) as $attribute) {
                if (!in_array($attribute, $wanted, true)) {
                    continue;
                }

                $without = self::facetFilter(
                    array_diff_key($facetFilters, [$attribute => true]),
                    $wanted
                );

                $counting = [
                    'indexUid' => Schema::indexFor($itemtype),
                    'q'        => $term,
                    'filter'   => $without === '' ? $filter : '(' . $filter . ') AND ' . $without,
                    // Nothing is displayed from this one; only the counts.
                    'limit'    => 0,
                    'facets'   => [$attribute],
                ];

                if ($searchable !== null) {
                    $counting['attributesToSearchOn'] = $searchable;
                }

                $plan[count($queries)] = ['itemtype' => $itemtype, 'facet' => $attribute];
                $queries[]             = $counting;
            }
        }

        if ($queries === []) {
            // Every requested type was one this session may see nothing of.
            // That is a real answer, not a failure, so no fallback is wanted:
            // searching them again in GLPI would return the same nothing more
            // slowly.
            return [];
        }

        try {
            $results = $client->multiSearch($queries);
        } catch (SearchException $e) {
            // Meilisearch rejects a whole multi-search when any one query is
            // invalid, and the likeliest cause is a facet: an attribute the
            // index has not finished declaring as filterable, or one left over
            // from a settings change. Losing the counts is a blemish; losing
            // every result because of them is an outage, so the query is tried
            // again without them before giving up.
            $retry = [];
            foreach ($queries as $query) {
                unset($query['facets']);
                $retry[] = $query;
            }

            try {
                $results = $client->multiSearch($retry);
                $degradedFacets = true;
            } catch (SearchException) {
                // Swallowed for the caller's sake, but reported through
                // $degraded. See the note on the contract above.
                $degraded = true;

                return [];
            }
        }

        // Only the counts are dropped on a retry. The facet *filter* is part of
        // what was asked for, and quietly widening a search because the counts
        // failed would show people records they had filtered away.
        if ($degradedFacets ?? false) {
            $facets = [];
        }

        $groups = [];

        foreach ($results as $position => $result) {
            $step = $plan[$position] ?? null;
            if ($step === null) {
                continue;
            }

            $itemtype = $step['itemtype'];

            // Merged across itemtypes: "12 New" means twelve records, whatever
            // kind they are. Counted before the rights check, and honestly
            // labelled as such — see the note on collect().
            $counts = is_array($result['facetDistribution'] ?? null) ? $result['facetDistribution'] : [];

            if ($step['facet'] !== null) {
                // A stand-in query: it exists only to count one attribute
                // without its own filter applied, so nothing else it returned
                // is wanted — least of all its counts for other attributes,
                // which *are* filtered and would double up.
                self::collect($facets, array_intersect_key($counts, [$step['facet'] => true]));

                continue;
            }

            // The results query counts everything except the attributes that
            // have a stand-in of their own.
            self::collect($facets, array_diff_key($counts, $facetFilters));

            $hits = is_array($result['hits'] ?? null) ? $result['hits'] : [];
            $rows = Visibility::keep($itemtype, $hits, $perType);

            if ($rows === []) {
                continue;
            }

            $groups[] = [
                'itemtype'   => $itemtype,
                'type_label' => (string) $itemtype::getTypeName(2),
                'icon'       => self::icon($itemtype),
                'items'      => array_map(static fn(array $row): array => [
                    'id'       => (int) $row['items_id'],
                    'title'    => (string) ($row['title'] ?? ''),
                    'subtitle' => (string) ($row['subtitle'] ?? ''),
                    'url'      => (string) ($row['url'] ?? ''),
                ], $rows),
            ];
        }

        return $groups;
    }

    /**
     * One Meilisearch filter expression for the chosen facet values.
     *
     * Values are quoted and attributes are checked against what the index
     * actually declares, because both arrive from the browser. An unknown
     * attribute is dropped rather than passed through: Meilisearch would reject
     * the whole query, and one stale bookmark would take out the search box.
     *
     * @param array<string,string[]> $chosen
     * @param string[] $allowed
     */
    private static function facetFilter(array $chosen, array $allowed): string
    {
        $clauses = [];

        foreach ($chosen as $attribute => $values) {
            if (!in_array($attribute, $allowed, true) || !is_array($values)) {
                continue;
            }

            $quoted = [];
            foreach ($values as $value) {
                $value = (string) $value;
                if ($value === '') {
                    continue;
                }

                // Meilisearch takes a single-quoted string; the only thing that
                // can break out of one is a single quote.
                $quoted[] = "'" . str_replace("'", "\\'", $value) . "'";
            }

            if ($quoted !== []) {
                $clauses[] = sprintf('%s IN [%s]', $attribute, implode(', ', $quoted));
            }
        }

        return implode(' AND ', $clauses);
    }

    /**
     * Fold one index's facet counts into the running total.
     *
     * These are Meilisearch's counts, which means they are counts *before*
     * GLPI's rights check. A technician restricted to one entity can therefore
     * be shown "New (40)" and then see twelve rows. That is a real wart and the
     * alternative is worse: counting after the check would mean fetching and
     * loading every matching record on every keystroke, which is the cost this
     * whole plugin exists to avoid. The entity filter has already been applied
     * in Meilisearch, so the gap is only ever the per-record rights — and the
     * numbers are presented as approximate where they are shown.
     *
     * @param array<string,array<string,int>> $into
     */
    private static function collect(array &$into, mixed $distribution): void
    {
        if (!is_array($distribution)) {
            return;
        }

        foreach ($distribution as $attribute => $values) {
            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $value => $count) {
                $value = (string) $value;
                if ($value === '') {
                    continue;
                }

                $into[$attribute][$value] = ($into[$attribute][$value] ?? 0) + (int) $count;
            }
        }

        foreach ($into as $attribute => $values) {
            arsort($values);
            $into[$attribute] = array_slice($values, 0, Schema::MAX_FACET_VALUES, true);
        }
    }

    private static function icon(string $itemtype): string
    {
        $item = getItemForItemtype($itemtype);

        if ($item instanceof CommonDBTM && method_exists($itemtype, 'getIcon')) {
            $icon = (string) $itemtype::getIcon();
            if ($icon !== '') {
                return $icon;
            }
        }

        return 'ti ti-file';
    }
}
