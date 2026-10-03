<?php
declare(strict_types=1);

namespace PlaidMonitor\Alerts;

use PlaidMonitor\Alerts\Sinks\EmailSink;
use PlaidMonitor\Alerts\Sinks\SlackSink;
use PlaidMonitor\Alerts\Sinks\WebhookSink;
use PlaidMonitor\Plaid\TransactionMapper;
use PlaidMonitor\Rules\EvaluationResult;
use PlaidMonitor\Rules\FlaggedTransaction;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Rules\TriggeredRule;
use PlaidMonitor\Storage\AlertStore;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\Item;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores alert records and delivers them.
 *
 * Fraud alerts:
 *   webhook - every flagged transaction, when a URL and signing secret are set
 *   email   - transactions with a triggered rule that has emailAlert set
 *   Slack   - transactions with a triggered rule that has slackAlert set
 *
 * Account errors go to every destination configured on the item's integrations.
 */
class AlertDispatcher
{
    public const EVENT_FRAUD = 'fraud_alert.detected';
    public const EVENT_ACCOUNT_ERROR = 'account.error';

    public function __construct(
        private readonly AlertStore $alerts,
        private readonly WebhookSink $webhooks,
        private readonly EmailSink $email,
        private readonly SlackSink $slack,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recordFraudAlert(Integration $integration, ?Item $item, EvaluationResult $result, string $source): FraudAlert
    {
        $severity = $result->severity();
        $totals = [];
        $transactions = [];

        foreach ($result->flagged as $flagged) {
            $tx = $flagged->transaction;
            $amount = abs((float) ($tx['amount'] ?? 0));
            $currency = (string) ($tx['iso_currency_code'] ?? 'USD');
            $totals[$currency] = round(($totals[$currency] ?? 0) + $amount, 2);

            $transactions[] = [
                'transaction_id'  => $tx['transaction_id'],
                'account_id'      => $tx['account_id'] ?? null,
                'amount'          => $amount,
                'currency'        => $currency,
                'merchant_name'   => TransactionMapper::merchant($tx),
                'category'        => TransactionMapper::category($tx),
                'date'            => $tx['date'] ?? null,
                'pending'         => (bool) ($tx['pending'] ?? false),
                'severity'        => $flagged->severity()->value,
                'triggered_rules' => array_map(static fn(TriggeredRule $rule) => [
                    'rule_id'     => $rule->ruleId,
                    'rule_name'   => $rule->ruleName,
                    'rule_type'   => $rule->ruleType,
                    'severity'    => $rule->severity->value,
                    'reason'      => $rule->reason,
                    'email_alert' => $rule->emailAlert,
                    'slack_alert' => $rule->slackAlert,
                ], $flagged->rules()),
            ];
        }

        $payload = [
            'integration_id'             => $integration->integrationId,
            'item_id'                    => $integration->itemId,
            'account_id'                 => $integration->accountId,
            'monitored_account_name'     => $integration->name,
            'institution_name'           => $item?->institutionName,
            'source'                     => $source,
            'severity'                   => $severity->value,
            'transactions_checked'       => $result->transactionsChecked,
            'total_flagged_transactions' => count($result->flagged),
            'total_flagged_amount'       => $totals,
            'flagged_transactions'       => $transactions,
        ];

        $alertId = $this->alerts->create(
            AlertStore::KIND_FRAUD,
            $integration->itemId,
            $integration->integrationId,
            $severity->value,
            $payload,
            $this->clock->now()
        );

        return new FraudAlert($alertId, ['alert_id' => $alertId] + $payload);
    }

    /**
     * @return array{webhook: bool, email: bool, slack: bool}
     */
    public function deliverFraudAlert(Integration $integration, ?Item $item, FraudAlert $alert, EvaluationResult $result): array
    {
        $label = $this->accountLabel($integration, $item);
        $sent = ['webhook' => false, 'email' => false, 'slack' => false];

        if ($integration->alertWebhookUrl && $integration->alertWebhookSecret) {
            $sent['webhook'] = $this->webhooks->send(
                $integration->alertWebhookUrl,
                $integration->alertWebhookSecret,
                self::EVENT_FRAUD,
                $alert->payload
            );
        }

        if ($integration->alertEmail && $result->wantsEmail()) {
            $forEmail = array_values(array_filter($result->flagged, static fn(FlaggedTransaction $f) => $f->wantsEmail()));
            $sent['email'] = $this->email->send(
                $integration->alertEmail,
                Messages::fraudEmailSubject($forEmail, $label),
                Messages::fraudEmailBody($forEmail, $label, $alert->alertId, static fn(TriggeredRule $r) => $r->emailAlert)
            );
        }

        if ($integration->slackWebhookUrl && $result->wantsSlack()) {
            $forSlack = array_values(array_filter($result->flagged, static fn(FlaggedTransaction $f) => $f->wantsSlack()));
            $sent['slack'] = $this->slack->send(
                $integration->slackWebhookUrl,
                Messages::fraudSlack($forSlack, $label, $alert->alertId)
            );
        }

        $this->logger->info('Fraud alert delivered', [
            'alert_id'       => $alert->alertId,
            'integration_id' => $integration->integrationId,
            'webhook'        => $sent['webhook'],
            'email'          => $sent['email'],
            'slack'          => $sent['slack'],
        ]);

        return $sent;
    }

    /**
     * Report a bank connection problem (PENDING_EXPIRATION, PENDING_DISCONNECT or ERROR).
     *
     * @param list<Integration> $integrations integrations on the item
     * @param array<string, mixed> $webhook the Plaid webhook body
     */
    public function accountError(string $itemId, ?Item $item, array $integrations, string $webhookCode, array $webhook): string
    {
        $data = $this->accountErrorData($itemId, $item, $integrations, $webhookCode, $webhook);
        $severity = $webhookCode === 'ERROR' ? Severity::High : Severity::Medium;

        $alertId = $this->alerts->create(
            AlertStore::KIND_ACCOUNT_ERROR,
            $itemId,
            null,
            $severity->value,
            $data,
            $this->clock->now()
        );
        $data = ['alert_id' => $alertId] + $data;

        $sentWebhooks = [];
        $sentEmails = [];
        $sentSlack = [];

        foreach ($integrations as $integration) {
            if ($integration->alertWebhookUrl && $integration->alertWebhookSecret
                && !isset($sentWebhooks[$integration->alertWebhookUrl])) {
                $sentWebhooks[$integration->alertWebhookUrl] = true;
                $this->webhooks->send($integration->alertWebhookUrl, $integration->alertWebhookSecret, self::EVENT_ACCOUNT_ERROR, $data);
            }

            if ($integration->alertEmail && !isset($sentEmails[strtolower($integration->alertEmail)])) {
                $sentEmails[strtolower($integration->alertEmail)] = true;
                $this->email->send(
                    $integration->alertEmail,
                    Messages::accountErrorSubject($webhookCode, $item?->institutionName ?? 'bank'),
                    Messages::accountErrorBody($data)
                );
            }

            if ($integration->slackWebhookUrl && !isset($sentSlack[$integration->slackWebhookUrl])) {
                $sentSlack[$integration->slackWebhookUrl] = true;
                $this->slack->send($integration->slackWebhookUrl, Messages::accountErrorSlack($data));
            }
        }

        $this->logger->info('Account error delivered', [
            'alert_id'     => $alertId,
            'item_id'      => $itemId,
            'webhook_code' => $webhookCode,
            'webhooks'     => count($sentWebhooks),
            'emails'       => count($sentEmails),
            'slack'        => count($sentSlack),
        ]);

        return $alertId;
    }

    /**
     * @param list<Integration> $integrations
     * @param array<string, mixed> $webhook
     * @return array<string, mixed>
     */
    private function accountErrorData(string $itemId, ?Item $item, array $integrations, string $webhookCode, array $webhook): array
    {
        $error = is_array($webhook['error'] ?? null) ? $webhook['error'] : [];

        [$errorType, $errorCode, $errorMessage, $displayMessage] = match ($webhookCode) {
            'PENDING_EXPIRATION' => [
                'ITEM_LOGIN_REQUIRED',
                'PENDING_EXPIRATION',
                'Consent for this connection is about to expire',
                'Your bank connection consent is expiring. Renew it to keep monitoring this account.',
            ],
            'PENDING_DISCONNECT' => [
                'ITEM_LOGIN_REQUIRED',
                'PENDING_DISCONNECT',
                'This connection will be disconnected soon',
                'Your bank connection is ending soon. Reconnect to keep monitoring this account.',
            ],
            default => [
                (string) ($error['error_type'] ?? 'ITEM_ERROR'),
                (string) ($error['error_code'] ?? 'UNKNOWN_ERROR'),
                (string) ($error['error_message'] ?? 'There is a problem with this bank connection'),
                (string) ($error['display_message'] ?? 'There is a problem with your bank connection. Update it to keep monitoring this account.'),
            ],
        };

        return [
            'item_id'                 => $itemId,
            'integration_ids'         => array_map(static fn(Integration $i) => $i->integrationId, $integrations),
            'institution_name'        => $item?->institutionName,
            'webhook_code'            => $webhookCode,
            'error_type'              => $errorType,
            'error_code'              => $errorCode,
            'error_message'           => $errorMessage,
            'display_message'         => $displayMessage,
            'consent_expiration_time' => $webhook['consent_expiration_time'] ?? null,
            'reason'                  => $webhook['reason'] ?? null,
        ];
    }

    private function accountLabel(Integration $integration, ?Item $item): string
    {
        $label = trim(($item?->institutionName ?? '') . ' ' . ($integration->name ?? ''));
        return $label !== '' ? $label : 'your monitored account';
    }
}
