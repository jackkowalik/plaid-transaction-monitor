<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Plaid\PlaidClient;
use PlaidMonitor\Plaid\PlaidException;
use PlaidMonitor\Sync\TransactionSyncer;
use PlaidMonitor\Tests\Support\FakeHttpClient;
use PlaidMonitor\Tests\Support\Fixtures;
use Psr\Log\NullLogger;

class TransactionSyncerTest extends TestCase
{
    private FakeHttpClient $http;
    private TransactionSyncer $syncer;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $plaid = new PlaidClient($this->http, 'client', 'secret', 'sandbox', new NullLogger());
        $this->syncer = new TransactionSyncer($plaid, new NullLogger());
    }

    /**
     * @param list<array<string, mixed>> $added
     * @return array<string, mixed>
     */
    private function page(array $added, string $next, bool $hasMore, array $removed = []): array
    {
        return ['added' => $added, 'modified' => [], 'removed' => $removed, 'next_cursor' => $next, 'has_more' => $hasMore];
    }

    public function testFollowsEveryPage(): void
    {
        $this->http
            ->queue('/transactions/sync', FakeHttpClient::json($this->page([Fixtures::plaidTransaction()], 'c1', true)))
            ->queue('/transactions/sync', FakeHttpClient::json($this->page([Fixtures::plaidTransaction()], 'c2', true)))
            ->queue('/transactions/sync', FakeHttpClient::json($this->page([], 'c3', false, [['transaction_id' => 'gone']])));

        $result = $this->syncer->syncAll('access-token', 'c0');

        $this->assertCount(2, $result->added);
        $this->assertSame(['gone'], $result->removed);
        $this->assertSame('c3', $result->nextCursor);
        $this->assertSame(3, $result->pages);
        $this->assertTrue($result->complete);

        $cursors = array_map(fn($r) => $r['json']['cursor'] ?? null, $this->http->requestsTo('/transactions/sync'));
        $this->assertSame(['c0', 'c1', 'c2'], $cursors);
    }

    public function testRestartsFromTheOriginalCursorAfterAMutation(): void
    {
        $this->http
            ->queue('/transactions/sync', FakeHttpClient::json($this->page([Fixtures::plaidTransaction()], 'c1', true)))
            ->queue('/transactions/sync', FakeHttpClient::json([
                'error_code'    => 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION',
                'error_type'    => 'TRANSACTIONS_ERROR',
                'error_message' => 'mutation',
            ], 400))
            ->queue('/transactions/sync', FakeHttpClient::json($this->page([Fixtures::plaidTransaction()], 'c9', false)));

        $result = $this->syncer->syncAll('access-token', 'c0');

        $this->assertCount(1, $result->added);
        $this->assertSame('c9', $result->nextCursor);
        $this->assertSame('c0', $this->http->requestsTo('/transactions/sync')[2]['json']['cursor']);
    }

    public function testOtherErrorsAreThrown(): void
    {
        $this->http->queue('/transactions/sync', FakeHttpClient::json([
            'error_code'    => 'ITEM_LOGIN_REQUIRED',
            'error_type'    => 'ITEM_ERROR',
            'error_message' => 'login required',
        ], 400));

        $this->expectException(PlaidException::class);
        $this->syncer->syncAll('access-token', null);
    }

    public function testFirstSyncSendsNoCursor(): void
    {
        $this->http->queue('/transactions/sync', FakeHttpClient::json($this->page([], 'c1', false)));

        $this->syncer->syncAll('access-token', null);

        $this->assertArrayNotHasKey('cursor', $this->http->requests[0]['json']);
    }

    public function testCurrencyIsKept(): void
    {
        $this->http->queue('/transactions/sync', FakeHttpClient::json($this->page([
            Fixtures::plaidTransaction(['iso_currency_code' => 'EUR']),
        ], 'c1', false)));

        $result = $this->syncer->syncAll('access-token', null);

        $this->assertSame('EUR', $result->added[0]['iso_currency_code']);
        $this->assertSame('FOOD_AND_DRINK_COFFEE', $result->added[0]['category']['detailed']);
    }
}
