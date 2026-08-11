# Engineering standards

The engineering constitution of Quick Events Manager. Every human contributor and
every AI coding agent working in this repository follows it.

If a rule here conflicts with something you were about to do, the rule wins. If you
believe a rule is wrong, change the rule in a pull request first, then write the code.

**Status:** approved, pre-implementation. Nothing here describes code that exists yet
except where marked *(already true)*.

---

## 1. Naming rules

The prefix is **`qevm`**. It is not `qem`.

> A WordPress.org plugin called **Quick Event Manager** (`quick-event-manager`,
> 1,000+ installs, actively maintained) already uses `qem` in practice: 142 global
> `qem_*` functions, 19 `qem_*` options, 13 `qem_*` hooks including
> `qem_event_register`, and `QEMBP_*` / `QEMFW_*` constants. Its name is one letter
> from ours. Nothing collides fatally today, but the option and hook namespaces are
> shared, and a collision there fails silently — a hook fires the wrong handler, an
> option reads the wrong value. See [adr/0001-naming-and-namespace.md](adr/0001-naming-and-namespace.md).

### The naming matrix

Everything global goes in this table. If you are about to name something global and
it is not here, add a row before you write the code.

| Object | Convention | Example |
| --- | --- | --- |
| PHP namespace | `QuickEventsManager\` root | `QuickEventsManager\Domain\Event` |
| Class, interface, trait, enum | `PascalCase`, namespaced | `RegistrationRepository` |
| Interface suffix | none — name the role | `PaymentGateway`, not `PaymentGatewayInterface` |
| Method, property | `snake_case` (WordPress standard) | `find_by_event()` |
| Namespaced function | `snake_case`, no prefix needed | `QuickEventsManager\format_money()` |
| Global function | `qevm_` prefix — **avoid; namespace instead** | `qevm_get_event()` |
| Constant | `QEVM_` | `QEVM_VERSION`, `QEVM_PATH` |
| Option | `qevm_` | `qevm_settings`, `qevm_db_version`, `qevm_migration_lock` |
| Transient | `qevm_` | `qevm_capacity_{event_id}` |
| Post type | `qevm_` | `qevm_event`, `qevm_venue` |
| Taxonomy | `qevm_` | `qevm_event_category` |
| Post status | `qevm-` (hyphen — core convention) | `qevm-cancelled` |
| Post meta | `_qevm_` (underscore hides it from the custom-fields box) | `_qevm_start_utc` |
| Capability | `qevm_` / `_qevm_` | `edit_qevm_events`, `manage_qevm_checkins` |
| Action / filter | `qevm_` | `qevm_registration_created` |
| AJAX action | `qevm_` | `qevm_toggle_module` |
| Cron hook | `qevm_` | `qevm_process_email_queue` |
| REST namespace | `qevm/v1` | `/wp-json/qevm/v1/events` |
| Block namespace | `qevm/` | `qevm/event-list` |
| Shortcode | `qevm_` | `[qevm_event_list]` |
| Script handle | `qevm-` | `qevm-admin` |
| Style handle | `qevm-` | `qevm-frontend` |
| CSS class | `.qevm-` | `.qevm-event__title` |
| Database table | `{$wpdb->prefix}qevm_` | `wp_qevm_registrations` |
| Text domain | `quick-events-manager` — **matches the slug, never abbreviate** | `__( 'Event', 'quick-events-manager' )` |
| Template file | plain, inside our directory | `templates/event-details.php` |
| Theme override dir | `quick-events-manager/` | `yourtheme/quick-events-manager/event-details.php` |

**The text domain is the one thing that is not `qevm`.** WordPress.org generates
translation files from the slug. It must be `quick-events-manager` exactly.

### Naming style

- Hooks read `qevm_{noun}_{past-tense verb}` for actions: `qevm_registration_created`.
- Filters read `qevm_{noun}_{thing being filtered}`: `qevm_event_schema_data`.
- Never rename a public hook. See §18.
- CSS follows BEM inside the `qevm-` namespace: `.qevm-event__title--past`.

---

## 2. Namespace rules

Root namespace: **`QuickEventsManager\`**

Mapped PSR-4 to `includes/`. `QuickEventsManager\Domain\Event` lives at
`includes/Domain/Event.php`. PSR-4 maps a namespace root to whichever directory the
autoloader is pointed at; it does not mandate `src/`.

Namespace everything that PHP allows to be namespaced: classes, interfaces, traits,
enums, exceptions. Namespacing is what protects us from the 142 global `qem_*`
functions in the other plugin, and it is why that collision is survivable.

**Do not** create global functions. If you think you need one, you need a static
method on a namespaced class. The only acceptable global functions are template
tags intended for theme authors, and each one needs a line in `docs/extensibility.md`
justifying it.

Do not use `use function` / `use const` imports for WordPress core functions — they
are global and calling them unqualified is correct and conventional.

Inside a namespaced file, calling a core function like `get_post()` resolves to the
global one only after PHP fails to find a namespaced version. This is fine and normal.
Do not add leading backslashes everywhere to "optimise" it; it hurts readability for
no measurable gain. Do use a leading `\` for core *classes* (`\WP_Error`, `\WP_Query`)
and for global functions inside closures where the intent could be misread.

---

## 3. Prefix rules

Namespaces do not protect anything that lives in a WordPress registry. Everything in
that category takes the `qevm` prefix, per the matrix in §1.

Before introducing any new global name:

1. Check the matrix. Use the convention that is already there.
2. Search the codebase for the name. It may already exist.
3. If it is a new *kind* of global object, add a row to the matrix in the same PR.

**Never** use a bare generic name — `event`, `events`, `event_list`, `registration`,
`settings`, `calendar` — in any global registry, in any form, for any reason.

---

## 4. Folder structure

```
quick-events-manager/
├── quick-events-manager.php     Header, constants, requirement check, bootstrap. Thin.
├── uninstall.php                Guarded, opt-in data removal
├── readme.txt                   WordPress.org listing
├── composer.json                Dev dependencies only. No runtime dependencies.
├── package.json                 @wordpress/scripts for blocks
├── phpunit.xml.dist             Unit suite
├── phpunit-integration.xml.dist Integration suite
├── phpcs.xml.dist               WordPress Coding Standards
├── phpstan.neon.dist            Static analysis
├── .distignore                  What does not ship
│
├── includes/                    All PHP. PSR-4 → QuickEventsManager\
│   ├── Plugin.php               Wiring. No business logic.
│   ├── Autoloader.php           Hand-written PSR-4. No Composer at runtime.
│   │
│   ├── Domain/                  Entities, value objects, enums. i18n only — see §5.
│   ├── Repository/              All $wpdb access. One repository per aggregate.
│   ├── Service/                 Use cases that coordinate repositories + domain
│   │
│   ├── Modules/                 Module contract and registry
│   ├── Install/                 Installer, schema versions, Migrations/ runner
│   │
│   ├── Events/                  Post type, meta, occurrence sync, queries
│   ├── Registration/            Registration + attendee feature
│   ├── Ticketing/               Ticket types
│   ├── Commerce/                Orders, payments, gateways
│   ├── Checkin/                 Check-in feature
│   ├── Email/                   Templates, queue, sending
│   │
│   ├── Admin/                   Admin screens. Presentation only.
│   ├── Frontend/                Renderers, templates, shortcodes, assets
│   ├── Blocks/                  Block registration
│   ├── Rest/                    REST controllers
│   ├── Cli/                     WP-CLI commands
│   └── Privacy/                 Exporters and erasers
│
├── assets/                      Hand-written css/js. Not built.
├── src/                         Block sources. @wordpress/scripts default.
├── build/                       Compiled blocks. Gitignored, ships in the package.
├── templates/                   Overridable front-end markup
├── languages/                   .pot
├── tests/{Unit,Integration,e2e}/
└── docs/
    ├── engineering-standards.md  This file
    ├── architecture.md
    ├── database.md
    ├── development-plan.md
    ├── security.md
    ├── extensibility.md
    ├── hooks.md
    ├── rest-api.md
    ├── migrations.md
    ├── accessibility.md
    └── adr/
