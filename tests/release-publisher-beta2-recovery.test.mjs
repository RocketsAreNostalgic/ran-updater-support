import assert from "node:assert/strict";
import test from "node:test";

import { PublisherRefusal } from "../scripts/release-publisher-content.mjs";
import { recoverHistoricalBeta2 } from "../scripts/release-publisher-beta2-recovery-run.mjs";
import { HISTORICAL_BETA2 as h, validateHistoricalBeta2Recovery } from "../scripts/release-publisher-beta2-recovery.mjs";

const REPOSITORY = "RocketsAreNostalgic/ran-updater-support";
const REPOSITORY_ID = 1360288787;
const CURRENT_SHA = "e".repeat(40);

const repo = () => ({ id: REPOSITORY_ID, full_name: REPOSITORY });
const identity = () => ({ candidateSha: h.candidateSha, version: h.version, tag: h.tag, notes: "release notes" });
const event = () => ({ event: "push", conclusion: "success", head_branch: "main", head_sha: CURRENT_SHA, head_repository: repo() });

function pull(names = ["autorelease: pending"]) {
  return {
    number: h.pullNumber,
    state: "closed",
    merged_at: "2026-09-14T16:25:12Z",
    draft: false,
    merge_commit_sha: h.candidateSha,
    base: { ref: "main", sha: h.baseSha, repo: repo() },
    head: { ref: h.branch, sha: h.headSha, repo: repo() },
    user: { login: "github-actions[bot]" },
    title: `chore(main): release ${h.version}`,
    labels: names.map((name) => ({ name })),
  };
}

function input() {
  return {
    event: event(), repository: REPOSITORY, repositoryId: REPOSITORY_ID,
    currentSha: CURRENT_SHA, mainSha: CURRENT_SHA, currentVersion: h.version,
    identity: identity(), pull: pull(), candidateIsAncestor: true,
    candidateCommit: { sha: h.candidateSha, tree: { sha: h.treeSha }, parents: [{ sha: h.baseSha }] },
    headCommit: { sha: h.headSha, tree: { sha: h.treeSha } },
    changedPaths: [".release-please-manifest.json", "CHANGELOG.md"],
    delta: { parentVersion: "0.1.0-beta.1", candidateVersion: h.version },
    tagRef: null, release: null, immutableReleasesEnabled: true,
  };
}

function refusal(code, fn) {
  assert.throws(fn, (error) => error instanceof PublisherRefusal && error.code === code);
}

function publishedState() {
  return {
    tagRef: { object: { type: "commit", sha: h.candidateSha } },
    release: {
      id: 99, tag_name: h.tag, target_commitish: h.candidateSha, name: h.tag,
      body: identity().notes, draft: false, prerelease: true, immutable: true, assets: [],
    },
  };
}

