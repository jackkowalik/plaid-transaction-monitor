<?php
declare(strict_types=1);

namespace PlaidMonitor\Plaid;

use PlaidMonitor\Http\HttpClient;
use PlaidMonitor\Http\HttpException;
use Psr\Log\LoggerInterface;

class PlaidClient
{
    private const BASE_URLS = [
        'sandbox'    => 'https://sandbox.plaid.com',
        'production' => 'https://production.plaid.com',
    ];

    private const MAX_SYNC_COUNT = 500;

    private string $baseUrl;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $clientId,
        private readonly string $secret,
        private readonly string $environment,
        private readonly LoggerInterface $logger
    ) {
        if (!isset(self::BASE_URLS[$environment])) {
            throw new \InvalidArgumentException("Unknown Plaid environment '{$environment}'");
        }
        $this->baseUrl = self::BASE_URLS[$environment];
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /**
     * Fetch one page of /transactions/sync.
     */
    public function syncTransactions(string $accessToken, ?string $cursor, int $count = self::MAX_SYNC_COUNT): SyncPage
    {
        $body = [
            'access_token' => $accessToken,
            'count'        => min(max($count, 1), self::MAX_SYNC_COUNT),
        ];
        if ($cursor !== null && $cursor !== '') {
            $body['cursor'] = $cursor;
        }

        $data = $this->post('/transactions/sync', $body);

        return new SyncPage(
            array_map([TransactionMapper::class, 'fromPlaid'], $data['added'] ?? []),
            array_map([TransactionMapper::class, 'fromPlaid'], $data['modified'] ?? []),
            array_values(array_map(
                static fn(array $removed) => (string) $removed['transaction_id'],
                $data['removed'] ?? []
            )),
            (string) ($data['next_cursor'] ?? ''),
            (bool) ($data['has_more'] ?? false),
            isset($data['transactions_update_status']) ? (string) $data['transactions_update_status'] : null
        );
    }

    /**
     * Ask Plaid to check the institution for new transactions now. This is a
     * paid add-on. Results arrive later as a SYNC_UPDATES_AVAILABLE webhook,
     * and only if something changed.
     */
    public function refreshTransactions(string $accessToken): void
    {
        $this->post('/transactions/refresh', ['access_token' => $accessToken]);
    }

    /**
     * @param array{
     *     client_user_id: string,
     *     client_name: string,
     *     products?: list<string>,
     *     country_codes?: list<string>,
     *     language?: string,
     *     redirect_uri?: string,
     *     webhook?: string,
     *     days_requested?: int
     * } $options
     * @return array{link_token: string, expiration: ?string}
     */
    public function createLinkToken(array $options): array
    {
        $products = $options['products'] ?? ['transactions'];

        $body = [
            'client_name'   => $options['client_name'],
            'user'          => ['client_user_id' => $options['client_user_id']],
            'products'      => $products,
            'country_codes' => $options['country_codes'] ?? ['US'],
            'language'      => $options['language'] ?? 'en',
        ];
        if (!empty($options['webhook'])) {
            $body['webhook'] = $options['webhook'];
        }
        if (!empty($options['redirect_uri'])) {
            $body['redirect_uri'] = $options['redirect_uri'];
        }
        if (in_array('transactions', $products, true)) {
            $body['transactions'] = ['days_requested' => $options['days_requested'] ?? 90];
        }

        $data = $this->post('/link/token/create', $body);

        return [
            'link_token' => (string) $data['link_token'],
            'expiration' => $data['expiration'] ?? null,
        ];
    }

    /**
     * Link token for update mode, used to repair an item after
     * PENDING_EXPIRATION, PENDING_DISCONNECT or ITEM_LOGIN_REQUIRED.
     *
     * @param list<string> $countryCodes
     * @return array{link_token: string, expiration: ?string}
     */
    public function createUpdateLinkToken(
        string $accessToken,
        string $clientUserId,
        string $clientName,
        array $countryCodes = ['US'],
        string $language = 'en',
        ?string $webhook = null
    ): array {
        $body = [
            'access_token'  => $accessToken,
            'client_name'   => $clientName,
            'user'          => ['client_user_id' => $clientUserId],
            'country_codes' => $countryCodes,
            'language'      => $language,
        ];
        if ($webhook !== null) {
            $body['webhook'] = $webhook;
        }

        $data = $this->post('/link/token/create', $body);

        return [
            'link_token' => (string) $data['link_token'],
            'expiration' => $data['expiration'] ?? null,
        ];
    }

    /**
     * @return array{access_token: string, item_id: string}
     */
    public function exchangePublicToken(string $publicToken): array
    {
        $data = $this->post('/item/public_token/exchange', ['public_token' => $publicToken]);

        if (empty($data['access_token']) || empty($data['item_id'])) {
            throw new PlaidException('Invalid response from /item/public_token/exchange');
        }

        return [
            'access_token' => (string) $data['access_token'],
            'item_id'      => (string) $data['item_id'],
        ];
    }

    public function removeItem(string $accessToken): void
    {
        $this->post('/item/remove', ['access_token' => $accessToken]);
    }

    /**
     * Public JWK used to verify the Plaid-Verification header on webhooks.
     *
     * @return array<string, mixed>
     */
    public function getWebhookVerificationKey(string $keyId): array
    {
        $data = $this->post('/webhook_verification_key/get', ['key_id' => $keyId]);

        if (!isset($data['key']) || !is_array($data['key'])) {
            throw new PlaidException('Invalid response from /webhook_verification_key/get');
        }

        return $data['key'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        $body['client_id'] = $this->clientId;
        $body['secret'] = $this->secret;

        try {
            $response = $this->http->request(
                'POST',
                $this->baseUrl . $path,
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                json_encode($body, JSON_THROW_ON_ERROR),
                60
            );
        } catch (HttpException $e) {
            throw new PlaidException("Failed to reach Plaid for {$path}: {$e->getMessage()}", null, null, 0, null, $e);
        }

        $data = $response->json();

        if (!$response->isSuccess()) {
            $exception = new PlaidException(
                (string) ($data['error_message'] ?? "Plaid request to {$path} failed"),
                $data['error_code'] ?? null,
                $data['error_type'] ?? null,
                $response->status,
                $data['request_id'] ?? null
            );

            $this->logger->warning('Plaid request failed', [
                'path'        => $path,
                'status'      => $response->status,
                'error_code'  => $exception->errorCode,
                'error_type'  => $exception->errorType,
                'request_id'  => $exception->requestId,
            ]);

            throw $exception;
        }

        if ($data === null) {
            throw new PlaidException("Invalid JSON from Plaid for {$path}", null, null, $response->status);
        }

        return $data;
    }
}
