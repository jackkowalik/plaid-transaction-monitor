<?php
declare(strict_types=1);

namespace PlaidMonitor;

use PDO;
use PlaidMonitor\Alerts\AlertDispatcher;
use PlaidMonitor\Alerts\Sinks\EmailSink;
use PlaidMonitor\Alerts\Sinks\SlackSink;
use PlaidMonitor\Alerts\Sinks\WebhookSink;
use PlaidMonitor\Cache\PdoCache;
use PlaidMonitor\Http\CurlHttpClient;
use PlaidMonitor\Http\HttpClient;
use PlaidMonitor\Mail\Mailer;
use PlaidMonitor\Mail\SmtpMailer;
use PlaidMonitor\Plaid\PlaidClient;
use PlaidMonitor\Plaid\SandboxClient;
use PlaidMonitor\Plaid\WebhookVerifier;
use PlaidMonitor\Rules\Geocoding\CachingGeocoder;
use PlaidMonitor\Rules\Geocoding\GoogleGeocoder;
use PlaidMonitor\Rules\Processors\AmountThreshold;
use PlaidMonitor\Rules\Processors\DuplicateDetection;
use PlaidMonitor\Rules\Processors\Geolocation;
use PlaidMonitor\Rules\Processors\NewMerchant;
use PlaidMonitor\Rules\Processors\TransactionCategory;
use PlaidMonitor\Rules\Processors\Velocity;
use PlaidMonitor\Rules\RuleEngine;
use PlaidMonitor\Scheduler\Scheduler;
use PlaidMonitor\Scheduler\TimingPolicy;
use PlaidMonitor\Storage\Pdo\PdoAlertStore;
use PlaidMonitor\Storage\Pdo\PdoIntegrationStore;
use PlaidMonitor\Storage\Pdo\PdoJobStore;
use PlaidMonitor\Storage\Pdo\PdoLockStore;
use PlaidMonitor\Storage\Pdo\PdoMerchantHistoryStore;
use PlaidMonitor\Storage\Pdo\PdoRefreshRequestStore;
use PlaidMonitor\Storage\Pdo\PdoTransactionStore;
use PlaidMonitor\Storage\SodiumTokenCipher;
use PlaidMonitor\Sync\Deduplicator;
use PlaidMonitor\Sync\TransactionSyncer;
use PlaidMonitor\Time\SystemClock;
use PlaidMonitor\Webhook\ItemEventHandler;
use PlaidMonitor\Webhook\ItemLock;
use PlaidMonitor\Webhook\SyncHandler;
use PlaidMonitor\Webhook\WebhookReceiver;
use PlaidMonitor\Webhook\Worker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Wires the default PDO-backed implementation from Config. Pass your own
 * PDO, HTTP client, clock, cache, mailer or eligibility policy to replace
 * the defaults.
 */
