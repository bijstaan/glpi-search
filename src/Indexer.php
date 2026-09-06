<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use CommonDBTM;
use CronTask;

/**
 * Draining the queue into Meilisearch.
 *
 * Everything expensive happens here, on cron, where nobody is waiting. The unit
 * of work is one batch rather than "everything outstanding", because a first
 * index of a real instance is a hundred thousand documents and doing it in one
 * pass would make GLPI's cron look hung while it happened.
 *
 * The failure posture is the opposite of the search path's. A search that
 * cannot reach Meilisearch returns nothing and lets the caller fall back; an
 * indexing run that cannot reach it says so and leaves the queue alone, because
 * a cron task that quietly indexes nothing is a cron task that reports success
 * while the index rots.
 */
final class Indexer
{
    /** @return array<string,mixed> */
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'index' => ['description' => __('Push changed records to Meilisearch', 'glpisearch')],
            default           => [],
        };
    }

    /** GLPI's cron entry point. -1 means "nothing to do", 1 means work happened. */
    public static function cronIndex(CronTask $task): int
    {
        $result = self::run((int) $task->fields['param'] ?: null);

        $task->log($result['message']);

        if ($result['indexed'] === 0 && $result['removed'] === 0) {
            return $result['failed'] > 0 ? 0 : -1;
        }

        $task->setVolume($result['indexed'] + $result['removed']);

        return 1;
    }

    /**
     * Drain one batch.
     *
     * @return array{indexed:int,removed:int,failed:int,remaining:int,message:string}
     */
    public static function run(?int $budget = null): array
    {
        $idle = ['indexed' => 0, 'removed' => 0, 'failed' => 0, 'remaining' => 0];

        if (!Settings::isLive()) {
            return $idle + ['message' => 'Search is not configured.'];
        }

        $client = Client::fromSettings();
        if ($client === null) {
            return $idle + ['message' => 'Search is not configured.'];
        }

        // Before anything else: every configured type needs an index, whether or
        // not it has records, or the next search fails for all of them.
        self::ensureAll();

        $budget = $budget ?: max(1, (int) Settings::get('batch'));

        // Dropped at the start of every batch. Documents memoises dropdown
        // names so that a first index does not resolve the same two hundred
        // categories fifty thousand times — but the memo must not outlive the
        // batch, or a rename processed earlier in this same process would be
        // written back with the name it had before. The queue is drained by a
        // long-lived cron worker, which is exactly where that would happen.
        Documents::forgetNames();

        $claim  = Queue::claim($budget);

        if ($claim['rows'] === []) {
            return $idle + ['message' => 'Nothing to index.'];
        }

        // Grouped by itemtype because that is how the indexes are split, and
        // one request per type beats one per document by three orders of
        // magnitude on a first index.
        $upserts = [];
        $deletes = [];
        $indexed = 0;

        foreach ($claim['rows'] as $row) {
            $itemtype = $row['itemtype'];

            if (!in_array($itemtype, Settings::itemtypes(), true)) {
                // A type that has since been switched off. Its documents are
                // removed rather than left behind, so that turning a type off
                // takes it out of results rather than freezing it there.
                $deletes[$itemtype][] = Documents::idFor($itemtype, $row['items_id']);
                continue;
            }

            if ($row['action'] === Queue::DELETE) {
                $deletes[$itemtype][] = Documents::idFor($itemtype, $row['items_id']);
                continue;
            }

            $item = getItemForItemtype($itemtype);
            if (!$item instanceof CommonDBTM || !$item->getFromDB($row['items_id'])) {
                // Queued as changed, gone by the time we got here. Removing is
                // the right reading of that, not skipping.
                $deletes[$itemtype][] = Documents::idFor($itemtype, $row['items_id']);
                continue;
            }

            $document = Documents::forItem($item);

            if ($document === null) {
                // Became a template, or went to the trash, between the hook and
                // now. Same reasoning as above.
                $deletes[$itemtype][] = Documents::idFor($itemtype, $row['items_id']);
                continue;
            }

            $upserts[$itemtype][] = $document;
            $indexed++;
        }

        $removed = 0;
        $failed  = 0;
        $errors  = [];
        $last    = 0;

        foreach ($upserts as $itemtype => $documents) {
            try {
                self::ensureIndex($client, $itemtype);
                $last = $client->addDocuments(Schema::indexFor($itemtype), $documents);
            } catch (SearchException $e) {
                $failed  += count($documents);
                $indexed -= count($documents);
                $errors[] = $itemtype . ': ' . $e->getMessage();
            }
        }

        foreach ($deletes as $itemtype => $ids) {
            try {
                self::ensureIndex($client, $itemtype);
                $last     = $client->deleteDocuments(Schema::indexFor($itemtype), $ids);
                $removed += count($ids);
            } catch (SearchException $e) {
                $failed  += count($ids);
                $errors[] = $itemtype . ': ' . $e->getMessage();
            }
        }

        // The queue is only cleared for work that actually left the building.
        // Clearing it wholesale would turn one unreachable-Meilisearch cron run
        // into a permanent hole in the index that nothing would ever notice.
        if ($failed === 0) {
            Queue::done($claim['ids']);
        }

        // Waited on rather than fired and forgotten, and only the last one:
        // enough to turn "Meilisearch accepted the batch" into "Meilisearch
        // indexed the batch", which is the difference between a settings page
        // that reports progress and one that reports optimism.
        if ($last > 0) {
            $task = $client->awaitTask($last, 30);
            if (($task['status'] ?? '') === 'failed') {
                $errors[] = 'Meilisearch rejected the batch: '
                    . (string) ($task['error']['message'] ?? 'no reason given');
            }
        }

        $remaining = Queue::pending();

        $message = $errors === []
            ? sprintf('Indexed %d, removed %d, %d still queued.', $indexed, $removed, $remaining)
            : sprintf(
                'Indexed %d, removed %d, %d failed, %d still queued. %s',
                $indexed,
                $removed,
                $failed,
                $remaining,
                implode('; ', array_slice($errors, 0, 3))
            );

        return [
            'indexed'   => max(0, $indexed),
            'removed'   => $removed,
            'failed'    => $failed,
            'remaining' => $remaining,
            'message'   => $message,
        ];
    }

    /** @var array<string,bool> itemtypes whose index has been prepared this request */
    private static array $ensured = [];

    /**
     * Create the index and push its settings, once per request.
     *
     * Settings are idempotent and cheap, but they are also a task each, and
     * pushing them per batch would fill Meilisearch's task queue with identical
     * no-ops that a human reading it then has to scroll past.
     */
    private static function ensureIndex(Client $client, string $itemtype): void
    {
        if (self::$ensured[$itemtype] ?? false) {
            return;
        }

        $uid     = Schema::indexFor($itemtype);
        $created = $client->ensureIndex($uid);

        $task = $client->updateSettings($uid, Schema::settingsFor($itemtype));

        // Waited on only when the index is new. Settings are asynchronous like
        // everything else in Meilisearch, and `filterableAttributes` is what
        // makes a facet legal — so a search issued between the push and its
        // application asks to filter on attributes the index does not yet
        // declare, and Meilisearch rejects the whole request. On an index that
        // already exists the previous push has long since applied and the
        // re-push is a no-op, so waiting for it would only make a run over
        // eighty types eighty round trips slower for nothing.
        if ($created && $task > 0) {
            $client->awaitTask($task, 30);
        }

        self::$ensured[$itemtype] = true;

        if ($created) {
            // A freshly created index has no embedder, and a cached "none" from
            // before it existed would outlive the operator adding one.
            Schema::forgetEmbedders();
        }
    }

    /**
     * Make sure every configured type has an index, records or not.
     *
     * Not housekeeping — this is what stops the search failing altogether.
     * Meilisearch rejects a whole multi-search if any one query names an index
     * that does not exist, so a single configured itemtype with no records
     * takes down every search on the instance, for every type. And a type with
     * no records is exactly what never gets an index, because indexes were
     * previously created only when there were documents to push into them.
     *
     * The symptom is brutal and gives no hint of the cause: tick "Certificate"
     * on an instance that has no certificates, and searching for a printer
     * stops working.
     *
     * @return int how many had to be created
     */
    public static function ensureAll(): int
    {
        $client = Client::fromSettings();
        if ($client === null) {
            return 0;
        }

        $created = 0;

        foreach (Settings::itemtypes() as $itemtype) {
            if (self::$ensured[$itemtype] ?? false) {
                continue;
            }

            try {
                if ($client->ensureIndex(Schema::indexFor($itemtype))) {
                    $created++;
                }

                self::ensureIndex($client, $itemtype);
            } catch (SearchException) {
                // One unreachable index must not stop the rest being made.
                continue;
            }
        }

        return $created;
    }

    /**
     * Queue everything, from scratch.
     *
     * Used after a settings change that alters what a document contains, and
     * from the settings page. Existing documents are left in place while it
     * runs rather than dropped first — a rebuild that empties the index and
     * then refills it over an hour is an hour of a search box that finds
     * nothing.
     *
     * @return array{queued:int,types:string[]}
     */
    public static function rebuild(): array
    {
        $queued = 0;
        $types  = Settings::itemtypes();

        foreach ($types as $itemtype) {
            $queued += Queue::enqueueAll($itemtype);
        }

        return ['queued' => $queued, 'types' => $types];
    }

    /**
     * Throw the indexes away entirely.
     *
     * The honest response to changing the index prefix or the embedder: both
     * change what a document *is*, and a half-migrated index answers with a
     * mixture nobody can reason about.
     */
    public static function drop(): int
    {
        $client = Client::fromSettings();
        if ($client === null) {
            return 0;
        }

        $dropped = 0;

        foreach (Schema::indexable() as $itemtype) {
            try {
                if ($client->deleteIndex(Schema::indexFor($itemtype)) > 0) {
                    $dropped++;
                }
            } catch (SearchException) {
                // Already gone, or never there. Either way there is nothing to
                // drop, which is the state this method exists to reach.
            }
        }

        self::$ensured = [];

        return $dropped;
    }

    /**
     * Remove indexes for types that are no longer configured.
     *
     * Unticking a type stops it being searched, and the documents already in
     * its index would otherwise sit there for good. That is not merely untidy:
     * those documents hold the text of the records — for tickets, that includes
     * private followups — so an index nobody searches is a copy of the data
     * nobody is thinking about any more. Somebody who unticks a type has said
     * they do not want it indexed, and this is what makes that true.
     *
     * Scoped to the current prefix, so a second GLPI sharing this Meilisearch
     * keeps its own indexes.
     *
     * @return string[] the index names removed
     */
    public static function prune(): array
    {
        $client = Client::fromSettings();
        if ($client === null) {
            return [];
        }

        $prefix = trim(Settings::get('index_prefix'));
        $prefix = ($prefix === '' ? 'glpi' : $prefix) . '_';

        $keep = [];
        foreach (Settings::itemtypes() as $itemtype) {
            $keep[] = Schema::indexFor($itemtype);
        }

        $removed = [];

        try {
            $existing = $client->indexes();
        } catch (SearchException) {
            return [];
        }

        foreach ($existing as $uid) {
            if (!str_starts_with($uid, $prefix) || in_array($uid, $keep, true)) {
                continue;
            }

            try {
                $client->deleteIndex($uid);
                $removed[] = $uid;
            } catch (SearchException) {
                // Leave it; the next save will try again.
            }
        }

        if ($removed !== []) {
            self::$ensured = [];
            Schema::forgetEmbedders();
        }

        return $removed;
    }

    /**
     * What is actually in Meilisearch right now.
     *
     * @return array{ok:bool,error:string,total:int,types:array<string,int>,
     *               embedders:array<string,string>,queued:int}
     */
    public static function status(): array
    {
        $status = [
            'ok'        => false,
            'error'     => '',
            'total'     => 0,
            'types'     => [],
            'embedders' => [],
            'queued'    => Queue::pending(),
        ];

        $client = Client::fromSettings();
        if ($client === null) {
            $status['error'] = 'Not configured.';

            return $status;
        }

        foreach (Settings::itemtypes() as $itemtype) {
            try {
                $stats = $client->stats(Schema::indexFor($itemtype));
            } catch (SearchException $e) {
                $status['error'] = $e->getMessage();

                return $status;
            }

            $count                      = (int) ($stats['numberOfDocuments'] ?? 0);
            $status['types'][$itemtype] = $count;
            $status['total']           += $count;

            // Reported rather than configured. The settings page's job here is
            // to show the administrator what Meilisearch is set up to do, not
            // to offer to set it up from GLPI.
            $embedder = Schema::embedderFor($itemtype);
            if ($embedder !== null) {
                $status['embedders'][$itemtype] = $embedder;
            }
        }

        $status['ok'] = true;

        return $status;
    }
}
