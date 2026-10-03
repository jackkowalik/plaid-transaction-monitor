<?php
declare(strict_types=1);

namespace PlaidMonitor\Plaid;

/**
 * Converts Plaid transaction objects into the array shape used by the
 * rules engine, the alert payloads and storage.
 */
final class TransactionMapper
{
    /**
     * @param array<string, mixed> $txn
     * @return array<string, mixed>
     */
    public static function fromPlaid(array $txn): array
    {
        $formatted = [
            'transaction_id'         => (string) $txn['transaction_id'],
            'account_id'             => (string) $txn['account_id'],
            'amount'                 => (float) $txn['amount'],
            'iso_currency_code'      => $txn['iso_currency_code'] ?? $txn['unofficial_currency_code'] ?? 'USD',
            'date'                   => (string) $txn['date'],
            'authorized_date'        => $txn['authorized_date'] ?? null,
            'name'                   => (string) ($txn['name'] ?? ''),
            'merchant_name'          => $txn['merchant_name'] ?? null,
            'pending'                => (bool) ($txn['pending'] ?? false),
            'pending_transaction_id' => $txn['pending_transaction_id'] ?? null,
            'payment_channel'        => $txn['payment_channel'] ?? null,
            'transaction_type'       => $txn['transaction_type'] ?? null,
        ];

        if (isset($txn['personal_finance_category']) && is_array($txn['personal_finance_category'])) {
            $formatted['category'] = [
                'primary'    => $txn['personal_finance_category']['primary'] ?? null,
                'detailed'   => $txn['personal_finance_category']['detailed'] ?? null,
                'confidence' => $txn['personal_finance_category']['confidence_level'] ?? null,
            ];
        }

        if (isset($txn['location']) && is_array($txn['location'])) {
            $formatted['location'] = [
                'address'     => $txn['location']['address'] ?? null,
                'city'        => $txn['location']['city'] ?? null,
                'region'      => $txn['location']['region'] ?? null,
                'postal_code' => $txn['location']['postal_code'] ?? null,
                'country'     => $txn['location']['country'] ?? null,
                'lat'         => $txn['location']['lat'] ?? null,
                'lon'         => $txn['location']['lon'] ?? null,
            ];
        }

        if (!empty($txn['counterparties']) && is_array($txn['counterparties'])) {
            $formatted['counterparties'] = array_map(static fn(array $cp) => [
                'name'     => $cp['name'] ?? null,
                'type'     => $cp['type'] ?? null,
                'logo_url' => $cp['logo_url'] ?? null,
                'website'  => $cp['website'] ?? null,
            ], $txn['counterparties']);
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $transaction
     */
    public static function merchant(array $transaction): string
    {
        $merchant = $transaction['merchant_name'] ?? null;
        if ($merchant === null || $merchant === '') {
            $merchant = $transaction['name'] ?? '';
        }
        return $merchant !== '' ? (string) $merchant : 'Unknown';
    }

    /**
     * Most specific personal finance category available.
     *
     * @param array<string, mixed> $transaction
     */
    public static function category(array $transaction): ?string
    {
        return $transaction['category']['detailed'] ?? $transaction['category']['primary'] ?? null;
    }
}
