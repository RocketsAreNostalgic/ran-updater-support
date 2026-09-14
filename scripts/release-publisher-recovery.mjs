import { refuse } from "./release-publisher-content.mjs";
import { labels, PENDING_LABEL, TAGGED_LABEL, verifyPublishedState } from "./release-publisher-decision.mjs";

export const HISTORICAL_BETA1 = Object.freeze({
  version: "0.1.0-beta.1",
  tag: "v0.1.0-beta.1",
  pullNumber: 1,
  baseSha: "fd2f86b81b71a9445cf0df64d4618eafde55052e",
  headSha: "2819db8d709f1b48f2f145f12c6a5a4b676bd73f",
  candidateSha: "736d546ce0d96507df8ef9cf4ca0de8d928ffe7d",
  treeSha: "b36b20c64a5882e7af9ebc8786fd9e448287697e",
  branch: "release-please--branches--main--components--ran/updater-support",
});

const RELEASE_PATHS = [".release-please-manifest.json", "CHANGELOG.md"];

export function validateHistoricalBeta1Recovery(input) {
  const h = HISTORICAL_BETA1;
  const pullLabels = labels(input.pull);

  const qualityExact = input.event?.event === "push"
    && input.event?.conclusion === "success"
    && input.event?.head_branch === "main"
    && input.event?.head_sha === input.currentSha
    && Number.isInteger(input.repositoryId)
    && input.event?.head_repository?.id === input.repositoryId
    && input.event?.head_repository?.full_name === input.repository;
  if (!qualityExact) {
    refuse("recovery_quality_identity_invalid", "recovery requires exact successful same-repository main CI");
  }
  if (input.mainSha !== input.currentSha) {
    refuse("main_moved", "main no longer points at the successful recovery candidate");
  }
  if (input.currentVersion !== h.version) {
    return { action: "none", reason: "historical_recovery_not_applicable" };
  }
  if (input.identity?.candidateSha !== h.candidateSha || input.identity?.version !== h.version || input.identity?.tag !== h.tag) {
    refuse("recovery_candidate_identity_invalid", "historical beta.1 identity is not exact");
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
    refuse("recovery_release_pr_invalid", "historical beta.1 Release Please PR is not exact");
  }

  const gitExact = input.candidateCommit?.sha === h.candidateSha
    && input.candidateCommit?.tree?.sha === h.treeSha
    && input.candidateCommit?.parents?.length === 1
    && input.candidateCommit.parents[0]?.sha === h.baseSha
    && input.headCommit?.sha === h.headSha
    && input.headCommit?.tree?.sha === h.treeSha
    && input.candidateIsAncestor === true;
  if (!gitExact) {
    refuse("recovery_git_identity_invalid", "historical beta.1 git identity is not exact");
  }
  if (JSON.stringify(input.changedPaths) !== JSON.stringify(RELEASE_PATHS)) {
    refuse("recovery_release_paths_invalid", "historical beta.1 changed paths are not exact");
  }
  if (input.delta?.parentVersion !== "0.0.0" || input.delta?.candidateVersion !== h.version) {
    refuse("recovery_release_delta_invalid", "historical beta.1 release delta is not exact");
  }

  if (input.release !== null) {
    verifyPublishedState(input.tagRef, input.release, input.identity);
    const pending = pullLabels.includes(PENDING_LABEL);
    const tagged = pullLabels.includes(TAGGED_LABEL);
    if (!pending && !tagged) {
      refuse("recovery_release_pr_label_conflict", "published recovery candidate has no lifecycle label");
    }
    return { action: pending ? "reconcile_labels" : "already_published", pullNumber: h.pullNumber };
  }
  if (input.tagRef !== null) {
    refuse("recovery_partial_publication_state", "historical beta.1 tag exists without release");
  }
  if (!pullLabels.includes(PENDING_LABEL) || pullLabels.includes(TAGGED_LABEL)) {
    refuse("recovery_release_pr_label_conflict", "unpublished recovery candidate must have only pending label");
  }
  if (input.immutableReleasesEnabled === false) {
    refuse("immutable_releases_disabled", "immutable-release acknowledgement is missing or mismatched");
  }
  return { action: "create_release", pullNumber: h.pullNumber };
}
