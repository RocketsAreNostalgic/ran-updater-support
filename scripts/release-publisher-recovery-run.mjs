import { execFileSync } from "node:child_process";
import { candidateIdentity, manifestVersion, verifyReleaseDelta } from "./release-publisher-content.mjs";
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

async function reconcileLabels(repository, pull) {
  if (!labels(pull).includes("autorelease: tagged")) {
    await api(`/repos/${repository}/issues/${h.pullNumber}/labels`, { method: "POST", body: { labels: ["autorelease: tagged"] } });
  }
  if (labels(pull).includes("autorelease: pending")) {
    await api(`/repos/${repository}/issues/${h.pullNumber}/labels/${encodeURIComponent("autorelease: pending")}`, { method: "DELETE", allow404: true });
  }
}

export async function recoverHistoricalBeta1(root, event, repository, repositoryId, currentSha) {
  const currentVersion = manifestVersion(releaseContents(root, currentSha).manifest, "current recovery candidate", true);
  if (currentVersion !== h.version) {
    return { action: "none", reason: "historical_recovery_not_applicable" };
  }

  const candidate = releaseContents(root, h.candidateSha);
  const identity = candidateIdentity(candidate, h.candidateSha);
  const [pullResponse, candidateCommitResponse, headCommitResponse, state] = await Promise.all([
    api(`/repos/${repository}/pulls/${h.pullNumber}`),
    api(`/repos/${repository}/git/commits/${h.candidateSha}`),
    api(`/repos/${repository}/git/commits/${h.headSha}`),
    remoteState(repository, h.tag),
  ]);
  let input = {
    event,
    repository,
    repositoryId,
    currentSha,
    currentVersion,
    identity,
    pull: pullResponse.data,
    candidateCommit: candidateCommitResponse.data,
    headCommit: headCommitResponse.data,
    candidateIsAncestor: isAncestor(root, h.candidateSha, currentSha),
    changedPaths: changedPaths(root, h.baseSha, h.candidateSha),
    delta: verifyReleaseDelta(releaseContents(root, h.baseSha), candidate),
    tagRef: state.tagRef,
    release: state.release,
    immutableReleasesEnabled: state.release === null
      ? process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID === String(repositoryId)
      : undefined,
  };

  let result = validateHistoricalBeta1Recovery(input);
  if (result.action === "create_release") {
    const fresh = await remoteState(repository, h.tag);
    input = { ...input, tagRef: fresh.tagRef, release: fresh.release };
    result = validateHistoricalBeta1Recovery(input);
    if (result.action === "create_release") {
      await createImmutableRelease(repository, identity);
    }
  }

  if (result.action === "create_release" || result.action === "reconcile_labels") {
    await reconcileLabels(repository, (await api(`/repos/${repository}/pulls/${h.pullNumber}`)).data);
  }

  const checked = await remoteState(repository, h.tag);
  const finalPull = (await api(`/repos/${repository}/pulls/${h.pullNumber}`)).data;
  validateHistoricalBeta1Recovery({ ...input, pull: finalPull, tagRef: checked.tagRef, release: checked.release });
  return { action: "recovered_release", releaseId: checked.release?.id };
}
