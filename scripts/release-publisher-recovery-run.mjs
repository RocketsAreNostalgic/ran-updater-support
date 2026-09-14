import { execFileSync } from "node:child_process";
import { candidateIdentity, manifestVersion, refuse, verifyReleaseDelta } from "./release-publisher-content.mjs";
import { labels } from "./release-publisher-decision.mjs";
import { changedPaths, releaseContents } from "./release-publisher-git.mjs";
import { api, createImmutableRelease, remoteState } from "./release-publisher-github.mjs";
import { HISTORICAL_BETA1 as h, validateHistoricalBeta1Recovery } from "./release-publisher-recovery.mjs";

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
  if (!labels(pull).includes("autorelease: tagged")) {
    await request(`/repos/${repository}/issues/${h.pullNumber}/labels`, { method: "POST", body: { labels: ["autorelease: tagged"] } });
  }
  if (labels(pull).includes("autorelease: pending")) {
    await request(`/repos/${repository}/issues/${h.pullNumber}/labels/${encodeURIComponent("autorelease: pending")}`, { method: "DELETE", allow404: true });
  }
}

export async function recoverHistoricalBeta1(root, event, repository, repositoryId, currentSha, overrides = {}) {
  const deps = { ...production, ...overrides };
  const currentVersion = deps.manifestVersion(deps.releaseContents(root, currentSha).manifest, "current recovery candidate", true);
  if (currentVersion !== h.version) {
    return { action: "none", reason: "historical_recovery_not_applicable" };
  }

  if (!deps.isAncestor(root, h.candidateSha, currentSha)) {
    return { action: "none", reason: "historical_recovery_candidate_not_in_history" };
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

  let result = validateHistoricalBeta1Recovery(input);
  if (result.action === "create_release" || result.action === "reconcile_labels") {
    if (process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") {
      refuse("mutation_disabled", "historical recovery mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1");
    }
  }

  if (result.action === "create_release") {
    const [fresh, freshMain] = await Promise.all([
      deps.remoteState(repository, h.tag),
      deps.api(`/repos/${repository}/git/ref/heads/main`),
    ]);
    input = {
      ...input,
      mainSha: freshMain.data?.object?.sha,
      tagRef: fresh.tagRef,
      release: fresh.release,
    };
    result = validateHistoricalBeta1Recovery(input);
    if (result.action === "create_release") {
      await deps.createImmutableRelease(repository, identity);
    }
  }

  const checked = await deps.remoteState(repository, h.tag);
  const checkedPull = (await deps.api(`/repos/${repository}/pulls/${h.pullNumber}`)).data;
  if (checked.release === null) {
    refuse("recovery_release_readback_failed", "historical beta.1 release was not readable after recovery");
  }
  const checkedResult = validateHistoricalBeta1Recovery({
    ...input,
    pull: checkedPull,
    tagRef: checked.tagRef,
    release: checked.release,
  });

  if (checkedResult.action === "reconcile_labels") {
    if (process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") {
      refuse("mutation_disabled", "historical recovery mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1");
    }
    await reconcileLabels(repository, checkedPull, deps.api);
  }

  const finalState = await deps.remoteState(repository, h.tag);
  const finalPull = (await deps.api(`/repos/${repository}/pulls/${h.pullNumber}`)).data;
  const finalResult = validateHistoricalBeta1Recovery({
    ...input,
    pull: finalPull,
    tagRef: finalState.tagRef,
    release: finalState.release,
  });
  if (finalResult.action !== "already_published") {
    refuse("recovery_label_readback_failed", "historical beta.1 lifecycle labels did not reconcile");
  }
  return { action: "recovered_release", releaseId: finalState.release.id };
}
