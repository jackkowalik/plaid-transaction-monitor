<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Webhook;

use PDO;
use PHPUnit\Framework\TestCase;
use PlaidMonitor\Alerts\AlertDispatcher;
use PlaidMonitor\Alerts\Sinks\EmailSink;
use PlaidMonitor\Alerts\Sinks\SlackSink;
use PlaidMonitor\Alerts\Sinks\WebhookSink;
use PlaidMonitor\Alerts\WebhookSigner;
use PlaidMonitor\AlwaysEligible;
use PlaidMonitor\Plaid\PlaidClient;
use PlaidMonitor\Processor;
use PlaidMonitor\Rules\Processors\AmountThreshold;
use PlaidMonitor\Rules\Processors\NewMerchant;
use PlaidMonitor\Rules\RuleEngine;
use PlaidMonitor\Plaid\PlaidException;
use PlaidMonitor\Scheduler\TimingPolicy;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\Item;
use PlaidMonitor\Storage\Pdo\PdoAlertStore;
use PlaidMonitor\Storage\Pdo\PdoIntegrationStore;
use PlaidMonitor\Storage\Pdo\PdoMerchantHistoryStore;
use PlaidMonitor\Storage\Pdo\PdoRefreshRequestStore;
use PlaidMonitor\Storage\Pdo\PdoTransactionStore;
use PlaidMonitor\Sync\Deduplicator;
use PlaidMonitor\Sync\TransactionSyncer;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\FakeHttpClient;
use PlaidMonitor\Tests\Support\Fixtures;
use PlaidMonitor\Tests\Support\FrozenClock;
use PlaidMonitor\Tests\Support\RecordingMailer;
use PlaidMonitor\Webhook\SyncHandler;
use Psr\Log\NullLogger;

/**
 * SYNC_UPDATES_AVAILABLE from the handler down to alert delivery, with
 * Plaid, the alert webhook and Slack faked over HTTP.
 */
class SyncFlowTest extends TestCase
{
    private PDO $pdo;
    private FakeHttpClient $http;
    private FrozenClock $clock;
    private RecordingMailer $mailer;
    private PdoIntegrationStore $integrations;
    private PdoRefreshRequestStore $requests;
    private PdoTransactionStore $transactions;

    protected function setUp(): void
    {
        $this->pdo = Database::sqlite();
        $this->http = new FakeHttpClient();
        $this->clock = new FrozenClock('2026-10-01 12:00:00');
        $this->mailer = new RecordingMailer();
        $this->integrations = new PdoIntegrationStore($this->pdo);
        $this->requests = new PdoRefreshRequestStore($this->pdo);
        $this->transactions = new PdoTransactionStore($this->pdo);

        $this->integrations->saveItem(new Item('item_1', 'access-1', 'Test Bank'));
        $this->http->always('/alerts', FakeHttpClient::json(['ok' => true]));
        $this->http->always('/services/T000/B000/XXXX', FakeHttpClient::json(['ok' => true]));
    }

    private function handler(bool $paidEnabled = true): SyncHandler
    {
        $logger = new NullLogger();
        $plaid = new PlaidClient($this->http, 'client', 'secret', 'sandbox', $logger);
        $merchants = new PdoMerchantHistoryStore($this->pdo);

        $processor = new Processor(
            $this->integrations,
            $this->requests,
            $this->transactions,
            new TransactionSyncer($plaid, $logger),
            new Deduplicator($this->transactions, $merchants),
            new RuleEngine([new AmountThreshold(), new NewMerchant($merchants, $this->clock)], $logger),
            new AlertDispatcher(
                new PdoAlertStore($this->pdo),
                new WebhookSink($this->http, $this->clock, $logger, [5, 10, 15], static function (int $s): void {}),
                new EmailSink($this->mailer, $logger),
                new SlackSink($this->http, $logger),
                $this->clock,
                $logger
            ),
            $this->clock,
            $logger
        );

        return new SyncHandler(
            $this->integrations,
            $this->requests,
            new TimingPolicy($paidEnabled, 1440, 0.9, 0.85),
            new AlwaysEligible(),
            $processor,
            $this->clock,
            $logger
        );
    }

