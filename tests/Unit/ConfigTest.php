<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Config;

class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new Config();

        $this->assertFalse($config->paidRefreshEnabled());
        $this->assertSame(1440, $config->paidRefreshCutoffMinutes());
        $this->assertSame(0.9, $config->shortThreshold());
        $this->assertSame(0.85, $config->autoThreshold());
        $this->assertSame(300, $config->lockTtlSeconds());
        $this->assertTrue($config->verifyPlaidWebhooks());
        $this->assertNull($config->googleGeocodingApiKey());
    }

    public function testRejectsUnknownPlaidEnvironment(): void
    {
        $this->expectException(\RuntimeException::class);
        new Config(['PLAID_ENV' => 'development']);
    }

    public function testRejectsThresholdsOutsideZeroToOne(): void
    {
        $this->expectException(\RuntimeException::class);
        new Config(['MONITOR_SHORT_THRESHOLD' => '1.5']);
    }

    public function testWebhookVerificationCannotBeDisabledInProduction(): void
    {
        $this->expectException(\RuntimeException::class);
        new Config(['PLAID_ENV' => 'production', 'PLAID_VERIFY_WEBHOOKS' => 'false']);
    }

    public function testAccessTokenKeyMustBe32Bytes(): void
    {
        $this->assertSame(32, strlen((string) (new Config(['ACCESS_TOKEN_KEY' => base64_encode(random_bytes(32))]))->accessTokenKey()));

        $this->expectException(\RuntimeException::class);
        new Config(['ACCESS_TOKEN_KEY' => base64_encode('too short')]);
    }

    public function testRequiresPlaidCredentialsOnlyWhenAsked(): void
    {
        $config = new Config();

        $this->expectException(\RuntimeException::class);
        $config->requirePlaidCredentials();
    }
}
