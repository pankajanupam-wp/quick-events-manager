# Hooks

Every action and filter the plugin provides. All are stable from 26.0 unless noted.

## Modules

### `qevm_modules` (filter)

The extension point for add-on plugins. Return your own object implementing `QuickEventsManager\Modules\Module` and it gains a toggle on the Features screen, an activation routine, and the same enabled/disabled guarantees as a bundled module.

```php
add_filter( 'qevm_modules', function ( $modules ) {
	$modules[] = new My_Plugin_Ticketing_Module();

	return $modules;
} );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$modules` | `Module[]` | Module instances |

### `qevm_module_enabled` / `qevm_module_disabled` (actions)

Fire after a module has been switched on (and its `activate()` has run) or off.

| Parameter | Type | Description |
| --- | --- | --- |
| `$id` | `string` | Module id |

## Events

### `qevm_post_type_args` (filter)

The arguments passed to `register_post_type()`.

Changing `rewrite` or `has_archive` changes every event URL on the site, so be sure.

| Parameter | Type | Description |
| --- | --- | --- |
| `$args` | `array` | Post type arguments |

### `qevm_venue_post_type_args` (filter)

The arguments passed to `register_post_type()` for `qevm_venue`. Only fires while the venues module is enabled.

Venues are registered with no public URLs — `public`, `publicly_queryable`, `has_archive`, `rewrite` and `query_var` are all off — because a generated venue page is a heading and an address, and "every event at this venue" deserves a design rather than a post type default. Turning them back on through this filter works, but the post type registers no rewrite rules, so nothing will have flushed them:

```php
add_filter(
	'qevm_venue_post_type_args',
	function ( $args ) {
		$args['public']             = true;
		$args['publicly_queryable'] = true;
		$args['has_archive']        = 'venues';
		$args['rewrite']            = array( 'slug' => 'venues', 'with_front' => false );

		return $args;
	}
);
```

Visit **Settings → Permalinks** once after adding that, or the new URLs return 404 until something else flushes.

| Parameter | Type | Description |
| --- | --- | --- |
| `$args` | `array` | Post type arguments |

### `qevm_organizer_post_type_args` (filter)

The arguments passed to `register_post_type()` for `qevm_organizer`. Only fires while the organisers module is enabled.

Registered with no public URLs, for the same reasons as venues and one more: a generated page carrying somebody's name, email address and phone number is a page nobody asked for and a scraper will thank you for.

| Parameter | Type | Description |
| --- | --- | --- |
| `$args` | `array` | Post type arguments |

### `qevm_promote_event_record` (filter)

Whether an event's existing fields become a reusable record during the one-off promotion sweep that runs when a records module — venues, organisers — is first switched on. Shared by both, so check `$record` if you only mean one of them.

Events are skipped already when they are online, when they already point at a venue, or when the address has no venue *name* — there is nothing to title a record built from a street and a postcode. This filter is for the rest. Returning `false` leaves the address exactly where it is, which costs the event nothing.

```php
// Do not promote addresses from events that finished years ago.
add_filter(
	'qevm_promote_event_record',
	function ( $promote, $event_id, $parts, $record ) {
		if ( \QuickEventsManager\Venues\Venue::class !== $record ) {
			return $promote;
		}

		$end = get_post_meta( $event_id, '_qevm_end_utc', true );

		return $end && $end < gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) ) ? false : $promote;
	},
	10,
	4
);
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$promote` | `bool` | Whether to promote |
| `$event_id` | `int` | Event being examined |
| `$parts` | `array` | Values, keyed by meta key |
| `$record` | `string` | Record class being promoted to |

### `qevm_record_created_from_event` (action)

Fires when a promotion sweep creates a record. Once per distinct set of values, not once per event — twelve events at one address fire this once.

| Parameter | Type | Description |
| --- | --- | --- |
| `$post_id` | `int` | The new record |
| `$parts` | `array` | Values it was built from |
| `$record` | `string` | Record class |

### `qevm_record_promotion_finished` (action)

Fires once, when a sweep has been through every event. It does not fire again if the module is switched off and on: promotion is for the backlog that predates the module, and afterwards the choice of whether an event uses a record belongs to the site owner.

| Parameter | Type | Description |
| --- | --- | --- |
| `$record` | `string` | Record class that was promoted to |
| `$events` | `int` | Events given a record |
| `$records` | `int` | Records created |

