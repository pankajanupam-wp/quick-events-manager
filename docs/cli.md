# WP-CLI commands

Maintenance the plugin owns, so a site administrator is never asked to open
phpMyAdmin.

Every command lives under `wp qevm`. They are registered only when `WP_CLI` is
defined, so they cost nothing on a web request.

---

## `wp qevm occurrence`

The occurrences table is **derived** from post meta. Post meta is what the editor
writes and what a human reads; the occurrence rows exist so that dates can be queried
against a real index. See [adr/0003-occurrence-table.md](adr/0003-occurrence-table.md).

Anything derived can drift from its source — a failed write, a plugin bypassing the
hooks, a database restored from a partial backup, an import that writes meta directly.
These commands are how drift is detected and corrected. They ship in the same chunk as
the table rather than being retrofitted during an incident, because a derived table
with no rebuild path is a table that eventually drifts with no way back.

### `wp qevm occurrence status`

Reports whether the table matches what post meta says it should hold. Changes nothing.

```sh
$ wp qevm occurrence status
Events:      5
Occurrences: 5
Success: Every event matches its meta. No drift.
```

When something is wrong:

```sh
$ wp qevm occurrence status
Events:      5
Occurrences: 0
Warning: 5 event(s) differ from their meta: 55, 57, 59, 61, 63
Run `wp qevm occurrence rebuild` to correct them.
```

This is the question worth asking before deciding to rebuild anything.

### `wp qevm occurrence rebuild`

Regenerates occurrence rows from post meta.

```sh
wp qevm occurrence rebuild              # every event
wp qevm occurrence rebuild --dry-run    # report, write nothing
wp qevm occurrence rebuild --event=123  # one event
```

Safe to run at any time. Post meta is the source of truth, so the worst a rebuild can
do is restore the table to what the meta already says.

It reconciles rather than truncating: a date that has not moved keeps its row id.
That matters from C1.6 onwards, when registrations point at a specific occurrence — a
rebuild must not invalidate every attendee's reference.

Reported counts are `inserted`, `updated`, `deleted` and `unchanged`. A healthy site
that has just been rebuilt reports everything as `unchanged`:

```sh
$ wp qevm occurrence rebuild
Success: 5 event(s): 5 inserted, 0 updated, 0 deleted, 0 unchanged. 5 occurrence row(s) in total.

$ wp qevm occurrence rebuild
Success: 5 event(s): 0 inserted, 0 updated, 0 deleted, 5 unchanged. 5 occurrence row(s) in total.
```

---

## How occurrences stay in sync normally

`OccurrenceSync` regenerates an event's rows whenever its dates could have changed. It
hooks two places, because the two editors write meta at different times:

| Hook | Priority | Why |
| --- | --- | --- |
| `save_post_qevm_event` | 20 | The classic editor writes meta inside `save_post`. `MetaBox::save()` is registered at 10, so this has to run after it |
| `rest_after_insert_qevm_event` | 20 | The block editor writes meta through the REST controller **after** `save_post` has already fired. Hooking `save_post` alone would read the previous values and write occurrences one save behind |
| `deleted_post` | 10 | Removes an event's rows when the event is deleted outright |

Three behaviours that are deliberate rather than incidental:

**Every post status is synced, not just published.** The table mirrors what exists;
deciding what a visitor may see is the query layer's job, and it has the post row to
hand. Syncing only published events would mean a draft has no occurrence to preview
and a scheduled post grows one at an unpredictable moment.

**Trashing does not remove occurrences.** Trash sets `post_status`, and the row still
describes a date that exists. Deleting on trash and rebuilding on untrash would make
the restore path reconstruct data that never needed to be lost.

**Auto-drafts are skipped.** An auto-draft is the empty shell WordPress creates the
moment "Add New" is clicked. Most are abandoned, and giving each one a row would leave
litter behind for every one that is.
