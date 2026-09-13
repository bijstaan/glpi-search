<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * glpi-search against a real Meilisearch.
 *
 * There is no mock here on purpose. Almost every claim worth asserting in this
 * plugin is a claim about what Meilisearch *does* — that a typo still matches,
 * that a filter excludes, that replacing a document removes the old terms — and
 * a stand-in would only prove that the plugin sent what it meant to send. The
 * dev stack ships a Meilisearch for exactly this reason.
 *
 *   docker compose -p glpi exec glpi sh -c \
 *     'cd /var/www/glpi/plugins/glpisearch && tests/run.sh'
 *
 * It restores the plugin's configuration and purges its fixtures on the way
 * out, including after a failure.
 */

require '/var/www/glpi/vendor/autoload.php';

// This suite logs in twice — once as an administrator and once as a restricted
// technician — and GLPI's Session::init() calls session_regenerate_id() on
// every login, which throws under CLI where there is no real session. Core
// guards that call with TU_USER for exactly this reason, so the visibility
// half of this file is only reachable with it defined.
if (!defined('TU_USER')) {
    define('TU_USER', 'glpisearch-suite');
}

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpisearch\Client;
use GlpiPlugin\Glpisearch\Documents;
use GlpiPlugin\Glpisearch\Dossier;
use GlpiPlugin\Glpisearch\Finder;
use GlpiPlugin\Glpisearch\Indexer;
use GlpiPlugin\Glpisearch\Queue;
use GlpiPlugin\Glpisearch\Schema;
use GlpiPlugin\Glpisearch\Settings;
use GlpiPlugin\Glpisearch\Visibility;

/** @var DBmysql $DB */
global $DB;

if (!(new Auth())->login('glpi', 'glpi', true)) {
    fwrite(STDERR, "could not log in as glpi/glpi\n");
    exit(1);
}

(new Plugin())->init(true);

$failures = [];
$checks   = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures, $checks;

    $checks++;
    if ($ok) {
        echo "  ok   $what\n";

        return;
    }

    $failures[] = $what . ($detail !== '' ? " ($detail)" : '');
    echo "  FAIL $what" . ($detail !== '' ? " ($detail)" : '') . "\n";
}

// --------------------------------------------------------------- fixtures

$made   = ['Ticket' => [], 'Entity' => [], 'User' => [], 'Profile_User' => [], 'ITILCategory' => [], 'Profile' => []];
$prefix = 'glpisearch-test';

// Configuration is restored from raw config rows rather than through the
// settings API, because the write path encrypts a secured config and the read
// path does not decrypt it — restoring through the API would add a layer of
// ciphertext per run. The same trap this repository has walked into before.
$before = iterator_to_array(
    $DB->request(['FROM' => 'glpi_configs', 'WHERE' => ['context' => 'plugin:glpisearch']]),
    false
);

register_shutdown_function(static function () use (&$made, $before, $prefix): void {
    /** @var DBmysql $DB */
    global $DB;

    foreach ($made['Profile_User'] as $id) {
        $DB->delete('glpi_profiles_users', ['id' => $id]);
    }
    foreach ($made['User'] as $id) {
        $DB->delete('glpi_profiles_users', ['users_id' => $id]);
        (new User())->delete(['id' => $id], true);
    }
    foreach ($made['Ticket'] as $id) {
        (new Ticket())->delete(['id' => $id], true);
    }
    foreach ($made['ITILCategory'] as $id) {
        (new ITILCategory())->delete(['id' => $id], true);
    }
    foreach ($made['Profile'] as $id) {
        $DB->delete('glpi_profilerights', ['profiles_id' => $id]);
        $DB->delete('glpi_profiles_users', ['profiles_id' => $id]);
        (new Profile())->delete(['id' => $id], true);
    }
    foreach ($made['Entity'] as $id) {
        (new Entity())->delete(['id' => $id], true);
    }

    // The test indexes go FIRST, by their literal names, and before the
    // configuration is put back.
    //
    // This is not fussiness. Deriving the names through Settings/Schema after
    // restoring the config resolves them against the *restored* prefix — which
    // is the real one — and the cleanup then cheerfully deletes the instance's
    // working indexes. That is not hypothetical: this suite did exactly that on
    // its first run.
    $client = Client::fromSettings();
    if ($client !== null) {
        foreach (Schema::indexable() as $itemtype) {
            $uid = $prefix . '_' . strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $itemtype) ?? '');

            try {
                $client->deleteIndex($uid);
            } catch (Throwable) {
                // Nothing to do about it here, and the suite is over.
            }
        }
    }

    Queue::clear();

    $DB->delete('glpi_configs', ['context' => 'plugin:glpisearch']);
    foreach ($before as $row) {
        unset($row['id']);
        $DB->insert('glpi_configs', $row);
    }

    echo "\n(configuration restored, fixtures purged)\n";
});

