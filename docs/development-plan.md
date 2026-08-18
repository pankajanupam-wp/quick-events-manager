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

> **Gate passed 2026-08-16, on the second run. The first found a defect that
> made the whole of C3.4 useless in production.**
>
> Both criteria are worded about the *page* — "renders its address", "appears on
> the form" — and nothing asserted either. Every custom-field test called
> `RegistrationService::create()` directly, and every venue test asserted on
> `Venue::summary()`. Both layers were correct. The layer between them was not.
>
> **`FormHandler` never read the answers.** It builds its input array by naming
> each key, and the custom answers were not among them, so every answer typed
> into the public registration form was discarded on the way to the service. The
> form rendered the questions; the service was ready to check them; nothing
> carried them across. No test could see it, because no test crossed that
> boundary. Found by making the accessibility fixture ask a *required* question
> and watching four browser tests fail to submit at all.
>
> `collect_input()` is now split out of `handle()` — the third time this shape
> has appeared, after `CancellationHandler::process()` at the Stage 2 gate and
> `Exporter::write()` in C3.5. A method that reads a superglobal and ends in a
> redirect or an `exit()` hides everything it decides.
>
> | Criterion | Result |
> | --- | --- |
> | An event from before the venues module renders its address | **Property held, nothing asserted it.** Now rendered through `Renderer::event_details()` and asserted on the markup, before and after switching the module on |
> | A chosen venue is what renders | **Was uncovered.** Now asserted, including that the old flat address is *not* what appears |
> | A chosen organiser is what renders | **Was uncovered.** Same treatment |
> | A custom field appears on the form | **Failed.** The markup was right and the submission was dropped — see above |
> | A custom field appears in the export | Pass |
> | A custom field appears in the privacy export | Pass |
> | A flagged field is absent from CSV unless included | Pass, and verified by removing the filter |
> | axe-core clean on the new controls | **Was uncovered.** The fixture had no custom questions, so six control types — select, radio group, checkbox group, single checkbox, textarea, help text — had never been scanned. Added to the fixture; clean |
>
> Suites: 182 unit / 604 · 201 integration / 1147 · 14 accessibility.
> PHPCS and PHPStan clean.

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
| **C4.1** | Month grid — real `<table>` with `scope`, announced changes | L | The accessibility test the whole standard rests on. Arrow-key navigation moved to C4.3 — see below |
| **C4.2** | **List view as a first-class equivalent**, not a fallback | M | Highest-value accessibility decision in the release. What "equivalent" had to mean is below |
| **C4.3** | Previous/next navigation without a page reload; mobile layout; arrow keys from C4.1 | M | Pulled the `[qevm_event_calendar]` shortcode forward from C4.4 — see below |
| **C4.4** | Calendar block, one renderer with the shortcode | M | Shortcode landed early in C4.3. Found two latent defects — see below |

**Gate:** axe-core clean · fully keyboard navigable · the list view returns the same
events as the grid for the same range · usable on a 360px viewport.

> **Gate passed 2026-08-17. It found one thing, and it was the same shape as
> the last two gates found.**
>
> **Every axe scan of the calendar had been of an empty grid.** The fixture had
> no events in the month the calendar opens on, so the scans passed on markup
> containing no event links, no populated list and none of the has-events
> styling — while reporting the calendar as clean. The markup turned out to be
> fine, but nothing had established that. The fixture now seeds events in this
> month and the next, including a multi-day one, and both scans assert the
> calendar has something in it before scanning, so it cannot quietly go back to
> proving nothing.
>
> That is three gates in a row where the finding was *a test that was passing
> without exercising the thing it named*: Stage 2's rate limit asserted a
> constant, Stage 3's custom-field controls were never scanned, and this. The
> pattern is a fixture that predates the feature it is supposed to cover.
>
> | Criterion | Result |
> | --- | --- |
> | axe-core clean | **Was scanning an empty grid.** Now scans a populated one, grid and list, at 360px and 320px |
> | Fully keyboard navigable | **Was partly assumed.** The arrow-key test began with a programmatic `focus()`, which says nothing about whether anybody can reach a cell. Now a full journey: tab in from the top of the document, reach the month control, activate it with Enter, and confirm focus is still on a control afterwards |
> | The list returns the same events as the grid | Pass in PHP, and now also cross-checked in the browser by comparing the rendered titles in both views |
> | Usable at 360px | Pass. 320px was already tested, which is stricter, but the gate names 360 so 360 is now tested by name |
>
> Suites: 183 unit / 612 · 219 integration / 1247 · 30 accessibility.
> PHPCS and PHPStan clean.

