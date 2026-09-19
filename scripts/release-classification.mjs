#!/usr/bin/env node

import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

import {
  candidateIdentity,
  verifyReleaseDelta,
} from "./release-publisher-content.mjs";
import { releaseContents } from "./release-publisher-git.mjs";

const FULL_SHA = /^[a-f0-9]{40}$/;
const TITLE = /^([a-z][a-z0-9-]*)(?:\([^)]+\))?(!)?:\s+\S/;
const RELEASE_BRANCH =
  "release-please--branches--main--components--ran/updater-support";
const DEVELOPMENT_ONLY_COMPOSER_KEYS = new Set([
  "require-dev",
  "autoload-dev",
  "scripts",
  "scripts-descriptions",
]);
const RELEASE_PULL_PATHS = [
  ".release-please-manifest.json",
  "CHANGELOG.md",
];

function objectRecord(value, label) {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    throw new Error(`${label} must be a JSON object`);
  }
  return value;
}

function canonicalValue(value) {
  if (Array.isArray(value)) {
    return value.map(canonicalValue);
  }
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value)
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([key, entry]) => [key, canonicalValue(entry)]),
    );
  }
  return value;
}

export function productionComposerMetadata(composer) {
  const document = objectRecord(composer, "composer.json");
  return canonicalValue(
    Object.fromEntries(
      Object.entries(document).filter(
        ([key]) => !DEVELOPMENT_ONLY_COMPOSER_KEYS.has(key),
      ),
    ),
  );
}

export function visibleReleaseTypes(config) {
  const document = objectRecord(config, "release-please-config.json");
  const packages = objectRecord(document.packages, "release-please-config.json packages");
  const root = objectRecord(packages["."], "release-please-config.json root package");
  const sections = root["changelog-sections"];

  if (!Array.isArray(sections) || sections.length === 0) {
    throw new Error("release-please-config.json must declare changelog-sections");
  }

  const types = new Set();
  for (const section of sections) {
    const entry = objectRecord(section, "release-please changelog section");
    if (entry.hidden === true) {
      continue;
    }
    if (typeof entry.type !== "string" || entry.type.length === 0) {
      throw new Error("visible release-please changelog sections must declare a type");
    }
    types.add(entry.type);
  }

  if (types.size === 0) {
    throw new Error("release-please-config.json declares no visible release-driving types");
  }
  return types;
}

export function classifyTitle(title) {
  if (typeof title !== "string") {
    throw new Error("pull request title is required");
  }
  const match = title.match(TITLE);
  if (!match) {
    throw new Error("pull request title must use Conventional Commit syntax");
  }
  return { type: match[1], breaking: match[2] === "!" };
}

export function productionComposerMetadataChanged(baseComposer, headComposer) {
  return (
    JSON.stringify(productionComposerMetadata(baseComposer)) !==
    JSON.stringify(productionComposerMetadata(headComposer))
  );
}

export function releaseSignificantChange({ baseComposer, headComposer, paths }) {
  if (!Array.isArray(paths)) {
    throw new Error("changed paths must be a list");
  }
  return (
    productionComposerMetadataChanged(baseComposer, headComposer) ||
    paths.some(
      (path) =>
        typeof path === "string" &&
        (path.startsWith("src/") ||
          path === ".gitattributes" ||
          path === ".release-please-manifest.json"),
    )
  );
}

export function assertCanonicalReleasePull({
  author,
  baseContents,
  baseSha,
  headContents,
  headRef,
  headSha,
  headRepository,
  headRepositoryId,
  mergeBaseSha,
  paths,
  pendingLabel,
  repository,
  repositoryId,
  taggedLabel,
  title,
}) {
  const isCanonical =
    author === "github-actions[bot]" &&
    headRef === RELEASE_BRANCH &&
    typeof repository === "string" &&
    repository.length > 0 &&
    headRepository === repository &&
    typeof repositoryId === "string" &&
    repositoryId.length > 0 &&
    headRepositoryId === repositoryId;

  if (!isCanonical) {
    return false;
  }

  if (mergeBaseSha !== baseSha) {
    throw new Error(
      "canonical Release Please pull request must contain the exact live base",
    );
  }

  if (pendingLabel !== true || taggedLabel !== false) {
    throw new Error(
      "canonical Release Please pull request must have pending and not tagged lifecycle state",
    );
  }

  const normalizedPaths = [...paths].sort();
  if (
    JSON.stringify(normalizedPaths) !==
    JSON.stringify(RELEASE_PULL_PATHS)
  ) {
    throw new Error(
      "canonical Release Please pull request changed non-generated files",
    );
  }

  const delta = verifyReleaseDelta(baseContents, headContents);
  const identity = candidateIdentity(headContents, headSha);
  if (identity.version !== delta.candidateVersion) {
    throw new Error(
      "canonical Release Please pull request candidate identity does not match release delta",
    );
  }
  const expected = `chore(main): release ${identity.version}`;
  if (title !== expected) {
    throw new Error(
      `canonical Release Please pull request title must be exactly "${expected}"`,
    );
  }
  return true;
}

