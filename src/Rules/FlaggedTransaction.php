<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules;

/**
 * A transaction together with every rule it triggered in one run.
 */
final class FlaggedTransaction
{
    /** @var list<TriggeredRule> */
    private array $rules = [];

    /**
     * @param array<string, mixed> $transaction
     */
    public function __construct(public readonly array $transaction)
    {
    }

    public function add(TriggeredRule $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * @return list<TriggeredRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function severity(): Severity
    {
        return Severity::highest(...array_map(static fn(TriggeredRule $r) => $r->severity, $this->rules));
    }

    public function wantsEmail(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->emailAlert) {
                return true;
            }
        }
        return false;
    }

    public function wantsSlack(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->slackAlert) {
                return true;
            }
        }
        return false;
    }
}
