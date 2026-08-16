# Development plan

How Quick Events Manager 26.0 gets built: what we do, in what order, and how each
piece is finished before the next one starts.

**26.0 is the only release.** Everything below ships in it, or it does not ship.
Nothing is published to WordPress.org until the whole plan is done. That is settled;
see [roadmap.md](roadmap.md).

Because there is no release boundary forcing checkpoints, **ordering and chunk
discipline are the only things keeping this finishable.** A ten-feature release built
with no internal structure is how projects stop.

---

## How we work

One chunk at a time. Every chunk follows the same loop:

```
  1  Read the chunk definition below
  2  Challenge it — if the definition is wrong, say so BEFORE writing code
  3  Implement, in the chunk's scope only
  4  Verify   phpcs · phpstan · unit · integration · axe (where applicable)
  5  Document in the same change — hooks, schema, ADR, changelog
  6  Report   what changed, what passed, what did not, what was skipped
  7  Review
  8  Commit
  9  Next chunk
```

### Rules

- **Never run ahead.** Do not start the next chunk because the current one felt small.
- **Never bundle chunks.** One chunk, one commit, one review.
- **If a chunk grows, stop and split it.** A chunk that turns out to be twice its
  estimate is two chunks that were not noticed. Report the split; do not absorb it.
- **A chunk is not done because it works.** It is done when it meets the Definition of
  Done in [engineering-standards.md §20](engineering-standards.md#20-definition-of-done).
- **Report honestly.** If a test fails, show the output. If a step was skipped, name
  it. Never describe a chunk as complete when part of it is not.
- **Stage gates are hard.** Do not begin a stage until the previous stage's gate
  passes.
- **Commits happen when asked**, not automatically. The plan says "commit each part";
  the maintainer says when.

### Sizes

`XS` under an hour · `S` a few hours · `M` half a day · `L` one to two days ·
`XL` three days or more

These are working-time estimates, not calendar time. This is a spare-time project;
the sequence matters, the dates do not.

---

## Stage 0 — Groundwork

**Goal:** make the codebase match the standards before adding anything to it.
**Why first:** the rename touches every file. Doing it after stage 1 means renaming
twice as much code.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C0.1** | Commit the working tree as a baseline on `feature/architecture` | XS | 8,800 uncommitted lines are no longer a single point of failure |
| **C0.2** | Tooling: `phpcs.xml.dist` (WordPress ruleset), `phpstan.neon.dist` (level 6), dev deps, CI jobs | M | `composer lint`, `composer analyse` run and pass |
| **C0.3** | PHP 8.1 / WP 6.5 bump — headers, `composer.json`, CI matrix, PHPUnit uncapped | S | [ADR-0002](adr/0002-php-and-wordpress-versions.md) signed off first |
| **C0.4** | **Rename**: `qem`→`qevm`, `QEM\`→`QuickEventsManager\` | L | [ADR-0001](adr/0001-naming-and-namespace.md). Includes `block.json`, CSS classes, capabilities, test bootstrap, docs. Directory names are unchanged — see the note below |
| **C0.5** | Enums for existing statuses — `RegistrationStatus`, `ModuleLevel` | S | First use of 8.1; proves the toolchain |
| **C0.6** | PHPCS cleanup — see the measured baseline below | M | Added after C0.2 measured the real number |
| **C0.7** | Type declarations to reach PHPStan level 6 — 162 findings | L | Added after C0.2 measured the real number; count corrected at C0.6. Grew to include two CI breaks and four defects the phars could not see — see below |

### Measured baseline, from C0.2

Real numbers, not estimates. PHPCS run locally against the committed code; PHPStan
run without the WordPress stubs that CI installs, so only its type findings are
meaningful here.

**PHPCS — 216 violations in 42 files.** For 8,800 lines that is low, and it breaks
down into four very different piles:

| Pile | Count | Handled by |
| --- | ---: | --- |
| Prefix and capability sniffs — code still says `qem`, config says `qevm` | 75 | **C0.4.** Going quiet is the proof the rename was complete |
| Formatting, auto-fixable by `phpcbf` | 45 | **C0.6** — 46 by the time it ran |
| Custom-table queries: `DirectQuery`, `NoCaching`, `InterpolatedNotPrepared` | 44 | **C0.6** — annotate with reasons, do not blanket-exclude |
| Genuine small cleanups: unused parameters, doc comments, reserved-word parameter names, `AlternativeFunctions` | ~44 | **C0.6** |

**C0.6 closed all four piles: PHPCS now reports zero.** The table below is kept as the
record of what the code looked like before, not as outstanding work. The custom-table
pile came out smaller than 44 because most of it was fixable rather than annotatable —
WordPress 6.2's `%i` identifier placeholder removes the table-name interpolation
outright, and the plugin's floor is 6.5.

Two findings were investigated and confirmed **false positives**, and must be
annotated rather than "fixed":

- `PreparedSQLPlaceholders` ×2 in `Repository.php` — the query is assembled from a
  dynamic `$clause`, so PHPCS cannot see the placeholders and cannot know `$params`
  is an array whose count matches. `$wpdb->prepare()` accepts a single array argument.
- `ValidatedSanitizedInput` ×6 in `FormHandler.php` and `FeaturesScreen.php` —
  sanitisation happens one layer down in `RegistrationService::validate()`
  (`sanitize_text_field`, `sanitize_email`, `absint` clamped 1–20). That is
  deliberate: the form handler and the REST route share one validation path, which
  is the entire reason the service exists. Sanitising twice would be theatre.

One finding is **independent confirmation of [ADR-0003](adr/0003-occurrence-table.md)**:
`WordPress.DB.SlowDBQuery` fires 8 times on exactly the `meta_key` / `meta_query`
date lookups the occurrence table replaces. WPCS reached the same conclusion from a
different direction.

Those 8 are the one group C0.6 silenced while agreeing with them. They are not false
positives and the annotations say so, each pointing at the note in `Events/Query.php`.
**C1.4 is not done until they are deleted** — PHPCS does not report a stale
`phpcs:ignore`, so nothing else will remind anyone. If removing them produces no new
findings, the migration off meta is complete.

> **Done at C1.4.** Every `meta_key` and `meta_query` date suppression is gone, and
> PHPCS reports nothing in their place. The two remaining `SlowDBQuery` annotations
> are `tax_query` ones, which were never part of this and are explained where they
> sit. The migration off meta is complete.

**PHPStan — level 6.** Of 539 local errors, ~356 are missing WordPress symbols that
`szepeviktor/phpstan-wordpress` resolves in CI. The real signal is **missing type
declarations**, which is expected for code written against PHP 7.4.

> **Corrected at C0.6.** This originally read "~151 (`missingType.parameter` 66,
> `missingType.iterableValue` 48, `missingType.return` 37)". Re-measuring against
> the same commit gives `missingType.return` **46**, not 37, so the real total was
> **160**. The 37 was a misread, not a change in the code. C0.6 then took it to
> **162** — `build_filter()` adds an `array` parameter and an inner untyped `array`
> in its return shape, both of which C0.7 types properly.

Level 6 is kept rather than lowered: `docs/engineering-standards.md` says typed
properties and return types are expected, and lowering the bar to make a number go
away is how standards stop meaning anything. The work is scheduled as C0.7 instead.

### What C0.7 found by running the real toolchain

Every number above was measured with standalone phars, because Composer is not
installed on this machine. C0.7 installed the actual dev dependencies, and the first
honest run of `composer check` failed for reasons that had nothing to do with types.

**Two of them would have broken CI on its first run.** Nothing had ever been pushed,
so nothing had ever executed `.github/workflows/tests.yml`.

| Defect | Effect | Fix |
| --- | --- | --- |
| `composer analyse` ran with `--memory-limit=512M` | PHPStan crashed outright once `php-stubs/wordpress-stubs` loaded | Raised to `1G` |
| `composer.json` allowed PHPUnit `^12.0` | PHPUnit 12 removed doc-comment metadata. `@dataProvider` was silently ignored, every provider-driven test ran once with no arguments, and the suite fell from **82 tests to 52** | Migrated to `#[DataProvider]` / `#[CoversClass]` attributes, which work on 10.5, 11 and 12 alike |

The PHPUnit one is the more serious of the two, because the failure mode is silent
rather than loud: had the tests not errored on the missing argument, thirty cases
would simply have stopped running while CI stayed green.

**The stubs were narrower than the functions they stand in for.** `do_action()` and
`apply_filters()` were declared here with fixed arity, so every call passing extra
arguments — eleven of them — was a call that works against this stub and fails
against WordPress. `esc_url_raw()` used `FILTER_SANITIZE_URL`, which keeps `<` and
`>`; core uses an allowlist that strips them, so the stub was weaker than the
function it imitates. None of this was visible to a green suite.

The lesson is the one CLAUDE.md already records, arriving from a new direction: a
stub that lies is worse than no stub. `tests/unit/bootstrap.php` now mirrors core's
signatures, including variadics and defaults, and says so at the top.

**Three defects were in production code**, all invisible to the phar run because it
had no WordPress symbols to compare against:

- `Module::level()` and both implementations still carried `@return int` after C0.5
  changed the native return type to `ModuleLevel`. Introduced by C0.5, caught here.
- `Event::$post` was documented `@var \WP_Post` when `get_post()` plainly returns
  `WP_Post|null`. That inaccuracy made the `is_valid()` guard look redundant to
  static analysis; the property is now `?\WP_Post` and the guard reads as the real
  check it is.
- `WP_HTTP_Response::header()` takes a string, and the pagination headers were
  passing ints.

**One test could never fail.** `test_every_meta_key_has_a_sanitiser` asserted that
each definition's `sanitize` entry was callable — which the declared array shape
already guarantees and PHPStan already enforces. It now invokes every sanitiser with
hostile input instead, and that rewrite is what exposed the `esc_url_raw()` gap.

> **C0.4 is the highest-risk mechanical change in the plan.** A naive replace over
> `*.php` misses `block.json` names, CSS class names, script handles, the `.pot`
> header and every document. Grep for `qem` across *all* file types afterwards and
> expect the count to be zero.

**Gate:** 73 unit tests still green · PHPCS and PHPStan clean · no occurrence of
`qem` outside the ADR that explains the rename · plugin activates on a real install
with `WP_DEBUG` on and no notices.

> **Gate passed, 2026-08-11.**
>
> | Criterion | Result |
> | --- | --- |
> | Unit tests green | **82 tests, 260 assertions.** The gate said 73, which was the count when it was written; C0.5 and C0.7 added nine. The number to hold from here is 82 |
> | PHPCS clean | Zero violations, exit 0 |
> | PHPStan clean | Level 6, "No errors", with `szepeviktor/phpstan-wordpress` actually installed |
> | No `qem` in code | Zero across `*.php`, `*.json`, `*.js`, `*.css`, `*.pot`, `*.txt`, `*.yml`. Remaining occurrences are all in `docs/` and one `phpcs.xml.dist` comment, every one of them prose about the rename |
> | Activates clean on a real install | WordPress 7.0.3, `WP_DEBUG` and `WP_DEBUG_LOG` on. Activated, then requested the front page, the event archive and both REST routes: four 200s and **no `debug.log` was created at all** |
>
> Two things worth carrying into Stage 1. `composer check` passes end to end for the
> first time — every earlier "green" in this stage was measured with standalone phars
> that had no WordPress symbols, and installing the real dependencies is what exposed
> both CI breaks. And the integration suite still does not exist: everything touching
> `$wpdb` has been verified with throwaway scripts against wp-env, which is C1.10's
> job to replace with something that runs unattended.

> **Directory layout is deliberately unchanged.** An earlier draft of this plan moved
> PHP from `includes/` to `src/` and block sources from `src/` to `src-js/`. That was
> reverted during C0.4: `includes/` is the WordPress convention, PSR-4 does not
> require `src/`, and `src/` is the `@wordpress/scripts` default for block sources so
> moving it would need a non-default build flag. The move was churn with no
> functional gain, and it would have broken `.distignore`, which excludes `src`
> precisely because block sources do not ship.

---

## Stage 1 — Schema foundation

**Goal:** every irreversible schema decision, made correctly, while there is nothing
in the field to migrate.
**Why now:** free today, a migration on every installation after 26.0 publishes.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C1.1** | Migration framework — versioned, idempotent, batched, runs on `admin_init` | M | `includes/Install/Migrations/`, `docs/migrations.md` |
| **C1.2** | `qevm_occurrences` table + `OccurrenceRepository` | M | [ADR-0003](adr/0003-occurrence-table.md) |
| **C1.3** | Occurrence sync on `save_post` / delete + `wp qevm occurrence rebuild` | M | The rebuild command ships *with* the table, not after |
| **C1.4** | `OccurrenceQuery`; repoint archive, upcoming, past, REST; delete every `meta_query` date path | L | The chunk that pays off the whole stage |
| **C1.5** | `qevm_attendees` table + `AttendeeRepository` | M | [ADR-0004](adr/0004-registration-attendee-split.md) |
| **C1.6** | Alter registrations — `booker_*`, `occurrence_id`, `order_id`, `consent_*` | M | Reserved columns cost nothing now |
| **C1.7** | Attendee creation in `RegistrationService` + per-guest name fields on the form | L | Capacity still counts places; insert-then-rank untouched |
| **C1.8** | Consent capture — configurable text, version, UTC timestamp | S | Personal data currently stored with no recorded basis |
| **C1.9** | `manage_qevm_checkins` capability added to `Installer` | XS | Must exist pre-release or it needs a role migration later |
| **C1.10** | Integration test harness — real WordPress + MySQL via wp-env | L | The piece most likely to be skipped, and the one that catches most |
| **C1.11** | Integration tests: occurrence sync, rebuild, attendee split, capacity under 8 parallel processes, migration run twice | L | Stage gate evidence |
| **C1.12** | `KEY status_end (status, end_utc)` on `qevm_occurrences`; schema version 4 → 5 | XS | Added by the gate, not planned. Nothing indexed the column the archive filters on |

**Gate:** upcoming-events query contains **no `CAST(`** and `EXPLAIN` shows
`type: range` on `qevm_occurrences` · 10,000 events render the archive in under 200ms
of DB time · a 3-place booking creates 1 registration and 3 attendee rows · 8 parallel
submissions to a capacity-1 event produce exactly 1 confirmed · every migration is a
no-op on second run.

> **Gate passed 2026-08-12 — on the second run. The first found a schema defect.**
>
> | Criterion | Result |
> | --- | --- |
> | No `CAST(` in the upcoming query | **Pass.** No `CAST(`, and no reference to `meta_value` at all |
> | `EXPLAIN` shows `type: range` on `qevm_occurrences` | **Failed, then passed.** `type: ALL`, `key: (none)`, 10,000 rows on the first run. After C1.12: `type: range`, `key: status_end`, **501 rows** |
> | 10,000 events, archive under 200ms of DB time | **Pass, by a wide margin.** 1.69ms median across 8 queries, page 1; 1.66ms at page 50, so it does not degrade with depth. The `meta_query` path this stage replaced measures 77.51ms on the same data — **45x** |
> | 3-place booking → 1 registration, 3 attendees | **Pass**, `RegistrationTest` |
> | 8 parallel submissions to capacity 1 → 1 confirmed | **Pass**, `CapacityRaceTest`, eight real processes held to one instant |
> | Every migration a no-op on second run | **Pass**, `MigrationTest` |
>
> Suites: 152 unit tests / 467 assertions · 49 integration tests / 238 assertions ·
> PHPCS and PHPStan clean. The fixture is 10,000 events with 95% of them already
> over, because that is the shape an event site actually has.
>
> **The defect.** The archive filters on `end_utc >= now`. Every index on
> `qevm_occurrences` led with something else — `start_utc`, `event_id`,
> `series_uuid`, `status` — so the predicate had nothing to seek on and MySQL read
> all 10,000 rows at every date. [ADR-0003](adr/0003-occurrence-table.md) had claimed
> that making `end_utc NOT NULL` "collapses the three-branch `OR` into one indexed
> range scan". The collapse happened; the scan was never indexed. A correct decision
> about *storage* does not carry a correct decision about *indexing* with it.
>
> Measured across four archive shapes, before and after the candidate
> `KEY status_end (status, end_utc)`:
>
> | Upcoming share | Before | After |
> | --- | --- | --- |
> | 100% (10000/10000) | `ALL`, 10000 rows, 9.11ms | `ALL`, 9.05ms |
> | 50% (5000/10000) | `ALL`, 10000 rows, 5.17ms | `ALL`, 5.14ms |
> | **5% (500/10000)** — a real archive | `ALL`, 10000 rows, 1.72ms | **`range`, 501 rows, 0.81ms** |
> | 1% (100/10000) | `ALL`, 10000 rows, 1.42ms | **`range`, 101 rows, 0.27ms** |
>
> Above roughly a third selectivity MySQL keeps scanning, and it is right to — reading
> the table beats 5,000 index lookups. What matters is the two rows where it *should*
> seek and could not, which is the shape every real archive has.
>
> **Why the timing mattered.** An index added before publication is one line in the
> installer that `dbDelta` applies. The same index added after 26.0 ships is a locking
> `ALTER TABLE` on every site that has one. This is precisely what Stage 1 exists for,
> and the gate is what caught it — the unit suite cannot, and the integration suite at
> a handful of rows reports `range` quite happily, because at that size MySQL picks an
> index for reasons that have nothing to do with whether the right one exists.

---

## Stage 2 — Correctness gaps

**Goal:** close the things that make the plugin *wrong* rather than incomplete.
Small chunks, high value.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C2.1** | Signed cancellation links — `hash_hmac` + `wp_salt()`, expiry, `hash_equals` | M | Attendees currently cannot withdraw at all |
| **C2.2** | Waitlist auto-promotion on cancellation, **with** notification | M | Silent promotion surprises people |
| **C2.3** | `.ics` attached to the confirmation email | S | The download exists on the page; the email is where people want it |
| **C2.4** | Duplicate event action | S | The most-used organiser action; also 90% of "event templates" |
| **C2.5** | Manual attendee add + resend confirmation | M | Phone and in-person bookings are real |
| **C2.6** | ~~`rtl.css`~~ → CSS logical properties | XS | Arabic, Hebrew and Persian are large WordPress markets. **Definition changed during the chunk — see below** |
| **C2.7** | Form accessibility pass + **axe-core in CI** | L | From here, a violation fails the build. Brought Playwright into the project from nothing — [ADR-0013](adr/0013-accessibility-testing.md) |

**Gate:** AC-3, AC-4, AC-5 pass · cancelling a 3-place booking frees 3 places and
promotes and emails the next waitlisted person · axe-core clean on archive, single,
form, form-in-error, confirmation.

> **Gate run 2026-08-15. The audit against AC-3/4/5 found more than it was meant
> to — including one missing feature.**
>
> Running the gate meant checking every criterion against an actual test rather
> than against memory, and eight were not covered. One of the eight was not a
> test gap at all:
>
> **AC-3.6 was a feature that did not exist.** The criterion says registration
> after the closing datetime is impossible and "the form is replaced by an
> explanation". The form was replaced by nothing: `Renderer::registration_form()`
> returned an empty string. A visitor following a link to register arrived at a
> page that looked broken, and the organiser got the email asking why.
>
> Fixed by `RegistrationService::closed_reason()`, which replaces a boolean that
> was hiding four different situations. Only two of them are the visitor's
> business: an event that has finished, and a closing date that has passed.
> Registration that was never switched on explains nothing, because nothing was
> ever offered; a closure imposed by somebody's own `qevm_registration_is_open`
> explains nothing either, because that code closed it for a reason only it
> knows, and inventing wording on its behalf would put our words on their front
> page. Wording is filterable through `qevm_registration_closed_notice`.
>
> **AC-4.2 was untestable rather than untested.** `CancellationHandler::handle()`
> reads `$_POST`, checks a nonce and ends in `exit()`, so no test could call it —
> every cancellation test reached past it to `Repository::update_status()`,
> proving the effect and skipping the decision. The signature check, the lookup
> and the status change are now in `process()`, which returns an outcome instead
> of redirecting. The nonce stays in `handle()` deliberately: it is a fact about
> the request, and a method taking "is this request genuine" as an argument is a
> method somebody can call with `true`.
>
> | Criterion | Result |
> | --- | --- |
> | AC-3.1 JS-disabled registration via POST-redirect-GET | **Was uncovered.** Now three Playwright tests with `javaScriptEnabled: false` |
> | AC-3.2 confirmation carries reference, timezone and `.ics` | Pass |
> | AC-3.3 eight concurrent → 1 confirmed, 7 waitlisted | Pass, real processes |
> | AC-3.4 duplicate address refused with a readable message | **Was admin-path only.** Now covers the public form and the message |
> | AC-3.5 3 places → 1 registration, 3 attendees | Pass |
> | AC-3.6 closed registration explained | **Was missing entirely.** Built, and covered in PHP and in the browser |
> | AC-3.7 thirty submissions from one address succeed | **Was a constant assertion** (`>= 20`, which would have passed a regression below the thirty the criterion names). Now thirty real submissions through the public path with the limiter live |
> | AC-3.8 manual add counts against capacity | Pass |
> | AC-4.1 signed link, no login | Pass |
> | AC-4.2 confirming cancels and frees the place | **Was unreachable.** Now covered through `process()` |
> | AC-4.3 first waitlisted promoted and emailed | Pass |
> | AC-4.4 tampered link reveals nothing about existence | **Property held, nothing asserted it.** `verify()` touches no database, so a forged token gets the same answer whether or not the booking exists — now pinned by a test comparing both messages byte for byte |
> | AC-4.5 cancelling 3 places frees 3 | Pass |
> | AC-5.1 consent required and unchecked | **Was config-only.** Now asserted against the rendered markup |
> | AC-5.2 consent version and UTC timestamp stored | Pass |
> | AC-5.3 / AC-5.4 export and erasure | Pass |
> | AC-5.5 no IP in any table or option | **Was uncovered.** `NoIpAddressTest` inspects the schema, the rows and the options; verified by adding an `ip_address` column and watching it fail |
> | AC-5.6 health-adjacent fields excluded from CSV | **Out of scope** — custom fields are C3.5 |
> | 3-place cancel frees 3, promotes and emails | Pass, as one test written to read like the criterion |
> | axe-core clean on the five screens | Pass |
>
> **On AC-4.4, one thing is deliberately not met as written.** Expired and forged
> links give *different* messages, and a test pins that difference. Telling
> somebody their link expired when it was never valid sends them hunting for a
> newer email that does not exist. Neither message touches the database, so
> neither reveals whether a booking exists — the property the criterion protects
> holds. Recorded here rather than quietly satisfied.


> **C2.6 was built differently from its definition.** The chunk asked for an
> `rtl.css`; there isn't one, and there should not be.
>
> The whole right-to-left surface was five declarations: `border-left` on
> `.qevm-notice` plus its three colour variants, and the honeypot's `left: -9999px`.
> WordPress offers two ways to serve those. `wp_style_add_data( $handle, 'rtl',
> 'replace' )` swaps in a complete `-rtl` twin — a second copy of 350 lines
> maintained so that four of them can differ. The additive form is smaller but is
> still a second file somebody has to remember every time they add a margin. Both
> drift, and they drift in a file nobody on the project reads in the language it
> exists for.
>
> CSS logical properties do it with no second file: `border-inline-start` is the
> left border in English and the right border in Arabic, decided by the `dir` the
> theme already sets. Grid and flex flip on their own, which is why only five
> declarations were ever in question.
>
> What this gives up is the ability to make RTL *look different* rather than
> mirrored. This plugin has no such need; the file can be added if one appears.
>
> The risk it introduces is that the next person writes `margin-left` out of habit
> and nothing complains, because in English it looks correct. `tests/unit/StylesheetTest.php`
> reads both stylesheets and fails on any physical direction property — verified by
> adding a `margin-left` and watching it fail. The honeypot keeps `left` as a
> fallback with `inset-inline-start` after it, and a test asserts that order,
> because if that offset ever failed to apply the honeypot would become a visible
> "Website" field that people fill in and are then refused for.

---

## Stage 3 — Records and fields

**Goal:** promote venues and organisers from flat meta to reusable records, and add
custom questions. Both change data shape, so they precede any UI that depends on them.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C3.1a** | `qevm_venue` CPT + venues module + resolution with flat-meta fallback | L | [ADR-0014](adr/0014-venue-records-with-flat-fallback.md). **Split from C3.1 — see below** |
| **C3.1b** | Batched, deduplicating promotion of existing flat addresses into records, on module enable | M | Split from C3.1. Matched on the whole normalised address, never the name alone |
| **C3.2** | `qevm_organizer` CPT + same pattern | M | "Same pattern" read as *extract* the pattern, not copy it — `includes/Records/` |
| **C3.3** | Custom field definitions — post meta JSON, admin UI, types, required, ordering | L | Definitions are config; JSON is correct here. Keys are minted, never derived from the label — see below |
| **C3.4** | `qevm_attendee_meta` + form rendering + validation | M | Answers are reportable; JSON is not. Asked once, of the booker — see below |
| **C3.5** | Custom answers in CSV export and attendee screen; health-adjacent fields flagged and excluded by default | M | Dietary and access needs are health-adjacent. Closes AC-5.6. Found an erasure defect — see below |
| **C3.6** | Data retention setting + cron sweep, with a filter | M | |

**Gate:** an event created before the venues module existed still renders its address ·
a custom field appears on the form, in the export, and in the privacy export · a
flagged field is absent from CSV unless explicitly included.

> **C3.1 was challenged and split before any code was written.**
>
> The row said "`qevm_venue` CPT + migration from flat meta". Three things were
> wrong with it.
>
> **The promotion must not be a migration.** `Install\Migrations\Runner` is
> schema-version-driven and runs on `admin_init` for every site on upgrade.
> Promoting flat addresses there would create `qevm_venue` posts on sites that
> never switch venues on — where the post type is not registered at all, so the
> rows would exist and be reachable from nowhere but the database. Promotion
> belongs in the module's own enable path.
>
> **Which means C3.1 needs a module, and the row did not mention one.** Every
> other document does: `roadmap.md` says "the flat fields must keep working for
> anyone who does not switch on the module", and the architecture invariant is
> that a feature is a module or it is core. The plan row was the odd one out.
>
> **Deduplication is the feature, and the row omitted it.** Forty events at the
> same address becoming forty records makes nothing reusable. The key is the
> normalised address tuple rather than the name — two "Town Hall"s in different
> cities are different buildings, and a silent merge cannot be undone.
>
> With a CPT, a module, capabilities, an editor selector, resolution, fallback,
> batched promotion and deduplication, the chunk was XL rather than L, so it
> was split at the seam the plan's own rule implies: C3.1a is the module and
> resolution, C3.1b is the promotion.
>
> **"Flat fields keep working" turned out to be three cases, not one** — module
> off; module on with nothing chosen; module on with a record chosen that has
> since been trashed or deleted. The third is why nothing ever deletes the flat
> meta, and it is what makes disabling the module non-destructive as
> `Module::deactivate()` requires. [ADR-0014](adr/0014-venue-records-with-flat-fallback.md)
> records the decision and the alternative that was rejected.
>
> **C3.3 — the field key is generated, never derived.** Deriving it from the
> label is the obvious implementation and it destroys data silently. Answers are
> stored against the key, so a key built from "Dietary requirements" is orphaned
> the afternoon somebody edits the label to "Dietary requirements or allergies".
> The form keeps working, that column of the export comes back empty, and the
> only trace is rows in a table nobody is looking at. Keys are random, assigned
> on creation, and carried through every edit; a test renames a question through
> the editor's own save routine and fails if the key moves.
>
> **The `sensitive` flag ships in C3.3 although nothing reads it until C3.5.**
> Dietary requirements and access needs are the two questions every event asks
> and both reveal something about health. Reserving the flag now costs a key in
> a JSON object; adding it after people have defined fields means walking every
> event on every site.
>
> **C3.4 asks the custom questions once, of the person booking.** The storage is
> per attendee, so a set per guest is a change of form and not of schema — but
> the form asks one set. Places run to twenty and questions to twenty, and the
> guest rows are already all rendered and hidden, so a set each is four hundred
> inputs in the markup of a form that usually books one place. Doing it properly
> means building the rows with script or paginating them, and both are larger
> than this chunk. The part that mattered was making validation match what is
> rendered: checking positions the form never asked about would make a required
> question unanswerable for guests two and up and refuse the booking outright.
>
> **The harness needed fixing in the same chunk.** `restore_schema()` drops every
> plugin table and then calls `Installer::upgrade_schema()`, which only activates
> the modules the option currently lists — and `setUp()` rewrites that option for
> every test. The first test to call it therefore left every later test running
> against a table that was not there. It showed as a wall of "table doesn't
> exist" from the row counters rather than as a failure, which is worse. Both
> `restore_schema()` and the integration bootstrap now create every module's
> tables regardless of what is switched on.
>
> **C3.5 found a privacy defect, not just a missing feature.** Deleting an
> attendee did not delete the answers hanging off them. An erasure request
> therefore removed the registration and the attendee rows and left the
> person's access requirements in `qevm_attendee_meta` with nothing pointing at
> them — unreachable by any later export, unreachable by any later erasure, and
> reported to the requester as "removed". `AttendeeRepository` now clears them
> first, and does so whether or not the custom fields module is switched on,
> because the rows exist regardless of what the Features screen says.
>
> **The exclusion is a second button, not a setting.** A setting is ticked once
> by somebody who needed the data that afternoon and stays ticked for every
> export anybody makes afterwards. The button only appears when the event
> actually asks something sensitive, so it does not become a control people
> learn to click past.
>
> **The screen shows everything; the file does not.** Not an inconsistency: the
> attendee screen is behind a capability, shows one event, and the answers are
> the reason the question was asked. A CSV leaves the building. The privacy
> export carries the sensitive answers too — withholding somebody's own dietary
> requirements from their own subject access request would be the flag doing
> the opposite of its job.
>
> **`Exporter::handle()` was untestable in the same way `CancellationHandler`
> was at AC-4.2** — it ends in `exit()`, which cannot be caught, so everything
> deciding what the file contains sat where no test could reach it. The
> file-building half is now `Exporter::write()`, taking a stream. The capability
> and nonce stay in `handle()`.
>
> **Found while surveying for the chunk, and fixed separately:** the event
> editor's save routine wrote `qevm_organizer_phone` from a form that never
> rendered it, so every save of every event wrote an empty string over any phone
> number set through REST or by code. It never shipped. `MetaBoxFieldsTest` now
> works against the rendered form rather than a hand-kept list, so a field that
> is saved without being rendered fails the build.

---

## Stage 4 — Calendar

**Goal:** the view everyone expects, built once, on the occurrence table, accessible.
**Why here:** building it before stage 1 would mean building it twice.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C4.1** | Month grid — real `<table>` with `scope`, arrow-key navigation, announced changes | L | The accessibility test the whole standard rests on |
| **C4.2** | **List view as a first-class equivalent**, not a fallback | M | Highest-value accessibility decision in the release |
| **C4.3** | Previous/next navigation without a page reload; mobile layout | M | |
| **C4.4** | Calendar block + `[qevm_event_calendar]` shortcode, one renderer | M | |

**Gate:** axe-core clean · fully keyboard navigable · the list view returns the same
events as the grid for the same range · usable on a 360px viewport.

---

## Stage 5 — Communication

**Goal:** make email reliable before anything else starts sending more of it.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C5.1** | `qevm_email_queue` + cron worker + batching + retry + per-recipient status | L | `wp_mail()` is synchronous; 500 recipients in one request is a timeout |
| **C5.2** | Route existing confirmation and notification mail through the queue | M | |
| **C5.3** | Email templates — editable subject and body, placeholders, HTML option | L | Replaces the plain-text builders left deliberately simple |
| **C5.4** | Email all attendees, through the queue | M | |

**Gate:** 500 recipients send without a timeout, with a per-recipient record of what
was sent and what failed · a failed send is retried and visible, not silent.

---

## Stage 6 — Recurring events

**Goal:** the largest correctness problem in the release.
**Note:** C6.1 is a document, not code, and it is not optional. Recurrence edit
semantics written during implementation are recurrence edit semantics done wrong.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C6.1** | **Write the spec**: "this occurrence" / "this and following" / "all" — every case, including registrations already attached | M | Doc only. Reviewed before C6.2 starts |
| **C6.2** | Recurrence rule domain — parsing, validation, `series_uuid` | L | |
| **C6.3** | Occurrence generation with a bounded horizon + exclusion dates | L | Generated in the event's own timezone, so 18:00 stays 18:00 across DST |
| **C6.4** | Edit semantics implementation + `is_exception` handling | XL | The hard part. Split if it grows |
| **C6.5** | Recurrence admin UI | L | |

**Gate:** a weekly series for 52 weeks generates 52 occurrences · editing one leaves 51
untouched and marks it `is_exception` · "this and following" splits the series
correctly and both halves share the `series_uuid` · registrations survive an edit to
their occurrence · generation is bounded.

---

## Stage 7 — Ticketing

**Goal:** ticket types with their own capacity. Precedes money, because capacity has
to move per-type first.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C7.1** | `qevm_ticket_types` table + repository + admin UI | L | Free types are `price_minor = 0` — same entity, no separate path |
| **C7.2** | Per-type capacity, preserving insert-then-rank | L | The concurrency guarantee must survive the move |
| **C7.3** | Ticket selection on the registration form; attendee gains `ticket_type_id` | L | |
| **C7.4** | Sale windows + early bird | M | |

**Gate:** two ticket types with separate capacities sell out independently · the
8-parallel-process test passes **per type** · archiving a type does not break existing
registrations.

---

## Stage 8 — Event operations

**Goal:** the day of the event — the journey most plugins neglect.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C8.1** | `qevm_checkins` table + `CheckInService`, unique-key concurrency | M | Duplicate insert means "already checked in", not an error |
| **C8.2** | QR generation, no dependency | M | Encodes `ticket_code` |
| **C8.3** | QR in confirmation emails | S | |
| **C8.4** | Check-in screen — mobile, connectivity-tolerant, accessible | XL | Used one-handed in a doorway. Split if it grows |
| **C8.5** | Named roles: Event Manager, Event Organizer, Event Staff | S | Capabilities already exist; this is the wiring |
| **C8.6** | Check-in REST endpoint + attendance report | M | |

**Gate:** two devices check in 200 attendees concurrently with nobody marked twice ·
the Event Staff role can check people in and cannot edit any post · a reversal is
recorded, not deleted · axe-core clean on the check-in screen.

---

## Stage 9 — Commerce

**Goal:** paid tickets, one gateway done properly.
**Scope is fixed by [ADR-0010](adr/0010-payment-gateway-scope.md):** interface +
Stripe + WooCommerce. Not Razorpay, not PayPal.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C9.1** | `qevm_orders`, `qevm_order_items`, `qevm_transactions` + repositories | L | [ADR-0006](adr/0006-money-and-immutability.md) |
| **C9.2** | `Money` value object — integer minor units, one formatting boundary | M | A raw `49950` in a template is a visible bug |
| **C9.3** | `Gateway` interface + order lifecycle + **seat holds with expiry cron** | L | Without holds, an abandoned checkout permanently eats a seat |
| **C9.4** | Stripe — PaymentIntent, return flow | XL | Split if it grows |
| **C9.5** | Stripe webhooks — signature verification + **idempotency** | L | Gateways deliver twice by design |
| **C9.6** | Refunds, full and partial, releasing capacity and promoting the waitlist | L | |
| **C9.7** | WooCommerce bridge — ticket type → product; mutually exclusive with the built-in gateway | XL | Inherits a decade of hardening |

**Gate:** an abandoned checkout releases its seat · the same webhook delivered twice
creates one registration · a full refund frees capacity and promotes the waitlist, a
partial refund does not · an event sells and refunds end to end through WooCommerce ·
no card data touches the plugin.

---

## Stage 10 — Release readiness

**Goal:** the stage most likely to be skipped. It is not optional.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C10.1** | Full accessibility audit across every screen; `docs/accessibility.md` with honest known limitations | L | |
| **C10.2** | Performance benchmark at 10,000 events and 10,000 registrations; publish the numbers | M | |
| **C10.3** | Security pass over **everything**, not just the last thing built | L | Checklist in [engineering-standards.md §7](engineering-standards.md#7-security-rules) |
| **C10.4** | `npm run build` wired; all blocks verified in the editor; front end loads zero block JS | M | Has never been run |
| **C10.5** | Multisite activation and uninstall tested | M | Currently untested |
| **C10.6** | i18n sweep; `.pot` regenerated; RTL verified | M | |
| **C10.7** | `readme.txt` rewritten for the finished plugin; screenshots in `.wordpress-org/` | M | |
| **C10.8** | `SVN_USERNAME` / `SVN_PASSWORD` verified on the repo | S | Their absence already failed a release on a sibling plugin |
| **C10.9** | Final review; tag `26.0` | S | Tag must equal `Stable tag` exactly, no `v` prefix |

**Gate:** every acceptance criterion passes on PHP 8.1 through 8.5 with `WP_DEBUG` and
`SCRIPT_DEBUG` on and no notices.

---

## Progress

Update this as chunks land. It is the honest record, not an aspiration.

| Stage | Chunks | Status |
| --- | :-: | --- |
| 0 · Groundwork | 7 | **C0.1 ✓ · C0.2 ✓ · C0.3 ✓ · C0.4 ✓ · C0.5 ✓ · C0.6 ✓ · C0.7 ✓** — stage complete |
| 1 · Schema foundation | 12 | **C1.1–C1.12 ✓** — stage complete. Gate failed on `EXPLAIN` first time round; C1.12 was added to fix it and the gate re-run passes |
| 2 · Correctness gaps | 7 | **all 7 chunks complete** — gate run 2026-08-15 and passed; eight acceptance criteria were uncovered and one (AC-3.6) was an unbuilt feature |
| 3 · Records and fields | 6 | not started |
| 4 · Calendar | 4 | not started |
| 5 · Communication | 4 | not started |
| 6 · Recurring events | 5 | not started |
| 7 · Ticketing | 4 | not started |
| 8 · Event operations | 6 | not started |
| 9 · Commerce | 7 | not started |
| 10 · Release readiness | 9 | not started |
| | **71** | |

---

## Deliberately not in this plan

Sessions · tracks · speakers · sponsors · exhibitors · booths · badges · seat maps ·
certificates · per-session registration · Razorpay · PayPal · importers from other
plugins · audit log · analytics dashboards · CRM · marketing automation · SSO/SAML ·
multi-tenancy · mobile apps.

See [ADR-0011](adr/0011-scope-boundary.md) and
[ADR-0010](adr/0010-payment-gateway-scope.md). This list belongs in the README too:
a refused feature list is what keeps the plan above finishable.

## Before stage 0 begins

1. ~~[ADR-0002](adr/0002-php-and-wordpress-versions.md) needs sign-off.~~
   **Resolved 2026-08-11: PHP 8.1, WordPress 6.5.** C0.3 and C0.5 are unblocked.
2. **C0.1 should happen today**, independent of everything else. The entire codebase
   is untracked, on one machine.

Nothing else is open. Stage 0 can start.
