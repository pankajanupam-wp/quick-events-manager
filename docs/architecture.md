# Architecture

## The organising idea

A fresh install does one thing: events. Everything else is a module the site owner switches on.

This is not a UI convention. A module that is off has `register()` never called, so it adds no hooks, registers no post types, creates no tables and enqueues no assets. The toggle is a real boundary.

Two things follow from that, and both are why the design is worth its complexity:

1. **A simple site stays simple.** Somebody who wanted a list of events is never shown a ticketing screen, and never pays for one in queries or page weight.
2. **The roadmap is safe.** Every future feature — ticketing, check-in, payments — arrives as a new module that is off by default, and cannot destabilise an install that never turns it on. A stable plugin can keep growing.

## Request lifecycle

```
quick-events-manager.php
  ├─ defines constants
  ├─ registers the autoloader          QEM\ -> includes/
  ├─ registers activation hooks
  └─ Plugin::instance()->boot()
        ├─ plugins_loaded  ─► Registry->boot()
        │                       ├─ reads qem_enabled_modules
        │                       └─ register() on enabled modules only
        ├─ init            ─► textdomain
        └─ admin_init      ─► Installer::maybe_upgrade()
                              Migrator::maybe_migrate()
```

Modules boot on `plugins_loaded` rather than immediately, so another plugin has a chance to add its own through the `qem_modules` filter before the registry is first built.

Upgrades run on `admin_init`, not activation, because a plugin updated in place through the dashboard or WP-CLI never fires its activation hook. Both routines compare a stored version first and cost one option read when there is nothing to do.

## Layers

```
Admin/          Screens: settings, features, attendees
Frontend/       Templates, renderer, shortcodes, schema, ICS
Blocks/         Block registration (render_callback -> Frontend\Renderer)
Rest/           Read endpoints
    │
    ▼
Events/         PostType, Meta, Event, Query        ─┐
Registration/   Service, Repository, Registration    │ domain
Modules/        Module, Registry                     │
Install/        Installer, Migrator                 ─┘
```

Presentation depends on the domain; the domain never depends on presentation. `Repository` is the only class that talks to `$wpdb` about registrations, which is what makes the escaping story auditable — every value reaching a query passes through `prepare()` in one file.

## Why classes here and functions in the sibling plugins

`change-howdy` and `shortcodes-in-sidebar` are prefixed functions with no namespace and no autoloader. That is right for three files.

This plugin is thirty. The module system needs an interface, and services and repositories need to be substitutable for tests. Plain functions at this size becomes a few thousand lines of `qem_` prefixes with no seams in it.

The divergence is justified by size, not taste. What has *not* changed: no DI container, no service locator, no framework. A `Plugin` singleton wires the registry, and each module owns its own hooks.

## One renderer per feature

The `qem/event-list` block and the `[qem_event_list]` shortcode both call `Renderer::event_list()`. Two entry points, one implementation.

This matters more than it looks. Markup, escaping and capability decisions are made in exactly one place per feature, so a security fix cannot land in the shortcode and miss the block. The module gate lives there too, for the same reason — three separate things render the registration form, and the check has to be somewhere all three pass through.

## Rendering into themes

Event details are added with a `the_content` filter rather than a `single-qem_event.php` template.

A template takeover only works in classic themes: a block theme renders singles through its own block template and never looks at the plugin's file. Filtering the content works in both, because a block theme's template still ends up rendering the `core/post-content` block, which applies `the_content`.

The filter carries four guards. Three are obvious — `is_singular()`, `in_the_loop()`, `is_main_query()` — because `the_content` also runs for excerpts, feeds, REST responses and any post rendered in a sidebar.

The fourth is a re-entrancy flag, and it is not theoretical. Rendering the details calls `get_the_excerpt()`; for a post with no manual excerpt, core generates one with `wp_trim_excerpt()`, which applies `the_content` — landing straight back in the filter with all three other guards still true. That recursion exhausted PHP's memory limit and served a blank page on every event.

## Blocks render in PHP

All three blocks are dynamic, with a PHP `render_callback`. The editor script is the only JavaScript the plugin ships and it never loads for visitors.

`block.json` is read from `build/`, which is gitignored and produced by CI and by the deploy workflow. It is deliberately not in `.distignore` — the compiled blocks are the one build artefact that has to ship, and a test fails if somebody adds it.

## Concurrency

Capacity uses insert-then-rank, not check-then-insert. See [data-model.md](data-model.md) and the extended comment in `Repository::insert_with_capacity()`; the short version is that checking then inserting has a race that a transaction does not close under MySQL's default isolation, and that ranking after the insert has no such window.

## Testing

Two suites, because they answer different questions.

The **unit suite** stubs WordPress and runs in about a second on any PHP 7.4+ machine. It covers logic that is genuinely independent of WordPress: timezone conversion, sanitisation, iCalendar escaping and folding, CSV formula defusing, release metadata.

It deliberately does not attempt `$wpdb`. SQL cannot be meaningfully faked — a stub that returns what you tell it proves only that you can write a stub.

**A real install** covers the rest, and this is not optional. Every bug in the regression table in the README was found by running against WordPress, not by the suite:

- the recursion above
- `sanitize_title` returning the request object when used as a bare `sanitize_callback`
- the registration form rendering with its module switched off
- a rate limit that would lock out an entire venue behind one NAT gateway

None of those were visible to a stub, and three of the four were invisible to code review as well.

## Security posture

- Every `$wpdb` call is prepared. `ORDER BY` columns are whitelisted rather than interpolated, because `prepare()` would quote an identifier into a string literal and MySQL would sort every row by the same constant.
- Every output is escaped at the point of output.
- Every admin action checks a capability *and* a nonce.
- Every REST route declares an explicit `permission_callback`; a test counts them against the number of routes.
- The public form is nonce-protected, rate-limited and honeypotted, and uses POST-redirect-GET so a refresh cannot resubmit.
- CSV cells beginning `=`, `+`, `-` or `@` are prefixed with a tab, so an attendee's name cannot become a formula that runs on the organiser's machine.
- No IP addresses are stored; rate limiting keys on a salted hash.
- Custom capabilities rather than mapping onto `post`, so a check-in role can exist later without granting the right to edit blog posts.
