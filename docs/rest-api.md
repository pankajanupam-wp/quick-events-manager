# REST API

Namespace `qevm/v1`. Read-only, with **one** write route: checking somebody in.

## Why almost read-only

The post type is registered with `show_in_rest`, so core already serves authenticated CRUD at `/wp/v2/qevm_event`, with the permission handling the block editor depends on. Hand-rolling a second write path would mean a second permission surface to audit, for no benefit.

What core does *not* give is an event-shaped read — dates resolved into the event's own timezone, the venue flattened, upcoming/past filtering. That is what these endpoints are for.

To create or update events programmatically, use `/wp/v2/qevm_event` with an application password.

## The exception: check-in

`POST /qevm/v1/checkins` exists because core has no route for it and cannot: the table is this plugin's and so is the decision. What needs to call it is a scanner at a door that is **not** a browser holding an admin cookie — a phone app, a second screen, a hardware reader posting from a kiosk. The alternative is that everybody screen-scrapes `admin-post.php`, which is worse in every direction.

It is registered by the check-in module, so with that module switched off the route does not exist rather than returning a permission error.

```
POST /qevm/v1/checkins            manage_qevm_checkins   { "ticket_code": "QEVT-…" }
GET  /qevm/v1/events/{id}/attendance   manage_qevm_checkins   ?occurrence_id=
```

**A second scan of the same ticket is a 200, not an error.** It returns `result: "already"` with the time of the first arrival. A scanner that treats the second scan as a failure is a scanner that beeps angrily at a queue — and the second scan is the most common thing that happens at a real door. An unknown code is a 404, a cancelled or waitlisted booking is a 409, and both carry a message meant to be read aloud to the person standing there.

`GET /qevm/v1/events/{id}/attendance` returns everybody expected with a `present` flag rather than two lists. "Who has not arrived" and "who is still outside" are the same list filtered, and neither is worth a second shape. Without `occurrence_id` it reports the event's next date.

## `GET /wp-json/qevm/v1/events`

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
curl 'https://example.com/wp-json/qevm/v1/events?show=upcoming&per_page=5'
```

## `GET /wp-json/qevm/v1/events/{id}`

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
| `qevm_event_not_found` | 404 | No published event with that id |

## Stability

The response shape is additive from 26.0: fields may be added, but existing fields will not be removed or change meaning within a major version. Add your own with `qevm_rest_prepare_event`.


## `POST /qevm/v1/stripe/webhook`

Where Stripe posts what has happened to a payment. Public, because Stripe is not a logged-in user and never will be — **the signature is the authentication**, and it is checked before the body is read.

Appears only while the **Paid tickets** module is on. The address is shown on **Events → Settings → Money**, ready to paste into the Stripe dashboard.

| Answer | When |
| --- | --- |
| `200 {"received":true,"handled":true}` | A genuine delivery this plugin acted on |
| `200 {"received":true,"handled":false}` | A genuine delivery about something else — not an error |
| `400 {"received":false,"reason":"bad_signature"}` | Unsigned, forged, or more than five minutes old |
| `400 {"received":false,"reason":"not_configured"}` | No signing secret has been entered |

**Why 400 and not 401.** Stripe retries a 5xx for days and gives up on a 4xx. A delivery that cannot be authenticated is one that will never succeed, so asking for it again is pure noise.

**Why 200 for events we ignore.** Anything else teaches Stripe to retry, and eventually to disable the endpoint — which would take the events that matter with it.

Events acted on: `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`. A repeat delivery of any of them does nothing the second time.
