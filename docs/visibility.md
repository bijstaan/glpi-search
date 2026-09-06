# Who may see what

This is the document to read before any other in this plugin, because it
describes the one thing that has to be right. Everything else here is a
performance argument. This is a correctness argument.

## The problem

A search engine sitting beside GLPI knows nothing about GLPI's rights. It does
not know that a technician may only see tickets they are assigned to, that a
knowledge article can be restricted to one group, that an entity can be
recursive, or that a profile can be changed this afternoon. It knows what was in
the document when the document was written.

If the index were allowed to decide who sees a result, every one of those would
become a record shown to somebody not entitled to it. And it would not look like
a bug. It would look like the search working: a plausible row, in the right
group, that opens.

## The split

The work is divided by what each side is actually good at.

**Meilisearch filters on entity.** Every document carries `entities_id` and
`is_recursive` as filterable attributes, and every query carries a filter built
from the session:

```
entities_id IN [<glpiactiveentities>]
  OR (is_recursive = 1 AND entities_id IN [<glpiparententities>])
```

That mirrors GLPI's own rule — an item is visible from its own entity, and a
recursive item is also visible from everything below it, which is why the
ancestors appear in the second clause. It is a numeric comparison against an
index, it costs nothing, and on a real multi-tenant instance it removes almost
everything.

It is a **performance filter**. It is not a permission check.

**GLPI decides.** Every surviving candidate is loaded and put to
`canViewItem()` — the same method the record's own page calls. Anything it says
no to is dropped, whatever the index thought. That is the last word, and there
is no path around it: `Finder` cannot return a row that `Visibility::keep()` did
not pass.

## What that costs, and why it is affordable

One primary-key read per row shown. Candidates are walked in score order and the
walk stops as soon as enough have survived, so the ordinary case — a technician
who can see what they searched for — costs exactly as many reads as there are
rows on screen. Five rows, five reads, on a primary key.

The `overfetch` setting (default 4) is the headroom for the case where they
cannot: it asks Meilisearch for four candidates per row, so a user who may see
one record in four still fills the list. A user far more restricted than that
sees a short list, which is the honest failure — and raising `overfetch` is the
knob for it.

## Types the entity filter must not judge

Two of the indexed types have an `entities_id` column that means something other
than visibility:

- **User.** The column is their *default* entity. What they may be seen from
  comes out of `glpi_profiles_users`.
- **KnowbaseItem.** Its own visibility rows override the column entirely — an
  article can be public, or restricted to a profile, a group, or a user.

Filtering these on `entities_id` would be too **narrow**: it would hide records
the viewer is entitled to see. A filter that loses results is as wrong as one
that leaks them, just quieter — nobody files a ticket about a search result they
never knew existed.

So these are indexed with `entities_id = -1`, the filter matches only that, and
every hit goes to GLPI to be settled. They cost more reads per result than the
entity-scoped types. That is the correct trade.

**The same rule governs what is displayed.** A result's subtitle shows the entity
only where the entity is what governs the record. This is not tidiness: the
first version printed `entities_id` under everything, which meant a user whose
profile was on the root entity but whose default entity was a customer's was
displayed as belonging to that customer — a claim nobody had made, on the
strength of a column that means "where records this person creates will go".

If a column is not good enough to decide who may see a record, it is not good
enough to print underneath it either. Unscoped types show something that
actually identifies them instead — for a user, their real name, their location
and their email address.

Email is worth a note of its own: it is not a column on `glpi_users` but a table
of its own, one row per address, so nothing that walks columns will ever find
it. It is assembled explicitly, and it is high in the searchable list, because
an address is how people actually look somebody up — it is what they are reading
in their mail client when they come to find the person.

## What is in a document

Only what is needed to search and to draw a row:

- the record's id, itemtype and entity fields
- the text columns being searched
- a resolved `title` and `subtitle` for display

No custom fields, no relations, no history, no attachments. Where an embedder is
configured, the vectors are Meilisearch's own and are derived from these same
fields — there is no separate copy of anything here either. The temptation is to
put the whole record in so a result never needs a second read — and that would
make the index a second copy of GLPI: one to keep in step, one to reason about
when somebody asks what is stored about them, and one to get wrong. What this
plugin keeps in Meilisearch is a pointer with enough text attached to find it.

## Uninstalling

`plugin:uninstall` drops the indexes before it deletes the settings, in that
order and deliberately: deleting the credentials first would strand every
document in Meilisearch with nothing left in GLPI that knows where they are. If
Meilisearch is unreachable at the time, the uninstall proceeds anyway and logs
it — an unreachable search server must not block an uninstall — and the indexes
are named `<prefix>_<itemtype>` for whoever has to remove them by hand.