// ----------------------------------------------------------- reachability

echo "\nMeilisearch\n";

$client = Client::fromSettings();

if ($client === null) {
    fwrite(STDERR, "glpi-search is not configured; set a URL and API key first.\n");
    exit(1);
}

$ping = $client->ping();
check('the server answers and the key works', $ping['ok'], $ping['message']);

if (!$ping['ok']) {
    fwrite(STDERR, "cannot continue without Meilisearch\n");
    exit(1);
}

// Isolated from whatever the instance is really using, so a test run cannot
// disturb a working index.
Settings::save(['enabled' => '1', 'index_prefix' => $prefix, 'itemtypes' => 'Ticket', 'min_chars' => '2']);
Queue::clear();

// Safe only because the prefix above is already in force: Indexer::drop() reads
// it from the settings, and calling this a line earlier would have dropped the
// instance's real indexes.
Indexer::drop();

// ------------------------------------------------------------- fixtures

$entity = new Entity();
$acme   = (int) $entity->add(['name' => 'glpisearch-acme', 'entities_id' => 0]);
$other  = (int) $entity->add(['name' => 'glpisearch-other', 'entities_id' => 0]);
$made['Entity'] = [$acme, $other];

$ticket = new Ticket();
$make   = static function (string $name, string $content, int $entities_id) use ($ticket, &$made): int {
    $id = (int) $ticket->add([
        'name'        => $name,
        'content'     => $content,
        'entities_id' => $entities_id,
        '_auto_import' => true,
    ]);
    $made['Ticket'][] = $id;

    return $id;
};

$printer = $make('Printer mapping issues on the second floor', 'The queue stalls and nothing prints.', $acme);
$vpn     = $make('AnyConnect disconnects after the update', 'The tunnel drops every few minutes.', $acme);
$secret  = $make('Printer fault at the other tenant', 'Should never be visible to Acme staff.', $other);

check('fixtures created', $printer > 0 && $vpn > 0 && $secret > 0);

// -------------------------------------------------------------- indexing

echo "\nIndexing\n";

check('the hooks queued the new tickets', Queue::pending() >= 3, (string) Queue::pending());

$run = Indexer::run();
check('a run reports what it did', $run['indexed'] >= 3, $run['message']);
check('and drains the queue', Queue::pending() === 0, (string) Queue::pending());

$status = Indexer::status();
check('the index reports documents', ($status['types']['Ticket'] ?? 0) >= 3, json_encode($status['types']));

// ---------------------------------------------------------------- typos

echo "\nMatching\n";

$hits = static function (string $q, int $per = 20): array {
    $groups = Finder::search($q, ['Ticket'], $per);
    $ids    = [];
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $ids[] = (int) $item['id'];
        }
    }

    return $ids;
};

check('an exact word matches', in_array($printer, $hits('printer'), true));

// The assertion the whole plugin exists for. GLPI's own `contains` cannot do
// this, so it is the one that proves the index is really in the path.
check('a single typo still matches', in_array($printer, $hits('priner'), true));
check('a dropped letter still matches', in_array($printer, $hits('prnter'), true));
check('a prefix matches from part of a word', in_array($vpn, $hits('anycon'), true));

