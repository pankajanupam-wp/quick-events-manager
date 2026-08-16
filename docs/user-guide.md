# User guide

## Creating your first event

**Events → Add New.**

Give it a title and a description, then fill in the **Event Details** box:

- **Starts** and **Ends** — the date and time as you would say them out loud
- **All-day event** — tick this and the times disappear
- **Timezone** — the zone you meant those times in

That last one matters more than it looks. Say your site is set to New York but your event is at 6pm in Kolkata: set the timezone to Asia/Kolkata and it stays 6pm in Kolkata, whatever your site is set to and wherever a visitor is reading from. Changing your site's timezone later will not move it.

For anything online, tick **This is an online event** and paste the joining link. The venue fields disappear, because they do not apply.

Location and organiser details are folded away under **Location and organiser** — open it only if you need them.

Publish, and the event is live at `yoursite.com/events/your-event-name/`.

## Showing events on a page

Add the **Event List** block wherever you want a list. In the sidebar you can set how many events, how many columns, upcoming or past, and a category.

There is also an automatic archive of every event at `yoursite.com/events/`.

If you use the classic editor, the shortcodes do the same thing:

```
[qevm_event_list limit="10" show="upcoming" columns="2"]
[qevm_event_details id="123"]
[qevm_event_registration id="123"]
```

## Taking registrations

Registration is off to start with, because plenty of sites only want to publish a calendar.

**Events → Features**, tick **Registration and attendees**, save.

Then edit the event you want sign-ups for. There is now a **Registration** box:

- **Let people register** — the switch for this event
- **Places available** — 0 means unlimited
- **Registration closes** — leave empty to accept sign-ups right up to the start

The form appears on the event page. It works with JavaScript turned off.

### Booking for more than one person

Anyone booking more than one place is asked for a name per place, up to twenty. Names are optional — somebody reserving three places for their team often does not know yet who is coming, and the booking goes through either way.

Each place becomes a person of its own with their own ticket reference, whether or not it has a name against it. That is what makes it possible to check people in one at a time rather than marking a whole booking as arrived.

The name fields appear as the number of places changes, which needs JavaScript. Without it the booking still works and still reserves the right number of places; only the names go uncollected.

### Consent

The form asks people to tick a box agreeing to their details being stored, and records what they agreed to and when. You can reword it under **Events → Settings → Privacy** — link your privacy policy there if you have one.

Editing the wording gives it a new version, and each registration keeps the version it agreed to, so you can tell who saw which text. Earlier wordings are not kept, so keep your own copy if you change it and might need to produce the old one.

Clearing the box empties the wording and stops the plugin asking for consent at all. Nothing is then recorded about it, which is the honest outcome — not a blank consent record.

### When the event fills up

Nothing breaks. Once the places are gone, further sign-ups become **waitlisted** — the form still works, but it says so, and those people are held in order. If somebody cancels, you can promote whoever is next from the attendees screen.

You are never oversold: two people clicking Register at the same moment for the last place cannot both get it.

## Reusing a venue

If you keep typing the same address, turn venues on: **Events → Features → Reusable venues**.

You then get **Events → Venues**, where a venue is a name, an address, and optionally a description and a photo. On any event there is a **Venue** box in the sidebar — pick one and its address is used instead of the fields under **Location and organiser**.

Switching it on also tidies up what you already have. The next few admin pages you load will work through your existing events in the background and turn the addresses on them into venues, matching events that share an address so twelve monthly meetups at one hall end up sharing one venue rather than making twelve. You will see a notice when it has finished. Events with no venue *name* are left alone — there is nothing sensible to call a venue made from a street and a postcode — as are online events.

This happens once. Afterwards, whether an event uses a venue record is your choice per event, and nothing goes back and changes it.

Two things worth knowing, because they are the questions people ask:

**You do not have to use it.** Leaving the box on **Address on this event** is a normal, permanent choice, not something half-finished. A church hall booked one afternoon does not need a record; type the address on the event and move on. Venues are for the places you use again.

**Nothing is lost if you change your mind.** Each event keeps its own copy of the address, so switching the feature off later — or deleting a venue you no longer use — leaves every event still showing where it is. Turning the feature back on picks up where you left off.

Venues have no pages of their own on your site. They exist to fill in addresses, not to be visited.

## Reusing an organiser

The same idea for the people or groups who run your events: **Events → Features → Reusable organisers**, then **Events → Organisers**, then pick one in the **Organiser** box on any event.

It is a separate switch from venues on purpose. Plenty of sites use the same three halls over and over and have exactly one organiser — themselves — and a whole screen listing one record is filing for its own sake.

Everything else works the way venues do: your existing events are swept once and matched on all their details together, the contact stays on the event so nothing is lost if you switch it off, and events with an email address but nobody's name against them are left alone.

## Copying an event

A monthly meetup is the same event with a different date. On **Events**, hover a row and choose **Duplicate**.

The copy gets the title (marked "(copy)"), description, dates, venue, organiser, capacity, categories, tags and every custom field. It does **not** get the original's bookings — those belong to the event people actually registered for.