> **C4.1 departures, recorded before the gate.**
>
> **Arrow-key navigation moved to C4.3.** It is the ARIA `grid` pattern — a
> roving tabindex over the day cells — and it is an enhancement over a table
> that has to work without it. Building it in the same chunk as the markup
> would mean the keyboard story and the semantics land together with nothing
> having proved the semantics stand alone. C4.3 already owns "navigation
> without a page reload", which is the other half of the same script.
>
> **"Announced changes" is mostly free, and that is the argument for a table.**
> A `<td>` inside a row, under a `<th scope="col">`, is announced with its
> column header by every screen reader without a live region. The `<caption>`
> carries the month and the event count, so entering the grid says where you
> are before you move through thirty cells. A live region is needed only for
> the changes C4.3 introduces.
>
> **Days are bucketed by the organiser's local date, never by the UTC instant.**
> The first version of the test that was supposed to prove this used a Kolkata
> event at 23:30 — which is 18:00 UTC, the same date either way — so bucketing
> on the UTC columns passed it. Rewritten with New York, where 23:30 is 03:30
> the next morning in UTC, and it fails as it should. The test now also asserts
> that its own fixture still spans a UTC date boundary, so it cannot quietly
> lose its meaning again.
>
> **C4.2 — what "first-class equivalent" had to mean in practice.** Three
> things, none of which are the markup:
>
> 1. **The same events, by construction.** Both views render from the same
>    `Month` object rather than from two queries that have to be kept in step.
>    The gate's criterion is then true because there is only one answer, and the
>    test exists to say the construction has not been quietly undone — proved by
>    giving the list its own narrower slice and watching it fail.
> 2. **The choice survives navigation.** The view travels in the URL, so
>    choosing the list and stepping to the next month does not land back in a
>    grid. A view that forgets itself on every click is a fallback with better
>    wording.
> 3. **Either can be the default.** `qevm_calendar_default_view` lets a site
>    that knows its audience commit to the list, rather than asking every
>    visitor to switch every time.
>
> The one place the list deliberately differs is that it omits days with nothing
> on them. A grid shows every date because the shape is the information; a list
> reading "2 September, no events" thirty times is a list nobody finishes. An
> empty month therefore says so in words, because an empty list looks like a
> page that failed to load.
>
> **Closed at C4.3.** The shortcode was pulled forward for exactly this reason;
> the calendar is now scanned in a browser, grid and list, including at 320px.
>
> **C4.3 pulled the shortcode forward from C4.4, deliberately and out of
> order.** C4.3 is navigation and keyboard behaviour, none of which can be
> driven in a browser without a page that shows a calendar. Leaving the
> shortcode until C4.4 would have meant shipping the script unverified and
> taking it on trust, which is how the C3.4 form handler defect happened. The
> block, which is the larger half of C4.4, stays where it is.
>
> **The new month is fetched from the URL the link points at**, not from a
> fragment endpoint. A fragment endpoint would be faster and would be a second
> render path — and two paths that were meant to agree and quietly stopped is
> the failure this project has now had twice. Fetching the page the link would
> have loaded makes them the same path by definition.
>
> **The live region is the opposite case to the form's error summary**, and the
> difference is worth stating because getting it the wrong way round is silent.
> A region that already holds its text when the page parses announces nothing —
> there is no change — which is why the form moves focus instead. A region that
> is empty at parse and written into later *is* announced, which is right here,
> because focus should stay on the button somebody is stepping through months
> with.
>
> **C4.4 found two things that had nothing to do with the calendar.**
>
> **Blocks were offered whatever their module was doing.** The renderers refuse
> to output anything without their module, so the registration block could be
> inserted on a site with registration off, showed nothing in the editor, saved
> nothing to the page and explained none of it — which reads as a broken block
> rather than as a switched-off feature. Blocks belonging to a module are now
> not registered at all when it is off. Confirmed against a real install:
> switching the calendar off removes its block and leaves the core ones alone.
>
> **`test_shipped_files_are_guarded` had never seen the build output.** `build/`
> is gitignored and produced by `npm run build`, and CI builds in a separate job
> from the one that runs the unit suite — so the first person to build and then
> run the tests locally hit a failure that had been latent since blocks were
> added. The generated `index.asset.php` files contain `return array( … )` and
> nothing else, so they are exempted, and a second test now fails if anything
> other than a generated manifest ever appears in there. The CI check that every
> block was built was also still naming three blocks.
>
> **A mobile layout mistake caught before it shipped.** The first version hid
> the event lists below 600px and left a dot behind — a common calendar pattern,
> and wrong here, because the titles are links: clipping them visually leaves
> them in the tab order while invisible, so keyboard focus would have vanished
> into nothing. Removing them outright would have taken them from screen readers
> too. The cells grow instead.

