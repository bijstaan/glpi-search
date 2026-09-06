# GLPI Search

Typo-tolerant search over GLPI's records, backed by [Meilisearch](https://www.meilisearch.com/).

GLPI's own search is exact-match SQL. It is precise, it respects every right the
profile system defines, and it cannot find `Printre` — which is what a
technician actually types at four in the afternoon. This plugin puts an inverted
index in front of it: typos tolerated, matching from the first keystroke, and
answers in single-digit milliseconds.

What it deliberately does **not** do is become the authority on who may see
what. Meilisearch filters on entity because that is cheap and removes almost
everything; GLPI is then asked about every survivor, by the same method the
record's own page calls. A search engine that disagreed with GLPI about
visibility would be a data leak that looks like a feature. See
[docs/visibility.md](docs/visibility.md), which is the document to read before
any other.

Requires GLPI 11.0 and a Meilisearch server. Depends on nothing outside GLPI's
own vendor tree — the client is a few hundred lines of Guzzle, not an SDK.

---

![The header search box answering as you type](docs/screenshots/search-01-header.png)

## What it does

- **Typo tolerance.** `priner`, `prnter` and `printr` all find the printer
  ticket. Tuned per attribute: prose gets tolerance, identifiers do not, because
  a serial number one character out is a different machine.

  ![A misspelled query still finding the record](docs/screenshots/search-02-typo.png)
- **Prefix matching.** `ubunt` matches `ubuntu-desktop-minimal` on the second
  keystroke, without a trailing wildcard anywhere in the code.
- **One index per itemtype**, searched together in a single round trip. The
  field that best identifies a record is not the same for a ticket as for a
  computer, and `searchableAttributes` is an ordered list — one shared index
  would force one ranking order onto all of them, and it would be wrong for
  most.
- **The whole ticket, not just the description.** Followups, tasks and
  solutions are indexed with the ticket — on the instance this was built
  against that is eight times as much text as the titles and descriptions put
  together, and it is where the answer usually is. Private followups and private
  tasks are kept in separate attributes and searched only by sessions holding
  the rights to read them.
- **Related records are searchable too.** A ticket carries the *names* of its
  category, location, requester and assignee, so "sarah chen" finds her ticket
  and "hardware" finds everything filed under it, even when neither word is
  anywhere in the ticket itself. Users carry their email addresses, which live
  in a table of their own and are how people actually look somebody up.
- **Facets**, derived automatically from whatever a type points at: counts per
  status, category, priority, assignee, location. Filter by them with or without
  a query — "every open ticket in this category" is a perfectly good search that
  contains no words at all.
- **Everything, optionally.** The default list is GLPI's globally searchable
  types plus the obvious omissions; one switch widens it to every itemtype with
  a table and a name, plugin types included. Measured on the dev instance: 81
  types, 5,019 documents, 21MB, a full rebuild in 36 seconds. It is not free on
  the read path though — a query across 81 indexes takes 84–177ms against 8–12ms
  across six, so `index_all` buys reachability rather than speed, and the
  keystroke paths feel it.
- **Two surfaces.** GLPI's header search box answers as you type, and
  **glpi-palette** takes its record results from here when both are installed.
- **Hybrid semantic search, with no configuration here at all.** Configure an
  embedder on the Meilisearch index and searches against it become hybrid
  automatically. Meilisearch calls the model, stores the vectors and keeps them
  in step with the documents; this plugin only notices.

## The assistant can search too

Where [glpi-ai](../glpi-ai) is installed, this plugin registers one read-only
tool with it: **`search_everything`** — the same typo-tolerant index the
command palette uses, across every configured itemtype at once.

It exists because of a specific failure. glpi-ai's native searches go through
GLPI's own exact-match SQL, and an exact search that finds nothing looks exactly
like a fact that is not recorded — so a model paraphrasing a customer's words
will conclude "there is no such ticket" and say so. The tool's description tells
it to reach for this as the *second* attempt, every time.

Rights are this plugin's own: `Visibility::filterFor()` narrows the query before
it reaches Meilisearch and `Visibility::keep()` re-checks each hit against GLPI
afterwards, both inside `Finder::search()`. The tool adds no opinion of its own,
because a second opinion on that question is how the two drift.

