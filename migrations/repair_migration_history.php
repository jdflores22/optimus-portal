<?php

declare(strict_types=1);

/**
 * Thin wrapper — delegates to Symfony command driven by .env defaults.
 *
 * Prefer:
 *   php bin/console app:migrations:repair
 *   php bin/console app:migrations:repair --env=test
 *
 * Legacy flags still supported:
 *   --dry-run  --normalize-only  --mark-all-applied  --rebuild-metadata  --env=test
 */

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectDir = dirname(__DIR__);
$args = array_slice($argv, 1);

$consoleArgs = ['app:migrations:repair'];

$hasMarkApplied = false;
foreach ($args as $arg) {
    if ($arg === '--mark-all-applied') {
        $consoleArgs[] = '--mark-applied';
        $hasMarkApplied = true;
        continue;
    }
    if ($arg === '--dry-run') {
        $consoleArgs[] = '--dry-run';
        continue;
    }
    if ($arg === '--normalize-only') {
        $consoleArgs[] = '--normalize-only';
        continue;
    }
    if ($arg === '--rebuild-metadata') {
        $consoleArgs[] = '--rebuild-metadata';
        continue;
    }
    if (str_starts_with($arg, '--env=')) {
        $consoleArgs[] = $arg;
    }
}

if (!$hasMarkApplied && !in_array('--normalize-only', $consoleArgs, true)) {
    $consoleArgs[] = '--mark-applied';
}

$command = array_merge([PHP_BINARY, $projectDir . '/bin/console'], $consoleArgs);
$process = new Process($command, $projectDir, null, null, 300);
$process->run(static function ($type, $buffer): void {
    echo $buffer;
});

exit($process->getExitCode() ?? 1);
