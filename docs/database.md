# Database architecture

The schema is a long-term contract with users. Once 26.0 publishes, every table and
column here becomes something real installations depend on.

This document describes the **whole target schema**, including tables that will not
be created until their module is switched on. Designing it in one piece is the point:
it is how we avoid discovering in 2029 that ticketing cannot be added without
restructuring registrations.

Two rules govern everything below:

> **Design for the product we are building toward, not just the release we are cutting.**
>
> **Do not build speculative complexity because it might be useful someday.**

The resolution between them is: *design* the full model now, *create* each table when
its feature arrives, and *reserve* only the columns whose later addition would be
expensive or ambiguous.

---

## Storage decision framework

Every entity is tested against this before it gets a table.

```
Is it authored by a site owner, with prose, images and a URL?
    └─ yes ──► Custom post type + post meta
Is it a classification with archives?
    └─ yes ──► Taxonomy
Is it a person who logs in?
    └─ yes ──► WordPress user + user meta
Is it a small, singular piece of configuration?
    └─ yes ──► Option
Is it generated in volume, queried relationally, or a transaction record?
    └─ yes ──► Custom table
Otherwise ──► post meta
```

A custom table must additionally answer, in writing, in an ADR:

1. What query does it serve that post meta cannot serve efficiently?
2. What volume is expected at each of the three scale tiers?
3. Which indexes serve which named queries?
4. What is the migration path if it must change?
5. What happens to it on uninstall?

### The results

| Entity | Storage | Why |
| --- | --- | --- |
| **Event** | CPT `qevm_event` + post meta | Authored. Wants the block editor, media, revisions, taxonomies, permalinks, search — all free, none worth rebuilding |
| **Event category / tag** | Taxonomies `qevm_event_category`, `qevm_event_tag` | Core gives archives, admin UI, REST |
| **Venue** | CPT `qevm_venue` + post meta, **plus a copy on the event** | Authored and reusable: address, description, photo, map. Low volume. Needs its own admin screen. The event keeps its own address so the module can be switched off — [ADR-0014](adr/0014-venue-records-with-flat-fallback.md) |
| **Organizer** | CPT `qevm_organizer` + post meta, **plus a copy on the event** | Same reasoning, and the same fallback. Not a WP user — most organisers have no account |
| **Occurrence** | **Table** `qevm_occurrences` | Every date query in the plugin. Needs a real indexed `datetime`. [ADR-0003](adr/0003-occurrence-table.md) |
| **Series** | `series_uuid` column + post meta | No table needed. A UUID groups events across a "this and following" split and survives deletion of any member |
| **Registration** | **Table** `qevm_registrations` | Transactional, high volume, queried by event and status |
| **Attendee** | **Table** `qevm_attendees` | Higher volume than registrations. Check-in and tickets target a person, not a booking. [ADR-0004](adr/0004-registration-attendee-split.md) |
| **Custom field *definitions*** | Post meta `_qevm_registration_fields` (JSON) | Configuration. Never queried across events. JSON is correct here |
| **Custom field *answers*** | **Table** `qevm_attendee_meta` | Reported on, filtered, exported. JSON cannot be indexed on MySQL 5.7, which WordPress still supports |
| **Ticket type** | **Table** `qevm_ticket_types` | Referenced by orders; has price, capacity and a sale window |
| **Ticket** | *No table* — it is an attendee row | One attendee holds one ticket. `ticket_code` on the attendee is what a QR encodes |
| **Order** | **Table** `qevm_orders` | Financial header |
| **Order item** | **Table** `qevm_order_items` | Immutable price snapshot. [ADR-0006](adr/0006-money-and-immutability.md) |
| **Payment / refund** | **Table** `qevm_transactions` | One signed ledger, not two tables. Reconciliation is a single `SUM` |
| **Check-in** | **Table** `qevm_checkins` | High volume; needs a unique constraint to survive concurrent scanning |
| **Queued email** | **Table** `qevm_email_queue` | `wp_mail()` is synchronous; 500 recipients in one request is a timeout |
| **Settings** | Options `qevm_settings`, `qevm_enabled_modules`, `qevm_db_version` | Small and autoloaded |

Ten tables in the final shape. Each is created by the module that owns it, on first
enable — an install that only lists meetups grows exactly one of them.

---

## Cross-cutting conventions

These apply to every table and are not repeated per-table below.

**Identifiers.** Every table has `id bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY`.
Names and titles change; ids do not. Anything a human or an external system quotes
back at us (`code`, `ticket_code`, `order_number`) is additionally unique-indexed and
never reissued.

