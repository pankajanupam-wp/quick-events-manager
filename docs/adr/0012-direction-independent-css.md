# ADR-0012 — Direction-independent CSS instead of an RTL stylesheet

**Status:** accepted · 2026-08-15

## Context

Chunk C2.6 in [development-plan.md](../development-plan.md) asked for an `rtl.css`,
and [engineering-standards.md](../engineering-standards.md) §16.8 states that one
ships and is tested. Both were written before anybody counted what would go in it.

The entire right-to-left surface of `assets/css/frontend.css` is five declarations:
`border-left` on `.qevm-notice` and its three colour variants (`--success`, `--error`,
`--info`), plus `left: -9999px` on the honeypot. Everything else is grid and flex,
which mirror on their own from the `dir` attribute the theme already sets.
`assets/css/admin.css` had nothing direction-dependent in it at all.

WordPress offers two mechanisms and neither is proportionate to four borders and an
offset. `wp_style_add_data( $handle, 'rtl', 'replace' )` swaps the whole stylesheet
for a `-rtl` twin, which means a second complete copy of roughly 350 lines maintained
so that four of them can differ. The additive form loads an override file after the
main one — smaller, but still a second file that has to be remembered every time
anybody adds a margin.

Both are a standing invitation to drift, and the drift lands in a file nobody on this
project reads in the language it exists for. Nobody here would notice a stale `-rtl`
copy; the people who would notice are the users it was written for.

## Decision

CSS logical properties, in the one stylesheet. No RTL file exists.

- `border-inline-start` and `border-inline-start-color` for the notice accent.
- `padding-inline-start` for the error summary list.
- `inset-inline-start` for the honeypot offset.

`border-inline-start` is the left border in English and the right border in Arabic,
decided by the browser from `dir`. There is nothing to keep in step because there is
only one file.

**The honeypot keeps both forms, in that order.** `left: -9999px !important` stays as
a fallback, with `inset-inline-start: -9999px !important` immediately after it so the
logical property wins where it is understood. Ordering is load-bearing rather than
tidy: if that offset ever failed to apply, the honeypot would render as a visible,
labelled "Website" field in the middle of the registration form — a field people fill
in, and are then refused for filling in, with no way to work out why.

**The guard is `tests/unit/StylesheetTest.php`.** It reads both shipped stylesheets as
text and fails on any physical direction property — `margin-left`, `margin-right`,
`padding-left`, `padding-right`, the `border-left`/`border-right` family, `float`,
`clear` — and on `text-align: left` or `right`, whose direction-independent values are
`start` and `end`. A third test asserts the honeypot carries both offsets with the
physical one first. The test was verified by adding a `margin-left` to the frontend
stylesheet and watching it fail, then removing it.

## Alternatives considered

**`wp_style_add_data( ..., 'rtl', 'replace' )`.** The documented WordPress way.
Rejected: a full duplicate of the stylesheet to change four declarations guarantees
drift, and the copy that drifts is the one this project cannot read.

**An additive RTL override file.** Smaller than the twin, and it only holds the
overrides. Rejected for the same class of problem — a second file that every future
margin must remember, enforced by nothing.

**Do nothing.** Arabic, Hebrew and Persian are large WordPress markets. A notice with
its accent bar down the wrong edge is visibly broken, and an off-screen honeypot that
lands on-screen is worse than broken.

## Consequences

**Good.** One stylesheet, no second copy, no drift, no build step. The browser decides
from `dir`, which is where that decision belongs. A new rule written with logical
properties is correct in every language the day it is written, and one written with
physical ones fails the unit suite before it reaches review.

**Bad.** This gives up the ability to make right-to-left *look different* rather than
merely mirrored — different imagery, a different type scale, different spacing for a
script with different metrics. This plugin has no such need today. If one appears, an
RTL file can be added then, and this ADR superseded.

**Bad.** The unit test can only prove the absence of physical properties. It cannot
prove a page renders correctly under `dir="rtl"`; that stays a manual check, and
belongs with the C10.6 RTL verification.

**Bad.** §16.8 of the engineering standards still reads "`rtl.css` ships and is
tested". The intent behind it — that direction handling is verified rather than
assumed — is met; the named artefact is not, deliberately. This ADR is the record of
why, and the standard should be reworded when it is next revised.

**Cost.** The next person will write `margin-left` out of habit, because in English it
looks correct and renders correctly on the screen in front of them. That is precisely
what the test exists to catch, and the failure message names the `-inline-` equivalent
so the fix is obvious rather than a lookup.
