<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules;

final class EvaluationResult
{
    /**
     * @param list<FlaggedTransaction> $flagged
     */
    public function __construct(
        public readonly array $flagged,
        public readonly int $transactionsChecked
    ) {
    }

    public function hasFlags(): bool
    {
        return $this->flagged !== [];
    }

    public function severity(): Severity
    {
        return Severity::highest(...array_map(static fn(FlaggedTransaction $f) => $f->severity(), $this->flagged));
    }

    public function wantsEmail(): bool
    {
        foreach ($this->flagged as $flagged) {
            if ($flagged->wantsEmail()) {
                return true;
            }
        }
        return false;
    }

    public function wantsSlack(): bool
    {
        foreach ($this->flagged as $flagged) {
            if ($flagged->wantsSlack()) {
                return true;
            }
        }
        return false;
    }
}
