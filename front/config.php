<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisearch\Client;
use GlpiPlugin\Glpisearch\Indexer;
use GlpiPlugin\Glpisearch\Queue;
use GlpiPlugin\Glpisearch\Schema;
use GlpiPlugin\Glpisearch\Settings;

Session::checkRight('plugin_glpisearch_config', READ);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/**
 * Read a bounded integer from the post, correcting rather than refusing.
 *
 * Deliberately not an HTML5 `min`/`max` pair. The browser refuses to submit a
 * form containing an out-of-range number and focuses the offending field —
 * which, on a long settings page, means the page appears to do nothing at all,
 * with the explanation attached to an input that is scrolled off screen.
 * Clamping here and saying so afterwards is the version people can act on.
 */
$clamped = [];
$boundedInt = static function (string $name, int $min, int $max, int $default, string $label) use (&$clamped): int {
    $posted = $_POST[$name] ?? null;

    if ($posted === null || trim((string) $posted) === '') {
        return $default;
    }

    $value = (int) $posted;
    $fixed = max($min, min($max, $value));

    if ($fixed !== $value) {
        $clamped[] = sprintf(
            __('%1$s must be between %2$d and %3$d. %4$d was saved as %5$d.', 'glpisearch'),
            $label,
            $min,
            $max,
            $value,
            $fixed
        );
    }

    return $fixed;
};

// ------------------------------------------------------------------ actions

$action = '';
foreach (['update', 'test', 'rebuild', 'index_now', 'drop'] as $candidate) {
    if (!empty($_POST[$candidate])) {
        $action = $candidate;
        break;
    }
}

if ($action !== '') {
    // No explicit Session::checkCSRF: GLPI 11's CheckCsrfListener validated and
    // consumed the token before this page ran, so a second check always fails.
    Session::checkRight('plugin_glpisearch_config', UPDATE);

    if ($action === 'update') {
        $types = array_values(array_intersect(
            (array) ($_POST['itemtypes'] ?? []),
            Schema::indexable()
        ));

        Settings::save([
            'enabled'          => !empty($_POST['enabled']) ? '1' : '0',
            'url'              => rtrim(trim((string) ($_POST['url'] ?? '')), '/'),
            'api_key'          => (string) ($_POST['api_key'] ?? ''),
            'index_prefix'     => (string) ($_POST['index_prefix'] ?? 'glpi'),
            'itemtypes'        => implode(',', $types),
            'index_all'        => !empty($_POST['index_all']) ? '1' : '0',
            'facets_enabled'   => !empty($_POST['facets_enabled']) ? '1' : '0',
            'synonyms'         => (string) ($_POST['synonyms'] ?? ''),
            'takeover_header'  => !empty($_POST['takeover_header']) ? '1' : '0',
            'per_type'         => (string) $boundedInt('per_type', 1, 25, 5, __('Results per type', 'glpisearch')),
            'min_chars'        => (string) $boundedInt('min_chars', 1, 10, 2, __('Minimum characters', 'glpisearch')),
            'batch'            => (string) $boundedInt('batch', 10, 10000, 500, __('Documents per run', 'glpisearch')),
            'overfetch'        => (string) $boundedInt('overfetch', 1, 20, 4, __('Candidates per result', 'glpisearch')),
            'semantic_ratio'   => (string) max(0.0, min(1.0, (float) ($_POST['semantic_ratio'] ?? 0.3))),
        ]);

        // The index prefix and the type list both change which indexes get
        // looked at, so what was discovered about the old ones is no longer
        // about anything.
        Schema::forgetEmbedders();

        // A type that was just unticked still has an index full of its records.
        // Removing it here is the difference between "stops being searched" and
        // "stops being stored", and only the second is what somebody unticking
        // it meant.
        $pruned = Indexer::prune();

        // And a type that was just *ticked* needs an index before the next
        // search, records or not — see Indexer::ensureAll().
        Indexer::ensureAll();

        Session::addMessageAfterRedirect(__s('Settings saved.', 'glpisearch'));

        if ($pruned !== []) {
            Session::addMessageAfterRedirect(sprintf(
                __s('Removed %d index(es) for types that are no longer indexed: %s', 'glpisearch'),
                count($pruned),
                $e(implode(', ', $pruned))
            ));
        }
    }

    if ($action === 'test') {
        $client = Client::fromSettings();

        if ($client === null) {
            Session::addMessageAfterRedirect(
                __s('Set a URL and an API key first.', 'glpisearch'),
                false,
                WARNING
            );
        } else {
            $result = $client->ping();
            Session::addMessageAfterRedirect(
                $e($result['ok']
                    ? sprintf(__('Connected to Meilisearch %s.', 'glpisearch'), $result['version'])
                    : $result['message']),
                false,
                $result['ok'] ? INFO : ERROR
            );
        }
    }

    if ($action === 'rebuild') {
        // Queues rather than indexes. A rebuild of a real instance is a hundred
        // thousand documents; doing it in this request would hold the page open
        // for as long as the instance has history, and the cron that already
        // exists does it a batch at a time.
        $result = Indexer::rebuild();
        Session::addMessageAfterRedirect(sprintf(
            __s('Queued %d record(s) across %d type(s). The index fills on the next cron run.', 'glpisearch'),
            $result['queued'],
            count($result['types'])
        ));
    }

    if ($action === 'index_now') {
        // One batch, not the backlog: this is the button that answers "is any
        // of this actually wired up", and it should answer in seconds.
        $result = Indexer::run();
        Session::addMessageAfterRedirect(
            $e($result['message']),
            false,
            $result['failed'] > 0 ? ERROR : INFO
        );
    }

    if ($action === 'drop') {
        $dropped = Indexer::drop();
        Queue::clear();
        Session::addMessageAfterRedirect(sprintf(
            __s('Dropped %d index(es) and emptied the queue. Rebuild to start again.', 'glpisearch'),
            $dropped
        ));
    }

    // Said out loud. A value quietly corrected is a value the administrator
    // still believes they set.
    foreach ($clamped as $note) {
        Session::addMessageAfterRedirect($e($note), false, WARNING);
    }

    Html::back();
}

