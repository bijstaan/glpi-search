<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use GlpiPlugin\Glpiai\Tool;

/**
 * Typo-tolerant search, offered to glpi-ai's assistant as a tool.
 *
 * glpi-ai's native searches go through `Search::getDatas()`, which is GLPI's
 * own exact-match SQL. It is precise, it respects every right the profile
 * system defines, and it cannot find `Printre` — or `Schmidt` when the record
 * says `Schmitt`, or a hostname somebody transposed two characters of. A model
 * paraphrasing an entity's words hits that wall constantly and has no way to
 * know it did: an exact-match search that finds nothing looks exactly like a
 * fact that is not recorded.
 *
 * So this is the tool for the second attempt. The description says so in those
 * words, because the failure mode worth designing against is not the model
 * ignoring this tool — it is the model concluding "there is no such ticket"
 * from one exact search and telling a technician so.
 *
 * **It is not a replacement for `search_tickets`.** That one filters by status,
 * scopes to the conversation's entity, and returns fields chosen for a ticket
 * search. This one is broad and shallow: it searches every indexed itemtype at
 * once and gives back a title, a subtitle and an id per hit, which is enough to
 * follow up with `read_ticket` or `read_asset`. Breadth first, then read.
 *
 * **Rights come from the plugin, not from here.** `Visibility::filterFor()`
 * narrows each Meilisearch query to the session's entities before it is sent,
 * and `Visibility::keep()` re-checks each hit against GLPI afterwards. Both
 * already run inside `Finder::search()`; a tool that re-implemented either
 * would be a second opinion on a question that must have one answer.
 *
 * **The degraded case is reported rather than hidden.** Meilisearch being
 * unreachable and there being no matches are both an empty result, and
 * `Finder` distinguishes them with a flag precisely because a caller that
 * cannot tell will say "nothing found" when the truth is "nothing was looked
 * at". A model saying that to a technician is worse than an error.
 */
final class AiTools
{
    /** Hits per itemtype in one answer. */
    private const PER_TYPE = 5;

    /** @return Tool[] */
    public static function all(): array
    {
        return [self::everything()];
    }

    private static function everything(): Tool
    {
        return new Tool(
            name: 'search_everything',
            description: 'Fuzzy, typo-tolerant search across everything indexed — tickets, '
                . 'assets, people, knowledge articles and more — matching on partial words and '
                . 'misspellings that GLPI\'s own exact-match search cannot find. Use it as the '
                . 'second attempt whenever an exact search came back empty, whenever you are '
                . 'searching words an entity used rather than words somebody typed into GLPI, '
                . 'and whenever a name or hostname might be spelled slightly differently. Never '
                . 'conclude that something does not exist from an exact search alone.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query'     => [
                        'type'        => 'string',
                        'description' => 'What to look for. Partial words are fine and so are '
                            . 'misspellings; that is the point of this tool.',
                    ],
                    'itemtypes' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Restrict to these GLPI itemtypes, e.g. ["Ticket", '
                            . '"Computer"]. Omit to search everything indexed.',
                    ],
                    'limit'     => [
                        'type'        => 'integer',
                        'description' => 'Hits per itemtype, 1-10. Defaults to 5.',
                    ],
                ],
                'required'   => ['query'],
            ],
            handler: [self::class, 'runEverything'],
            // No right of its own: each itemtype's results are filtered by the
            // plugin's own Visibility, against the session's profile and
            // entities. There is no profile right meaning "may search" to gate
            // this on, and inventing one would be inventing a permission GLPI
            // does not have.
            right: null,
            source: 'glpisearch'
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runEverything(array $arguments = [], mixed $context = null): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return ['error' => 'Give something to search for.'];
        }

        if (!Finder::isAvailable()) {
            return [
                'error' => 'The typo-tolerant index is not available on this instance. Use the '
                    . 'exact-match searches instead, and try more than one wording.',
            ];
        }

        $itemtypes = array_values(array_filter(
            array_map('strval', (array) ($arguments['itemtypes'] ?? [])),
            static fn(string $t): bool => $t !== '' && class_exists($t)
        ));

        $limit = max(1, min(10, (int) ($arguments['limit'] ?? self::PER_TYPE)));

        $degraded = false;
        $groups   = Finder::search($query, $itemtypes, $limit, $degraded);

        if ($degraded) {
            return [
                'error' => 'The search backend did not answer, so nothing was actually looked '
                    . 'at. Do not report this as "no results" — fall back to the exact-match '
                    . 'searches and say the fuzzy index was unavailable.',
            ];
        }

        $results = [];
        $total   = 0;

        foreach ($groups as $group) {
            $items = [];

            foreach ((array) ($group['items'] ?? []) as $item) {
                $items[] = array_filter([
                    'id'       => (int) ($item['items_id'] ?? 0),
                    'title'    => (string) ($item['title'] ?? ''),
                    'subtitle' => (string) ($item['subtitle'] ?? ''),
                ], static fn($v): bool => $v !== '' && $v !== 0);
            }

            if ($items === []) {
                continue;
            }

            $total    += count($items);
            $results[] = [
                'itemtype' => (string) ($group['itemtype'] ?? ''),
                'label'    => (string) ($group['type_label'] ?? ''),
                'matches'  => $items,
            ];
        }

        return [
            'count'   => $total,
            'results' => $results,
            'note'    => $total === 0
                ? 'Nothing matched, and this search does tolerate misspellings — so the words '
                  . 'themselves are probably wrong rather than the spelling. Try what an '
                  . 'engineer would have written rather than what the entity said.'
                : 'Titles and ids only. Follow up with read_ticket, read_asset or read_user for '
                  . 'anything worth looking at properly.',
        ];
    }
}
