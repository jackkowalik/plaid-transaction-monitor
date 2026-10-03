<?php
declare(strict_types=1);

namespace PlaidMonitor\Alerts;

use PlaidMonitor\Money;
use PlaidMonitor\Plaid\TransactionMapper;
use PlaidMonitor\Rules\FlaggedTransaction;
use PlaidMonitor\Rules\TriggeredRule;

/**
 * Plain-text bodies for email and Slack.
 */
final class Messages
{
    /**
     * @param list<FlaggedTransaction> $flagged
     */
    public static function fraudEmailSubject(array $flagged, string $accountLabel): string
    {
        $count = count($flagged);
        return sprintf(
            'Alert: %d flagged %s on %s',
            $count,
            $count === 1 ? 'transaction' : 'transactions',
            $accountLabel
        );
    }

    /**
     * @param list<FlaggedTransaction> $flagged
     * @param callable(TriggeredRule): bool $includeRule
     */
    public static function fraudEmailBody(array $flagged, string $accountLabel, string $alertId, callable $includeRule): string
    {
        $count = count($flagged);
        $lines = [
            sprintf(
                '%d %s on %s matched your monitoring rules.',
                $count,
                $count === 1 ? 'transaction' : 'transactions',
                $accountLabel
            ),
            '',
        ];

        foreach ($flagged as $index => $item) {
            $tx = $item->transaction;
            $lines[] = sprintf('Transaction %d', $index + 1);
            $lines[] = '  Merchant: ' . TransactionMapper::merchant($tx);
            $lines[] = '  Amount:   ' . Money::format(abs((float) ($tx['amount'] ?? 0)), (string) ($tx['iso_currency_code'] ?? 'USD'));
            $lines[] = '  Date:     ' . ($tx['date'] ?? 'unknown');
            $lines[] = '  Rules:';
            foreach ($item->rules() as $rule) {
                if (!$includeRule($rule)) {
                    continue;
                }
                $lines[] = sprintf('    - %s [%s]: %s', $rule->ruleName, strtoupper($rule->severity->value), $rule->reason);
            }
            $lines[] = '';
        }

        $lines[] = 'Review these transactions. If you do not recognize one, contact your bank.';
        $lines[] = '';
        $lines[] = 'Alert ID: ' . $alertId;

        return implode("\n", $lines);
    }

    /**
     * @param list<FlaggedTransaction> $flagged
     */
    public static function fraudSlack(array $flagged, string $accountLabel, string $alertId): string
    {
        $count = count($flagged);
        $lines = [
            sprintf(
                '*Transaction alert*: %d flagged %s on %s',
                $count,
                $count === 1 ? 'transaction' : 'transactions',
                self::slackEscape($accountLabel)
            ),
            '',
        ];

        foreach ($flagged as $item) {
            $tx = $item->transaction;
            $lines[] = sprintf(
                '*%s* - %s - %s',
                self::slackEscape(TransactionMapper::merchant($tx)),
                Money::format(abs((float) ($tx['amount'] ?? 0)), (string) ($tx['iso_currency_code'] ?? 'USD')),
                $tx['date'] ?? 'unknown date'
            );
            foreach ($item->rules() as $rule) {
                if ($rule->slackAlert) {
                    $lines[] = sprintf('  - %s: %s', self::slackEscape($rule->ruleName), self::slackEscape($rule->reason));
                }
            }
        }

        $lines[] = '';
        $lines[] = '_Alert ID: ' . $alertId . '_';

        return implode("\n", $lines);
    }

    public static function accountErrorSubject(string $webhookCode, string $institution): string
    {
        return match ($webhookCode) {
            'PENDING_EXPIRATION' => "Action required: {$institution} connection consent is expiring",
            'PENDING_DISCONNECT' => "Action required: {$institution} connection is ending soon",
            default => "Action required: problem with the {$institution} connection",
        };
    }

    /**
     * @param array<string, mixed> $error the account.error webhook data
     */
    public static function accountErrorBody(array $error): string
    {
        $lines = [
            (string) $error['display_message'],
            '',
            'Institution: ' . ($error['institution_name'] ?? 'unknown'),
            'Item ID:     ' . $error['item_id'],
            'Error code:  ' . $error['error_code'],
        ];

        if (!empty($error['consent_expiration_time'])) {
            $lines[] = 'Expires:     ' . $error['consent_expiration_time'];
        }

        $lines[] = '';
        $lines[] = 'Monitoring for this connection stops until it is repaired through Plaid Link update mode.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $error
     */
    public static function accountErrorSlack(array $error): string
    {
        return sprintf(
            "*Bank connection problem* (%s): %s\n_%s_",
            self::slackEscape((string) ($error['institution_name'] ?? 'unknown institution')),
            self::slackEscape((string) $error['display_message']),
            self::slackEscape((string) $error['error_code'])
        );
    }

    /**
     * Slack reads <...> as links and mentions, so a merchant name like
     * "<!channel>" would ping the whole channel.
     */
    private static function slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
