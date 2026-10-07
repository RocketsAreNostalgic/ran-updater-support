import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { readdirSync, readFileSync, writeFileSync, unlinkSync } from "node:fs";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = fileURLToPath(new URL("../", import.meta.url));
const sniff = "RANOwnedMethods.NamingConventions.ValidMethodName";

function check(source, selectedSniff = sniff, path = "src/NamingProbe.php", standard = ".phpcs.xml") {
  const result = spawnSync("php", [
    "vendor/bin/phpcs", `--standard=${standard}`, ...(selectedSniff === null ? [] : [`--sniffs=${selectedSniff}`]),
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

// These probes must use the canonical profile without a CLI sniff override:
// an override can undo a narrowing argument in XML and conceal a broken gate.
const canonicalProbe = `<?php
function unowned_probe() {}
class UnownedProbe { public function badName() {} }
const UNOWNED_PROBE = 1;
$local_value = 1;
`;
const canonicalCodes = [
  `${sniff}.NotSnakeCase`,
  ...["Class", "Constant", "Function", "Variable"].map(kind =>
    `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixed${kind}Found`),
];
const namespaceCode = "WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound";
const namespaceProbe = "<?php namespace Unowned; class Probe {}\n";
function assertCanonicalNaming(result, path) {
  for (const code of canonicalCodes) {
    assert.ok(result.messages.some(message => message.source === code), `${path}: canonical profile lost ${code}`);
  }
}

test("canonical profile enforces naming without test-only sniff overrides", () => {
  for (const path of ["src/NamingProbe.php", "tests/NamingProbe.php", "tests/fixtures/NamingProbe.php", "future-root.php"]) {
    assertCanonicalNaming(check(canonicalProbe, null, path), path);
    assert.ok(check(namespaceProbe, null, path).messages.some(message => message.source === namespaceCode), `${path}: canonical namespace diagnostic missing`);
  }
});

test("actual XML narrowing and property changes fail canonical naming controls", () => {
  const ruleset = readFileSync(root + ".phpcs.xml", "utf8");
  const path = `standards-probe-${process.pid}-${Date.now()}.xml`;
  const weakenings = [
    '<arg name="sniffs" value="WordPress.PHP.YodaConditions"/>',
    `<arg name="exclude" value="${sniff}"/>`,
    ...[0, 1, 4].map(severity => `<rule ref="${sniff}"><severity>${severity}</severity></rule>`),
    '<rule ref="WordPress.NamingConventions.PrefixAllGlobals"><properties><property name="prefixes" type="array"><element value="unowned"/></property></properties></rule>',
  ];
  try {
    for (const weakening of weakenings) {
      writeFileSync(root + path, ruleset.replace("</ruleset>", weakening + "</ruleset>"));
      const result = check(canonicalProbe, null, "tests/NamingProbe.php", path);
      assert.throws(() => assertCanonicalNaming(result, weakening), /canonical profile lost/);
    }
    writeFileSync(root + path, ruleset.replace("</ruleset>", `<rule ref="${namespaceCode}"><severity>0</severity></rule></ruleset>`));
    assert.equal(check(namespaceProbe, null, "src/NamespaceProbe.php", path).messages.some(message => message.source === namespaceCode), false);
  } finally {
    unlinkSync(root + path);
  }
});

// Naming probes cannot establish that every inherited library diagnostic remains
// active at every future path. Protect the local selector boundary structurally.
function assertRulesetCoverage(ruleset) {
  const result = spawnSync("php", ["-r", `
    $xml = simplexml_load_string(stream_get_contents(STDIN));
    if ($xml === false) { exit(1); }
    $values = static fn($nodes) => array_map(static fn($node) => (string) $node, $nodes);
    $attributes = static fn($nodes) => array_map(static fn($node) => (array) $node->attributes(), $nodes);
    echo json_encode([
      'forbidden' => count($xml->xpath('//@phpcs-only | //@phpcbf-only | //include-pattern | //rule//exclude-pattern | //exclude | //severity | //type | //file/@* | //exclude-pattern/@* | //rule/@*[name() != "ref"]')),
      'files' => $values($xml->xpath('//file')),
      'exclusions' => $values($xml->xpath('//exclude-pattern')),
      'rules' => $values($xml->xpath('//rule/@ref')),
      'arguments' => $attributes($xml->xpath('//arg')),
      'configuration' => $attributes($xml->xpath('//config')),
    ], JSON_THROW_ON_ERROR);
  `], { cwd: root, input: ruleset, encoding: "utf8", timeout: 60000 });
  assert.ifError(result.error);
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), {
    forbidden: 0,
    files: ["."],
    exclusions: ["/vendor/", "/node_modules/"],
    rules: ["RANWordPressLibrary", "RANOwnedMethods", "WordPress.NamingConventions.PrefixAllGlobals"],
    configuration: [{ "@attributes": { name: "testVersion", value: "8.2-" } }],
    arguments: [
      { "@attributes": { name: "basepath", value: "." } },
      { "@attributes": { name: "colors" } },
      { "@attributes": { name: "extensions", value: "php" } },
      { "@attributes": { name: "parallel", value: "4" } },
      { "@attributes": { value: "sp" } },
    ],
  }, "unreviewed ruleset coverage change");
}

test("repository XML preserves unconditional coverage and reviewed path boundaries", () => {
  assertRulesetCoverage(readFileSync(root + ".phpcs.xml", "utf8"));
});

test("conditional and targeted path weakening hides a library diagnostic but fails the guard", () => {
  const ruleset = readFileSync(root + ".phpcs.xml", "utf8");
  const code = "WordPress.WP.AlternativeFunctions.json_encode_json_encode";
  const source = "<?php json_encode( array() );";
  const target = "src/unreviewed-future.php";
  const diagnosticPresent = result => result.messages.some(message => message.source === code);
  assert.ok(diagnosticPresent(check(source, null, target)), "baseline library diagnostic missing");
  const mutants = [
    ...['phpcbf-only="true"', 'phpcs-only="false"'].map(attribute =>
      ruleset.replace('<rule ref="RANWordPressLibrary"/>', `<rule ref="RANWordPressLibrary" ${attribute}/>`)),
    ruleset.replace("</ruleset>", `<rule ref="${code}"><include-pattern>^(?!*unreviewed-future[.]php)</include-pattern></rule></ruleset>`),
    ruleset.replace("</ruleset>", `<rule ref="${code}"><exclude-pattern>*/unreviewed-future.php</exclude-pattern></rule></ruleset>`),
    ruleset.replace("</ruleset>", '<exclude-pattern>*/unreviewed-future.php</exclude-pattern></ruleset>'),
  ];
  const path = `standards-coverage-${process.pid}-${Date.now()}.xml`;
  try {
    for (const mutant of mutants) {
      writeFileSync(root + path, mutant);
      assert.equal(diagnosticPresent(check(source, null, target, path)), false, "mutation did not hide the target diagnostic");
      if (mutant.includes("unreviewed-future")) {
        assert.ok(diagnosticPresent(check(source, null, "src/adjacent-future.php", path)), "targeted mutation unexpectedly hid the adjacent diagnostic");
      }
      assert.throws(() => assertRulesetCoverage(mutant), /unreviewed ruleset coverage change/);
    }
    // Conditional filtering also applies to properties and their array elements.
    // Every spelling is forbidden, including forms active only under PHPCBF.
    for (const attribute of ['phpcs-only="true"', 'phpcs-only="false"', 'phpcbf-only="true"', 'phpcbf-only="false"']) {
      for (const node of ['<rule ', '<property ', '<element ']) {
        assert.throws(() => assertRulesetCoverage(ruleset.replace(node, `${node}${attribute} `)), /unreviewed ruleset coverage change/);
      }
    }
    for (const mutant of [
      ruleset.replace('<file>.</file>', '<file>src</file>'),
      ruleset.replace('</ruleset>', '<arg name="ignore" value="*/unreviewed-future.php"/></ruleset>'),
    ]) {
      assert.throws(() => assertRulesetCoverage(mutant), /unreviewed ruleset coverage change/);
    }
  } finally {
    unlinkSync(root + path);
  }
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
  for (const path of ["tests/contract.php", "tests/FuturePrefix.php", "src/FuturePrefix.php", "src/tests/FuturePrefix.php", "src/views/FuturePrefix.php", "future-prefix.php"]) {
    const result = check("<?php function unowned_probe() {} class UnownedProbe {} const UNOWNED_PROBE = 1; $local_value = 1;", prefix, path);
    assert.notEqual(result.status, 0);
    assert.deepEqual(result.messages.map(message => message.source).sort(), ["Class", "Constant", "Function", "Variable"].map(kind => `${prefix}.NonPrefixed${kind}Found`).sort(), path);
  }
  assert.deepEqual(check("<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Isolated test local.\n$local_value = 1;", prefix, "tests/FuturePrefix.php"), { status: 0, messages: [] });
});

// Only the three existing standalone files retain a persistent variable exemption.
const persistentVariableFiles = [
  "tests/analysis-coverage.php", "tests/contract.php", "tests/fixtures/archive-safety.php",
];

function commentDirectives(source, path = "") {
  const result = spawnSync("php", ["-r", `
    $tokens = token_get_all(stream_get_contents(STDIN));
    echo json_encode(array_values(array_filter($tokens, static fn($token) =>
      is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
    )), JSON_THROW_ON_ERROR);
  `], { cwd: root, input: source, encoding: "utf8", timeout: 60000 });
  assert.ifError(result.error);
  assert.equal(result.status, 0, result.stderr);
  const directives = [];
  for (const [, comment, line] of JSON.parse(result.stdout)) {
    assert.doesNotMatch(comment, /@codingStandards(?:Ignore|ChangeSetting)|@phpcs:/i, path);
    for (const match of comment.matchAll(/phpcs:(\S+)([^\r\n]*)/gi)) {
      const operation = match[1].toLowerCase();
      assert.ok(["ignore", "disable"].includes(operation), `${path}: forbidden directive ${operation}`);
      const detail = match[2].replace(/\*\/\s*$/, "").trim();
      const parts = detail.split(" -- ");
      assert.ok(parts.length >= 2 && parts.slice(1).join(" -- ").trim(), `${path}: missing reason`);
      const codes = parts[0];
      for (const code of codes.split(",")) {
        assert.match(code.trim(), /^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*){3}$/, `${path}: broad diagnostic selector`);
      }
      const reason = parts.slice(1).join(" -- ");
      if (operation === "disable") {
        assert.equal(line + (comment.slice(0, match.index).match(/\n/g) ?? []).length, 2, `${path}: persistent exemption must remain at the existing boundary`);
        assert.equal(codes, "WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound");
        assert.ok(persistentVariableFiles.includes(path), `${path}: unreviewed persistent exemption`);
      }
      directives.push({ operation, codes, reason });
    }
  }
  return directives;
}

