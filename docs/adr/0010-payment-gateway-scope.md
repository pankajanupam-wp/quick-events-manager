# ADR-0010 — Gateway interface + Stripe + WooCommerce only

**Status:** accepted · 2026-08-10

## Context

The roadmap originally listed Stripe, Razorpay and PayPal, plus a WooCommerce
integration. Payments is the largest and least forgiving item in the plan, and the
only one where being wrong costs users money rather than annoyance.

What "payments" actually obligates a maintainer to, permanently:

- Refunds and **partial** refunds; chargebacks and disputes
- Failed, abandoned and duplicated payments
- **Seat holds with expiry** — without them an abandoned checkout permanently eats a
  seat, which is the step most implementations forget
- **Idempotency on webhook replay** — gateways deliver the same event more than once
  by design, and a naive handler creates two registrations for one payment
- Multi-currency, rounding, tax
- Reconciliation and an audit trail a business will trust
- PCI-adjacent expectations
- Three gateway APIs changing on their own schedules, forever

This project has one unpaid maintainer, and one of its stated goals is skill growth.
Those two facts pull in opposite directions: payments is simultaneously the most
educational thing on the roadmap and the most dangerous thing to own badly.

## Decision

**One gateway, built properly, plus a bridge to a system that already solved this.**

1. **`Commerce\Gateway` interface**, documented as public API. Razorpay, PayPal and
   anything else can be implemented against it later — by this project or by anyone
   else, as a separate plugin, with their own maintainer.
2. **Stripe** as the single first-party implementation. One gateway done thoroughly
   teaches everything the hard parts have to teach: PaymentIntent flow, webhook
   signature verification, idempotency, seat holds, refund state machines,
   reconciliation.
3. **WooCommerce bridge** — a ticket type becomes a product, and Woo owns checkout,
   orders, refunds, coupons, tax and every gateway it already supports. A few hundred
   lines inherit a decade of hardening.
4. Woo and the built-in gateway are **mutually exclusive** on a given site.
5. **No card details ever touch this plugin.** Every gateway takes that off-site.

Schema is designed for all of this now ([ADR-0006](0006-money-and-immutability.md)),
so the tables do not change when a second gateway arrives. No payment code is written
before stage 9.

## Alternatives considered

**All three gateways.** Most complete, and genuinely instructive about how differently
three providers model the same transaction. Rejected: three sets of webhooks, three
sandbox environments, three refund semantics and three API-churn schedules, on top of
a release that already contains nine other features. It is the single largest block of
work in the plan and cutting it by two thirds is the largest available risk reduction.

**Interface plus WooCommerce, no first-party gateway.** Smallest scope; the money
handling never becomes ours. Rejected because it skips the part of the problem most
worth learning, and because requiring WooCommerce for a ₹0-cost ticket sale is a heavy
ask for a small site.

**No payments at all.** Defensible for a plugin this size, and it was the original
recommendation when the goal was read as market positioning. Reversed once the goal
was stated as community use and skill growth: the maintenance-liability argument is
weaker when the alternative is not learning the thing.

## Consequences

**Good.** The riskiest item shrinks by roughly two thirds without losing the
capability. Sites already running WooCommerce get the better path. The interface means
declining to build Razorpay is a pointer rather than a refusal.

**Bad.** Users wanting Razorpay or PayPal directly must use WooCommerce or wait for a
community implementation. For an Indian audience Razorpay is a real gap, and this
should be revisited — as a new ADR — once Stripe has been stable for a quarter.

**Bad.** A `Gateway` interface with one implementation is an abstraction justified by
a future that may not arrive. Accepted deliberately: the interface is small, and the
WooCommerce bridge is a genuine second implementation, so it is exercised by two
callers from the start rather than one.

**Gate.** Stage 9 does not begin until stages 1–8 are complete and stable. If it
stalls, the WooCommerce bridge alone is a shippable outcome.
