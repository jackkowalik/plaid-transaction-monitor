<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Plaid;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use PlaidMonitor\Cache\PdoCache;
use PlaidMonitor\Plaid\PlaidClient;
use PlaidMonitor\Plaid\WebhookVerifier;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\FakeHttpClient;
use PlaidMonitor\Tests\Support\FrozenClock;
use Psr\Log\NullLogger;

class WebhookVerifierTest extends TestCase
{
    private const KID = 'test-key-1';

    private \OpenSSLAsymmetricKey $privateKey;
    private FakeHttpClient $http;
    private FrozenClock $clock;
    private WebhookVerifier $verifier;

    protected function setUp(): void
    {
        $key = $this->newEcKey();
        if ($key === null) {
            $this->markTestSkipped('OpenSSL cannot generate EC keys here; set OPENSSL_CONF to an openssl.cnf');
        }
        $this->privateKey = $key;
        $details = openssl_pkey_get_details($key);

        $this->http = new FakeHttpClient();
        $this->http->always('/webhook_verification_key/get', FakeHttpClient::json([
            'key' => [
                'alg'        => 'ES256',
                'crv'        => 'P-256',
                'kid'        => self::KID,
                'kty'        => 'EC',
                'use'        => 'sig',
                'x'          => rtrim(strtr(base64_encode($details['ec']['x']), '+/', '-_'), '='),
                'y'          => rtrim(strtr(base64_encode($details['ec']['y']), '+/', '-_'), '='),
                'created_at' => 1700000000,
                'expired_at' => null,
            ],
        ]));

        $this->clock = new FrozenClock('2026-10-01 12:00:00');
        $plaid = new PlaidClient($this->http, 'client', 'secret', 'sandbox', new NullLogger());
        $this->verifier = new WebhookVerifier($plaid, new PdoCache(Database::sqlite(), $this->clock), $this->clock, new NullLogger());
    }

    private function newEcKey(): ?\OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        return $key === false ? null : $key;
    }

    private function token(string $body, ?int $issuedAt = null, string $kid = self::KID): string
    {
        return JWT::encode([
            'iat'                 => $issuedAt ?? $this->clock->now()->getTimestamp(),
            'request_body_sha256' => hash('sha256', $body),
        ], $this->privateKey, 'ES256', $kid);
    }

    public function testValidWebhookPasses(): void
    {
        $body = '{"webhook_type":"TRANSACTIONS","webhook_code":"SYNC_UPDATES_AVAILABLE","item_id":"item_1"}';

        $this->assertTrue($this->verifier->verify($body, ['Plaid-Verification' => $this->token($body)]));
    }

    public function testTamperedBodyFails(): void
    {
        $body = '{"item_id":"item_1"}';

        $this->assertFalse($this->verifier->verify('{"item_id":"item_2"}', ['plaid-verification' => $this->token($body)]));
    }

    public function testOldTokenFails(): void
    {
        $body = '{"item_id":"item_1"}';
        $token = $this->token($body, $this->clock->now()->getTimestamp() - 301);

        $this->assertFalse($this->verifier->verify($body, ['plaid-verification' => $token]));
    }

    public function testMissingHeaderFails(): void
    {
        $this->assertFalse($this->verifier->verify('{}', []));
    }

    public function testTokenSignedByAnotherKeyFails(): void
    {
        $body = '{"item_id":"item_1"}';
        $otherKey = $this->newEcKey();
        $token = JWT::encode(['iat' => $this->clock->now()->getTimestamp(), 'request_body_sha256' => hash('sha256', $body)], $otherKey, 'ES256', self::KID);

        $this->assertFalse($this->verifier->verify($body, ['plaid-verification' => $token]));
    }

    public function testVerificationKeyIsCached(): void
    {
        $body = '{"item_id":"item_1"}';
        $this->verifier->verify($body, ['plaid-verification' => $this->token($body)]);
        $this->verifier->verify($body, ['plaid-verification' => $this->token($body)]);

        $this->assertCount(1, $this->http->requestsTo('/webhook_verification_key/get'));
    }
}