check('a query matching nothing returns nothing', $hits('zzzzqqqqxxxx') === []);

// Below min_chars this refuses rather than answers, and says so — the caller
// needs to tell that apart from a genuine miss or it cannot fall back.
$degraded = false;
Finder::search('p', ['Ticket'], 5, $degraded);
check('a too-short query is reported as degraded, not as empty', $degraded === true);

$degraded = false;
$hits('printer');
Finder::search('printer', ['Ticket'], 5, $degraded);
check('a real answer is not degraded', $degraded === false);

// ----------------------------------------------------------- visibility

echo "\nVisibility\n";

// A technician who may see one entity and not the other. The point is not that
// the filter is built correctly — it is that a record from the other entity is
// absent from the results, and that the same query as an administrator finds
// it, so an empty index cannot make this pass.
$admin_sees = $hits('printer');
check('an administrator sees both printer tickets',
    in_array($printer, $admin_sees, true) && in_array($secret, $admin_sees, true),
    implode(',', $admin_sees));

$tech_profile = (int) ($DB->request([
    'SELECT' => ['id'],
    'FROM'   => 'glpi_profiles',
    'WHERE'  => ['name' => 'Technician'],
    'LIMIT'  => 1,
])->current()['id'] ?? 0);

$user = new User();
$uid  = (int) $user->add([
    'name'      => 'glpisearch-probe',
    'password'  => 'probe-pw-1',
    'password2' => 'probe-pw-1',
    'is_active' => 1,
]);
$made['User'][] = $uid;

$DB->delete('glpi_profiles_users', ['users_id' => $uid]);
$pu = new Profile_User();
$made['Profile_User'][] = (int) $pu->add([
    'users_id'     => $uid,
    'profiles_id'  => $tech_profile,
    'entities_id'  => $acme,
    'is_recursive' => 0,
]);
$user->update(['id' => $uid, 'entities_id' => $acme]);

// Swap sessions. Everything after this runs as the restricted technician.
$session_backup = $_SESSION;

check('the probe technician can log in', (bool) (new Auth())->login('glpisearch-probe', 'probe-pw-1', true));
(new Plugin())->init(true);

$filter = Visibility::filterFor('Ticket');
check('the entity filter names only the entities in session',
    is_string($filter) && str_contains($filter, (string) $acme) && !str_contains($filter, "[$other]"),
    (string) $filter);

$tech_sees = $hits('printer');
check('the technician sees their own entity\'s ticket', in_array($printer, $tech_sees, true),
    implode(',', $tech_sees));
check('and NOT the other entity\'s ticket', !in_array($secret, $tech_sees, true),
    implode(',', $tech_sees));

$_SESSION = $session_backup;
(new Plugin())->init(true);

// ------------------------------------------------------------ lifecycle

echo "\nLifecycle\n";

$ticket->update(['id' => $vpn, 'name' => 'Renamed to Blorptastic subsystem']);
Indexer::run();

check('a renamed record is found by its new name', in_array($vpn, $hits('blorptastic'), true));
// Replaced, not merged. A partial update would leave the old title's terms
// behind and the record would answer to both names forever.
check('and no longer by its old one', !in_array($vpn, $hits('anyconnect'), true));

$ticket->delete(['id' => $vpn]);
Indexer::run();
check('a trashed record leaves the index', !in_array($vpn, $hits('blorptastic'), true));

$ticket->restore(['id' => $vpn]);
Indexer::run();
check('and comes back when restored', in_array($vpn, $hits('blorptastic'), true));

$ticket->delete(['id' => $vpn], true);
Indexer::run();
check('a purged record leaves the index', !in_array($vpn, $hits('blorptastic'), true));
array_splice($made['Ticket'], array_search($vpn, $made['Ticket'], true), 1);

// --------------------------------------------------------------- degrade

echo "\nDegrading\n";

