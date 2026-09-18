import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import {
  assertReleaseClassification,
  classifyTitle,
  productionRequirementsChanged,
  releaseSignificantChange,
  runCli,
  visibleReleaseTypes,
} from "../scripts/release-classification.mjs";

const releaseConfig = {
  packages: {
    ".": {
      "changelog-sections": [
        { type: "feat", section: "Features" },
        { type: "fix", section: "Bug Fixes" },
        { type: "perf", section: "Performance" },
        { type: "revert", section: "Reverts" },
        { type: "refactor", section: "Code Refactoring", hidden: true },
        { type: "chore", section: "Miscellaneous Chores", hidden: true },
      ],
    },
  },
};

const baseComposer = {
  require: { php: "^8.2" },
  "require-dev": { "phpstan/phpstan": "^2.1" },
};

function git(root, args) {
  return execFileSync("git", args, {
    cwd: root,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  }).trim();
}

function writeJson(path, value) {
  writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`, "utf8");
}

test("derives updater-support visible release-driving types", () => {
  assert.deepEqual([...visibleReleaseTypes(releaseConfig)], ["feat", "fix", "perf", "revert"]);
});

test("parses scoped and breaking Conventional Commit titles", () => {
  assert.deepEqual(classifyTitle("fix(runtime): preserve archive safety"), {
    type: "fix",
    breaking: false,
  });
  assert.deepEqual(classifyTitle("refactor!: replace public contract"), {
    type: "refactor",
    breaking: true,
  });
});

test("production requirement comparison ignores key order and require-dev", () => {
  assert.equal(productionRequirementsChanged(baseComposer, {
    ...baseComposer,
    require: { php: "^8.2" },
    "require-dev": { "phpstan/phpstan": "^3.0" },
  }), false);
  assert.equal(productionRequirementsChanged(baseComposer, {
    ...baseComposer,
    require: { php: "^8.3" },
  }), true);
});

test("source and production requirements are release-significant", () => {
  assert.equal(releaseSignificantChange({
    baseComposer,
    headComposer: baseComposer,
    paths: ["src/ArchiveSafety.php"],
  }), true);
  assert.equal(releaseSignificantChange({
    baseComposer,
    headComposer: { ...baseComposer, require: { php: "^8.3" } },
    paths: ["composer.json"],
  }), true);
  assert.equal(releaseSignificantChange({
    baseComposer,
    headComposer: baseComposer,
    paths: ["README.md"],
  }), false);
});

test("release-significant changes reject hidden squash classifications", () => {
  assert.throws(() => assertReleaseClassification({
    baseComposer,
    headComposer: baseComposer,
    releaseConfig,
    paths: ["src/ArchiveSafety.php"],
    title: "refactor: reorganize archive safety",
  }), /release-significant updater-support changes require/);
});

test("visible or explicit breaking classifications admit release-significant changes", () => {
  assert.equal(assertReleaseClassification({
    baseComposer,
    headComposer: baseComposer,
    releaseConfig,
    paths: ["src/ArchiveSafety.php"],
    title: "fix(runtime): preserve archive safety",
  }).classification.type, "fix");
  assert.equal(assertReleaseClassification({
    baseComposer,
    headComposer: baseComposer,
    releaseConfig,
    paths: ["src/ArchiveSafety.php"],
    title: "refactor!: replace archive contract",
  }).classification.breaking, true);
});

test("documentation-only changes do not require a release-driving title", () => {
  assert.deepEqual(assertReleaseClassification({
    baseComposer,
    headComposer: baseComposer,
    releaseConfig,
    paths: ["README.md"],
    title: "Update docs",
  }), { required: false, classification: null });
});

test("CLI verifies exact head and merge-base-to-head classification", () => {
  const root = mkdtempSync(join(tmpdir(), "updater-support-release-classification-"));
  try {
    git(root, ["init", "--initial-branch=main"]);
    git(root, ["config", "user.name", "Release Test"]);
    git(root, ["config", "user.email", "release@example.invalid"]);
    writeJson(join(root, "composer.json"), baseComposer);
    writeJson(join(root, "release-please-config.json"), releaseConfig);
    writeFileSync(join(root, "README.md"), "base\n");
    git(root, ["add", "."]);
    git(root, ["commit", "-m", "chore: base"]);
    const baseSha = git(root, ["rev-parse", "HEAD"]);
    git(root, ["branch", "feature"]);

    mkdirSync(join(root, "src"));
    writeFileSync(join(root, "src", "BaseOnly.php"), "<?php\n");
    git(root, ["add", "src/BaseOnly.php"]);
    git(root, ["commit", "-m", "feat: advance main"]);
    const advancedBaseSha = git(root, ["rev-parse", "HEAD"]);

    git(root, ["checkout", "feature"]);
    writeFileSync(join(root, "README.md"), "feature docs\n");
    git(root, ["add", "README.md"]);
    git(root, ["commit", "-m", "docs: update docs"]);
    const headSha = git(root, ["rev-parse", "HEAD"]);

    assert.deepEqual(runCli(root, {
      RAN_RELEASE_BASE_SHA: advancedBaseSha,
      RAN_RELEASE_HEAD_SHA: headSha,
      RAN_RELEASE_PR_TITLE: "Update docs",
    }), { required: false, classification: null });

    assert.throws(() => runCli(root, {
      RAN_RELEASE_BASE_SHA: baseSha,
      RAN_RELEASE_HEAD_SHA: baseSha,
      RAN_RELEASE_PR_TITLE: "fix: wrong checkout",
    }), /does not match pull request head/);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
