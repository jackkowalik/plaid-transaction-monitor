<?php
declare(strict_types=1);

// Processes queued webhooks. Either run it from cron every minute, which
// drains the queue and exits:
//   * * * * * php /path/to/plaid-transaction-monitor/bin/worker.php
// or keep it running under a process supervisor:
//   php bin/worker.php --loop

require __DIR__ . '/bootstrap.php';

use Psr\Log\LogLevel;

$loop = false;
$maxJobs = 0;
$logLevel = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--loop') {
        $loop = true;
    } elseif (str_starts_with($arg, '--max-jobs=')) {
        $maxJobs = max(0, (int) substr($arg, 11));
    } elseif ($arg === '-v' || $arg === '--verbose') {
        $logLevel = LogLevel::DEBUG;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php bin/worker.php [--loop] [--max-jobs=N] [-v]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

$app = bootstrapCli($logLevel);
$worker = $app->worker();

$stop = false;
if ($loop && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop) { $stop = true; });
    pcntl_signal(SIGINT, static function () use (&$stop) { $stop = true; });
}

do {
    $processed = $worker->run($maxJobs);
    if ($loop && $processed === 0 && !$stop) {
        sleep(1);
    }
} while ($loop && !$stop);

exit(0);