// Pointed at a port nothing is listening on. The contract is that a search
// returns nothing *and says so*, so that callers fall back rather than showing
// an empty box.
$live_url = Settings::get('url');
Settings::save(['url' => 'http://127.0.0.1:9']);

$degraded = false;
$groups   = Finder::search('printer', ['Ticket'], 5, $degraded);
check('an unreachable backend returns no groups', $groups === []);
check('and reports itself degraded', $degraded === true);

// Queued explicitly: by this point the suite has drained everything, and a run
// with nothing to do would report "nothing to index" — which is true, and would
// have made this pass without testing anything.
Queue::clear();
Queue::mark('Ticket', $printer, Queue::UPSERT);
check('there is work to fail at', Queue::pending() === 1, (string) Queue::pending());

$down = Indexer::run();
check('indexing against an unreachable backend reports failure', $down['failed'] > 0, $down['message']);

// The property that matters. Clearing the queue on a failed push would turn one
// unreachable-Meilisearch cron run into a permanent hole in the index that
// nothing would ever notice.
check('and leaves the queued work alone for the next run', Queue::pending() === 1,
    (string) Queue::pending());

// Put back immediately. Every check after this point talks to Meilisearch, and
// leaving the plugin pointed at a dead port made all of them fail for a reason
// that had nothing to do with what they were testing.
Settings::save(['url' => $live_url]);
Queue::clear();
Indexer::run();

// ---------------------------------------------------------------- shapes

echo "\nDocuments\n";

$doc = Documents::forItem((static function () use ($printer): Ticket {
    $t = new Ticket();
    $t->getFromDB($printer);

    return $t;
})());

check('a document has an id Meilisearch will accept',
    is_array($doc) && preg_match('/^[A-Za-z0-9_-]+$/', (string) $doc['id']) === 1,
    (string) ($doc['id'] ?? ''));
check('it carries the fields the filter needs',
    isset($doc['entities_id'], $doc['is_recursive'], $doc['itemtype'], $doc['items_id']));
check('it carries a title for display', ($doc['title'] ?? '') !== '');
check('and no HTML from the rich-text content',
    !str_contains((string) ($doc['content'] ?? ''), '<'));

check('an unscoped type is marked as such, not filtered by entity',
    !Schema::isEntityScoped(User::class)
    && Schema::isEntityScoped(Ticket::class));

// ------------------------------------------- unscoped types and subtitles

echo "\nUnscoped types\n";

// A user's entities_id is the default entity for records they *create*. It is
// not where they belong and not what they can see, both of which come out of
// glpi_profiles_users — so a user whose profile is on the root entity and whose
// default is an entity's must not be labelled with that entity.
$probe_user = new User();
$probe_id   = (int) $probe_user->add([
    'name'        => 'glpisearch-subtitle',
    'password'    => 'probe-pw-2',
    'password2'   => 'probe-pw-2',
    'realname'    => 'Kowalczyk',
    'firstname'   => 'Marta',
    'is_active'   => 1,
    // Deliberately a different entity from any profile they will hold.
    'entities_id' => $other,
]);
$made['User'][] = $probe_id;

$DB->insert('glpi_useremails', [
    'users_id'   => $probe_id,
    'email'      => 'marta.k@glpisearch-probe.test',
    'is_default' => 1,
]);

$probe_user->getFromDB($probe_id);
$user_doc = Documents::forItem($probe_user);

check('an unscoped type is indexed with the unscoped entity id',
    (int) ($user_doc['entities_id'] ?? 0) === Schema::UNSCOPED_ENTITY,
    (string) ($user_doc['entities_id'] ?? ''));

// The bug this asserts against: the document declared entities_id = -1 because
// the column cannot be trusted to decide visibility, and then printed that same
// column underneath the record as though it could.
$other_name = Dropdown::getDropdownName('glpi_entities', $other);
check('and its subtitle does not claim an entity',
    !str_contains((string) ($user_doc['subtitle'] ?? ''), (string) $other_name),
    (string) ($user_doc['subtitle'] ?? ''));