    private function saveIntegration(int $interval, ?string $cursor = null, ?string $lastStartedAt = null): void
    {
        $this->integrations->saveIntegration(new Integration(
            integrationId: 'int_1',
            itemId: 'item_1',
            accountId: null,
            intervalMinutes: $interval,
            rules: [
                Fixtures::rule('amount_threshold', ['operator' => 'greater_than', 'threshold' => 100], email: true, slack: false),
                Fixtures::rule('new_merchant', ['detectionType' => 'new'], email: false, slack: true),
            ],
            name: 'Checking',
            alertWebhookUrl: 'https://alerts.example.com/alerts',
            alertWebhookSecret: 'whsec_test',
            alertEmail: 'owner@example.com',
            slackWebhookUrl: 'https://hooks.slack.com/services/T000/B000/XXXX',
            cursor: $cursor,
            lastProcessingStartedAt: $lastStartedAt !== null ? new \DateTimeImmutable($lastStartedAt, new \DateTimeZone('UTC')) : null,
            baselineComplete: $cursor !== null,
        ));
    }

    /**
     * @param list<array<string, mixed>> $added
     */
    private function plaidReturns(array $added, string $nextCursor, ?string $updateStatus = null): void
    {
        $page = ['added' => $added, 'modified' => [], 'removed' => [], 'next_cursor' => $nextCursor, 'has_more' => false];
        if ($updateStatus !== null) {
            $page['transactions_update_status'] = $updateStatus;
        }
        $this->http->queue('/transactions/sync', FakeHttpClient::json($page));
    }

    public function testFirstSyncStoresHistoryWithoutAlerting(): void
    {
        $this->saveIntegration(2880);
        $this->plaidReturns([Fixtures::plaidTransaction(['amount' => 5000.0, 'merchant_name' => 'Jeweler'])], 'cursor_1');

        $this->handler()->handle('item_1');

        $this->assertSame('cursor_1', $this->integrations->find('int_1')->cursor);
        $this->assertSame([], $this->http->requestsTo('/alerts'));
        $this->assertSame([], $this->mailer->sent);
        $this->assertCount(1, $this->transactions->since('int_1', '2026-01-01'));
    }

    public function testBaselineLastsUntilPlaidFinishesPullingHistory(): void
    {
        $this->saveIntegration(2880);

        // Initial update: Plaid has only the last few days so far.
        $this->plaidReturns([Fixtures::plaidTransaction(['amount' => 900.0, 'merchant_name' => 'Recent'])], 'cursor_1', 'INITIAL_UPDATE_COMPLETE');
        $this->handler()->handle('item_1');
        $this->assertFalse($this->integrations->find('int_1')->baselineComplete);

        // Historical update: 90 days of history arrive. Still the baseline.
        $this->clock->advance('+2 days');
        $this->plaidReturns([Fixtures::plaidTransaction(['amount' => 5000.0, 'merchant_name' => 'Old', 'date' => '2026-08-01'])], 'cursor_2', 'HISTORICAL_UPDATE_COMPLETE');
        $this->handler()->handle('item_1');
        $this->assertTrue($this->integrations->find('int_1')->baselineComplete);
        $this->assertSame([], $this->http->requestsTo('/alerts'));

        // From now on transactions are evaluated.
        $this->clock->advance('+2 days');
        $this->plaidReturns([Fixtures::plaidTransaction(['amount' => 700.0, 'merchant_name' => 'Recent', 'date' => '2026-10-05'])], 'cursor_3', 'HISTORICAL_UPDATE_COMPLETE');
        $this->handler()->handle('item_1');
        $this->assertCount(1, $this->http->requestsTo('/alerts'));
    }