The copy is always a **draft**, even when the original is published, because it arrives carrying the original's date. Change the date, then publish.

## Managing attendees

**Events → Attendees**, then pick your event.

You get everyone who has registered, with search across name, email and reference, and a status filter. Change anybody's status from the dropdown on their row:

- **Confirmed** — has a place
- **Pending** — holds a place, awaiting something
- **Waitlisted** — next in line if a place frees up
- **Cancelled** — frees their place

**Export CSV** downloads the list. It opens correctly in Excel including names with accents or non-Latin scripts, which most exports get wrong.

### Adding somebody yourself

Phone calls, walk-ins and a sign-up sheet at the door are how a lot of people actually register. At the bottom of the attendee list there is **Add an attendee** — name, email, phone and how many places.

Three things worth knowing:

- **Capacity still applies.** Adding somebody to a full event puts them on the waiting list, the same as anybody else. The room is the same size whichever way they got into it.
- **It works when registration is closed**, and on an event that is still a draft. That is the point: the call always comes after the closing date.
- **No consent record is stored.** The person never saw your consent wording, so the plugin will not claim they agreed to it. Make sure you have their permission to keep their details — a made-up consent record is worse than none, because it looks real in an audit.

They are emailed a confirmation unless you untick the box.

### Sending a confirmation again

Every row has a **Resend** button. Use it when somebody has lost their email or mistyped their address and you have corrected it.

Resending sends the confirmation and does nothing else — no new booking, no second place taken, no second notification to you.

### When registration closes

Once an event has happened, or its closing date has passed, the form is replaced by a short explanation rather than disappearing. A page that simply has no form on it looks broken, and the organiser gets the email asking why.

An event you never switched registration on for shows nothing at all — nothing was offered, so there is nothing to explain.

## When somebody cannot come

Every confirmation email contains a link the attendee can use to cancel. They do not need an account, and you do not have to do it for them.

Following the link shows their booking and asks them to confirm — it never cancels on the first click, because mail scanners and link previewers follow URLs in email without anybody reading them.

The link stops working when the event ends. Cancelling a booking for something that has already happened frees nothing and throws away a useful record of who came.

### The waiting list moves on its own

When a place is given back, the longest-waiting booking that fits is confirmed automatically and told by email.

It is strictly in order of joining. If the next person is waiting for three places and only one has come free, the place waits for them rather than going to somebody who joined later — a waiting list people can be overtaken in is not really a waiting list. Cancelling a *waitlisted* booking promotes nobody, because it was never holding a place.

### If something is wrong with the form

Mistakes are shown twice: once as a list at the top of the form, and again beside the field they belong to. The form keeps what was typed, so nobody retypes their details to fix one character, and the cursor moves to the first field that needs attention.

None of that needs JavaScript. With scripting off the form still submits, still reports errors and still confirms — the only thing lost is the per-guest name fields, and a note in the form says so.

## Emails

When somebody registers, they get a confirmation with the event details and their reference number, and you get a notification.

Notifications go to your site's admin email unless you set a different one under **Events → Settings**.

Email templates are editable under the email templates module. If your emails are not arriving at all, that is almost always the host rather than this plugin — an SMTP plugin usually fixes it.

## Add to calendar

Every event page has an **Add to calendar** button that downloads an `.ics` file, plus a Google Calendar link. These work in Apple Calendar, Outlook, Google Calendar and anything else that reads iCalendar.

All-day events are added as all-day, not as a midnight appointment.

## Search engines

Events automatically include `schema.org` markup, so Google can show the date and venue directly in results. There is nothing to configure. You can check any event with Google's Rich Results Test.

## Changing how events look

Copy any file from the plugin's `templates` folder into a folder called `quick-events-manager` inside your theme, and edit your copy. It will be used instead of the plugin's, and updating the plugin will not overwrite it.

For example, to change how event cards look, copy `templates/event-list.php` to `wp-content/themes/your-theme/quick-events-manager/event-list.php`.

## Settings

**Events → Settings** is deliberately short:

- **Event details** — whether the date and location appear automatically above the description. Turn it off if you would rather place them yourself with the Event Details block.
- **Events per page** — how many the archive shows.
- **Send notifications to** — leave empty to use your site's admin email.

## Troubleshooting

**Event times look wrong.** Check the event's timezone field, not your site's. The event's own zone is what visitors see.

**The event page is 404.** Go to **Settings → Permalinks** and click Save. That rebuilds the URL rules; nothing else is needed.

**The registration form is not showing.** Three things have to be true: the module is on under **Events → Features**, **Let people register** is ticked on that event, and the event has not finished.

**Registrations are not arriving by email.** The registrations are still recorded — check **Events → Attendees**. Missing email is nearly always the host blocking `wp_mail()`; an SMTP plugin fixes it.

**My old events from the 2012 version are missing.** They are migrated automatically the first time you load the admin after updating, and keep their original web addresses. If they are not showing, see [migration.md](migration.md).

## Getting help

- [Support forum](https://wordpress.org/support/plugin/quick-events-manager/) for questions
- [GitHub issues](https://github.com/pankajanupam-wp/quick-events-manager/issues) for bugs and feature requests
