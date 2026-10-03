<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Alerts;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Alerts\Sinks\WebhookSink;
use PlaidMonitor\Http\HttpException;
use PlaidMonitor\Http\HttpResponse;
use PlaidMonitor\Tests\Support\FakeHttpClient;
use PlaidMonitor\Tests\Support\FrozenClock;
use Psr\Log\NullLogger;

class WebhookSignerTest extends TestCase
{
    /**
     * The receiver snippet from the README, as a function.
     */
    private function readmeVerify(string $raw, string $received, string $signingSecret, int $now): bool
    {
        $payload = json_decode($raw, true);

        $expected = hash_hmac('sha256', $payload['timestamp'] . '.' . $raw, $signingSecret);

        if (!hash_equals($expected, $received)) {
            return false;
        }

        return abs($now - strtotime($payload['timestamp'])) <= 300;
    }

    public function testDeliveriesPassTheReadmeVerification(): void
    {
        $http = new FakeHttpClient();
        $http->always('/hook', new HttpResponse(204, ''));
        $clock = new FrozenClock('2026-10-03 14:00:00');
        $sink = new WebhookSink($http, $clock, new NullLogger(), [], static function (int $s): void {});

        $this->assertTrue($sink->send('https://example.com/hook', 'whsec_abc', 'fraud_alert.detected', ['url' => 'https://a/b']));

        $request = $http->requests[0];
        $this->assertTrue($this->readmeVerify(
            $request['body'],
            $request['headers']['X-Webhook-Signature'],
            'whsec_abc',
            $clock->now()->getTimestamp()
        ));
        $this->assertFalse($this->readmeVerify($request['body'], $request['headers']['X-Webhook-Signature'], 'wrong', $clock->now()->getTimestamp()));
    }

    public function testRetriesAfterEachDelay(): void
    {
        $http = new FakeHttpClient();
        $http->queue('/hook', new HttpResponse(500, ''))
            ->queue('/hook', new HttpException('timeout'))
            ->queue('/hook', new HttpResponse(200, ''));
        $slept = [];
        $sink = new WebhookSink($http, new FrozenClock(), new NullLogger(), [5, 10, 15], function (int $s) use (&$slept): void {
            $slept[] = $s;
        });

        $this->assertTrue($sink->send('https://example.com/hook', 'secret', 'account.error', []));
        $this->assertSame([5, 10], $slept);
    }

    public function testGivesUpAfterTheLastRetry(): void
    {
        $http = new FakeHttpClient();
        $http->always('/hook', new HttpResponse(503, ''));
        $sink = new WebhookSink($http, new FrozenClock(), new NullLogger(), [5, 10, 15], static function (int $s): void {});

        $this->assertFalse($sink->send('https://example.com/hook', 'secret', 'account.error', []));
        $this->assertCount(4, $http->requests);
    }
}
