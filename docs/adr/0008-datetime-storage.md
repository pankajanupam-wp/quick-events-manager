# ADR-0008 — UTC canonical, local and timezone retained

**Status:** accepted · 2026-08-10

## Context

Timezone handling is the defining correctness problem of event software, and the
failure is always the same shape: an event stored as local wall-clock time moves the
day somebody changes the site timezone, and nobody notices until attendees arrive an
hour late.

Storing only UTC does not solve it either. "6pm in Kolkata" has to keep being 6pm in
Kolkata when a visitor in Berlin reads the page, so the *intended* zone has to be
recorded rather than derived from whatever the site is set to today.

There is a third failure: deriving anything from the server's timezone. A host
migration changes it, and every date shifts.

## Decision

Three facts are stored for every datetime:

| Stored | Purpose |
| --- | --- |
| **UTC instant** (`start_utc`, `end_utc`) | The only thing ever sorted, filtered or compared |
| **Local wall-clock** (`start_local`, `end_local`) | Exactly what the organiser typed, displayed without conversion |
| **Timezone identifier** (`timezone`) | IANA identifier, or a `±HH:MM` offset for sites configured that way |

Rules:

- Format is `Y-m-d H:i:s`, zero-padded and big-endian, so lexical order is
  chronological order.
- In the occurrence table these are real `datetime` columns with real indexes
  ([ADR-0003](0003-occurrence-table.md)).
- **Queries compare UTC. Display converts.** No query ever converts.
- `end_utc` is `NOT NULL`; no stated end means `end_utc = start_utc`.
- Display goes through `wp_date()` / `date_i18n()`. Never `date()`.
- The site timezone comes from `wp_timezone()`, falling back to the `gmt_offset`
  option formatted as `±HH:MM`. The server's timezone is never consulted.
- An event counts as upcoming until it **ends**, so a three-day conference on day two
  is still listed.
- All-day events suppress the time in display and use `VALUE=DATE` in iCalendar
  output, which is what stops a calendar client showing them as a midnight
  appointment.

Sanitisation is strict: a datetime that is not exactly `Y-m-d H:i:s` is rejected and
stored empty rather than coerced. A half-understood date sorts into the wrong place
silently, which is worse than an absent one.

## Alternatives considered

**Store local time only.** The classic bug described above. Rejected.

**Store UTC only.** Loses the organiser's intent. A conference at 09:00 local becomes
whatever 09:00 was in UTC on the day it was created, and DST changes between creation
and the event break it. Rejected.

**Store a Unix timestamp.** Compact and unambiguous, but unreadable in the database
during debugging, awkward in `dbDelta` schemas, and it still needs the timezone
stored separately — so it costs readability and saves nothing.

## Consequences

**Good.** Changing the site timezone moves no event. Sorting is index-friendly. DST
transitions are handled by PHP's timezone database rather than by arithmetic. This is
already better than most of the field.

**Bad.** Three columns where naive designs have one, and every write path must keep
them consistent. Confined to one place: the occurrence synchroniser derives UTC from
local plus timezone, and nothing else writes these columns.

**Bad.** A timezone identifier can be deprecated by IANA. Sanitisation accepts any
identifier PHP currently recognises plus explicit offsets, so a stale identifier
degrades to a stored string rather than a fatal error.

**Recurrence note.** When recurring events land, occurrences are generated in the
event's own timezone, so a weekly 18:00 meeting stays at 18:00 across a DST boundary
rather than drifting to 17:00. Generating in UTC would produce exactly that drift.