function maintainedPhp(directory = "") {
  return readdirSync(root + directory, { withFileTypes: true }).flatMap(entry => {
    const path = directory + entry.name;
    if (entry.isDirectory()) {
      return ["vendor", "node_modules", ".git", ".workspaces"].includes(path) ? [] : maintainedPhp(path + "/");
    }
    if (!entry.isFile() || !/\.php$/i.test(path)) return [];
    assert.ok(path.endsWith(".php"), `${path}: checker does not select uppercase PHP extensions`);
    return [path];
  }).sort();
}

test("every maintained PHP file rejects broad or unexplained suppressions", () => {
  const files = maintainedPhp();
  assert.ok(files.length > 0);
  for (const path of files) commentDirectives(readFileSync(root + path, "utf8"), path);
});

test("blanket, case-variant, ancestor and legacy suppression bypasses fail the guard", () => {
  const bypasses = [
    "phpcs:disable", "PHPCS:DISABLE", "phpcs:ignore -- blanket", "phpcs:ignoreFile", "PHPCS:IGNOREfileSuffix",
    "phpcs:ignore RANOwnedMethods -- ancestor standard", "phpcs:disable RANOwnedMethods.NamingConventions -- ancestor category",
    "phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName -- ancestor sniff",
    "phpcs:disable RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- persistent waiver",
    "phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase",
    "@codingStandardsIgnoreStart",
  ];
  for (const directive of bypasses) {
    const source = `<?php\n// ${directive}\nclass Probe { public function badName() {} }\n`;
    assert.deepEqual(check(source), { status: 0, messages: [] }, directive);
    assert.throws(() => commentDirectives(source, "src/Probe.php"), undefined, directive);
    for (const comment of [`/* ${directive} */`, `/**\n * ${directive}\n */`]) {
      assert.throws(() => commentDirectives(`<?php\n${comment}\n`, "src/Probe.php"));
    }
    assert.deepEqual(commentDirectives(`<?php $literal = '${directive}';`), []);
  }
  const prefix = "WordPress.NamingConventions.PrefixAllGlobals";
  const declaration = "function rogue_function() {}";
  assert.equal(check(`<?php\n${declaration}\n`, prefix).messages.length, 1);
  for (const marker of ["@codingStandardsChangeSetting", "phpcs:set", "PHPCS:SET"]) {
    const directive = `${marker} ${prefix} prefixes rogue`;
    const source = `<?php\n// ${directive}\n${declaration}\n`;
    assert.deepEqual(check(source, prefix), { status: 0, messages: [] }, directive);
    assert.throws(() => commentDirectives(source, "src/Probe.php"));
    assert.throws(() => commentDirectives(`<?php\n/** ${directive} */\n`, "src/Probe.php"));
    assert.deepEqual(commentDirectives(`<?php $literal = '${directive}';`), []);
  }
  for (const directive of ["@phpcs:ignore", "@CODINGSTANDARDSCHANGESETTING WordPress.NamingConventions.PrefixAllGlobals prefixes rogue"]) {
    assert.throws(() => commentDirectives(`<?php\n// ${directive}\n`, "src/Probe.php"));
  }
});

