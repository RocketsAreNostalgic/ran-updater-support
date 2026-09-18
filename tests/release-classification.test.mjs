import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import {
  assertCanonicalReleasePull,
  assertReleaseClassification,
  classifyTitle,
  productionComposerMetadataChanged,
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
  name: "ran/updater-support",
  type: "library",
  require: {
    php: "^8.2",
    "ext-json": "*",
  },
  autoload: {
    "psr-4": {
      "RAN\\UpdaterSupport\\V1\\": "src/",
    },
  },
  "require-dev": { "phpstan/phpstan": "^2.1" },
};

const manifest = { ".": "0.1.0-beta.4" };

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
  assert.deepEqual([...visibleReleaseTypes(releaseConfig)], [
    "feat",
    "fix",
    "perf",
    "revert",
  ]);
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

test("production Composer comparison is recursive and ignores require-dev", () => {
  assert.equal(
    productionComposerMetadataChanged(baseComposer, {
      ...baseComposer,
      require: {
        "ext-json": "*",
        php: "^8.2",
      },
      autoload: {
        "psr-4": {
          "RAN\\UpdaterSupport\\V1\\": "src/",
        },
      },
      "require-dev": { "phpstan/phpstan": "^3.0" },
    }),
    false,
  );

  assert.equal(
    productionComposerMetadataChanged(baseComposer, {
      ...baseComposer,
      autoload: {
        "psr-4": {
          "RAN\\UpdaterSupport\\V1\\": "lib/",
        },
      },
    }),
    true,
  );
});

test("source and production Composer metadata are release-significant", () => {
  assert.equal(
    releaseSignificantChange({
      baseComposer,
      headComposer: baseComposer,
      paths: ["src/ArchiveSafety.php"],
    }),
    true,
  );
  assert.equal(
    releaseSignificantChange({
      baseComposer,
      headComposer: {
        ...baseComposer,
        name: "ran/updater-support-next",
      },
      paths: ["composer.json"],
    }),
    true,
  );
  assert.equal(
    releaseSignificantChange({
      baseComposer,
      headComposer: baseComposer,
      paths: ["README.md"],
    }),
    false,
  );
});

test("canonical Release Please pull title must exactly match manifest version", () => {
  assert.equal(
    assertCanonicalReleasePull({
      author: "github-actions[bot]",
      headRef: "release-please--branches--main--components--ran/updater-support",
      manifest,
      paths: [".release-please-manifest.json", "CHANGELOG.md"],
      title: "chore(main): release 0.1.0-beta.4",
    }),
    true,
  );

  assert.throws(
    () =>
      assertCanonicalReleasePull({
        author: "github-actions[bot]",
        headRef: "release-please--branches--main--components--ran/updater-support",
        manifest,
        paths: [".release-please-manifest.json", "CHANGELOG.md"],
        title: "chore: release 0.1.0-beta.4",
      }),
    /must be exactly/,
  );
});

test("canonical Release Please pull rejects extra changed paths", () => {
  assert.throws(
    () =>
      assertCanonicalReleasePull({
        author: "github-actions[bot]",
        headRef: "release-please--branches--main--components--ran/updater-support",
        manifest,
        paths: [
          ".release-please-manifest.json",
          "CHANGELOG.md",
          "src/Unexpected.php",
        ],
        title: "chore(main): release 0.1.0-beta.4",
      }),
    /changed non-generated files/,
  );
});

test("ordinary pull requests cannot change release metadata", () => {
  for (const path of [".release-please-manifest.json", "CHANGELOG.md"]) {
    assert.throws(
      () =>
        assertReleaseClassification({
          baseComposer,
          headComposer: baseComposer,
          releaseConfig,
          paths: [path],
          title: "fix(release): alter release metadata",
          manifest,
        }),
      /release metadata changes are only permitted/,
    );
  }
});

test("release-significant changes reject non-driving squash classifications", () => {
  assert.throws(
    () =>
      assertReleaseClassification({
        baseComposer,
        headComposer: baseComposer,
        releaseConfig,
        paths: ["src/ArchiveSafety.php"],
        title: "refactor: reorganize archive safety",
        manifest,
      }),
    /release-significant updater-support changes require/,
  );
});

test("visible or explicit breaking classifications admit release-significant changes", () => {
  assert.equal(
    assertReleaseClassification({
      baseComposer,
      headComposer: baseComposer,
      releaseConfig,
      paths: ["src/ArchiveSafety.php"],
      title: "fix(runtime): preserve archive safety",
      manifest,
    }).classification.type,
    "fix",
  );
  assert.equal(
    assertReleaseClassification({
      baseComposer,
      headComposer: baseComposer,
      releaseConfig,
      paths: ["src/ArchiveSafety.php"],
      title: "refactor!: replace archive contract",
      manifest,
    }).classification.breaking,
    true,
  );
});

test("documentation-only changes do not require a release-driving title", () => {
  assert.deepEqual(
    assertReleaseClassification({
      baseComposer,
      headComposer: baseComposer,
      releaseConfig,
      paths: ["README.md"],
      title: "Update docs",
      manifest,
    }),
    { required: false, classification: null, releasePull: false },
  );
});