// -------------------------------------------------------------------- render

Html::header(__('Search', 'glpisearch'), $_SERVER['PHP_SELF'], 'config', 'plugins');

$cfg      = Settings::all();
$selected = Settings::itemtypes();
$can_edit = Session::haveRight('plugin_glpisearch_config', UPDATE);

echo "<div class='container-fluid glpisearch-config' style='max-width:960px'>";
// No action attribute, deliberately. `$_SERVER['PHP_SELF']` is `/index.php`
// under GLPI 11's front controller, not this script — a form pointed at it
// posts to the router, which routes the post nowhere and redirects to the
// dashboard. The page then looks like it saved and has not. Omitting the
// attribute posts to the current URL, which is the only thing here that is
// reliably right.
echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// -------------------------------------------------------------------- status
//
// First on the page, not last. It is the answer to the question somebody opens
// this page asking — is search actually working — and the other settings pages
// in this family (AI, netscan) already lead with the same thing. It also holds
// the rebuild and drop actions, which are not settings and do not belong in
// the middle of a form somebody is part-way through filling in.

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Index status', 'glpisearch') . '</h3></div><div class="card-body">';

echo "<div class='d-flex align-items-center flex-wrap gap-2'>";
echo '<div>';

if ($status['ok']) {
    echo "<span class='badge " . ($status['total'] > 0 ? 'bg-green-lt' : 'bg-secondary-lt') . " me-1'>"
       . sprintf(__s('%s indexed', 'glpisearch'), number_format($status['total'])) . '</span>';

    foreach ($status['types'] as $itemtype => $count) {
        echo "<span class='badge bg-secondary-lt me-1'>"
           . $e($itemtype::getTypeName(2)) . ' ' . number_format($count) . '</span>';
    }
} else {
    echo "<span class='badge bg-red-lt me-1'>" . $e($status['error'] ?: __('unavailable', 'glpisearch'))
       . '</span>';
}

if ($status['queued'] > 0) {
    echo "<span class='badge bg-blue-lt me-1'>"
       . sprintf(__s('%s queued', 'glpisearch'), number_format($status['queued'])) . '</span>';
}

