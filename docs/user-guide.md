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
[qem_event_list limit="10" show="upcoming" columns="2"]
[qem_event_details id="123"]
[qem_event_registration id="123"]
```

## Taking registrations

Registration is off to start with, because plenty of sites only want to publish a calendar.

**Events → Features**, tick **Registration and attendees**, save.

Then edit the event you want sign-ups for. There is now a **Registration** box:

- **Let people register** — the switch for this event
- **Places available** — 0 means unlimited
- **Registration closes** — leave empty to accept sign-ups right up to the start

The form appears on the event page. It works with JavaScript turned off.

### When the event fills up

Nothing breaks. Once the places are gone, further sign-ups become **waitlisted** — the form still works, but it says so, and those people are held in order. If somebody cancels, you can promote whoever is next from the attendees screen.

You are never oversold: two people clicking Register at the same moment for the last place cannot both get it.

## Managing attendees

**Events → Attendees**, then pick your event.

You get everyone who has registered, with search across name, email and reference, and a status filter. Change anybody's status from the dropdown on their row:

- **Confirmed** — has a place
- **Pending** — holds a place, awaiting something
- **Waitlisted** — next in line if a place frees up
- **Cancelled** — frees their place

**Export CSV** downloads the list. It opens correctly in Excel including names with accents or non-Latin scripts, which most exports get wrong.

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