test("CLI uses trusted base config even when head tries to weaken release semantics", () => {
  const root = mkdtempSync(join(tmpdir(), "updater-support-release-classification-"));
  try {
    git(root, ["init", "--initial-branch=main"]);
    git(root, ["config", "user.name", "Release Test"]);
    git(root, ["config", "user.email", "release@example.invalid"]);
    writeJson(join(root, "composer.json"), baseComposer);
    writeJson(join(root, "release-please-config.json"), releaseConfig);
    writeJson(join(root, ".release-please-manifest.json"), manifest);
    git(root, ["add", "."]);
    git(root, ["commit", "-m", "chore: base"]);
    const baseSha = git(root, ["rev-parse", "HEAD"]);

    mkdirSync(join(root, "src"));
    writeFileSync(join(root, "src", "ArchiveSafety.php"), "<?php\n");
    writeJson(join(root, "release-please-config.json"), {
      packages: {
        ".": {
          "changelog-sections": [
            { type: "refactor", section: "Refactors" },
          ],
        },
      },
    });
    git(root, ["add", "."]);
    git(root, ["commit", "-m", "refactor: weaken config"]);
    const headSha = git(root, ["rev-parse", "HEAD"]);
    git(root, ["checkout", baseSha]);

    assert.throws(
      () =>
        runCli(root, {
          RAN_RELEASE_BASE_SHA: baseSha,
          RAN_RELEASE_HEAD_SHA: headSha,
          RAN_RELEASE_PR_TITLE: "refactor: weaken config",
          RAN_RELEASE_PR_HEAD_REF: "feature",
          RAN_RELEASE_PR_AUTHOR: "contributor",
        }),
      /release-significant updater-support changes require/,
    );
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("CLI treats a rename out of src as release-significant", () => {
  const root = mkdtempSync(
    join(tmpdir(), "updater-support-release-classification-rename-"),
  );
  try {
    git(root, ["init", "--initial-branch=main"]);
    git(root, ["config", "user.name", "Release Test"]);
    git(root, ["config", "user.email", "release@example.invalid"]);
    writeJson(join(root, "composer.json"), baseComposer);
    writeJson(join(root, "release-please-config.json"), releaseConfig);
    writeJson(join(root, ".release-please-manifest.json"), manifest);
    mkdirSync(join(root, "src"));
    writeFileSync(join(root, "src", "ArchiveSafety.php"), "<?php\n");
    git(root, ["add", "."]);
    git(root, ["commit", "-m", "chore: base"]);
    const baseSha = git(root, ["rev-parse", "HEAD"]);

    mkdirSync(join(root, "archive"));
    git(root, ["mv", "src/ArchiveSafety.php", "archive/ArchiveSafety.php"]);
    git(root, ["commit", "-m", "refactor: move source"]);
    const headSha = git(root, ["rev-parse", "HEAD"]);
    git(root, ["checkout", baseSha]);

    assert.throws(
      () =>
        runCli(root, {
          RAN_RELEASE_BASE_SHA: baseSha,
          RAN_RELEASE_HEAD_SHA: headSha,
          RAN_RELEASE_PR_TITLE: "refactor: move source",
          RAN_RELEASE_PR_HEAD_REF: "feature",
          RAN_RELEASE_PR_AUTHOR: "contributor",
        }),
      /release-significant updater-support changes require/,
    );
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("CLI treats newline-containing source paths as release-significant", () => {
  const root = mkdtempSync(
    join(tmpdir(), "updater-support-release-classification-newline-"),
  );
  try {
    git(root, ["init", "--initial-branch=main"]);
    git(root, ["config", "user.name", "Release Test"]);
    git(root, ["config", "user.email", "release@example.invalid"]);
    writeJson(join(root, "composer.json"), baseComposer);
    writeJson(join(root, "release-please-config.json"), releaseConfig);
    writeJson(join(root, ".release-please-manifest.json"), manifest);
    git(root, ["add", "."]);
    git(root, ["commit", "-m", "chore: base"]);
    const baseSha = git(root, ["rev-parse", "HEAD"]);

    mkdirSync(join(root, "src"));
    writeFileSync(join(root, "src", "Line\nBreak.php"), "<?php\n");
    git(root, ["add", "src"]);
    git(root, ["commit", "-m", "refactor: source path"]);
    const headSha = git(root, ["rev-parse", "HEAD"]);
    git(root, ["checkout", baseSha]);

    assert.throws(
      () =>
        runCli(root, {
          RAN_RELEASE_BASE_SHA: baseSha,
          RAN_RELEASE_HEAD_SHA: headSha,
          RAN_RELEASE_PR_TITLE: "refactor: source path",
          RAN_RELEASE_PR_HEAD_REF: "feature",
          RAN_RELEASE_PR_AUTHOR: "contributor",
        }),
      /release-significant updater-support changes require/,
    );
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