final class App
{
    private PDO $pdo;
    private HttpClient $http;
    private ClockInterface $clock;
    private CacheInterface $cache;
    private EligibilityPolicy $eligibility;
    private ?Mailer $mailer;
    private ?PlaidClient $plaid = null;
    private ?PdoIntegrationStore $integrations = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        ?PDO $pdo = null,
        ?HttpClient $http = null,
        ?ClockInterface $clock = null,
        ?CacheInterface $cache = null,
        ?EligibilityPolicy $eligibility = null,
        ?Mailer $mailer = null
    ) {
        $this->pdo = $pdo ?? self::connect($config);
        $this->http = $http ?? new CurlHttpClient();
        $this->clock = $clock ?? new SystemClock();
        $this->cache = $cache ?? new PdoCache($this->pdo, $this->clock);
        $this->eligibility = $eligibility ?? new AlwaysEligible();
        $this->mailer = $mailer ?? $this->defaultMailer();
    }

    public static function connect(Config $config): PDO
    {
        $pdo = new PDO($config->dbDsn(), $config->dbUser(), $config->dbPass(), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // Wait for the scheduler, worker and webhook endpoint instead of
            // failing with "database is locked".
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function plaid(): PlaidClient
    {
        if ($this->plaid === null) {
            $this->config->requirePlaidCredentials();
            $this->plaid = new PlaidClient(
                $this->http,
                $this->config->plaidClientId(),
                $this->config->plaidSecret(),
                $this->config->plaidEnv(),
                $this->logger
            );
        }
        return $this->plaid;
    }

    public function sandbox(): SandboxClient
    {
        return new SandboxClient($this->plaid());
    }

    public function integrations(): PdoIntegrationStore
    {
        if ($this->integrations === null) {
            $key = $this->config->accessTokenKey();
            $this->integrations = new PdoIntegrationStore($this->pdo, $key !== null ? new SodiumTokenCipher($key) : null);
        }
        return $this->integrations;
    }

    public function timing(): TimingPolicy
    {
        return new TimingPolicy(
            $this->config->paidRefreshEnabled(),
            $this->config->paidRefreshCutoffMinutes(),
            $this->config->shortThreshold(),
            $this->config->autoThreshold()
        );
    }

    public function scheduler(): Scheduler
    {
        return new Scheduler(
            $this->integrations(),
            new PdoRefreshRequestStore($this->pdo),
            new PdoLockStore($this->pdo),
            $this->plaid(),
            $this->timing(),
            $this->eligibility,
            $this->clock,
            $this->logger
        );
    }

    public function receiver(): WebhookReceiver
    {
        $verifier = $this->config->verifyPlaidWebhooks()
            ? new WebhookVerifier($this->plaid(), $this->cache, $this->clock, $this->logger)
            : null;

        return new WebhookReceiver($verifier, new PdoJobStore($this->pdo), $this->clock, $this->logger);
    }

    public function worker(): Worker
    {
        $transactions = new PdoTransactionStore($this->pdo);
        $merchants = new PdoMerchantHistoryStore($this->pdo);
        $refreshRequests = new PdoRefreshRequestStore($this->pdo);
        $alerts = $this->alertDispatcher();

        $processor = new Processor(
            $this->integrations(),
            $refreshRequests,
            $transactions,
            new TransactionSyncer($this->plaid(), $this->logger),
            new Deduplicator($transactions, $merchants),
            $this->ruleEngine($transactions, $merchants),
            $alerts,
            $this->clock,
            $this->logger
        );

        $lockTtl = $this->config->lockTtlSeconds();

        return new Worker(
            new PdoJobStore($this->pdo),
            new SyncHandler(
                $this->integrations(),
                $refreshRequests,
                $this->timing(),
                $this->eligibility,
                $processor,
                $this->clock,
                $this->logger
            ),
            new ItemEventHandler($this->integrations(), $alerts, $this->logger),
            new ItemLock(new PdoLockStore($this->pdo), $this->clock, $lockTtl, $this->logger),
            $this->clock,
            $this->logger,
            $this->config->jobMaxAttempts(),
            $lockTtl + 60
        );
    }

    private function alertDispatcher(): AlertDispatcher
    {
        return new AlertDispatcher(
            new PdoAlertStore($this->pdo),
            new WebhookSink($this->http, $this->clock, $this->logger),
            new EmailSink($this->mailer, $this->logger),
            new SlackSink($this->http, $this->logger),
            $this->clock,
            $this->logger
        );
    }

    private function ruleEngine(PdoTransactionStore $transactions, PdoMerchantHistoryStore $merchants): RuleEngine
    {
        $apiKey = $this->config->googleGeocodingApiKey();
        $geocoder = $apiKey !== null
            ? new CachingGeocoder(new GoogleGeocoder($this->http, $apiKey, $this->logger), $this->cache)
            : null;

        return new RuleEngine([
            new AmountThreshold(),
            new NewMerchant($merchants, $this->clock),
            new TransactionCategory(),
            new Velocity($transactions, $this->clock),
            new Geolocation($geocoder, $this->logger),
            new DuplicateDetection($transactions, $this->clock),
        ], $this->logger);
    }

    private function defaultMailer(): ?Mailer
    {
        $host = $this->config->smtpHost();
        $from = $this->config->alertFromAddress();
        if ($host === null || $from === null) {
            return null;
        }

        return new SmtpMailer(
            $host,
            $this->config->smtpPort(),
            $this->config->smtpUser(),
            $this->config->smtpPass(),
            $this->config->smtpEncryption(),
            $from,
            $this->config->alertFromName()
        );
    }
}
