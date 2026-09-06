<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpisearch\Indexer;
use GlpiPlugin\Glpisearch\Queue;
use GlpiPlugin\Glpisearch\Settings;

/**
 * Install: one table, one cron task, one right, and the settings defaults.
 *
 * The table is a work queue, not a copy of anything. What this plugin knows
 * about a record lives in Meilisearch; what lives in GLPI is a note that a
 * record has changed and has not been sent yet. That distinction is what makes
 * uninstalling honest — dropping the queue and the indexes leaves nothing
 * behind, because there was never a second copy of the data here.
 */
function plugin_glpisearch_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    if (!$DB->tableExists(Queue::TABLE)) {
        $DB->doQuery(
            "CREATE TABLE `" . Queue::TABLE . "` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `itemtype` VARCHAR(100) NOT NULL,
                `items_id` INT UNSIGNED NOT NULL,
                -- 'upsert' | 'delete'. See Queue for why the latest wins.
                `action` VARCHAR(8) NOT NULL DEFAULT 'upsert',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                -- Deliberately not unique on (itemtype, items_id): a unique key
                -- would make every save an upsert, and an upsert under two
                -- concurrent saves of one record is a lock in a path that must
                -- never block a technician. Duplicates collapse when drained.
                KEY `item` (`itemtype`,`items_id`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    CronTask::register(
        Indexer::class,
        'index',
        5 * MINUTE_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Push changed records to Meilisearch',
            // The queue is drained in batches, so a run that finds a backlog
            // does part of it. Five minutes keeps ordinary edits fresh without
            // making a first index take a week.
            'param'         => 500,
        ]
    );

    plugin_glpisearch_install_rights();

    // Seeded rather than left to the defaults so that an administrator auditing
    // what the plugin will do can read it out of the config table instead of
    // inferring it from code.
    Config::setConfigurationValues(PLUGIN_GLPISEARCH_CONFIG_CONTEXT, Settings::DEFAULTS);

    return true;
}

/**
 * One right, held by whoever configures GLPI.
 *
 * There is nothing here for a non-administrator to hold. Searching is governed
 * by the rights on the records being searched — which is the entire design —
 * so a separate "may search" right would be a second, weaker answer to a
 * question GLPI has already answered properly.
 */
function plugin_glpisearch_install_rights()
{
    /** @var DBmysql $DB */
    global $DB;

    $right = 'plugin_glpisearch_config';

    $exists = false;
    foreach (
        $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => $right],
            'LIMIT'  => 1,
        ]) as $row
    ) {
        $exists = true;
    }

    // GLPI runs the install hook on upgrade too, and addProfileRights() inserts
    // unconditionally — calling it for a right that is already there raises a
    // duplicate-key error that aborts the whole upgrade.
    if (!$exists) {
        ProfileRight::addProfileRights([$right]);
    }

    $targets = [];
    foreach (
        $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config', 'rights' => ['>', 0]],
        ]) as $row
    ) {
        $targets[] = (int) $row['profiles_id'];
    }

    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $targets[] = (int) $_SESSION['glpiactiveprofile']['id'];
    }

    foreach (array_unique($targets) as $profiles_id) {
        ProfileRight::updateProfileRights($profiles_id, [$right => ALLSTANDARDRIGHT]);
    }
}

function plugin_glpisearch_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    // The indexes first, while the credentials to reach them still exist.
    // Deleting the settings first would strand every document in Meilisearch
    // with nothing left in GLPI that knows where they are.
    try {
        Indexer::drop();
    } catch (Throwable $e) {
        // An unreachable Meilisearch must not block an uninstall. The indexes
        // are named and documented; a human can remove them.
        Toolbox::logInFile(
            'glpisearch',
            'uninstall could not drop the indexes: ' . $e->getMessage() . "\n"
        );
    }

    if ($DB->tableExists(Queue::TABLE)) {
        $DB->doQuery('DROP TABLE `' . Queue::TABLE . '`');
    }

    CronTask::unregister('glpisearch');

    ProfileRight::deleteProfileRights(['plugin_glpisearch_config']);

    Config::deleteConfigurationValues(
        PLUGIN_GLPISEARCH_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    return true;
}

// ---------------------------------------------------------------- dispatchers

/**
 * A record was created, changed, or restored from the trash.
 *
 * GLPI allows one callback per itemtype per hook, and these three hooks all
 * mean the same thing to an index: whatever is stored for this record is no
 * longer what the record says.
 */
function plugin_glpisearch_item_changed(CommonDBTM $item)
{
    Queue::markItem($item, Queue::UPSERT);

    // A record can be both indexed in its own right and named by others — a
    // user is the obvious case, being searchable *and* the requester on a
    // thousand tickets. GLPI allows one callback per itemtype per hook, so the
    // rename handling has to happen here too rather than in a second callback.
    plugin_glpisearch_referenced_renamed($item);
}

/**
 * A record went to the trash or was purged outright.
 *
 * Both are removals as far as search is concerned. GLPI's own search stops
 * showing a trashed record, and an index that kept showing it would be a search
 * box that finds things the rest of GLPI says are gone.
 */
function plugin_glpisearch_item_gone(CommonDBTM $item)
{
    Queue::markItem($item, Queue::DELETE);
}

/**
 * A record that other records name has been renamed.
 *
 * Documents carry the *names* of what they point at — a ticket holds "Hardware"
 * and "Second floor", not the ids — because that is the only way the text is
 * searchable and the values are countable as facets. The cost is that renaming
 * a category leaves every ticket in it findable by the old word, so the records
 * that name it are queued to be rewritten.
 *
 * Only fires when the name actually changed. Dropdowns are touched for all
 * sorts of reasons — a comment, a parent, a colour — and none of the others
 * change a single document.
 */
function plugin_glpisearch_referenced_renamed(CommonDBTM $item)
{
    if (!in_array('name', $item->updates ?? [], true)) {
        return;
    }

    $result = Queue::enqueueReferrers($item::class, (int) $item->getID());

    if ($result['capped']) {
        // Said out loud rather than swallowed. The alternative to a cap is a
        // rename that enqueues a quarter of a million rows inside the request
        // that renamed it, and the alternative to saying so is an index quietly
        // holding a name nobody uses any more.
        Toolbox::logInFile(
            'glpisearch',
            sprintf(
                "renaming %s #%d touched more records than one pass will queue (%d queued); "
                . "the rest keep the old name until the next rebuild\n",
                $item::class,
                (int) $item->getID(),
                $result['queued']
            )
        );
    }
}
