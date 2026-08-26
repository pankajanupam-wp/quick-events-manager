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

## Asking your own questions

**Events → Features → Custom registration questions**, then edit any event. There is a **Registration questions** box under the event details.

Each question has wording, an answer type — short answer, long answer, email, phone, number, date, a dropdown, a list to choose one from, yes/no, or choose-any — optional help text, and a position number. Tick **An answer is required** to refuse the form without one. Leave a question's wording blank to remove it.

Ordering is a number rather than dragging, so it works by keyboard and with a screen reader, and with JavaScript off.

Tick **The answer is sensitive** for anything health-adjacent — dietary needs, access requirements. It changes nothing about the form; it marks the answer so it is left out of exports unless you deliberately include it.

Renaming a question later is safe. Answers stay attached to it however much you reword it.

## Deleting old registrations automatically

Under **Events → Settings → Privacy** there is **Delete registrations after**. It is **0 by default, which keeps everything for ever**, and nothing is deleted until you change it.

Set a number of days and once a day the plugin removes the registrations for any event that finished that long ago, along with its attendees and their answers. It cannot be undone and no copy is kept.

Two things about how it counts:

- **From when the event ended, not from when somebody registered.** Somebody booking eleven months early is not deleted before they arrive.
- **An event with no date is never touched.** There is no answer to how long ago it finished.

The shortest period you can set is 7 days. If you need to keep one event's list — an AGM whose attendance is minuted, a course whose certificates depend on it — a developer can exclude it with the `qevm_retention_delete_event` filter.

Switching the registration feature off, or deactivating the plugin, stops the deletions. Neither removes anything that is already there.

## Events that repeat

Switch **Recurring events** on under **Events → Features**, and the event editor grows a **Repeats** box.

Tick **This event repeats** and say how often: every week, every two weeks, every month, every year. A weekly event can name the days it happens on — a class on Tuesdays and Thursdays is one event, not two. A monthly one can repeat on the same date each month or on the same weekday, "the last Friday", which is usually what a monthly meetup means.

Choose an ending: a date, a number of times, or none at all. Without an ending the dates are generated two years ahead and topped up daily, so a standing weekly meeting never runs out.

**Skip these dates** takes one date a line, as `2026-12-25`. Holidays, and the weeks you are away.

Everything is worked out in the event's own timezone, so an 18:00 class stays at 18:00 through the clocks changing.

If something in the box cannot be read — a number where a date belongs — the event still saves and the repeat rule is left exactly as it was, with a note at the top of the screen saying so. Nothing silently rebuilds your dates from a typo.

Unticking **This event repeats** turns the event back into a single date. Dates nobody has booked are removed; any date with a booking on it is marked cancelled instead, so the people who booked still see what happened to it.

### Booking one date of a repeating event

When an event has more than one date, the registration form asks which one. Somebody picks a date, and their booking belongs to it.

**Capacity is per date.** Twenty places on a weekly class is twenty places each week, not twenty for the term. A date that is full still appears on the list, marked as full — booking it joins the waiting list for that date, and if somebody cancels that week, the place goes to whoever was waiting for *that* week.

The same person can book several dates. Booking the same date twice is refused, booking next week as well is not.

Dates that have already happened, and dates you have called off, are not offered.

### Changing one date

**Events → Dates** lists every date a repeating event produces. Pick the event, and each date has its own row.

- **Move** — change the date or time of that one date. Every other date stays where it is.
- **Call off** — the date stays on the list, marked as called off, rather than disappearing. Somebody who has it in their diary sees it crossed out instead of finding nothing. Bookings on it are left alone: calling off a date and refunding twelve people are two decisions.
- **Put back** — undoes calling off.
- **Reset to the rule** — forgets the edit and lets the repeat rule decide that date again.
- **Split from here** — everything from this date onward becomes a second event, so you can change it without touching the dates before it. You land on the new half, which is where those changes belong. Bookings move with their dates.

