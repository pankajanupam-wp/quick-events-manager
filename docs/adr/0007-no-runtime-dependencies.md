# ADR-0007 — No Composer at runtime; hand-written PSR-4 autoloader

**Status:** accepted · 2026-08-10

## Context

Composer is the standard PHP dependency tool, and using it for autoloading is the
default in modern PHP. In WordPress it carries a specific hazard.

Plugins run in one PHP process with no isolation. Two plugins that each bundle their
own `vendor/` containing different versions of the same package produce a conflict
that neither author can fix: whichever loads first wins, and the other gets a class
whose API it does not expect. This is a well-known and recurring source of WordPress
plugin conflicts, and it has no clean solution short of scoping every namespace.

The plugin also has to pass WordPress.org review, ship a package small enough to be
reasonable, and remain human-readable source.

## Decision

**Zero runtime dependencies.** The shipped package contains no `vendor/` directory.

Autoloading is a hand-written PSR-4 autoloader — roughly fifteen lines — mapping
`QuickEventsManager\Foo\Bar` to `src/Foo/Bar.php`.

Composer is used for **development only**: PHPUnit, PHPCS with the WordPress ruleset,
PHPStan. `vendor/` is in both `.gitignore` and `.distignore`. No `composer.lock` is
committed, so each PHP version in CI resolves the tool versions that support it.

JavaScript uses `@wordpress/scripts` only, and prefers the `@wordpress/*` packages
WordPress already enqueues over bundling React, lodash or a date library again.

Before adding any dependency, runtime or dev, five questions must be answered in the
pull request: what it solves that ~100 lines would not, whether its licence is
GPL-compatible (verified, not assumed), whether it is maintained, whether WordPress
already provides it, and what the removal plan is if it is abandoned.

## Alternatives considered

**Composer autoload in production.** Standard practice outside WordPress. Rejected for
the conflict above, and because `vendor/` in a WordPress.org package is bytes users
download without benefit when the dependency count is zero anyway.

**Composer with a scoper** (PHP-Scoper or similar) to rewrite namespaces at build
time. This is the correct answer for a plugin that genuinely needs third-party
libraries. Rejected as unnecessary complexity when the dependency list is empty — a
build step, a scoping config and a class of confusing stack traces, all to solve a
problem we do not have.

**A generic class-map autoloader.** Faster in principle. Rejected: PSR-4 is one
`str_replace` and one `is_readable`, needs no build step, and no measurement suggests
autoloading is a cost worth optimising here.

## Consequences

**Good.** Cannot conflict with another plugin's dependencies, because there are none.
Smaller package. Source stays readable, which WordPress.org review requires. No build
step for PHP at all — the only build is blocks.

**Bad.** Anything a library would provide must be written and maintained here. This
has already been accepted twice: iCalendar generation (RFC 5545 for one `VEVENT` is
short and well-specified) and QR code generation in stage 8 will be the next test. If
a future need is genuinely large — a PDF renderer, for instance — this ADR should be
revisited and superseded rather than quietly worked around.

**Bad.** Contributors must run `composer install` to get the dev tools, and remember
that nothing they add there may be referenced from `src/`. PHPStan configuration
enforces this by treating `vendor/` as unavailable to production code.
