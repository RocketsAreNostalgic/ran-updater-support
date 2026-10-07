# Agent guidance

This is the independent `ran/updater-support` Composer library. Keep committed
files suitable for public distribution. Shared utilities need concrete consumers
with equivalent behavior; keep updater orchestration in its owning package.

## RAN quality profile

This repository uses the RAN `php-library` quality profile. PHP coding and
compatibility ancestry comes from `ran/coding-standards` through
`RANWordPressLibrary`; the tracked Composer lock binds the published v1.0.0 release
under the `^1.0` development constraint. This repository explicitly enables
`RANOwnedMethods` across its existing first-party PHP scope, including tests.
Owned methods must use ASCII snake_case even in derived/implementing classes.
Required external signatures need a justified method-local suppression;
inheritance is not a blanket naming exception. `composer test:standards`
checks this enforcement through the repository ruleset.

Keep package identity and applicability local: the `RAN\UpdaterSupport\V1`
namespace, PHP `^8.2` support range, source paths, tests, and any future narrow
exceptions belong in this repository. Do not copy shared rules back into local
configuration and do not add a WordPress-version floor unless this package
actually claims one.

- Run `composer check` before committing and retain consumer contract fixtures.
- `composer check` must retain strict manifest validation, PHP syntax lint,
  shared PHPCS/PHPCompatibility checks, PHPStan, and the package's existing
  contract and release workflow tests.
- Use `composer lint:syntax` for parser checks, `composer standards` /
  `composer standards:fix` for PHPCS/PHPCBF, `composer analyze` for level-8
  production and maintained-PHP analysis, and `composer test` for the ordinary test aggregate.
- Preserve the `RAN\UpdaterSupport\V1` public namespace and independent beta line.
- Before changing release automation or preparing a release, read `RELEASING.md`.
- Every pull request needs independent review against its exact base/head SHAs.
- Merging requires explicit owner authorization of the exact PR and merge method.
  For ordinary iterative or agent-developed PRs, prefer squash so the reviewed PR lands as one meaningful default-branch commit. Use a merge commit only when the PR's internal commit sequence is deliberately meaningful and worth preserving. Rebase merge is not part of the normal RAN workflow. Release Please version PRs follow the repository's approved merge policy; publication no longer depends on a special two-parent merge geometry.
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

## Maintained PHP analysis

`composer analyze` runs both level-8 profiles. `phpstan.neon` retains isolated
production inference; `phpstan-maintained.neon` includes every maintained PHP file,
including current/future tests and scripts, automatically from the repository root.
Only root dependencies, Git metadata and disposable .workspaces are exempt.
The existing analysis coverage guard checks both effective populations, exact
commands, level 8, configured stubs and suppression inventory. Three exact local
annotations cover four locked PHPStan internal-API compatibility notifications in
that guard; they do not suppress source defects. Preserve their reviewed inventory
and the actual-checker outside-boundary control. New exemptions require review.

`test:standards` also tokenizes comments throughout recursively maintained PHP.
Exact local diagnostics need reasons and independent review; no duplicate local
annotation registry is required. No file ignore,
ancestor selector, legacy directive or inline checker configuration is permitted,
regardless of case. The three current line-2 process-variable prefix disables are
file-wide for that exact diagnostic only; new annotations need explicit review.
Do not treat a plausible reason or a green checker run as approval.

Canonical naming controls must also run without a command-line sniff selector.
Such a selector can override narrowing XML arguments and make a disabled gate
appear covered. Preserve actual root/source/test diagnostics and the real XML
argument, severity and prefix-property weakening controls.

The same standards suite protects the reviewed XML file population, dependency
exclusions, rule ancestry and canonical arguments. Conditional PHPCS/PHPCBF
attributes and rule-local path selectors are forbidden: a passing naming probe
does not establish coverage of unrelated inherited library diagnostics. Retain
the actual JSON-encoding diagnostic controls for conditional and targeted path
weakening; changes to these boundaries require explicit review.
