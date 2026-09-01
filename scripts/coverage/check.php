<?php

declare(strict_types=1);

[$script, $report, $minimum] = $argv + [null, null, null];

if ($report === null || $minimum === null || !is_file($report)) {
    fwrite(STDERR, "Usage: php scripts/coverage/check.php <phpcov-text-report> <minimum-line-coverage>\n");
    exit(2);
}

$contents = file_get_contents($report);

if ($contents === false || preg_match('/Lines:\s+([0-9.]+)%/', $contents, $matches) !== 1) {
    fwrite(STDERR, "Could not read line coverage from PHPCOV report: $report\n");
    exit(2);
}

$coverage = (float) $matches[1];
$required = (float) $minimum;

printf("Aggregate line coverage: %.2f%% (required: %.2f%%)\n", $coverage, $required);

if ($coverage < $required) {
    exit(1);
}
