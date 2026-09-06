<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI Search — typo-tolerant search over GLPI's records, backed by Meilisearch.
 *
 * GLPI's own search is exact-match SQL. It is precise, it respects every right
 * the profile system defines, and it cannot find "Printre" — which is what a
 * technician actually types at 4pm. This plugin puts an inverted index in front
 * of it: typo tolerance, prefix matching from the first keystroke, and results
 * in single-digit milliseconds rather than the ~140ms a full `contains` sweep
 * costs.
 *
 * What it deliberately does not do is become the authority on who may see what.
 * Meilisearch filters on entity because that is cheap and removes almost
 * everything; GLPI is then asked about every survivor. A search engine that
 * disagreed with GLPI about visibility would be a data leak that looks like a
 * feature, so the index is never the last word.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpisearch\Schema;
use GlpiPlugin\Glpisearch\Settings;

define('PLUGIN_GLPISEARCH_VERSION', '0.1.0');
define('PLUGIN_GLPISEARCH_MIN_GLPI', '11.0');

// Settings live under this config context; the Meilisearch key is encrypted.
define('PLUGIN_GLPISEARCH_CONFIG_CONTEXT', 'plugin:glpisearch');

function plugin_init_glpisearch()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpisearch'] = true;
    $PLUGIN_HOOKS['config_page']['glpisearch']    = 'front/config.php';

    /**
     * Keeping the index in step with GLPI.
     *
     * These only ever write a row to a queue table. Nothing here talks to
     * Meilisearch: a technician saving a ticket must not wait on a search
     * engine, and a search engine that is down must not stop them saving. The
     * cron task drains the queue.
     *
     * Registered for every indexable type rather than only the configured ones,
     * because a type that is switched off today may hold documents indexed
     * yesterday, and those still have to be removed when the record goes.
     */
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['glpisearch']    = [];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpisearch'] = [];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['glpisearch']  = [];
    $PLUGIN_HOOKS[Hooks::ITEM_DELETE]['glpisearch'] = [];
    $PLUGIN_HOOKS[Hooks::ITEM_RESTORE]['glpisearch'] = [];

    foreach (Schema::indexable() as $itemtype) {
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['glpisearch'][$itemtype]     = 'plugin_glpisearch_item_changed';
        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpisearch'][$itemtype]  = 'plugin_glpisearch_item_changed';
        $PLUGIN_HOOKS[Hooks::ITEM_RESTORE]['glpisearch'][$itemtype] = 'plugin_glpisearch_item_changed';

        // Soft delete puts an item in the trash, where GLPI's own search stops
        // showing it. The index has to agree, so a trashed item is removed
        // rather than updated — and re-added by the restore hook above.
        $PLUGIN_HOOKS[Hooks::ITEM_DELETE]['glpisearch'][$itemtype] = 'plugin_glpisearch_item_gone';
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['glpisearch'][$itemtype]  = 'plugin_glpisearch_item_gone';
    }

    // Renaming something that documents *name* — a category, a location, a
    // person — makes every document naming it stale. Registered for the types
    // the indexed ones actually point at, which is a wider set than the indexed
    // types themselves: nobody searches for a Manufacturer, and every Computer
    // carries one.
    foreach (Schema::referenced() as $itemtype) {
        if (isset($PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpisearch'][$itemtype])) {
            // Already indexed in its own right; its own hook queues it, and
            // GLPI allows only one callback per itemtype. The referrers are
            // picked up by the dispatcher below instead.
            continue;
        }

        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpisearch'][$itemtype] = 'plugin_glpisearch_referenced_renamed';
    }

    // The header search box, taken over in the browser rather than by
    // overriding core's template. The form still exists and still works: with
    // nothing selected, Enter submits it and lands on GLPI's own search page.
    // Nothing is taken away from anybody, including when this plugin is broken.
    if (Session::getCurrentInterface() === 'central' && Settings::flag('takeover_header')) {
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['glpisearch'] = 'js/search.js';
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['glpisearch']        = 'css/search.css';
    }

    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['glpisearch'] = Settings::secretKeys();

    // Offered to glpi-ai's assistant as tools. Registered unconditionally:
    // only glpi-ai reads this hook, so an instance without it pays one array
    // assignment and never loads the class — while guarding on
    // Plugin::isPluginActive('glpiai') would run a database lookup on every
    // request to avoid exactly that.
    $PLUGIN_HOOKS['glpiai_tools']['glpisearch'] = [\GlpiPlugin\Glpisearch\AiTools::class, 'all'];
}

function plugin_version_glpisearch()
{
    return [
        'name'         => 'GLPI Search',
        'version'      => PLUGIN_GLPISEARCH_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-search',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPISEARCH_MIN_GLPI]],
    ];
}

function plugin_glpisearch_check_prerequisites()
{
    return true;
}

function plugin_glpisearch_check_config($verbose = false)
{
    return true;
}
