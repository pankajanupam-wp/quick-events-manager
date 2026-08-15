# Architecture

How Quick Events Manager is put together and why. Rules that govern changes live in
[engineering-standards.md](engineering-standards.md); decisions and their reasoning
live in [adr/](adr/).

## The organising idea

A fresh install does one thing: events. Everything else is a module the site owner
switches on.

This is not a UI convention. A module that is off never has `register()` called, so it
adds no hooks, registers no post types, creates no tables and enqueues no assets. The
toggle is a real performance boundary, asserted by test.
([ADR-0009](adr/0009-module-architecture.md))

Two things follow, and both are why the design earns its complexity:

1. **A simple site stays simple.** Somebody who wanted a list of meetups is never
   shown a ticketing screen, and never pays for one in queries or page weight.
2. **The plugin can keep growing.** Every feature arrives off by default and cannot
   destabilise an install that never enables it.


## Date queries

Every date filter and every date sort goes through `Events\OccurrenceQuery`. Nothing
else builds a `meta_query` for a date, and nothing else writes date SQL.

A caller adds one query variable:

```php
new WP_Query( OccurrenceQuery::upcoming_args( array( 'posts_per_page' => 10 ) ) );
```

A single `posts_clauses` filter turns that into a join against `qevm_occurrences`, a
range condition on `end_utc` and an ordering on `start_utc`. Because it modifies the
query rather than replacing it, pagination, `found_posts`, search, taxonomy filters
and post-status handling all keep working — WP_Query is still doing its job.

The generated SQL for an upcoming list:

```sql
SELECT SQL_CALC_FOUND_ROWS wp_posts.*
FROM wp_posts
INNER JOIN wp_qevm_occurrences qevm_occ
        ON qevm_occ.event_id = wp_posts.ID
       AND qevm_occ.status IN ( 'scheduled', 'moved' )
WHERE wp_posts.post_type = 'qevm_event'
  AND wp_posts.post_status = 'publish'
  AND qevm_occ.end_utc >= '2030-06-15 12:00:00'
GROUP BY wp_posts.ID
ORDER BY MIN( qevm_occ.start_utc ) ASC, wp_posts.ID ASC
LIMIT 0, 10
```

No `CAST`, no `postmeta`, one indexed range condition. Compare
[adr/0003-occurrence-table.md](adr/0003-occurrence-table.md), which describes what this
replaced: four `postmeta` self-joins, a `CAST` in the `WHERE` and a filesort over an
unindexed `longtext`.

Three details that are load-bearing:

- **The status test is in the `ON` clause, not the `WHERE`.** On a `LEFT` join a
  `WHERE` condition against the joined table discards rows with no match, silently
  turning it back into an `INNER` join and losing exactly the undated events the
  `LEFT` join existed to keep.
- **`GROUP BY` with an aggregated `ORDER BY`.** An event may hold several dates once
  recurrence lands; an upcoming list sorts on the soonest still to come (`MIN`), a past
  list on the most recent that has been (`MAX`). A bare column would let MySQL pick any
  row in the group and the order would be stable only by accident. Passing
  `'group' => false` gives one row per date instead, which is what a calendar wants.
- **Writes to the occurrence table invalidate the post query cache.** WP_Query caches
  the ids a set of arguments resolved to, keyed on the `posts` group's `last_changed`.
  Editing a post bumps that; writing to a table core has never heard of does not. Every
  write in `OccurrenceRepository` calls `wp_cache_set_last_changed( 'posts' )`, without
  which a cancelled date keeps appearing until something unrelated touches a post.

## Layers

Four. Dependencies point **downward only**.

```
   WordPress integration     Admin/  Frontend/  Blocks/  Rest/  Cli/  Privacy/
            │                hooks, screens, HTTP, markup, escaping
            ▼
   Application               Service/  Modules/
            │                use cases; coordinates repositories and domain
            ▼
   Domain                    Domain/
            │                entities, value objects, enums, business rules
            ▼
   Infrastructure            Repository/  Install/
                             $wpdb, schema, migrations
```

| Layer | Rule |
| --- | --- |
| `Domain/` | Calls **nothing but i18n**. No `$wpdb`, no `get_option()`, no `apply_filters()`, no I/O. Unit-testable with no WordPress present. `__()` is the single exception, because an enum case's label belongs with the case — see [engineering-standards.md §5](engineering-standards.md#5-architecture-rules) |
| `Repository/` | The only place `$wpdb` appears. One file per aggregate — this is what keeps the SQL audit finite |
| `Service/` | Never emits HTML, never reads `$_POST`. Typed input, typed output or `WP_Error` |
| Integration | Never contains business rules. Translates a request into a service call and a result into output |

Presentation depends on the domain; the domain never depends on presentation.

### What we deliberately do not build

