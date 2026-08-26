# ADR-0004 — Attendees are rows, not registrations

**Status:** accepted · 2026-08-10

## Context

The rewrite treats one registration row as one attendee. `quantity` records how many
places were booked, but only the booker's name and email are stored.

That model breaks on the first person who books for someone else, which in practice
is the first week of real use. "Book 3 places for my team" produces one row with one
name and the number 3.

Every downstream feature needs a person, not a booking:

- **Check-in** marks a person through a door. With one row per booking, three
  colleagues arriving separately cannot be marked individually.
- **QR codes** encode a ticket, and a ticket admits one person.
- **Ticket types** are chosen per person — two standard and one concession on the
  same booking is ordinary.
- **Custom fields** ask per person: dietary requirements, accessibility needs, t-shirt
  size. Attached to a booking they are meaningless for guests 2 and 3.
- **Attendance reports** count people.

## Decision

`{prefix}qevm_attendees` — one row per place, always, including when quantity is 1.
Schema in [database.md](../database.md#qevm_attendees--stage-1).

- A registration is the **booking**: who arranged it, who pays, one status, one code.
- An attendee is the **person**: a name, a unique `ticket_code`, a `position`, and
  later a ticket type and check-in records.
- Registration columns are renamed `booker_name`, `booker_email`, `booker_phone`.
  Once attendees exist, an unqualified `name` on a registration is ambiguous.
- The registration form collects a name per guest when quantity exceeds one.
- `name` may be empty when a guest's name was not collected. That is a known state,
  not an error.
- `ticket_type_id` is reserved on the attendee row now, used when ticketing lands.

Capacity continues to count **places** via the registration's `quantity`, using the
existing insert-then-rank routine. Attendee rows are created after the registration
resolves, so the concurrency guarantee is untouched.

## Alternatives considered

**Schema now, per-guest form later.** Create attendee rows but keep collecting only
the booker's details, backfilling names when the UI arrives. Cheaper in this stage
and gets the irreversible half done. Rejected by the maintainer in favour of doing
both together, so that group booking is genuinely usable at release rather than
structurally possible but unavailable.

**Store guests as JSON on the registration.** No new table. Rejected: check-in needs
to mark an individual, QR codes need a unique per-person identifier, and reports need
to count and filter people. JSON cannot be indexed on MySQL 5.7, which WordPress
still supports.

**Keep one row per person and drop the registration concept.** Simpler, but loses the
booking: who paid, one confirmation email, one cancellation, one order. Cancelling a
three-person booking would become three unrelated operations.

## Consequences

**Good.** Group booking works. Check-in, QR codes, per-person ticket types and
per-person custom fields all become possible with no further schema change. Reports
count people.

**Bad.** More rows — roughly 1.5× registrations at typical group sizes. More
complexity in the form, which is also the screen with the highest accessibility bar.
Cancellation must cascade to attendee rows.

**Timing.** Like [ADR-0003](0003-occurrence-table.md), free now and a migration on
every install later. Both land in stage 1, before anything publishes.