check('it shows something that actually identifies the record instead',
    str_contains((string) ($user_doc['subtitle'] ?? ''), 'Kowalczyk'),
    (string) ($user_doc['subtitle'] ?? ''));

// Emails are their own table, so nothing that walks columns will find them.
check('a user carries their email addresses',
    in_array('marta.k@glpisearch-probe.test', (array) ($user_doc['email'] ?? []), true),
    json_encode($user_doc['email'] ?? null));

$entity_doc = Documents::forItem((static function () use ($printer): Ticket {
    $t = new Ticket();
    $t->getFromDB($printer);

    return $t;
})());

check('an entity-scoped type still shows its entity, because there it is the truth',
    str_contains((string) ($entity_doc['subtitle'] ?? ''), 'glpisearch-acme'),
    (string) ($entity_doc['subtitle'] ?? ''));

// ------------------------------------------------------- related data

echo "\nRelated data\n";

$category = new ITILCategory();
$cat_id   = (int) $category->add(['name' => 'Glpisearch Widgets', 'entities_id' => 0, 'is_recursive' => 1]);
$made['ITILCategory'][] = $cat_id;

$ticket->update(['id' => $printer, 'itilcategories_id' => $cat_id]);
Indexer::run();

$doc = Documents::forItem((static function () use ($printer): Ticket {
    $t = new Ticket();
    $t->getFromDB($printer);

    return $t;
})());

check('a document carries the names of what it points at',
    ($doc['itilcategories'] ?? '') === 'Glpisearch Widgets',
    (string) ($doc['itilcategories'] ?? ''));

check('and the labels behind coded fields', ($doc['status'] ?? '') !== '');

// The payoff, and the reason this is denormalised rather than joined: a word
// that appears nowhere in the ticket still finds it. Meilisearch's own joins
// cannot do this — joined text is not searchable — which is what settled the
// design.
check('a word only present on a related record finds the ticket',
    in_array($printer, $hits('widgets'), true),
    implode(',', $hits('widgets')));

// ------------------------------------------------------------- facets

echo "\nFacets\n";

$facets   = [];
$degraded = false;
Finder::search('printer', ['Ticket'], 20, $degraded, [], $facets);

check('a search reports facet counts', ($facets['status'] ?? []) !== [], json_encode($facets['status'] ?? []));
check('including the ones built from relations',
    array_key_exists('itilcategories', $facets), implode(',', array_keys($facets)));

$filtered = static function (array $filters) use (&$degraded): array {
    $f   = [];
    $out = [];
    foreach (Finder::search('', ['Ticket'], 20, $degraded, $filters, $f) as $group) {
        foreach ($group['items'] as $item) {
            $out[] = (int) $item['id'];
        }
    }

    return $out;
};

// No query text at all. Browsing by facet is a real request — "every open
// ticket in this category" contains no words — so the minimum length applies
// to typing, not to asking.
$by_category = $filtered(['itilcategories' => ['Glpisearch Widgets']]);
check('a facet filter alone, with no query, returns the matching records',
    in_array($printer, $by_category, true), implode(',', $by_category));
check('and excludes what does not match', !in_array($secret, $by_category, true));

check('an unknown facet attribute is dropped rather than passed through',
    $filtered(['not_a_real_attribute' => ['x']]) !== [],
    'an unknown attribute must not filter anything out, nor break the query');

// The filter expression carries the entity restriction, so anything the browser
// can inject into it is a visibility bug rather than a syntax one.
check('a facet value containing a quote does not break the filter',
    is_array($filtered(['itilcategories' => ["Glpisearch' OR is_recursive = 1 OR '"]])),
    'must return cleanly, not error');

// A facet counted against the filtered set collapses to the chosen value, which
// takes the alternatives away at the moment somebody wants them. The fix is an
// extra query per filtered attribute; this is the assertion that it happened.
$narrowed = [];
Finder::search('', ['Ticket'], 20, $degraded, ['status' => [$doc['status']]], $narrowed);

