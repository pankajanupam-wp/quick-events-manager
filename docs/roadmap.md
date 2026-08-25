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
- Confirmation and notification emails, queued and retried rather than sent inline
- Editable email templates, and one message to everybody registered for an event
- Custom registration questions, with the answers reaching the CSV, the attendee screen and the privacy export
- Reusable venues and organisers, with every event keeping its own copy of the address
- Recurring events: rules, per-date bookings, per-date edits and "this and following"
- Ticket types with their own capacity, price and sale window
- Check-in: QR codes on confirmations, a door screen that works without JavaScript, and three named roles
- Paid tickets through Stripe, with seat holds, webhooks and partial refunds
- Selling through WooCommerce instead, for sites that already have a shop
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

Answers reach the CSV export, the attendee screen and the privacy export. Questions marked sensitive are left out of the ordinary CSV and need a second, explicitly labelled button — a per-export decision rather than a setting somebody ticks once. The attendee screen shows them, because it is behind a capability and the answers are why the question was asked; the privacy export carries them too, since that is the person's own data.

### 3. Email templates

**Built.** Editable subject and body with placeholders and an HTML option, behind the Level 2 **Email templates** module, replacing the plain-text builders in `Registration\Emails` that were deliberately left simple pending this. A template that has never been edited is not stored at all, so the built-in wording keeps improving for every site that never touched it.

The renderer keeps the template's own markup and escapes every value substituted into it, by format — `esc_html()` into HTML, tags stripped for plain text. The template is written by somebody holding a capability; `{attendee_name}` came from a public form.

**Mail is queued rather than sent in the request that triggers it.** `qevm_email_queue` holds a row per recipient with its own status, attempts and error, claimed by an `UPDATE` so two workers cannot take the same message, retried with a backoff and given up on after three attempts. Attachments are produced at send time from the event the row points at, so a delayed confirmation cannot put a stale time in somebody's diary.

**Emailing everybody is built on top of it.** A compose box at the bottom of the attendee screen sends one message to a chosen audience — confirmed places by default, optionally the waiting list, never anybody who cancelled — rendered per recipient so `{attendee_name}` is their name. Recipients are distinct addresses rather than bookings. Five hundred people are five hundred queue rows inserted in one request and sent over the following ticks, with a delivery panel naming whatever failed and a withdraw button for anything not yet gone.

### 4. Reusable venues and organisers

**Venues are built.** `qevm_venue` is a post type behind the Level 2 **Reusable venues** module, and `_qevm_venue_id` — reserved in 26.0 and read by nothing until now — is what an event points at. Every event still keeps its own copy of the address, so switching the module off, deleting a venue or trashing one leaves the event rendering its location as before; [ADR-0014](adr/0014-venue-records-with-flat-fallback.md) explains why that duplication is deliberate rather than an oversight.

The addresses already on existing events are promoted too, once, in a batched sweep queued when the module is first enabled. Events are matched on a fingerprint of the whole normalised address rather than on the name, so forty events at one address become one venue — and two "Town Hall"s in different towns stay two, because a wrong merge is not something the site owner can undo.

Organisers work the same way, as a separate module: `qevm_organizer`, `_qevm_organizer_id`, the same resolution, the same fallback and the same sweep. Separate rather than bundled because a site can easily want one and not the other.

The shared machinery lives in `includes/Records/`, so the matching rule that decides what merges exists once rather than twice.

### 5. Ticketing

**Built.** Multiple ticket types per event behind the Level 3 **Ticketing** module, each with its own capacity, price and sale window, and the registrations table carrying which type a booking is for. Capacity became two limits rather than one, both ranked the same way: the event's is a shared, strictly ordered queue, because somebody who asked for three places must not watch every later single booking go in ahead of them; a ticket type's is a private queue, and a full one is skipped rather than left blocking people waiting for something else.

Early-bird pricing turned out not to be a thing to build. It is a type whose sale window closes early and whose price is lower, which is why neither the schema nor the code contains the words. Prices are typed in whole units and stored in minor ones, rounded rather than truncated — `(int) ( 12.10 * 100 )` is 1209, and a penny a ticket is how money quietly goes wrong.

### 6. QR codes and check-in

**Built.** A QR code per person on the confirmation, a door screen designed for one hand and one thumb, a camera scanner over the top of it, reversible check-ins, an attendance report and a REST endpoint for anything that wants to build its own door. `manage_qevm_checkins` and `manage_qevm_registrations` were granted from 26.0 for exactly this, kept apart from the post type's own capabilities, so the door role is a different list rather than a smaller one.

The screen works with **no JavaScript at all**, and that is the mechanism rather than the fallback: every action is a form post, which is slower and completely reliable in a church hall with two bars of signal. The camera writes into the same code field a person can type into, so a locked-down phone, an older Android or a site not on HTTPS still runs the door.

The QR encoder is written here rather than pulled in: version 1, level M, alphanumeric only — exactly what a ticket code is, and it refuses anything else. SVG rather than PNG, so nothing depends on GD or Imagick being present on somebody else's server.

Three roles land with it — Event Manager, Event Organizer and Event Staff, the last of which can admit people and cannot edit a single post. Adding them is idempotent and never resets a site's own edits to them.

