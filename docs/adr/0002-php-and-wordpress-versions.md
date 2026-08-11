# ADR-0002 — PHP 8.1, WordPress 6.5

**Status:** accepted · 2026-08-11

## Context

The rewrite currently declares `Requires PHP: 7.4` and `Requires at least: 5.0`.
Both were inherited rather than chosen, and both have costs.

PHP version distribution across WordPress installations, from
`api.wordpress.org/stats/php/1.0/` (August 2026):

| Version | Share | Cumulative "this or newer" | Security support |
| --- | ---: | ---: | --- |
| < 7.4 | 6.1% | 100% | all ended |
| 7.4 | 17.7% | 93.9% | **ended Nov 2022** |
| 8.0 | 4.2% | 76.2% | **ended Nov 2023** |
| 8.1 | 11.9% | **72.0%** | ended Dec 2025 |
| 8.2 | 24.9% | 60.1% | active |
| 8.3 | 24.6% | 35.2% | active |
| 8.4 | 8.2% | 10.6% | active |
| 8.5 | 2.5% | 2.5% | active |

Every version at or below 8.1 is out of security support. 60% of installations are
already on 8.2 or newer.

Two architectural requirements point at 8.1 specifically:

- **Separate state machines** ([database.md](../database.md)) — registration status,
  order status, transaction kind, occurrence status, check-in method are five
  distinct vocabularies. Backed enums (8.1) model these with compile-time safety;
  class constants do not.
- **Financial immutability** ([ADR-0006](0006-money-and-immutability.md)) — `readonly`
  properties (8.1) make an order-item snapshot unmodifiable at the language level
  rather than by convention and code review.

There is also a tooling consequence. `composer.json` currently pins PHPUnit to
`^9.6 || ^10.5` with a note explaining why: PHPUnit 11 replaces `@dataProvider` with
PHP 8 attributes, which cannot be used while 7.4 is supported. Dropping 7.4 removes
that constraint.

## Decision

**`Requires PHP: 8.1`** and **`Requires at least: 6.5`** (WordPress).

WordPress refuses to install or activate a plugin whose requirements the site does
not meet, so incompatible sites see a clear message rather than a fatal error.

Permitted language features: enums, readonly properties, constructor promotion,
union types, `match`, nullsafe operator, named arguments, first-class callables,
`never` return type. Typed properties and return types are expected, not optional.

The WordPress floor of 6.5 is chosen so that block registration from `block.json`,
`wp_date()`, `wp_timezone()`, the privacy exporter APIs and modern block-theme
rendering are all simply present, with no capability shims or version branches in
our code.

## Alternatives considered

**Stay on PHP 7.4.** Reaches 93.9% of installations — 22 points more. Rejected: 7.4
has been unsupported for nearly four years, it blocks enums and `readonly` which two
architectural decisions depend on, it keeps the PHPUnit cap, and shipping a *new*
plugin in 2026 born on EOL PHP is a maintenance debt taken on for free.

**PHP 8.0.** Reaches 76.2%, only 4 points better than 8.1. Rejected: the 4 points buy
nothing, and 8.0 lacks exactly the two features that motivated moving at all.

**PHP 8.2.** Reaches 60.1%. Attractive for readonly classes and DNF types, but neither
is needed, and 12 points of reach is a real cost for a convenience.

## Consequences

**Good.** Enums and `readonly` become available where the architecture wants them.
PHPUnit 11+ with attributes. No compatibility branches for old PHP. The CI matrix
shrinks to 8.1–8.5.

**Bad.** 22% of WordPress installations cannot install the plugin. For a plugin whose
purpose is community use, that is the real cost, and it was accepted knowingly by the
maintainer on 2026-08-11 rather than assumed by the architecture.

**Mitigation.** The share on unsupported PHP falls every month; by the time 26.0
publishes the gap will be smaller than the table above. Sites on 7.4 see a clear
"requires PHP 8.1" notice, not a white screen.

**Reversal cost.** Low now, high later — code written against enums and `readonly`
cannot be trivially back-ported. This is why the decision was taken before stage 0
rather than during it.

## Follow-through

Everything this unblocks, all in stage 0:

| Where | Change |
| --- | --- |
| `quick-events-manager.php` | `Requires PHP: 8.1`, `Requires at least: 6.5` |
| `readme.txt` | Same two headers, kept in step |
| `composer.json` | `"php": ">=8.1"`; PHPUnit cap removed |
| `.github/workflows/tests.yml` | Matrix becomes 8.1 · 8.2 · 8.3 · 8.4 · 8.5 |
| `phpstan.neon.dist` | `phpVersion: 80100` |
| `docs/development-plan.md` | C0.3, then C0.5 introduces the first enums |

The PHPUnit cap in `composer.json` carried a note explaining that PHPUnit 11 replaces
`@dataProvider` with PHP 8 attributes, which could not be used while 7.4 was
supported. That constraint is now gone and the note should be deleted with it.