test("precise annotations preserve adjacent diagnostics and persistent scopes stay bounded", () => {
  const source = `<?php
// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Synthetic foreign signature.
class Probe { public function foreignName() {} }
class OtherProbe { public function ownedBadName() {} }
`;
  const result = check(source);
  assert.equal(result.messages.length, 1);
  assert.ok(result.messages[0].message.includes("ownedBadName"));
  assert.equal(commentDirectives(source, "tests/Future.php").length, 1);
  // Syntax eligibility is not approval; new exact annotations still require PR review.
  const persistent = "<?php\n// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- CLI locals.\n";
  assert.throws(() => commentDirectives(persistent, "tests/Future.php"));
  assert.throws(() => commentDirectives(persistent.replace("<?php\n", "<?php\n\n"), "tests/contract.php"));
  assert.throws(() => commentDirectives(persistent.replace("// phpcs:", "/**\n * phpcs:") + " */\n", "tests/contract.php"));
  const prefix = "WordPress.NamingConventions.PrefixAllGlobals";
  for (const path of persistentVariableFiles) {
    const source = readFileSync(root + path, "utf8") + "\nfunction unowned_future_declaration() {}\n";
    const result = check(source, prefix, path);
    assert.deepEqual(result.messages.map(message => message.source), [`${prefix}.NonPrefixedFunctionFound`], path);
  }
});


