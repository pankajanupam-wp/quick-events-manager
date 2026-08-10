# Hooks

Every action and filter the plugin provides. All are stable from 26.0 unless noted.

## Modules

### `qem_modules` (filter)

The extension point for add-on plugins. Return your own object implementing `QEM\Modules\Module` and it gains a toggle on the Features screen, an activation routine, and the same enabled/disabled guarantees as a bundled module.

```php
add_filter( 'qem_modules', function ( $modules ) {
	$modules[] = new My_Plugin_Ticketing_Module();

	return $modules;
} );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$modules` | `Module[]` | Module instances |

### `qem_module_enabled` / `qem_module_disabled` (actions)

Fire after a module has been switched on (and its `activate()` has run) or off.

| Parameter | Type | Description |
| --- | --- | --- |
| `$id` | `string` | Module id |

## Events

### `qem_post_type_args` (filter)

The arguments passed to `register_post_type()`.

Changing `rewrite` or `has_archive` changes every event URL on the site, so be sure.

| Parameter | Type | Description |
| --- | --- | --- |
| `$args` | `array` | Post type arguments |

## Front end

### `qem_template_path` (filter)

The resolved path of a template, checked only after the theme has had its chance. Use this to ship templates from a plugin.

```php
add_filter( 'qem_template_path', function ( $path, $template ) {
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

### `qem_schema_data` (filter)

The `schema.org/Event` JSON-LD emitted on single event pages. Return an empty array to emit nothing.

| Parameter | Type | Description |
| --- | --- | --- |
| `$data` | `array` | JSON-LD data |
| `$event` | `QEM\Events\Event` | The event |

## Registration

### `qem_registration_created` (action)

Fires once a registration has been stored. Both confirmation emails hang off this, so removing them stops the email without touching the rest of the flow:

```php
remove_action( 'qem_registration_created', array( $emails, 'send_attendee_confirmation' ), 10 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$registration` | `QEM\Registration\Registration` | The stored registration |
| `$event` | `QEM\Events\Event` | The event registered for |

### `qem_registration_status_changed` (action)

Fires after an administrator changes a status on the attendees screen.

| Parameter | Type | Description |
| --- | --- | --- |
| `$id` | `int` | Registration id |
| `$status` | `string` | New status |

### `qem_registration_is_open` (filter)

The last word on whether an event is accepting registrations. Runs after the built-in checks (module on, per-event setting on, event not finished, closing date not passed), so it can only close registration that was otherwise open — not force it open.

```php
// Members only.
add_filter( 'qem_registration_is_open', function ( $is_open, $event ) {
	return $is_open && is_user_logged_in();
}, 10, 2 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$is_open` | `bool` | Whether registration is open |
| `$event` | `QEM\Events\Event` | The event |

### `qem_registration_rate_limit` (filter)

How many registrations one address may submit per five minutes. Defaults to 30. Return 0 to switch rate limiting off entirely — reasonable when registration happens at a staffed desk on one connection.

The default is deliberately loose: offices, universities and conference venues put every visitor behind one NAT gateway, and those are exactly the places that run events.

| Parameter | Type | Description |
| --- | --- | --- |
| `$limit` | `int` | Submissions allowed per window |

### `qem_attendee_email` / `qem_organizer_email` (filters)

The emails sent after a registration. Return an empty `to` to suppress one.

```php
add_filter( 'qem_attendee_email', function ( $email, $registration, $event ) {
	$email['headers'][] = 'Content-Type: text/html; charset=UTF-8';
	$email['body']      = my_html_template( $registration, $event );

	return $email;
}, 10, 3 );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$email` | `array` | Keys: `to`, `subject`, `body`, `headers` |
| `$registration` | `QEM\Registration\Registration` | The registration |
| `$event` | `QEM\Events\Event` | The event |

## Migration

### `qem_legacy_posts_migrated` (action)

Fires after 1.0's `events` posts have been moved to `qem_event`, and only if at least one was moved.

| Parameter | Type | Description |
| --- | --- | --- |
| `$migrated` | `int` | Number of posts updated |

## REST

### `qem_rest_prepare_event` (filter)

An event's REST representation, before it is returned.

Be careful what you add. The response is public, so anything included here is readable by anonymous callers — which is why the joining link of an online event is only added for users who can edit that event.

| Parameter | Type | Description |
| --- | --- | --- |
| `$data` | `array` | Prepared data |
| `$event` | `QEM\Events\Event` | The event |