A date with bookings on it is marked **Booked**, so you can see what a change would affect before you make it.

**None of these buttons emails anybody.** Moving a date that two hundred people have booked is exactly when they need telling — and exactly why it should not happen because you corrected a typo. Use **Email everybody** on the attendee screen when you are ready to tell them.

## Offering more than one kind of place

Switch **Ticket types** on under **Events → Features**, and the event editor grows a **Ticket types** box.

Leave it empty and everybody books the same kind of place. Add types — Member and Guest, Full and Concession — and the registration form asks which one. Each type can have **its own number of places**, and a booking has to fit both that and the event's capacity: twelve seats with four kept for members means the fifth member joins the waiting list while the room is half empty, and the thirteenth person waits whatever kind of place they asked for.

The waiting list works the same way. A member who cancels frees a member place, and it goes to the next member waiting — not to somebody waiting for a guest ticket.

Leaving **Places** empty means as many as the event allows.

Clearing a type's name removes it. If somebody already holds a ticket of that type it is **archived** instead: it stops being offered, and every booking that named it still says so on your attendee list and in your export.

Each type can have a **price**, and the form shows it beside the name. A price of nothing reads as "Free" rather than as a zero.

What the site prices in is set once under **Events → Settings → Money**, which appears when **Paid tickets** is switched on under **Events → Features**. Orders already taken keep the currency they were taken in.

## Charging for a place

Switch on **Paid tickets** under **Events → Features**, then put your Stripe keys into **Events → Settings → Money**. Until both keys are there, priced tickets are still free to book — nothing is half-charged.

Test keys and live keys are told apart automatically, and the screen says which you are using. A test key paired with a live one switches card payments off rather than failing in front of a customer.

### What somebody paying sees

They fill in the booking form as usual, and instead of landing back on the event they land on a short payment screen with the amount, their reference and a card form. The card is typed into Stripe's own form — it never reaches your site.

**Their place is held while they pay**, for twenty minutes. If they close the tab, the place goes back and whoever is next on the waiting list gets it. Nobody is confirmed, and no confirmation email is sent, until the money has actually arrived.

Somebody on the waiting list is never asked to pay. There is nothing to pay for until they have a place.

### Refunds

The attendee screen grows a **Payment** column for events that have taken money: what was paid, where the order stands, and a box to refund from.

Leave the box empty to refund everything left; type an amount to refund part of it. A **full refund frees the place** and moves the waiting list on. A **partial refund does not** — somebody given a few pounds back is still coming.

Refunding more than the order has left is refused rather than trimmed to fit, because a number that large usually means the wrong order.

## Selling through WooCommerce instead

If your site already runs WooCommerce, switch on **Sell through WooCommerce** under **Events → Features** and let the shop you already have do the work: the basket, the payment, the tax and the refunds are all Woo's.

Switching it on switches **Paid tickets** off, and the Features screen says so before you do it. Two checkouts on one site is not a configuration anybody wants — neither breaks, and afterwards nobody can tell which one took the money.

Each ticket type becomes a hidden, virtual product, kept in step with the ticket type whenever you save the event. **Edit prices on the event, not on the product**: the ticket type is the copy that counts, and it is the one capacity is counted against.

When Woo marks an order paid, the booking is made and the usual confirmation goes out. When you refund it in Woo, the place is released and the waiting list moves on — you never have to do it in two places.

One difference worth knowing: with Woo, a seat is not held while somebody is at the payment step, because Woo already owns the basket and the abandoned order. If an event fills while a purchase is in progress, that purchase becomes a waiting list place rather than an extra seat, and you can refund it from Woo.


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

If your event asks questions of its own, the answers become columns in the file — except the ones you marked sensitive. Those need the second button, **Export CSV including sensitive answers**, which only appears when there is something for it to include.

