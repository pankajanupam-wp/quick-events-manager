# Accessibility

What has been checked, how, and what has not. The last section is the important
one: a page of claims with no limitations on it is a page nobody should believe.

## What is checked, and where

Every screen this plugin adds is scanned by [axe-core](https://github.com/dequelabs/axe-core)
through Playwright, on every run of the suite. A violation fails the build.

```sh
npx playwright test --config=tests/e2e/playwright.config.js
```

| Screen | Where |
| --- | --- |
| Event archive, single event | `tests/e2e/accessibility.spec.js` |
| Registration form — empty, with guest rows, in its error state | same |
| Confirmation, waiting-list and sold-out states | same |
| Calendar month view | same |
| Events list, event editor | `tests/e2e/admin-accessibility.spec.js` |
| Attendee screen — choosing an event, and with attendees on it | same |
| Check-in door — choosing, running, after an arrival | same |
| Features, Settings, Email templates, Dates | same |

The rules are WCAG 2.0 A and AA, 2.1 A and AA, and 2.2 AA. Beyond the automated
rules, the suite also asserts three things a scanner cannot:

- The registration form and the check-in door are **completable with the
  keyboard alone**, in a sensible order.
- A failed submission **moves focus to the field that failed**, rather than
  announcing an error somewhere the person is not.
- Hidden guest rows are **not reachable by tab** while they are hidden, so the
  keyboard order matches what is on screen.

## Decisions worth knowing

**Errors are never colour alone.** A field that failed carries `aria-invalid`,
its message is text, and the message is tied to the input with
`aria-describedby`. A red border on its own says nothing to somebody who cannot
see it and little to somebody who cannot distinguish it.

**The door is a live region.** What happened to the last scan is announced
without moving focus, because focus belongs in the code field where the next
ticket is about to be typed.

**The door works with no JavaScript at all.** That is the mechanism rather than
the fallback: every action is a form post. It is slower, and it is completely
reliable on a phone with two bars of signal in a hall with thick walls.

**Nothing depends on hover.** Every action is a button or a link, reachable and
operable by keyboard and touch.

**Counts are said in words.** "12 of 40 in", not a bare number beside an icon.

## Known limitations

**WordPress's own chrome is excluded from the admin scans.** The admin menu, the
toolbar, the footer and the screen-options panel are core's markup, and their
violations are core's to fix — a build that fails on somebody else's markup is a
build everybody learns to ignore. The cost is real and worth stating: page-level
rules that need the whole document, such as landmark structure and heading order
across the page, are not evaluated on admin screens.

**The theme is excluded from the front-end scans**, for the same reason. What is
scanned is this plugin's markup inside whatever theme is active, and a theme with
poor contrast or a broken skip link will not fail this suite.

**One browser engine.** axe-core reports on the computed accessibility tree,
which does not meaningfully differ between engines for static markup. Running
three would triple the time to re-derive the same answer.

**The payment screen has not been scanned.** It cannot render without working
Stripe keys, and there are none on the machine this was built on. Its markup is a
heading, two paragraphs, Stripe's own card iframe and one button with a live
region beside it for errors — but that is a description, not a scan. The card
fields themselves are Stripe's markup and outside this plugin's control.

**Automated rules find perhaps a third of real problems.** Everything above is a
floor, not a ceiling. No screen reader has been used on these screens by somebody
who uses one daily, and that is the check that would find what the rest of this
misses. If you use one and something here is wrong, please open an issue — that
report is more valuable than another rule in the suite.

**Blocks are not scanned inside the editor.** The three blocks render through PHP
on the front end, which is what the front-end scans cover; their editor previews
are the block editor's own controls and have not been separately audited.
