# Releases

This package uses the organisation-owned **Profile A** release lifecycle.

Release Please owns version selection, changelog generation, the managed release PR, tag creation, and the GitHub Release. The repository-local release workflow is a thin caller of the pinned shared Profile A contract in `RocketsAreNostalgic/.github`.

## Repository setup

`main` is protected by the repository ruleset and terminal `quality` check. Production releases are immutable. `CI` supports input-free `workflow_dispatch` so the shared Profile A contract can qualify the exact bot-created Release Please PR head when GitHub suppresses recursive `pull_request` events from `GITHUB_TOKEN`.

The previous repository-owned publisher, manual lifecycle reconciliation, trusted Release Please classifier, immutable-release acknowledgement variable, special release-merge geometry, and recovery-retirement harness are no longer part of the release architecture.

## Prepare and publish

1. Merge reviewed changes through the protected pull-request process. Exact `main` CI must succeed.
2. Shared Profile A admits only the canonical successful same-repository `main` CI revision and runs Release Please against current `main`.
3. If Release Please creates or updates its bot-owned release PR, Profile A binds the configured release branch to its exact head and dispatches this repository's existing read-only `CI` only when that head lacks successful or in-flight qualification.
4. Review the generated version/changelog proposal and merge only after required checks and review complete.
5. Exact `main` CI for the merged release revision admits Release Please again; Release Please creates the version tag and GitHub Release. Organisation immutable-release policy is the publication baseline.

Release Please is authoritative for prerelease progression, including legitimate SemVer-core changes. The repository does not maintain a second version engine or release state machine.

If publication fails, repair the cause through reviewed source/configuration and fresh qualification. Do not manually create or move tags, rewrite the manifest backwards, or add standing historical recovery authority.

## Consumption

Consumers should depend on a published reviewed tag, not the manifest or an arbitrary source revision, for production use. Commit the consuming root project's lockfile and verify the selected source/tag identity as appropriate.
