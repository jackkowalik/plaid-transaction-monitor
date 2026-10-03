<?php
declare(strict_types=1);

// Cron entry point. Run every 10 minutes:
//   */10 * * * * php /path/to/plaid-transaction-monitor/bin/scheduler.php

require __DIR__ . '/bootstrap.php';

use Psr\Log\LogLevel;

$verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php bin/scheduler.php [-v]\n";
    exit(0);
}

$app = bootstrapCli($verbose ? LogLevel::DEBUG : null);

try {
    $report = $app->scheduler()->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "Scheduler failed: {$e->getMessage()}\n");
    exit(1);
}

exit($report['errors'] > 0 ? 1 : 0);
