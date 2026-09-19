import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const workflow = readFileSync(
  new URL("../.github/workflows/release-please.yml", import.meta.url),
  "utf8",
);
const classificationWorkflow = readFileSync(
  new URL("../.github/workflows/release-classification.yml", import.meta.url),
  "utf8",
);

test("release job requires the canonical CI workflow path", () => {
  const jobStart = workflow.indexOf("jobs:\n  release:");
  const ifMarker = "    if: >-\n";
  const ifStart = workflow.indexOf(ifMarker, jobStart);
  const runsOn = workflow.indexOf("\n    runs-on:", ifStart);

  assert.ok(jobStart >= 0 && ifStart > jobStart && runsOn > ifStart);
  const conditionLines = workflow
    .slice(ifStart + ifMarker.length, runsOn)
    .trimEnd()
    .split("\n");
  const allLinesActive = conditionLines.every((line) => {
    return line.startsWith("      ") && !line.trimStart().startsWith("#");
  });
  assert.ok(allLinesActive);

  const condition = conditionLines.map((line) => line.trim()).join(" ");
  const terms = condition
    .replace("${{", "")
    .replace("}}", "")
    .split("&&")
    .map((term) => term.trim());
  const pathGuard = "github.event.workflow_run.path == '.github/workflows/ci.yml'";
  assert.ok(terms.includes(pathGuard));
});

test("Release Please explicitly dispatches trusted classification for token-created PRs", () => {
  assert.match(
    workflow,
    /name: Dispatch trusted classification for the canonical release PR/,
  );
  assert.match(
    workflow,
    /event_type: "trusted-release-classification"/,
  );
  assert.match(
    workflow,
    /pull_request_number: \$number/,
  );
  assert.match(
    workflow,
    /expected_head_sha: \$head_sha/,
  );
  assert.match(
    workflow,
    /\.head\.repo\.full_name == \$repository/,
  );
  assert.match(
    workflow,
    /\.user\.login == "github-actions\[bot\]"/,
  );
  assert.match(
    workflow,
    /gh api --method POST "repos\/\$\{GITHUB_REPOSITORY\}\/dispatches" --input -/,
  );
});

test("trusted release classification workflow stays on protected base", () => {
  assert.match(classificationWorkflow, /^\s*pull_request_target:/m);
  assert.match(classificationWorkflow, /^\s*repository_dispatch:/m);
  assert.match(
    classificationWorkflow,
    /repository_dispatch:\n\s+types: \[trusted-release-classification\]/,
  );
  assert.match(classificationWorkflow, /pull_request_target:\n\s+branches: \[main\]/);
  assert.match(
    classificationWorkflow,
    /types: \[opened, synchronize, reopened, edited, labeled, unlabeled\]/,
  );
  assert.match(classificationWorkflow, /test "\$base_ref" = main/);
  assert.match(
    classificationWorkflow,
    /test "\$base_repo" = "\$GITHUB_REPOSITORY"/,
  );
  assert.match(
    classificationWorkflow,
    /ref: \$\{\{ steps\.pr\.outputs\.base_sha \}\}/,
  );
  assert.match(
    classificationWorkflow,
    /git fetch --no-tags origin "\+refs\/pull\/\$\{RAN_PR_NUMBER\}\/head:refs\/remotes\/origin\/pr-head"/,
  );
  assert.match(
    classificationWorkflow,
    /RAN_EXPECTED_HEAD_SHA: \$\{\{ github\.event\.client_payload\.expected_head_sha \|\| '' \}\}/,
  );
  assert.match(
    classificationWorkflow,
    /test "\$head_sha" = "\$RAN_EXPECTED_HEAD_SHA"/,
  );
  assert.match(
    classificationWorkflow,
    /RAN_PR_NUMBER: \$\{\{ steps\.pr\.outputs\.number \}\}/,
  );
  assert.match(
    classificationWorkflow,
    /test "\$\(git rev-parse refs\/remotes\/origin\/pr-head\)" = "\$RAN_HEAD_SHA"/,
  );
  assert.match(
    classificationWorkflow,
    /run: node scripts\/release-classification\.mjs/,
  );
  assert.match(
    classificationWorkflow,
    /\.head\.repo\.full_name/,
  );
  assert.match(
    classificationWorkflow,
    /\.head\.repo\.id/,
  );
  assert.match(
    classificationWorkflow,
    /autorelease: pending/,
  );
  assert.match(
    classificationWorkflow,
    /autorelease: tagged/,
  );
  assert.match(
    classificationWorkflow,
    /RAN_RELEASE_PR_HEAD_REPOSITORY:/,
  );
  assert.match(
    classificationWorkflow,
    /RAN_RELEASE_PR_PENDING_LABEL:/,
  );
  assert.doesNotMatch(
    classificationWorkflow,
    /ref: \$\{\{ github\.event\.pull_request\.head\.sha \}\}/,
  );
});
