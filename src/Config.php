<?php
declare(strict_types=1);

namespace PlaidMonitor;

use Dotenv\Dotenv;

class Config
{
    private const DEFAULTS = [
        'PLAID_CLIENT_ID'                     => '',
        'PLAID_SECRET'                        => '',
        'PLAID_ENV'                           => 'sandbox',
        'PLAID_WEBHOOK_URL'                   => '',
        'PLAID_VERIFY_WEBHOOKS'               => 'true',
        'DB_DSN'                              => 'sqlite:./monitor.db',
        'DB_USER'                             => '',
        'DB_PASS'                             => '',
        'ACCESS_TOKEN_KEY'                    => '',
        'MONITOR_PAID_REFRESH_ENABLED'        => 'false',
        'MONITOR_PAID_REFRESH_CUTOFF_MINUTES' => '1440',
        'MONITOR_SHORT_THRESHOLD'             => '0.9',
        'MONITOR_AUTO_THRESHOLD'              => '0.85',
        'MONITOR_LOCK_TTL_SECONDS'            => '300',
        'MONITOR_JOB_MAX_ATTEMPTS'            => '5',
        'GOOGLE_GEOCODING_API_KEY'            => '',
        'SMTP_HOST'                           => '',
        'SMTP_PORT'                           => '587',
        'SMTP_USER'                           => '',
        'SMTP_PASS'                           => '',
        'SMTP_ENCRYPTION'                     => 'tls',
        'ALERT_FROM_ADDRESS'                  => '',
        'ALERT_FROM_NAME'                     => 'Transaction Monitor',
        'LOG_LEVEL'                           => 'info',
    ];

    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = array_merge(self::DEFAULTS, $values);
        $this->validate();
    }

    public static function load(string $projectRoot): self
    {
        Dotenv::createImmutable($projectRoot)->safeLoad();

        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $value = self::env($key);
            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        $values['DB_DSN'] = self::resolveSqlitePath($values['DB_DSN'] ?? self::DEFAULTS['DB_DSN'], $projectRoot);

        return new self($values);
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === '') {
            return null;
        }
        return (string) $value;
    }

    private static function resolveSqlitePath(string $dsn, string $root): string
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return $dsn;
        }

        $path = substr($dsn, 7);
        if ($path === ':memory:' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $dsn;
        }
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return 'sqlite:' . rtrim($root, '/\\') . '/' . $path;
    }

    private function validate(): void
    {
        if (!in_array($this->plaidEnv(), ['sandbox', 'production'], true)) {
            throw new \RuntimeException(
                "Invalid PLAID_ENV value '{$this->values['PLAID_ENV']}': expected 'sandbox' or 'production'"
            );
        }

        if ($this->plaidEnv() === 'production' && !$this->verifyPlaidWebhooks()) {
            throw new \RuntimeException('PLAID_VERIFY_WEBHOOKS cannot be turned off with PLAID_ENV=production');
        }

        if ($this->values['ACCESS_TOKEN_KEY'] !== '' && $this->accessTokenKey() === null) {
            throw new \RuntimeException('ACCESS_TOKEN_KEY must be 32 bytes, base64 encoded');
        }

        foreach (['MONITOR_SHORT_THRESHOLD', 'MONITOR_AUTO_THRESHOLD'] as $key) {
            $value = (float) $this->values[$key];
            if ($value <= 0 || $value > 1) {
                throw new \RuntimeException("{$key} must be greater than 0 and at most 1");
            }
        }

        foreach (['MONITOR_PAID_REFRESH_CUTOFF_MINUTES', 'MONITOR_LOCK_TTL_SECONDS', 'MONITOR_JOB_MAX_ATTEMPTS'] as $key) {
            if ((int) $this->values[$key] < 1) {
                throw new \RuntimeException("{$key} must be a positive integer");
            }
        }
    }

    public function requirePlaidCredentials(): void
    {
        $missing = [];
        foreach (['PLAID_CLIENT_ID', 'PLAID_SECRET'] as $key) {
            if ($this->values[$key] === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException(implode(', ', $missing) . ' must be set in the environment or .env');
        }
    }

    public function plaidClientId(): string
    {
        return $this->values['PLAID_CLIENT_ID'];
    }

    public function plaidSecret(): string
    {
        return $this->values['PLAID_SECRET'];
    }

    public function plaidEnv(): string
    {
        return strtolower($this->values['PLAID_ENV']);
    }

    public function plaidWebhookUrl(): ?string
    {
        return $this->values['PLAID_WEBHOOK_URL'] !== '' ? $this->values['PLAID_WEBHOOK_URL'] : null;
    }

    public function verifyPlaidWebhooks(): bool
    {
        return $this->bool('PLAID_VERIFY_WEBHOOKS');
    }

    public function dbDsn(): string
    {
        return $this->values['DB_DSN'];
    }

    public function dbUser(): ?string
    {
        return $this->values['DB_USER'] !== '' ? $this->values['DB_USER'] : null;
    }

    public function dbPass(): ?string
    {
        return $this->values['DB_PASS'] !== '' ? $this->values['DB_PASS'] : null;
    }

    /**
     * Raw 32-byte key for encrypting access tokens, or null when not configured.
     */
    public function accessTokenKey(): ?string
    {
        if ($this->values['ACCESS_TOKEN_KEY'] === '') {
            return null;
        }
        $key = base64_decode($this->values['ACCESS_TOKEN_KEY'], true);
        return $key !== false && strlen($key) === 32 ? $key : null;
    }

    public function paidRefreshEnabled(): bool
    {
        return $this->bool('MONITOR_PAID_REFRESH_ENABLED');
    }

    public function paidRefreshCutoffMinutes(): int
    {
        return (int) $this->values['MONITOR_PAID_REFRESH_CUTOFF_MINUTES'];
    }

    public function shortThreshold(): float
    {
        return (float) $this->values['MONITOR_SHORT_THRESHOLD'];
    }

    public function autoThreshold(): float
    {
        return (float) $this->values['MONITOR_AUTO_THRESHOLD'];
    }

    public function lockTtlSeconds(): int
    {
        return (int) $this->values['MONITOR_LOCK_TTL_SECONDS'];
    }

    public function jobMaxAttempts(): int
    {
        return (int) $this->values['MONITOR_JOB_MAX_ATTEMPTS'];
    }

    public function googleGeocodingApiKey(): ?string
    {
        return $this->values['GOOGLE_GEOCODING_API_KEY'] !== '' ? $this->values['GOOGLE_GEOCODING_API_KEY'] : null;
    }

    public function smtpHost(): ?string
    {
        return $this->values['SMTP_HOST'] !== '' ? $this->values['SMTP_HOST'] : null;
    }

    public function smtpPort(): int
    {
        return (int) $this->values['SMTP_PORT'];
    }

    public function smtpUser(): string
    {
        return $this->values['SMTP_USER'];
    }

    public function smtpPass(): string
    {
        return $this->values['SMTP_PASS'];
    }

    public function smtpEncryption(): string
    {
        return strtolower($this->values['SMTP_ENCRYPTION']);
    }

    public function alertFromAddress(): ?string
    {
        return $this->values['ALERT_FROM_ADDRESS'] !== '' ? $this->values['ALERT_FROM_ADDRESS'] : null;
    }

    public function alertFromName(): string
    {
        return $this->values['ALERT_FROM_NAME'];
    }

    public function logLevel(): string
    {
        return strtolower($this->values['LOG_LEVEL']);
    }

    private function bool(string $key): bool
    {
        return filter_var($this->values[$key], FILTER_VALIDATE_BOOL);
    }
}
