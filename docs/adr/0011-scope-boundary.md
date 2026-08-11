# ADR-0011 — No conference layer in core

**Status:** accepted · 2026-08-10

## Context

Event management shades continuously into conference management. Sessions, tracks,
speakers, sponsors, exhibitors, booths, badges, per-session registration and agenda
builders are all things some organiser genuinely wants, and every one of them is a
plausible next feature.

They are also where focused event plugins stop shipping. Per-session registration in
particular means capacity, waitlists and check-in must all work at two levels
simultaneously, roughly doubling the complexity of three subsystems that are already
the hardest parts of the plugin.

26.0 already contains occurrences, the attendee split, custom fields, a calendar,
recurrence, email templating, ticketing, check-in, Stripe and WooCommerce, built by
one person in spare time.

## Decision

**Quick Events Manager is an event management plugin. It is not a conference
platform.**

Not in core, now or later:

> Sessions · tracks · speakers · sponsors · exhibitors · booths · badges · seat maps ·
> certificates · agenda builders · per-session registration · attendee networking ·
> CRM · marketing automation · analytics dashboards · organisations and departments ·
> SSO / SAML / SCIM · multi-tenancy · approval workflows · mobile apps

This list is published in the README. **A refused feature list is a product asset** —
it keeps the plugin finishable, and it stops a contributor spending a weekend on a
pull request that was never going to merge.

The obligation this creates: **make the alternative real.** Declining to build
something is only legitimate if someone else can. So the plugin must provide, and
document:

- The `qevm_modules` filter, so a third party registers a module through exactly the
  same interface the bundled ones use
- A domain model whose boundaries permit it — sessions would be occurrences with a
  parent, speakers a taxonomy or CPT; nothing in
  [database.md](../database.md) forecloses that
- A documented hook and REST surface, with `docs/extensibility.md` showing a worked
  example of building an add-on module

## Evaluating requests

Published in `CONTRIBUTING.md` so refusals are principled rather than personal:

1. Does it serve the **Event Organizer** or the **Attendee**? (Not Speaker, Sponsor,
   Exhibitor.)
2. Can it be a module that is **off by default**?
3. Can it be done **without a new external dependency**?
4. Would the plugin be **worse for someone who does not use it**? If yes, refuse.
5. Is it **already possible via a hook**? If so, document the hook instead.

Anything failing 1 or 4 is closed `wontfix-by-design` with a link to this ADR.
Kindly, quickly, and consistently.

## Alternatives considered

**Sessions and speakers only**, the two most-requested. Sessions are nearly free
given the occurrence table — child occurrences with a parent id — and speakers are a
taxonomy. Genuinely tempting. Rejected because it does not stop there: sessions
immediately raise per-session capacity, per-session check-in and agenda display, and
each of those raises the next thing. The boundary has to be somewhere defensible, and
"we do events, not conferences" is defensible in a way that "we do sessions but not
tracks" is not.

**Build the full conference layer.** Feature parity with Eventin and The Events
Calendar Pro. Rejected: roughly doubles an already very large release, for a different
buyer, maintained by one person.

**Say nothing and decide case by case.** Rejected: silence reads as "maybe", and
accumulates issues nobody will close.

## Consequences

**Good.** The release is finishable. Every subsystem stays single-level, which keeps
capacity, waitlists and check-in comprehensible. Contributors know where the edge is
before they start.

**Bad.** Real users with real conferences will be told no, and some will leave for a
plugin that says yes. That is the intended trade.

**Bad.** Maintaining an extension surface good enough to justify the refusal is
ongoing work — documented hooks, a worked example, stable contracts. If that surface
is allowed to rot, this ADR becomes a refusal with nothing behind it, and should be
revisited rather than defended.
