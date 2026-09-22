import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const workflow = readFileSync(
  new URL("../.github/workflows/release-please.yml", import.meta.url),
  "utf8",
);
const ciWorkflow = readFileSync(
  new URL("../.github/workflows/ci.yml", import.meta.url),
  "utf8",
);
const releaseConfig = JSON.parse(
  readFileSync(new URL("../release-please-config.json", import.meta.url), "utf8"),
);

test("release workflow is a thin pinned Profile A caller", () => {
  assert.match(workflow, /workflow_run:/);
  assert.match(workflow, /workflows: \[CI\]/);
  assert.match(workflow, /permissions: \{\}/);
  assert.match(
    workflow,
    /^\s{4}uses: RocketsAreNostalgic\/\.github\/\.github\/workflows\/release-profile-a\.yml@289352e08cdf10b15d07c4e1c890f385afc3d3f5$/m,
  );
  assert.match(workflow, /expected-workflow-path: \.github\/workflows\/ci\.yml/);
  assert.match(
    workflow,
    /release-pr-head: release-please--branches--main--components--ran\/updater-support/,
  );
  assert.match(workflow, /actions: write/);
  assert.doesNotMatch(workflow, /repository_dispatch/);
  assert.doesNotMatch(workflow, /release-publisher/);
  assert.doesNotMatch(workflow, /autorelease:/);
  assert.equal(releaseConfig.packages["."]["skip-github-release"], undefined);
});

test("canonical CI supports input-free exact-head candidate dispatch", () => {
  assert.match(ciWorkflow, /^\s*workflow_dispatch:/m);
  assert.match(ciWorkflow, /^\s*pull_request:/m);
  assert.match(ciWorkflow, /push:\n\s+branches: \[main\]/);
  assert.match(ciWorkflow, /permissions:\n\s+contents: read/);
  assert.match(ciWorkflow, /quality:\n\s+if: \$\{\{ always\(\) \}\}/);
});
