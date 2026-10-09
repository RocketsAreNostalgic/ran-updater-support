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
# Real helper failures cannot be mistaken for successful diagnostic validation.
if php "$root/tests/assert-analysis-diagnostic.php" > "$fixture/helper.log" 2>&1; then exit 1; fi
grep -q 'are required' "$fixture/helper.log"
if php "$root/tests/assert-analysis-diagnostic.php" "$fixture/missing.json" "$fixture/src/ArchiveSafety.php" function.notFound missing > "$fixture/helper.log" 2>&1; then exit 1; fi
grep -q 'Cannot read the checker report' "$fixture/helper.log"
printf 'invalid JSON' > "$fixture/malformed.json"
if php "$root/tests/assert-analysis-diagnostic.php" "$fixture/malformed.json" "$fixture/src/ArchiveSafety.php" function.notFound missing > "$fixture/helper.log" 2>&1; then exit 1; fi
if php "$root/tests/assert-analysis-diagnostic.php" "$fixture/clean.json" "$fixture/missing.php" function.notFound missing > "$fixture/helper.log" 2>&1; then exit 1; fi
grep -q 'Selected diagnostic file does not exist' "$fixture/helper.log"
if php "$root/tests/assert-analysis-diagnostic.php" "$fixture/clean.json" "$fixture/src/ArchiveSafety.php" function.notFound missing; then exit 1; fi
if php "$root/tests/generate-template-prefix.php" > "$fixture/helper.log" 2>&1; then exit 1; fi
grep -q 'Template opening argument is required' "$fixture/helper.log"
php "$root/tests/generate-template-prefix.php" fixture > "$fixture/prefix.actual"
printf '\357\273\277<section>' > "$fixture/prefix.expected"
printf 'x%.0s' {1..4096} >> "$fixture/prefix.expected"
printf fixture >> "$fixture/prefix.expected"
cmp "$fixture/prefix.expected" "$fixture/prefix.actual"
mkdir -p "$fixture/new-product/contracts" "$fixture/src/tests" "$fixture/tests"
for path in root-contract.php new-product/contracts/split.php src/tests/runtime-contract.php; do
    printf '<?php\nran_support_missing_contract();\n' > "$fixture/$path"
    php "$root/tests/analysis-coverage.php" "$fixture"
    if analyze > "$fixture/negative.json" 2> "$fixture/negative.log"; then exit 1; fi
    php "$root/tests/assert-analysis-diagnostic.php" "$fixture/negative.json" "$fixture/$path" function.notFound ran_support_missing_contract
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
    php "$root/tests/assert-analysis-diagnostic.php" "$fixture/maintained-negative.json" "$fixture/$path" function.notFound ran_support_missing_contract
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
            php "$root/tests/generate-template-prefix.php" "$opening" > "$fixture/$prefix$path"
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
    php "$root/tests/assert-analysis-diagnostic.php" "$fixture/compatibility.json" "$fixture/$prefix"compatibility.php function.notFound json_validate
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


# Discovery must not inherit the current PHP process's short_open_tag setting.
printf '<? echo "short-tag-executed"; ?>' > "$fixture/short-execution.tpl"
test "$(php -d short_open_tag=1 "$fixture/short-execution.tpl")" = short-tag-executed
rm "$fixture/short-execution.tpl"
for mode in production maintained; do
    args=()
    prefix=""
    if [[ "$mode" == maintained ]]; then args=(--maintained); prefix=tests/; fi
    for suffix in tpl inc phtml custom; do
        printf '<main><? echo "executed"; ?>' > "$fixture/$prefix"short.$suffix
        for enabled in 0 1; do
            if php -d short_open_tag="$enabled" "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
            grep -q 'Nonstandard-extension PHP' "$fixture/guard.log"
        done
        rm "$fixture/$prefix"short.$suffix
    done
    printf '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><root/>' > "$fixture/$prefix"example.xml
    php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}"
    printf '<? echo "executed"; ?>' >> "$fixture/$prefix"example.xml
    if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Nonstandard-extension PHP' "$fixture/guard.log"
    printf '<?xmlfake payload?><root/>' > "$fixture/$prefix"example.xml
    if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Nonstandard-extension PHP' "$fixture/guard.log"
    rm "$fixture/$prefix"example.xml
done
echo 'PASS executable bare short tags under both INI settings and genuine XML boundaries.'

# Ordinary inline interpreters cannot silently create a second unanalysed PHP surface.
for mode in production maintained; do
    args=()
    prefix=""
    if [[ "$mode" == maintained ]]; then args=(--maintained); prefix=tests/; fi
    for command in "php -r 'echo 1;'" "php --run 'echo 1;'" "if php -nr 'echo 1;'; then true; fi" "php --process-code 'echo 1;'" "php <<'PHP'" 'php /dev/stdin' 'printf fixture | php' 'printf fixture | php -n' 'php < code' "env php -r 'echo 1;'" \
        "result=\$(php -r 'echo 1;')" "result=\"\$(php -r 'echo 1;')\"" "(php -r 'echo 1;')" "VAR=1 php -r 'echo 1;'" \
        "FIRST=1 SECOND=two php -r 'echo 1;'" "env VAR=1 php -r 'echo 1;'" \
        'result=$(php)' '(php -n)' 'VAR=1 php < code'; do
        printf '%s\n' '#!/usr/bin/env bash' "$command" > "$fixture/$prefix"inline.sh
        if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
        grep -q 'Inline or STDIN PHP requires' "$fixture/guard.log"
        rm "$fixture/$prefix"inline.sh
    done
    # Quoted examples stay data, and explicit maintained helpers may receive stdin data.
    cp "$root/tests/extract-comment-tokens.php" "$fixture/src/helper.php"
    for command in \
        "printf '%s\\n' '(php -r fixture)'" \
        "printf '%s\\n' 'result=\$(php -r fixture)'" \
        'printf "%s\n" "(php -r fixture)"' \
        'printf "%s\n" "result=\$(php -r fixture)"' \
        'printf "%s\n" "VAR=1 php -r fixture"' \
        'result=$(php src/helper.php < input)' \
        '(php src/helper.php < input)' \
        'VAR=1 php src/helper.php < input'; do
        printf '%s\n' '#!/usr/bin/env bash' "$command" > "$fixture/$prefix"inline.sh
        php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}"
        rm "$fixture/$prefix"inline.sh
    done
    rm "$fixture/src/helper.php"
    for invocation in 'spawnSync("php", ["-r", "echo 1;"])' 'spawnSync("php", ["--run", "echo 1;"])' 'spawnSync("php", [])' 'spawnSync("php", { input: "fixture" })'; do
        printf '%s\n' "$invocation" > "$fixture/$prefix"inline.mjs
        if php "$root/tests/analysis-coverage.php" "$fixture" "${args[@]}" > "$fixture/guard.log" 2>&1; then exit 1; fi
        grep -q 'Inline or STDIN Node PHP requires' "$fixture/guard.log"
        rm "$fixture/$prefix"inline.mjs
    done
done
echo 'PASS ordinary inline and STDIN PHP fail closed in shell and Node drivers.'
