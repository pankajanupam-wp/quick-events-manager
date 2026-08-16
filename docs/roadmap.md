# Build plan

**26.0 is the only release.** Everything below ships in it, or it does not ship.

Nothing is published to WordPress.org until the whole list is done. The 2012 plugin has been sitting there for fourteen years — one more month makes no difference, and a complete first impression is worth more than an early partial one.

That means this document is a build order, not a release schedule. Versions after 26.0 will exist eventually, but they are not planned here.

## Why still modules, then

Every feature is still a `QuickEventsManager\Modules\Module` that is off by default, even though they all arrive together.

The reason is no longer release staging. It is that somebody installing this to put a list of meetups on a page should not be handed ticketing, payment gateways and recurrence rules. They switch on what they need under **Events → Features**, and a module that is off registers no hooks, creates no tables and enqueues no assets.

A plugin this size is only pleasant to use because most of it is switched off.

## Done

- Events: dates, times, per-event timezone, all-day, online events
- Venue and organiser fields, categories, tags
- Event list, single event, search, category filtering, archive ordering
- Add to calendar (`.ics` and Google), `schema.org/Event` markup
- Three dynamic blocks and matching shortcodes
- Free registration: capacity, automatic waiting list, closing date
- Attendees screen: search, status filtering, CSV export
- Confirmation and notification emails
- Read-only REST API, GDPR export and erasure
- The Features screen and module registry
- Migration from 1.0, verified to preserve URLs

## To build

Ordered so each piece rests on the one before it.

### 1. Calendar view

A month grid as its own module. Reads the same query layer the list uses. Needs previous/next navigation that does not reload the page, and has to stay usable on a phone.

### 2. Custom registration fields

**Definitions are built.** Ten field types, required flags, help text, ordering and a sensitive flag, stored as JSON in `_qevm_registration_fields` on each event and edited from a box on the event editor. Keys are minted rather than derived from the label, so renaming a question never orphans the answers already given to it.

**Answers are built too.** They are stored in `qevm_attendee_meta`, one row per answer and one row per choice on a choose-any question, so counting how many people need step-free access is a `COUNT` rather than a search through serialised text. The questions render on the form, and every value is checked against its own definition before it is written — a choice question accepts only the choices it offered.

The questions are asked once, of the person booking. Asking each guest is a change of form rather than of schema, since the answers already hang off the attendee row.

Still to come: feeding the answers to the CSV export and the attendee screen, with the sensitive ones left out by default.

### 3. Email templates

Editable subject and body with placeholders, and an HTML option. Replaces the plain-text builders in `Registration\Emails`, which were deliberately left simple pending this.

### 4. Reusable venues and organisers

**Venues are built.** `qevm_venue` is a post type behind the Level 2 **Reusable venues** module, and `_qevm_venue_id` — reserved in 26.0 and read by nothing until now — is what an event points at. Every event still keeps its own copy of the address, so switching the module off, deleting a venue or trashing one leaves the event rendering its location as before; [ADR-0014](adr/0014-venue-records-with-flat-fallback.md) explains why that duplication is deliberate rather than an oversight.

The addresses already on existing events are promoted too, once, in a batched sweep queued when the module is first enabled. Events are matched on a fingerprint of the whole normalised address rather than on the name, so forty events at one address become one venue — and two "Town Hall"s in different towns stay two, because a wrong merge is not something the site owner can undo.

Organisers work the same way, as a separate module: `qevm_organizer`, `_qevm_organizer_id`, the same resolution, the same fallback and the same sweep. Separate rather than bundled because a site can easily want one and not the other.

The shared machinery lives in `includes/Records/`, so the matching rule that decides what merges exists once rather than twice.

### 5. Ticketing

Multiple ticket types per event, each with its own quantity, capacity and sale window. Early-bird pricing. The registrations table gains a ticket reference; capacity moves from per-event to per-type without losing the insert-then-rank guarantee.

### 6. QR codes and check-in

QR on confirmations, a mobile-friendly check-in screen, manual search, check-in timestamps, and a staff role that can check people in without being able to edit events. `manage_qevm_checkins` and `manage_qevm_registrations` are both granted from 26.0 for exactly this, kept apart from the post type's own capabilities so the door role is a different list rather than a smaller one. Granting a capability after release means a migration walking every role on every site; granting it before costs nothing.

### 7. Payments

The largest and riskiest piece, and the one to talk through before writing.

A gateway interface first, then Stripe, Razorpay and PayPal against it. Money means refunds, partial refunds, failed and abandoned payments, currency handling, and an audit trail — none of which the current schema has. It also means the plugin starts handling things where being wrong is expensive rather than annoying.

Nothing here stores card details; every gateway hands that off.

### 8. WooCommerce integration

An alternative to the built-in gateways for sites that already run Woo: an event ticket becomes a product, and Woo handles checkout, orders, refunds, coupons and taxes. Optional, and mutually exclusive with the built-in payment module.

### 9. Recurring events

Modelled as a series with a parent and generated occurrences, not as unrelated copies. Editing one occurrence versus the whole series is the hard part, and getting it wrong is what makes recurring events miserable in most calendar software.

## Before release

Independent of the features above:

- `npm run build` wired up and the compiled blocks confirmed present
- Screenshots in `.wordpress-org/`
- `SVN_USERNAME` and `SVN_PASSWORD` secrets on the GitHub repo — their absence already failed a release on a sibling plugin
- An integration test suite against real WordPress and MySQL, covering the custom tables, REST routes and migrations that stubs cannot reach
- A full security and performance pass over everything, not just the last thing built
- `readme.txt` rewritten to describe the finished plugin
- Translation template regenerated

## Not planned, ever

- **A paid tier.** No feature is held back to create one, and none will be.
- **Telemetry or usage tracking.** The plugin sends nothing anywhere.
- **A page builder.** Copy a template into your theme; that is the whole system.
- **Bundled icon fonts or JavaScript frameworks.** Blocks render in PHP and the front end ships one stylesheet.
