# ADR-0001 — Prefix `qevm`, namespace `QuickEventsManager\`

**Status:** accepted · 2026-08-10

## Context

The rewrite used the prefix `qem` throughout: namespace `QEM\`, constants `QEM_*`,
options `qem_*`, hooks `qem_*`, shortcodes `qem_event_list`, blocks `qem/*`, REST
`qem/v1`, table `wp_qem_registrations`.

Investigating whether that prefix was already in use turned up a direct conflict.

**Quick Event Manager** (`quick-event-manager`) — note the singular *Event* — is an
actively maintained WordPress.org plugin with 1,000+ active installs, currently at
v9.17, with a Freemius-backed paid tier. Its name differs from ours by one letter and
it solves the same problem. Downloading and inspecting its source showed it already
occupies `qem`:

| Symbol type | Count | Examples |
| --- | ---: | --- |
| Global functions `qem_*` | 142 | `qem_add_attendee`, `qem_add_role_caps`, `qem_ajax_calendar` |
| Options `qem_*` | 19 | `qem_version`, `qem_register`, `qem_style`, `qem_display` |
| Hooks `qem_*` | 13 | **`qem_event_register`**, `qem_registration_email_message` |
| Constants | — | `QEMBP_ADMIN_ASSETS`, `QEMFW_DEBUG` |
| Shortcodes | 11 | `[qem]`, `[qemcalendar]`, `[qemregistration]` |
| Post type | 1 | `event` |

Nothing collides *fatally* today. Our PHP is namespaced, which is what protects us
from their 142 global functions — that decision has already paid for itself. Their
post type is `event` and ours is `qem_event`. Their shortcodes have no underscore.

But options, hooks and constants share a flat global namespace that neither project
controls, and the failure mode there is **silent**: a hook fires the wrong callbacks,
an option reads another plugin's value. They already own `qem_event_register`, and we
intend to publish a family of `qem_event_*` hooks.

The WordPress Plugin Handbook recommends a prefix of at least four, preferably five,
characters. `qem` is three.

## Decision

The prefix is **`qevm`** / **`QEVM_`**. The PHP namespace root is
**`QuickEventsManager\`**, mapped PSR-4 to `src/`.

Full matrix in [engineering-standards.md §1](../engineering-standards.md#the-naming-matrix).

The text domain remains `quick-events-manager`, unabbreviated, because WordPress.org
generates translation packages from the slug.

`qevm` returns no results in the WordPress.org plugin directory.

## Alternatives considered

**Keep `qem`.** Cheapest. Rejected because the shared namespace is a permanent risk
neither party controls, the failure mode is silent rather than fatal, and the cost of
changing rises from "one find-and-replace" to "impossible" the day 26.0 publishes.

**`quickevents_` / `QUICKEVENTS_`.** Uniqueness guaranteed by the slug we own rather
than hoped for. Rejected on verbosity: `quickevents_registration_created` and
`wp_quickevents_order_items` are names we would read tens of thousands of times.
`qevm` clears the handbook's minimum and is verified unoccupied, which is enough.

**`PankajAnupam\QuickEventsManager\` vendor namespace.** The handbook's suggested
shape, and genuinely collision-proof. Rejected because this is a community project
that may gain co-maintainers or change hands; coupling every class name to one
individual is a liability, and the WordPress.org slug already guarantees uniqueness.

## Consequences

**Good.** No shared global namespace with a 1,000-install neighbour. The prefix meets
handbook guidance. The namespace reads well and is not tied to a person.

**Bad.** A mechanical rename across roughly 8,800 lines and every document. Roughly an
hour, plus a careful review that no string was missed — particularly in `block.json`
files, CSS class names, and the test bootstrap, which a naive search-and-replace over
`.php` alone would skip.

**Accepted risk.** Users will still confuse *Quick Events Manager* with *Quick Event
Manager*. Nothing in our control fixes that; the README should name the other plugin
plainly so anyone who lands in the wrong place can find the right one.

**Timing.** This is free now and impossible later. The rewrite has never been
published, so there is nothing in the field to migrate — no option to rename, no hook
another plugin depends on, no table to alter. It must land before 26.0 ships.