**Money.** `bigint` in **minor units** (paise, cents) plus a `char(3)` ISO-4217
currency. Never a float, never a decimal string. `₹499.50` is `49950` with `INR`.
Floats cannot represent `0.1`, and a rounding error in a refund is a support ticket
that costs more than the transaction.

**Timestamps.** `datetime` in **UTC**, always. Where a wall-clock time was authored,
the local value and the IANA timezone identifier are stored alongside it. Queries
compare UTC; display converts. The server's timezone is never the source of truth.
Every table carries `created_at` and `updated_at`.

**Character set.** `utf8mb4` via `$wpdb->get_charset_collate()`. Indexed string
columns are `varchar(190)` — 190 × 4 = 760, inside the 767-byte index limit that
MySQL below 5.7 enforces.

**Statuses are separate state machines.** There is no shared `status` vocabulary.
Each table's status column documents its own allowed values, and each is modelled as
a PHP enum. A registration being `confirmed` and an order being `paid` are unrelated
facts, and collapsing them into one column is how status fields rot.

**Foreign keys.** Declared as indexed `bigint unsigned` columns, not SQL `FOREIGN KEY`
constraints — WordPress runs on MyISAM installations and core itself declares none.
Referential integrity is enforced in the repository layer, and every child row is
resolvable to `0` meaning "not set" rather than `NULL` meaning the same thing.

**Deletion never cascades silently.** Trashing an event does not delete registrations.
Removal is always a deliberate, named operation.

---

## Table definitions

Stage numbers refer to [development-plan.md](development-plan.md).

### `qevm_occurrences` — stage 1 *(built in C1.2, queried through `OccurrenceQuery` from C1.4)*

Every date the plugin knows about. A one-off event has exactly one row.

```sql
CREATE TABLE {prefix}qevm_occurrences (
    id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    event_id      bigint(20) unsigned NOT NULL,
    series_uuid   char(36)     NOT NULL DEFAULT '',
    recurrence_id datetime         NULL DEFAULT NULL,
    start_utc     datetime     NOT NULL,
    end_utc       datetime     NOT NULL,
    start_local   datetime     NOT NULL,
    end_local     datetime     NOT NULL,
    timezone      varchar(64)  NOT NULL,
    all_day       tinyint(1)   NOT NULL DEFAULT 0,
    is_exception  tinyint(1)   NOT NULL DEFAULT 0,
    status        varchar(20)  NOT NULL DEFAULT 'scheduled',
    created_at    datetime     NOT NULL,
    updated_at    datetime     NOT NULL,
    PRIMARY KEY  (id),
    KEY start_utc     (start_utc),
    KEY event_start   (event_id, start_utc),
    KEY series_start  (series_uuid, start_utc),
    KEY status_start  (status, start_utc),
    KEY status_end    (status, end_utc)
);
```

`status`: `scheduled` · `cancelled` · `moved`

**`end_utc` is `NOT NULL`.** An event with no stated end stores `end_utc = start_utc`.
This single decision removes the three-branch `OR` meta_query the current code needs
to answer "is this still upcoming", replacing four `postmeta` joins and a `CAST` with
one range condition.

**`status_end` is what makes that range a seek.** Added in C1.12, after the Stage 1
gate measured the archive at 10,000 events and found `type: ALL`. The four original
indexes all led with `start_utc`; the archive filters on `end_utc >= now`, so the
predicate had nothing to seek on and every query read the whole table. With the index,
a realistic archive — 5% of events still to come — reads 501 rows instead of 10,000.

MySQL still chooses a full scan when more than roughly a third of the table matches,
and it is right to: reading the table beats 5,000 index lookups. An index is worth
having for the case that is common, not for the case that is worst.

The row is **derived**, not authored. Post meta remains the human-readable record and
the thing the editor writes; `save_post` regenerates the occurrence rows from it, and
`wp qevm occurrence rebuild` regenerates all of them from scratch. A derived table
without a rebuild path is a table that eventually drifts with no way back.

`series_uuid` and `is_exception` are created empty in stage 1 and used in stage 6.
They cost nothing now and avoid altering a large table later.

`recurrence_id` was not anticipated in stage 1 and is added in C6.2 (schema version 7).
It holds the UTC start of the slot the recurrence rule generated, and it is what a
generated row is **identified by**. Reconciliation used to match on `start_utc`, which
stops identifying anything the moment a single occurrence can be moved — moving it changes
the field being matched on, so the row is deleted and reinserted and the override is lost
on the next unrelated save. See
[ADR-0015](adr/0015-recurrence-identity-and-overrides.md) and
[docs/recurrence.md](recurrence.md) §2, which has the run that demonstrates it.