export function assertReleaseClassification({
  baseComposer,
  baseContents,
  baseSha,
  headComposer,
  headContents,
  headRepository = "",
  headRepositoryId = "",
  headSha,
  mergeBaseSha,
  releaseConfig,
  paths,
  pendingLabel = false,
  repository = "",
  repositoryId = "",
  taggedLabel = false,
  title,
  prAuthor = "",
  prHeadRef = "",
}) {
  if (
    assertCanonicalReleasePull({
      author: prAuthor,
      baseContents,
      baseSha,
      headContents,
      headRef: prHeadRef,
      headSha,
      headRepository,
      headRepositoryId,
      mergeBaseSha,
      paths,
      pendingLabel,
      repository,
      repositoryId,
      taggedLabel,
      title,
    })
  ) {
    return { required: true, classification: null, releasePull: true };
  }

  if (
    paths.some(
      (path) =>
        path === ".release-please-manifest.json" || path === "CHANGELOG.md",
    )
  ) {
    throw new Error(
      "release metadata changes are only permitted in canonical Release Please pull requests",
    );
  }

  if (!releaseSignificantChange({ baseComposer, headComposer, paths })) {
    return { required: false, classification: null, releasePull: false };
  }

  const classification = classifyTitle(title);
  const visible = visibleReleaseTypes(releaseConfig);
  if (!classification.breaking && !visible.has(classification.type)) {
    throw new Error(
      `release-significant updater-support changes require one of ${[...visible].join(", ")} or an explicit breaking ! classification; classification "${classification.type}" is not release-driving`,
    );
  }

  return { required: true, classification, releasePull: false };
}

function git(root, args, options = {}) {
  return execFileSync("git", args, {
    cwd: root,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    ...options,
  });
}

function readJsonAt(root, sha, path) {
  return JSON.parse(git(root, ["show", `${sha}:${path}`]).trim());
}

function mergeBase(root, baseSha, headSha) {
  const sha = git(root, ["merge-base", baseSha, headSha]).trim();
  if (!FULL_SHA.test(sha)) {
    throw new Error("pull request base and head do not have a canonical merge base");
  }
  return sha;
}

function changedPaths(root, baseSha, headSha) {
  return git(root, [
    "diff",
    "--name-only",
    "--no-renames",
    "-z",
    baseSha,
    headSha,
  ])
    .split("\0")
    .filter(Boolean)
    .sort();
}

export function runCli(root = process.cwd(), env = process.env) {
  const baseSha = env.RAN_RELEASE_BASE_SHA;
  const headSha = env.RAN_RELEASE_HEAD_SHA;
  const title = env.RAN_RELEASE_PR_TITLE;
  const prHeadRef = env.RAN_RELEASE_PR_HEAD_REF;
  const prAuthor = env.RAN_RELEASE_PR_AUTHOR;
  const headRepository = env.RAN_RELEASE_PR_HEAD_REPOSITORY;
  const headRepositoryId = env.RAN_RELEASE_PR_HEAD_REPOSITORY_ID;
  const repository = env.RAN_RELEASE_REPOSITORY;
  const repositoryId = env.RAN_RELEASE_REPOSITORY_ID;
  const pendingLabelRaw = env.RAN_RELEASE_PR_PENDING_LABEL;
  const taggedLabelRaw = env.RAN_RELEASE_PR_TAGGED_LABEL;

  if (!FULL_SHA.test(baseSha ?? "") || !FULL_SHA.test(headSha ?? "")) {
    throw new Error("exact live pull request base and head SHAs are required");
  }
  if (
    typeof title !== "string" ||
    typeof prHeadRef !== "string" ||
    typeof prAuthor !== "string" ||
    typeof headRepository !== "string" ||
    typeof headRepositoryId !== "string" ||
    typeof repository !== "string" ||
    typeof repositoryId !== "string" ||
    !["true", "false"].includes(pendingLabelRaw ?? "") ||
    !["true", "false"].includes(taggedLabelRaw ?? "") ||
    prHeadRef.length === 0 ||
    prAuthor.length === 0 ||
    repository.length === 0 ||
    repositoryId.length === 0
  ) {
    throw new Error(
      "live pull request identity, repository identity, and lifecycle labels are required",
    );
  }

  const checkoutSha = git(root, ["rev-parse", "HEAD"]).trim();
  if (checkoutSha !== baseSha) {
    throw new Error(
      `trusted classifier checkout ${checkoutSha} does not match live protected base ${baseSha}`,
    );
  }

  const classificationBaseSha = mergeBase(root, baseSha, headSha);
  const canonicalReleaseIdentity =
    prAuthor === "github-actions[bot]" &&
    prHeadRef === RELEASE_BRANCH &&
    headRepository === repository &&
    headRepositoryId === repositoryId;

  const result = assertReleaseClassification({
    baseComposer: readJsonAt(root, classificationBaseSha, "composer.json"),
    baseContents: canonicalReleaseIdentity
      ? releaseContents(root, baseSha)
      : undefined,
    baseSha,
    headComposer: readJsonAt(root, headSha, "composer.json"),
    headContents: canonicalReleaseIdentity
      ? releaseContents(root, headSha)
      : undefined,
    headRepository,
    headRepositoryId,
    headSha,
    mergeBaseSha: classificationBaseSha,
    releaseConfig: readJsonAt(root, baseSha, "release-please-config.json"),
    paths: changedPaths(root, classificationBaseSha, headSha),
    pendingLabel: pendingLabelRaw === "true",
    repository,
    repositoryId,
    taggedLabel: taggedLabelRaw === "true",
    title,
    prAuthor,
    prHeadRef,
  });

  if (result.releasePull) {
    console.log("canonical Release Please pull request title is exact");
  } else if (result.required) {
    console.log(
      `release-significant updater-support change; classification ${result.classification.type}${result.classification.breaking ? "!" : ""} is release-driving`,
    );
  } else {
    console.log(
      "no release-significant updater-support source or production Composer metadata change",
    );
  }

  return result;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    runCli();
  } catch (error) {
    console.error(error instanceof Error ? error.message : error);
    process.exitCode = 1;
  }
}
