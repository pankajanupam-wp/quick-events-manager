# ADR-0006 — Integer minor units; order items are immutable snapshots

**Status:** accepted · 2026-08-10

## Context

Payments are planned for stage 9. Two mistakes in money handling are common, easy to
make, and effectively unfixable once real transactions exist.

**Floating point.** `0.1 + 0.2 !== 0.3` in binary floating point. Storing prices as
`float` or `decimal(10,2)` and summing them across an order, then a refund, then a
report, accumulates error. A one-paisa discrepancy in a reconciliation report is a
support conversation that costs more than the transaction.

**Recomputing history from current configuration.** A ticket priced ₹499 in March and
₹999 in September. If last March's revenue report joins orders to `ticket_types` and
reads the current price, March's revenue silently doubles. The same problem affects
a ticket that was renamed, or deleted.

Both are decided by the schema, not by the code that reads it, which is why they
belong in an ADR written before the tables exist.

## Decision

**Money is a `bigint` in minor units plus a `char(3)` ISO-4217 currency code.**
`₹499.50` is `49950` with `INR`. Never a float. Never a decimal string. Formatting for
display happens once, at the point of output, in a single value object.

**Order items are immutable snapshots.** `qevm_order_items` stores, written once and
never updated:

| Column | Holds |
| --- | --- |
| `name_snapshot` | What the ticket was called when it was bought |
| `event_snapshot` | What the event was called when it was bought |
| `unit_price_minor` | What it cost when it was bought |
| `quantity`, `tax_minor`, `total_minor` | The arithmetic as it stood |

`ticket_type_id` and `event_id` are kept alongside as references for navigation, but
**no financial figure is ever read through them**. A report that needs last year's
revenue reads last year's rows.

Ticket types are never hard-deleted once referenced; they are archived, so the
reference stays resolvable.

**Charges and refunds share one signed ledger** (`qevm_transactions`, charges
positive, refunds negative), so an order's net position is a single `SUM` and cannot
disagree with itself.

`readonly` properties on the value objects enforce this at the language level rather
than by convention — one of the two reasons for PHP 8.1
([ADR-0002](0002-php-and-wordpress-versions.md)).

## Alternatives considered

**`decimal(10,2)` columns.** Exact in MySQL, and the conventional answer. Rejected
because PHP has no decimal type: every value read out becomes a float somewhere in
the round trip, and the guarantee is lost precisely where the arithmetic happens.
Integers survive the boundary intact.

**Store only `ticket_type_id` and join for price.** Normalised and smaller. Rejected:
it makes historical reports depend on mutable configuration, which is the exact bug
described above.

**Separate `payments` and `refunds` tables.** Conventional. Rejected in favour of one
signed ledger — two tables can disagree, one cannot, and reconciliation is simpler.

## Consequences

**Good.** Financial arithmetic is exact. Historical reports stay correct across price
changes, renames and deletions. Multi-currency works without restructuring, because
currency travels with the amount. Partial refunds need no new concept.

**Bad.** Every read and write crosses a formatting boundary; a raw `49950` leaking
into a template is a visible bug. Mitigated by a `Money` value object that is the only
thing allowed to format, and by never exposing `*_minor` columns through REST without
conversion.

**Bad.** Snapshot columns duplicate data. This is deliberate denormalisation with a
clear justification, which is the only kind permitted
([engineering-standards.md §8](../engineering-standards.md#8-database-rules)).

**Scope note.** This decision is made now, before stage 9, because it constrains the
schema. No payment code is written until stage 9.