```

Rules:

- **PHP stays in `includes/`.** WordPress core uses `wp-includes/`, the Plugin
  Handbook and the boilerplate use `includes/`, and it is the directory a WordPress
  developer opens first. PSR-4 does not require `src/` — it maps a namespace root to
  whichever directory the autoloader is pointed at, and
  `QuickEventsManager\Foo\Bar` → `includes/Foo/Bar.php` satisfies it exactly.
- **Block sources stay in `src/`.** It is the `@wordpress/scripts` default, so the
  build needs no `--webpack-src-dir` flag, and `src/` is already excluded by
  `.distignore` because compiled `build/` is what ships.
- Renaming either of these would be importing a general-PHP convention into a
  WordPress project for no functional gain, which is the thing this document exists
  to prevent.
- Maximum three levels of nesting under `includes/`. If you need a fourth, the module is
  too big — split it.
- A feature directory (`Registration/`, `Ticketing/`) owns its own admin screens,
  REST controllers and templates *only if* they are not shared. Shared presentation
  lives in `Admin/`, `Frontend/`, `Rest/`.

---

## 5. Architecture rules

Four layers. Dependencies point **downward only**.

```
  WordPress integration    Admin/  Frontend/  Blocks/  Rest/  Cli/  Privacy/
           │               hooks, screens, HTTP, markup, escaping
           ▼
  Application              Service/  Modules/
           │               use cases; coordinates repositories and domain
           ▼
  Domain                   Domain/
           │               entities, value objects, enums, business rules
           ▼
  Infrastructure           Repository/  Install/
                           $wpdb, schema, migrations
