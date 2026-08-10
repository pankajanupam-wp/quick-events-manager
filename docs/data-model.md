# Data model

Where everything lives, and why it lives there.

## Overview

```
WordPress tables                        Custom table
─────────────────────────               ─────────────────────────
wp_posts    qem_event  ─────────┐
wp_postmeta _qem_*              └─────► wp_qem_registrations
wp_terms    qem_event_category               event_id
            qem_event_tag                    user_id ──► wp_users
wp_options  qem_settings
            qem_enabled_modules
            qem_db_version
```

One rule decides the split: **things a site owner authors are posts; things visitors generate in volume are rows.**

An event is authored. It wants the block editor, media, revisions, taxonomies, permalinks and search — all of which WordPress gives a custom post type for free and none of which would be worth rebuilding.

A registration is generated, is never edited as prose, and arrives in bulk.

## Event

Custom post type `qem_event`.

| Field | Storage |
| --- | --- |
| Title, description, excerpt, featured image, author | Core post fields |
| Categories | Taxonomy `qem_event_category` (hierarchical) |
| Tags | Taxonomy `qem_event_tag` (flat) |
| Status | Core post status (`draft`, `publish`, `private`, `trash`) |
| Everything below | Post meta |

### Meta keys

Every key is prefixed `_qem_`, which both avoids collisions and hides them from the custom-fields box. `MetaTest` asserts the prefix on all of them, and that each has a sanitiser.

| Key | Type | Notes |
| --- | --- | --- |
| `_qem_start_utc` | string | `Y-m-d H:i:s` in UTC. **The only key ever sorted or queried on.** |
| `_qem_end_utc` | string | `Y-m-d H:i:s` in UTC |
| `_qem_start_local` | string | Wall-clock time as the organiser typed it |
| `_qem_end_local` | string | Wall-clock time as the organiser typed it |
| `_qem_timezone` | string | PHP timezone identifier, or a `+05:30` style offset |
| `_qem_all_day` | bool | Suppresses the time in display and in the `.ics` |
| `_qem_is_online` | bool | |
| `_qem_online_url` | string | Only exposed via REST to users who can edit the event |
| `_qem_venue_name` … `_qem_venue_country` | string | Flat address fields |
| `_qem_venue_id` | int | Reserved, always 0 in 26.0 — see below |
| `_qem_organizer_name`, `_qem_organizer_email`, `_qem_organizer_phone`, `_qem_organizer_url` | string | |
| `_qem_registration_enabled` | bool | Per-event, on top of the module toggle |
| `_qem_capacity` | int | 0 means unlimited |
| `_qem_registration_closes_utc` | string | `Y-m-d H:i:s` in UTC |

### Why three time fields

Storing only local time is the classic failure in event software: every event silently moves the day somebody changes the site timezone, and nobody notices until attendees arrive an hour late.

Storing only UTC is not enough either — "6pm in Kolkata" has to keep being 6pm in Kolkata when a visitor in Berlin reads it, so the intended zone has to be recorded, not derived from the site.

So three things are stored: what was typed, the zone it was meant in, and the resulting instant. UTC drives sorting and filtering; the local pair drives display.

`Y-m-d H:i:s` is zero-padded and big-endian, so lexical order is chronological order. That is what lets the archive `orderby => meta_value` without a `CAST`. `MetaTest::test_stored_format_sorts_chronologically_as_a_string` asserts the property rather than trusting it.

### Why `_qem_venue_id` exists but is unused

26.0 stores venues as flat fields, which is all a single event needs and avoids two extra admin screens on day one. Reusable venue records are a separate module in the same release.

Registering the id column now means that module can back the existing fields with real records without a schema change or a second migration. It costs one unused meta key today and saves a breaking change later.

## Registration

Custom table `{prefix}qem_registrations`.