---

## Stage 5 — Communication

**Goal:** make email reliable before anything else starts sending more of it.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C5.1** | `qevm_email_queue` + cron worker + batching + retry + per-recipient status | L | `wp_mail()` is synchronous; 500 recipients in one request is a timeout |
| **C5.2** | Route existing confirmation and notification mail through the queue | M | Nearly lost a rule the tests caught — see below |
| **C5.3** | Email templates — editable subject and body, placeholders, HTML option | L | Replaces the plain-text builders left deliberately simple. Templates override rather than replace — see below |
| **C5.4** | Email all attendees, through the queue | M | Broadcasts needed their own queue context, and found a guard that had never guarded anything — see below |

**Gate:** 500 recipients send without a timeout, with a per-recipient record of what
was sent and what failed · a failed send is retried and visible, not silent.

**Gate run: passed.** Against a real WordPress and a real MySQL, on an event with 500
confirmed registrations and a mailer set to refuse three specific addresses:

| | |
| --- | --- |
| Queueing 500 | 0.21s, 1008 queries, nothing sent inside the request |
| Draining | 497 sent, 3 failed, nobody sent to twice |
| Per-recipient record | 500 rows, 500 distinct bodies, every sent row carrying its own `sent_at` |
| First failure | still `pending`, 1 attempt, `wp_mail() returned false.`, retry 5 minutes out |
| After the last attempt | all 3 `failed` with 3 attempts, listed by address on the screen |

> **The first budget probe proved nothing, and passed.** "500 recipients send without a
> timeout" rests on the worker stopping when its budget is gone and leaving the rest for
> the next tick. With a `pre_wp_mail` filter that returns instantly, a run drains 500
> messages in 0.14s and the budget is never reached — so the check went green having
> never exercised the thing it was named after. The same shape as the empty calendar in
> Stage 4 and the fixture that asked no questions in Stage 3.
>
> Rewritten with a mailer that sleeps 0.1s per send, so 200 messages is 10s of work
> against a 3s budget. It then showed the run stopping at 29 sent in 3.09s with 160 left
> queued, and a second run continuing rather than repeating.
>
> **Even that needed a second attempt.** The first version used a 2s budget, which with
> a 20-message batch at 0.1s a send lands *exactly* on a batch boundary — where the
> guarded and unguarded versions stop at the same moment. It passed with the inner
> deadline check deleted. Moved to a 3s budget so the deadline falls mid-batch, and the
> difference is now plain: **3.09s with the check, 4.25s without**, a 42% overrun on a
> job that runs inside somebody's page load. Both `Worker::run()` deadline checks are
> load-bearing and only the inner one is hard to see.

