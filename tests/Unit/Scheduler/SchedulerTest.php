<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\AlwaysEligible;
use PlaidMonitor\EligibilityPolicy;
use PlaidMonitor\Plaid\PlaidClient;
use PlaidMonitor\Scheduler\Scheduler;
use PlaidMonitor\Scheduler\TimingPolicy;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\Item;
use PlaidMonitor\Storage\Pdo\PdoIntegrationStore;
use PlaidMonitor\Storage\Pdo\PdoLockStore;
use PlaidMonitor\Storage\Pdo\PdoRefreshRequestStore;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\FakeHttpClient;
use PlaidMonitor\Tests\Support\Fixtures;
use PlaidMonitor\Tests\Support\FrozenClock;
use Psr\Log\NullLogger;

class SchedulerTest extends TestCase
{
    private PdoIntegrationStore $integrations;
    private PdoRefreshRequestStore $requests;
    private PdoLockStore $lockStore;
    private FakeHttpClient $http;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $pdo = Database::sqlite();
        $this->integrations = new PdoIntegrationStore($pdo);
        $this->requests = new PdoRefreshRequestStore($pdo);
        $this->lockStore = new PdoLockStore($pdo);
        $this->http = new FakeHttpClient();
        $this->clock = new FrozenClock('2026-10-01 12:00:00');

        $this->integrations->saveItem(new Item('item_1', 'access-1', 'Test Bank'));
        $this->integrations->saveItem(new Item('item_2', 'access-2', 'Test Bank'));
    }

    private function scheduler(bool $paidEnabled = true, ?EligibilityPolicy $eligibility = null): Scheduler
    {
        return new Scheduler(
            $this->integrations,
            $this->requests,
            $this->lockStore,
            new PlaidClient($this->http, 'client', 'secret', 'sandbox', new NullLogger()),
            new TimingPolicy($paidEnabled, 1440, 0.9, 0.85),
            $eligibility ?? new AlwaysEligible(),
            $this->clock,
            new NullLogger()
        );
    }

    public function testRefreshesDueShortIntervalIntegrations(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'due', lastStartedAt: '2026-10-01 10:59:00'));
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'not_due', itemId: 'item_2', lastStartedAt: '2026-10-01 11:30:00'));
        $this->integrations->saveIntegration(Fixtures::integration(2880, integrationId: 'long', itemId: 'item_2'));
        $this->http->always('/transactions/refresh', FakeHttpClient::json(['request_id' => 'r']));

        $report = $this->scheduler()->run();

        $this->assertSame(1, $report['integrations_refreshed']);
        $this->assertSame('access-1', $this->http->requestsTo('/transactions/refresh')[0]['json']['access_token']);
        $this->assertNotNull($this->requests->findPending('due'));
        $this->assertNull($this->requests->findPending('not_due'));
        $this->assertEquals($this->clock->now(), $this->integrations->find('due')->lastProcessingStartedAt);
    }

    public function testTheRequestIsRecordedBeforePlaidIsCalled(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'due'));
        $requests = $this->requests;
        $this->http->always('/transactions/refresh', function () use ($requests) {
            $this->assertNotNull($requests->findPending('due'), 'pending request must exist before Plaid can send the webhook');
            return FakeHttpClient::json(['request_id' => 'r']);
        });

        $this->scheduler()->run();
    }

    public function testOneRefreshPerItem(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'a', accountId: 'acc_1'));
        $this->integrations->saveIntegration(Fixtures::integration(30, integrationId: 'b', accountId: 'acc_2'));
        $this->http->always('/transactions/refresh', FakeHttpClient::json(['request_id' => 'r']));

        $report = $this->scheduler()->run();

        $this->assertCount(1, $this->http->requestsTo('/transactions/refresh'));
        $this->assertSame(2, $report['integrations_refreshed']);
        $this->assertNotNull($this->requests->findPending('a'));
        $this->assertNotNull($this->requests->findPending('b'));
    }

    public function testAFailedRefreshLeavesNoPendingRequestAndKeepsTheStartTime(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'due', lastStartedAt: '2026-10-01 10:00:00'));
        $this->http->always('/transactions/refresh', FakeHttpClient::json([
            'error_code' => 'RATE_LIMIT_EXCEEDED', 'error_type' => 'RATE_LIMIT_EXCEEDED', 'error_message' => 'slow down',
        ], 429));

        $report = $this->scheduler()->run();

        $this->assertSame(1, $report['errors']);
        $this->assertNull($this->requests->findPending('due'));
        $this->assertSame('2026-10-01 10:00:00', $this->integrations->find('due')->lastProcessingStartedAt->format('Y-m-d H:i:s'));
    }

    public function testItemErrorsBackOffWithoutFailingTheRun(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'broken', lastStartedAt: '2026-10-01 10:00:00'));
        $this->http->always('/transactions/refresh', FakeHttpClient::json([
            'error_code' => 'ITEM_LOGIN_REQUIRED', 'error_type' => 'ITEM_ERROR', 'error_message' => 'login required',
        ], 400));

        $report = $this->scheduler()->run();

        $this->assertSame(0, $report['errors']);
        $this->assertSame(1, $report['item_errors']);
        $this->assertNull($this->requests->findPending('broken'));
        $this->assertEquals($this->clock->now(), $this->integrations->find('broken')->lastProcessingStartedAt);
    }

    public function testStaleRequestsFromThePreviousCycleAreReplaced(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'due', lastStartedAt: '2026-10-01 10:00:00'));
        $stale = $this->requests->create('due', 'item_1', new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->http->always('/transactions/refresh', FakeHttpClient::json(['request_id' => 'r']));

        $this->scheduler()->run();

        $pending = $this->requests->findPending('due');
        $this->assertNotSame($stale, $pending['request_id']);
        $this->assertSame(1, $this->requests->deleteForIntegration('due'));
    }

    public function testDisabledPaidRefreshDoesNothing(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'due'));

        $report = $this->scheduler(paidEnabled: false)->run();

        $this->assertSame(0, $report['due']);
        $this->assertSame([], $this->http->requests);
    }

    public function testIneligibleIntegrationsAreSkipped(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(60, integrationId: 'due'));
        $never = new class implements EligibilityPolicy {
            public function isEligible(Integration $integration): bool
            {
                return false;
            }
        };

        $report = $this->scheduler(eligibility: $never)->run();

        $this->assertSame(0, $report['due']);
        $this->assertSame([], $this->http->requests);
    }
}
