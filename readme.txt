=== Quick Events Manager ===
Contributors: pankajanupam
Tags: events, event manager, calendar, registration, tickets
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 26.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create events, publish them, and take free registrations. Start simple and switch on more features only when you need them.

== Description ==

Quick Events Manager gives you events that behave like events: they sort by when they happen, they keep their own timezone, and they show up properly in search results.

It is free and open source, with no paid tier, no locked features and no upsells. Everything the plugin can do, it does for everybody.

= Start simple, add what you need =

Most event plugins hand you a dashboard full of settings for ticketing, payments and recurrence before you have created a single event. This one starts with events and nothing else.

When you want more, you switch it on yourself under **Events > Features**. Anything you leave off adds nothing to your site: no code runs, no database tables are created, and no scripts or styles are loaded. It is not a hidden menu — it is genuinely not there.

= What you get out of the box =

* Events with a start and end date and time
* A proper timezone per event, so a talk at 6pm in Kolkata stays at 6pm in Kolkata
* All-day events
* Online events with a joining link
* Venue and organiser details, with reusable venue and organiser records as an option
* Event categories and tags
* Featured images
* An event list that sorts by date, not by when you published it
* Search and category filtering
* "Add to calendar" downloads, plus Google Calendar links
* Rich results markup, so search engines can show your event's date and venue

= Switch on registration =

* A sign-up form that works with or without JavaScript
* A capacity, with an automatic waiting list once it is reached
* A closing date for registrations
* Confirmation emails to attendees and notifications to you
* An attendee list with search and status filtering
* CSV export

= Blocks and shortcodes =

Three blocks — Event List, Event Details and Event Registration — plus the matching shortcodes:

`[qevm_event_list limit="10" show="upcoming"]`
`[qevm_event_details id="123"]`
`[qevm_event_registration id="123"]`

The blocks render on the server, so your visitors download no extra JavaScript.

= Built to be worked on =

The code is on GitHub. Issues and pull requests are welcome.

https://github.com/pankajanupam-wp/quick-events-manager

= Privacy =

When somebody registers for an event, the plugin stores the name, email address, phone number and number of places they entered, so you can see who is coming. If they book several places and name the people taking them, those names are stored too.

Registering asks people to agree to that, in wording you control, and records which wording they agreed to and the moment they did. Nothing else about the agreement is kept.

It does not store IP addresses. It sends nothing to any external service — no tracking, no analytics, no calls home. Registrations are covered by WordPress's own privacy tools, so an export or erasure request includes them automatically.

== Installation ==

= From your dashboard =

1. Go to **Plugins > Add New**.
2. Search for "Quick Events Manager".
3. Click **Install Now**, then **Activate**.
4. Go to **Events > Add New** and create your first event.

= Manual installation =

1. Download the plugin and unzip it.
2. Upload the `quick-events-manager` folder to `/wp-content/plugins/`.
3. Activate the plugin through the **Plugins** menu.

= Taking registrations =

1. Go to **Events > Features** and switch on **Registration and attendees**.
2. Edit an event, tick **Let people register**, and set how many places there are.
3. The form appears on the event page.

== Frequently Asked Questions ==

= I used version 1.0. Will my events survive the update? =

Yes. Version 1.0 only created events — it stored no other data — and those events are carried over automatically the first time you load the admin after updating. Their web addresses do not change, so existing links and bookmarks keep working.

= Why did the events move to a different post type? =

Version 1.0 registered its events under the name `events`, which is generic enough that any other event plugin or theme using the same name would clash with it and one of them would silently lose. Version 26.0 uses a name specific to this plugin. Your `/events/` addresses are unchanged.

= Does the plugin handle timezones properly? =

Yes, and this is worth checking in anything you use. Each event stores the time you typed, the timezone you meant it in, and the equivalent moment in UTC. Sorting and filtering use UTC; display uses the event's own timezone. Changing your site's timezone does not move any event.

= Can I take payments? =

Yes. Payments sit behind a gateway interface, so no part of the event or registration system is tied to one provider. Nothing in this plugin ever handles card details — every gateway takes that off your site.

Like everything past the basics, it is off until you switch it on.

= Can I change how events look? =

Yes. Copy any file from the plugin's `templates` folder into a `quick-events-manager` folder inside your theme and edit it there. Your copy is used instead of the plugin's, and updating the plugin will not overwrite it.

= Will it slow my site down? =

It should not. Styles load only on pages that actually show an event, the blocks render on the server so there is no front-end JavaScript, and any feature you have not switched on does not run at all.

= Does it work with block themes? =

Yes. Event details are added through the content filter rather than a template takeover, which is what makes them work the same way in block themes and classic themes.

= Where do I report a bug? =

On GitHub: https://github.com/pankajanupam-wp/quick-events-manager/issues

== Screenshots ==

1. The event editor, with date, time and timezone.
2. Events > Features, where you switch capability on a level at a time.
3. An event page with details and a registration form.
4. The attendee list, with search, filtering and CSV export.

== Changelog ==

= 26.0 =

A complete rewrite. The 2012 plugin registered an event post type and nothing else; this version is an actual event manager.

Added:

* Start and end dates and times, with a real timezone per event
* All-day and online events
* Venue and organiser details, with reusable venue and organiser records as an option
* Event categories and tags
* Event list, details and registration blocks, with matching shortcodes
* Free registration with capacity, waiting list and closing date
* Group booking, with a name and a ticket reference for each person
* Attendee management with search, status filtering and CSV export
* Confirmation and notification emails
* "Add to calendar" .ics downloads and Google Calendar links
* schema.org Event markup for search engines
* A read-only REST API at `/wp-json/qevm/v1/`
* A Features screen for switching capability on a level at a time
* Privacy exporters and erasers for attendee data
* A consent checkbox with wording you control, recording what was agreed to and when
* Translation support

Changed:

* Events moved from the generic `events` post type to `qevm_event`, keeping their existing `/events/` addresses. Existing events are migrated automatically.
* Event archives now sort by start date instead of publish date.
* Minimum requirements are WordPress 6.5 and PHP 8.1.

== Upgrade Notice ==

= 26.0 =
A complete rewrite of a plugin that had not been updated since 2012. Your existing events and their web addresses are preserved. Please back up before updating.
