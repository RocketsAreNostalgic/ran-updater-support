#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php "$root/tests/analysis-coverage.php"
php "$root/tests/analysis-coverage.php" "$root" --maintained
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
cp "$root/composer.json" "$root/composer.lock" "$root/phpstan.neon" "$root/phpstan-maintained.neon" "$fixture/"
cp -R "$root/src" "$fixture/src"
ln -s "$root/vendor" "$fixture/vendor"
analyze() { composer --no-plugins --no-interaction --working-dir="$fixture" analyze:production -- --error-format=json; }
analyze > "$fixture/clean.json"
mkdir -p "$fixture/new-product/contracts" "$fixture/src/tests" "$fixture/tests"
for path in root-contract.php new-product/contracts/split.php src/tests/runtime-contract.php; do
    printf '<?php\nran_support_missing_contract();\n' > "$fixture/$path"
    php "$root/tests/analysis-coverage.php" "$fixture"
    if analyze > "$fixture/negative.json" 2> "$fixture/negative.log"; then exit 1; fi
    php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="function.notFound"&&str_contains($m["message"],"ran_support_missing_contract")){exit(0);}}exit(1);' "$fixture/negative.json" "$fixture/$path"
    rm "$fixture/$path"
done
# Moving an existing maintained source beyond its old src root retains coverage.
source=$(find "$fixture/src" -type f -name '*.php' | head -1)
mv "$source" "$fixture/moved-contract.php"
php "$root/tests/analysis-coverage.php" "$fixture"
printf '<?php\n' > "$fixture/tests/development.php"
php "$root/tests/analysis-coverage.php" "$fixture"
for path in NewContract.PHP contract-tool; do
    printf '#!/usr/bin/env php\n<?php\n' > "$fixture/$path"
    if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -Eq 'Unsupported PHP extension|Nonstandard-extension PHP' "$fixture/guard.log"
    rm "$fixture/$path"
done
for header in '<?PHP' '<?='; do
    for path in contract-tool contract.inc; do
        printf '%s\n' "$header" > "$fixture/$path"
        if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
        grep -q 'Nonstandard-extension PHP' "$fixture/guard.log"
        rm "$fixture/$path"
    done
done
# CLI removes production registered as a stub after FileFinder selection.
printf '\tstubFiles:\n\t\t- moved-contract.php\n' >> "$fixture/phpstan.neon"
if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Effective PHPStan selection differs' "$fixture/guard.log"
cp "$root/phpstan.neon" "$fixture/phpstan.neon"
# Excluded fixture declarations must not pollute production symbol discovery.
printf '<?php\nconst RAN_SUPPORT_FIXTURE_ONLY = 1;\n' > "$fixture/tests/development.php"
printf '<?php\necho RAN_SUPPORT_FIXTURE_ONLY;\n' > "$fixture/scan-isolation.php"
if analyze > "$fixture/isolation.json" 2> "$fixture/isolation.log"; then exit 1; fi
grep -q 'RAN_SUPPORT_FIXTURE_ONLY' "$fixture/isolation.json"
sed -i 's/analyseAndScan:/analyse:/' "$fixture/phpstan.neon"
analyze > "$fixture/leaked.json"
rm "$fixture/scan-isolation.php"
cp "$root/phpstan.neon" "$fixture/phpstan.neon"
sed -i 's/- \.$/- src/' "$fixture/phpstan.neon"
if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
echo 'PASS inclusive analysis: root, nested, split/moved, role collision, exclusions and unsupported extensions.'

# The separate maintained profile includes every test and future script at level 8.
cp "$root/phpstan.neon" "$fixture/phpstan.neon"
mkdir -p "$fixture/scripts" "$fixture/tests/new-fixtures"
maintained() { composer --no-plugins --no-interaction --working-dir="$fixture" analyze:maintained -- --error-format=json; }
maintained > "$fixture/maintained-clean.json"
for path in tests/new-fixtures/contract.php scripts/maintenance.php; do
    printf '<?php\nran_support_missing_contract();\n' > "$fixture/$path"
    php "$root/tests/analysis-coverage.php" "$fixture" --maintained
    if maintained > "$fixture/maintained-negative.json" 2> "$fixture/negative.log"; then exit 1; fi
    php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="function.notFound"&&str_contains($m["message"],"ran_support_missing_contract")){exit(0);}}exit(1);' "$fixture/maintained-negative.json" "$fixture/$path"
    rm "$fixture/$path"
done
for configuration in phpstan.neon phpstan-maintained.neon; do
    sed -i 's/level: 8/level: 4/' "$fixture/$configuration"
    args=()
    if [[ "$configuration" == phpstan-maintained.neon ]]; then args=(--maintained); fi
    if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
    cp "$root/$configuration" "$fixture/$configuration"
