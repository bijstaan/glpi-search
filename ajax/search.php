<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The header search box's backend.
 *
 * One action, and everything it returns has already been through GLPI's own
 * rights check — see Visibility. The endpoint itself adds only the two gates
 * that belong at the door: you must be logged in, and you must be on the
 * technician interface, which is where the assets are registered.
 *
 * No CSRF check here. GLPI 11's CheckCsrfListener already validated the request
 * before this script ran, taking the token from the `X-Glpi-Csrf-Token` header
 * and *preserving* it — which is what lets a search box fire a request per
 * keystroke without draining the session's token pool. Re-checking would reject
 * requests whose token the kernel has already consumed.
 */

use GlpiPlugin\Glpisearch\Dossier;
use GlpiPlugin\Glpisearch\Finder;
use GlpiPlugin\Glpisearch\Settings;

$degraded = false;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

if ((int) Session::getLoginUserID() <= 0) {
    $respond(['error' => 'unauthenticated'], 401);
}

if (Session::getCurrentInterface() !== 'central') {
    $respond(['error' => 'forbidden'], 403);
}

// Everything about one person, for when the question is "who is calling and
// what have they got" rather than "find me a record". Answered from the
// database rather than the index: the id is already known, and what is left is
// a handful of targeted lookups the database does better.
if ((string) ($_POST['action'] ?? '') === 'dossier') {
    $dossier = Dossier::forUser((int) ($_POST['users_id'] ?? 0));

    // Null covers both "no such user" and "not yours to see", deliberately
    // indistinguishable: telling them apart would confirm which user ids exist
    // to somebody with no right to any of them.
    $respond($dossier === null ? ['dossier' => null] : ['dossier' => $dossier]);
}

$term = trim((string) ($_POST['q'] ?? ''));

// Facet choices, as attribute => [values]. Both halves arrive from the browser
// and neither is trusted: Finder drops any attribute the index does not declare
// as filterable, and quotes every value. Passing them through unchecked would
// let a crafted request rewrite the filter expression that carries the entity
// restriction.
$filters = [];
foreach ((array) ($_POST['facets'] ?? []) as $attribute => $values) {
    if (!is_string($attribute) || !is_array($values)) {
        continue;
    }

    $clean = [];
    foreach ($values as $value) {
        if (is_string($value) && $value !== '') {
            $clean[] = $value;
        }
    }

    if ($clean !== []) {
        $filters[$attribute] = $clean;
    }
}

if ($term === '' && $filters === []) {
    $respond(['query' => '', 'groups' => [], 'facets' => [], 'available' => Finder::isAvailable()]);
}

$facets = [];
$groups = Finder::search($term, [], (int) Settings::get('per_type'), $degraded, $filters, $facets);

// `available` is not decoration. The browser uses it to decide whether to keep
// its dropdown open or hand the keystroke back to the form it took over, which
// is how a Meilisearch that has gone away turns back into GLPI's own search
// box rather than into a box that silently stops answering.
$respond([
    'query'     => $term,
    'available' => Finder::isAvailable(),
    'groups'    => $groups,
    // Counted by Meilisearch, which means counted before GLPI's per-record
    // rights check. Flagged rather than silently rounded: see Finder::collect().
    'facets'    => $facets,
    'approximate_facets' => true,
    'applied'   => $filters,
]);
