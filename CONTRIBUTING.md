# Contributing

This package is in a pre-release development line. Keep every change safe for public review. Use a Conventional Commit pull-request title so an ordinary squash merge uses that title as the subject consumed by Release Please, rather than the individual branch commit subjects. Choose the release classification from this repository's release configuration and [RELEASING.md](RELEASING.md); hidden documentation/test/chore types do not independently drive a release. A deliberately approved merge commit preserves individual commits, so their Conventional Commit subjects remain release inputs.

Use PHP 8.2 and Node.js 24.21.0. Install Composer dependencies, then run the
repository gate:

```sh
composer install --no-interaction --prefer-dist
composer check
```

`composer check` validates Composer metadata, lints PHP syntax, runs the shared
RAN PHPCS/PHPCompatibility standards, runs PHPStan over production and maintained code, runs
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
| `composer analyze` | Blocking PHPStan level 8 over isolated production and all maintained PHP |
| `composer test` | Archive-safety, failure-text, naming-enforcement and release-workflow contracts |
| `composer test:failure-text` | Shared failure-text corpus and deterministic property tests |
| `composer test:failure-text-mutation` | Removes each screener rule in turn; the corpus must notice |
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

## Global prefix boundary

Existing fixture/contract files and the standalone discovery helper locally except only `PrefixAllGlobals.NonPrefixedVariableFound`: standalone
contract runners and returned fixture arrays use local variables. Functions,
classes, constants and namespaces remain subject to the configured prefix rule,
including future test and root PHP files. The existing actual-checker regression
proves unprefixed global declarations fail at test, source and future root paths;
this does not declare acceptance of unrelated exception families.

Variable exceptions are confined to existing source files with a reasoned
`NonPrefixedVariableFound` annotation. No path-wide prefix exception remains;
new test/view files and nested production `tests`/`views` paths are checked.

## Default-inclusive production analysis

Root analysis includes future root, nested, split and moved production PHP. Only
root tests, vendor, node_modules, Git metadata and disposable .workspaces are
excluded from analysis and symbol scanning. Nested product directories retain
coverage. `test:analysis-coverage` compares independent recursive discovery with
locked PHPStan FileFinder plus CLI stub-file removal and exercises real missing-function negatives outside
the former src root. Uppercase and extensionless PHP fail for explicit review.
The current production population is unchanged; no existing omission is claimed.
The controls also reject production registered as a stub and prove excluded
fixture constants do not leak through symbol scanning; analyse-only exclusion
deliberately demonstrates the unsafe contrast.


## All maintained PHP acceptance (#65 / #128)

The production profile above remains unchanged to preserve its isolation from
synthetic fixture declarations. The additional `phpstan-maintained.neon` profile
analyzes all ten maintained PHP files at level 8, including the test/fixture/guard
files and four CLI helpers. `composer analyze` requires both profiles; new
root/nested tests and scripts enter maintained analysis automatically. Dependencies,
Git metadata and disposable `.workspaces` are the only root role exclusions.
No maintained file exemption or baseline is added. The existing coverage contract
checks both effective populations after stub-file removal, exact level and commands,
and rejects new analysis suppressions. Negative controls cover new test/script
errors, reduced level, restored test exclusions and developer files marked as stubs.

Six previously embedded PHP invocations now call maintained helpers: three
Bash report checks, the exact BOM/HTML fixture generator, and Node ruleset/comment
extraction. Generated malformed PHP remains test input data. The coverage guard
rejects ordinary literal inline-code and STDIN PHP invocations in shell and Node
drivers for explicit review. This finite check does not interpret dynamic shell or
JavaScript execution; quoted fixture data remains inert. New helper files enter
the existing maintained profile automatically.

The coverage guard deliberately uses the locked analyzer's actual NeonAdapter and
FileExcluder implementation to mirror effective discovery. Three occurrence-local
annotations suppress exactly four `phpstanApi.constructor` / `phpstanApi.method`
compatibility notifications, with an exact comment inventory checked against
maintained PHP. They acknowledge reliance on locked internal tooling, not errors in
source inference. All other diagnostics remain enabled; an immediately outside
unannotated API call must report its compatibility diagnostic, and an unreviewed
annotated copy must fail the inventory. A dependency update must requalify these
existing effective-discovery and actual-analyzer contracts. Two failed-read paths
in the guard now stop explicitly rather than passing false to parser functions.
Production PHP, public APIs, dependencies and runtime behavior are unchanged.
This bounded analysis change does not certify the separate PHPCS suppression policy.


## Suppression regression policy (#65 / #128)

The existing `test:standards` suite tokenizes comments in every recursively
maintained PHP file, including future files. It rejects blanket or case-variant
file ignores (including checker-recognized suffix spellings), ancestor selectors,
legacy directives, inline checker configuration and unexplained exemptions.
Only exact diagnostic identifiers with a reason are syntactically eligible;
that alone does not grant acceptance. New exact local exceptions require normal
independent PR review against their concrete source boundary and evidence; there
is no duplicate registry for local annotations. Fixture strings remain inert data.

