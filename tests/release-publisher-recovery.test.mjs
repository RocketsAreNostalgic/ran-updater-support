import assert from "node:assert/strict";
import test from "node:test";

import { PublisherRefusal } from "../scripts/release-publisher-content.mjs";
import { HISTORICAL_BETA1 as h, validateHistoricalBeta1Recovery } from "../scripts/release-publisher-recovery.mjs";

const REPOSITORY = "RocketsAreNostalgic/ran-updater-support";
const REPOSITORY_ID = 1360288787;
const CURRENT_SHA = "e".repeat(40);

function repoIdentity() {
  return { id: REPOSITORY_ID, full_name: REPOSITORY };
}

function identity() {
  return {
    candidateSha: h.candidateSha,
    version: h.version,
    tag: h.tag,
    notes: "release notes",
  };
}

function pull(labels = [{ name: "autorelease: pending" }]) {
  return {
    number: h.pullNumber,
    state: "closed",
    merged_at: "2026-09-12T18:07:56Z",
    draft: false,
    merge_commit_sha: h.candidateSha,
    base: { ref: "main", sha: h.baseSha, repo: repoIdentity() },
    head: { ref: h.branch, sha: h.headSha, repo: repoIdentity() },
    user: { login: "github-actions[bot]" },
    title: `chore(main): release ${h.version}`,
    labels,
  };
}

function input() {
  return {
    event: {
      event: "push",
      conclusion: "success",
      head_branch: "main",
      head_sha: CURRENT_SHA,
      head_repository: repoIdentity(),
    },
    repository: REPOSITORY,
    repositoryId: REPOSITORY_ID,
    currentSha: CURRENT_SHA,
    currentVersion: h.version,
    identity: identity(),
    pull: pull(),
    candidateCommit: {
      sha: h.candidateSha,
      tree: { sha: h.treeSha },
      parents: [{ sha: h.baseSha }],
    },
    headCommit: { sha: h.headSha, tree: { sha: h.treeSha } },
    candidateIsAncestor: true,
    changedPaths: [".release-please-manifest.json", "CHANGELOG.md"],
    delta: { parentVersion: "0.0.0", candidateVersion: h.version },
    tagRef: null,
    release: null,
    immutableReleasesEnabled: true,
  };
}

function refusal(code, callback) {
  assert.throws(callback, (error) => error instanceof PublisherRefusal && error.code === code);
}

test("exact historical beta.1 state permits one recovery publication", () => {
  assert.deepEqual(validateHistoricalBeta1Recovery(input()), { action: "create_release", pullNumber: 1 });
});

test("recovery is inert after the version advances", () => {
  assert.deepEqual(
    validateHistoricalBeta1Recovery({ ...input(), currentVersion: "0.1.0-beta.2" }),
    { action: "none", reason: "historical_recovery_not_applicable" },
  );
});

test("recovery requires exact successful current main evidence", () => {
  refusal("recovery_quality_identity_invalid", () =>
    validateHistoricalBeta1Recovery({ ...input(), currentSha: "f".repeat(40) })
  );
});

test("historical identity drift fails closed", () => {
  refusal("recovery_candidate_identity_invalid", () =>
    validateHistoricalBeta1Recovery({ ...input(), identity: { ...identity(), candidateSha: "f".repeat(40) } })
  );
  refusal("recovery_release_pr_invalid", () =>
    validateHistoricalBeta1Recovery({ ...input(), pull: { ...pull(), head: { ...pull().head, repo: { ...repoIdentity(), id: REPOSITORY_ID + 1 } } } })
  );
  refusal("recovery_git_identity_invalid", () =>
    validateHistoricalBeta1Recovery({ ...input(), candidateCommit: { ...input().candidateCommit, tree: { sha: "f".repeat(40) } } })
  );
  refusal("recovery_release_paths_invalid", () =>
    validateHistoricalBeta1Recovery({ ...input(), changedPaths: [...input().changedPaths, "composer.json"] })
  );
});

test("existing exact immutable beta.1 reconciles labels without republishing", () => {
  const release = {
    id: 99,
    tag_name: h.tag,
    target_commitish: h.candidateSha,
    name: h.tag,
    body: "release notes",
    draft: false,
    prerelease: true,
    immutable: true,
    assets: [],
  };
  const tagRef = { object: { type: "commit", sha: h.candidateSha } };
  assert.deepEqual(
    validateHistoricalBeta1Recovery({ ...input(), release, tagRef }),
    { action: "reconcile_labels", pullNumber: 1 },
  );
  assert.deepEqual(
    validateHistoricalBeta1Recovery({ ...input(), release, tagRef, pull: pull([{ name: "autorelease: tagged" }]) }),
    { action: "already_published", pullNumber: 1 },
  );
});

test("partial or mutable publication state fails closed", () => {
  refusal("recovery_partial_publication_state", () =>
    validateHistoricalBeta1Recovery({ ...input(), tagRef: { object: { type: "commit", sha: h.candidateSha } } })
  );
  refusal("immutable_releases_disabled", () =>
    validateHistoricalBeta1Recovery({ ...input(), immutableReleasesEnabled: false })
  );
});
