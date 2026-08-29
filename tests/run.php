<?php

declare(strict_types=1);

// Runs all suites in separate processes and collects the result.
//
// Each suite runs in its own PHP process, so a suite that forks servers cannot
// disturb another. run.php exits with code 0 only if ALL suites are green.
//
// Use:  php tests/run.php

$suites = [
    __DIR__ . '/test_fail_open.php',
    __DIR__ . '/test_no_content.php',
    __DIR__ . '/test_spool.php',
    __DIR__ . '/test_verdict_accuracy.php',
    __DIR__ . '/test_idempotency.php',
    __DIR__ . '/test_read_own.php',
];

$php = PHP_BINARY;
$failed = 0;
$passedSuites = 0;

foreach ($suites as $suite) {
    $code = 0;
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($suite), $code);
    if ($code === 0) {
        $passedSuites++;
    } else {
        $failed++;
    }
}

$total = count($suites);
fwrite(STDOUT, "\n================================\n");
fwrite(STDOUT, sprintf(
    "TOTAL: %d/%d suites green%s\n",
    $passedSuites,
    $total,
    $failed === 0 ? ' - ALL PASSED' : " - {$failed} FAILED",
));
fwrite(STDOUT, "================================\n");

exit($failed === 0 ? 0 : 1);