It is a separate button rather than a setting on purpose. A file of dietary requirements and access needs is health information about named people, and it gets emailed to caterers, copied onto laptops and left in downloads folders. A setting gets ticked once by somebody who needed it that afternoon and stays ticked for everybody afterwards; a button is a decision you make each time.

The attendee screen itself shows every answer, sensitive ones included. That screen is behind a login, shows one event, and the answers are why you asked. It is the file leaving your computer that gets the extra step, not the screen you run the event from.

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

### Emailing everybody

At the bottom of the attendee screen there is an **Email everybody** box, for the message you need to send the day before: the venue has moved, bring a coat, here is the joining link.

Choose who it goes to. By default that is confirmed places only — people on the waiting list are left out, because "see you tomorrow" reaching somebody who has not got a place is worse than not writing to them at all. You can include the waiting list, or everybody still registered. People who cancelled are never included.

A few things worth knowing:

- **The search box and status filter above do not affect who it goes to.** The audience you pick in the box is the audience, so filtering the list to one name and then writing a message does not quietly send to one person.
- **Each person is emailed once**, however many bookings they made.
- **Guests booked by somebody else are reached through whoever booked them**, because that is the only address on the booking.
- You can use the same `{placeholders}` as the templates, so `Hi {attendee_name}` writes to each person by name.
- **Send yourself a test first.** It is the only way to notice a mistyped placeholder before four hundred people read it.

Mail goes out in the background over the next few minutes rather than all at once, so the page comes back straight away. Under the box is a **Delivery** panel saying how many have gone, how many are waiting and how many failed, listing the addresses that did not work and why. While messages are still waiting you can withdraw them; that catches whatever has not gone yet and leaves booking confirmations alone. Anything already sent is gone — there is no unsend.

## On the day: checking people in

Switch **Check-in** on under **Events → Features**, and two things happen: confirmations start carrying a QR code for each person on the booking, and **Events → Check-in** appears.

### The door screen

Open **Events → Check-in** and pick the event. A repeating event opens on the next date, because somebody opening this at ten to seven is running tonight's door.

You get one row per person who is actually coming, with a large **Check in** button beside each, and the count in words at the top — "12 of 40 in" — because that is the question you get asked every few minutes. Cancelled bookings and anybody still on the waiting list are not on the list: they have no place yet.

Type or scan a code into the box at the top to admit somebody straight away.

### Scanning

Tap **Scan a ticket** and allow the camera. Point it at the QR code on somebody's phone or printout and they are admitted.

If the camera does not work — an older phone, a work phone with the camera locked down, or a site that is not on HTTPS — every confirmation email also prints the code in plain text, one per person on the booking. Read it out and type it in. That is the same path the scanner uses, not a lesser one, and the whole screen works with JavaScript switched off.

The code on the ticket is **not** the booking reference. A booking for three is three people who can arrive separately, so each has their own.

### Somebody checked in by mistake

Press **Undo** on their row. The check-in is marked as reversed rather than deleted, so the record of what happened at the door survives — and they can be checked in again afterwards.

Scanning the same ticket twice is not an error. The screen says they are already in, along with when, so you know whether to let them past.

### Who can run a door

Switching Check-in on adds three roles you can give people under **Users**:

| Role | Can do |
| --- | --- |
| Event Manager | Everything — events, attendees, settings, features |
| Event Organizer | Their own events, their own attendees, and their own door |
| Event Staff | Check people in. Nothing else — no editing, not even their own drafts |

Give the person on the door **Event Staff**. They can admit people and cannot change anything about the event, so handing over a phone for the evening is safe.

If you have already customised these roles yourself, updating the plugin leaves your changes alone.

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

- **When the plugin is deleted** — off by default. Leave it off and removing the plugin leaves your events and attendees in the database, so reinstalling picks up where you left off. Tick it and deleting the plugin takes everything with it, permanently.
- **Currency** — what prices on this site are in. Only shown once **Paid tickets** is on, and it does not rewrite what past orders were taken in.
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