One number recorded rather than fixed: queueing 500 messages costs **1008 queries**,
because `Queue::table_exists()` runs a `SHOW TABLES` per call and `Queue::add()` calls it
every time. `Repository::table_exists()` memoises its positive answer for exactly this
reason. Not changed here — the memo would make the "queue table is missing" test model
something that cannot happen in production, a table vanishing mid-request — so it goes to
the Stage 10 performance pass with the measurement attached.

> **C5.3 — templates override, they do not replace.** A template that has never
> been edited is not stored at all, and the built-in wording is what gets sent.
> That is what lets the wording improve in a later release for every site that
> never touched it, while leaving alone every site that did. Clearing a template
> puts the built-in wording back rather than sending an empty message, and a
> template missing either a subject or a body counts as not written.
>
> **The escaping rule is the whole of the renderer.** A template is authored by
> somebody with the capability to edit settings, so its own markup is trusted —
> that is the point of offering HTML at all. The values dropped into it are not:
> an attendee's name comes from a public form. So the template is left alone and
> every value is escaped on its way in, according to the format: `esc_html()`
> into HTML, tags stripped for plain text, because `&lt;b&gt;Priya&lt;/b&gt;` in
> a text email is worse than `Priya`. Getting this backwards is silent — the
> email looks right in every test written with an ordinary name in it — so it is
> verified by removing the escape and watching the injection test fail.
>
> The subject is always plain whatever the body is. Mail clients do not render
> markup there, so escaping it puts the entities themselves in somebody's inbox.
>
> **C5.2 nearly lost the rule that a waiting list place gets no calendar file.**
>
> Attachments cannot travel on a queue row the way they travelled on a message:
> `wp_mail()` takes file paths, the calendar exists only as a string, and it is
> attached through a one-shot `phpmailer_init` listener that cannot survive
> being deferred. So attachments are produced at send time instead, from the
> event the row points at — which is also the better answer, because a calendar
> entry stored an hour earlier may name a time the event has since moved away
> from, and that one goes into somebody's diary rather than merely reading
> oddly.
>
> What that lost was the distinction between a confirmed place and a waitlisted
> one. It had been carried by an `ics` key on the message — empty for a waiting
> list — and once the attachment was decided at send time from the template
> name, both cases looked identical and a provisional place started receiving a
> calendar entry for a seat it did not have. The existing attachment tests
> failed and said so. The two are now separate templates, and a test asserts the
> distinction on the queue row itself so the next person to touch the filter can
> see what the template name is carrying.
>
> Three tests also had to learn to drain the queue before asserting, which is
> them being right rather than them being in the way: mail no longer leaves
> during the request that triggered it, and a test that still observed the send
> there was testing something that no longer happens.
>
> **C5.4 — a broadcast needs its own queue context.** The obvious thing was to
> queue a message to attendees against `event` and the event's id, which is
> where the confirmations already sit. Then withdrawing a broadcast — the
> button that exists because somebody spots the typo twenty seconds after
> pressing send — would also cancel every unsent booking confirmation for that
> event, silently, as a side effect of fixing a sentence. Broadcasts go on as
> `broadcast` with the same id instead, and a test asserts the confirmations
> survive a withdrawal. Sharing the context makes it fail.
>
> **The audience is chosen, never inherited.** The attendee screen above the
> compose box has a search box and a status filter. Reading the audience from
> those would mean an organiser who searched for one name and then wrote a
> message emailed one person, with nothing on screen saying so. The audience is
> a field in the form and nothing else feeds it — and the number on the button
> comes from the same clause the send uses, so it is a fact rather than an
> estimate.
>
> **Recipients are people, not bookings.** Distinct addresses, folded to lower
> case rather than trusting the column collation, so somebody who booked twice
> hears once. Cancelled bookings are in no audience at all: that address was
> given to arrange a place that no longer exists.
>
> **A guard that had never guarded anything.** `Worker::schedule_soon()` fires
> once per message queued, and its guard tested `wp_next_scheduled( HOOK .
> '_soon' )` — a hook name nothing ever schedules, so it was always false. Core's
> own duplicate check inside `wp_schedule_single_event()` meant this produced no
> visible symptom, which is why it survived C5.1 and C5.2: with two messages per
> booking there was nothing to see. A broadcast queues five hundred at once, and
> five hundred round trips through the cron option is where an invisible bug
> becomes a slow one. The guard now tests the real hook and returns when a run
> is already due within the minute.
>
> **A test send is in the chunk and was not in its definition.** Sending is the
> only action in this admin that cannot be undone, and a test to your own
> address is the only way to find out that `{attendee_nmae}` is a typo before
> four hundred people read it. It goes through the queue like everything else,
> under its own context so it neither shows up in the delivery counts nor is
> swept up by withdraw.

