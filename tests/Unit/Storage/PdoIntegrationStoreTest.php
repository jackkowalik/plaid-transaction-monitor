<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Storage;

use PDO;
use PHPUnit\Framework\TestCase;
use PlaidMonitor\Storage\Item;
use PlaidMonitor\Storage\Pdo\PdoIntegrationStore;
use PlaidMonitor\Storage\SodiumTokenCipher;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\Fixtures;

class PdoIntegrationStoreTest extends TestCase
{
    public function testSavingAnEditedIntegrationKeepsItsSyncState(): void
    {
        $store = new PdoIntegrationStore(Database::sqlite());
        $store->saveIntegration(Fixtures::integration(60));
        $store->recordProcessed('int_1', 'cursor_9', 'paid_refresh', 3, 1, new \DateTimeImmutable('2026-10-01 12:00:00'), true);

        // An app loads its own copy of the integration, changes the rules and saves it.
        $store->saveIntegration(Fixtures::integration(30, [Fixtures::rule('amount_threshold')]));

        $saved = $store->find('int_1');
        $this->assertSame(30, $saved->intervalMinutes);
        $this->assertCount(1, $saved->rules);
        $this->assertSame('cursor_9', $saved->cursor);
        $this->assertTrue($saved->baselineComplete);
    }

    public function testAccessTokensAreEncryptedWhenAKeyIsSet(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('sodium extension not loaded');
        }

        $pdo = Database::sqlite();
        $cipher = new SodiumTokenCipher(random_bytes(32));
        $store = new PdoIntegrationStore($pdo, $cipher);

        $store->saveItem(new Item('item_1', 'access-sandbox-1234', 'Bank'));

        $raw = $pdo->query("SELECT access_token FROM items WHERE item_id = 'item_1'")->fetchColumn();
        $this->assertStringStartsWith('v1:', $raw);
        $this->assertStringNotContainsString('access-sandbox-1234', $raw);
        $this->assertSame('access-sandbox-1234', $store->findItem('item_1')->accessToken);
    }

    public function testPlainTokensStoredBeforeEncryptionStillWork(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('sodium extension not loaded');
        }

        $pdo = Database::sqlite();
        (new PdoIntegrationStore($pdo))->saveItem(new Item('item_1', 'access-sandbox-1234', 'Bank'));

        $store = new PdoIntegrationStore($pdo, new SodiumTokenCipher(random_bytes(32)));

        $this->assertSame('access-sandbox-1234', $store->findItem('item_1')->accessToken);
    }

    public function testAWrongKeyFailsLoudly(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('sodium extension not loaded');
        }

        $pdo = Database::sqlite();
        (new PdoIntegrationStore($pdo, new SodiumTokenCipher(random_bytes(32))))->saveItem(new Item('item_1', 'access-sandbox-1234'));

        $this->expectException(\RuntimeException::class);
        (new PdoIntegrationStore($pdo, new SodiumTokenCipher(random_bytes(32))))->findItem('item_1');
    }

    public function testDeletingAnItemRemovesEverythingForItsIntegrations(): void
    {
        $pdo = Database::sqlite();
        $store = new PdoIntegrationStore($pdo);
        $store->saveItem(new Item('item_1', 'access-1'));
        $store->saveIntegration(Fixtures::integration());
        $pdo->exec("INSERT INTO refresh_requests (request_id, integration_id, item_id, created_at) VALUES ('rr', 'int_1', 'item_1', '2026-10-01 00:00:00')");
        $pdo->exec("INSERT INTO merchant_history (integration_id, merchant, seen_date) VALUES ('int_1', 'x', '2026-10-01')");

        $store->deleteItem('item_1');

        foreach (['items', 'integrations', 'refresh_requests', 'merchant_history'] as $table) {
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        }
    }
}