```

Read the arrows as "may call". Concretely:

| Rule | Meaning |
| --- | --- |
| `Domain/` calls **nothing but i18n** | No `$wpdb`, no `get_option()`, no `apply_filters()`, no HTTP, no cache. Unit-testable with no WordPress at all. The single exception is `__()` and friends — see below |
| `Repository/` is the only place `$wpdb` appears | One file per aggregate. This is what makes the SQL audit finite *(already true for registrations)* |
| `Service/` never emits HTML and never reads `$_POST` | It takes typed input and returns typed output or `WP_Error` |
| `Admin/`, `Frontend/`, `Rest/`, `Cli/` never contain business rules | They translate a request into a service call and a result into output |
| Nothing above `Repository/` writes SQL | Not one query. Not "just this once" |
| One query layer owns dates | Every date query goes through `Events\OccurrenceQuery`. No feature invents its own |
| One renderer per feature | Block and shortcode call the same function, so a fix cannot land in one and miss the other *(already true)* |

#### Why i18n is the one exception in `Domain/`

An enum that models a state machine is the right place for that state's label,
and there is exactly one correct label per case. Pushing labels out to a
presentation helper splits one concept across two files so that a rule can stay
absolute, and the rule is not worth that.

`__()`, `_n()` and `esc_html__()` touch no database, read no option, fire no
hook and perform no I/O. They map a string through a locale. The unit suite
stubs them in three lines, so the layer stays testable with no WordPress
present, which is what the rule was actually protecting.

Nothing else is exempt. A domain object that reaches for `get_option()`,
`$wpdb`, `apply_filters()`, the object cache or the network is in the wrong
layer, and no amount of convenience changes that.

### Things we deliberately do not build

- **No dependency injection container.** `Plugin` constructs what it needs and passes
  it in. Constructor injection, by hand. If wiring becomes painful, that is a signal
  the object graph is wrong, not that we need a container.
- **No event bus.** WordPress has one; it is called `do_action()`.
- **No ORM, no query builder, no active record.** `$wpdb` with `prepare()`.
- **No custom templating engine.** PHP templates with `include`.
- **No custom autoloader magic.** PSR-4, one `str_replace`, one `is_readable`.
- **No custom error/exception hierarchy above what is needed.** `WP_Error` at every
  WordPress-facing boundary. Exceptions only inside `Domain/` and `Service/`, caught
  before they reach a boundary.

> This plugin is a WordPress plugin, not a framework wearing one. Every abstraction
> must remove more complexity than it adds. When in doubt, write the simple version.

### God classes

`Plugin` wires. It does not do. If `Plugin.php` exceeds ~200 lines, something has
leaked into it that belongs in a module.

No class named `Manager`, `Helper`, `Utils`, or `Handler` without a qualifier that
says what it actually manages. `EventManager` is a smell; `OccurrenceSynchroniser`
is a name.

---

## 6. WordPress rules

Use the platform. Do not rebuild it.

| Need | Use | Never |
| --- | --- | --- |
| Errors at a boundary | `WP_Error` | A custom Result class exposed publicly |
| Assets | `wp_enqueue_script/style` | `<script>` tags in output |
| Content types | `register_post_type()` | A custom content system |
| REST | `register_rest_route()` | `admin-ajax.php` for new read APIs |
| Authorization | `current_user_can()` | `is_admin()`, role name comparisons |
| Forms | `wp_nonce_field()` + `check_admin_referer()` | Unverified POST |
| SQL | `$wpdb->prepare()` | String concatenation, ever |
| Scheduling | `wp_schedule_event()` / Action Scheduler if justified | `sleep()`, long-running requests |
| HTTP | `wp_remote_get/post()` | cURL directly, `file_get_contents()` on URLs |
| Caching | `wp_cache_*`, transients | A custom cache layer |
| Dates | `wp_date()`, `date_i18n()`, `wp_timezone()` | `date()`, server timezone assumptions |
| Sanitising | `sanitize_text_field()`, `absint()`, `sanitize_email()` … | Hand-rolled regex validation |
| Escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` | Trusting any variable |
| Redirects | `wp_safe_redirect()` | `wp_redirect()` with user input |
| Privacy | `wp_privacy_personal_data_exporters` / `_erasers` | A bespoke export screen |