**Nullable, and never an empty string.** `''` is not a datetime: MySQL in strict mode
rejects it, and without strict mode stores `0000-00-00 00:00:00` — a value that is not
NULL, compares equal to nothing, and cannot be read back as a date. Either way the row
stops being matchable, which is the one thing the column exists to make possible.
`OccurrenceRepository::normalise()` converts an empty value to NULL for exactly this
reason, and an integration test asserts the stored value is NULL rather than a zero date.

### `qevm_registrations` — stage 1 *(altered in C1.6)*

One booking. May cover several people.

```sql
CREATE TABLE {prefix}qevm_registrations (
    id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    event_id         bigint(20) unsigned NOT NULL,
    occurrence_id    bigint(20) unsigned NOT NULL DEFAULT 0,
    ticket_type_id   bigint(20) unsigned NOT NULL DEFAULT 0,
    order_id         bigint(20) unsigned NOT NULL DEFAULT 0,
    user_id          bigint(20) unsigned NOT NULL DEFAULT 0,
    code             varchar(32)  NOT NULL,
    status           varchar(20)  NOT NULL DEFAULT 'confirmed',
    quantity         smallint(5) unsigned NOT NULL DEFAULT 1,
    booker_name      varchar(190) NOT NULL,
    booker_email     varchar(190) NOT NULL,
    booker_phone     varchar(50)  DEFAULT NULL,
    consent_version  varchar(64)  NOT NULL DEFAULT '',
    consent_at       datetime     DEFAULT NULL,
    created_at       datetime     NOT NULL,
    updated_at       datetime     NOT NULL,
    cancelled_at     datetime     DEFAULT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY   code (code),
    KEY occ_status    (occurrence_id, status),
    KEY ticket_status (ticket_type_id, status),
    KEY event_status  (event_id, status),
    KEY event_email  (event_id, booker_email),
    KEY user_id      (user_id),
    KEY order_id     (order_id)
);
```

`status`: `pending` · `confirmed` · `waitlisted` · `cancelled`
Occupying a place: `pending` and `confirmed` only.

`name`/`email`/`phone` are renamed to `booker_*`. Once attendees exist, an unqualified
`name` on a registration is ambiguous, and ambiguous column names are how schemas
become unreadable.

`code` is a human-readable reference — `QEVM-7F3K9A2M` — from an alphabet with no
`0`/`O` or `1`/`I`, so it survives being read aloud at a desk.

**No IP address column, ever.** Rate limiting keys on a salted hash held in a
transient. It keeps the privacy disclosure short and honest.

`consent_version` is a fingerprint of the wording that was agreed to — twelve hex
characters of SHA-1, derived from the text rather than typed by hand *(C1.8)*. A
version somebody has to remember to increment is a version that is wrong the first
time the text is edited in a hurry. The wording itself is not archived, so this
answers "was this the wording that is on the site now?" and not "what exactly did
they see". Both columns are written only when consent was actually given: an empty
version with a timestamp beside it would read as consent to nothing at a specific
moment. Neither is ever taken from the request — what was agreed to is whatever the
site was showing.

`order_id` is reserved in stage 1, used in stage 9.

**`ticket_type_id` is added in C7.2**, and it is on the booking rather than only on the
people for one reason: **capacity is ranked here.** The insert-then-rank routine counts
places on this table, and attendee rows are written afterwards precisely so that nothing
in the ranking depends on them. Counting a ticket type's capacity from `attendees` would
put the count on rows created after the decision it informs, which is the race this
design exists to avoid. So a booking is for one kind of place; somebody wanting two kinds
makes two bookings.

A booking is ranked against **both** limits — the event's or date's capacity, and its
ticket type's — and is confirmed only if it fits both. Twelve seats with four kept for
members means the fifth member waits while the room is half empty, and the thirteenth
person waits whatever ticket they hold. `KEY ticket_status` exists so the second count is
an index lookup rather than a scan.

### `qevm_attendees` — stage 1 *(built in C1.5)*

One person. What a QR code resolves to and what check-in marks.

