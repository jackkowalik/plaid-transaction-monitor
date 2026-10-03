<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Support;

use PlaidMonitor\Storage\Integration;

final class Fixtures
{
    /**
     * A normalized transaction, as TransactionMapper produces.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function transaction(array $overrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        return array_merge([
            'transaction_id'         => 'txn_' . $sequence,
            'account_id'             => 'acc_1',
            'amount'                 => 25.00,
            'iso_currency_code'      => 'USD',
            'date'                   => '2026-10-01',
            'authorized_date'        => null,
            'name'                   => 'Coffee Shop',
            'merchant_name'          => 'Coffee Shop',
            'pending'                => false,
            'pending_transaction_id' => null,
            'payment_channel'        => 'in store',
            'transaction_type'       => null,
            'category'               => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_COFFEE', 'confidence' => 'HIGH'],
        ], $overrides);
    }

    /**
     * A raw Plaid transaction object, as /transactions/sync returns.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function plaidTransaction(array $overrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        return array_merge([
            'transaction_id'            => 'plaid_txn_' . $sequence,
            'account_id'                => 'acc_1',
            'amount'                    => 25.00,
            'iso_currency_code'         => 'USD',
            'date'                      => '2026-10-01',
            'name'                      => 'Coffee Shop',
            'merchant_name'             => 'Coffee Shop',
            'pending'                   => false,
            'pending_transaction_id'    => null,
            'payment_channel'           => 'in store',
            'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_COFFEE', 'confidence_level' => 'HIGH'],
            'location'                  => ['address' => null, 'city' => null, 'region' => null, 'postal_code' => null, 'country' => null, 'lat' => null, 'lon' => null],
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    public static function integration(
        int $intervalMinutes = 60,
        array $rules = [],
        ?string $cursor = null,
        ?string $lastStartedAt = null,
        string $integrationId = 'int_1',
        string $itemId = 'item_1',
        ?string $accountId = null
    ): Integration {
        return new Integration(
            integrationId: $integrationId,
            itemId: $itemId,
            accountId: $accountId,
            intervalMinutes: $intervalMinutes,
            rules: $rules,
            name: 'Checking',
            alertWebhookUrl: 'https://alerts.example.com/hook',
            alertWebhookSecret: 'whsec_test',
            alertEmail: 'owner@example.com',
            slackWebhookUrl: 'https://hooks.slack.com/services/T000/B000/XXXX',
            cursor: $cursor,
            lastProcessingStartedAt: $lastStartedAt !== null ? new \DateTimeImmutable($lastStartedAt, new \DateTimeZone('UTC')) : null,
            baselineComplete: $cursor !== null,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function rule(string $type, array $config = [], bool $email = false, bool $slack = false, ?string $id = null): array
    {
        return [
            'id'         => $id ?? $type,
            'type'       => $type,
            'name'       => ucwords(str_replace('_', ' ', $type)),
            'enabled'    => true,
            'emailAlert' => $email,
            'slackAlert' => $slack,
            'config'     => $config,
        ];
    }
}