**`is_admin()` is not an authorization check.** It reports which side of the site the
request is on. It says nothing about who is asking. Every admin action needs
`current_user_can()` *and* a nonce.

### Suppressing a sniff

A standard nobody can suppress gets excluded wholesale the first time it is
inconvenient, so suppression is allowed — under conditions.

- **Narrowest scope that works.** An inline `phpcs:ignore` before one line, or a
  `phpcs:disable` / `phpcs:enable` pair around one statement. A file-scoped disable
  needs the file to *be* the exception — `Registration/Repository.php` is the data
  access layer, so `DirectDatabaseQuery` there is the point of the file.
- **Never in `phpcs.xml.dist`** for a sniff that is right about production code. An
  exclusion there silences every file, including the one that gets it wrong next year.
  The `/tests/*` exclusions are the exception, and each says why.
- **Always name the sniff.** A bare `phpcs:ignore` disables everything on that line,
  including the sniff that catches tomorrow's mistake on the same line.
- **Always give a reason after `--`,** and make it a reason, not a restatement. "Custom
  table" explains nothing; "custom table, no core API reads it" explains it.
- **Say which of the three it is.** The sniff is *wrong* here (explain why), or the
  sniff is *right and the fix is scheduled* (name the chunk), or the sniff is *right
  and we are accepting it* (say what would change our mind). The middle case needs a
  line in `docs/development-plan.md` too: PHPCS never reports a stale ignore, so an
  annotation nobody wrote down is one nobody will remove.

Check first whether the finding is simply fixable. Most of the custom-table pile in
C0.6 looked like it needed annotating and turned out to need `%i`.

---

## 7. Security rules

Non-negotiable. A PR that violates any of these does not merge.

1. **Every** `$wpdb` call that includes a variable uses `prepare()`.
2. **Table and column names use the `%i` placeholder, never interpolation.** WordPress
   6.2 added `%i` for identifiers and the floor here is 6.5, so `"FROM {$table}"` has
   no remaining excuse — `prepare( 'FROM %i', $table )` lets `$wpdb` do the escaping.
   The value is a prefixed constant today, but the rule is about not having to check
   that again on every future edit.
3. `ORDER BY` **directions** are matched against a hard-coded allowlist, because a sort
   direction is a keyword rather than an identifier and `%i` would backtick it into a
   column name. Columns take `%i` **and** keep their allowlist; the allowlist is what
   makes a mistyped column an ignored request rather than a SQL error shown to a user.
   `prepare()` alone quotes an identifier into a string literal, which silently sorts
   every row by the same constant. This is not a hypothetical *(already handled)*.
4. **Every** output is escaped at the point of output, not at the point of storage.
5. **Every** admin action checks a capability *and* verifies a nonce.
6. **Every** REST route declares an explicit `permission_callback`. `__return_true`
   is permitted only for genuinely public reads, and each use carries a comment
   saying why. A test counts routes against permission callbacks *(already true)*.