done
printf '\t\t\t- tests/*\n' >> "$fixture/phpstan-maintained.neon"
if php "$root/tests/analysis-coverage.php" "$fixture" --maintained > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
cp "$root/phpstan-maintained.neon" "$fixture/phpstan-maintained.neon"
printf '\tstubFiles:\n\t\t- tests/development.php\n' >> "$fixture/phpstan-maintained.neon"
if php "$root/tests/analysis-coverage.php" "$fixture" --maintained > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Effective PHPStan selection differs' "$fixture/guard.log"
echo 'PASS maintained level 8: future tests/scripts, minimum level, exclusions and stub removal.'

cp "$root/phpstan-maintained.neon" "$fixture/phpstan-maintained.neon"
# A neighboring internal API call still reports its compatibility notification.
printf '<?php\nnew PHPStan\\DependencyInjection\\NeonAdapter([]);\n' > "$fixture/tests/api-boundary.php"
if maintained > "$fixture/api-negative.json" 2> "$fixture/negative.log"; then exit 1; fi
grep -q 'phpstanApi.constructor' "$fixture/api-negative.json"
# A new annotated occurrence requires an explicit reviewed inventory decision.
printf '<?php\n// @phpstan-ignore phpstanApi.constructor (Unreviewed copy.)\nnew PHPStan\\DependencyInjection\\NeonAdapter([]);\n' > "$fixture/tests/api-boundary.php"
if php "$root/tests/analysis-coverage.php" "$fixture" --maintained > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Review new or changed analysis exemptions' "$fixture/guard.log"
rm "$fixture/tests/api-boundary.php"
echo 'PASS exact locked-tool API exemption and immediately outside diagnostic.'


# Templates are executable regardless of suffix or the length of their HTML preamble.
for mode in production maintained; do
    args=()
    prefix=""
    configuration=phpstan.neon
    if [[ "$mode" == maintained ]]; then args=(--maintained); prefix=tests/; configuration=phpstan-maintained.neon; fi
    for path in template.phtml template.inc template.html template.htm template template.unknown template.json template.lock template.neon template.xml template.yml; do
        for opening in '<?php ran_support_missing_contract();' '<?= ran_support_missing_contract();'; do
            php -r 'echo "\xEF\xBB\xBF<section>", str_repeat("x", 4096), $argv[1];' "$opening" > "$fixture/$prefix$path"
            if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
            grep -q 'Nonstandard-extension PHP' "$fixture/guard.log"
            rm "$fixture/$prefix$path"
        done
    done
    printf 'An inert example: <?php ran_support_missing_contract();\n' > "$fixture/$prefix"example.md
    php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}"
    rm "$fixture/$prefix"example.md
    # Only an actual Bash driver may quote a PHP fixture under the shell suffix.
    for shebang in '#!/usr/bin/env bash' '#!/bin/bash'; do
        printf '%s\nprintf '\''<main><?php fixture(); ?></main>'\''\n' "$shebang" > "$fixture/$prefix"example.sh
        php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}"
        sed -i '1d' "$fixture/$prefix"example.sh
        if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
        grep -q 'Nonstandard-extension PHP' "$fixture/guard.log"
        printf '%s\n<?php ran_support_missing_contract();\n' "$shebang" > "$fixture/$prefix"example.sh
        if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
        grep -q 'Nonstandard-extension PHP' "$fixture/guard.log"
        rm "$fixture/$prefix"example.sh
    done
    printf '<?php\necho json_validate( "{}" );\n' > "$fixture/$prefix"compatibility.php
    if composer --no-plugins --no-interaction --working-dir="$fixture" "analyze:$mode" -- --error-format=json > "$fixture/compatibility.json" 2> "$fixture/negative.log"; then exit 1; fi
    php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="function.notFound"&&str_contains($m["message"],"json_validate")){exit(0);}}exit(1);' "$fixture/compatibility.json" "$fixture/$prefix"compatibility.php
    sed -i 's/phpVersion: 80200/phpVersion: 80300/' "$fixture/$configuration"
    composer --no-plugins --no-interaction --working-dir="$fixture" "analyze:$mode" -- --error-format=json > "$fixture/compatibility-raised.json"
    if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
    sed -i '/phpVersion:/d' "$fixture/$configuration"
    if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
    cp "$root/$configuration" "$fixture/$configuration"
    rm "$fixture/$prefix"compatibility.php
done
echo 'PASS executable templates, inert examples and actual PHP 8.2 compatibility boundaries in both profiles.'
