=== Quick Events Manager ===
Contributors: pankajanupam
Tags: events, event manager, calendar, registration, tickets
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 26.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Events that behave like events. Start with a date and a page; switch on registration, a calendar, tickets, payments or a door as you need them.

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
* Your own questions on the registration form
* Event categories and tags
* Featured images
* An event list that sorts by date, not by when you published it
* Search and category filtering
* "Add to calendar" downloads, plus Google Calendar links
* Rich results markup, so search engines can show your event's date and venue

= Switch on registration =

* A sign-up form that works with or without JavaScript
* A capacity, with an automatic waiting list once it is reached — strictly in the order people joined
* A closing date for registrations
* Confirmation emails carrying a calendar file, and notifications to you
* Editable email wording, and one message to everybody registered for an event
* An attendee list with search and status filtering, and CSV export
* A cancellation link, so "I can't come after all" takes one click
* Automatic deletion of old registrations, if you want it

= And, when you need them =

Each of these is off until you switch it on under **Events > Features**.

* **A calendar** — a month grid or a list, on any page
* **Your own questions** on the registration form, with the answers in the export
* **Reusable venues and organisers**, promoted from the addresses you have already typed
* **Repeating events** — daily, weekly, monthly or yearly, with per-date bookings. Move one date, call one off, or split a series from a date onwards
* **Ticket types** — Member and Guest, Full and Concession — each with its own capacity, price and sale window
* **Paid tickets through Stripe**, with seats held for twenty minutes while somebody pays, and full or partial refunds
* **Selling through WooCommerce** instead, if you already run a shop
* **Check-in** — a QR code on every confirmation and a door screen built for one hand and a phone with two bars of signal. It works with JavaScript switched off
* **Three roles** — Event Manager, Event Organizer and Event Staff. Hand a volunteer a phone for the evening and they can admit people and change nothing else

= Blocks and shortcodes =

Four blocks — Event List, Event Details, Event Registration and Event Calendar — plus the matching shortcodes:

`[qevm_event_list limit="10" show="upcoming"]`
`[qevm_event_details id="123"]`
`[qevm_event_registration id="123"]`
`[qevm_event_calendar]`

The blocks render on the server, so your visitors download no extra JavaScript.

= Built to be worked on =

The code is on GitHub. Issues and pull requests are welcome.

https://github.com/pankajanupam-wp/quick-events-manager

= Privacy =

When somebody registers for an event, the plugin stores the name, email address, phone number and number of places they entered, so you can see who is coming. If they book several places and name the people taking them, those names are stored too.

Registering asks people to agree to that, in wording you control, and records which wording they agreed to and the moment they did. Nothing else about the agreement is kept.

Deleting the plugin does **not** delete your events and attendees unless you ask it to. There is a setting for that under Events → Settings, and it is off: removing a plugin to try something else should not destroy a guest list.

It does not store IP addresses. There is no tracking, no analytics and no calls home, ever. Registrations are covered by WordPress's own privacy tools, so an export or erasure request includes them automatically.

= External services =

Out of the box the plugin contacts nothing at all. One optional feature changes that, and only after you have set it up:

**Stripe** — used only if you switch on **Paid tickets** and enter your own Stripe keys. Two things happen then, and only on a payment screen:

* The site asks Stripe (api.stripe.com) to create a payment for the amount, the currency, the order reference and the buyer's email address, so that Stripe can send its own receipt.
* The payment screen loads Stripe's script (js.stripe.com), which draws the card form. Stripe requires the script to be loaded from them, and it is what makes bank authentication work.

Card details are entered on Stripe's side and never reach this site. Nothing is sent to Stripe for a free event, on any other page, or before you have entered keys.

Stripe's terms are at https://stripe.com/legal and their privacy policy at https://stripe.com/privacy.

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

Yes, through Stripe. Payments sit behind a gateway interface, so no part of the event or registration system is tied to one provider and another can be added without touching the rest. Nothing in this plugin ever handles card details — the card is entered on the gateway's side.

An unpaid checkout holds its seats for twenty minutes and then gives them back, so an event is never sold out by people who closed the tab.

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
3. An event page, with the details, an add-to-calendar button and the registration form.
4. The attendee list, with search, filtering and CSV export.
5. The check-in door: one row per person, one big button, and a code you can type when the camera will not start.

== Changelog ==

= 26.0 =

A complete rewrite. The 2012 plugin registered an event post type and nothing else; this version is an actual event manager.

Added:

* Start and end dates and times, with a real timezone per event
* All-day and online events
* Venue and organiser details, with reusable venue and organiser records as an option
* Your own questions on the registration form
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
