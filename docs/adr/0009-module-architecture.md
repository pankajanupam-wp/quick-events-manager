# ADR-0009 — Features are modules with a hard on/off boundary

**Status:** accepted · 2026-08-10

## Context

The finished plugin contains events, registration, custom fields, a calendar,
recurrence, email templating, ticketing, check-in and payments. Presented all at once,
that is a dashboard nobody installing "a list of meetups" wants to see, and it is the
specific complaint users make about every large event plugin in the directory.

A settings screen that hides features is not enough. Hidden features still register
hooks, still create tables, still enqueue assets, and still cost every page load.

## Decision

Every feature beyond core events is a `Modules\Module`:

```php
interface Module {
    public function id(): string;
    public function title(): string;
    public function description(): string;
    public function level(): int;        // 0 core, 1, 2, 3
    public function is_required(): bool;
    public function register(): void;    // hooks — only called when enabled
    public function activate(): void;    // schema, roles — once, on enable
    public function deactivate(): void;  // never destroys data
}
```

`Modules\Registry` reads one autoloaded option and calls `register()` on enabled
modules only. Modules boot on `plugins_loaded` so a third party can add its own
through the `qevm_modules` filter before the registry is first built.

**A disabled module registers no hooks, creates no tables and enqueues no assets.**
The toggle is a real performance boundary, not a UI filter. This is asserted by test,
not by intention.

Three further rules:

- **Modules never depend on each other.** Where a dependency looks necessary — check-in
  needs attendees — the dependency is on the *domain*, which is always present, not on
  the module.
- **Disabling never destroys data.** A site owner switching registration off to
  simplify their admin expects their attendee list to be there when they switch it
  back on. Tables are dropped only by `uninstall.php`, and only on opt-in.
- **The gate lives at the render boundary**, not scattered through callers. Three
  things render the registration form — a shortcode, a block and content injection —
  and the check has to be somewhere all three pass through. Gating per caller has
  already produced a bug here once, where the form rendered with its module off.

## Alternatives considered

**One monolithic plugin, everything always on.** Simpler code. Rejected: it is the
thing users complain about, and it makes every future feature a risk to every
existing install.

**Separate add-on plugins per feature.** The dominant commercial model. Rejected: it
is the WP Event Manager pattern — 37+ add-ons and a support queue full of people
assembling a product from parts. One plugin, switchable, is the better shape for a
free plugin with one maintainer.

**Feature flags in a settings array.** Cheaper to build. Rejected: a flag checked at
call sites is not a boundary. Code still loads, hooks still fire, and the flag gets
forgotten at one of the call sites — which is exactly the bug referenced above.

## Consequences

**Good.** A simple site stays simple, measurably. Every future feature arrives off by
default and cannot destabilise an install that never enables it. The plugin can keep
growing without getting heavier for people who do not need the growth.

**Bad.** Every feature must be written so it can be absent, which forbids convenient
direct calls between features and pushes shared behaviour down into the domain layer.
That is a discipline cost on every pull request.

**Bad.** The test matrix is combinatorial in principle. Bounded in practice by the
no-inter-dependency rule: integration tests run all-on and all-off, plus each module
alone.

**Note.** Modules are not a release-staging device. 26.0 ships every module in
[development-plan.md](../development-plan.md) at once. They exist so a user who wants
a list of meetups is not handed a payment gateway screen.