```sql
CREATE TABLE {prefix}qem_registrations (
    id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    event_id    bigint(20) unsigned NOT NULL,
    user_id     bigint(20) unsigned NOT NULL DEFAULT 0,
    code        varchar(32)  NOT NULL,
    status      varchar(20)  NOT NULL DEFAULT 'confirmed',
    name        varchar(190) NOT NULL,
    email       varchar(190) NOT NULL,
    phone       varchar(50)  DEFAULT NULL,
    quantity    smallint(5) unsigned NOT NULL DEFAULT 1,
    fields      longtext     DEFAULT NULL,
    created_at  datetime NOT NULL,
    updated_at  datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY   code (code),
    KEY          event_status (event_id, status),
    KEY          event_email (event_id, email),
    KEY          user_id (user_id)
);
```

### Why not a post type

The question asked most often is *how many confirmed registrations does this event have*. Here that is:

```sql
SELECT SUM(quantity) FROM wp_qem_registrations
 WHERE event_id = %d AND status IN ('confirmed','pending')
```

— one lookup on `event_status`. Modelled as a post type it is a `meta_query` join against `wp_postmeta`, and a 500-person event adds several thousand rows to a table that every unrelated `WP_Query` on the site then walks past.

### Column notes

- **`varchar(190)`** on `name` and `email` keeps the indexed columns inside the 767-byte index limit MySQL below 5.7 enforces on `utf8mb4`. 190 × 4 = 760.
- **`code`** is a human-readable reference like `QEM-7F3K9A2M`, generated from an alphabet with no `0`/`O` or `1`/`I`, so it survives being read aloud or copied off a screen at a check-in desk. Unique-indexed and re-rolled on collision.
- **`fields`** is JSON, holding the answers to custom registration fields (a separate module in the same release). It is JSON rather than a meta table because nothing queries on it — the moment something needs to, it earns a table.
- **`user_id`** is 0 for logged-out registrations, which is the normal case.
- **No IP address column.** Rate limiting uses a salted hash of the address as a transient key, so no address is ever written anywhere. It keeps the privacy story short and the WordPress.org disclosure honest.

### Statuses

| Status | Occupies a place? | Meaning |
| --- | --- | --- |
| `confirmed` | yes | Has a place |
| `pending` | yes | Holds a place, awaiting something (reserved for paid tickets) |
| `waitlisted` | no | Event was full; next in line |
| `cancelled` | no | Frees the place |

`Registration::occupying_statuses()` is the single definition of which statuses count, so the capacity query and the display counts cannot drift apart.

### The table is created lazily

Not at plugin activation, but the first time the Registration module is switched on. A site that only publishes a calendar never grows it.

Switching the module back off **never** drops it. A site owner turning registration off to simplify their admin expects to find their attendees still there when they turn it back on. The table is only dropped by `uninstall.php`, and only when the plugin is deleted outright.

## Options

| Option | Autoloaded | Contents |
| --- | --- | --- |
| `qem_enabled_modules` | yes | Array of module ids that are switched on |
| `qem_settings` | yes | Display and notification settings |
| `qem_db_version` | yes | Schema version, compared on `admin_init` |
| `qem_migrated_legacy_post_type` | yes | Records that the 1.0 migration has run |

All four are small. Nothing large is autoloaded.

## Capabilities

The post type uses a custom capability type rather than mapping onto `post`:

```
edit_qem_event            read_qem_event             delete_qem_event
edit_qem_events           publish_qem_events         delete_qem_events
edit_others_qem_events    delete_others_qem_events   read_private_qem_events
edit_published_qem_events delete_published_qem_events
manage_qem_registrations
```

Granted to `administrator` and `editor` on activation.

Mapping onto `post` would have been one line shorter and would have made a check-in-only staff role impossible without also handing out the right to edit blog posts. `manage_qem_registrations` is separate for the same reason: seeing the attendee list is a different permission from editing the event.

## Entity relationships

```
      wp_users
         │ 0..1
         │
         ▼
  qem_registrations ──────► qem_event (post)
      many                     one
         │
         └── code (unique)  the public reference an attendee is given

  qem_event ──────► qem_event_category   (many-to-many, hierarchical)
            ──────► qem_event_tag        (many-to-many, flat)
```

A registration belongs to exactly one event. Deleting an event does not cascade — `Repository::delete_for_event()` exists but is only called deliberately, because silently destroying an attendee list when somebody trashes an event by accident is not recoverable.