```sql
CREATE TABLE {prefix}qevm_attendees (
    id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    registration_id  bigint(20) unsigned NOT NULL,
    occurrence_id    bigint(20) unsigned NOT NULL DEFAULT 0,
    ticket_type_id   bigint(20) unsigned NOT NULL DEFAULT 0,
    ticket_code      varchar(32)  NOT NULL,
    position         smallint(5) unsigned NOT NULL DEFAULT 1,
    name             varchar(190) NOT NULL DEFAULT '',
    email            varchar(190) NOT NULL DEFAULT '',
    status           varchar(20)  NOT NULL DEFAULT 'active',
    created_at       datetime     NOT NULL,
    updated_at       datetime     NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY   ticket_code (ticket_code),
    KEY registration (registration_id),
    KEY occ_status   (occurrence_id, status),
    KEY ticket_type  (ticket_type_id),
    KEY email        (email)
);
```

`status`: `active` · `cancelled`

A registration for three places creates three rows. `position` is 1, 2, 3 — the
booker is position 1. `name` may be empty for guests whose names were not collected;
that is a known and acceptable state, not an error.

`ticket_type_id` is reserved in stage 1, used in stage 7.

**Written by `RegistrationService` after the booking has resolved** *(C1.7)*, never
during it: capacity is counted in places on the registration, and the insert-then-rank
routine that makes that safe under concurrency must not have anything added to it.
Only position 1 carries an `email`, because the form asks for one address and
inventing a guest's would record something nobody entered.

There is no foreign key — `dbDelta` does not create them and WordPress does not
assume InnoDB — so the parent owns the cascade. `Repository::delete()` removes a
booking's rows before removing the booking, `Repository::delete_for_event()` reaches
them through a join, and `Repository::update_status()` carries a cancellation and a
reinstatement across on the transition rather than on every save.

### `qevm_attendee_meta` — stage 3 *(built in C3.4)*

Answers to custom registration questions.

No `created_at` or `updated_at`, departing from the convention above that every table
carries them. A row here has no life of its own: it is written with its attendee and
removed with them, and the retention sweep works from the registration's date rather
than the answer's. Two datetime columns per answer would record nothing anybody can
ask a question about.

A choose-any question stores one row per choice. That is what makes "how many people
need step-free access" a `COUNT` rather than a search through serialised text, and it
is the whole reason answers are a table while definitions are JSON.

```sql
CREATE TABLE {prefix}qevm_attendee_meta (
    meta_id      bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    attendee_id  bigint(20) unsigned NOT NULL,
    meta_key     varchar(190) NOT NULL,
    meta_value   longtext     DEFAULT NULL,
    PRIMARY KEY  (meta_id),
    KEY attendee_key (attendee_id, meta_key),
    KEY meta_key     (meta_key(60))
);
```

Deliberately mirrors core's `postmeta` shape, so every WordPress developer recognises
it on sight.

**This is the boundary between modelled and extensible data.** Core fields — name,
email, dates, capacity, price — are real columns with real indexes. Site-defined
questions live here. The rule that keeps this from becoming an unqueryable swamp:
*we never add a core feature that reads from this table.* If a field graduates to
being something the plugin itself reasons about, it graduates to a column.

Bounded by attendees × questions, so it grows linearly and predictably. Answers are
reportable — `KEY meta_key` serves "how many people chose the vegetarian option"
without scanning JSON.

### `qevm_email_queue` — stage 5 *(built in C5.1)*

```sql
CREATE TABLE {prefix}qevm_email_queue (
    id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    template       varchar(64)  NOT NULL,
    recipient      varchar(190) NOT NULL,
    subject        text         NOT NULL,
    body           longtext     NOT NULL,
    headers        text         DEFAULT NULL,
    context_type   varchar(40)  NOT NULL DEFAULT '',
    context_id     bigint(20) unsigned NOT NULL DEFAULT 0,
    meta           longtext     DEFAULT NULL,
    status         varchar(20)  NOT NULL DEFAULT 'pending',
    attempts       tinyint(3) unsigned NOT NULL DEFAULT 0,
    last_error     text         DEFAULT NULL,
    scheduled_for  datetime     NOT NULL,
    sent_at        datetime     DEFAULT NULL,
    created_at     datetime     NOT NULL,
    PRIMARY KEY  (id),
    KEY status_scheduled (status, scheduled_for),
    KEY context          (context_type, context_id)
);
```

`status`: `pending` · `sending` · `sent` · `failed` · `cancelled`

The body is rendered at queue time, not send time, so an email says what it said when
it was triggered even if the template later changes. `attempts` plus `last_error`
means a delivery failure is visible rather than silent.

