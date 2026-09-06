<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use CommonDBTM;

/**
 * The list of records whose documents are out of date.
 *
 * GLPI's item hooks write here and nothing else. They do not talk to
 * Meilisearch, do not build a document, and do not care whether the search
 * backend is up: a technician saving a ticket must not wait on a search engine,
 * and a search engine that is down must not stop them saving. A row in this
 * table is the whole of what a save costs.
 *
 * There is no unique key on (itemtype, items_id) on purpose. Adding one would
 * mean an upsert, and an upsert under two concurrent saves of the same record
 * is a lock; without it the worst case is the same record queued twice and
 * indexed once, because {@see Indexer} collapses duplicates when it drains.
 */
final class Queue
{
    public const TABLE = 'glpi_plugin_glpisearch_queue';

    public const UPSERT = 'upsert';
    public const DELETE = 'delete';

    /** Queue one record. Cheap enough to sit in a save path; nothing else may. */
    public static function mark(string $itemtype, int $items_id, string $action): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($items_id <= 0 || !in_array($action, [self::UPSERT, self::DELETE], true)) {
            return;
        }

        // Not gated on the master switch. A record deleted while the plugin was
        // switched off still has a document in Meilisearch, and forgetting it
        // here is how an index ends up serving records that no longer exist.
        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        $DB->insert(self::TABLE, [
            'itemtype'      => $itemtype,
            'items_id'      => $items_id,
            'action'        => $action,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function markItem(CommonDBTM $item, string $action): void
    {
        self::mark($item::class, (int) $item->getID(), $action);
    }

    /**
     * The next batch of work, oldest first, with duplicates collapsed.
     *
     * Collapsing keeps the *latest* action for a record: a ticket created and
     * then deleted inside one cron interval must end up absent from the index,
     * not present because the creation was processed second.
     *
     * @return array{rows:array<string,array{itemtype:string,items_id:int,action:string}>,ids:int[]}
     */
    public static function claim(int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        $ids  = [];

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'ORDER' => 'id ASC',
                'LIMIT' => max(1, $limit),
            ]) as $row
        ) {
            $key = $row['itemtype'] . '-' . $row['items_id'];

            $rows[$key] = [
                'itemtype' => (string) $row['itemtype'],
                'items_id' => (int) $row['items_id'],
                'action'   => (string) $row['action'],
            ];

            $ids[] = (int) $row['id'];
        }

        return ['rows' => $rows, 'ids' => $ids];
    }

    /** @param int[] $ids */
    public static function done(array $ids): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($ids === []) {
            return;
        }

        $DB->delete(self::TABLE, ['id' => array_values($ids)]);
    }

    public static function pending(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }

        return (int) ($DB->request([
            'SELECT' => ['COUNT' => 'id AS n'],
            'FROM'   => self::TABLE,
        ])->current()['n'] ?? 0);
    }

    /**
     * Queue every existing record of a type.
     *
     * One `INSERT ... SELECT` rather than a row at a time, because the whole
     * point is a first index of an instance that already has a hundred thousand
     * tickets, and doing that in PHP would mean holding them all in memory to
     * write them straight back to the database.
     *
     * Deleted and template rows are skipped here rather than filtered later:
     * queueing work only to discard it is work.
     */
    public static function enqueueAll(string $itemtype): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = getTableForItemType($itemtype);
        if (!$DB->tableExists($table)) {
            return 0;
        }

        $where = [];
        if ($DB->fieldExists($table, 'is_deleted')) {
            $where[] = '`is_deleted` = 0';
        }
        if ($DB->fieldExists($table, 'is_template')) {
            $where[] = '`is_template` = 0';
        }

        $DB->doQuery(sprintf(
            'INSERT INTO `%s` (`itemtype`, `items_id`, `action`, `date_creation`)
             SELECT %s, `id`, %s, NOW() FROM `%s`%s',
            self::TABLE,
            $DB->quoteValue($itemtype),
            $DB->quoteValue(self::UPSERT),
            $table,
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where)
        ));

        return $DB->affectedRows();
    }

    /**
     * Queue every record that names this one.
     *
     * The price of denormalising. A category, a location or a person's name is
     * copied into every document that points at it, so renaming one leaves
     * every one of those documents carrying the old word — findable by what it
     * used to be called and not by what it is now.
     *
     * This walks the indexed types looking for columns that point at the
     * renamed record and queues whatever matches. It is deliberately bounded:
     * renaming "Hardware" on an instance with 200,000 tickets in it should not
     * enqueue 200,000 rows inside the web request that renamed it. Past the
     * limit the rest go stale until the next full rebuild, and the caller is
     * told how many were left.
     *
     * @return array{queued:int,capped:bool}
     */
    public static function enqueueReferrers(string $itemtype, int $items_id, int $limit = 5000): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($items_id <= 0 || !$DB->tableExists(self::TABLE)) {
            return ['queued' => 0, 'capped' => false];
        }

        $queued = 0;
        $capped = false;

        foreach (Settings::itemtypes() as $referrer) {
            try {
                $table = getTableForItemType($referrer);
            } catch (\Throwable) {
                continue;
            }

            if (!$DB->tableExists($table)) {
                continue;
            }

            // Only the columns that actually point at the renamed type. A
            // ticket has a dozen foreign keys and eleven of them are irrelevant
            // to a location being renamed.
            $columns = [];
            foreach (array_keys($DB->listFields($table)) as $column) {
                if (preg_match('/_id(_.*)?$/', (string) $column) !== 1) {
                    continue;
                }

                if (getItemtypeForForeignKeyField((string) $column) === $itemtype) {
                    $columns[] = (string) $column;
                }
            }

            if ($columns === []) {
                continue;
            }

            $remaining = $limit - $queued;
            if ($remaining <= 0) {
                $capped = true;
                break;
            }

            $where = [];
            foreach ($columns as $column) {
                $where[] = sprintf('`%s` = %d', $column, $items_id);
            }

            $DB->doQuery(sprintf(
                'INSERT INTO `%s` (`itemtype`, `items_id`, `action`, `date_creation`)
                 SELECT %s, `id`, %s, NOW() FROM `%s` WHERE (%s) LIMIT %d',
                self::TABLE,
                $DB->quoteValue($referrer),
                $DB->quoteValue(self::UPSERT),
                $table,
                implode(' OR ', $where),
                $remaining
            ));

            $affected = $DB->affectedRows();
            $queued  += $affected;

            if ($affected >= $remaining) {
                $capped = true;
                break;
            }
        }

        return ['queued' => $queued, 'capped' => $capped];
    }

    /** Empty the queue. Used when the plugin is reconfigured out from under it. */
    public static function clear(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->delete(self::TABLE, [1]);
        }
    }
}
