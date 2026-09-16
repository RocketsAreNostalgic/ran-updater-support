import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const workflow = readFileSync(
  new URL("../.github/workflows/release-please.yml", import.meta.url),
  "utf8",
);

test("release workflow authenticates the canonical CI path before mutation", () => {
  const jobStart = workflow.indexOf("jobs:\n  release:");
  const runsOn = workflow.indexOf("\n    runs-on:", jobStart);

  assert.ok(jobStart >= 0 && runsOn > jobStart);
  const admission = workflow.slice(jobStart, runsOn);
  assert.match(
    admission,
    /github\.event\.workflow_run\.path == '\.github\/workflows\/ci\.yml'/,
  );
});
