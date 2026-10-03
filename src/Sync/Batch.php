<?php
declare(strict_types=1);

namespace PlaidMonitor\Sync;

/**
 * The transactions from one sync, sorted into what needs evaluating and
 * what only needs storing.
 */
final class Batch
{
    /**
     * @param list<array<string, mixed>> $new          not seen before; evaluated and stored
     * @param list<array<string, mixed>> $modified     already stored and changed; evaluated and stored
     * @param list<array<string, mixed>> $storeOnly    posted versions of pending transactions already evaluated
     * @param list<string>               $removed      stored transaction IDs Plaid removed
     * @param int                        $alreadyProcessed added transactions skipped as duplicates
     */
    public function __construct(
        public readonly array $new,
        public readonly array $modified,
        public readonly array $storeOnly,
        public readonly array $removed,
        public readonly int $alreadyProcessed
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toEvaluate(): array
    {
        return array_merge($this->new, $this->modified);
    }

    public function isEmpty(): bool
    {
        return $this->new === [] && $this->modified === [] && $this->storeOnly === [] && $this->removed === [];
    }
}