check('the filtered facet still offers its other values',
    count($narrowed['status'] ?? []) >= count($facets['status'] ?? []),
    json_encode(['before' => $facets['status'] ?? [], 'after' => $narrowed['status'] ?? []]));

// -------------------------------------------- a type with nothing in it

echo "\nEmpty types\n";

// Meilisearch rejects an entire multi-search if any one query names an index
// that does not exist — so a single configured itemtype with no records used to
// take down every search on the instance, for every type. The symptom gave no
// hint of the cause: tick a type you have none of, and searching for a printer
// stops working.
$empty_type = 'Certificate';
$kept_types = Settings::get('itemtypes');
Settings::save(['itemtypes' => $kept_types . ',' . $empty_type]);

check('the empty type is now configured',
    in_array($empty_type, Settings::itemtypes(), true), Settings::get('itemtypes'));

Indexer::ensureAll();

$degraded = false;
$still    = Finder::search('printer', [], 5, $degraded);

check('a configured type with no records does not break every other search',
    $still !== [] && !$degraded,
    json_encode(['groups' => count($still), 'degraded' => $degraded]));

Settings::save(['itemtypes' => $kept_types]);
Indexer::prune();

// ---------------------------------------------------------------- dossier

echo "\nDossier\n";

$caller = new User();
$caller_id = (int) $caller->add([
    'name'        => 'glpisearch-caller-test',
    'password'    => 'probe-pw-5',
    'password2'   => 'probe-pw-5',
    'realname'    => 'Okonkwo',
    'firstname'   => 'Ada',
    'is_active'   => 1,
    'entities_id' => $acme,
]);
$made['User'][] = $caller_id;

// A profile in the entity the ticket will live in. Without one GLPI declines to
// attach them as its requester — quietly, by simply not writing the link row —
// and the dossier then correctly reports that they have raised nothing.
(new Profile_User())->add([
    'users_id'     => $caller_id,
    'profiles_id'  => $tech_profile,
    'entities_id'  => $acme,
    'is_recursive' => 1,
]);

$their_ticket = (int) $ticket->add([
    'name'                => 'Their own laptop will not wake',
    'content'             => 'Reported by phone.',
    'entities_id'         => $acme,
    '_auto_import'        => true,
    '_users_id_requester' => $caller_id,
]);
$made['Ticket'][] = $their_ticket;

$dossier = Dossier::forUser($caller_id);

check('a dossier is built for a real user', $dossier !== null);
check('it names them', ($dossier['user']['realname'] ?? '') === 'Okonkwo Ada',
    (string) ($dossier['user']['realname'] ?? ''));
check('it lists the tickets they raised',
    in_array($their_ticket, array_column($dossier['tickets'], 'id'), true),
    json_encode(array_column($dossier['tickets'], 'id')));
check('and counts them', ($dossier['counts']['open'] ?? 0) >= 1,
    json_encode($dossier['counts'] ?? []));

// Somebody else's ticket is not theirs, however much it matches.
check('it does not list tickets they did not raise',
    !in_array($printer, array_column($dossier['tickets'], 'id'), true),
    json_encode(array_column($dossier['tickets'], 'id')));

check('a user who does not exist has no dossier', Dossier::forUser(99999999) === null);
check('and neither does id zero', Dossier::forUser(0) === null);

// ------------------------------------------------- renaming a referenced record

echo "\nRenaming\n";

Queue::clear();
$category->update(['id' => $cat_id, 'name' => 'Glpisearch Sprockets']);

check('renaming a referenced record queues the records that name it',
    Queue::pending() > 0, (string) Queue::pending());

Indexer::run();

check('after which it is found by the new name', in_array($printer, $hits('sprockets'), true),
    implode(',', $hits('sprockets')));
check('and no longer by the old one', !in_array($printer, $hits('widgets'), true),
    implode(',', $hits('widgets')));

