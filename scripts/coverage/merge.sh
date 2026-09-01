#!/bin/sh
set -eu

coverage_dir=build/coverage
raw_dir="$coverage_dir/raw"
mkdir -p "$coverage_dir"

vendor/bin/phpcov merge --php "$coverage_dir/merged.cov" --clover "$coverage_dir/clover.xml" --text "$coverage_dir/text.txt" "$raw_dir"
php scripts/coverage/check.php "$coverage_dir/text.txt" 90.0
