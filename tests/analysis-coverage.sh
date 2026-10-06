#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php "$root/tests/analysis-coverage.php"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
cp "$root/composer.json" "$root/composer.lock" "$root/phpstan.neon" "$fixture/"
cp -R "$root/src" "$fixture/src"
ln -s "$root/vendor" "$fixture/vendor"
analyze() { composer --no-plugins --no-interaction --working-dir="$fixture" analyze -- --error-format=json; }
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
