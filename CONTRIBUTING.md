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
the archive-safety contract corpus, and runs the release-publisher tests. Add
focused contract coverage when changing a public rule. See
[RELEASING.md](RELEASING.md) before changing release automation or preparing a
release.

Do not commit credentials, tokens, private repository details, archives,
temporary files, logs, `vendor`, or dependency caches. Use ordinary issues for
non-sensitive work; follow [SECURITY.md](SECURITY.md) for vulnerabilities and
[SUPPORT.md](SUPPORT.md) for support.