Scanning the same ticket twice is the ordinary case, not an error: the second scan says *already checked in*, and the unique key on `(attendee_id, occurrence_id)` is what makes that true rather than a count that two phones can both read at once. Eight concurrent processes against one ticket admit exactly one person.

### 7. Payments

**Built, and scoped down first** — [ADR-0010](adr/0010-payment-gateway-scope.md) cut three gateways to one. A `Commerce\Gateway` interface, documented as public API, with Stripe as the single first-party implementation; anything else can be written against it as a separate plugin with its own maintainer.

Orders, immutable line snapshots and one signed ledger, so a report written next year still adds up and reconciliation cannot disagree with itself. Money is integer minor units everywhere, formatted in exactly one place — including the number of decimal places, which is not always two.

Seat holds with an expiry sweep, because without them every abandoned checkout permanently eats a seat. Webhooks with signature verification and a timestamp window, because the endpoint is public and a signature stays valid for ever. Refunds full and partial, where only a full one frees the place.

Nothing here stores card details: the card is entered on Stripe's side and never reaches the site.

### 8. WooCommerce integration

**Built.** For sites that already run a shop: a ticket type becomes a product, and Woo handles the basket, the payment, the tax and the refunds. The ticket type stays the one editable copy of a price — the product is a shopfront for it, never the other way round.

Mutually exclusive with the built-in gateway, which needed a mechanism the module registry did not have: switching one on switches the other off, read from either end of the pair. Two things that both own a checkout do not fail loudly when both are on; they each half-work.

A purchase for a full event joins the waiting list rather than overselling, because Woo does not know the room's capacity and the booking goes through the ordinary path.

### 9. Recurring events

Modelled as a series with a parent and generated occurrences, not as unrelated copies. Editing one occurrence versus the whole series is the hard part, and getting it wrong is what makes recurring events miserable in most calendar software.

**The edit semantics were written down before any code** — [docs/recurrence.md](recurrence.md), reviewed, with [ADR-0015](adr/0015-recurrence-identity-and-overrides.md) behind it. Writing that spec found a latent bug in the occurrence reconciler: it identified rows by their start time, which stops identifying anything the moment one occurrence can be moved, so a moved date was silently destroyed on the next unrelated save of the event. Occurrences now carry a `recurrence_id` — the slot the rule generated, which never changes however far the date moves.

**The rule domain and generation are built.** Rules are stored as RFC 5545 RRULE strings, so the `.ics` export is a copy rather than a translation and a series can be imported from another calendar. Daily, weekly with named weekdays, monthly by date or by weekday position — "the last Friday" — and yearly. Generation happens in the event's own timezone in wall-clock time, so an 18:00 meeting is 18:00 every week and its UTC value moves across a daylight saving boundary rather than the other way round. Bounded three ways: the rule's own ending, a two-year horizon, and a ceiling of 730 dates, with a daily task walking the horizon forward so a series never quietly runs out.

**Editing is built.** Moving one date, calling one off and putting it back are per-occurrence edits that survive every later save of the event, because a generated row is identified by the slot the rule produced rather than by when it happens. "This and following" splits the series into two events sharing one identifier, moving the dates from the split point onward to the new one rather than regenerating them — so a place somebody booked on the ninth week is still that place afterwards.

**The UI is built too**, so none of this is code-only: a **Repeats** box on the event editor builds the rule without anybody typing an RRULE, and **Events → Dates** lists every date the rule produced with move, call off, put back, reset and "split from here" beside each one. Bookings belong to a date rather than to the series: the form asks which one, capacity is counted per date, and the waiting list for the 3rd of June is not the waiting list for the 10th. Nothing on the dates screen emails anybody, which is deliberate — moving a date that two hundred people have booked is exactly when they need telling, and exactly why it must not happen as a side effect of a save.

## Before release

Independent of the features above:

- ~~`npm run build` wired up and the compiled blocks confirmed present~~ — done, and all four blocks now open in the editor without the fatal that used to kill that screen
- ~~Screenshots in `.wordpress-org/`~~ — done, and taking them found two visible defects
- **`SVN_USERNAME` is still missing from the GitHub repo.** `SVN_PASSWORD` is there; both siblings have the pair. A tag pushed today fails at authentication after a green build, which is how a release on a sibling was already lost
- ~~An integration test suite against real WordPress and MySQL~~ — 564 tests, including WooCommerce and eight-process concurrency runs
- ~~A full security and performance pass~~ — done; both found real defects, recorded in the development plan
- ~~`readme.txt` rewritten to describe the finished plugin~~ — done
- ~~Translation template regenerated~~ — done, 713 strings, and the block editor's strings are translatable for the first time
- Still to do: the final review and the `26.0` tag

## Not planned, ever

- **A paid tier.** No feature is held back to create one, and none will be.
- **Telemetry or usage tracking.** The plugin sends nothing anywhere.
- **A page builder.** Copy a template into your theme; that is the whole system.
- **Bundled icon fonts or JavaScript frameworks.** Blocks render in PHP and the front end ships one stylesheet.
