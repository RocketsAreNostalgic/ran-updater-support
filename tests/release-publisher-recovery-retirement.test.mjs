import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import test from "node:test";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const publisher = readFileSync(join(root, "scripts/release-publisher.mjs"), "utf8");

test("completed historical recovery authority remains retired", () => {
  for (const path of [
    "scripts/release-publisher-recovery.mjs",
    "scripts/release-publisher-recovery-run.mjs",
    "scripts/release-publisher-beta2-recovery.mjs",
    "scripts/release-publisher-beta2-recovery-run.mjs",
  ]) {
    assert.equal(existsSync(join(root, path)), false, `${path} must remain absent`);
  }
  assert.equal(publisher.includes("recoverHistoricalBeta1"), false);
  assert.equal(publisher.includes("recoverHistoricalBeta2"), false);
  assert.equal(publisher.includes("release-publisher-beta2-recovery"), false);
});