echo "<div class='form-text mt-1'>";
if (((int) $cfg['enabled']) !== 1) {
    echo "<span class='text-orange'>"
       . __s('Nothing will be indexed while the switch below is off.', 'glpisearch')
       . '</span> ';
}
echo __s('The queue drains on GLPI\'s cron, a batch at a time. Records queue themselves when they '
       . 'change; a rebuild is only needed after altering what a document contains — the indexed '
       . 'types, or the hybrid settings below.', 'glpisearch');
echo '</div>';
echo '</div>';

if ($can_edit) {
    echo "<div class='ms-auto'>";
    echo "<button type='submit' name='index_now' value='1' class='btn btn-sm btn-outline-secondary me-1'>"
       . "<i class='ti ti-player-play me-1'></i>" . __s('Index a batch now', 'glpisearch') . '</button>';
    echo "<button type='submit' name='rebuild' value='1' class='btn btn-sm btn-outline-primary me-1'>"
       . "<i class='ti ti-refresh me-1'></i>" . __s('Rebuild', 'glpisearch') . '</button>';
    echo "<button type='submit' name='drop' value='1' class='btn btn-sm btn-outline-danger'>"
       . "<i class='ti ti-trash me-1'></i>" . __s('Drop indexes', 'glpisearch') . '</button>';
    echo '</div>';
}

echo '</div>';
echo '</div></div>';


// ------------------------------------------------------------------ backend

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Meilisearch', 'glpisearch') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('GLPI\'s own search matches words exactly. This one tolerates typos, matches from the '
       . 'first keystroke, and answers in single-digit milliseconds — but it never decides who '
       . 'may see a record. Meilisearch filters by entity because that is cheap; GLPI is then '
       . 'asked about every result before it is shown.', 'glpisearch')
   . '</p>';

$on = ((int) $cfg['enabled']) === 1 ? "checked='checked'" : '';
echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='enabled' value='1' $on>";
echo "<span class='form-check-label'>" . __s('Enable search', 'glpisearch') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('Off means nothing is queued, nothing is indexed, and the header box behaves exactly as '
       . 'GLPI ships it.', 'glpisearch')
   . '</div>';

echo "<div class='row'>";

echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('URL', 'glpisearch') . '</label>';
echo "<input type='text' class='form-control' name='url' value='" . $e($cfg['url'])
   . "' placeholder='http://meilisearch:7700'>";
echo "<div class='form-text'>"
   . __s('As GLPI reaches it, not as your browser does. On the dev stack that is '
       . 'http://meilisearch:7700.', 'glpisearch')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('API key', 'glpisearch') . '</label>';
echo "<input type='password' class='form-control' name='api_key' autocomplete='new-password' value='"
   . ($cfg['api_key'] !== '' ? $e(Settings::SECRET_PLACEHOLDER) : '') . "'>";
echo "<div class='form-text'>"
   . __s('Needs write access: this plugin creates indexes and pushes documents, so a search-only '
       . 'key is not enough. Stored encrypted with GLPI\'s key.', 'glpisearch')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Index prefix', 'glpisearch') . '</label>';
echo "<input type='text' class='form-control' name='index_prefix' value='" . $e($cfg['index_prefix']) . "'>";
echo "<div class='form-text'>"
   . __s('One index per itemtype, named prefix_ticket, prefix_computer and so on. Change it only '
       . 'if two GLPI instances share one Meilisearch — and drop the old indexes afterwards, '
       . 'because nothing will be looking at them any more.', 'glpisearch')
   . '</div></div>';

echo '</div>';

echo "<div class='mt-2'>";
echo "<button type='submit' name='test' value='1' class='btn btn-sm btn-outline-secondary'>"
   . "<i class='ti ti-plug-connected me-1'></i>" . __s('Test connection', 'glpisearch') . '</button>';
echo '</div>';

echo '</div></div>';

// ------------------------------------------------------------------- content

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('What gets indexed', 'glpisearch') . '</h3></div><div class="card-body">';

