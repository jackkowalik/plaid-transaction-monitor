<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Sandbox;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\App;
use PlaidMonitor\Config;
use PlaidMonitor\Storage\Ids;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\Item;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\Fixtures;
use PlaidMonitor\Tests\Support\RecordingMailer;
use Psr\Log\NullLogger;

/**
 * Runs against the real Plaid sandbox. Needs PLAID_CLIENT_ID and
 * PLAID_SECRET for a sandbox account with Transactions enabled; skipped
 * otherwise.
 *
 *   composer test-sandbox
 */
class SandboxFlowTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $config = Config::load(dirname(__DIR__, 2));
        if ($config->plaidClientId() === '' || $config->plaidSecret() === '' || $config->plaidEnv() !== 'sandbox') {
            $this->markTestSkipped('Set PLAID_CLIENT_ID and PLAID_SECRET with PLAID_ENV=sandbox to run sandbox tests');
        }

        $this->app = new App(
            new Config([
                'PLAID_CLIENT_ID'       => $config->plaidClientId(),
                'PLAID_SECRET'          => $config->plaidSecret(),
                'PLAID_ENV'             => 'sandbox',
                'PLAID_VERIFY_WEBHOOKS' => 'false',
            ]),
            new NullLogger(),
            Database::sqlite(),
            mailer: new RecordingMailer()
        );
    }

    public function testDynamicTransactionsAreSyncedAndFlagged(): void
    {
        $plaid = $this->app->plaid();
        $sandbox = $this->app->sandbox();
        $exchange = $plaid->exchangePublicToken($sandbox->createPublicToken());
        $integrations = $this->app->integrations();

        $integrations->saveItem(new Item($exchange['item_id'], $exchange['access_token'], 'Sandbox'));
        $integrations->saveIntegration(new Integration(
            integrationId: Ids::generate('int'),
            itemId: $exchange['item_id'],
            accountId: null,
            intervalMinutes: 2880,
            rules: [Fixtures::rule('amount_threshold', ['operator' => 'greater_than', 'threshold' => 500])],
        ));

        try {
            $worker = $this->app->worker();
            $jobs = new \PlaidMonitor\Storage\Pdo\PdoJobStore($this->app->pdo());
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            // First sync stores history as the baseline.
            $this->eventually(function () use ($jobs, $worker, $exchange, $now, $integrations) {
                $jobs->enqueue($exchange['item_id'], 'TRANSACTIONS', 'SYNC_UPDATES_AVAILABLE', [], $now);
                $worker->run();
                $integration = $integrations->findActiveByItem($exchange['item_id'])[0];
                return $integration->cursor !== null && $integration->cursor !== '';
            });

            $today = gmdate('Y-m-d');
            $sandbox->createTransactions($exchange['access_token'], [
                ['date_transacted' => $today, 'date_posted' => $today, 'amount' => 812.34, 'description' => 'Sandbox Jeweler', 'iso_currency_code' => 'USD'],
            ]);
            $plaid->refreshTransactions($exchange['access_token']);

            $this->eventually(function () use ($jobs, $worker, $exchange, $integrations) {
                $integration = $integrations->findActiveByItem($exchange['item_id'])[0];
                $integrations->setProcessingStarted($integration->integrationId, null);
                $jobs->enqueue($exchange['item_id'], 'TRANSACTIONS', 'SYNC_UPDATES_AVAILABLE', [], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                $worker->run();
                return (int) $this->app->pdo()->query("SELECT COUNT(*) FROM alerts WHERE kind = 'fraud_alert'")->fetchColumn() > 0;
            }, 90);

            $payload = json_decode((string) $this->app->pdo()->query('SELECT payload FROM alerts')->fetchColumn(), true);
            $this->assertSame(812.34, $payload['flagged_transactions'][0]['amount']);
        } finally {
            $plaid->removeItem($exchange['access_token']);
        }
    }

    private function eventually(callable $condition, int $timeoutSeconds = 60): void
    {
        $deadline = time() + $timeoutSeconds;
        while (!$condition()) {
            if (time() >= $deadline) {
                $this->fail("Condition not met within {$timeoutSeconds} seconds");
            }
            sleep(5);
        }
    }
}
