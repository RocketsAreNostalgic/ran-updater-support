import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import test from "node:test";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const publisher = readFileSync(join(root, "scripts/release-publisher.mjs"), "utf8");

test("completed beta.1 recovery authority remains retired", () => {
  assert.equal(existsSync(join(root, "scripts/release-publisher-recovery.mjs")), false);
  assert.equal(existsSync(join(root, "scripts/release-publisher-recovery-run.mjs")), false);
  assert.equal(publisher.includes("recoverHistoricalBeta1"), false);
});
