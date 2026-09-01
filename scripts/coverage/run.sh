#!/bin/sh
set -eu

coverage_dir=build/coverage/raw
rm -rf build/coverage
mkdir -p "$coverage_dir"

for app in common identity inventory payment store trade wallet; do
    XDEBUG_MODE=coverage composer --working-dir="apps/$app" test -- --coverage-php "../../$coverage_dir/$app.cov"
done

XDEBUG_MODE=coverage php -d memory_limit=512M vendor/bin/phpunit tests/ --coverage-php "$coverage_dir/root.cov"
composer coverage:merge
