<?php
declare(strict_types=1);

namespace PlaidMonitor\Plaid;

/**
 * Sandbox-only endpoints, used by the bin/ scripts and the sandbox tests.
 */
class SandboxClient
{
    public function __construct(private readonly PlaidClient $plaid)
    {
        if ($plaid->environment() !== 'sandbox') {
            throw new \LogicException('SandboxClient can only be used with PLAID_ENV=sandbox');
        }
    }

    /**
     * Create an item without going through Link. Use the
     * user_transactions_dynamic username so createTransactions() works.
     *
     * @param list<string> $products
     */
    public function createPublicToken(
        string $institutionId = 'ins_109508',
        array $products = ['transactions'],
        string $overrideUsername = 'user_transactions_dynamic',
        ?string $webhook = null
    ): string {
        $options = ['override_username' => $overrideUsername];
        if ($webhook !== null) {
            $options['webhook'] = $webhook;
        }

        $data = $this->plaid->post('/sandbox/public_token/create', [
            'institution_id'   => $institutionId,
            'initial_products' => $products,
            'options'          => $options,
        ]);

        return (string) $data['public_token'];
    }

    /**
     * Add up to 10 transactions to a user_transactions_dynamic item. Dates
     * must be today or up to 14 days in the past.
     *
     * @param list<array{date_transacted: string, date_posted: string, amount: float, description: string, iso_currency_code?: string}> $transactions
     */
    public function createTransactions(string $accessToken, array $transactions): void
    {
        if ($transactions === [] || count($transactions) > 10) {
            throw new \InvalidArgumentException('Between 1 and 10 transactions can be created at a time');
        }

        $this->plaid->post('/sandbox/transactions/create', [
            'access_token' => $accessToken,
            'transactions' => $transactions,
        ]);
    }

    public function fireWebhook(string $accessToken, string $webhookCode, string $webhookType = 'TRANSACTIONS'): void
    {
        $this->plaid->post('/sandbox/item/fire_webhook', [
            'access_token' => $accessToken,
            'webhook_code' => $webhookCode,
            'webhook_type' => $webhookType,
        ]);
    }
}
