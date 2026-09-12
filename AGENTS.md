# Agent guidance

This is the independent `ran/updater-support` Composer library. Keep committed
files suitable for public distribution. Shared utilities need concrete consumers
with equivalent behavior; keep updater orchestration in its owning package.

## RAN quality profile

This repository uses the RAN `php-library` quality profile. PHP coding and
compatibility ancestry comes from `ran/coding-standards` through
`RANWordPressLibrary`; the tracked Composer lock binds the reviewed candidate
revision until the shared package receives its first versioned release.

Keep package identity and applicability local: the `RAN\UpdaterSupport\V1`
namespace, PHP `^8.2` support range, source paths, tests, and any future narrow
exceptions belong in this repository. Do not copy shared rules back into local
configuration and do not add a WordPress-version floor unless this package
actually claims one.

- Run `composer check` before committing and retain consumer contract fixtures.
- `composer check` must retain strict manifest validation, PHP syntax lint,
  shared PHPCS/PHPCompatibility checks, PHPStan, and the package's existing
  contract/release-publisher tests.
- Preserve the `RAN\UpdaterSupport\V1` public namespace and independent beta line.
- Before changing release automation or preparing a release, read `RELEASING.md`.
- Every pull request needs independent review against its exact base/head SHAs.
- Merging requires explicit owner authorization of the exact PR and merge method.
- Keep credentials, local logs, vendor files and internal planning out of commits.

## Blacksmith AI prohibition

Blacksmith is approved only as GitHub Actions runner infrastructure where a
repository workflow explicitly selects a Blacksmith runner.

- Never invoke, delegate work to, tag, enable, or otherwise use Blacksmith
  [code]smith, `@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning,
  Blacksmith Testbox agents, or any other Blacksmith AI/agent feature.
- Do not trigger "Enable autofix", ask [code]smith to investigate or repair CI,
  or call Blacksmith agent/MCP/CLI/API features that perform AI inference.
- If CI fails, inspect GitHub Actions logs directly and diagnose or fix the
  failure without delegating it to Blacksmith AI.
- This is a cost-control requirement. Do not override it for convenience, CI
  failures, review comments, or suggestions presented by GitHub or Blacksmith
  UI.