The degraded case is reported rather than hidden. Meilisearch being unreachable
and there being no matches are both an empty result, and `Finder` already
distinguishes them — so the tool returns an explicit error saying nothing was
looked at, rather than an empty list a model would report as "nothing found".

## How the index stays in step

GLPI's item hooks write one row to a queue table and return. They do not talk to
Meilisearch, do not build a document, and do not care whether the search backend
is up: a technician saving a ticket must not wait on a search engine, and a
search engine that is down must not stop them saving.

A cron task drains the queue in batches. The whole lifecycle is covered —
created, changed, trashed, restored, purged — and a trashed record leaves the
index, because GLPI's own search stops showing it and a search box that finds
things the rest of GLPI says are gone is a search box nobody trusts.

The failure posture is deliberately opposite on each side:

| | On a Meilisearch it cannot reach |
|---|---|
| **Searching** | Returns nothing, reports `degraded`, and the caller falls back to GLPI's own search. Slower results, never no results. |
| **Indexing** | Says so, and leaves the queue alone. A cron task that quietly indexes nothing reports success while the index rots. |

## Install

```bash
# from the GLPI root — the directory has to be named for the plugin
# key, which is not the repository name
git clone https://github.com/bijstaan/glpi-search.git plugins/glpisearch
php bin/console plugin:install -u glpi glpisearch
php bin/console plugin:activate glpisearch
```

Then **Setup → Plugins → Search**: point it at Meilisearch, tick the itemtypes,
and press **Rebuild**. The queue fills immediately and drains on cron; nothing
blocks on the rebuild.

![The Meilisearch connection settings](docs/screenshots/search-05-settings.png)

![Choosing what gets indexed](docs/screenshots/search-06-indexed-types.png)

Meilisearch is one container:

```bash
docker run -d --name meilisearch -p 7700:7700 \
  -e MEILI_MASTER_KEY=a-development-key \
  getmeili/meilisearch:v1.53
# URL      http://meilisearch:7700   (as GLPI reaches it)
# API key  the master key you set above
```

That key is a development convenience and nothing more. Production wants a real
key and a Meilisearch that is not on a published port.

## Configuration worth understanding

- **Candidates per result** (`overfetch`, default 4). How many hits to fetch for
  each row shown, before GLPI's rights check thins them. It only matters for
  users who may see a small fraction of what matched; raise it if restricted
  technicians report short result lists.
- **Semantic ratio** (default 0.3). `0.0` is pure keyword, `1.0` is pure
  meaning. Start low. Keyword results are the ones people can predict, and a
  search that reorders itself for reasons the user cannot see feels broken even
  when it is right. It is here rather than on the server because Meilisearch has
  no index-level default for it, and it is ignored where no embedder exists.
- **Index prefix** (default `glpi`). Change it only if two GLPI instances share
  one Meilisearch — and drop the old indexes afterwards, because nothing will be
  looking at them any more.

## The caller

The question when the phone rings is not "find me a record", it is "who is this
and what have they got". Searching a person — when the query matches exactly
one, because two would be a guess about which one you meant — puts their open
tickets and their assets above the results, with their email, phone and site on
one line.