---

## Stage 6 — Recurring events

**Goal:** the largest correctness problem in the release.
**Note:** C6.1 is a document, not code, and it is not optional. Recurrence edit
semantics written during implementation are recurrence edit semantics done wrong.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C6.1** | **Write the spec**: "this occurrence" / "this and following" / "all" — every case, including registrations already attached | M | **Done** — [docs/recurrence.md](recurrence.md) + [ADR-0015](adr/0015-recurrence-identity-and-overrides.md). Found a latent bug in the reconciler and a schema addition; see below |
| **C6.2** | Recurrence rule domain — parsing, validation, `series_uuid` | L | **Done.** RRULE as the stored form; `recurrence_id` column and the version bump in the same change, per ADR-0015 |
| **C6.3** | Occurrence generation with a bounded horizon + exclusion dates | L | **Done.** Generated in the event's own timezone, so 18:00 stays 18:00 across DST. Recurrence became a module here; see below |
| **C6.4** | Edit semantics implementation + `is_exception` handling | XL | **Split, as the definition allowed for.** See the three rows below |
| **C6.4a** | Reconciler identity: match on `recurrence_id`, preserve exceptions, cancel-not-delete for booked dates | L | **Done.** Fixes the defect C6.1 found |
| **C6.4b** | Single-occurrence edits — move one date, call one off | M | **Done.** Plus reinstate and restore, which are the undo — see below |
| **C6.4c** | "This and following": split the series, re-point occurrences, keep both tables agreeing | L | |
| **C6.5** | Recurrence admin UI | L | |
| **C6.6** | Attach a booking to an occurrence — pick a date on the form, count capacity per date | L | Surfaced by C6.4a. Without it, `occurrence_id` stays `0` and capacity is per series, contradicting docs/recurrence.md §6 |