`sending` is a claim, not a progress report. A worker moves a row into it with an
`UPDATE … WHERE status IN ( pending, sending )`, which MySQL applies to a given row
once — so two workers running at the same time, a cron tick and a page load, cannot
both take the same message and send it twice. A duplicate confirmation carries the
same booking reference and the recipient cannot tell which is real.

The claim also pushes `scheduled_for` forward, which is what makes an abandoned
message recoverable: a worker that dies leaves its row in `sending`, and after the
timeout the row is due again. `attempts` was already incremented by the claim, so a
row that keeps killing workers still runs out of attempts rather than looping.

**`meta` is added in C8.3**, and it is not a second context. `context_type` and
`context_id` are how a message is *found again* — every message for an event, every
message in a broadcast — which is what withdrawing an unsent broadcast searches on, and
they stay one pair for that reason. `meta` is detail a listener needs when the message is
finally sent and nothing needs to search by: a confirmation carries the id of the booking
it is for, which is what lets the check-in module attach that booking's tickets without
the registration module knowing that QR codes exist.

### `qevm_ticket_types` — stage 7 *(built in C7.1)*

```sql
CREATE TABLE {prefix}qevm_ticket_types (
    id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    event_id         bigint(20) unsigned NOT NULL,
    occurrence_id    bigint(20) unsigned NOT NULL DEFAULT 0,
    name             varchar(190) NOT NULL,
    description      text         DEFAULT NULL,
    price_minor      bigint(20)   NOT NULL DEFAULT 0,
    currency         char(3)      NOT NULL DEFAULT '',
    capacity         int(10) unsigned NOT NULL DEFAULT 0,
    min_per_order    smallint(5) unsigned NOT NULL DEFAULT 1,
    max_per_order    smallint(5) unsigned NOT NULL DEFAULT 0,
    sale_starts_utc  datetime     DEFAULT NULL,
    sale_ends_utc    datetime     DEFAULT NULL,
    sort_order       smallint(5) unsigned NOT NULL DEFAULT 0,
    status           varchar(20)  NOT NULL DEFAULT 'active',
    created_at       datetime     NOT NULL,
    updated_at       datetime     NOT NULL,
    PRIMARY KEY  (id),
    KEY event      (event_id),
    KEY occ_status (occurrence_id, status)
);
```

`occurrence_id = 0` means the type applies to every occurrence of the event.
`capacity = 0` means unlimited. A free ticket type is `price_minor = 0` — free and
paid are the same entity, which is what stops free events needing a separate code path.

**Ticket types are never hard-deleted** once an order references them; they are set
`status = 'archived'`. Historical orders keep their own snapshot regardless (below),
but the reference stays resolvable.

> **As built in C7.1**, with one deliberate departure: `price_minor` is `unsigned`. A
> negative price is not a discount, it is a mistake, and a discount is a different feature
> with its own record. The note here previously claimed the table was "exactly as
> described", which was wrong in the one column stage 9 copies into its snapshots — found
> by an audit, not by a test, because nothing compares this document to that table the way
> `MigrationTest` compares it to `registrations`.
>
> Everything else is as described above, including the four columns nothing reads yet — `occurrence_id`, `currency`, `min_per_order` and
> `max_per_order`. Writing the schema this document already specified, rather than the
> narrower one the chunk needed, costs nothing now and avoids altering a table that will
> hold a row for every ticket ever sold.
>
> "In use" is asked of `attendees.ticket_type_id`, which has existed since stage 1 for
> this. `TicketTypeRepository::delete()` refuses and archives instead when anything holds
> a ticket of the type, and it says which it did rather than reporting success either
> way.
>
> One thing worth deciding rather than inheriting: **`currency` per ticket type is
> probably a modelling mistake** — a site has one currency, and two types of the same
> event priced in different ones is not a thing anybody wants. The column is written as
> specified and left empty, meaning "the site's own". Stage 9 should either use it
> deliberately or drop it.

### `qevm_orders` — stage 9

```sql
CREATE TABLE {prefix}qevm_orders (
    id                bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    order_number      varchar(32)  NOT NULL,
    user_id           bigint(20) unsigned NOT NULL DEFAULT 0,
    status            varchar(20)  NOT NULL DEFAULT 'pending',
    currency          char(3)      NOT NULL,
    subtotal_minor    bigint(20)   NOT NULL DEFAULT 0,
    tax_minor         bigint(20)   NOT NULL DEFAULT 0,
    total_minor       bigint(20)   NOT NULL DEFAULT 0,
    refunded_minor    bigint(20)   NOT NULL DEFAULT 0,
    billing_name      varchar(190) NOT NULL DEFAULT '',
    billing_email     varchar(190) NOT NULL DEFAULT '',
    gateway           varchar(40)  NOT NULL DEFAULT '',
    hold_expires_utc  datetime     DEFAULT NULL,
    created_at        datetime     NOT NULL,
    updated_at        datetime     NOT NULL,
    paid_at           datetime     DEFAULT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY   order_number (order_number),
    KEY status_created (status, created_at),
    KEY user_id        (user_id),
    KEY hold_expires   (hold_expires_utc)
);
```

