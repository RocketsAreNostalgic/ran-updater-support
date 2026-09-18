# Releases

This package follows the RAN release-updater's exact-commit publishing process.
Its independent prerelease line starts at `v0.1.0-beta.1`. Composer derives
available versions from published Git tags/releases. The Release Please manifest
tracks release-preparation state and may therefore be ahead of the latest
successfully published version after a failed publication attempt.

## Repository setup

The repository uses `main`, protected against deletion and force pushes, with
pull requests and the `quality` CI check required. Release PRs must use a normal
merge commit: squash and rebase merges cannot satisfy the publisher's parent and
tree checks. Ordinary PRs may be squashed; the generated Release Please version
PR is the exception and must be merged normally.

Enable GitHub Actions PR creation and immutable releases before releasing.
Set the repository variable
`RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID` to the exact
numeric repository ID only after verifying immutable releases are enabled.
The variable acknowledges that setting; it does not enable it.

## Prepare and publish

1. Merge reviewed Conventional Commits through the normal PR process. CI runs
   strict Composer validation, PHP syntax lint, the shared RAN
   PHPCS/PHPCompatibility standards, PHPStan, package contract tests, and
   publisher tests. A successful same-repository main push starts Release
   Please.
2. Release Please opens a version PR. Approve its Actions workflow run if GitHub
   requires approval for the bot-created PR. If that exact release-PR head has
   no required `quality` check because GitHub suppressed the bot-created PR
   event, make a body-only metadata edit on the unchanged PR to trigger the
   existing `pull_request: edited` CI path. Do not change the release title or
   source merely to trigger CI. Review the version and complete changelog diff.
   Prereleases remain on the `0.1.0-beta.*` line unless a reviewed configuration
   and publisher policy change deliberately advances that line.
3. Run independent review against the exact PR base and head, resolve findings,
   and present the checks and normal-merge method to the owner. Merge only after
   explicit authorization. Only the manifest version and prepended changelog
   section may change in the release PR.
4. Successful CI for that exact main merge permits publication. The publisher
   verifies the PR's two parents and head tree, rechecks main and remote release
   state, and creates one immutable prerelease targeting the exact merge SHA.
   It publishes no uploaded assets. GitHub's source archives are the Composer
   distribution. Conflicting or partial remote state fails closed.
5. The publisher verifies the tag, release metadata, notes and empty asset list
   before changing the PR's lifecycle label. A retry after label interruption
   reconciles the existing release instead of publishing a second one.

If a version PR is merged but publication fails before a tag/release is created,
do not create or move a tag manually and do not rewrite the manifest backwards.
The manifest records that prepared version even though it is unavailable, and
Release Please may refuse to prepare another version while that merged release PR
remains untagged. Fix the cause through the normal reviewed workflow, then recover
the exact historical candidate only through a bounded, reviewed, fail-closed
release-control change that proves the original PR, commit/tree identity, release
metadata, immutable publication and readback before lifecycle labels are changed.
Remove any one-time recovery authority after successful reconciliation. Consumers
must treat published tags/releases, not the manifest alone, as the availability
boundary.

Do not create manual release tags, move existing tags, bypass failed checks, or
edit generated version/changelog content outside a reviewed release correction.
Release Please prepares PRs; this repository's separate publisher owns releases.
Packagist registration is a separate publication step and is not implied by a
GitHub release.

## Before the first published release

A consuming root project can declare the GitHub VCS repository and require
`ran/updater-support` using `dev-main`. Commit the root project's lockfile, verify
its source reference against the reviewed full commit SHA, and explicitly allow
that development dependency. Composer repository declarations are root-only and
are not inherited from dependencies. Replace the development pin with an
owner-approved beta tag after publication. There is no claimed stable `0.1.0`
release and no local path repository requirement.

The initial bootstrap boundary is the fresh repository's seed commit. The
following `feat` commit supplied the first release's source and changelog scope.
See the [Release Please manifest reference](https://github.com/googleapis/release-please/blob/main/docs/manifest-releaser.md)
and [GitHub immutable releases documentation](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/establish-provenance-and-integrity/prevent-release-changes).
