<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cloverPath = $root.'/var/coverage/clover.xml';
$threshold = 90.0;

$targetDirs = [];
foreach (['Domain', 'Application'] as $layer) {
    foreach (glob($root.'/src/*/'.$layer, GLOB_ONLYDIR) ?: [] as $dir) {
        $targetDirs[] = $dir;
    }
}

if ([] === $targetDirs) {
    fwrite(STDOUT, "check-coverage: no src/*/Domain or src/*/Application directories yet; skipping threshold.\n");
    exit(0);
}

if (!is_file($cloverPath)) {
    fwrite(STDERR, "check-coverage: clover report not found at {$cloverPath}.\n");
    exit(1);
}

$report = @simplexml_load_file($cloverPath);
if (false === $report) {
    fwrite(STDERR, "check-coverage: unable to parse clover report at {$cloverPath}.\n");
    exit(1);
}

$statements = 0;
$covered = 0;
foreach ($report->xpath('//file') ?: [] as $file) {
    $name = (string) $file['name'];
    $matched = false;
    foreach ($targetDirs as $dir) {
        if (str_starts_with($name, $dir.'/')) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        continue;
    }

    $metrics = $file->metrics;
    if (null === $metrics) {
        continue;
    }

    $statements += (int) $metrics['statements'];
    $covered += (int) $metrics['coveredstatements'];
}

if (0 === $statements) {
    fwrite(STDOUT, "check-coverage: no executable statements found in Domain/Application layers; skipping threshold.\n");
    exit(0);
}

$percent = ($covered / $statements) * 100;
$formatted = number_format($percent, 2);

if ($percent < $threshold) {
    fwrite(STDERR, "check-coverage: {$formatted}% is below the required {$threshold}% for Domain/Application layers.\n");
    exit(1);
}

fwrite(STDOUT, "check-coverage: {$formatted}% meets the required {$threshold}% for Domain/Application layers.\n");
exit(0);
