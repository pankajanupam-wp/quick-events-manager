# Quick Events Manager

A free, open-source event manager for WordPress. Events, registration, attendees — and nothing you did not ask for.

[![WordPress](https://img.shields.io/badge/WordPress-6.5%20%E2%80%93%207.0-blue)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)](LICENSE)

There is no paid tier, no locked feature and no upsell, and there is not going to be one.

## The idea

Most event plugins greet a new user with ticketing, payment gateways and recurrence rules before they have created a single event. This one starts with events and stops there.

Everything else is a **module** the site owner switches on under **Events → Features**. A module that is off registers no hooks, creates no tables and enqueues no assets — the toggle is a real boundary, not a display filter over code that runs anyway. `RegressionTest` and the integration suite both check that.

```
Core      always on   Events, listing, single event, search, categories/tags
Level 1   opt-in      Registration & attendees · Calendar view
Level 2   opt-in      Custom fields · Email templates · Reusable venues · Reusable organisers
Level 3   opt-in      Ticketing · Check-in · Payments · WooCommerce · Recurring
```

A plugin this size is only pleasant to use because most of it is switched off. Somebody putting a list of meetups on a page should never be handed a payment gateway screen.

> **Status: in development.** 26.0 ships as a single release and is not published until everything in [docs/roadmap.md](docs/roadmap.md) is built. Core, registration, attendees, reusable venues and organisers, and custom registration questions are done; the rest of Level 2 and all of Level 3 are not. See the roadmap for the build order.

## Architecture

```
quick-events-manager.php     Header, constants, autoloader, bootstrap
uninstall.php                Deletes options, caps and the table — never the events
includes/
  Autoloader.php             ~40 lines, QuickEventsManager\ -> includes/
  Plugin.php                 Wires the registry; activation and upgrade
  Modules/                   Module interface + Registry (the feature gate)
  Install/                   Installer (schema, caps), Migrator (1.0 -> 26.0)
  Events/                    PostType, Meta, Event, Query, MetaBox, AdminColumns
  Registration/              Module, Service, Repository, FormHandler, emails, CSV
  Frontend/                  Templates, Renderer, Shortcodes, SingleEvent, Schema, Ics
  Blocks/                    Registers the three dynamic blocks
  Rest/                      Read-only qevm/v1 controller
  Admin/                     Settings, FeaturesScreen
  Privacy/                   GDPR exporter and eraser
templates/                   Overridable by themes
src/                         Block editor sources (JSX)
```

Namespaced classes with a hand-written autoloader — Composer's is a dev dependency only, because `vendor/` must not ship to WordPress.org. No DI container and no service locator: a `Plugin` singleton wires the registry, and each module owns its own hooks.

### Storage

| Entity | Where | Why |
| --- | --- | --- |
| Event | CPT `qevm_event` + post meta | Needs the editor, blocks, media, taxonomies, permalinks, revisions |
| When it happens | Custom table `{prefix}qevm_occurrences`, derived from the meta | Post meta cannot answer a date range: `meta_value` is an unindexed `longtext` and `'type' => 'DATETIME'` wraps it in a `CAST` |
| Registration | Custom table `{prefix}qevm_registrations` | See below |
| Attendee | Custom table `{prefix}qevm_attendees` | One booking may cover several people, and each of them needs a ticket and a check-in of their own |
| Settings | Three options | Small, and read on every request |

Registrations are the one thing that must not be posts. The question asked most often is "how many confirmed registrations does this event have", which in a custom table is one indexed `COUNT`. As a post type it is a `meta_query` join, and a 500-person event would add thousands of rows to `wp_postmeta` that every unrelated `WP_Query` then walks past.

The table is created when the Registration module is first switched on, not at activation. A site that never takes registrations never grows it.

### Dates and times

The bug that ruins event plugins is storing local time and nothing else: every event silently moves the day somebody changes the site timezone, and nobody notices until attendees turn up an hour late.

Each event stores three things — the wall-clock time the organiser typed (`_qevm_start_local`), the timezone they meant it in (`_qevm_timezone`), and the equivalent UTC instant (`_qevm_start_utc`). **UTC is the only value ever sorted or queried on**; display always uses the event's own zone.

`Y-m-d H:i:s` is zero-padded and big-endian, so lexical order is chronological order. `MetaTest` asserts that property rather than assuming it.

Dates are also written to `qevm_occurrences` as real `datetime` columns, and **every date query in the plugin reads them from there**, never from post meta — one `posts_clauses` filter in `Events\OccurrenceQuery` turns "what is on next week" into an indexed range scan. Post meta stays the authoring surface and the human-readable record; the occurrence row is derived, regenerated on `save_post`, and `wp qevm occurrence rebuild` rebuilds the lot.

At 10,000 events the archive costs **1.7ms of database time across 8 queries**, flat with pagination depth. The `meta_query` this replaced measures 77ms on the same data. Both numbers, and the missing index the benchmark found, are in [docs/development-plan.md](docs/development-plan.md#stage-1--schema-foundation).

### Capacity

Capacity uses **insert-then-rank**, not check-then-insert:

1. Insert the registration as `pending`.
2. Count the places taken by rows with an id at or below this one.
3. Position within capacity → `confirmed`. Beyond it → `waitlisted`.

Check-then-insert has a race between the count and the insert, and a transaction does not close it — under the `REPEATABLE READ` isolation MySQL defaults to, two concurrent transactions read the same snapshot and both decide there is room. Inserting first removes the window entirely: an auto-increment id fixes a row's position in the queue for good, so two simultaneous inserts can never both be position N.

Verified with eight parallel processes against a capacity-1 event: one confirmed, seven waitlisted, one place taken.

## Front end

- Templates resolve from `your-theme/quick-events-manager/` before the plugin's `templates/`. That is the whole theming system — no template hierarchy of our own, no layout settings.
- Event details are injected through `the_content`, not a `single-qevm_event.php` takeover. A template takeover only works in classic themes; a block theme renders singles through its own block template and never looks at the plugin's file. Filtering the content works in both.
- The three blocks are **dynamic** — they render in PHP, so the front end downloads no block JavaScript. The shortcodes call the same render functions, so there is one implementation and one security review per feature.
- The stylesheet loads only on pages that actually show an event.

## Tests

```sh
composer install
composer test
```

The unit suite stubs the slice of WordPress the pure-logic classes touch, so it runs in about a second with no database, no WordPress and no Docker. It covers timezone conversion, sanitisation, iCalendar escaping and folding, CSV formula defusing, and release metadata.

What it deliberately does **not** cover is anything involving `$wpdb`. SQL cannot be meaningfully faked — a stub that returns what you tell it proves only that you can write a stub. The custom table, the capacity ranking, the REST routes and the migration are checked against a real MySQL instead.

`tests/unit/RegressionTest.php` is worth reading on its own. Every test in it corresponds to something that was genuinely broken and that the stubbed suite could not have caught:

| Bug | How it presented |
| --- | --- |
| `the_content` re-entrancy | `get_the_excerpt()` re-applies `the_content` for posts with no manual excerpt, so the filter re-entered itself and exhausted PHP's memory on every event page |
| `sanitize_title` as a bare `sanitize_callback` | WordPress calls it as `( $value, $request, $param )`, and its second parameter is `$fallback_title` — so an empty category came back as the `WP_REST_Request` object and fatalled the list endpoint |
| Ungated registration form | The form rendered on every event even with the module switched off, which is the opposite of what the Features screen promises |
| Rate limit too tight | 5 per 5 minutes per address locks out an entire office, university or conference venue behind one NAT gateway — exactly the places that run events |

No `composer.lock` is committed, so each PHP version in CI resolves the PHPUnit release that supports it.

### The integration suite

```sh
npx @wordpress/env start   # from the plugins directory, once
composer test:integration
```

Real WordPress, real MySQL, inside the wp-env tests container — the half the unit suite says outright it cannot reach. Each test runs in a database transaction that is rolled back afterwards, so nothing it writes survives it and no test has to clean up after itself.

Three things are worth knowing before adding to it:

- **DDL commits.** MySQL ends the transaction on `CREATE`, `ALTER`, `DROP` and `TRUNCATE`, which takes the rollback with it. A test that runs `dbDelta` or a migration calls `restore_schema()` itself.
- **The plugin must be active**, not merely present, so that it loads early enough for `init`. `composer test:integration` activates it first; anything that resets the test database — including the WordPress test library's own installer — empties the active plugin list.
- **`WP_UnitTestCase` is not used.** WordPress 7.0's test library calls `PHPUnit\Util\Test::parseTestMethodAnnotations()` before every test, an API PHPUnit removed in 10, so using it would mean a second PHPUnit toolchain to install, pin and keep alive alongside the 10–12 the unit matrix runs. What catches defects is real WordPress and real MySQL, not that base class; `tests/integration/TestCase.php` brings the isolation it was wanted for in about twenty lines.

`tests/integration/HarnessTest.php` exists to keep the harness honest — that this really is WordPress, that the schema is installed, and that the rollback is working. A suite whose isolation quietly breaks does not fail; it starts passing for the wrong reasons.

`tests/integration/CapacityRaceTest.php` is the one that needed real processes. It starts eight, each with its own database connection, and holds them at a shared wall-clock instant before they all submit for the same single place. Exactly one may end up confirmed. Replacing insert-then-rank with the obvious check-then-insert makes all eight confirm — an event with one place selling eight — which is both what the design prevents and the proof that the concurrency is genuine rather than eight bookings in a queue.

## Checking against a real install

Unit tests stub WordPress, so they cannot catch a change in core's own behaviour — and every bug in the table above was found this way, not by the suite.

```sh
npx @wordpress/env start   # localhost:8888, admin / password
```

Worth checking by hand before any release:

1. Activate with `WP_DEBUG` on and confirm no notices anywhere.
2. Create an event in a timezone different from the site's, then change the site timezone and confirm the event does not move.
3. Set a capacity of 1 and register twice — the second must be waitlisted, not refused or accepted.
4. Switch every module off and confirm the front end shows no form and loads no assets.
5. Create a post with `post_type = 'events'`, run the migration, and confirm the URL still resolves.

## Blocks

```sh
npm install
npm run build     # or npm start to watch
```

`build/` is gitignored and produced by CI and by the deploy workflow. It is deliberately **not** in `.distignore` — the compiled blocks are the one build artefact that has to ship, and `PluginTest` fails if somebody adds it.

## REST API

Read-only, at `/wp-json/qevm/v1/`:

```
GET /qevm/v1/events?show=upcoming&per_page=10&category=&search=
GET /qevm/v1/events/{id}
```

Write endpoints are deliberately absent. The post type is registered with `show_in_rest`, so core already serves authenticated CRUD at `/wp/v2/qevm_event` with the permission handling the block editor relies on. A second write path would mean a second permission surface to audit for no benefit.

The joining link of an online event is only included for a user who can edit that event — a public meeting URL is an open door into the meeting.

## Upgrading from 1.0

Version 1.0 (2012) was 34 lines that registered one post type, `events`, and did nothing else. That is the entire legacy surface.

The key moves to `qevm_event`, because `events` is generic enough that any other event plugin or theme registering it silently collides. Public URLs are unaffected — the new post type pins its rewrite slug and archive back to `events`, so `/events/` and `/events/{slug}` resolve exactly as before. The migration is version-gated, idempotent, and touches only `post_type`.

## Requirements

- WordPress 6.5 or newer
- PHP 8.1 or newer

Why 8.1 rather than 7.4, and what it costs in reach, is recorded in [docs/adr/0002-php-and-wordpress-versions.md](docs/adr/0002-php-and-wordpress-versions.md).

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Security reports go through [SECURITY.md](SECURITY.md) rather than a public issue.

## License

[GPL-2.0-or-later](LICENSE). Copyright 2011–2026 Pankaj Anupam.
