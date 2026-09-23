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
the archive-safety contract corpus, and runs the retained release-workflow contract test. Add
focused contract coverage when changing a public rule. See
[RELEASING.md](RELEASING.md) before changing release automation or preparing a
release.

## Focused quality commands

| Command | Scope |
| --- | --- |
| `composer lint:syntax` | PHP syntax in `src/` and `tests/` |
| `composer standards` | Shared PHPCS/WPCS/PHPCompatibility rules |
| `composer standards:fix` | PHPCBF with the same rules and source paths |
| `composer analyze` | Blocking PHPStan level 8 over `src/` |
| `composer test` | Archive-safety and release-workflow contracts |

`composer check` includes syntax, standards, analysis and test checks, plus
strict manifest validation. `standards:fix` is a manual source edit. The former
`composer lint:php` command is now `composer lint:syntax`; focused
`test:contract` and `test:release-control` commands remain available.

Do not commit credentials, tokens, private repository details, archives,
temporary files, logs, `vendor`, or dependency caches. Use ordinary issues for
non-sensitive work; follow [SECURITY.md](SECURITY.md) for vulnerabilities and
[SUPPORT.md](SUPPORT.md) for support.
