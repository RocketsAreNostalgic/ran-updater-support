# Contributing

This package is in a fresh pre-release development line. Keep every change safe
for public review and use Conventional Commits (`feat:`, `fix:`, `docs:`,
`test:`, or `chore:`) so Release Please can prepare version proposals.

Use PHP 8.2 and Node.js 24.11.0. Install Composer dependencies, then run the
repository gate:

```sh
composer install --no-interaction --prefer-dist
composer check
```

`composer check` validates Composer metadata, lints PHP syntax, runs the shared
RAN PHPCS/PHPCompatibility standards, runs PHPStan over production code, runs
the archive-safety contract corpus, naming-enforcement regressions, and the
retained release-workflow contract test. Add
focused contract coverage when changing a public rule. See
[RELEASING.md](RELEASING.md) before changing release automation or preparing a
release.

## Focused quality commands

| Command | Scope |
| --- | --- |
| `composer lint:syntax` | PHP syntax in `src/` and `tests/` |
| `composer standards` | Shared PHPCS/WPCS/PHPCompatibility and RAN-owned method rules |
| `composer standards:fix` | PHPCBF with the same rules and source paths |
| `composer analyze` | Blocking PHPStan level 8 over `src/` |
| `composer test` | Archive-safety, naming-enforcement and release-workflow contracts |
| `composer test:standards` | Positive/negative owned-method checks through the local ruleset |

`composer check` includes syntax, standards, analysis and test checks, plus
strict manifest validation. `standards:fix` is a manual source edit. The former
`composer lint:php` command is now `composer lint:syntax`; focused
`test:contract` and `test:release-control` commands remain available.

`RANOwnedMethods` requires ASCII snake_case for owned PHP methods, including
methods in classes that extend or implement other types. PHP magic methods are
allowed. A required external signature needs a justified method-local
suppression; it does not exempt other methods in the same class. PHPCBF does not
rename methods because declarations and callers must be migrated together.

Do not commit credentials, tokens, private repository details, archives,
temporary files, logs, `vendor`, or dependency caches. Use ordinary issues for
non-sensitive work; follow [SECURITY.md](SECURITY.md) for vulnerabilities and
[SUPPORT.md](SUPPORT.md) for support.
