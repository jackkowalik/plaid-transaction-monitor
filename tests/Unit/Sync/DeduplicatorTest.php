<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Storage\Pdo\PdoMerchantHistoryStore;
use PlaidMonitor\Storage\Pdo\PdoTransactionStore;
use PlaidMonitor\Sync\Deduplicator;
use PlaidMonitor\Sync\SyncResult;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\Fixtures;

class DeduplicatorTest extends TestCase
{
    private PdoTransactionStore $transactions;
    private PdoMerchantHistoryStore $merchants;
    private Deduplicator $deduplicator;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $pdo = Database::sqlite();
        $this->transactions = new PdoTransactionStore($pdo);
        $this->merchants = new PdoMerchantHistoryStore($pdo);
        $this->deduplicator = new Deduplicator($this->transactions, $this->merchants);
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @param list<array<string, mixed>> $added
     * @param list<array<string, mixed>> $modified
     * @param list<string> $removed
     */
    private function sync(array $added = [], array $modified = [], array $removed = []): SyncResult
    {
        return new SyncResult($added, $modified, $removed, 'cursor', 1, true);
    }

    public function testNewTransactionsAreReturnedAndStoredOnPersist(): void
    {
        $integration = Fixtures::integration();
        $tx = Fixtures::transaction();

        $batch = $this->deduplicator->classify($integration, $this->sync([$tx]));
        $this->assertSame([$tx], $batch->new);
        $this->assertFalse($this->transactions->has('int_1', $tx['transaction_id']));

        $this->deduplicator->persist($integration, $batch, $this->now);
        $this->assertTrue($this->transactions->has('int_1', $tx['transaction_id']));
        $this->assertSame(1, $this->merchants->daysSeen('int_1', 'Coffee Shop', '2026-01-01'));
    }

    public function testAlreadyStoredTransactionsAreSkipped(): void
    {
        $integration = Fixtures::integration();
        $tx = Fixtures::transaction();
        $this->deduplicator->persist($integration, $this->deduplicator->classify($integration, $this->sync([$tx])), $this->now);

        $batch = $this->deduplicator->classify($integration, $this->sync([$tx]));

        $this->assertSame([], $batch->new);
        $this->assertSame(1, $batch->alreadyProcessed);
    }

    public function testTwoIdenticalPurchasesAreBothKept(): void
    {
        $integration = Fixtures::integration();
        $tx = Fixtures::transaction(['transaction_id' => 'a']);
        $this->deduplicator->persist($integration, $this->deduplicator->classify($integration, $this->sync([$tx])), $this->now);

        $batch = $this->deduplicator->classify($integration, $this->sync([array_merge($tx, ['transaction_id' => 'b'])]));

        $this->assertCount(1, $batch->new);
    }

    public function testUnlinkedPostedVersionIsRecognisedWhenThePendingOneIsRemoved(): void
    {
        $integration = Fixtures::integration();
        $pending = Fixtures::transaction(['transaction_id' => 'pending_1', 'pending' => true]);
        $this->deduplicator->persist($integration, $this->deduplicator->classify($integration, $this->sync([$pending])), $this->now);

        $posted = array_merge($pending, ['transaction_id' => 'posted_1', 'pending' => false]);
        $batch = $this->deduplicator->classify($integration, $this->sync([$posted], removed: ['pending_1']));

        $this->assertSame([], $batch->new);
        $this->assertSame([$posted], $batch->storeOnly);
    }

    public function testModifiedTransactionsAreOnlyReEvaluatedWhenTheyMateriallyChange(): void
    {
        $integration = Fixtures::integration();
        $tx = Fixtures::transaction(['transaction_id' => 'a', 'amount' => 40.0]);
        $this->deduplicator->persist($integration, $this->deduplicator->classify($integration, $this->sync([$tx])), $this->now);

        $recategorized = array_merge($tx, ['category' => ['primary' => 'GENERAL_MERCHANDISE', 'detailed' => null, 'confidence' => 'LOW']]);
        $batch = $this->deduplicator->classify($integration, $this->sync(modified: [$recategorized]));
        $this->assertSame([], $batch->modified);
        $this->assertSame([$recategorized], $batch->storeOnly);

        $tipAdded = array_merge($tx, ['amount' => 48.0]);
        $batch = $this->deduplicator->classify($integration, $this->sync(modified: [$tipAdded]));
        $this->assertSame([$tipAdded], $batch->modified);
    }

    public function testRemovedTransactionsAreDeleted(): void
    {
        $integration = Fixtures::integration();
        $tx = Fixtures::transaction(['transaction_id' => 'a']);
        $this->deduplicator->persist($integration, $this->deduplicator->classify($integration, $this->sync([$tx])), $this->now);

        $removal = $this->deduplicator->classify($integration, $this->sync(removed: ['a']));
        $this->deduplicator->persist($integration, $removal, $this->now);

        $this->assertFalse($this->transactions->has('int_1', 'a'));
    }

    public function testPostedVersionOfAPendingTransactionIsStoredButNotReEvaluated(): void
    {
        $integration = Fixtures::integration();
        $pending = Fixtures::transaction(['transaction_id' => 'pending_1', 'pending' => true]);
        $this->deduplicator->persist($integration, $this->deduplicator->classify($integration, $this->sync([$pending])), $this->now);

        $posted = Fixtures::transaction([
            'transaction_id'         => 'posted_1',
            'pending_transaction_id' => 'pending_1',
            'date'                   => '2026-10-02',
        ]);
        $batch = $this->deduplicator->classify($integration, $this->sync([$posted], removed: ['pending_1']));

        $this->assertSame([], $batch->toEvaluate());
        $this->assertSame([$posted], $batch->storeOnly);

        $this->deduplicator->persist($integration, $batch, $this->now);
        $this->assertTrue($this->transactions->has('int_1', 'posted_1'));
        $this->assertFalse($this->transactions->has('int_1', 'pending_1'));
    }

    public function testOnlyTheWatchedAccountIsKept(): void
    {
        $integration = Fixtures::integration(accountId: 'acc_1');

        $batch = $this->deduplicator->classify($integration, $this->sync([
            Fixtures::transaction(['account_id' => 'acc_1']),
            Fixtures::transaction(['account_id' => 'acc_2']),
        ]));

        $this->assertCount(1, $batch->new);
        $this->assertSame('acc_1', $batch->new[0]['account_id']);
    }

    public function testRemovalsOfUnknownTransactionsAreIgnored(): void
    {
        $batch = $this->deduplicator->classify(Fixtures::integration(), $this->sync(removed: ['never_seen']));

        $this->assertSame([], $batch->removed);
        $this->assertTrue($batch->isEmpty());
    }
}