// Touching a referenced record without changing its name changes no *other*
// document, and queueing thousands of re-indexes for a comment edit would be a
// quiet performance bug that only shows up on a big instance.
//
// The category itself may still be queued, and should be: it is an indexable
// type in its own right and its comment is one of the fields indexed for it.
// What must not happen is the tickets that merely name it being dragged along.
Queue::clear();
$category->update(['id' => $cat_id, 'comment' => 'edited, but the name is the same']);

$dragged = 0;
foreach ($DB->request(['FROM' => Queue::TABLE]) as $row) {
    if ((string) $row['itemtype'] === Ticket::class) {
        $dragged++;
    }
}

check('editing something other than the name does not re-queue the records that name it',
    $dragged === 0, (string) $dragged);

// ------------------------------------------------------------- timeline

echo "\nTimeline\n";

// The words that matter are usually written after the ticket was opened. A
// distinctive token in each bucket, so a match can only have come from there.
//
// Deliberately hung on $printer rather than $vpn: the lifecycle section above
// purges $vpn, and a ticket that does not exist cannot be found — which would
// make the three "must NOT find it" checks below pass without testing anything.
$followup = new ITILFollowup();
$followup->add([
    'itemtype'   => Ticket::class,
    'items_id'   => $printer,
    'content'    => 'Resolved by clearing the stale zzpublicmarker referral.',
    'is_private' => 0,
]);
$followup->add([
    'itemtype'   => Ticket::class,
    'items_id'   => $printer,
    'content'    => 'Internal note: the entity was billed under zzprivatemarker.',
    'is_private' => 1,
]);

$task = new TicketTask();
$task->add([
    'tickets_id' => $printer,
    'content'    => 'Private task note mentioning zztaskmarker.',
    'is_private' => 1,
]);

Queue::mark(Ticket::class, $printer, Queue::UPSERT);
Indexer::run();

check('a public followup is searchable', in_array($printer, $hits('zzpublicmarker'), true),
    implode(',', $hits('zzpublicmarker')));
check('a private followup is searchable by somebody who may read it',
    in_array($printer, $hits('zzprivatemarker'), true), implode(',', $hits('zzprivatemarker')));
check('a private task is too', in_array($printer, $hits('zztaskmarker'), true),
    implode(',', $hits('zztaskmarker')));

// Now the half that matters. A technician without SEEPRIVATE must not be able
// to find a ticket by words that only appear in text they may not read — that
// does not show them the note, but it tells them the phrase is on that ticket,
// which is the kind of leak that reads as the search working well.
$bare = new Profile();
$bare_id = (int) $bare->add(['name' => 'glpisearch-bare', 'interface' => 'central']);
$made['Profile'][] = $bare_id;

foreach (['ticket' => Ticket::READALL, 'followup' => READ, 'task' => READ] as $name => $value) {
    $DB->doQuery(sprintf(
        'INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES (%d, %s, %d)
         ON DUPLICATE KEY UPDATE rights = %d',
        $bare_id,
        $DB->quoteValue($name),
        $value,
        $value
    ));
}

$bare_user = new User();
$bare_uid  = (int) $bare_user->add([
    'name'      => 'glpisearch-bare-user',
    'password'  => 'probe-pw-4',
    'password2' => 'probe-pw-4',
    'is_active' => 1,
]);
$made['User'][] = $bare_uid;

$DB->delete('glpi_profiles_users', ['users_id' => $bare_uid]);
(new Profile_User())->add([
    'users_id'     => $bare_uid,
    'profiles_id'  => $bare_id,
    'entities_id'  => $acme,
    'is_recursive' => 1,
]);

$admin_session = $_SESSION;
check('a technician without SEEPRIVATE can log in',
    (bool) (new Auth())->login('glpisearch-bare-user', 'probe-pw-4', true));
(new Plugin())->init(true);

check('the search is restricted to the attributes they may read',
    Visibility::searchableAttributes(Ticket::class) !== null
    && !in_array(Documents::NOTES_PRIVATE, (array) Visibility::searchableAttributes(Ticket::class), true),
    json_encode(Visibility::searchableAttributes(Ticket::class)));