> **C6.1 — the spec's own definition understated it.** "Every case, including
> registrations already attached" is the right list of *situations*, and the hard
> question turned out to be one it does not name: **what identifies an occurrence.**
>
> `OccurrenceRepository::replace_for_event()` reconciles by `start_utc`, with a comment
> saying the start "is what identifies a date within an event". That is true for a
> one-off event and false the moment a single occurrence can be moved, because moving it
> changes the field being used to recognise it. A moved occurrence reads as two facts —
> a date the event no longer has, and a new one — so it is deleted and reinserted, and
> the organiser's override disappears on the next unrelated save of the event.
>
> **It cannot be seen today.** `registrations.occurrence_id` and
> `attendees.occurrence_id` have existed since C1.5 and C1.6 and are still written as `0`
> on every insert, so nothing points at an occurrence row and destroying one is
> invisible. Stage 6 is where those columns start carrying values — the identity bug and
> the feature that makes identity matter arrive together.
>
> Every check in the gate below passes with the bug present, because each acts on an
> occurrence and then looks at it immediately, which is exactly the window in which it
> cannot be seen. The gate gains "an exception survives an unrelated save".
>
> The fix is a `recurrence_id` column holding the slot the rule generated, matched on
> instead of the start time — RFC 5545's `RECURRENCE-ID`, borrowed by name. It lands in
> C6.2 with a `QEVM_DB_VERSION` bump in the same change, which C5.1 has just demonstrated
> is easy to forget.
>
> **C6.2 — the rule is stored as an RRULE string**, not a serialised array. It is legible
> in the database, so a support question can be answered by looking; it is what an `.ics`
> export has to emit, so that export becomes a copy rather than a translation; and it is
> what every other calendar already speaks, so importing a series is parsing rather than
> mapping. The cost is that a stored value can be malformed in ways an array cannot, which
> is why parsing returns null rather than a partly-filled object and why validation is a
> separate step: "can I read this" and "should this be allowed to generate dates" are
> different questions, and `FREQ=WEEKLY;BYDAY=2TU` answers yes to the first and no to the
> second.
>
> Reading is deliberately strict. One unreadable weekday fails the whole rule rather than
> being dropped, because `BYDAY=MO,XX,FR` quietly becoming Mondays and Fridays is a rule
> that parses, validates and generates the wrong dates with nothing anywhere saying so.
>
> **Two more decisions the definition left open**, both in ADR-0015 rather than settled
> mid-implementation: a per-occurrence edit changes *when* a date happens or *whether* it
> happens and nothing else, because there is nowhere to put a per-occurrence title; and a
> split re-points occurrence rows rather than regenerating them, because regenerating
> gives every date from the split onward a new row id and orphans every registration
> attached to it in one statement. A split also has to rewrite `registrations.event_id`,
> which is the kind of two-table disagreement neither table can show on its own — so
> that is in the gate too.

> **C6.3 — recurrence had to become a module, and the definition never said so.**
> [ADR-0009](adr/0009-module-architecture.md) makes every feature a module that is off by
> default, and the roadmap says in as many words that somebody publishing a list of
> meetups should not be handed recurrence rules. C6.3 is the first chunk that registers
> hooks and changes what a save does, so it is where the module had to exist.
>
> **The gate is at the generator, not at the event's meta.** An event can carry a rule
> from a previous life — the module was on and has been switched off, or the rule arrived
> through the REST API — so "does this event have a rule" is the wrong question.
> `OccurrenceSync::build_recurring()` is the one place every generation passes through.
> This is the same mistake C3.4 made with the registration form, where the gate was on the
> per-event meta and the form rendered with the module off.
>
> **PHP's DST behaviour was measured, and the C6.1 spec was half wrong about it.** The
> spec said PHP's answers were neither of the two the plugin wants. In fact for an
> ambiguous time PHP already picks the earlier of the two, which is what is wanted; only
> the non-existent time differs, where PHP gives `03:30` and the spec calls for `03:00`.
> So one rule is implemented and one is inherited — and the inherited one is pinned by a
> test that asserts PHP's own behaviour as well as the plugin's, because inheriting
> behaviour is only safe if you find out when it changes.
>
> **Two of my own tests were wrong before the code was.** One asserted that consecutive
> weekly occurrences are 604800 seconds apart in UTC; across a clock change they are
> 601200, which is the entire point of generating in wall-clock time. The other expected
> `COUNT=3` on a yearly rule to produce three dates when only two are inside a two-year
> horizon. Both were fixed by making the assertion say what should happen rather than by
> loosening it — the first now steps local dates, and the second asserts the third date
> appears once the horizon reaches it, which is a better test than the one I set out to
> write.
>
> **The horizon cursor is a position, not an event id.** An id cursor needs a
> "WHERE ID > n" that `WP_Query` cannot express, and filtering ids in PHP after the query
> has applied its `LIMIT` leaves the first batch filtered down to nothing and the walk
> never advancing past it. Caught by reading the code back before writing the test.