No DI container. No event bus — WordPress has one, called `do_action()`. No ORM, no
query builder, no active record. No templating engine. No custom exception hierarchy
beyond what is caught before it reaches a boundary.

> This is a WordPress plugin, not a framework wearing one. Every abstraction must
> remove more complexity than it adds.

## Request lifecycle

```
quick-events-manager.php
  ├─ defines constants                  QEVM_VERSION, QEVM_PATH, …
  ├─ checks PHP and WordPress versions
  ├─ registers the autoloader           QuickEventsManager\ -> includes/
  ├─ registers activation hooks
  └─ Plugin::instance()->boot()
        ├─ plugins_loaded  ─► Registry->boot()
        │                       ├─ reads qevm_enabled_modules
        │                       └─ register() on enabled modules only
        ├─ init            ─► textdomain, post types, taxonomies
        └─ admin_init      ─► Plugin::maybe_upgrade()
                              ├─ Installer::upgrade_schema()
                              └─ Migrations\Runner::run()
```

Modules boot on `plugins_loaded` rather than immediately, so another plugin can add
its own through the `qevm_modules` filter before the registry is first built.

Upgrades run on `admin_init`, not activation, because a plugin updated in place
through the dashboard or WP-CLI never fires its activation hook. A single stored
integer is compared first, so the no-op path costs one option read. Structure is
brought up to date before data, because a migration may depend on a column dbDelta
has just added. See [migrations.md](migrations.md).

## Naming