`status`: `pending` · `paid` · `partially_refunded` · `refunded` · `failed` · `abandoned`

**`hold_expires_utc` is the seat-hold mechanism.** A pending order holds its capacity
until this moment; a cron job then releases it and marks the order `abandoned`.
Without it, every abandoned checkout permanently eats a seat — the step most
implementations forget. `KEY hold_expires` exists solely so that cron sweep is an
index scan.

### `qevm_order_items` — stage 9

The immutable record. This table is the reason historical reports can be trusted.

```sql
CREATE TABLE {prefix}qevm_order_items (
    id                bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    order_id          bigint(20) unsigned NOT NULL,
    ticket_type_id    bigint(20) unsigned NOT NULL DEFAULT 0,
    event_id          bigint(20) unsigned NOT NULL DEFAULT 0,
    occurrence_id     bigint(20) unsigned NOT NULL DEFAULT 0,
    name_snapshot     varchar(190) NOT NULL,
    event_snapshot    varchar(190) NOT NULL DEFAULT '',
    unit_price_minor  bigint(20)   NOT NULL,
    quantity          smallint(5) unsigned NOT NULL DEFAULT 1,
    tax_minor         bigint(20)   NOT NULL DEFAULT 0,
    total_minor       bigint(20)   NOT NULL,
    created_at        datetime     NOT NULL,
    PRIMARY KEY  (id),
    KEY order_id (order_id),
    KEY event_id (event_id)
);
```

`*_snapshot` and `unit_price_minor` are written once and **never updated**. If a
ticket rises from ₹499 to ₹999, last year's order still reads ₹499, and last year's
revenue report still adds up. A financial report never recomputes from current
configuration. See [ADR-0006](adr/0006-money-and-immutability.md).

### `qevm_transactions` — stage 9

Charges and refunds in one signed ledger.

```sql
CREATE TABLE {prefix}qevm_transactions (
    id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    order_id         bigint(20) unsigned NOT NULL,
    kind             varchar(20)  NOT NULL,
    status           varchar(20)  NOT NULL DEFAULT 'pending',
    amount_minor     bigint(20)   NOT NULL,
    currency         char(3)      NOT NULL,
    gateway          varchar(40)  NOT NULL,
    gateway_txn_id   varchar(190) NOT NULL DEFAULT '',
    idempotency_key  varchar(190) NOT NULL DEFAULT '',
    reason           varchar(190) NOT NULL DEFAULT '',
    error_code       varchar(64)  NOT NULL DEFAULT '',
    created_at       datetime     NOT NULL,
    updated_at       datetime     NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY   gateway_txn     (gateway, gateway_txn_id),
    UNIQUE KEY   idempotency     (idempotency_key),
    KEY order_kind (order_id, kind)
);
```

`kind`: `charge` · `refund`  ·  `status`: `pending` · `succeeded` · `failed`

`amount_minor` is **signed** — charges positive, refunds negative — so an order's net
position is one `SUM`. One ledger rather than separate payment and refund tables
means reconciliation cannot disagree with itself.

**`UNIQUE KEY gateway_txn` is the webhook-replay defence.** Payment gateways deliver
the same event more than once by design; a naive handler creates two registrations
for one payment. Inserting first and treating a duplicate-key error as "already
processed" closes that window without a lock — the same insert-then-resolve discipline
already used for capacity.

### `qevm_checkins` — stage 8

```sql
CREATE TABLE {prefix}qevm_checkins (
    id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    attendee_id    bigint(20) unsigned NOT NULL,
    occurrence_id  bigint(20) unsigned NOT NULL,
    checked_in_at  datetime     NOT NULL,
    checked_in_by  bigint(20) unsigned NOT NULL DEFAULT 0,
    method         varchar(20)  NOT NULL DEFAULT 'manual',
    reversed_at    datetime     DEFAULT NULL,
    reversed_by    bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY  (id),
    UNIQUE KEY   attendee_occurrence (attendee_id, occurrence_id),
    KEY occurrence_time (occurrence_id, checked_in_at)
);
```

