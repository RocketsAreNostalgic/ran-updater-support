import assert from "node:assert/strict";
import test from "node:test";

import { PublisherRefusal } from "../scripts/release-publisher-content.mjs";
import { recoverHistoricalBeta1 } from "../scripts/release-publisher-recovery-run.mjs";
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

function event() {
  return {
    event: "push",
    conclusion: "success",
    head_branch: "main",
    head_sha: CURRENT_SHA,
    head_repository: repoIdentity(),
  };
}

function input() {
  return {
    event: event(),
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

function orchestration(options = {}) {
  const calls = [];
  const state = {
    tagRef: options.published ? { object: { type: "commit", sha: h.candidateSha } } : null,
    release: options.published ? {
      id: 99,
      tag_name: h.tag,
      target_commitish: h.candidateSha,
      name: h.tag,
      body: identity().notes,
      draft: false,
      prerelease: true,
      immutable: true,
      assets: [],
    } : null,
    labels: options.labels ?? ["autorelease: pending"],
  };

  const currentPull = () => pull(state.labels.map((name) => ({ name })));
  const api = async (path, init = {}) => {
    const method = init.method ?? "GET";
    calls.push(`${method} ${path}`);
    if (path.endsWith(`/pulls/${h.pullNumber}`)) {
      return { data: currentPull() };
    }
    if (path.endsWith(`/git/commits/${h.candidateSha}`)) {
      return { data: input().candidateCommit };
    }
    if (path.endsWith(`/git/commits/${h.headSha}`)) {
      return { data: input().headCommit };
    }
    if (path.endsWith("/labels") && method === "POST") {
      if (!state.labels.includes("autorelease: tagged")) state.labels.push("autorelease: tagged");
      return { data: null };
    }
    if (path.includes("/labels/autorelease%3A%20pending") && method === "DELETE") {
      state.labels = state.labels.filter((name) => name !== "autorelease: pending");
      return { data: null };
    }
    throw new Error(`unexpected ${method} ${path}`);
  };
  const remoteState = async () => {
    calls.push("REMOTE");
    return { tagRef: state.tagRef, release: state.release };
  };
  const createImmutableRelease = async () => {
    calls.push("CREATE");
    if (!options.missingReadback) {
      state.tagRef = { object: { type: "commit", sha: h.candidateSha } };
      state.release = {
        id: 99,
        tag_name: h.tag,
        target_commitish: h.candidateSha,
        name: h.tag,
        body: identity().notes,
        draft: false,
        prerelease: true,
        immutable: true,
        assets: [],
      };
    }
    return { data: state.release };
  };

  return {
    calls,
    state,
    deps: {
      api,
      candidateIdentity: () => identity(),
      changedPaths: () => [".release-please-manifest.json", "CHANGELOG.md"],
      createImmutableRelease,
      isAncestor: () => true,
      manifestVersion: () => h.version,
      releaseContents: () => ({ manifest: JSON.stringify({ ".": h.version }) }),
      remoteState,
      verifyReleaseDelta: () => ({ parentVersion: "0.0.0", candidateVersion: h.version }),
    },
  };
}

async function withMutationEnvironment(callback, enabled = true) {
  const beforeMutate = process.env.RAN_RELEASE_PUBLISHER_MUTATE;
  const beforeAck = process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID;
  if (enabled) process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1";
  else delete process.env.RAN_RELEASE_PUBLISHER_MUTATE;
  process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = String(REPOSITORY_ID);
  try {
    return await callback();
  } finally {
    if (beforeMutate === undefined) delete process.env.RAN_RELEASE_PUBLISHER_MUTATE;
    else process.env.RAN_RELEASE_PUBLISHER_MUTATE = beforeMutate;
    if (beforeAck === undefined) delete process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID;
    else process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = beforeAck;
  }
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
  refusal("recovery_quality_identity_invalid", () =>
    validateHistoricalBeta1Recovery({ ...input(), repositoryId: undefined, event: { ...event(), head_repository: { full_name: REPOSITORY } } })
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
  assert.deepEqual(
    validateHistoricalBeta1Recovery({ ...input(), release, tagRef, pull: pull([{ name: "autorelease: tagged" }, { name: "autorelease: pending" }]) }),
    { action: "reconcile_labels", pullNumber: 1 },
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

test("recovery creates once, proves readback, then reconciles labels", async () => {
  const mocked = orchestration();
  const result = await withMutationEnvironment(() =>
    recoverHistoricalBeta1("/unused", event(), REPOSITORY, REPOSITORY_ID, CURRENT_SHA, mocked.deps)
  );
  assert.deepEqual(result, { action: "recovered_release", releaseId: 99 });
  assert.equal(mocked.calls.filter((call) => call === "CREATE").length, 1);
  assert.deepEqual(mocked.state.labels, ["autorelease: tagged"]);
  const createIndex = mocked.calls.indexOf("CREATE");
  const firstLabelIndex = mocked.calls.findIndex((call) => call.includes("/labels"));
  assert.ok(mocked.calls.slice(createIndex + 1, firstLabelIndex).includes("REMOTE"));
});

test("recovery retry clears pending after tagged without a second release", async () => {
  const mocked = orchestration({ published: true, labels: ["autorelease: tagged", "autorelease: pending"] });
  const result = await withMutationEnvironment(() =>
    recoverHistoricalBeta1("/unused", event(), REPOSITORY, REPOSITORY_ID, CURRENT_SHA, mocked.deps)
  );
  assert.deepEqual(result, { action: "recovered_release", releaseId: 99 });
  assert.equal(mocked.calls.filter((call) => call === "CREATE").length, 0);
  assert.deepEqual(mocked.state.labels, ["autorelease: tagged"]);
});

test("recovery mutation is explicitly gated", async () => {
  const mocked = orchestration();
  await withMutationEnvironment(
    () => assert.rejects(
      recoverHistoricalBeta1("/unused", event(), REPOSITORY, REPOSITORY_ID, CURRENT_SHA, mocked.deps),
      (error) => error.code === "mutation_disabled",
    ),
    false,
  );
  assert.equal(mocked.calls.filter((call) => call === "CREATE" || call.includes("/labels")).length, 0);
});

test("recovery never labels a release that fails exact readback", async () => {
  const mocked = orchestration({ missingReadback: true });
  await withMutationEnvironment(() =>
    assert.rejects(
      recoverHistoricalBeta1("/unused", event(), REPOSITORY, REPOSITORY_ID, CURRENT_SHA, mocked.deps),
      (error) => error.code === "recovery_release_readback_failed",
    )
  );
  assert.equal(mocked.calls.filter((call) => call.includes("/labels")).length, 0);
});
