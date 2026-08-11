# Upgrading from version 1.0

## What 1.0 actually was

The 2012 plugin was two files and 34 lines. It called `register_post_type( 'events', … )` on `init` and stopped.

There was no meta, no options, no custom tables, no shortcodes, no admin UI and no front end. That is the whole legacy surface, and it is why the migration is forty lines rather than a subsystem.

At the time of writing the wordpress.org listing reports fewer than 10 active installs.

## What changes

The post type key moves from `events` to `qevm_event`.

`events` is generic enough that any other event plugin or theme registering the same key silently collides — whichever registers last wins, and the loser's events become unreachable. Prefixing removes that permanently.

## What does not change

**URLs.** The new post type pins its rewrite slug and archive back to `events`:

```php
'has_archive' => 'events',
'rewrite'     => array( 'slug' => 'events', 'with_front' => false ),
```

So `/events/` and `/events/my-meetup/` resolve exactly as they did in 2012. Existing links, bookmarks and search results keep working. This is verified end to end: a post created with `post_type = 'events'` still returns HTTP 200 at its original URL after migrating.

**Content.** Titles, content, excerpts, authors, dates, featured images, comments and post IDs are all untouched.

## How it runs

`QuickEventsManager\Install\Migrator::maybe_migrate()` runs on activation and on `admin_init`.

Both, because a plugin updated in place through the dashboard or WP-CLI never fires its activation hook — relying on activation alone is how a migration quietly fails to run on the majority of sites.

```php
UPDATE wp_posts SET post_type = 'qevm_event' WHERE post_type = 'events';
```

A single `UPDATE` rather than a loop over `WP_Query`: there is no meta to transform and no hook that needs to fire, so this stays constant-time on a site with any number of events.

The affected IDs are collected first, purely so `clean_post_cache()` can be called on each afterwards. WordPress caches each post individually in the `posts` group, and a direct `UPDATE` leaves those entries holding the old `post_type`. Bumping the group's `last_changed` is not enough — that only invalidates cached *query results*, not the post objects — so on a site with a persistent object cache the events would keep reporting the legacy type until something else evicted them.

## Safety

- **Version-gated.** Guarded by the `qevm_migrated_legacy_post_type` option, not by a row count, so a post of type `events` legitimately created by something else years later is not silently absorbed.
- **Idempotent.** Running it twice changes nothing the second time.
- **Non-destructive.** Only the `post_type` column is written. Nothing is deleted, and no other table is touched.

## Verifying it worked

After updating, check **Events** in the admin — your old events should be listed. Or:

```sh
wp post list --post_type=qevm_event --fields=ID,post_title,post_name
wp post list --post_type=events    # should be empty
```

Then open one of the old URLs and confirm it still loads.

## If something goes wrong

The migration is reversible with one statement:

```sh
wp db query "UPDATE wp_posts SET post_type = 'events' WHERE post_type = 'qevm_event';"
wp option delete qevm_migrated_legacy_post_type
```

Then deactivate the plugin. Your events are back exactly as they were, because nothing else was ever changed.

Please also open an issue — a migration that needs reverting is a bug.

## What is not migrated

Nothing, because there is nothing else. If you added event dates or locations to 1.0 events using a custom fields plugin, those meta values are still on the posts, but this plugin will not recognise them — it expects its own `_qevm_*` keys. See [data-model.md](data-model.md) for the key names if you want to script a conversion.