$all_on = ((int) $cfg['index_all']) === 1;
echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='index_all' value='1' "
   . ($all_on ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Offer every itemtype', 'glpisearch') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . sprintf(
       __s('The list below is GLPI\'s own globally searchable types plus a few obvious omissions '
           . '(%1$d types). Ticking this widens it to every itemtype with a table and a name — '
           . 'currently %2$d, including plugin types — because "can I find it by typing part of '
           . 'its name" has no good reason to stop at thirty. You still choose which of them to '
           . 'index; an index nobody queries costs a few hundred kilobytes.', 'glpisearch'),
       count(Schema::indexable()),
       count(Schema::indexable())
   )
   . '</div>';

echo "<div class='row'>";
foreach (Schema::indexable() as $itemtype) {
    $checked = in_array($itemtype, $selected, true) ? "checked='checked'" : '';
    echo "<div class='col-md-3'><label class='form-check'>";
    echo "<input type='checkbox' class='form-check-input' name='itemtypes[]' value='"
       . $e($itemtype) . "' $checked>";
    echo "<span class='form-check-label'>" . $e($itemtype::getTypeName(2)) . '</span>';
    echo '</label></div>';
}
echo '</div>';

echo "<div class='form-text mt-2 mb-3'>"
   . __s('Each type gets its own index, because the field that identifies a record best is not the '
       . 'same for a ticket as for a computer — a serial number is decisive on one and meaningless '
       . 'on the other. Unticking a type removes its documents on the next run rather than '
       . 'freezing them in place.', 'glpisearch')
   . '</div>';

echo "<div class='row'>";

echo "<div class='col-md-3 mb-3'><label class='form-label'>" . __s('Results per type', 'glpisearch') . '</label>';
echo "<input type='number' class='form-control' name='per_type' value='" . $e($cfg['per_type']) . "'>";
echo '</div>';

echo "<div class='col-md-3 mb-3'><label class='form-label'>" . __s('Minimum characters', 'glpisearch') . '</label>';
echo "<input type='number' class='form-control' name='min_chars' value='" . $e($cfg['min_chars']) . "'>";
echo '</div>';

echo "<div class='col-md-3 mb-3'><label class='form-label'>" . __s('Documents per run', 'glpisearch') . '</label>';
echo "<input type='number' class='form-control' name='batch' value='" . $e($cfg['batch']) . "'>";
echo "<div class='form-text'>" . __s('Per cron run.', 'glpisearch') . '</div></div>';

echo "<div class='col-md-3 mb-3'><label class='form-label'>" . __s('Candidates per result', 'glpisearch') . '</label>';
echo "<input type='number' class='form-control' name='overfetch' value='" . $e($cfg['overfetch']) . "'>";
echo "<div class='form-text'>"
   . __s('How many hits to fetch for each row shown, before GLPI\'s rights check thins them. '
       . 'Raise it only if restricted technicians see short result lists.', 'glpisearch')
   . '</div></div>';

echo '</div>';

$facets_on = ((int) $cfg['facets_enabled']) === 1 ? "checked='checked'" : '';
echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='facets_enabled' value='1' $facets_on>";
echo "<span class='form-check-label'>" . __s('Count facet values alongside results', 'glpisearch')
   . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('Every foreign key a record has — its category, location, status, the people on it — is '
       . 'written into the document as a name, which is both what makes those words searchable '
       . 'and what lets them be counted. Switching this off keeps the searching and stops the '
       . 'counting. Note the counts come from Meilisearch, so they are taken before GLPI\'s '
       . 'per-record rights check: a restricted technician may see "New (40)" above twelve rows.',
       'glpisearch')
   . '</div>';

echo "<div class='mb-3'><label class='form-label'>" . __s('Jargon', 'glpisearch') . '</label>';
echo "<textarea class='form-control font-monospace' name='synonyms' rows='5'>"
   . $e($cfg['synonyms']) . '</textarea>';
echo "<div class='form-text'>"
   . __s('One rule per line. This closes a gap typo tolerance cannot: a user says "the VPN is '
       . 'down" and the ticket says AnyConnect, or says "the copier" and the asset is a Ricoh '
       . 'MFP. Neither is a misspelling — the words are simply different.<br>'
       . '<code>copier &lt;=&gt; printer, mfp</code> — every word finds every other. Usually what '
       . 'you want, because the technician cannot know which word this instance wrote down.<br>'
       . '<code>vpn =&gt; anyconnect</code> — one-way, for when the reverse would be wrong: '
       . 'somebody typing a product name is being specific on purpose.<br>'
       . 'Takes effect on the next indexing run.', 'glpisearch')
   . '</div></div>';