function orchestration(options = {}) {
  const calls = [];
  let mainReads = 0;
  const state = {
    ...(options.published ? publishedState() : { tagRef: null, release: null }),
    labels: options.labels ?? ["autorelease: pending"],
  };
  const currentPull = () => pull(state.labels);
  const api = async (path, init = {}) => {
    const method = init.method ?? "GET";
    calls.push(`${method} ${path}`);
    if (path.endsWith(`/pulls/${h.pullNumber}`)) return { data: currentPull() };
    if (path.endsWith(`/git/commits/${h.candidateSha}`)) return { data: input().candidateCommit };
    if (path.endsWith(`/git/commits/${h.headSha}`)) return { data: input().headCommit };
    if (path.endsWith("/git/ref/heads/main")) {
      mainReads += 1;
      return { data: { object: { sha: options.moveMain && mainReads > 1 ? "f".repeat(40) : CURRENT_SHA } } };
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
  const remoteState = async () => { calls.push("REMOTE"); return { tagRef: state.tagRef, release: state.release }; };
  const createImmutableRelease = async () => {
    calls.push("CREATE");
    if (!options.missingReadback) Object.assign(state, publishedState());
    return { data: state.release };
  };
  return {
    calls, state,
    deps: {
      api,
      candidateIdentity: identity,
      changedPaths: () => [".release-please-manifest.json", "CHANGELOG.md"],
      createImmutableRelease,
      isAncestor: () => true,
      manifestVersion: () => h.version,
      releaseContents: () => ({ manifest: JSON.stringify({ ".": h.version }) }),
      remoteState,
      verifyReleaseDelta: () => ({ parentVersion: "0.1.0-beta.1", candidateVersion: h.version }),
    },
  };
}

async function withEnv(fn, mutate = true) {
  const oldMutate = process.env.RAN_RELEASE_PUBLISHER_MUTATE;
  const oldAck = process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID;
  if (mutate) process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1";
  else delete process.env.RAN_RELEASE_PUBLISHER_MUTATE;
  process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = String(REPOSITORY_ID);
  try { return await fn(); } finally {
    if (oldMutate === undefined) delete process.env.RAN_RELEASE_PUBLISHER_MUTATE; else process.env.RAN_RELEASE_PUBLISHER_MUTATE = oldMutate;
    if (oldAck === undefined) delete process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID; else process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = oldAck;
  }
}

test("exact beta.2 squash identity permits one bounded recovery", () => {
  assert.deepEqual(validateHistoricalBeta2Recovery(input()), { action: "create_release", pullNumber: 18 });
});

test("beta.2 recovery becomes inert after version advances", () => {
  assert.deepEqual(validateHistoricalBeta2Recovery({ ...input(), currentVersion: "0.1.0-beta.3" }), {
    action: "none", reason: "historical_beta2_recovery_not_applicable",
  });
});

test("identity, topology, paths and release delta fail closed on drift", () => {
  refusal("recovery_candidate_identity_invalid", () => validateHistoricalBeta2Recovery({ ...input(), identity: { ...identity(), candidateSha: "f".repeat(40) } }));
  refusal("recovery_release_pr_invalid", () => validateHistoricalBeta2Recovery({ ...input(), pull: { ...pull(), head: { ...pull().head, sha: "f".repeat(40) } } }));
  refusal("recovery_git_identity_invalid", () => validateHistoricalBeta2Recovery({ ...input(), headCommit: { ...input().headCommit, tree: { sha: "f".repeat(40) } } }));
  refusal("recovery_release_paths_invalid", () => validateHistoricalBeta2Recovery({ ...input(), changedPaths: [...input().changedPaths, "composer.json"] }));
  refusal("recovery_release_delta_invalid", () => validateHistoricalBeta2Recovery({ ...input(), delta: { parentVersion: "0.0.0", candidateVersion: h.version } }));
});

test("exact published beta.2 reconciles lifecycle labels without republishing", () => {
  const state = publishedState();
  assert.deepEqual(validateHistoricalBeta2Recovery({ ...input(), ...state }), { action: "reconcile_labels", pullNumber: 18 });
  assert.deepEqual(validateHistoricalBeta2Recovery({ ...input(), ...state, pull: pull(["autorelease: tagged"]) }), { action: "already_published", pullNumber: 18 });
});

test("partial publication and missing immutable acknowledgement fail closed", () => {
  refusal("recovery_partial_publication_state", () => validateHistoricalBeta2Recovery({ ...input(), tagRef: publishedState().tagRef }));
  refusal("immutable_releases_disabled", () => validateHistoricalBeta2Recovery({ ...input(), immutableReleasesEnabled: false }));
});

test("recovery creates, proves readback, then reconciles labels", async () => {
  const mocked = orchestration();
  const result = await withEnv(() => recoverHistoricalBeta2("/unused", event(), REPOSITORY, REPOSITORY_ID, CURRENT_SHA, mocked.deps));
  assert.deepEqual(result, { action: "recovered_release", releaseId: 99 });
  assert.equal(mocked.calls.filter((call) => call === "CREATE").length, 1);
  assert.deepEqual(mocked.state.labels, ["autorelease: tagged"]);
  const create = mocked.calls.indexOf("CREATE");
  const label = mocked.calls.findIndex((call) => call.includes("/labels"));
  assert.ok(mocked.calls.slice(create + 1, label).includes("REMOTE"));
});

test("recovery is mutation-gated and rechecks live main", async () => {
  const disabled = orchestration();
  await withEnv(() => assert.rejects(
    recoverHistoricalBeta2("/unused", event(), REPOSITORY, REPOSITORY_ID, CURRENT_SHA, disabled.deps),
    (error) => error.code === "mutation_disabled",
  ), false);
  assert.equal(disabled.calls.includes("CREATE"), false);

  const moved = orchestration({ moveMain: true });
  await withEnv(() => assert.rejects(
    recoverHistoricalBeta2("/unused", event(), REPOSITORY, REPOSITORY_ID, CURRENT_SHA, moved.deps),
    (error) => error.code === "main_moved",
  ));
  assert.equal(moved.calls.includes("CREATE"), false);
});

test("recovery never labels before exact release readback", async () => {
  const mocked = orchestration({ missingReadback: true });
  await withEnv(() => assert.rejects(
    recoverHistoricalBeta2("/unused", event(), REPOSITORY, REPOSITORY_ID, CURRENT_SHA, mocked.deps),
    (error) => error.code === "recovery_release_readback_failed",
  ));
  assert.equal(mocked.calls.some((call) => call.includes("/labels")), false);
});