### `qevm_event_duplicated` (action)

Fires after an event has been copied. The copy already has the original's meta, terms and occurrence rows; it does **not** have its registrations or attendees, and it never will — those belong to the original.

| Parameter | Type | Description |
| --- | --- | --- |
| `$copy_id` | `int` | The new event |
| `$original_id` | `int` | The event it was copied from |

### `qevm_duplicate_skipped_meta` (filter)

Meta keys a duplicate does not inherit. Defaults to WordPress's editing bookkeeping — `_edit_lock`, `_edit_last`, the trash and old-slug keys — because copying a lock tells the next person to open the copy that somebody else is already editing it.

Everything else is copied, including meta this plugin does not own. A Duplicate that silently drops an event's custom fields is worse than no Duplicate, because the loss is noticed later, by which time the original may have moved on.

| Parameter | Type | Description |
| --- | --- | --- |
| `$skipped` | `string[]` | Meta keys to leave behind |
| `$from` | `int` | Original post id |

## Email

Templates are edited under **Events → Email templates**, which appears when the Email templates module is on. A template left empty uses the wording the plugin ships with, so a site that never touches them keeps getting whatever the current release says.

The escaping rule is worth knowing before writing one: **the template's own markup is kept, and every placeholder value is escaped into it.** An HTML template runs values through `esc_html()`; a plain-text one strips tags from them. So `{attendee_name}` can never introduce markup, however it was typed into the registration form.

### `qevm_email_queued` (action)

Fires after a message has been added to the queue. The worker uses it to ask for a run in the next few seconds rather than leaving a confirmation until the next five-minute tick.

| Parameter | Type | Description |
| --- | --- | --- |
| `$id` | `int` | Queue id |
| `$message` | `array` | Message as it was queued |

### `qevm_email_attempted` (action)

Fires after one queued message has been handed to `wp_mail()`, whether or not it went. `$went` reports what `wp_mail()` returned, which means the message was accepted for delivery — not that it arrived.

| Parameter | Type | Description |
| --- | --- | --- |
| `$id` | `int` | Queue id |
| `$went` | `bool` | Whether `wp_mail()` accepted it |
| `$recipient` | `string` | Address it was for |

### `qevm_broadcast_queued` (action)

Fires after a message to an event's attendees has been put on the queue, from the **Email everybody** box at the bottom of the attendee screen.

Queued, not sent: nothing has left the site when this runs, and `$queued` counts rows the queue accepted rather than messages that arrived. Anything still waiting can be withdrawn until the worker reaches it.

```php
add_action(
	'qevm_broadcast_queued',
	function ( $event_id, $queued, $audience ) {
		error_log( sprintf( 'Event %d: %d message(s) queued for the %s audience.', $event_id, $queued, $audience ) );
	},
	10,
	3
);
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$event_id` | `int` | Event the message is about |
| `$queued` | `int` | Rows the queue accepted |
| `$audience` | `string` | `confirmed`, `waiting` or `everyone` |

Broadcasts are queued against the context `broadcast` with the event's id, and confirmations against `event` with the same id. That separation is deliberate: withdrawing a broadcast must not take back the booking confirmations sitting behind it in the queue.

Recipients are distinct addresses rather than bookings, so somebody who booked twice is emailed once, and people who cancelled are in no audience at all.

## Calendar

### `qevm_calendar_default_view` (filter)

Which view a visitor sees before they have chosen one. `'grid'` by default; return `'list'` to make the list the default.

The two views are equals, not a feature and its fallback — they read the same month, so they always hold the same events, and each links to the other. A site that knows its audience is better served by the list should be able to say so once rather than asking every visitor to switch every time.

```php
add_filter( 'qevm_calendar_default_view', function () {
	return 'list';
} );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$view` | `string` | `grid` or `list` |

## Tickets

### `qevm_event_ticket_types` (filter)

The ticket types an event offers. The ticketing module answers it while it is switched on, and nothing answers it when it is off — which is how every other part of the plugin knows whether an event has kinds of place at all.

```php
add_filter(
	'qevm_event_ticket_types',
	function ( $types, $event_id ) {
		// Add your own, or reorder what is there.
		return $types;
	},
	10,
	2
);
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$types` | `array` | Types supplied so far; empty by default |
| `$event_id` | `int` | The event being asked about |