`method`: `manual` · `qr`

**`UNIQUE KEY attendee_occurrence` is the concurrency guarantee.** Two staff scanning
the same person at two doors both attempt an insert; one succeeds, the other gets a
duplicate-key error and reports "already checked in at 18:42". No locking, no
read-then-write race.

A reversal sets `reversed_at` rather than deleting the row. Attendance is a historical
record, and "checked in then reversed" is different information from "never arrived".

---

## Query patterns and their indexes

Indexes exist because a named query needs them. Each of these is a real query the
plugin runs; anything not on this list does not get an index.

| # | Query | Table | Index used |
| --- | --- | --- | --- |
| Q1 | Upcoming events, soonest first | `occurrences` | `status_start (status, start_utc)` |
| Q2 | Events in a month, for the calendar | `occurrences` | `start_utc` range |
| Q3 | All dates for one event | `occurrences` | `event_start (event_id, start_utc)` |
| Q4 | All dates in a recurring series | `occurrences` | `series_start (series_uuid, start_utc)` |
| Q5 | Places taken for an occurrence | `registrations` | `occ_status (occurrence_id, status)` |
| Q6 | Attendee list for an event, filtered by status | `registrations` | `event_status (event_id, status)` |
| Q7 | Has this email already registered? | `registrations` | `event_email (event_id, booker_email)` |
| Q8 | Look up a booking by its public code | `registrations` | `UNIQUE code` |
| Q9 | Everyone on one booking | `attendees` | `registration (registration_id)` |
| Q10 | Scan a QR code | `attendees` | `UNIQUE ticket_code` |
| Q11 | Everyone expected at one occurrence | `attendees` | `occ_status (occurrence_id, status)` |
| Q12 | One attendee's custom answers | `attendee_meta` | `attendee_key (attendee_id, meta_key)` |
| Q13 | Report: how many chose each option | `attendee_meta` | `meta_key` |
| Q14 | Is this person already checked in? | `checkins` | `UNIQUE attendee_occurrence` |
| Q15 | Door report for an occurrence | `checkins` | `occurrence_time (occurrence_id, checked_in_at)` |
| Q16 | Ticket types on sale for an occurrence | `ticket_types` | `occ_status (occurrence_id, status)` |
| Q17 | Places taken per ticket type | `registrations` | `ticket_status (ticket_type_id, status)` |
| Q18 | Expired seat holds, for the cron sweep | `orders` | `hold_expires (hold_expires_utc)` |
| Q19 | Has this webhook already been handled? | `transactions` | `UNIQUE gateway_txn` |
| Q20 | Net position of an order | `transactions` | `order_kind (order_id, kind)` |
| Q21 | Next batch of mail to send | `email_queue` | `status_scheduled (status, scheduled_for)` |
| Q22 | Every email sent about one registration | `email_queue` | `context (context_type, context_id)` |

**Q1 is the query that motivates the whole occurrence table.** Today it is four
`postmeta` joins, a `CAST(meta_value AS DATETIME)` in the `WHERE`, and a filesort over
an unindexed `LONGTEXT`. After stage 1 it is one index range scan.

### Concurrency

Two places in this schema are contended, and both use **insert-then-resolve** rather
than check-then-write. Checking and then writing has a race that a transaction does
not close under MySQL's default `REPEATABLE READ`, because both sessions read the same
snapshot.

- **Capacity**: insert as `pending`, then count rows with an id at or below the new
  one. Every row learns its own place in the queue deterministically, so an oversell
  becomes a waitlist entry rather than an error. *(Already implemented and verified
  with eight parallel processes against a capacity-1 event.)*
- **Check-in and webhooks**: attempt the insert, treat a duplicate-key error as the
  already-handled case.

---

## Scale assumptions

| Tier | Events | Registrations | What it needs |
| --- | ---: | ---: | --- |
| Small | 100 | 1,000 | Nothing special |
| Medium | 10,000 | 100,000 | Indexed occurrence queries, paginated lists, cached counts |
| Large | 10,000 | 1,000,000+ | The above, plus queued email, batched exports, batched migrations |

Row-count expectations at the medium tier: `occurrences` ≈ events × average
occurrences (10k–500k with recurrence), `registrations` 100k, `attendees` ≈ 1.5 ×
registrations, `attendee_meta` ≈ attendees × questions.

`attendee_meta` is the fastest-growing table. It is bounded and linear, but if a site
defines twenty questions and takes a million registrations it holds thirty million
rows. That is acceptable — it is indexed, and it is only ever read by attendee id or
aggregated by key. It is also the reason we do not add core features that read from it.

