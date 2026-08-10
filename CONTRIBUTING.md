# Contributing

Thanks for considering it. This is a free, open-source plugin and it stays that way — every contribution benefits everybody who uses it.

## Getting set up

```sh
git clone https://github.com/pankajanupam-wp/quick-events-manager.git
cd quick-events-manager
composer install
npm install          # only if you are touching the blocks
```

Run the tests:

```sh
composer test
```

Run a real WordPress to click around in:

```sh
npx @wordpress/env start   # localhost:8888, admin / password
```

If you have no Composer, download `https://phar.phpunit.de/phpunit-10.5.phar` and run `php phpunit.phar` instead. The suite needs no autoloader.

## Before you open a pull request

- `composer test` passes.
- `composer lint` passes (it is just `php -l` over every file).
- New behaviour has a test. If it is a bug fix, the test should fail without your fix.
- No new dependency without a note in the PR explaining why it is necessary.

## What we are looking for

Good pull requests, roughly in order of how welcome they are:

1. Bug fixes with a failing test.
2. Accessibility and internationalisation fixes.
3. Performance fixes with a before and after.
4. Features already on the roadmap in [docs/roadmap.md](docs/roadmap.md).
5. New features — please open an issue first so we can agree on the shape.

## House style

The code follows WordPress coding standards: tabs, `array()` long syntax, Yoda conditions, spaces inside parens. PHP 7.4 is the floor, so no arrow functions in shipped code, no typed properties, no constructor promotion.

Two things matter more than style:

**Comments explain why, not what.** `// Increment the counter` earns nothing. A comment that records the trap somebody fell into last time is worth keeping forever.

**Everything user-facing is escaped on output and sanitised on input.** Every `$wpdb` call is prepared. There are no exceptions to this and a PR that breaks it will not be merged, however good the feature is.

## New features are modules

The plugin's organising idea is that a fresh install does one thing and everything else is opted into. A feature that is off must register no hooks, create no tables and enqueue no assets.

If you are adding something substantial, add a class implementing `QEM\Modules\Module` and register it through the `qem_modules` filter. See `includes/Registration/RegistrationModule.php` for a worked example.

## Testing philosophy

The unit suite stubs WordPress, which makes it fast but means it cannot catch a change in core's own behaviour — and every bug listed in the README's regression table was found by running against a real install, not by the suite.

So: if your change touches `$wpdb`, a REST route, or a core hook whose behaviour you are relying on, please check it in `wp-env` and say so in the PR.

## Reporting bugs

Open an issue with the WordPress version, the PHP version, what you did, what you expected and what happened. A failing test is the ideal bug report, but a clear description is plenty.

Security issues go to the process in [SECURITY.md](SECURITY.md) instead — please do not open a public issue for those.