![A caller's dossier above the results](docs/screenshots/search-08-dossier.png)

None of it comes from Meilisearch. The id is already known by the time anybody
asks, and what remains is a handful of targeted lookups the database answers
better than an index would — entity-scoped in SQL because that is cheap, then
every row put to `canViewItem()` before it is shown.

## Ranking, and the words people actually use

Two tiebreakers are appended *after* Meilisearch's own ranking rules, never
before: an open record beats a closed one, and among equals the more recently
touched wins. Appended is the whole design — text relevance still decides, and
putting recency above it produces a search that cannot find last year's answer,
which is usually the one somebody wants.

Synonyms close a gap typo tolerance cannot. A user says "the VPN is down" and the
ticket says AnyConnect; a user says "the copier" and the asset is a Ricoh MFP.
Neither is a misspelling — the words are simply different. Rules are editable in
the settings page:

```
copier <=> printer, mfp, laserjet     # every word finds every other
vpn => anyconnect                     # one-way, when the reverse would be wrong
```

Bidirectional is usually right, because the technician cannot know which word
this instance happens to have written down.

![The jargon list](docs/screenshots/search-07-jargon.png)

## Related data: why it is copied, not joined

A ticket's category, its location and the person who raised it are not columns
on the ticket — they are foreign keys, and the requester is not even that, it is
a row in a link table. None of it is reachable by a search engine on its own.

Meilisearch does now have joins (`foreignKeys`, v1.39+), and they were measured
against this exact case before being turned down. They do two of the four things
needed:

| | joins | denormalising |
|---|---|---|
| Hydrate a related record into the result | yes | n/a |
| Filter on a related field (`_foreign()`) | yes | yes |
| **Search the related text** | **no** | yes |
| **Facet on a related field** | **no** | yes |

The two they cannot do are most of what anyone wants from search over a
helpdesk: find the ticket by the name of the person who raised it, and count
tickets by category. Both need the value *in* the document — and once it is
there, a join adds nothing except a second mechanism, an experimental feature
flag, one index per dropdown table, and no referential integrity.

So names are copied in. The price is staleness: renaming a category leaves every
ticket in it findable by the old word. That is what the rename hook is for — it
queues the records that name a renamed one, bounded at 5,000 per rename so that
renaming "Hardware" on a large instance does not enqueue a quarter of a million
rows inside the web request that renamed it. Past the cap the remainder keep the
old name until the next rebuild, and that is written to the log rather than
passed over.

## Facets

Every foreign key a type has becomes a facet, derived from the table rather than
listed anywhere, so a type nobody anticipated still gets facets and a GLPI that
adds a column gets one more without this plugin changing. The coded fields —
status, priority, urgency, impact, type — are stored as their labels, which is
what makes them both searchable and countable.

```php
$facets = [];
$groups = Finder::search('printer', ['Ticket'], 5, $degraded,
    ['status' => ['New'], 'itilcategories' => ['Hardware']], $facets);
// $facets['status'] === ['New' => 12, 'Processing (assigned)' => 4]
```

Selecting a value does not take the alternatives away: each filtered attribute
is counted by a second query that omits its own filter, so the other values in
it stay visible and switchable. Every other facet narrows as you would expect.

Values within one attribute are OR'd, different attributes are AND'd, and the
visibility filter is AND'd over the top of both — a facet filter narrows what
somebody may already see and can never widen it. Unknown attributes are dropped
and every value is quoted, because both halves arrive from the browser and the
same expression carries the entity restriction.

**The counts are approximate, and deliberately so.** They come from Meilisearch,
which means they are taken before GLPI's per-record rights check: a restricted
technician can be shown "New (40)" above twelve rows. Counting after the check
would mean loading every matching record on every keystroke, which is the cost
this whole plugin exists to avoid. The entity filter has already been applied, so
the gap is only ever the per-record rights.

## Searching by meaning

There is no switch for this and no embedder settings in GLPI. If an index has an
embedder, searches against it are hybrid:

```bash
curl -X PATCH "$MEILI/indexes/glpi_ticket/settings" \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"embedders":{"default":{"source":"ollama","url":"http://ollama:11434/api/embeddings","model":"embeddinggemma:latest"}}}'
```

The settings page then reports which indexes are hybrid; it does not offer to
configure one. An embedder is a model, credentials and a corpus somebody has
already paid to embed — asking for the same thing again in GLPI would only be a
second place for it to be wrong, and the failure mode is a plugin whose semantic
search silently does not work because it was told once instead of twice.

Meilisearch will **not** apply an embedder on its own: a plain query is
keyword-only however well configured the index is, and `hybrid` has to be sent
per query. That is what this plugin does with what it discovers. The lookup is
cached for five minutes.

**One caution.** Meilisearch processes its tasks in a single queue. An embedder
pointed at a model that hangs or refuses will wedge that queue on the first
document it tries to embed, and indexing stops for *every* index — not just the
one with the embedder. Recovering means cancelling the task and restarting
Meilisearch. Test a new embedder against a small index before turning it loose
on a ticket history.

## The results page

GLPI's own global search page gains a panel above its results: the same
typo-tolerant hits, with a facet sidebar whose chips filter as you click them.
Core's exact-match results stay underneath, untouched — they disagree with ours
in useful ways, and a plugin that removed the page people already know has taken
something away.

![The results panel above GLPI's own results](docs/screenshots/search-03-results-page.png)

Clicking a chip narrows the list and marks itself chosen; clicking it again
clears it.

![A facet chip filtering the results](docs/screenshots/search-04-facet-filtered.png)

The facet counts are labelled as approximate on the page itself, because they
are. See above for why.

## The header search box

The box in the top bar is core's, and stays core's. This plugin attaches to it
in the browser rather than overriding the template, and the form underneath is
untouched:

- **Enter with a result selected** opens that result.
- **Enter with nothing selected** submits the form, landing on GLPI's own search
  page exactly as before.
- **A failed or unavailable search** closes the dropdown silently rather than
  showing an error, because the fallback is one keypress away.

Nothing is taken away from anybody — not by a bug, not by an unreachable
Meilisearch, not by the JavaScript failing to parse.

## Tests

```bash
docker compose -p glpi exec glpi sh -c 'cd /var/www/glpi/plugins/glpisearch && tests/run.sh'
```

`tests/search.php` needs a real Meilisearch, because almost everything worth
asserting here is about what Meilisearch actually does. A mock would only prove
the plugin sent what it meant to send, and the interesting claims — that a typo
still matches, that an entity filter excludes, that replacing a document removes
the old terms — are claims about the engine.

The assertion that matters most is the visibility one: it creates a technician
scoped to one entity, searches as them, and checks that a record from another
entity is **absent** — then checks the same query as a super-admin and finds it,
so that an empty index cannot make the test pass. The private-followup checks
work the same way: a technician without `SEEPRIVATE` must not find a ticket by
words that appear only in a private note, and the same query as somebody who may
read it must still find it.

```bash
cd glpi-search/tests/browser && SHOT_DIR=../../docs/screenshots \
  node glpisearch-check.js
```

Three things are only true in a browser, and each of them is the difference
between the plugin working and appearing to: the header takeover attaches to
core's own input and must leave the form under it submittable, the results panel
has to land above core's results without disturbing them, and the facet chips
have to actually narrow the list. It writes the screenshots in this README on
the way through, so they cannot drift from what the plugin does.

Unticking a type removes its index, not just its place in the search. An index
left behind holds the text of every record it had — for tickets, that includes
private followups — so "stops being searched" and "stops being stored" have to
be the same act.

It caught two real bugs the CLI suite structurally could not. The endpoint
returned **403 on every request after the first**, because the fetch omitted
`X-Requested-With` and GLPI's kernel therefore consumed the CSRF token as though
it were an ordinary form post — a search box that answered one keystroke and
then went silent. And selecting a facet made its own chip **disappear**: filters
are counted after they are applied, so the chosen value collapsed its facet to a
single entry, which the "a facet with one value narrows nothing" rule then hid —
leaving no way to undo the filter.

## Layout

```
setup.php              hooks and the SECURED_CONFIGS declaration
hook.php               install/uninstall: one queue table, one cron, one right
src/Settings.php       configuration, including the secret round-trip
src/Client.php         the Meilisearch HTTP client
src/Schema.php         what gets indexed, under what name, ranked how
src/Documents.php      one GLPI record, as a Meilisearch document
src/Queue.php          the list of records whose documents are stale
src/Indexer.php        the cron task that drains it
src/Visibility.php     the entity filter, and GLPI's final word
src/Finder.php         the search API every surface calls
front/config.php       the settings page
ajax/search.php        the header box's backend
public/js/search.js    the header takeover
docs/visibility.md     who may see what, and why it is split in two

glpi-search/tests/browser/glpisearch-check.js
                       the browser half: the header takeover, the results
                       page, the facet chips — and the screenshots above
```

## Licence

GNU General Public License, version 3 or later — the same licence as GLPI.
This plugin is loaded into GLPI's process and extends its classes, so it is a
derivative work of GLPI and carries GLPI's licence. See [LICENSE](LICENSE).