> **C6.4 split into three, which its own definition allowed for.** "All" scope needed
> almost no new code and is not a fourth: changing a rule already regenerates, and
> changing a title already touches nothing, because those fields live on the post every
> occurrence reads from.
>
> **C6.4a closed the defect C6.1 predicted, and the sabotage confirmed the prediction
> exactly.** Reverting the reconciler to `start_utc` matching fails
> `test_a_moved_date_survives_an_unrelated_save` and nothing else about the identity
> change; letting the rule overwrite an exception, or protecting nothing, fails five tests
> between them.
>
> **`is_exception` now has a precise meaning:** the rule no longer owns this row's times or
> status. It still owns everything else — which series the row belongs to, its timezone,
> whether it is all day — so a moved date still follows the series in every respect except
> the one somebody overrode.
>
> **Booked dates are protected through a filter, not a query.** `OccurrenceRepository` must
> not know what a registration is, so it asks `qevm_occurrence_is_protected` and the
> registration module answers. With registration switched off nothing answers, which is
> correct rather than a gap: there are no bookings to protect. That is the dependency
> direction [ADR-0009](adr/0009-module-architecture.md) requires, and there is a test that
> the answer really is "no" with the module off rather than the filter merely being absent.
>
> **C6.4b added two operations the definition did not name, because "move" and "call off"
> without an undo is a trap.** `restore()` hands a date back to its rule, and `reinstate()`
> puts a called-off date back on. Both are small, and the mechanism is the part worth
> knowing: **nothing stores an "original" to restore from.** Clearing `is_exception` gives
> the rule ownership of the row again and the regeneration rewrites it from the slot that
> has been on the row since it was generated. That falls straight out of ADR-0015 and is
> the clearest evidence the identity model is the right one.
>
> `reinstate()` decides between the two. A date that was only ever cancelled goes back to
> the rule completely — leaving it flagged as an exception would freeze it against every
> future rule change for no reason. A date that was moved *and then* cancelled keeps its
> moved time, because that time is still what the organiser asked for.
>
> Restoring a date the rule no longer produces removes it, and that is the honest answer
> rather than an oversight: a date the series does not have is not one the series can hold
> on somebody's behalf. If it has bookings on it, C6.4a's protection cancels it instead.
>
> **Editing a date sends nothing.** Asserted rather than assumed — the test hooks
> `pre_wp_mail` and also checks the queue is empty, because since C5.2 mail leaves through
> the queue and a test watching only `wp_mail()` would no longer see it.
>
> **A gap this chunk surfaced and did not close.** `registrations.occurrence_id` is still
> written as `0` by every booking, because nothing asks a visitor *which date of a series*
> they are booking — that form does not exist. So docs/recurrence.md §6's "capacity is per
> occurrence" is not yet true in code: a booking on a recurring event counts against the
> series as a whole. The reconciler's protection is real and tested regardless of how the
> row got its `occurrence_id`, but the contradiction is live and needs its own chunk rather
> than being folded quietly into the recurrence UI. Added as **C6.6** below.

**Gate:** a weekly series for 52 weeks generates 52 occurrences · editing one leaves 51
untouched and marks it `is_exception` · "this and following" splits the series
correctly and both halves share the `series_uuid` · registrations survive an edit to
their occurrence · generation is bounded.

**Added by C6.1:** an exception survives an unrelated save of the event ·
`registrations.event_id` agrees with the occurrence's `event_id` after a split · a date
with registrations on it is cancelled rather than deleted when the rule stops generating
it · 18:00 stays 18:00 across a real DST transition in a zone that has one.

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
