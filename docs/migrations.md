# Migrations

Every schema or data change a released site has to be brought through, and how the
plugin brings it through without asking anyone to open phpMyAdmin.

The rules this implements are set out in [database.md](database.md#migrations). This
document is the operational half: how the runner behaves, how to add a migration, and
what each existing migration does.

---

## How it runs

`QEVM_DB_VERSION` is an integer. A site stores the version it has completed in the
`qevm_db_version` option. Everything follows from comparing the two.

### Two reasons the version moves

`QEVM_DB_VERSION` is the **schema** version. Migration numbers are a separate sequence
that happens to share it, and the two do not always move together:

| Change | Migration? | Bump? |
| --- | --- | --- |
| A new table, created by `dbDelta`, with no existing data to convert | No | **Yes** |
| Existing rows have to be transformed | Yes | Yes |
| A column added that nothing has to backfill | No | **Yes** |

So `Runner::target_version()` is `max( QEVM_DB_VERSION, highest migration )`. Taking
the larger of the two means neither can be forgotten: a migration above the constant
still runs, and a constant above the migrations still triggers the schema pass.

Once every pending migration is through, the runner writes `target_version()` rather
than the last migration's number. Without that step a release which changed the schema
but needed no migration would leave the stored version permanently below the target —
`needs_upgrade()` would stay true and the schema pass would re-run on every admin
request for the life of the site. That was a real bug in C1.2, caught against a
database and not by the unit suite, which had been asserting the two numbers were
equal.

```
admin_init
   └─ Runner::needs_upgrade()        one option read; returns immediately if equal
        ├─ Installer::upgrade_schema()   dbDelta for enabled modules, capabilities
        └─ Runner::run()
             ├─ acquire lock
             ├─ for each pending migration, lowest version first
             │    └─ run( batch_size ) until it returns 0, or the budget expires
             │         └─ on completion: store its version, fire qevm_migration_completed
             └─ release lock
```

**Structure before data.** A migration may depend on a column `dbDelta()` has just
added, so tables are brought up to date before the numbered migrations run.

**On `admin_init`, not activation.** A plugin updated in place through the dashboard
or WP-CLI never fires its activation hook. Activation *also* calls the runner, because
a site still on 1.0 that deactivates it and activates 26.0 arrives that way and has
legacy posts to move.

**The version is written only on completion.** A request that dies halfway through
leaves the stored version where it was, and the migration is re-entered.

### Batching and the time budget

`run( $batch_size )` processes at most one batch and returns how many rows it touched.
Zero means finished. The runner keeps calling it until it returns zero or
`Runner::TIME_BUDGET` (10 seconds) elapses, then stops and leaves the rest for the next
request.

The budget is short on purpose: this runs while an administrator waits for a page, so
it is bounded by their patience rather than by PHP's `max_execution_time`.

A migration is handed a batch size, never an offset. It has to find its own remaining
work each time. A stored offset goes wrong the moment the underlying rows change;
asking "what is still unmigrated?" cannot — and that same property is what makes the
migration idempotent for free.

Both values are filterable:

```php
add_filter( 'qevm_migration_batch_size', fn( $size, $version ) => 100, 10, 2 );
```

### The lock

Two administrators loading the dashboard at the same moment would otherwise both start
migrating. Every migration is idempotent, so the result would still be correct — but
correct after doing the work twice, which on a large table is the difference between a
slow page and a timeout.

The lock is a hand-written `INSERT IGNORE` into `wp_options`, checking that exactly one
row was affected.

**`add_option()` is not usable for this.** Reading `wp-includes/option.php`: it checks
whether the option exists and only then inserts, so two requests can both pass the
check; and the insert itself is `INSERT ... ON DUPLICATE KEY UPDATE`, which succeeds
either way. `INSERT IGNORE` plus `rows_affected` is atomic because `option_name`
carries a `UNIQUE` key — that is what makes it a lock rather than a suggestion.

The lock carries an expiry (5 minutes) so a process killed mid-migration does not block
the site forever.

---

## Adding a migration

1. Add a class in `includes/Install/Migrations/` implementing `Migration`.
2. Give it the next unused `version()`. **Never renumber or reuse one** — sites in the
   field are already holding those values.
3. Register it in `Runner::migrations()`. The list is explicit rather than a directory
   scan, so a migration cannot silently stop running because a file was renamed.
4. Bump `QEVM_DB_VERSION` to at least that number. A test asserts it is never behind,
   because a migration added without the bump would never run.
5. Write it up in the table below.
6. Add an integration test that runs it against populated data **twice**, asserting the
   second run changes nothing.

### What a migration must be

**Forward-only.** No down migrations. A migration that turns out to be wrong is
corrected by a later one, never reversed: a site that ran the bad one and a site that
did not must end up in the same place, and only rolling forward achieves that.

**Idempotent.** Safe to run twice. This follows from finding your own remaining work,
so it is usually free — but it is the property the tests check, not the implementation.

**Non-destructive.** A normal upgrade never drops a column or deletes a row. Follow the
expand-never-replace sequence in [database.md](database.md#expand-never-replace).

**Batched.** Anything that could exceed a thousand rows.

---

## The migrations

| # | What it does | Notes |
| --: | --- | --- |
| 1 | Moves version 1.0's `events` posts onto the `qevm_event` post type | `LegacyPostType`. Batched by id. URLs are unaffected — the post type sets its rewrite slug and archive back to `events` |

Schema versions with no migration behind them:

| Version | What changed |
| --: | --- |
| 2 | Adds the `qevm_occurrences` table (C1.2). Created by `dbDelta`; there is no data to convert, because occurrences are derived from post meta and get populated by the sync in C1.3 |
| 3 | Adds the `qevm_attendees` table (C1.5). Created by `dbDelta` when the registration module is on. Nothing to convert: no site has taken a booking yet, and rows for existing registrations are created by C1.7 |

### 1 — Legacy post type

Version 1.0 (2012) was 34 lines that registered one post type, `events`, and did
nothing else: no meta, no options, no tables. That is the entire legacy surface, so the
migration is a `post_type` rewrite and nothing more.

The key changes because `events` is generic enough that any other event plugin or theme
registering it silently collides. `/events/` and `/events/{slug}` still resolve, via
`rewrite => array( 'slug' => 'events' )` and `has_archive => 'events'`.

Two details that are easy to get wrong:

- **Rows are selected, then updated by id** — not updated with a blanket
  `WHERE post_type = 'events'`. A blanket update would also catch anything inserted
  between the select and the update, which is the one case where a batch could move a
  row it never counted.
- **`clean_post_cache()` is called for every moved id.** WordPress caches each post
  individually in the `posts` group keyed by id, and a direct `UPDATE` leaves those
  entries holding the old `post_type`. Bumping the group's `last_changed` is not
  enough: that invalidates cached *queries*, not the post objects. On a site with a
  persistent object cache the events would keep reporting the legacy type until
  something else evicted them.

Running exactly once matters here beyond the usual reasons. A site could legitimately
create a post of type `events` with some other plugin years from now, and it must not
be absorbed. The stored version is the guarantee: once migration 1 has completed,
nothing re-enters it.

---

## Recovery

The plugin owns its schema, including repair. Derived data can be rebuilt from its
source rather than restored from a backup:

```sh
wp qevm occurrence status    # has it drifted?
wp qevm occurrence rebuild   # put it back
```

Those ship alongside the occurrence table rather than after it, precisely so that a
site whose derived table has drifted has a documented way back. See
[cli.md](cli.md).
