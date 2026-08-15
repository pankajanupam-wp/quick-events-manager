# Architecture decision records

Short records of decisions that were expensive to make and would be expensive to
revisit. When someone asks "why is it like this" in two years, the answer is a link
rather than an argument.

Each ADR states the context, the decision, the alternatives that were rejected and
why, and the consequences we accepted — including the bad ones.

**Status values:** `accepted` · `superseded by NNNN` · `proposed`

| # | Decision | Status |
| --- | --- | --- |
| [0001](0001-naming-and-namespace.md) | Prefix `qevm`, namespace `QuickEventsManager\` | accepted |
| [0002](0002-php-and-wordpress-versions.md) | PHP 8.1, WordPress 6.5 | accepted |
| [0003](0003-occurrence-table.md) | Dates live in an indexed occurrence table | accepted |
| [0004](0004-registration-attendee-split.md) | Attendees are rows, not registrations | accepted |
| [0005](0005-storage-strategy.md) | WordPress-native by default, custom tables by exception | accepted |
| [0006](0006-money-and-immutability.md) | Integer minor units; order items are immutable snapshots | accepted |
| [0007](0007-no-runtime-dependencies.md) | No Composer at runtime; hand-written PSR-4 autoloader | accepted |
| [0008](0008-datetime-storage.md) | UTC canonical, local and timezone retained | accepted |
| [0009](0009-module-architecture.md) | Features are modules with a hard on/off boundary | accepted |
| [0010](0010-payment-gateway-scope.md) | Gateway interface + Stripe + WooCommerce only | accepted |
| [0011](0011-scope-boundary.md) | No conference layer in core | accepted |
| [0012](0012-direction-independent-css.md) | Direction-independent CSS instead of an RTL stylesheet | accepted |
| [0013](0013-accessibility-testing.md) | Accessibility is verified in a real browser | accepted |

## Writing a new one

Copy the shape of an existing file. Number sequentially. An ADR is required when a
decision affects the database schema, a public API, the extension model, a dependency,
or the supported platform. It is not required for ordinary implementation choices.

Never edit an accepted ADR to change its decision. Write a new one and mark the old
one `superseded by NNNN`. The record of what we used to think is the useful part.