Every type is returned, in display order, including withdrawn ones — a caller that wants only what is on sale asks the types themselves, because "withdrawn" and "outside its sale window" are different facts that need different words in front of somebody.

**This exists so that registration does not read the ticketing module's table.** Modules depend on the domain and not on each other ([ADR-0009](adr/0009-module-architecture.md)); the earlier shape had the registration code querying that table directly, which also meant switching ticketing off left the public form demanding a choice it no longer offered any way to make.

## Registration questions

### `qevm_registration_fields` (filter)

The custom questions asked on an event's form, in order. Return a `Field[]`; anything else in the array is dropped rather than trusted, because a stray value here would otherwise fatal every event page on the site.

| Parameter | Type | Description |
| --- | --- | --- |
| `$fields` | `Field[]` | Questions, in order |
| `$event_id` | `int` | Event id |

### `qevm_registration_fields_limit` (filter)

How many questions one event may ask. Defaults to 20 — not a technical limit, but a form with fifty questions is a form nobody finishes, and the organiser who built it will blame the plugin for their sign-up rate.

| Parameter | Type | Description |
| --- | --- | --- |
| `$limit` | `int` | Maximum number of questions |

### `qevm_registration_field_answers` (note)

There is no filter over stored answers. Values are checked against the question's own
definition before they are written — a choice question accepts only the choices it
offers — and a hook that let that be bypassed would put values in the export that were
never on the screen.

## Data retention

Off unless the site owner sets a period under **Events → Settings → Privacy**. Zero, the default, keeps registrations for ever.

### `qevm_retention_delete_event` (filter)

Whether one event's registrations are deleted by the retention sweep. Return `false` to keep them — an AGM whose attendance has to be minuted, a course whose certificates depend on the list.

The event is named rather than the registration because retention is a decision about an event's records as a whole.

```php
add_filter(
	'qevm_retention_delete_event',
	function ( $delete, $event_id ) {
		return has_term( 'agm', 'qevm_event_category', $event_id ) ? false : $delete;
	},
	10,
	2
);
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$delete` | `bool` | Whether to delete |
| `$event_id` | `int` | Event id |
| `$days` | `int` | Retention period in days |
| `$cutoff` | `string` | UTC datetime everything older than is being removed |

### `qevm_retention_swept_event` (action)

Fires after an event's registrations have been deleted by the sweep. This is the only record that it happened — nothing is written to the database, deliberately, because a log of who was removed is the personal data the feature exists to be rid of. Hook this if you want your own audit trail, and think about what you put in it.

| Parameter | Type | Description |
| --- | --- | --- |
| `$event_id` | `int` | Event id |
| `$removed` | `int` | Registrations deleted |

## Front end

### `qevm_template_path` (filter)

The resolved path of a template, checked only after the theme has had its chance. Use this to ship templates from a plugin.

```php
add_filter( 'qevm_template_path', function ( $path, $template ) {
	if ( 'event-card.php' === $template ) {
		return plugin_dir_path( __FILE__ ) . 'templates/event-card.php';
	}

	return $path;
}, 10, 2 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$path` | `string` | Absolute path to the plugin's copy |
| `$template` | `string` | Requested file name |

To override a template without code, copy it into `your-theme/quick-events-manager/` instead.

### `qevm_schema_data` (filter)

The `schema.org/Event` JSON-LD emitted on single event pages. Return an empty array to emit nothing.

| Parameter | Type | Description |
| --- | --- | --- |
| `$data` | `array` | JSON-LD data |
| `$event` | `QuickEventsManager\Events\Event` | The event |

## Registration

### `qevm_registration_created` (action)

Fires once a registration has been stored. Both confirmation emails hang off this, so removing them stops the email without touching the rest of the flow:

```php
remove_action( 'qevm_registration_created', array( $emails, 'send_attendee_confirmation' ), 10 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$registration` | `QuickEventsManager\Registration\Registration` | The stored registration |
| `$event` | `QuickEventsManager\Events\Event` | The event registered for |

### `qevm_registration_status_changed` (action)

Fires when a booking's status actually changes, whatever changed it — an administrator on the attendees screen, an attendee following a cancellation link, or your own code calling `Repository::update_status()`.

Fired from the repository rather than the call site. It used to fire in the attendees screen, which was correct for exactly as long as that screen was the only way to change a status; the moment cancellation links existed, a booking could be cancelled with nothing hearing about it.

