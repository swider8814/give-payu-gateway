<?php
/**
 * Test runner. No dependencies beyond PHP:
 *
 *     php tests/run.php
 *
 * Exits non-zero when a check fails, so it can gate a release.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*-test.php') as $file) {
    echo "\n=== " . basename($file) . " ===\n";
    require $file;
}

give_payu_test_summary();

exit(give_payu_test_failures() === 0 ? 0 : 1);
