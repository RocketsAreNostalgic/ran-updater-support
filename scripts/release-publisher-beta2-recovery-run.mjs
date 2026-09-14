import { execFileSync } from "node:child_process";
import { candidateIdentity, manifestVersion, refuse, verifyReleaseDelta } from "./release-publisher-content.mjs";
import { labels, PENDING_LABEL, TAGGED_LABEL } from "./release-publisher-decision.mjs";
import { changedPaths, releaseContents } from "./release-publisher-git.mjs";
import { api, createImmutableRelease, remoteState } from "./release-publisher-github.mjs";
import { HISTORICAL_BETA2 as h, validateHistoricalBeta2Recovery } from "./release-publisher-beta2-recovery.mjs";

function isAncestor(root, ancestor, descendant) {
  try {
    execFileSync("git", ["merge-base", "--is-ancestor", ancestor, descendant], { cwd: root, stdio: "ignore" });
    return true;
  } catch {
    return false;
  }
}

const production = {
  api,
  candidateIdentity,
  changedPaths,
  createImmutableRelease,
  isAncestor,
  manifestVersion,
  releaseContents,
  remoteState,
  verifyReleaseDelta,
};

async function reconcileLabels(repository, pull, request) {
  if (!labels(pull).includes(TAGGED_LABEL)) {
    await request(`/repos/${repository}/issues/${h.pullNumber}/labels`, {
      method: "POST",
      body: { labels: [TAGGED_LABEL] },
    });
  }
  if (labels(pull).includes(PENDING_LABEL)) {
    await request(`/repos/${repository}/issues/${h.pullNumber}/labels/${encodeURIComponent(PENDING_LABEL)}`, {
      method: "DELETE",
      allow404: true,
    });
  }
}

export async function recoverHistoricalBeta2(root, event, repository, repositoryId, currentSha, overrides = {}) {
  const deps = { ...production, ...overrides };
  const currentVersion = deps.manifestVersion(
    deps.releaseContents(root, currentSha).manifest,
    "current beta.2 recovery candidate",
    true,
  );
  if (currentVersion !== h.version) {
    return { action: "none", reason: "historical_beta2_recovery_not_applicable" };
  }

  if (!deps.isAncestor(root, h.candidateSha, currentSha)) {
    return { action: "none", reason: "historical_beta2_recovery_candidate_not_in_history" };
  }

  const candidate = deps.releaseContents(root, h.candidateSha);
  const identity = deps.candidateIdentity(candidate, h.candidateSha);
  const [pullResponse, candidateCommitResponse, headCommitResponse, state, mainResponse] = await Promise.all([
    deps.api(`/repos/${repository}/pulls/${h.pullNumber}`),
    deps.api(`/repos/${repository}/git/commits/${h.candidateSha}`),
    deps.api(`/repos/${repository}/git/commits/${h.headSha}`),
    deps.remoteState(repository, h.tag),
    deps.api(`/repos/${repository}/git/ref/heads/main`),
  ]);

  let input = {
    event,
    repository,
    repositoryId,
    currentSha,
    mainSha: mainResponse.data?.object?.sha,
    currentVersion,
    identity,
    pull: pullResponse.data,
    candidateCommit: candidateCommitResponse.data,
    headCommit: headCommitResponse.data,
    candidateIsAncestor: true,
    changedPaths: deps.changedPaths(root, h.baseSha, h.candidateSha),
    delta: deps.verifyReleaseDelta(deps.releaseContents(root, h.baseSha), candidate),
    tagRef: state.tagRef,
    release: state.release,
    immutableReleasesEnabled: state.release === null
      ? process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID === String(repositoryId)
      : undefined,
  };

  let result = validateHistoricalBeta2Recovery(input);
  const mutating = result.action === "create_release" || result.action === "reconcile_labels";
  if (mutating && process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") {
    refuse("mutation_disabled", "historical beta.2 recovery mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1");
  }

  if (mutating) {
    const [freshState, freshMain, freshPullResponse] = await Promise.all([
      deps.remoteState(repository, h.tag),
      deps.api(`/repos/${repository}/git/ref/heads/main`),
      deps.api(`/repos/${repository}/pulls/${h.pullNumber}`),
    ]);
    input = {
      ...input,
      mainSha: freshMain.data?.object?.sha,
      pull: freshPullResponse.data,
      tagRef: freshState.tagRef,
      release: freshState.release,
      immutableReleasesEnabled: freshState.release === null
        ? process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID === String(repositoryId)
        : undefined,
    };
    result = validateHistoricalBeta2Recovery(input);

    if (result.action === "create_release") {
      await deps.createImmutableRelease(repository, identity);
    }
  }

  const [checked, checkedPullResponse, checkedMain] = await Promise.all([
    deps.remoteState(repository, h.tag),
    deps.api(`/repos/${repository}/pulls/${h.pullNumber}`),
    deps.api(`/repos/${repository}/git/ref/heads/main`),
  ]);
  const checkedPull = checkedPullResponse.data;
  if (checked.release === null) {
    refuse("recovery_release_readback_failed", "historical beta.2 release was not readable after recovery");
  }

  const checkedResult = validateHistoricalBeta2Recovery({
    ...input,
    mainSha: checkedMain.data?.object?.sha,
    pull: checkedPull,
    tagRef: checked.tagRef,
    release: checked.release,
  });

  if (checkedResult.action === "reconcile_labels") {
    if (process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") {
      refuse("mutation_disabled", "historical beta.2 recovery mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1");
    }
    await reconcileLabels(repository, checkedPull, deps.api);
  }

  const [finalState, finalPullResponse, finalMain] = await Promise.all([
    deps.remoteState(repository, h.tag),
    deps.api(`/repos/${repository}/pulls/${h.pullNumber}`),
    deps.api(`/repos/${repository}/git/ref/heads/main`),
  ]);
  const finalPull = finalPullResponse.data;
  const finalResult = validateHistoricalBeta2Recovery({
    ...input,
    mainSha: finalMain.data?.object?.sha,
    pull: finalPull,
    tagRef: finalState.tagRef,
    release: finalState.release,
  });
  if (finalResult.action !== "already_published") {
    refuse("recovery_label_readback_failed", "historical beta.2 lifecycle labels did not reconcile");
  }

  return { action: "recovered_release", releaseId: finalState.release.id };
}
