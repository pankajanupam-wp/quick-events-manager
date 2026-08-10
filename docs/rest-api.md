# REST API

Namespace `qem/v1`. Read-only.

## Why read-only

The post type is registered with `show_in_rest`, so core already serves authenticated CRUD at `/wp/v2/qem_event`, with the permission handling the block editor depends on. Hand-rolling a second write path would mean a second permission surface to audit, for no benefit.

What core does *not* give is an event-shaped read — dates resolved into the event's own timezone, the venue flattened, upcoming/past filtering. That is what these endpoints are for.

To create or update events programmatically, use `/wp/v2/qem_event` with an application password.

## `GET /wp-json/qem/v1/events`

Public. Returns published events.

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `show` | string | `upcoming` | `upcoming`, `past` or `all` |
| `per_page` | int | 10 | 1–100 |
| `page` | int | 1 | |
| `category` | string | — | Category slug |
| `search` | string | — | Matches title and content |

Pagination is returned in headers: `X-WP-Total` and `X-WP-TotalPages`.

An event counts as upcoming until it *ends*, so a three-day conference on its second day is still listed rather than disappearing the moment it starts.

```sh
curl 'https://example.com/wp-json/qem/v1/events?show=upcoming&per_page=5'
```

## `GET /wp-json/qem/v1/events/{id}`

Public. 404s for anything that is not a published event.

## Response

```json
{
  "id": 11,
  "title": "Kolkata Conference",
  "excerpt": "A day of talks.",
  "url": "https://example.com/events/kolkata-conference/",
  "image": "https://example.com/wp-content/uploads/2026/09/hall.jpg",
  "start_utc": "2026-09-01 12:30:00",
  "end_utc": "2026-09-01 15:30:00",
  "timezone": "Asia/Kolkata",
  "all_day": false,
  "is_online": false,
  "has_ended": false,
  "venue": {
    "name": "Science City",
    "address": "JBS Haldane Ave",
    "city": "Kolkata",
    "region": "West Bengal",
    "postal": "700046",
    "country": "India"
  },
  "organizer": { "name": "WordPress Kolkata", "url": "https://example.com" },
  "categories": [ "Conferences" ]
}
```

### Reading the times

`start_utc` and `end_utc` are UTC instants. `timezone` is the zone the organiser meant the event in.

To display an event the way its organiser intended, convert the UTC value into `timezone` — not into the viewer's zone, and not into the site's:

```js
new Intl.DateTimeFormat( 'en-GB', {
	timeZone: event.timezone,
	dateStyle: 'full',
	timeStyle: 'short',
} ).format( new Date( event.start_utc.replace( ' ', 'T' ) + 'Z' ) );
```

When `all_day` is true, ignore the time portion entirely.

### `online_url`

Only present when the authenticated user can edit that event. A meeting link is an open door into the meeting, so it is not in the public response.

## Errors

| Code | HTTP | Meaning |
| --- | --- | --- |
| `qem_event_not_found` | 404 | No published event with that id |

## Stability

The response shape is additive from 26.0: fields may be added, but existing fields will not be removed or change meaning within a major version. Add your own with `qem_rest_prepare_event`.
