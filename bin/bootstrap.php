<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PlaidMonitor\App;
use PlaidMonitor\Config;
use PlaidMonitor\Log\ConsoleLogger;
use Psr\Log\LogLevel;

/**
 * Shared setup for the bin/ scripts and public/webhook.php.
 *
 * @throws \RuntimeException on a configuration error
 * @throws \PDOException when the database is unreachable
 */
function bootstrap(?string $logLevel = null): App
{
    $config = Config::load(dirname(__DIR__));
    $logger = new ConsoleLogger(($logLevel ?? $config->logLevel()) ?: LogLevel::INFO);

    return new App($config, $logger);
}

/**
 * bootstrap() for command-line scripts: prints the error and exits on failure.
 */
function bootstrapCli(?string $logLevel = null): App
{
    try {
        return bootstrap($logLevel);
    } catch (\PDOException $e) {
        fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    } catch (\RuntimeException $e) {
        fwrite(STDERR, "Configuration error: {$e->getMessage()}\n");
    }
    exit(1);
}
