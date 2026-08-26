# ADR-0013 — Accessibility is verified in a real browser, and what that cannot cover

**Status:** accepted · 2026-08-15

## Context

[engineering-standards.md](../engineering-standards.md) §15 makes accessibility a
build standard applied per feature rather than an audit at the end, and its first rule
is unambiguous: "axe-core runs in CI over every public screen. A violation fails the
build." AC-6 in the acceptance criteria names the screens.

Before chunk C2.7 there was no JavaScript test tooling in the repository at all.
`package.json` had two scripts — `build` and `start` — and one dev dependency,
`@wordpress/scripts`. The standard was an intention with nothing behind it.

The thing a browser is needed for is the thing PHP cannot answer: what the
accessibility tree actually looks like once the markup, the CSS and the script have
all been applied. Markup assertions in PHPUnit prove an attribute is present in a
string. They cannot tell whether a control is reachable, whether contrast passes, or
whether an element the CSS hides is still in the tab order.

## Decision

Playwright with `@axe-core/playwright`, in `tests/e2e/`, run by `npm run test:a11y`.
A new `accessibility` job in `.github/workflows/tests.yml` runs it on every push and
pull request. `tests/e2e/fixture.php` seeds one event with registration switched on,
idempotently, through WP-CLI, and flushes rewrite rules — wp-env starts from an empty
database, so without it every test looks at a site with nothing on it and passes for
the wrong reason.

This is not a general end-to-end suite and should not become one. PHP behaviour stays
in `tests/integration/` against real WordPress and real MySQL, which is faster, easier
to debug and needs no browser.

**Port 8888, not 8889.** The run needs a site whose content survives it. 8889 is the
throwaway one the PHP integration suite resets.

**One browser, Chromium.** axe reports on the computed accessibility tree, which does
not meaningfully differ between engines for static markup. Three browsers would triple
the slowest job in the matrix to re-derive the same result.

**`retries: 0`.** A violation fails the build, so a flaky pass is worse than useless.
An accessibility result that only appears on the second attempt is one nobody can act
on.

**WCAG 2.2 AA tags only** — `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`.
`best-practice` is excluded deliberately: it flags advice rather than conformance, and
a build that fails on advice gets ignored.

**The theme is excluded from the scan** — `header`, `footer`, `.wp-block-navigation`,
`.wp-site-blocks > header`. This was not the original intention. The first run failed
every single page on one violation: Twenty Twenty-Five's navigation block puts a
`<div>` directly inside a `<ul>`, which axe correctly reports as the `list` rule. It
is a real defect and it is in WordPress, not in this plugin. Left in, the suite would
fail on every commit for a reason nobody working here can fix — and a build that
always fails is a build everybody learns to ignore, which costs more than the rule was
worth.

The suite covers more than axe, because the interesting failures are not the
mechanical ones:

- The form can be completed with the keyboard alone, tabbing to the submit button
  rather than clicking it. If anything in between is unreachable, the loop never
  arrives and the test says so.
- Hidden guest rows are `disabled`, and therefore out of the tab order rather than
  merely invisible.
- A failed submission moves focus to the first invalid field. Post/redirect/get means
  the error arrives in the initial HTML, and a live region already present when the
  document parses announces nothing at all — without the focus move, a screen reader
  user is told nothing and lands at the top of a page that looks unchanged.
- The page reflows at 320px with no sideways scrolling.

The error-state test submits for real rather than building a URL by hand, because the
error state is assembled from a transient the handler wrote; a faked query string
would test markup that never renders in production.

**It was verified that it can fail.** Removing `for="qevm-email"` from a label
produced `[critical] label: Form elements must have labels` and failed six tests.

## Alternatives considered

**pa11y.** Simpler to wire up and it wraps the same engine. Rejected: no browser
automation, so the error-state and keyboard tests are impossible to express — and
those are the tests that catch the failures users actually hit.

**jsdom plus axe.** No browser download, so a much faster job. Rejected on evidence
from this project: jsdom had already proved an unreliable oracle when checking the
`[hidden]` cascade, reporting `display: none` both with and without the rule under
test, which is why that was verified in real Chrome. It does no layout and not the
full cascade, so contrast and reflow cannot be evaluated at all.

**A manual audit only, at stage 10.** Rejected by the standard itself: accessibility
is a build standard, and retrofitting is where it becomes expensive. The manual pass
still happens; it is not a substitute for the mechanical one, and the mechanical one
is not a substitute for it.

## Consequences

**Good.** A real accessibility regression fails the build rather than reaching a user.
Contrast, missing labels, broken ARIA references and heading order are checked on
every commit, over the archive, the single event, the registration form in three
states, the confirmation and the form at 320px.

**Bad.** CI now needs Node, a browser download and a running WordPress. It is the
slowest job in the matrix, and the only one that can fail for infrastructure reasons.

**Bad.** The theme exclusion means the suite cannot see whole-document problems.
Page-level rules that need the entire page — landmark uniqueness, and heading order
relative to the theme's own headings — are no longer checked automatically. They
belong to the manual pass recorded in `docs/accessibility.md` at stage 10, and that is
a promise this ADR is making on stage 10's behalf.

**Bad, and worth stating plainly.** axe catches roughly a third of WCAG: the
mechanical third. It cannot judge whether a label makes sense, whether focus order is
logical, or whether an error message helps the person reading it. A clean run is a
floor, not a certificate, and treating a green job as proof of an accessible plugin is
the specific way this kind of tooling makes things worse.

**Cost.** `package-lock.json` is gitignored, so the job runs `npm install` rather than
`npm ci`. Every run resolves current dependencies, which is how a break in a
dependency is found here rather than at a user's site — at the price of a build that
can fail for reasons unrelated to the commit that triggered it. The PHP side makes the
same trade for the same reason ([ADR-0007](0007-no-runtime-dependencies.md)), so at
least it is consistent.