$takeover = ((int) $cfg['takeover_header']) === 1 ? "checked='checked'" : '';
echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='takeover_header' value='1' $takeover>";
echo "<span class='form-check-label'>" . __s('Use it for the header search box', 'glpisearch') . '</span></label>';
echo "<div class='form-text'>"
   . __s('The box in the top bar answers as you type instead of on Enter. The form underneath is '
       . 'untouched: with nothing selected, Enter still submits it and lands on GLPI\'s own search '
       . 'page, so nothing is taken away — including when this plugin is broken.', 'glpisearch')
   . '</div>';

echo '</div></div>';

// -------------------------------------------------------------------- hybrid
// Fetched here rather than in the status card below, because both need it and
// it costs a round trip per index.
$status       = Indexer::status();
$status_ready = $status['ok'];

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Search by meaning', 'glpisearch') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('Typing "vpn keeps dropping" will not find a ticket that only ever says "AnyConnect '
       . 'disconnects" — the words are not there to match. Meilisearch can rank by meaning as '
       . 'well as by word, and it does all of it: it calls the embedding model, stores the '
       . 'vectors, and keeps them in step with the documents.', 'glpisearch')
   . '</p>';

echo '<p class="text-muted">'
   . __s('So there is nothing to switch on here. Configure an embedder on the Meilisearch index '
       . 'and searches against it become hybrid automatically — no model, no URL and no second '
       . 'API key in GLPI, because asking for the same thing twice is only a second place for it '
       . 'to be wrong.', 'glpisearch')
   . '</p>';

echo "<div class='mb-3'>";
if (!$status_ready) {
    echo "<span class='badge bg-secondary-lt'>"
       . __s('cannot tell — Meilisearch is unreachable', 'glpisearch') . '</span>';
} elseif ($status['embedders'] === []) {
    echo "<span class='badge bg-secondary-lt'>" . __s('no embedder configured', 'glpisearch')
       . "</span> <span class='form-text'>"
       . __s('Searching by word only.', 'glpisearch') . '</span>';
} else {
    foreach ($status['embedders'] as $itemtype => $embedder) {
        echo "<span class='badge bg-green-lt me-1'>"
           . $e($itemtype::getTypeName(2)) . ' · ' . $e($embedder) . '</span>';
    }
    echo "<div class='form-text'>"
       . __s('These indexes are searched by meaning as well as by word.', 'glpisearch') . '</div>';
}
echo '</div>';

echo "<div class='row'>";
echo "<div class='col-md-4 mb-3'><label class='form-label'>" . __s('Semantic ratio', 'glpisearch') . '</label>';
echo "<input type='text' class='form-control' name='semantic_ratio' value='" . $e($cfg['semantic_ratio']) . "'>";
echo "<div class='form-text'>"
   . __s('The one thing that is a query-time choice rather than server configuration — '
       . 'Meilisearch has no index-level default for it. 0.0 is pure keyword, 1.0 is pure '
       . 'meaning. Start low: keyword results are the ones people can predict, and a search that '
       . 'reorders itself for reasons the user cannot see feels broken even when it is right. '
       . 'Ignored entirely where no embedder is configured.', 'glpisearch')
   . '</div></div>';
echo '</div>';

echo "<div class='alert alert-warning mb-0'><strong>"
   . __s('One caution about configuring an embedder.', 'glpisearch') . '</strong> '
   . __s('Meilisearch processes its tasks in a single queue. An embedder pointed at a model that '
       . 'hangs will wedge that queue on the first document it tries to embed, and indexing stops '
       . 'for every index — not just the one with the embedder. Check a new embedder against a '
       . 'small index before turning it loose on a ticket history.', 'glpisearch')
   . '</div>';

echo '</div></div>';


if ($can_edit) {
    echo "<div class='text-end mb-4'><button type='submit' name='update' value='1' class='btn btn-primary'>"
       . __s('Save', 'glpisearch') . '</button></div>';
}

echo '</form></div>';

Html::footer();