check('they still find the ticket by its public followup',
    in_array($printer, $hits('zzpublicmarker'), true), implode(',', $hits('zzpublicmarker')));
check('but NOT by the private followup', !in_array($printer, $hits('zzprivatemarker'), true),
    implode(',', $hits('zzprivatemarker')));
check('and NOT by the private task', !in_array($printer, $hits('zztaskmarker'), true),
    implode(',', $hits('zztaskmarker')));

$_SESSION = $admin_session;
(new Plugin())->init(true);

// --------------------------------------------------- ranking and jargon

echo "\nRanking and jargon\n";

$syn = Settings::synonyms();
check('a bidirectional rule is expanded both ways',
    in_array('printer', $syn['copier'] ?? [], true) && in_array('copier', $syn['printer'] ?? [], true),
    json_encode(['copier' => $syn['copier'] ?? [], 'printer' => $syn['printer'] ?? []]));

check('and every word on the line reaches every other',
    in_array('mfp', $syn['copier'] ?? [], true), json_encode($syn['copier'] ?? []));

check('a one-way rule stays one-way',
    (static function (): bool {
        $keep = Settings::get('synonyms');
        Settings::save(['synonyms' => 'aaa => bbb']);
        $rules = Settings::synonyms();
        Settings::save(['synonyms' => $keep]);

        return ($rules['aaa'] ?? []) === ['bbb'] && !isset($rules['bbb']);
    })());

$rules = Schema::settingsFor(Ticket::class)['rankingRules'];
check('the ranking tiebreakers come after relevance, not before',
    array_search(Documents::IS_OPEN . ':desc', $rules, true) > array_search('exactness', $rules, true),
    implode(' > ', $rules));

check('typo tolerance survives the custom ranking rules',
    in_array('typo', $rules, true), implode(' > ', $rules));

// ---------------------------------------------------------------- hybrid

echo "\nHybrid\n";

// The design, asserted rather than described. An embedder belongs to the
// Meilisearch index; this plugin reads it and never writes it.
$pushed = Schema::settingsFor(Ticket::class);
check('a settings push never carries an embedder', !array_key_exists('embedders', $pushed),
    implode(',', array_keys($pushed)));

// Belt and braces on the same point: if a config key for an embedder ever
// reappears here, somebody has started configuring one in two places.
check('there is no embedder configuration in GLPI at all',
    array_filter(array_keys(Settings::DEFAULTS), static fn(string $k): bool
        => str_contains($k, 'embedder')) === []);

check('an index with no embedder searches by word only',
    Schema::embedderFor(Ticket::class) === null,
    var_export(Schema::embedderFor(Ticket::class), true));

// ---------------------------------------------------------------- schema

echo "\nSchema\n";

// The writer and the settings must agree on which columns become attributes.
// A facet declared for a column Documents skips is a filter that matches
// nothing forever, and nothing raises an error about it.
$declared = Schema::facetsFor(Ticket::class);
foreach (Documents::SKIP_RELATIONS as $skipped) {
    check("no facet is declared for the skipped column $skipped",
        !in_array(Documents::attributeFor($skipped), $declared, true));
}

check('facets are declared as filterable, or Meilisearch would refuse them',
    array_diff($declared, Schema::settingsFor(Ticket::class)['filterableAttributes']) === []);

check('index_all offers strictly more types than the curated list',
    (static function (): bool {
        $curated = count(Schema::indexable());
        Settings::save(['index_all' => '1']);
        $all = count(Schema::indexable());
        Settings::save(['index_all' => '0']);

        return $all > $curated;
    })());

// ----------------------------------------------------------------- result

echo "\n" . str_repeat('-', 60) . "\n";

if ($failures === []) {
    echo "all $checks checks passed\n";
    exit(0);
}

printf("%d of %d checks failed:\n", count($failures), $checks);
foreach ($failures as $failure) {
    echo "  - $failure\n";
}

exit(1);