Three existing files retain only their line-2 persistent process-variable prefix
allowance: `tests/contract.php`, `tests/fixtures/archive-safety.php` and
`tests/analysis-coverage.php`. This is a file-wide variable allowance, not an
occurrence-local claim; unrelated function/class/constant declarations remain
checked. Exact native cache/read operations and CLI exception diagnostics retain
their current local annotations. Real-checker tests demonstrate actual blanket
bypasses, precise annotation acceptance, the immediate outside diagnostic, new
persistent annotation rejection and future declarations inside the three allowance files.
This change adds no source exemptions and changes no executable PHP or dependencies.


## Error-reporting inventory (#54)

This package contains no catch blocks, suppression operators, logging, or
filesystem, lock or journal code. The only places it discards a computed reason
are listed below. Each row records where the reason is known, what leaves the
site, and why nothing sensitive can leave. The package holds no credentials,
tokens, URLs or remote text, so no value is redacted; the guarantee is that only
fixed identifiers are returned.

| ID | File and site | Known at site | Leaves site | Class | Reason id | Redaction rationale | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `ArchiveSafety::normalize_path` | Which of ten rules failed | `null` (unchanged); `path_failure()` returns the reason | B | `PATH_FAILURES` | Fixed identifiers; the name is never returned | Reported |
| 2 | `RepositoryRelativePath::normalize` | Which of eleven rules failed | Fixed-message `InvalidRepositoryPathException` with `reason()` | B | `InvalidRepositoryPathException::REASONS` | Fixed message and identifiers; the path is never returned | Reported |
| 3 | `ArchiveSafety::entry_type_failure` | Five causes behind two codes | Two legacy codes (unchanged); `entry_type_reason()` returns the cause | B | `ENTRY_TYPE_REASONS` | Fixed identifiers | Reported |
| 4 | `ArchiveSafety::collision_failure` | The offending path | Code only (`path_duplicate` or `file_parent_collision`) | B | none added | A path is untrusted publisher text and cannot be proven free of sensitive-looking text; the code already names the cause | Deferred: callers that need the path hold the entries and must escape and bound it |
| 5 | `ArchiveSafety::normalize_path` control-character check | Subsumed by the next check | None | none | none | Redundant, not a defect | Kept so `path_control_character` stays distinct |

Existing codes must not gain values: the branch updater compares
`entry_type_failure()` and `collision_failure()` results by equality with the
known codes and would treat any new non-null value as a pass. New reasons belong
in sibling methods. `tests/contract.php` pins the legacy rules as frozen
reference implementations and compares them with the current methods over a
generated corpus.

## Failure-text screener and its corpus (#57)

`FailureText` is the single source of failure-text screening for Core, the
branch updater and the release updater; the policy and rule list are in the
[README](README.md#failure-text). Changing it is a cross-package contract change.

- The hostile and benign corpus in `tests/fixtures/failure-text-corpus.php` is
  the contract. A string found passing any screener goes into `hostile` first,
  then the rule is fixed; a string that must keep passing goes into `benign`.
  Keep the file ASCII (use escapes) and use synthetic shapes, never a real
  credential. Consumers' tests load this file, so do not change its shape
  (six maps of unique label to string; see the file header) without a migration note.
- Every rule is one row of `FailureText::RULES` or one `// rule:` line in
  `FailureText::hostile()`. Add a rule only with a hostile entry that no other
  rule rejects: `tests/failure-text-mutation.php` removes each rule, each
  structural guard and each alternation branch or threshold listed in
  `tests/fixtures/failure-text-mutants.php` from a private copy and fails if the
  corpus still passes, so a redundant rule has to be removed or justified as the
  only rejection of an entry. A mutant no input can distinguish is listed with its
  reason in `$equivalent`; an unexplained survivor fails the check.
- Prefer widening a rule to adding a concept. The screener stays one class of
  pure static functions: no state, no WordPress or extension dependency, printable
  ASCII output, no redaction. The three screening modes (`strict`, `display`,
  `identifier`) differ only in the prose credential-word rules and the identifier
  shape; a shape rule belongs in the shared table so every mode applies it, and a
  prose-word rule belongs in `PROSE_RULES`. Keep exactly one occurrence of the
  namespace string in the file: the release updater generates a namespace-rewritten
  vendored copy and refuses any other shape. Avoid end-anchored patterns, so a
  bounded prefix of accepted text is always itself acceptable (the property tests
  check this).
- False positives are accepted, false negatives are not. When a benign string is
  dropped, record it in the README list rather than loosening a rule unless a
  consumer cannot work around it with its closed reason code.
- Run `composer test:failure-text` and `composer test:failure-text-mutation`, or
  `composer check`, before committing.

