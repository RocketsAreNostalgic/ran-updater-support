import { refuse } from "./release-publisher-content.mjs";
import { labels, PENDING_LABEL, TAGGED_LABEL, verifyPublishedState } from "./release-publisher-decision.mjs";

export const HISTORICAL_BETA2 = Object.freeze({
  version: "0.1.0-beta.2",
  tag: "v0.1.0-beta.2",
  pullNumber: 18,
  baseSha: "08dc4df2ff289cb5d2485c13b77d190a27ba6e89",
  headSha: "421bf1076032b1ae3492ca408597e2b5753a613c",
  candidateSha: "83384bb6f4652d8988374867f4103fde63878451",
  treeSha: "bc97153c26876af3b547158d9e6ca8ea067dd639",
  branch: "release-please--branches--main--components--ran/updater-support",
});

const RELEASE_PATHS = [".release-please-manifest.json", "CHANGELOG.md"];

export function validateHistoricalBeta2Recovery(input) {
  const h = HISTORICAL_BETA2;
  const pullLabels = labels(input.pull);

  const qualityExact = input.event?.event === "push"
    && input.event?.conclusion === "success"
    && input.event?.head_branch === "main"
    && input.event?.head_sha === input.currentSha
    && Number.isInteger(input.repositoryId)
    && input.event?.head_repository?.id === input.repositoryId
    && input.event?.head_repository?.full_name === input.repository;
  if (!qualityExact) {
    refuse("recovery_quality_identity_invalid", "beta.2 recovery requires exact successful same-repository main CI");
  }
  if (input.mainSha !== input.currentSha) {
    refuse("main_moved", "main no longer points at the successful beta.2 recovery candidate");
  }
  if (input.currentVersion !== h.version) {
    return { action: "none", reason: "historical_beta2_recovery_not_applicable" };
  }
  if (input.identity?.candidateSha !== h.candidateSha || input.identity?.version !== h.version || input.identity?.tag !== h.tag) {
    refuse("recovery_candidate_identity_invalid", "historical beta.2 identity is not exact");
  }

  const pullExact = input.pull?.number === h.pullNumber
    && input.pull?.state === "closed"
    && typeof input.pull?.merged_at === "string"
    && input.pull?.draft === false
    && input.pull?.merge_commit_sha === h.candidateSha
    && input.pull?.base?.ref === "main"
    && input.pull?.base?.sha === h.baseSha
    && input.pull?.base?.repo?.id === input.repositoryId
    && input.pull?.base?.repo?.full_name === input.repository
    && input.pull?.head?.ref === h.branch
    && input.pull?.head?.sha === h.headSha
    && input.pull?.head?.repo?.id === input.repositoryId
    && input.pull?.head?.repo?.full_name === input.repository
    && input.pull?.user?.login === "github-actions[bot]"
    && input.pull?.title === `chore(main): release ${h.version}`;
  if (!pullExact) {
    refuse("recovery_release_pr_invalid", "historical beta.2 Release Please PR is not exact");
  }

  const gitExact = input.candidateCommit?.sha === h.candidateSha
    && input.candidateCommit?.tree?.sha === h.treeSha
    && input.candidateCommit?.parents?.length === 1
    && input.candidateCommit.parents[0]?.sha === h.baseSha
    && input.headCommit?.sha === h.headSha
    && input.headCommit?.tree?.sha === h.treeSha
    && input.candidateIsAncestor === true;
  if (!gitExact) {
    refuse("recovery_git_identity_invalid", "historical beta.2 git identity is not exact");
  }
  if (JSON.stringify(input.changedPaths) !== JSON.stringify(RELEASE_PATHS)) {
    refuse("recovery_release_paths_invalid", "historical beta.2 changed paths are not exact");
  }
  if (input.delta?.parentVersion !== "0.1.0-beta.1" || input.delta?.candidateVersion !== h.version) {
    refuse("recovery_release_delta_invalid", "historical beta.2 release delta is not exact");
  }

  if (input.release !== null) {
    verifyPublishedState(input.tagRef, input.release, input.identity);
    const pending = pullLabels.includes(PENDING_LABEL);
    const tagged = pullLabels.includes(TAGGED_LABEL);
    if (!pending && !tagged) {
      refuse("recovery_release_pr_label_conflict", "published beta.2 recovery candidate has no lifecycle label");
    }
    return { action: pending ? "reconcile_labels" : "already_published", pullNumber: h.pullNumber };
  }
  if (input.tagRef !== null) {
    refuse("recovery_partial_publication_state", "historical beta.2 tag exists without release");
  }
  if (!pullLabels.includes(PENDING_LABEL) || pullLabels.includes(TAGGED_LABEL)) {
    refuse("recovery_release_pr_label_conflict", "unpublished beta.2 recovery candidate must have only pending label");
  }
  if (input.immutableReleasesEnabled !== true) {
    refuse("immutable_releases_disabled", "immutable-release acknowledgement is missing or mismatched");
  }
  return { action: "create_release", pullNumber: h.pullNumber };
}