**It does not fire when the status is set to what it already is.** Setting a cancelled booking to cancelled is a no-op, and announcing it would make somebody clicking their cancellation link twice look like two cancellations.

```php
add_action( 'qevm_registration_status_changed', function ( $id, $status, $previous ) {
	if ( RegistrationStatus::Cancelled === $status ) {
		// A place has just been given back.
	}
}, 10, 3 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$id` | `int` | Registration id |
| `$status` | `QuickEventsManager\Domain\RegistrationStatus` | Status it now has |
| `$previous` | `QuickEventsManager\Domain\RegistrationStatus` | Status it had before |

### `qevm_registration_self_cancelled` (action)

Fires after somebody cancels their own booking from a link in their confirmation email. Distinct from `qevm_registration_status_changed`, which also covers an administrator making the change.

| Parameter | Type | Description |
| --- | --- | --- |
| `$registration` | `QuickEventsManager\Registration\Registration` | The booking as it was before cancelling |
| `$event_id` | `int` | Event id |

### `qevm_registration_promoted` (action)

Fires when a booking is moved off the waiting list into a confirmed place. The promotion email is sent on this hook, so removing that callback leaves promotion silent — which is a worse outcome than not promoting at all, because the organiser is then counting on somebody who does not know they have a place.

| Parameter | Type | Description |
| --- | --- | --- |
| `$registration` | `QuickEventsManager\Registration\Registration` | The booking, now confirmed |
| `$event` | `QuickEventsManager\Events\Event` | The event |

### `qevm_promotion_email` (filter)

The email telling somebody they have come off the waiting list. Same four-key shape as `qevm_attendee_email`: `to`, `subject`, `body`, `headers`. An empty `to` suppresses the send.

### `qevm_waitlist_candidates` (filter)

Who is considered for promotion, and in what order. The default is the order people joined the list, oldest first.

Promotion is **strict first-in, first-out**: the loop takes candidates in the order this filter returns them and stops at the first booking that will not fit in the free places. It does not step over a booking to fit a smaller one behind it — that fills more seats, but it also means somebody who asked for three places watches every later single booking go in ahead of them and never gets in. Reordering here is how a site implements best-fit or priority tiers.

```php
// Smallest bookings first, filling as many seats as possible.
add_filter( 'qevm_waitlist_candidates', function ( $candidates ) {
	usort( $candidates, fn( $a, $b ) => $a->quantity() <=> $b->quantity() );

	return $candidates;
} );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$candidates` | `QuickEventsManager\Registration\Registration[]` | Waitlisted bookings, oldest first |
| `$event_id` | `int` | Event id |
| `$occurrence_id` | `int` | The date being filled, or `0` for an event with only one |
| `$ticket_type_id` | `int` | The kind of place being filled, or `0` when the event offers only one |

A queue belongs to a date **and** to a ticket type. A cancelled member ticket on the 3rd of June frees a member place on the 3rd of June, and nothing else — the guest queue and the following week are separate lines.

On a repeating event the queue belongs to a date. A cancellation on the 3rd of June frees a place on the 3rd of June, so only the people waiting for that date are considered — promoting somebody waiting for the 10th would hand them a place they cannot use and take it from whoever wanted that week.

### `qevm_cancellation_link_expiry` (filter)

When a cancellation link stops working. Defaults to the event's own end, because cancelling a booking for something that has already happened frees nothing and discards a useful attendance record. Events with no usable end date fall back to a year.

```php
// Stop accepting cancellations 24 hours before the event starts.
add_filter( 'qevm_cancellation_link_expiry', function ( $timestamp, $event ) {
	$start = strtotime( $event->start_utc() . ' UTC' );

	return $start ? min( $timestamp, $start - DAY_IN_SECONDS ) : $timestamp;
}, 10, 2 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$timestamp` | `int` | Unix timestamp |
| `$event` | `QuickEventsManager\Events\Event` | The event the link is for |

### `qevm_registration_is_open` (filter)

The last word on whether an event is accepting registrations. Closing it this way shows the visitor nothing by default — see `qevm_registration_closed_notice` to explain it. Runs after the built-in checks (module on, per-event setting on, event not finished, closing date not passed), so it can only close registration that was otherwise open — not force it open.

```php
// Members only.
add_filter( 'qevm_registration_is_open', function ( $is_open, $event ) {
	return $is_open && is_user_logged_in();
}, 10, 2 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$is_open` | `bool` | Whether registration is open |
| `$event` | `QuickEventsManager\Events\Event` | The event |

