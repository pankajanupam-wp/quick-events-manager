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
| **C0.4** | **Rename**: `qem`→`qevm`, `QEM\`→`QuickEventsManager\`, `includes/`→`src/`, block sources→`src-js/` | L | [ADR-0001](adr/0001-naming-and-namespace.md). Includes `block.json`, CSS classes, test bootstrap, docs |
| **C0.5** | Enums for existing statuses — `RegistrationStatus`, `ModuleLevel` | S | First use of 8.1; proves the toolchain |

> **C0.4 is the highest-risk mechanical change in the plan.** A naive replace over
> `*.php` misses `block.json` names, CSS class names, script handles, the `.pot`
> header and every document. Grep for `qem` across *all* file types afterwards and
> expect the count to be zero.

**Gate:** 73 unit tests still green · PHPCS and PHPStan clean · no occurrence of
`qem` outside the ADR that explains the rename · plugin activates on a real install
with `WP_DEBUG` on and no notices.

---

## Stage 1 — Schema foundation

**Goal:** every irreversible schema decision, made correctly, while there is nothing
in the field to migrate.
**Why now:** free today, a migration on every installation after 26.0 publishes.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C1.1** | Migration framework — versioned, idempotent, batched, runs on `admin_init` | M | `src/Install/Migrations/`, `docs/migrations.md` |
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

**Gate:** upcoming-events query contains **no `CAST(`** and `EXPLAIN` shows
`type: range` on `qevm_occurrences` · 10,000 events render the archive in under 200ms
of DB time · a 3-place booking creates 1 registration and 3 attendee rows · 8 parallel
submissions to a capacity-1 event produce exactly 1 confirmed · every migration is a
no-op on second run.

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
| **C2.6** | `rtl.css` | XS | About an hour; Arabic, Hebrew and Persian are large WordPress markets |
| **C2.7** | Form accessibility pass + **axe-core in CI** | L | From here, a violation fails the build |

**Gate:** AC-3, AC-4, AC-5 pass · cancelling a 3-place booking frees 3 places and
promotes and emails the next waitlisted person · axe-core clean on archive, single,
form, form-in-error, confirmation.

---

## Stage 3 — Records and fields

**Goal:** promote venues and organisers from flat meta to reusable records, and add
custom questions. Both change data shape, so they precede any UI that depends on them.

| ID | Chunk | Size | Output |
| --- | --- | :-: | --- |
| **C3.1** | `qevm_venue` CPT + migration from flat meta, **flat fields keep working** | L | `_qevm_venue_id` already reserved |
| **C3.2** | `qevm_organizer` CPT + same pattern | M | |
| **C3.3** | Custom field definitions — post meta JSON, admin UI, types, required, ordering | L | Definitions are config; JSON is correct here |
| **C3.4** | `qevm_attendee_meta` + form rendering + validation | M | Answers are reportable; JSON is not |
| **C3.5** | Custom answers in CSV export and attendee screen; health-adjacent fields flagged and excluded by default | M | Dietary and access needs are health-adjacent |
| **C3.6** | Data retention setting + cron sweep, with a filter | M | |

**Gate:** an event created before the venues module existed still renders its address ·
a custom field appears on the form, in the export, and in the privacy export · a
flagged field is absent from CSV unless explicitly included.

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
| 0 · Groundwork | 5 | not started |
| 1 · Schema foundation | 11 | not started |
| 2 · Correctness gaps | 7 | not started |
| 3 · Records and fields | 6 | not started |
| 4 · Calendar | 4 | not started |
| 5 · Communication | 4 | not started |
| 6 · Recurring events | 5 | not started |
| 7 · Ticketing | 4 | not started |
| 8 · Event operations | 6 | not started |
| 9 · Commerce | 7 | not started |
| 10 · Release readiness | 9 | not started |
| | **68** | |

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
