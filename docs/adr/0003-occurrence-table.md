# ADR-0003 — Dates live in an indexed occurrence table

**Status:** accepted · 2026-08-10

## Context

The rewrite stores event dates in post meta (`_qem_start_utc`, `_qem_end_utc`) and
queries them with `orderby => meta_value` plus a `meta_query`.

Three facts, verified against the WordPress 7.0.3 source on disk rather than from
memory:

**1. `wp_postmeta` has no usable index on `meta_value`.** From
`wp-admin/includes/schema.php`:

```sql
CREATE TABLE $wpdb->postmeta (
    meta_id    bigint(20) unsigned NOT NULL auto_increment,
    post_id    bigint(20) unsigned NOT NULL default '0',
    meta_key   varchar(255) default NULL,
    meta_value longtext,
    PRIMARY KEY (meta_id),
    KEY post_id (post_id),
    KEY meta_key (meta_key($max_index_length))
)
```

`meta_value` is `longtext`. There is no index and there cannot usefully be one.

**2. `'type' => 'DATETIME'` emits a `CAST`.** From `class-wp-meta-query.php:780`:

```php
$sql_chunks['where'][] = "CAST($alias.meta_value AS {$meta_type}) {$meta_compare} {$where}";
```

A function wrapped around a column defeats any index that might have existed.

**3. The `OR` meta_query multiplies joins.** `Query::upcoming_args()` produces four
`postmeta` aliases — one for the `orderby` key, one per `OR` branch.

So the most-run query in the plugin is four self-joins against the site's largest
table, a `CAST` in the `WHERE`, and a filesort over an unindexed `LONGTEXT`.

`docs/data-model.md` claimed the `Y-m-d H:i:s` format "is what lets the archive
`orderby => meta_value` without a `CAST`". True of the ordering, false of the
filtering. The comment was half right and read as fully right, which is how it
survived review.

This is not a theoretical concern. The Events Calendar — 800,000+ installs — hit
exactly this wall and escaped it in v6.0 by introducing `tec_occurrences` custom
tables that every date query targets instead.

## Decision

Dates move to `{prefix}qevm_occurrences`, with real `datetime` columns and real
B-tree indexes. Schema in [database.md](../database.md#qevm_occurrences--stage-1).

- Post meta remains the **authoring surface** and the human-readable record.
- The occurrence row is **derived**, regenerated on `save_post`.
- `wp qevm occurrence rebuild` regenerates everything from post meta.
- Every date query in the plugin goes through one `Events\OccurrenceQuery`.
- A one-off event has exactly one row — no conceptual overhead for the simple case.
- `end_utc` is `NOT NULL`; an event with no stated end stores `end_utc = start_utc`,
  which collapses the three-branch `OR` into one indexed range scan.
- `series_uuid` and `is_exception` are created now and used when recurrence lands.

## Alternatives considered

**Keep post meta, add a `meta_value` index.** Not possible on `longtext` without a
prefix index, which does not help range queries, and altering a core table is not
something a plugin may do.

**Keep post meta, drop the `CAST` by comparing strings.** Removes one problem, leaves
the unindexed filesort and four joins. Helps at 100 events, not at 10,000.

**Defer until recurring events force it.** The original roadmap position. Rejected:
by then every install needs a data migration, the calendar has been built twice, and
a compatibility layer must serve both storage paths. Today the plugin is unpublished
and the migration is free.

## Consequences

**Good.** `EXPLAIN` shows an index range scan. Query count stops varying with event
count. Recurrence becomes an insert loop rather than a schema change. The calendar
can be built once.

**Bad.** A derived table can drift from its source. Mitigated by the rebuild command,
which is written in the same chunk as the table — not retrofitted during an incident.
Writes get slower: saving an event now writes post meta *and* occurrence rows. Events
are written rarely and read constantly, so this is the right side of the trade.

**Cost.** One stage of work, no user-visible payoff. That is the definition of
foundation work, and it is the only kind that gets more expensive every day it waits.