    public function testPaidRefreshWebhookIsProcessedAndAlertsAreDelivered(): void
    {
        $this->saveIntegration(60, 'cursor_1', '2026-10-01 11:00:00');
        $this->requests->create('int_1', 'item_1', new \DateTimeImmutable('2026-10-01 11:00:00'));
        $this->plaidReturns([
            Fixtures::plaidTransaction(['transaction_id' => 'big', 'amount' => 600.0, 'merchant_name' => 'Jeweler']),
            Fixtures::plaidTransaction(['transaction_id' => 'small', 'amount' => 4.0, 'merchant_name' => 'Bakery']),
        ], 'cursor_2');

        $this->handler()->handle('item_1');

        $this->assertSame('cursor_2', $this->integrations->find('int_1')->cursor);
        $this->assertNull($this->requests->findPending('int_1'));

        // Webhook: every flagged transaction, signed as the README describes.
        $webhook = $this->http->requestsTo('/alerts')[0];
        $this->assertTrue(WebhookSigner::verify($webhook['body'], $webhook['headers']['X-Webhook-Signature'], 'whsec_test'));
        $this->assertSame('fraud_alert.detected', $webhook['json']['event']);
        $this->assertSame('2026-10-01T12:00:00Z', $webhook['json']['timestamp']);
        $this->assertSame('critical', $webhook['json']['data']['severity']);
        $rulesPerTransaction = array_column(array_map(
            fn($t) => [$t['transaction_id'], count($t['triggered_rules'])],
            $webhook['json']['data']['flagged_transactions']
        ), 1, 0);
        $this->assertSame(['big' => 2, 'small' => 1], $rulesPerTransaction);

        // Email: only the transaction whose rule has emailAlert.
        $this->assertCount(1, $this->mailer->sent);
        $this->assertStringContainsString('USD 600.00', $this->mailer->sent[0]['body']);
        $this->assertStringNotContainsString('New Merchant', $this->mailer->sent[0]['body']);

        // Slack: only rules with slackAlert.
        $slack = $this->http->requestsTo('/services/T000/B000/XXXX')[0]['json']['text'];
        $this->assertStringContainsString('New Merchant', $slack);
        $this->assertStringNotContainsString('exceeds threshold', $slack);

        $flagged = $this->pdo->query("SELECT transaction_id FROM transactions WHERE flagged = 1 ORDER BY transaction_id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['big', 'small'], $flagged);
    }

    public function testShortIntervalWithoutAPendingRequestIsSkipped(): void
    {
        $this->saveIntegration(60, 'cursor_1', '2026-09-01 00:00:00');

        $this->handler()->handle('item_1');

        $this->assertSame([], $this->http->requestsTo('/transactions/sync'));
    }

    public function testAutomaticUpdatesRespectTheInterval(): void
    {
        $this->saveIntegration(2880, 'cursor_1', '2026-09-28 12:00:00');
        $this->plaidReturns([], 'cursor_2');

        $this->handler()->handle('item_1');
        $this->assertCount(1, $this->http->requestsTo('/transactions/sync'));
        $this->assertEquals($this->clock->now(), $this->integrations->find('int_1')->lastProcessingStartedAt);

        // Plaid's next automatic update a few hours later is ignored.
        $this->clock->advance('+6 hours');
        $this->handler()->handle('item_1');
        $this->assertCount(1, $this->http->requestsTo('/transactions/sync'));
    }

    public function testAFailedAutomaticRunCanBeRetried(): void
    {
        $this->saveIntegration(2880, 'cursor_1', '2026-09-28 12:00:00');
        $this->http->queue('/transactions/sync', FakeHttpClient::json(['error_type' => 'API_ERROR', 'error_code' => 'INTERNAL_SERVER_ERROR'], 500));

        try {
            $this->handler()->handle('item_1');
            $this->fail('expected exception');
        } catch (PlaidException) {
        }

        $this->assertSame('2026-09-28 12:00:00', $this->integrations->find('int_1')->lastProcessingStartedAt->format('Y-m-d H:i:s'));
    }

    public function testWithPaidRefreshDisabledShortIntervalsUseAutomaticUpdates(): void
    {
        $this->saveIntegration(60, 'cursor_1', '2026-10-01 10:00:00');
        $this->plaidReturns([], 'cursor_2');

        $this->handler(paidEnabled: false)->handle('item_1');

        $this->assertCount(1, $this->http->requestsTo('/transactions/sync'));
    }
}