---

## Migrations

`QEVM_DB_VERSION` is an integer. Every schema change increments it and adds one
numbered migration class under `includes/Install/Migrations/`.

The runner, the batching, the lock and the checklist for adding one are in
[migrations.md](migrations.md). What follows is the policy it implements.

Rules:

1. **Forward-only.** No down migrations. A failed migration is fixed by a new one.
2. **Idempotent.** Safe to run twice. Integration tests assert the second run changes
   nothing.
3. **Batched** above 1,000 rows, with progress recorded, so a timeout resumes rather
   than restarts.
4. **Runs on `admin_init`, not activation.** A plugin updated in place through the
   dashboard or WP-CLI never fires its activation hook. Compare the stored version
   first; the no-op path costs one option read.
5. **Never destructive** during a normal upgrade.
6. **Documented** in `docs/migrations.md`, every one.

### Expand, never replace

```
Add nullable column  →  backfill in batches  →  write to both  →  read from new
   →  stop writing old  →  mark deprecated  →  drop in a planned major release
```

| Safe | Requires a migration plan |
| --- | --- |
| Add a nullable column | Rename a column |
| Add a table | Change a column's type |
| Add an index | Change a column's meaning |
| Add a status value | Remove a column or table |
| Add a hook | Change a primary key |

A site administrator must never be asked to open phpMyAdmin. The plugin owns its own
schema, including recovery: `wp qevm occurrence rebuild` regenerates the derived table
from post meta if it ever drifts.

### Migrations already required

| # | From | To | Notes |
| --- | --- | --- | --- |
| 1 | `post_type = 'events'` (1.0) | `qevm_event` | **Built in C1.1** as `Migrations\LegacyPostType`. Version-gated, idempotent, batched by id, URLs preserved via `rewrite` slug. Fewer than 10 installs affected |
| 2 | `qem_*` names | `qevm_*` | **Not a migration.** The rewrite was never published, so there is nothing in the field to migrate. This is why the rename is free today and impossible after 26.0 |

---

## Uninstall behaviour

Three distinct events, three distinct behaviours. Conflating them is how plugins
destroy data.

| Event | Behaviour |
| --- | --- |
| **Update** | Nothing is removed. Migrations run. Data is preserved. |
| **Deactivate** | Nothing is removed. Cron events are unscheduled. Rewrite rules flushed. |
| **Module switched off** | Nothing is removed. The table stays. Switching it back on finds the data intact. |
| **Uninstall** | Removes plugin data **only if** the site owner ticked *Delete all data on uninstall*. Default is off. |

> **Not true today, and it is the most serious contradiction in this document.**
> `uninstall.php` consults no option at all: it drops every table unconditionally, and no
> such setting exists. A site owner who removes the plugin to try something else loses
> every registration, attendee and answer with no warning and no way to have asked
> otherwise. Found by an audit of the plan against the code in stage 7. Owned by **C10.11**
> — the setting, the guard, and the tables stages 8 and 9 add to that list.

The default is off because deleting an attendee list is not recoverable, and a plugin
that does it silently on an accidental delete has done something unforgivable.

---

## How future features fit without restructuring

The test of this schema is whether the next five years of the roadmap fit into it.

| Future feature | What it needs | Restructuring required |
| --- | --- | --- |
| Recurring events | More `occurrences` rows; `series_uuid` and `is_exception` already present | **None** |
| Multi-day events | `start_utc` / `end_utc` already span days | **None** |
| Per-occurrence capacity | `occurrence_id` already on registrations and attendees | **None** |
| Group booking | Already the shape after stage 1 | **None** |
| Ticket types | New table; `ticket_type_id` already reserved on attendees | **None** |
| Payments | New tables; `order_id` already reserved on registrations | **None** |
| Partial refunds | Signed ledger already supports them | **None** |
| Multi-currency | Currency is per-order and per-ticket-type already | **None** |
| Check-in | New table keyed on existing attendee and occurrence ids | **None** |
| Waitlist promotion | A status transition, already modelled | **None** |
| Custom fields | `attendee_meta` | **None** |
| Reporting | Every reportable dimension is a column or an indexed meta key | **None** |
| Sessions / speakers | Out of core by decision. Would be occurrences with a parent, plus a taxonomy — the schema permits it | *Not planned* |

The one thing that would force restructuring is what we are doing in stage 1: dates in
post meta, and registrations doubling as attendees. Which is precisely why stage 1
comes before anything is published.
