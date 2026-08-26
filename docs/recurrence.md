# Recurrence — the specification

**Status:** written in C6.1, before any recurrence code exists. Reviewed before C6.2
starts. Everything in stages 6 is implemented against this document; where the two
disagree, this document is wrong and gets changed deliberately rather than drifted away
from.

The plan calls Stage 6 "the largest correctness problem in the release" and makes this
document non-optional, on the grounds that *recurrence edit semantics written during
implementation are recurrence edit semantics done wrong*. This is the attempt to decide
them while it is still cheap.

---

## 1. What a recurring event is

**One post, many occurrence rows, one rule.**

| Thing | Where it lives | Notes |
| --- | --- | --- |
| The event | A `qevm_event` post | Title, content, venue, capacity, registration settings |
| The rule | Post meta on that event | `_qevm_recurrence_rule` |
| Each date | A row in `qevm_occurrences` | Derived from the rule, never authored by hand |
| The series | `occurrences.series_uuid` | Groups rows **across posts**, which only matters after a split |

This is not a new model. [ADR-0003](adr/0003-occurrence-table.md) already established that
dates live in an indexed table and are *derived* from post meta, and `series_uuid` and
`is_exception` have existed as empty columns since C1.2 precisely so that Stage 6 would
not have to alter a large table. Stage 6 fills them in.

**`series_uuid` is minted when recurrence is first switched on**, not at the first split.
Minting it up front means "everything in this series" is one indexed query with one shape
whether or not the series has ever been split. Minting it lazily would mean two code
paths, one of which is exercised rarely and is therefore the one that is broken.

A non-recurring event keeps `series_uuid = ''`. It is not a series of one, and pretending
otherwise would put every ordinary event into the recurrence code paths.

---

## 2. The problem this document exists to catch

`OccurrenceRepository::replace_for_event()` reconciles by **start time**:

```php
// Keyed on start, which is what identifies a date within an event.
$existing[ $occurrence->start_utc() ] = $occurrence;
```

Anything the rule no longer produces is deleted. That comment is true for one-off events
and **false the moment a single occurrence can be moved**, because moving an occurrence
changes exactly the thing being used to identify it.

Concretely: a weekly series, and the organiser moves the 12 March meeting to the 13th.

1. The occurrence row's `start_utc` becomes 13 March. `is_exception = 1`.
2. Somebody edits the event's title, or anything else that triggers `save_post`.
3. `build()` regenerates from the rule, which still says 12 March.
4. The wanted list contains 12 March. The existing rows contain 13 March.
5. 13 March is "a date the event no longer has" → **deleted**.
6. 12 March is missing → **inserted**, as a fresh row with a new id.

The organiser's edit is gone, silently, on an unrelated save. Every registration pointing
at the old row id is now pointing at nothing.

**This does not bite today.** `registrations.occurrence_id` and `attendees.occurrence_id`
exist but are never written — every row holds `0`. Nothing points at an occurrence, so
destroying and recreating one is invisible. Stage 6 is where that stops being true, and
the bug arrives at the same time as the feature that needs it not to.

### Verified, not reasoned

The paragraphs above were written from reading `replace_for_event()`. Reasoning about code
instead of running it has produced a wrong answer on this project before, and this claim
is what the rest of the document rests on, so it was run against a real WordPress: create
an event, move its occurrence row and set `is_exception = 1` the way Stage 6 would, then
edit the **title** and nothing else.

```
occurrence 20 at 2026-03-12 18:00:00
moved to 2026-03-13 18:00:00, is_exception=1, status=moved

sync after an unrelated save: {"inserted":0,"updated":0,"deleted":0,"unchanged":1}
FAIL — row 20 was deleted
FAIL — row 21 is at 2026-03-12 18:00:00, is_exception=0
FAIL — id went 20 -> 21
```

The override was destroyed by the title edit, through `save_post`, with no recurrence code
involved at all.

The reported result is the part worth keeping. `unchanged: 1` is what a second sync
returns **after** the first one has already replaced the row — and it is completely
truthful, because by then the table and the rule do agree. The reconciler's own report
cannot show this happening. Anything watching `qevm_occurrences_synced` for trouble sees a
quiet, healthy save.

### The fix: occurrences need an identity that is not their time

Every rule-generated row records the slot it came from, in a new column:

```sql
recurrence_id datetime DEFAULT NULL
```

