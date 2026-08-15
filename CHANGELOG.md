# Changelog

All notable changes are documented here. This project uses year-based versioning: `26.0` is the first release of 2026.

## [26.0] — in development

A complete rewrite. The 2012 plugin registered an event post type and nothing else; this version is an actual event manager.

It ships as one release. Nothing is published until the whole of [docs/roadmap.md](docs/roadmap.md) is done — the entries below are what is finished so far, not a shipped version.

### Added

- Events with start and end dates and times, and a real timezone per event
- All-day events and online events with a joining link
- Venue and organiser details, with reusable venue records as an option
- Event categories and tags
- A Features screen for switching capability on a module at a time
- Free registration with capacity, automatic waiting list and a closing date
- Attendee management with search, status filtering and CSV export
- Confirmation emails to attendees and notifications to the organiser
- Event List, Event Details and Event Registration blocks, with matching shortcodes
- "Add to calendar" `.ics` downloads and Google Calendar links
- `schema.org/Event` JSON-LD for search engines
- A read-only REST API at `/wp-json/qevm/v1/`
- GDPR exporters and erasers for attendee data
- Translation support

### Still to come in this release

Calendar view, custom registration fields, email templates, reusable venues and organisers, ticketing, QR codes and check-in, payments, WooCommerce integration, and recurring events. See [docs/roadmap.md](docs/roadmap.md) for the build order.

### Changed

- Events moved from the generic `events` post type to `qevm_event`, keeping their existing `/events/` URLs. Existing events are migrated automatically on first admin load.
- Event archives sort by start date rather than publish date.
- Minimum requirements raised to WordPress 6.5 and PHP 8.1.

### Security

- Every database query is prepared; every output is escaped
- Custom capabilities rather than mapping onto `post`
- Registration is nonce-protected, rate-limited and honeypotted
- CSV exports defuse cells that a spreadsheet would execute as formulas
- No IP addresses are stored

## [1.0] — 2012-02-14

- Beta release: registered an `events` custom post type.
