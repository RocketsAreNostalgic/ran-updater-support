import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = fileURLToPath(new URL("../", import.meta.url));
const sniff = "RANOwnedMethods.NamingConventions.ValidMethodName";

function check(source, selectedSniff = sniff, path = "src/NamingProbe.php") {
  const result = spawnSync("php", [
    "vendor/bin/phpcs", "--standard=.phpcs.xml", `--sniffs=${selectedSniff}`,
    "-q", "--no-colors", "--report=json", `--stdin-path=${root}${path}`, "-",
  ], { cwd: root, input: source, encoding: "utf8", timeout: 60000 });
  assert.ifError(result.error);
  assert.equal(result.signal, null);
  const report = JSON.parse(result.stdout);
  return { status: result.status, messages: Object.values(report.files).flatMap(file => file.messages) };
}

const compliant = `<?php
interface Contract { public function is_ready(); }
class ParentType { public function is_ready() {} }
class Implementation extends ParentType implements Contract {
    public function is_ready() {}
    private function owned_helper() {}
    public function __toString() { return ''; }
}
class ExternalTest extends \\PHPUnit\\Framework\\TestCase {
    // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Required PHPUnit lifecycle signature.
    protected function setUp(): void {}
    private function local_helper() {}
}
`;

test("repository rules accept snake_case, magic methods and a narrow external signature", () => {
  assert.deepEqual(check(compliant), { status: 0, messages: [] });
});

test("repository rules reject owned camelCase in plain, derived and implementing classes", () => {
  const result = check(`<?php
class PlainType { public function plainName() {} }
class DerivedType extends \\RuntimeException { private function derivedName() {} }
interface Contract { public function is_ready(); }
class Implementation implements Contract {
    public function is_ready() {}
    private function unrelatedName() {}
}
`);
  assert.equal(result.status, 1);
  assert.equal(result.messages.length, 3);
  for (const [index, name] of ["plainName", "derivedName", "unrelatedName"].entries()) {
    const message = result.messages[index];
    assert.equal(message.source, `${sniff}.NotSnakeCase`);
    assert.equal(message.type, "ERROR");
    assert.equal(message.fixable, false);
    assert.ok(message.message.includes(`"${name}"`));
  }
});

test("external signature exception does not exempt an owned sibling method", () => {
  const result = check(compliant.replace("function local_helper()", "function localHelper()"));
  assert.equal(result.status, 1);
  assert.equal(result.messages.length, 1);
  assert.equal(result.messages[0].source, `${sniff}.NotSnakeCase`);
  assert.ok(result.messages[0].message.includes('"localHelper"'));
});


test("test locals do not exempt unrelated global declarations", () => {
  const prefix = "WordPress.NamingConventions.PrefixAllGlobals";
  for (const path of ["tests/contract.php", "tests/FuturePrefix.php", "src/FuturePrefix.php", "future-prefix.php"]) {
    const result = check("<?php function unowned_probe() {} class UnownedProbe {} const UNOWNED_PROBE = 1;", prefix, path);
    assert.notEqual(result.status, 0);
    assert.deepEqual(result.messages.map(message => message.source).sort(), ["Class", "Constant", "Function"].map(kind => `${prefix}.NonPrefixed${kind}Found`).sort(), path);
  }
  assert.deepEqual(check("<?php $local_value = 1;", prefix, "tests/FuturePrefix.php"), { status: 0, messages: [] });
});
