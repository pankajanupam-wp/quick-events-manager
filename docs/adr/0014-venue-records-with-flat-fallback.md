# ADR-0014 — Venue records never become the only copy of an address

**Status:** accepted · 2026-08-15

## Context

Until now an event's location was six flat post meta keys — name, address, city,
region, postal code, country — written on the event itself. That is the right shape
for an event that happens somewhere once, and the wrong shape for an organiser running
a monthly meetup who has typed the same address forty times.

Promoting the address to a record is the obvious fix, and it is where this kind of
feature usually goes wrong. The tempting version reads: create a `qevm_venue` post
type, migrate every distinct address into a record, point each event at one with
`_qevm_venue_id`, and delete the flat meta now that it is redundant. That model has one
copy of each address, which looks like the clean answer.

It is not, because venues are an **opt-in module** ([ADR-0009](0009-module-architecture.md))
and modules can be switched off. Under that model, switching venues off takes the post
type away and leaves every event pointing at rows nothing can read. The address does
not become stale — it disappears. So does the location line on the event page, the
`LOCATION:` in the `.ics`, the `Place` in the structured data, the "Where" line in the
confirmation email and the Location column in the admin list. Six render paths go blank
at once, on a site whose owner did nothing worse than tidy their features screen.

The same hole opens two other ways with the module still on: an organiser deletes a
venue that events still point at, or trashes one, and every event held there loses its
address.

## Decision

**The event's flat address meta is authoritative for display, and a venue record is a
convenience that populates it.**

Concretely:

- Choosing a venue on an event copies that venue's address into the event's own meta,
  at save time. `EventVenueBox` saves at priority 20, after the event details box at
  10, so the copy lands on top rather than underneath.
- `Venue::for_event()` is the single point of resolution. It returns the record when
  four things hold — the module is on, the event names a venue, the post exists and is
  a `qevm_venue`, and it is not trashed — and the event's own meta in every other case.
- Nothing ever deletes the flat meta. Not the editor, not module deactivation, and not
  the promotion sweep in C3.1b.
- Choosing a record is optional with the module on. "Address on this event" is a
  permanent supported state, not an unfinished migration.

Venue records store their address under the **same meta keys** an event uses. That is
what lets one value object read either source with no translation table, and it makes
the promotion in C3.1b a copy rather than a mapping. The venue's *name* is its post
title rather than a meta key, because a record with a title and a separate name field
has two places to change it and will eventually disagree with itself.

The post type is registered `public => false` with no archive, no rewrite rules and no
query var. A generated venue page would be a heading and an address — a thin page on
every site that switches the module on, indexed and answering nothing. "Every event at
this venue" is a real feature and deserves a real design rather than falling out of a
default. That choice also removes the one thing a module cannot do cleanly: `activate()`
runs from an admin request where `init` has already fired, so a rewrite slug would have
to be registered by hand and flushed on enable, and flushed again on disable to take the
dead URLs away. Registering no rules removes the problem instead of solving it.

## Consequences

**The address is duplicated, deliberately.** One row per event repeating what a venue
record already says. This is the cost, and it buys a system with no state in which an
event cannot say where it is.

**Editing a venue record does not rewrite the copies on past events.** With the module
on this is invisible, because resolution reads the record. It only surfaces if the
module is later switched off, at which point each event shows the address it had when
it was last saved. That is a defensible answer — "where this event was, as far as we
last knew" — rather than a bug to be engineered away, and engineering it away means
writing to every event whenever anybody corrects a postcode.

**Two writers touch the same six keys**, so their order matters and is pinned by a
constant with the reason attached. A future third writer must be later than both.

**`_qevm_venue_id` is finally read.** It was registered in 26.0 and consumed by nothing
— exposed through REST, sanitised, defaulted, and inert. It now means what its docblock
always claimed.

## Alternatives considered

**Records own the address; flat meta is dropped.** One copy, no duplication, and the
normalised answer. Rejected because the failure modes are silent and total: disable the
module, delete a venue, or trash one, and events render no location at all. A data model
whose correctness depends on a site owner never switching off an optional feature is not
a data model.

**Keep both, and treat the record as authoritative even when the module is off** — read
`_qevm_venue_id` regardless of module state. Rejected because it makes the module
boundary a lie. ADR-0009 says a disabled module registers no post type and runs no code,
and reading its data anyway is exactly the "settings screen that hides features" that
architecture exists to avoid.

**Refresh every event's copy whenever a venue is edited.** Removes the staleness above.
Rejected for this chunk: correcting one postcode would write to every event ever held
there, which is an unbounded write from an ordinary editor save. If it is wanted later
it belongs on the batched sweep C3.1b builds, not inline.

**No records at all; deduplicate with an autocomplete on the flat fields.** Genuinely
tempting, and half the benefit for none of this. Rejected because a record is what
later features need to hang off — capacity per room, a venue photo, a map, "events at
this venue" — and retrofitting one after the fact is the migration this ADR exists to
avoid.