test("a future untracked root file cannot hide behind a file-wide ignore", () => {
  const path = `suppression-probe-${process.pid}-${Date.now()}.php`;
  const source = "<?php\n// PHPCS:IGNOREfileSuffix\nclass Probe { public function badName() {} }\n";
  try {
    writeFileSync(root + path, source, { flag: "wx" });
    assert.ok(maintainedPhp().includes(path));
    assert.deepEqual(check(source, sniff, path), { status: 0, messages: [] });
    assert.throws(() => commentDirectives(readFileSync(root + path, "utf8"), path));
  } finally {
    unlinkSync(root + path);
  }
});


test("PHP 8.2 compatibility target retains the actual newer-function diagnostic", () => {
  const ruleset = readFileSync(root + ".phpcs.xml", "utf8");
  const code = "PHPCompatibility.FunctionUse.NewFunctions.json_validateFound";
  const source = "<?php json_validate( '{}' );";
  const present = result => result.messages.some(message => message.source === code);
  assert.ok(present(check(source, null)), "PHP 8.2 diagnostic missing");
  const path = `standards-compatibility-${process.pid}-${Date.now()}.xml`;
  try {
    const raised = ruleset.replace('value="8.2-"', 'value="8.3-"');
    writeFileSync(root + path, raised);
    assert.equal(present(check(source, null, "src/CompatibilityProbe.php", path)), false);
    for (const mutant of [raised,
      ruleset.replace('<config name="testVersion" value="8.2-"/>', ''),
      ruleset.replace('</ruleset>', '<config name="testVersion" value="8.3-"/></ruleset>'),
    ]) assert.throws(() => assertRulesetCoverage(mutant), /unreviewed ruleset coverage change/);
  } finally { unlinkSync(root + path); }
});