### `qevm_registration_closed_notice` (filter)

The explanation shown in place of the form once registration has closed. Keyed by reason.

Closing is four situations wearing one boolean, and only two of them are the visitor's business:

| Reason | Explained by default | Why |
| --- | --- | --- |
| `ended` | Yes | The event has happened |
| `expired` | Yes | The closing date has passed |
| `disabled` | No | Registration was never offered for this event, so there is nothing to explain |
| `filtered` | No | Somebody's own `qevm_registration_is_open` closed it, for a reason only that code knows |

Return an empty string for a reason to show nothing for it, or add a key of your own:

```php
add_filter( 'qevm_registration_closed_notice', function ( $messages, $event, $reason ) {
	$messages['filtered'] = __( 'Registration is open to members only.', 'my-plugin' );

	return $messages;
}, 10, 3 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$messages` | `array<string, string>` | Message by reason |
| `$event` | `QuickEventsManager\Events\Event` | The event |
| `$reason` | `string` | `ended`, `expired`, `disabled` or `filtered` |

### `qevm_registration_rate_limit` (filter)

How many registrations one address may submit per five minutes. Defaults to 30. Return 0 to switch rate limiting off entirely — reasonable when registration happens at a staffed desk on one connection.

The default is deliberately loose: offices, universities and conference venues put every visitor behind one NAT gateway, and those are exactly the places that run events.

| Parameter | Type | Description |
| --- | --- | --- |
| `$limit` | `int` | Submissions allowed per window |

### `qevm_consent_text` (filter)

The wording shown beside the consent checkbox on the registration form. Return an empty string to stop asking for consent at all.

The recorded consent version is a fingerprint of whatever this returns, so a wording that varies — per language, say — is recorded as the distinct wording it is rather than being credited to the text in the settings screen.

```php
// Ask in the visitor's language.
add_filter( 'qevm_consent_text', function ( $text ) {
	return 'de_DE' === get_locale()
		? 'Ich stimme der Speicherung meiner Daten zu.'
		: $text;
} );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$text` | `string` | The configured wording |

### `qevm_attendee_email` / `qevm_organizer_email` (filters)

The emails sent after a registration. Return an empty `to` to suppress one.

```php
add_filter( 'qevm_attendee_email', function ( $email, $registration, $event ) {
	$email['headers'][] = 'Content-Type: text/html; charset=UTF-8';
	$email['body']      = my_html_template( $registration, $event );

	return $email;
}, 10, 3 );
```

The attendee email also carries an `ics` key holding the event as an iCalendar document. Set it to an empty string to send the confirmation without a calendar file:

```php
add_filter( 'qevm_attendee_email', function ( $email ) {
	$email['ics'] = '';

	return $email;
} );
```

It is already empty for a waiting list place — an `.ics` asserts that this is happening and you are going to it, and putting a provisional place into somebody's calendar is how a person turns up to an event they were never confirmed for. They get one when they are promoted. It is also empty for an event with no date.

The file is attached through `phpmailer_init` rather than `wp_mail()`'s `$attachments` parameter, which takes file paths: the calendar exists only as a string, and writing it to disk to hand back a path means finding a writable directory on a host that may not have one, and cleaning up afterwards on a code path that can exit early. The listener is added immediately before the send and removed in a `finally` — `phpmailer_init` fires for **every** message the site sends, so one left attached would staple an event calendar to password resets.

| Parameter | Type | Description |
| --- | --- | --- |
| `$email` | `array` | Keys: `to`, `subject`, `body`, `headers`, and `ics` on the attendee email |
| `$registration` | `QuickEventsManager\Registration\Registration` | The registration |
| `$event` | `QuickEventsManager\Events\Event` | The event |

## Migration

### `qevm_legacy_posts_migrated` (action)

Fires after 1.0's `events` posts have been moved to `qevm_event`, and only if at least one was moved.

| Parameter | Type | Description |
| --- | --- | --- |
| `$migrated` | `int` | Number of posts updated |

## REST

### `qevm_rest_prepare_event` (filter)

An event's REST representation, before it is returned.

Be careful what you add. The response is public, so anything included here is readable by anonymous callers — which is why the joining link of an online event is only added for users who can edit that event.

| Parameter | Type | Description |
| --- | --- | --- |
| `$data` | `array` | Prepared data |
| `$event` | `QuickEventsManager\Events\Event` | The event |