The UTC start of the occurrence **as the rule generated it**, set once and never changed.
Moving an occurrence changes `start_utc`; it does not change `recurrence_id`.
Reconciliation matches on `recurrence_id`, so a moved occurrence is recognised as the
same date it always was.

The name is taken from [RFC 5545 §3.8.4.4](https://datatracker.ietf.org/doc/html/rfc5545#section-3.8.4.4),
where `RECURRENCE-ID` does this exact job. Borrowing the name costs nothing now and makes
the `.ics` export correct later instead of needing a translation.

`recurrence_id` is `NULL` for a non-recurring event's single row, which continues to
reconcile by start time. See [ADR-0015](adr/0015-recurrence-identity-and-overrides.md).

---

## 3. The three scopes

Every edit to a recurring event asks one question: **how far does this go?**

| Scope | Means | Applies to |
| --- | --- | --- |
| **This occurrence** | One date changes; the rule is untouched | Time, cancellation |
| **This and following** | The series splits here; this date onward changes | Time, rule, and the event's own fields |
| **All** | The whole series changes | Time, rule, and the event's own fields |

The prompt is never skipped and has no remembered default. A checkbox that says "apply to
all future events" and stays ticked is how somebody rewrites a year of dates while
thinking they fixed a typo in one.

**"All" is the default selection** where a default is needed, because it is the only one
of the three that is fully reversible by repeating the edit. An accidental "this and
following" leaves a split that has to be merged back by hand.

### What each scope may change

Not every field is editable at every scope, and the honest answer is narrower than the
prompt suggests.

| Field | This occurrence | This and following | All |
| --- | --- | --- | --- |
| Start / end time | Yes | Yes | Yes |
| Cancelled | Yes | Yes | Yes |
| Recurrence rule | — | Yes | Yes |
| Title, content, venue, capacity, registration settings | **No** | Yes | Yes |

**A single-occurrence edit changes when a date happens, or whether it happens. It does not
change what it is.** There is nowhere to put a per-occurrence title: the occurrence table
holds times and status, and the title lives on the post that all the occurrences share.

Giving one instance its own title means detaching it into its own post, which is a
different feature with its own consequences — a detached instance stops receiving series
edits, which is either exactly what was wanted or a silent surprise depending on who you
ask. It is **out of scope for 26.0** and named in ADR-0015 rather than half-built. A
"Christmas special" is served today by cancelling that occurrence and creating a separate
event, which is honest and takes four clicks.

---

## 4. Every case

### 4.1 This occurrence — move

1. `start_utc` / `end_utc` / `start_local` / `end_local` updated on that row alone.
2. `is_exception = 1`.
3. `status = moved`.
4. `recurrence_id` unchanged — this is still the 12 March slot, happening on the 13th.
5. The rule is untouched. Every other row is untouched.
6. Registrations keep their `occurrence_id`. Nothing is re-pointed, because the row they
   point at is the row that moved.
7. Attendees are **not** emailed automatically. See §6.

**Built in C6.4b**, along with two operations §3's table does not list because they are
the undo rather than an edit:

| Operation | What it does |
| --- | --- |
| `OccurrenceEditor::move()` | New times, `is_exception = 1`, `status = moved` |
| `OccurrenceEditor::cancel()` | `status = cancelled`, `is_exception = 1`, row kept |
| `OccurrenceEditor::reinstate()` | Puts a called-off date back on |
| `OccurrenceEditor::restore()` | Hands the date back to the rule |

Nothing stores an "original" to restore from. Clearing `is_exception` gives the rule
ownership again and the next regeneration rewrites the row from its own
`recurrence_id` — the slot has been on the row since it was generated.

### 4.2 This occurrence — cancel

Same as a move, but `status = cancelled` and the times are left alone. The row stays.
A cancelled date that vanishes from the calendar is indistinguishable from a date that
was never scheduled, and somebody who has it in their diary deserves to see it crossed
out rather than to find nothing.

Registrations are **not** cancelled automatically. Cancelling a date and refunding twelve
people are two decisions, and collapsing them means one click does both.

### 4.3 This occurrence — regenerated by a later save

The case §2 exists for. On any regeneration:

- A row with `is_exception = 1` **keeps its times and status**. The rule may not overwrite
  them.
- It is matched by `recurrence_id`, so it is found however far it has been moved.
- If the rule no longer generates that slot at all, see §4.7.

### 4.4 This and following — split

The hardest case, and the one the gate names.

1. Mint a new `qevm_event` post, copying every field of the original: title, content,
   meta, taxonomy terms, featured image. It is a *duplicate*, and C2.7's duplicator
   already exists to do it.
2. Both posts carry the **same `series_uuid`**. That is what the column is for and what
   the gate checks.
3. The original event's rule gains an end: it stops the day before the split point.
4. The new event's rule starts at the split point and carries the edit.
5. Occurrences from the split point onward are **re-pointed** to the new post —
   `UPDATE ... SET event_id = <new>` — rather than deleted and regenerated.

Point 5 is the one that matters. Regenerating would give every date from the split point
onward a new row id, and every registration attached to those dates would be orphaned in
one statement. Re-pointing preserves the rows, so `registrations.occurrence_id` stays
valid across a split without the registration table being touched at all.

`registrations.event_id`, however, becomes wrong: it names the original post, and the
occurrence has moved to the new one. So the split also runs
`UPDATE registrations SET event_id = <new> WHERE occurrence_id IN (<moved rows>)`, and
the same for `attendees` where it carries one. **A split is not complete until both
tables agree with the occurrence table.** This is the integrity check the Stage 6 gate
should assert, not merely that both halves share a uuid.

Exceptions inside the moved range keep their `is_exception` flag and their overridden
times. They were exceptions to a rule that is now a different post's rule, and their
override is still what the organiser asked for.

> **Built in C6.4c, with four things this section did not say.**
>
> **A `COUNT` has to be divided, not copied.** The spec says the original gains an end
> and the new half "starts at the split point", which is complete for a rule ending in
> `UNTIL` and wrong for one ending in `COUNT`. Ten weeks split at the fifth, with the
> count copied to both halves, is thirteen weeks — four on the original and ten more on
> the new one — and the organiser finds out from whoever turns up to the eleventh. The
> new half gets `COUNT` minus however many dates stayed behind.
>
> **The original's ending is a second before the slot, not the day before.** "The day
> before the split point" loses any earlier date on the split day itself, which a rule
> producing more than one date a day has.
>
> **The attendees table needs nothing.** It reaches its event through its registration
> and stores no `event_id` of its own, so the "and the same for `attendees`" above has
> no work in it. That is the argument for not denormalising twice, not an omission.
>
> **Splitting at the first date is refused.** It is not a split: it leaves the original
> holding no dates at all and the new event holding the whole series, with the bookings
> of every date that used to be the original's. What "this and following" means on the
> first date is "all of them", which is §4.5's scope and screen.
>
> The split does **not** apply the organiser's edit. It is the structural half of the
> operation; the change they asked for is made against the new event afterwards, by the
> screen that offered the choice. Keeping them apart is what lets the split be tested
> for the thing it must never do — lose something — rather than only ever through an
> edit that hides it.
>
> One more thing the integrity check needs, found by sabotaging the re-point rather than
> by reading the code: comparing `registrations.event_id` against the occurrence's
> `event_id` is an inner join, so it sees nothing at all when the dates were deleted and
> regenerated — the very failure re-pointing prevents. A booking pointing at a date that
> no longer exists has to be looked for separately.

### 4.5 All — rule change

1. The rule is rewritten on the post.
2. Occurrences are regenerated (§5).
3. **Exceptions survive by default.** A row with `is_exception = 1` whose slot the new
   rule still generates keeps its overridden times.
4. Slots the new rule no longer generates go to §4.7.

Changing "every Tuesday" to "every Wednesday" regenerates every slot, so nothing matches
by `recurrence_id` and the whole series is rebuilt. That is correct and it is also
destructive, so the UI must say how many dates are about to change **before** the save,
counted from the same generator that will do it — the same rule as the broadcast's
recipient count in C5.4.

### 4.6 All — field change

Title, content, venue, capacity. Nothing happens to the occurrence table at all: those
fields live on the post, and every occurrence already reads them from it. This is the
common case and it must stay cheap — an organiser fixing a typo should not trigger 52
row updates.

### 4.7 A slot disappears from the rule

Either the rule changed, or the horizon moved, or an exclusion date was added.

| The row | What happens |
| --- | --- |
| Ordinary, no registrations | Deleted |
| Ordinary, has registrations | **Kept**, `status = cancelled`, and reported |
| An exception (`is_exception = 1`) | **Kept**, `status = cancelled`, and reported |

**A date somebody has booked onto is never deleted by a rule change.** Deleting it
destroys the only link between a booking and what it was for, and the organiser finds out
when twelve people arrive. Cancelling it keeps the record, keeps the attendee list, and
puts the decision about telling them in front of the person who made the change.

"Reported" means the save returns a count the UI shows: *"3 dates with registrations were
cancelled rather than removed."* A silent difference between what the rule says and what
the table holds is exactly the derived-data drift ADR-0003 warns about.

### 4.8 Deleting the event

Unchanged from today: `deleted_post` removes every occurrence for that event. With a
split series, deleting one post leaves the other half intact — they are separate posts
that share a `series_uuid`, and the uuid is a grouping, not an owner.

---

## 5. Generation

**Built in C6.3.** `Recurrence\Generator` expands a rule; `Recurrence\LocalTime`
resolves the two wall-clock times that are not instants; `Recurrence\Exclusions` holds
the skip list; `Recurrence\Horizon` walks the window forward on a daily schedule. The
whole feature is a module, `RecurrenceModule`, off on a fresh install — and **the gate is
at the generator, not at the event's meta**, because an event can carry a rule from a
previous life. C3.4 made exactly that mistake with the registration form and the form
rendered with its module off.

### 5.1 Bounded, always

A rule with no end date is not permission to generate forever. Generation is bounded by
whichever comes first:

- the rule's own `UNTIL` or `COUNT`,
- a horizon of **24 months** from today,
- a hard ceiling of **730 rows** per series.

The ceiling is not a second horizon; it is the backstop for a daily rule, where 24 months
is 730 dates. A rule that would exceed it is refused at validation with a message saying
so, rather than accepted and quietly truncated.

The horizon rolls forward. A daily task extends generation as time passes, so a weekly
series set up today still has dates two years out in a year's time. Extension is
generation with a later reference point — it inserts, and it never rewrites what is
already there. Without it every series on the site slowly runs out, invisibly, because
there is no error and every date that exists is correct; the organiser finds out when
somebody asks why the calendar stops in March.

**Events are visited in turn, by position, through a stored cursor.** The obvious design —
extend whichever series is closest to running out — has a treadmill in it: a series whose
rule has already finished has nothing left to generate, so it stays closest to running out
for ever and is picked on every run while nothing else is ever reached. Rotating through
costs a little redundant work on series that need none, and guarantees every series is
reached.

The cursor is a position rather than an event id, and that was the second attempt. An id
cursor needs a "WHERE ID > n" that `WP_Query` cannot express, and filtering ids in PHP
*after* the query has applied its `LIMIT` is worse than either — the first batch filters
down to nothing and the walk never advances past it.

### 5.2 Timezone and DST

Generated in the event's own timezone, in **local wall-clock time**, then converted to
UTC. A 18:00 Tuesday meeting is 18:00 every Tuesday, and the UTC value moves by an hour
across a DST boundary. Generating in UTC instead would hold the UTC value still and move
the meeting to 17:00 or 19:00 for half the year — the bug that makes a plugin feel broken
to everybody outside a fixed-offset zone.

Two local times need deciding rather than defaulting, because PHP will answer both without
complaint and the answer it gives is not obviously the one wanted:

| Case | Example | Decision |
| --- | --- | --- |
| **The time does not exist** — clocks sprang forward over it | 02:30 on a night the clock jumps 02:00 → 03:00 | Use the instant the clock jumped to: 03:00. The event happens once, slightly later. |
| **The time happens twice** — clocks went back over it | 01:30 on a night the clock repeats 01:00–02:00 | Use the **first** occurrence, the one before the change. Earlier is the safer error: attendees arrive to a room that is open rather than one that has closed. |

`DateTimeImmutable` resolves both silently, so both get a test with a real timezone and a
real transition date. The plugin's existing timezone tests learned this once already: a
fixture in `Asia/Kolkata` at 23:30 proved nothing because it was still the same date in
UTC.

**Measured in C6.3 rather than assumed**, because the sentence above originally claimed
PHP's choices were neither of these and that was only half right:

| Case | PHP's own answer | Wanted | |
| --- | --- | --- | --- |
| 02:30, spring forward, `America/New_York` | `03:30` | `03:00` | implemented |
| 01:30, fall back, `America/New_York` | first, `-04:00` | first | inherited |

So one rule is written and one is inherited. The inherited one is pinned by a test that
asserts PHP's behaviour directly as well as the plugin's, because inheriting behaviour is
only safe if you find out when it changes. The implemented one detects the gap by
round-tripping the string — a wall-clock time that does not read back as itself is one that
does not exist — and then takes the zone's transition instant.

### 5.3 Exclusion dates

A list of dates the rule generates but the series does not hold, stored on the event.
Removing a date this way is different from cancelling it: an exclusion means the date
never happens and no row exists, and a cancellation means the date exists and is called
off. Excluding a date that has registrations is refused, with the reason, and offers
cancelling instead — §4.7's rule, enforced earlier.

---

## 6. Registrations

The section the chunk definition names, and the one with the least room for
cleverness.

| Event | Registrations |
| --- | --- |
| An occurrence moves | Keep their `occurrence_id`. Untouched. |
| An occurrence is cancelled | Untouched. The organiser decides whether to cancel or refund. |
| A series splits | `occurrence_id` unchanged; `event_id` rewritten to the new post (§4.4) |
| A slot leaves the rule | The occurrence is cancelled rather than deleted (§4.7) |
| The event is deleted | Unchanged from today — deletion cascades as it already does |

**Nothing in Stage 6 emails anybody automatically.** The queue from Stage 5 makes it
trivially easy to tell 200 people that a date moved, which is exactly why it should not
happen as a side effect of a save. A rule edit that shifts 52 dates would send 52 × 200
messages before the organiser had finished reading their own change.

What Stage 6 does instead: after a change that moved or cancelled dates with
registrations on them, the screen says which dates and how many people, with a link to the
broadcast box for that event, pre-addressed. The organiser presses send. This is the same
judgement as C5.4's audience — the plugin makes it easy to tell people and never decides
to.

**Capacity is per occurrence, not per series.** A weekly class with 20 places has 20
places each week. This follows from capacity being counted through `occurrence_id`, and it
is stated here because the alternative — 20 places across the whole series — is what you
get by accident if capacity keeps being read from the post.

> **True as of C6.6.** It was not when this was written: `registrations.occurrence_id` was
> written as `0` by every booking, because nothing asked a visitor which date of a series
> they were booking. The form now asks whenever an event has more than one date, and the
> ranking that decides confirmed-or-waiting counts within that date.
>
> Three things fell out of building it that this section had not said:
>
> - **The duplicate check is per date too.** "That address is already registered for this
>   event" is right for one date and absurd for a weekly class — it would make a term
>   bookable exactly once. Somebody who comes every week books every week.
> - **The waiting list belongs to a date.** A cancellation on the 3rd of June frees a
>   place on the 3rd of June; promoting somebody waiting for the 10th hands them a place
>   they cannot use and takes it from whoever wanted that week.
> - **An event with one date keeps `occurrence_id = 0`, deliberately.** Its single
>   occurrence is identified by its start time, so rescheduling replaces that row with a
>   different one — a booking pointing at it would be orphaned by an ordinary change of
>   date. A generated date carries the slot it came from and survives being moved, which
>   is what makes it safe to attach a person to. Zero means "this booking is for the
>   event", not "unknown".

---

## 7. What this does not cover

Named so that nobody has to guess whether the omission was deliberate.

- **Per-occurrence titles and content.** Out of scope; ADR-0015 records why.
- **Detaching an occurrence into its own event.** Same.
- **Merging a split series back together.** A split is one-way in 26.0.
- **Rules more complex than RFC 5545's common forms** — daily, weekly with weekdays,
  monthly by date, monthly by weekday, yearly. No `BYSETPOS`, no second-Tuesday-unless-
  it-is-a-holiday.
- **Recurring registration** — booking a whole series at once. That is a ticketing
  question and belongs to Stage 7 if it belongs anywhere.

---

## 8. What the gate must check

The plan's Stage 6 gate, with the two additions this document argues for:

| From the plan | |
| --- | --- |
| A weekly series for 52 weeks generates 52 occurrences | |
| Editing one leaves 51 untouched and marks it `is_exception` | |
| "This and following" splits correctly and both halves share the `series_uuid` | |
| Registrations survive an edit to their occurrence | |
| Generation is bounded | |

| Added here | Why |
| --- | --- |
| An exception survives an **unrelated save** of the event | The §2 bug. Every other check passes with the bug present, because they all act on the occurrence and then look at it immediately. |
| After a split, `registrations.event_id` agrees with the occurrence's `event_id` | §4.4. Two tables silently disagreeing is not visible from either one. |
| A date with registrations is cancelled, not deleted, when the rule stops generating it | §4.7 |
| 18:00 stays 18:00 across a real DST transition, in a zone that has one | §5.2, and the existing timezone tests show this needs a real transition to mean anything |
