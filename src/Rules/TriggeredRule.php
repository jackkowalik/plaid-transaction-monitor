<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules;

final class TriggeredRule
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $ruleType,
        public readonly string $ruleName,
        public readonly string $reason,
        public readonly Severity $severity,
        public readonly array $details,
        public readonly bool $emailAlert,
        public readonly bool $slackAlert
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id'     => $this->ruleId,
            'rule_type'   => $this->ruleType,
            'rule_name'   => $this->ruleName,
            'reason'      => $this->reason,
            'severity'    => $this->severity->value,
            'details'     => $this->details,
            'email_alert' => $this->emailAlert,
            'slack_alert' => $this->slackAlert,
        ];
    }
}
