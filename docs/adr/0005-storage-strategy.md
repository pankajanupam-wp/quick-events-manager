# ADR-0005 — WordPress-native by default, custom tables by exception

**Status:** accepted · 2026-08-10

## Context

Event plugins fail in two opposite ways. Some model everything as posts and post
meta, then discover that counting registrations for a 500-person event is a
`meta_query` join against a table holding thousands of unrelated rows that every
other query on the site walks past. Others build a parallel database and lose the
editor, media library, taxonomies, permalinks, search, revisions and REST — then
spend years rebuilding each one badly.

We need a rule that decides each entity on its merits rather than by taste.

## Decision

```
Authored by a site owner, with prose, images and a URL?   → CPT + post meta
A classification that wants archives?                     → Taxonomy
A person who logs in?                                     → WP user + user meta
Small singular configuration?                             → Option
Generated in volume, queried relationally, transactional? → Custom table
Otherwise                                                 → post meta
```

A custom table additionally requires a written answer to five questions before it is
created: which query it serves that post meta cannot, expected volume at each scale
tier, which indexes serve which named queries, the migration path, and uninstall
behaviour. That list lives in
[database.md](../database.md#storage-decision-framework).

Applied, this yields custom post types for events, venues and organisers — events and
venues built, organisers still flat meta pending their own chunk; taxonomies
for categories and tags; and ten custom tables for occurrences, registrations,
attendees, attendee meta, ticket types, orders, order items, transactions, check-ins
and the email queue.

Each table is created by the module that owns it, on first enable. An install that
only lists meetups grows exactly one.

## Alternatives considered

**Everything as post types**, including registrations. Rejected on the arithmetic: a
500-person event becomes 500 posts and several thousand `postmeta` rows, and
"how many places are taken" becomes a join instead of one index lookup on
`(event_id, status)`.

**Everything in custom tables**, including events. Rejected because it discards the
block editor, media, revisions, taxonomies, permalinks, search integration and the
REST API that `show_in_rest => true` provides free — all of which an event page wants
and none of which is worth rebuilding.

**Custom tables only when a performance problem appears.** Attractive, and correct for
most software. Rejected here because schema is a published contract: by the time the
problem is measurable, the migration touches every installation. The two tables we
are adding pre-emptively — occurrences and attendees — are both cases where the
future need is certain rather than speculative, and both are documented in their own
ADRs.

## Consequences

**Good.** Events keep everything WordPress gives an authored object. High-volume data
sits in tables shaped for the queries that actually run. The rule is mechanical
enough that a contributor can apply it without asking.

**Bad.** Two storage idioms in one codebase, so contributors must know which applies
where. Mitigated by confining `$wpdb` to `Repository/` — one directory answers "where
does the SQL live".

**Bad.** Custom tables are invisible to WordPress's own export, backup and multisite
tooling. We therefore own privacy export and erasure for them explicitly, which is
already implemented for registrations and extends to attendees and attendee meta.
