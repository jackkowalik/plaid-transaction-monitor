<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Webhook;

use PDO;
use PHPUnit\Framework\TestCase;
use PlaidMonitor\Alerts\AlertDispatcher;
use PlaidMonitor\Alerts\Sinks\EmailSink;
use PlaidMonitor\Alerts\Sinks\SlackSink;
use PlaidMonitor\Alerts\Sinks\WebhookSink;
use PlaidMonitor\Storage\Item;
use PlaidMonitor\Storage\Pdo\PdoAlertStore;
use PlaidMonitor\Storage\Pdo\PdoIntegrationStore;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\FakeHttpClient;
use PlaidMonitor\Tests\Support\Fixtures;
use PlaidMonitor\Tests\Support\FrozenClock;
use PlaidMonitor\Tests\Support\RecordingMailer;
use PlaidMonitor\Webhook\ItemEventHandler;
use Psr\Log\NullLogger;

class ItemEventHandlerTest extends TestCase
{
    private PDO $pdo;
    private PdoIntegrationStore $integrations;
    private FakeHttpClient $http;
    private RecordingMailer $mailer;
    private ItemEventHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = Database::sqlite();
        $this->integrations = new PdoIntegrationStore($this->pdo);
        $this->http = new FakeHttpClient();
        $this->http->always('/hook', FakeHttpClient::json([]));
        $this->http->always('/services/T000/B000/XXXX', FakeHttpClient::json([]));
        $this->mailer = new RecordingMailer();
        $clock = new FrozenClock();
        $logger = new NullLogger();

        $this->handler = new ItemEventHandler(
            $this->integrations,
            new AlertDispatcher(
                new PdoAlertStore($this->pdo),
                new WebhookSink($this->http, $clock, $logger, [], static function (int $s): void {}),
                new EmailSink($this->mailer, $logger),
                new SlackSink($this->http, $logger),
                $clock,
                $logger
            ),
            $logger
        );

        $this->integrations->saveItem(new Item('item_1', 'access-1', 'Test Bank'));
    }

    public function testConnectionProblemsAreDeliveredOncePerDestination(): void
    {
        // Two integrations on the same item sharing the same destinations.
        $this->integrations->saveIntegration(Fixtures::integration(integrationId: 'a', accountId: 'acc_1'));
        $this->integrations->saveIntegration(Fixtures::integration(integrationId: 'b', accountId: 'acc_2'));

        $this->handler->handle('item_1', 'PENDING_EXPIRATION', [
            'webhook_type'            => 'ITEM',
            'webhook_code'            => 'PENDING_EXPIRATION',
            'item_id'                 => 'item_1',
            'consent_expiration_time' => '2026-10-15T00:00:00Z',
        ]);

        $webhooks = $this->http->requestsTo('/hook');
        $this->assertCount(1, $webhooks);
        $this->assertSame('account.error', $webhooks[0]['json']['event']);
        $this->assertSame('PENDING_EXPIRATION', $webhooks[0]['json']['data']['error_code']);
        $this->assertSame(['a', 'b'], $webhooks[0]['json']['data']['integration_ids']);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertStringContainsString('2026-10-15T00:00:00Z', $this->mailer->sent[0]['body']);
        $this->assertCount(1, $this->http->requestsTo('/services/T000/B000/XXXX'));
        $this->assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM alerts WHERE kind = 'account_error'")->fetchColumn());
    }

    public function testItemErrorsCarryPlaidsErrorDetails(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration());

        $this->handler->handle('item_1', 'ERROR', [
            'error' => ['error_type' => 'ITEM_ERROR', 'error_code' => 'ITEM_LOGIN_REQUIRED', 'error_message' => 'login', 'display_message' => 'Please log in again'],
        ]);

        $data = $this->http->requestsTo('/hook')[0]['json']['data'];
        $this->assertSame('ITEM_LOGIN_REQUIRED', $data['error_code']);
        $this->assertSame('Please log in again', $data['display_message']);
    }

    public function testPermissionRevokedRemovesTheItem(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration());

        $this->handler->handle('item_1', 'USER_PERMISSION_REVOKED', []);

        $this->assertNull($this->integrations->findItem('item_1'));
        $this->assertNull($this->integrations->find('int_1'));
    }

    public function testAccountRevokedRemovesOnlyThatAccountsIntegrations(): void
    {
        $this->integrations->saveIntegration(Fixtures::integration(integrationId: 'a', accountId: 'acc_1'));
        $this->integrations->saveIntegration(Fixtures::integration(integrationId: 'b', accountId: 'acc_2'));

        $this->handler->handle('item_1', 'USER_ACCOUNT_REVOKED', ['account_id' => 'acc_1']);

        $this->assertNull($this->integrations->find('a'));
        $this->assertNotNull($this->integrations->find('b'));
        $this->assertNotNull($this->integrations->findItem('item_1'));
    }
}
