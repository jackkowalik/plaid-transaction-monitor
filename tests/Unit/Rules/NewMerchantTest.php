<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Rules;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Rules\Processors\NewMerchant;
use PlaidMonitor\Storage\Pdo\PdoMerchantHistoryStore;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\Fixtures;
use PlaidMonitor\Tests\Support\FrozenClock;

class NewMerchantTest extends TestCase
{
    private PdoMerchantHistoryStore $history;
    private NewMerchant $rule;

    protected function setUp(): void
    {
        $this->history = new PdoMerchantHistoryStore(Database::sqlite());
        $this->rule = new NewMerchant($this->history, new FrozenClock('2026-10-01 12:00:00'));
    }

    public function testFlagsMerchantsNotSeenInTheLookbackWindow(): void
    {
        $this->history->record('int_1', 'Coffee Shop', '2026-09-20');
        $this->history->record('int_1', 'Old Store', '2026-01-01');

        $flags = $this->rule->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['merchant_name' => 'coffee shop']),
            Fixtures::transaction(['merchant_name' => 'Old Store']),
            Fixtures::transaction(['merchant_name' => 'Brand New']),
        ], ['detectionType' => 'new', 'lookbackDays' => 90]);

        $this->assertSame(['Old Store', 'Brand New'], array_map(fn($f) => $f->transaction['merchant_name'], $flags));
    }

    public function testFlagsANewMerchantOncePerRun(): void
    {
        $flags = $this->rule->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['merchant_name' => 'Brand New', 'date' => '2026-09-30']),
            Fixtures::transaction(['merchant_name' => 'Brand New', 'date' => '2026-10-01']),
        ], ['detectionType' => 'new']);

        $this->assertCount(1, $flags);
    }

    public function testRareCountsDistinctDays(): void
    {
        $this->history->record('int_1', 'Bakery', '2026-09-01');
        $this->history->record('int_1', 'Bakery', '2026-09-02');
        $this->history->record('int_1', 'Bakery', '2026-09-03');
        $this->history->record('int_1', 'Florist', '2026-09-01');

        $flags = $this->rule->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['merchant_name' => 'Bakery']),
            Fixtures::transaction(['merchant_name' => 'Florist']),
        ], ['detectionType' => 'rare', 'maxPurchaseCount' => 3]);

        $this->assertCount(1, $flags);
        $this->assertSame('Florist', $flags[0]->transaction['merchant_name']);
    }
}
