# Performance

Measured, not asserted. The script that produced these numbers is
`tests/bench/benchmark.php`, it is in the repository, and anyone can re-run it:

```sh
npx @wordpress/env run tests-cli wp eval-file \
    wp-content/plugins/quick-events-manager/tests/bench/benchmark.php
```

## The numbers

**10,000 events**, each with a date row, spread over two years either side of
today. **10,000 registrations** on a single event. Both are far past what this
plugin's audience has — a village hall does not run ten thousand events — and
that is the point: a query that scans is invisible at fifty rows and fatal at
fifty thousand, and the only way to tell the two apart is to look.

| What | ms | queries | rows |
| --- | --: | --: | --: |
| Upcoming events, first page of 10 | 8.1 | 6 | 10 |
| Upcoming events, page 50 | 6.6 | 5 | 10 |
| Places taken on an event with 10,000 bookings | 2.1 | 2 | 10,000 |
| Attendee screen, first page of 25 | 9.0 | 1 | 25 |
| Attendee screen, searching 10,000 bookings | 17.8 | 1 | 1 |
| One booking by its reference | 0.1 | 1 | 1 |
| A month of the calendar | 19.4 | 3 | 87,418 |

Peak memory across the run: 122 MB, which is PHP's own baseline plus WordPress
rather than anything this plugin holds — none of these queries loads more than a
page of rows.

"Rows" is in the table on purpose. **A measurement of a query that returned
nothing is a measurement of nothing**, and the first run of this benchmark
reported the archive at 1.2ms over zero results — the events had start times but
no date rows, and the archive joins the date rows. The row count is what made
that visible.

## What the benchmark found

**The calendar was 471 queries and 70ms for one month.** The grid builds an event
object for each occupied day, and one at a time that is a post read and a meta
read each — the classic N+1, invisible on the ten-event site anybody develops
against and the whole page on a real one. The month's events are now fetched
together before the grid renders, which takes it to **3 queries and 19ms**.

`tests/integration/CalendarQueryCountTest.php` holds it there: twenty events in a
month must cost no more queries than two do. The assertion is a ceiling rather
than an exact count, because pinning the exact number makes every unrelated
change a failing test — what matters is that the number does not follow the row
count.

## Why the numbers look like they do

**Nothing sorts on a `CAST`.** Start times are stored as `Y-m-d H:i:s` in UTC,
which sorts correctly as a string, so ordering an archive by date is an index
read rather than a scan.

**Capacity is counted, never cached.** Two queries at ten thousand bookings, and
a stale count oversells a seat — which is the one failure this plugin must not
have. See [ADR-0004](adr/0004-capacity-insert-then-rank.md).

**Registrations are a custom table, not posts.** Ten thousand bookings as a post
type would be ten thousand rows in `wp_postmeta` slowing every unrelated query on
the site, and capacity would be a `meta_query` join instead of one index lookup.

**Searching bookings is one query**, and it is the slowest thing here at 17.8ms,
because a `LIKE '%…%'` across name, email and reference cannot use an index. It
is bounded by the event's own bookings rather than the site's, and 18ms on ten
thousand is a price worth paying for a search that finds people by any of the
three things an organiser might have in front of them.

## What is not measured

**Concurrency.** These are single-request timings. What happens under a hundred
simultaneous bookings is covered instead by the race tests, which run eight
parallel processes against a capacity-1 event and assert exactly one wins.

**A real host.** This is Docker on a laptop, with MySQL on the same machine. Take
the shape of the numbers — flat where it should be flat — rather than the
absolute milliseconds.

**Page rendering.** These measure the queries and the objects, not a full
front-end request through a theme, which is dominated by whatever the theme does.
