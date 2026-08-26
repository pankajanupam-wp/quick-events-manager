# Data model

**Superseded by [database.md](database.md).**

This file described the pre-architecture data model, in which event dates lived in
post meta and a registration row doubled as an attendee. Both are being replaced —
see [ADR-0003](adr/0003-occurrence-table.md) and
[ADR-0004](adr/0004-registration-attendee-split.md).

It also contained a claim that was wrong and worth recording, because it survived
review by reading as correct:

> "`Y-m-d H:i:s` is zero-padded and big-endian, so lexical order is chronological
> order. That is what lets the archive `orderby => meta_value` without a `CAST`."

The first sentence is true. The second is true of the **ordering** and false of the
**filtering** — `WP_Meta_Query` emits `CAST( meta_value AS DATETIME )` whenever a
clause sets `'type' => 'DATETIME'`, regardless of the stored format
(`class-wp-meta-query.php:780`). And avoiding a `CAST` was never the point:
`wp_postmeta` has no index on `meta_value` at all, so the sort is a filesort over an
unindexed `LONGTEXT` either way.

Half-right documentation is more dangerous than none, because it stops the next person
looking. Kept here as a marker rather than deleted.

For the current schema, storage decisions, indexes, query patterns, migrations and
uninstall behaviour, read [database.md](database.md).