## `qevm_occurrences_synced`

Fires after an event's occurrence rows have been regenerated from its post meta.

```php
add_action(
	'qevm_occurrences_synced',
	function ( $event_id, $result ) {
		// $result: array{inserted: int, updated: int, deleted: int, unchanged: int}
		error_log( sprintf( 'Event %d: %d occurrence(s) inserted.', $event_id, $result['inserted'] ) );
	},
	10,
	2
);
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$event_id` | `int` | The event whose dates were rebuilt |
| `$result` | `array` | What changed: `inserted`, `updated`, `deleted`, `unchanged`, `cancelled` |

### `qevm_occurrence_is_protected` (filter)

Whether a date the recurrence rule has stopped producing must be kept rather than removed. Return `true` and the date is marked cancelled instead of deleted.

The registration module answers this for any date with bookings on it, because deleting such a date destroys the only link between a booking and what it was for — the organiser finds out when twelve people arrive. Cancelling keeps the record and the attendee list and leaves telling them to the person who made the change.

An occurrence marked `is_exception` is kept without asking: it is a decision somebody made by hand, and a rule change should not throw it away silently.

```php
add_filter(
	'qevm_occurrence_is_protected',
	function ( $keep, $occurrence ) {
		if ( $keep ) {
			return true;
		}

		return my_plugin_has_something_attached_to( $occurrence->id() );
	},
	10,
	2
);
```

Respect an incoming `true` rather than overwriting it, so that several answers can coexist.

| Parameter | Type | Description |
| --- | --- | --- |
| `$keep` | `bool` | Whether something has already claimed it |
| `$occurrence` | `QuickEventsManager\Events\Occurrence` | The date about to be removed |

### Editing one date of a series

Four actions fire when "this occurrence" scope is used. **None of them sends any email**, and that is deliberate: the queue makes telling two hundred attendees trivially easy, which is exactly why it must not happen as a side effect of an organiser correcting one date. These hooks are where a site adds that if it wants it; the admin screen offers it as a next step instead.

| Hook | Fires when | Parameters |
| --- | --- | --- |
| `qevm_occurrence_moved` | One date is moved | `$occurrence_id`, `$from` (UTC), `$to` (UTC) |
| `qevm_occurrence_cancelled` | One date is called off | `$occurrence_id`, `$event_id` |
| `qevm_occurrence_reinstated` | A called-off date is put back on | `$occurrence_id`, `$event_id` |
| `qevm_occurrence_restored` | A date is handed back to its rule | `$occurrence_id`, `$event_id` |

```php
add_action(
	'qevm_occurrence_moved',
	function ( $occurrence_id, $from, $to ) {
		error_log( sprintf( 'Date %d moved from %s to %s.', $occurrence_id, $from, $to ) );
	},
	10,
	3
);
```

Restoring is the undo for all of them, and nothing stores an "original" to restore from: clearing `is_exception` hands the row back to the rule, and the regeneration rewrites it from the slot that was on the row all along.

### `qevm_series_split` (action)

Fires after "this and following" has split a series into two events. The dates from the split point onward have already moved to the new event, keeping their ids.

```php
add_action(
	'qevm_series_split',
	function ( $new_event_id, $original_event_id, $moved_occurrence_ids ) {
		// $moved_occurrence_ids belong to $new_event_id from now on.
	},
	10,
	3
);
```

| Parameter | Type | Meaning |
| --- | --- | --- |
| `$new_event_id` | `int` | The new event, holding the dates from the split point on |
| `$original_event_id` | `int` | The original, holding the dates before it |
| `$moved_occurrence_ids` | `int[]` | Every occurrence that changed hands |

**Anything storing an event id beside an occurrence id has to correct it here.** The occurrence rows moved; a table that also records which event a row belonged to is now describing the event it *used* to be part of, and nothing anywhere reports the disagreement — both tables stay internally consistent while the screens built on them quietly disagree. This is how the registration module keeps bookings with their dates, and it is why the split does not reach into that module itself: it may not be loaded.

Bookings with no occurrence on them are deliberately left alone. They were made for the series as it stood, and moving them would hand somebody's place to a half of the series they never chose.

Fires on every save of an event, including saves that changed nothing — in which case
every count is zero except `unchanged`. It also fires during
`wp qevm occurrence rebuild`.