The prefix is **`qevm`**; the PHP namespace root is **`QuickEventsManager\`**.

It is not `qem`. An actively maintained plugin with 1,000+ installs and a near
identical name already occupies that prefix with 142 global functions, 19 options and
13 hooks. Nothing collides fatally — our namespacing is what prevents that — but the
option and hook namespaces are shared, and collisions there fail silently.
([ADR-0001](adr/0001-naming-and-namespace.md))

Full matrix in
[engineering-standards.md §1](engineering-standards.md#the-naming-matrix). The one
exception is the text domain, which stays `quick-events-manager` unabbreviated
because WordPress.org generates translations from the slug.

## Storage

WordPress-native by default; custom tables by exception, each justified in writing.
([ADR-0005](adr/0005-storage-strategy.md))

Events, venues and organisers are custom post types — they are authored, and they want
the editor, media, taxonomies, permalinks, revisions and search that come free. Events
and venues are built; organisers are still flat meta on the event, and become a record
in the same way venues did.

Venues carry one wrinkle worth stating here, because it looks like a mistake until the
reason is known: an event keeps its own copy of the address even when it points at a
venue record. A venue is an opt-in module, and a record that is the *only* copy of an
address disappears with the module — taking the location off the event page, the `.ics`,
the structured data and the confirmation email at once.
[ADR-0014](adr/0014-venue-records-with-flat-fallback.md) has the full argument.
Occurrences, registrations, attendees, tickets, orders, transactions, check-ins and
queued email are custom tables — they are generated in volume and queried relationally.

Full schema, indexes and query patterns in [database.md](database.md).

### Dates

Every date query in the plugin goes through one occurrence table with real `datetime`
columns and real indexes. Post meta remains the authoring surface; occurrence rows are
derived on `save_post` and rebuildable from scratch via WP-CLI.

The reason is specific and was verified against core rather than assumed:
`wp_postmeta` indexes `post_id` and `meta_key` only — `meta_value` is an unindexed
`LONGTEXT` — and a `meta_query` with `'type' => 'DATETIME'` wraps it in a `CAST` that
defeats any index. The previous design was four `postmeta` joins, a `CAST`, and a
filesort. ([ADR-0003](adr/0003-occurrence-table.md))

Three facts are stored per datetime: the UTC instant, the local wall-clock time as
typed, and the timezone identifier. Queries compare UTC; display converts; the
server's timezone is never consulted. ([ADR-0008](adr/0008-datetime-storage.md))

### People

A registration is a booking. An attendee is a person. A booking for three places
creates one registration and three attendee rows, each with its own name, ticket code
and check-in record. ([ADR-0004](adr/0004-registration-attendee-split.md))

### Money

Integer minor units plus a currency code, never a float. Order items store an
immutable snapshot of name and price, so a historical report never recomputes from
current configuration. ([ADR-0006](adr/0006-money-and-immutability.md))

## Concurrency

Two contended paths, both **insert-then-resolve** rather than check-then-write.
Checking then writing has a race that a transaction does not close under MySQL's
default `REPEATABLE READ`, because both sessions read the same snapshot.

- **Capacity** — insert as pending, then count rows with an id at or below the new
  one. Every row learns its place deterministically, so an oversell becomes a waitlist
  entry rather than an error. Verified with eight parallel processes against a
  capacity-1 event.
- **Check-in and webhooks** — attempt the insert; treat a duplicate-key error as the
  already-handled case.

## One renderer per feature

The `qevm/event-list` block and the `[qevm_event_list]` shortcode both call
`Renderer::event_list()`. Two entry points, one implementation.

This matters more than it looks. Markup, escaping and capability decisions are made in
exactly one place per feature, so a security fix cannot land in the shortcode and miss
the block. The module gate lives there too — three separate things render the
registration form, and the check has to be somewhere all three pass through. Gating
per caller has already produced a bug here once.

## Rendering into themes

Event details are added with a `the_content` filter rather than a
`single-qevm_event.php` template.

A template takeover only works in classic themes: a block theme renders singles
through its own block template and never looks at the plugin's file. Filtering the
content works in both, because a block theme's template still renders the
`core/post-content` block, which applies `the_content`.

The filter carries four guards. Three are obvious — `is_singular()`, `in_the_loop()`,
`is_main_query()` — because `the_content` also runs for excerpts, feeds, REST
responses and any post rendered in a sidebar.

The fourth is a re-entrancy flag, and it is not theoretical. Rendering the details
calls `get_the_excerpt()`; for a post with no manual excerpt, core generates one with
`wp_trim_excerpt()`, which applies `the_content` — landing straight back in the filter
with all three other guards still true. That recursion exhausted PHP's memory limit
and served a blank page on every event page.

## Blocks render in PHP

All blocks are dynamic, with a PHP `render_callback`. The editor script is the only
JavaScript the plugin ships and it never loads for visitors.

`block.json` is read from `build/`, which is gitignored and produced by CI and by the
deploy workflow. It is deliberately **not** in `.distignore` — compiled blocks are the
one build artefact that has to ship, and a test fails if somebody excludes it.

## Dependencies

**Zero at runtime.** The shipped package contains no `vendor/`. Autoloading is a
hand-written PSR-4 autoloader of about fifteen lines.

Two plugins bundling different versions of the same Composer package into one PHP
process is an unfixable class of WordPress conflict. Shipping nothing avoids it.
Composer is used for development tools only. ([ADR-0007](adr/0007-no-runtime-dependencies.md))

## Testing

Three suites, answering different questions.

The **unit suite** stubs WordPress and runs in about a second. It covers logic that is
genuinely independent of WordPress: timezone conversion, sanitisation, iCalendar
escaping and folding, CSV formula defusing, money arithmetic, release metadata.

It deliberately does not attempt `$wpdb`. SQL cannot be meaningfully faked — a stub
that returns what you told it proves only that you can write a stub.

The **integration suite** runs against real WordPress and MySQL, and is where
repositories, migrations, REST permissions and concurrency are tested.

**e2e** covers the journeys: create → publish → register → manage → check in.

This is not a preference. Every bug in the regression table was found by running
against WordPress, not by the suite:

- the `the_content` recursion above
- `sanitize_title` returning the request object when used as a bare `sanitize_callback`
  — WordPress calls it as `( $value, $request, $param )` and its second parameter is
  `$fallback_title`
- the registration form rendering with its module switched off
- a rate limit that would lock out an entire venue behind one NAT gateway

None were visible to a stub, and three of the four were invisible to code review.

## Security posture

- Every `$wpdb` call is prepared. `ORDER BY` columns are allowlisted rather than
  interpolated, because `prepare()` would quote an identifier into a string literal
  and MySQL would sort every row by the same constant.
- Every output is escaped at the point of output.
- Every admin action checks a capability **and** a nonce.
- Every REST route declares an explicit `permission_callback`; a test counts them
  against the number of routes.
- Public forms are nonce-protected, rate-limited, honeypotted, and use
  POST-redirect-GET so a refresh cannot resubmit.
- Signed public links use `hash_hmac()` with `wp_salt()`, carry an expiry, and are
  compared with `hash_equals()`.
- Webhooks verify the provider's signature and are idempotent on the provider's event
  id.
- CSV cells beginning `=`, `+`, `-` or `@` are prefixed with a tab, so an attendee's
  name cannot become a formula that runs on the organiser's machine.
- No IP addresses are stored; rate limiting keys on a salted hash.
- Custom capabilities rather than mapping onto `post`, so a check-in role can exist
  without granting the right to edit blog posts.

A 150,000-install competitor shipped an unauthenticated SQL injection in 2026 and left
the directory. Confining `$wpdb` to one directory is the structural reason that class
of bug is hard to write here.

## Scope

Quick Events Manager is an event management plugin. Sessions, tracks, speakers,
sponsors, exhibitors, booths and per-session registration are **not** in core, now or
later. The obligation that creates is to make the alternative real: a documented
module interface, a documented hook and REST surface, and a worked example of building
an add-on. ([ADR-0011](adr/0011-scope-boundary.md))