7. **Every** AJAX action verifies a nonce and a capability. Both.
8. Public forms are nonce-protected, rate-limited, honeypotted, and use
   POST-redirect-GET *(already true)*.
9. Rate limiting keys on a **salted hash**, never a stored IP address.
10. CSV cells beginning `=`, `+`, `-`, `@` are prefixed with a tab, so an attendee's
    name cannot execute on the organiser's machine *(already true)*.
11. Signed public links (cancellation, check-in) use `hash_hmac()` with `wp_salt()`,
    carry an expiry, and are compared with `hash_equals()`.
12. Webhooks verify the provider's signature before touching the database, and are
    idempotent on the provider's event id.
13. No card details ever touch this plugin. Every gateway takes that off-site.
13. Uploaded files are validated with `wp_check_filetype_and_ext()`, stored through
    `wp_handle_upload()`, and never executed.
14. Never log passwords, tokens, gateway credentials, or full personal records.
15. Never expose stack traces, `$wpdb->last_error`, or query text to a browser.

Assume every input is hostile, including input from a logged-in administrator.

---

## 8. Database rules

Full schema in [database.md](database.md). The rules that govern changes to it:

1. Tables are `{$wpdb->prefix}qevm_*`. Never hard-code `wp_`.
2. **Every table needs written justification** before it is created. WordPress-native
   storage is the default; a custom table is the exception. See the decision
   framework in [database.md](database.md#storage-decision-framework).
3. Money is stored as an **integer in minor units** plus an ISO-4217 currency code.
   Never a float. Never a decimal string.
4. Timestamps are stored as `datetime` in **UTC**. The event's own timezone is stored
   alongside as an identifier. Display converts; queries never do.
5. Historical financial records store a **snapshot** of price and description. A
   report never recomputes from current configuration.
6. Indexes are added because a named query needs them, and the query is named in a
   comment. Speculative indexes are removed.
7. **Expand, never replace.** Add a nullable column, backfill, migrate, deprecate.
   Do not drop and recreate.
8. Every schema change increments `QEVM_DB_VERSION` and adds a numbered migration.
9. Migrations are **idempotent**, **resumable**, and **batched** above 1,000 rows.
10. A normal upgrade never destroys user data. Not registrations, not events, not
    settings. Data removal happens on uninstall, and only when the user opted in.
11. Every new table joins `uninstall.php` in the same commit that creates it.
12. No serialized PHP arrays for anything that needs to be searched, filtered,
    sorted, joined, or reported on.

---

## 9. API rules

### Public versus internal

| Tier | Contract | Marked as |
| --- | --- | --- |
| **Public** | Backward compatible until a major release. Documented. | `@since`, listed in `docs/hooks.md` or `docs/rest-api.md` |
| **Internal** | May change in any release without notice. | `@internal` in the docblock |
| **Deprecated** | Still works, scheduled for removal, emits a notice. | `@deprecated` + `_deprecated_function()` |

Anything not explicitly documented as public is internal. Do not let an implementation
detail become a contract by accident — if it is not in `docs/`, nobody may rely on it.

### Hooks

Add a hook when there is a real extension point, not to be thorough. Every public
hook needs, in the same commit:

- A docblock with `@since`, every parameter typed and described
- An entry in `docs/hooks.md` with a **worked example**
- Stable parameters — adding a parameter at the end is safe, reordering is not

Before adding a hook, check whether an existing one can carry the case.

### REST

- Namespace `qevm/v1`. Versioned from the first route.
- Every route: `permission_callback`, `args` with `validate_callback` and
  `sanitize_callback`, and a `schema`.
- **Only pass single-argument functions by name as a `sanitize_callback`.** WordPress
  calls it as `( $value, $request, $param )`. `sanitize_title`'s second parameter is
  `$fallback_title`, so passing it by name returns the `WP_REST_Request` object and
  fatals. Wrap anything with optional parameters in a closure. This has already
  broken this plugin once.
- Return `WP_REST_Response` or `WP_Error`. Never a bare array.
- Paginate every collection. Send `X-WP-Total` and `X-WP-TotalPages`.
- Never expose a table row directly. Map through a service to a documented shape.
- Do not duplicate what core gives free: `show_in_rest => true` on the post type
  already provides authenticated event CRUD at `/wp/v2/qevm_event`. Hand-rolling it
  would create a second permission surface to audit.

---

## 10. Testing rules

Three suites, answering different questions.

| Suite | Runs | Covers | Does not cover |
| --- | --- | --- | --- |
| **Unit** | No WordPress, no DB, ~1s | Domain logic, value objects, enums, timezone conversion, sanitisation, iCal escaping, CSV defusing, release metadata | Anything touching `$wpdb` |
| **Integration** | Real WordPress + MySQL | Repositories, migrations, REST routes and their permissions, capacity under concurrency, module enable/disable, privacy export | Browser behaviour |
| **e2e** | Playwright via `@wordpress/env` | Create → publish → register → manage → check in | — |

Rules:

1. **Do not fake `$wpdb`.** A stub that returns what you told it to proves you can
   write a stub. SQL correctness is an integration concern.
2. Every bug fixed in production gets a regression test naming the bug
   *(already true for four of them)*.
3. Coverage percentage is not a goal. Critical paths are: capacity, money, dates,
   permissions, migrations. Those approach complete coverage; a settings screen
   does not need any.
4. Concurrency is tested with real parallel processes, not by mocking a race
   *(already true — eight processes against a capacity-1 event)*.
5. A migration is not merged without an integration test that runs it against a
   populated database, twice, asserting the second run changes nothing.
6. **A stub matches core's signature exactly** — variadics, defaults, parameter
   count, return type. A stub narrower than the real function turns a call that
   would fail against WordPress into one that passes here. `do_action()` and
   `apply_filters()` were declared with fixed arity in `tests/unit/bootstrap.php`,
   and eleven call sites passing extra arguments went unnoticed until static
   analysis had core's real signatures to compare against. When a stub's behaviour
   matters, copy core's implementation rather than approximating it: `esc_url_raw()`
   used `FILTER_SANITIZE_URL`, which keeps `<` and `>` where core's allowlist strips
   them.
7. **PHPUnit metadata is attributes, never doc-comments.** `#[DataProvider]`,
   `#[CoversClass]`. PHPUnit 12 removed doc-comment metadata: `@dataProvider` is not
   an error there, it is *ignored*, so provider-driven tests run once with no
   arguments and the suite quietly shrinks. Attributes work on 10.5 through 12,
   which is the whole supported range.
8. **A test that cannot fail is not a test.** If PHPStan can prove an assertion
   always holds, the assertion is checking a type declaration rather than any
   behaviour, and the declaration is already enforced. Assert on what the code
   does — invoke the sanitiser rather than checking that it is callable.

---

## 11. Documentation rules

Documentation is part of the feature, not a follow-up.

| Change | Requires |
| --- | --- |
| New public hook | `docs/hooks.md` entry with a worked example |
| New REST route | `docs/rest-api.md` entry with request and response |
| New table or column | `docs/database.md` update |
| Schema change | Numbered migration + `docs/migrations.md` entry |
| Architectural decision | An ADR in `docs/adr/` |
| New user-visible feature | `docs/user-guide.md` update |
| Anything a contributor could get wrong | A line in this file |

Every PHP file carries a `@package QuickEventsManager` docblock. Every class and
public method carries a docblock explaining *why*, not restating the signature.

**Comments explain the non-obvious.** A comment that says what the next line does is
noise; a comment that says why a `CAST` would break the index is the reason the code
survives its author.

---

## 12. Git rules

- **Never commit unless asked.** Changes are left in the working tree with a report
  of what changed.
- No AI attribution in commit messages. No `Co-Authored-By` trailers, no mention of
  the tooling used.
- Branch from `main` as `feature/<slug>` or `fix/<slug>`. No `develop`.
- One logical change per commit. Message: imperative mood, why in the body.
- A commit that changes schema, adds a hook, or adds a table includes its
  documentation and its migration in the same commit.
- CI must be green before merge.

---

## 13. Dependency rules

**Runtime dependencies: zero.** The shipped package contains no `vendor/`.

Composer is used for **development only** — PHPUnit, PHPCS, PHPStan. `vendor/` is in
`.distignore` and `.gitignore`. Autoloading at runtime is a hand-written PSR-4
autoloader, about fifteen lines.

The reason is specific: two plugins bundling different versions of the same Composer
package into the same PHP process is a known and unfixable class of WordPress
conflict. Shipping nothing avoids it entirely.

Before adding **any** dependency, runtime or dev:

1. What problem does it solve that ~100 lines would not?
2. Is its licence GPL-compatible? (Verify. Do not assume.)
3. Is it maintained?
4. Does WordPress already provide it? (`wp_remote_get`, `WP_List_Table`,
   `wp_kses`, `WP_Date_Query`, `@wordpress/*` packages.)
5. What is the removal plan if it is abandoned?

JavaScript: `@wordpress/scripts` only. Use the `@wordpress/*` packages WordPress
already enqueues rather than bundling React, lodash or date libraries again.

---

## 14. Performance rules

1. **Assets load only where used.** Never enqueue globally. An event stylesheet on a
   contact page is a bug.
2. Front-end blocks render in PHP. Visitors download no block JavaScript
   *(already true)*.
3. Every list is paginated. No unbounded `SELECT`.
4. No N+1. If you are calling a repository inside a loop, you need a batch method.
5. Date queries hit the indexed occurrence table, never a `meta_value` sort.
   `wp_postmeta` indexes `post_id` and `meta_key` only — `meta_value` is an unindexed
   `LONGTEXT`, and a `meta_query` with `'type' => 'DATETIME'` wraps it in a `CAST`
   that defeats any index. See [adr/0003-occurrence-table.md](adr/0003-occurrence-table.md).
6. Counts that render on every page view are cached in a transient and invalidated by
   the action that changes them.
7. Bulk email goes through the queue. Never `wp_mail()` in a loop over attendees.
8. Exports above ~5,000 rows stream or batch. Never build the whole file in memory.
9. Caching does not excuse a bad query. Fix the schema, the index, and the query
   first; cache last.

Targets: the archive query count does not vary with event count, and 10,000 events
render the archive in under 200ms of database time.

---

## 15. Accessibility rules

Accessibility is a build standard, applied per feature, not an audit at the end.
Retrofitting is where it becomes expensive.

1. **axe-core runs in CI** over every public screen. A violation fails the build.
2. WCAG 2.2 AA contrast (4.5:1 text, 3:1 UI) in light and dark.
3. Every control has a persistent visible `<label>`. A placeholder is not a label.
4. Keyboard-complete: every flow, with a visible focus indicator at every step.
5. Errors are announced via `aria-live`, associated via `aria-describedby`, and focus
   moves to the first error.
6. Semantic HTML first. One `<h1>`. Landmarks. ARIA only where HTML cannot express it.
7. The calendar month grid is a real `<table>` with `scope`, or a proper ARIA grid —
   never a `<div>` soup — with arrow-key navigation and announced month changes.
8. **A list view is a first-class equivalent of every calendar view**, not a fallback.
9. `prefers-reduced-motion` is respected.
10. Content and function survive 200% zoom.

---

## 16. Internationalization rules

1. Text domain is `quick-events-manager`. Always. It matches the slug because
   WordPress.org requires that to generate translations.
2. Every user-facing string is wrapped. No exceptions, including admin notices,
   error messages and `aria-label` values.
3. **Never concatenate translatable strings.** Use `sprintf()` with placeholders.
4. Numbered placeholders (`%1$s`, `%2$s`) whenever there is more than one — word
   order differs between languages.
5. Every `sprintf` carries a `/* translators: */` comment naming each placeholder.
6. Plurals use `_n()`. Never `if ( $n > 1 )`.
7. Dates display through `wp_date()` / `date_i18n()`. Never `date()`.
8. `rtl.css` ships and is tested.
9. Do not translate: hook names, option keys, capability names, post type keys,
   CSS classes, or anything in the naming matrix.

---

## 17. Backward compatibility rules

Everything published is a contract. What we already owe:

| Existing artefact | Obligation |
| --- | --- |
| `post_type = 'events'` from 1.0 | Migrated to `qevm_event`, version-gated, idempotent |
| `/events/` URLs | Preserved via `rewrite` slug and `has_archive`, decoupled from the post type key |
| `qem_*` names in the unpublished rewrite | **No obligation** — never published. This is why the rename is free today |

Rules going forward:

1. Never rename a public hook, option, capability, post type, table or REST route.
2. Never change the meaning of an existing column.
3. Never change a stable public identifier. Registration codes and occurrence ids
   appear in emails, QR codes and other people's systems.
4. Adding is safe. Removing and renaming are not.
5. Every migration: detect the old version, migrate, record completion, be safe to
   run twice, and leave a recovery path.

---

## 18. Deprecation rules

```
Introduce replacement  →  mark old @deprecated  →  emit _deprecated_*()
   →  document the migration  →  one full minor cycle minimum
   →  remove only in a planned major release
```

- Functions: `_deprecated_function()`. Hooks: `apply_filters_deprecated()` /
  `do_action_deprecated()` — these keep third-party callbacks working.
- The changelog names every deprecation and its replacement.
- Never remove something in the same release that deprecated it.

---

## 19. Code review rules

Every pull request answers these. An AI agent asks them of its own work before
opening the PR.

**Architecture** — Is it in the right layer? Do dependencies still point downward?
Is any abstraction here unearned?

**WordPress** — Does it use the platform API rather than reinventing it? Does it pass
PHPCS with the WordPress ruleset?

**Naming** — Namespace correct? Everything global carries `qevm`? Is any new global
kind of object missing from the matrix in §1?

**Security** — Inputs validated and sanitised? Outputs escaped at output? Capability
checked? Nonce verified? REST `permission_callback` present? SQL prepared? `ORDER BY`
allowlisted?

**Database** — New table justified in writing? Migration numbered, idempotent,
batched? `uninstall.php` updated? Money in integer minor units?

**Compatibility** — Does it change any public name? Does it touch existing data? Does
it need a migration?

**Performance** — Any query inside a loop? Any unpaginated select? Any asset loading
where it is not used? Any date query bypassing the occurrence layer?

**i18n** — Every string wrapped, with the correct domain, numbered placeholders, and
translator comments?

**Accessibility** — Keyboard complete? Labelled? Errors announced? axe-core clean?

**Testing** — Unit tests for domain logic, integration tests for anything touching
the database, a regression test if this fixes a bug?

**Documentation** — Hooks, REST routes, schema changes and decisions all documented
in this PR?

---

## 20. Definition of Done

A feature is not done because it works.

- [ ] Implemented, in the correct layer
- [ ] WordPress Coding Standards pass (PHPCS)
- [ ] Static analysis passes (PHPStan)
- [ ] Unit tests for domain logic; integration tests for anything touching the database
- [ ] All suites green on every supported PHP version
- [ ] Security reviewed against §7
- [ ] No new naming collisions; matrix updated if a new kind of global appeared
- [ ] Assets load only where used
- [ ] Every string translatable, with translator comments
- [ ] axe-core clean on every screen touched
- [ ] Migration written, numbered, idempotent, tested twice
- [ ] `uninstall.php` updated if a table or option was added
- [ ] Hooks and REST routes documented with worked examples
- [ ] ADR written if an architectural decision was made
- [ ] Changelog updated
- [ ] Verified against a real WordPress install, not just the test suite

That last one is not ceremony. Four of the worst bugs in this plugin's history were
invisible to a green unit suite and to code review, and were found only by running
against real WordPress.

---

## 21. Rules for AI coding agents

These apply in addition to everything above.

**Stop and ask before you:**

- Change the architecture or move code between layers
- Add any dependency, runtime or dev
- Create a database table, or change a column's type or meaning
- Create a new global name of a kind not already in the matrix
- Add a public hook or REST route
- Make a breaking change to anything published
- Change the minimum PHP or WordPress version
- Rename a hook, option, capability, post type or route

**Never silently:**

- Change schema · delete data · remove a public API · rename a hook · change a
  shortcode · change a REST contract · introduce a dependency

**Before adding a hook**, check whether an existing one can carry the case.
**Before adding a table**, explain in writing why WordPress-native storage is
insufficient.
**Before adding an abstraction**, state which concrete duplication it removes today.

**Report honestly.** If tests fail, say so and show the output. If a step was
skipped, say which. If something is unverified, say it is unverified. Never describe
work as complete when it is partially done, and never claim a verification you did
not run.

**Work in chunks.** Follow [development-plan.md](development-plan.md). Finish a chunk,
report, let it be reviewed, commit, then move to the next. Do not run ahead.
