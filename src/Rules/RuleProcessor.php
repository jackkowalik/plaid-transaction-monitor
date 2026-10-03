<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules;

use PlaidMonitor\Storage\Integration;

interface RuleProcessor
{
    /**
     * The rule "type" value this processor handles, e.g. "amount_threshold".
     */
    public function type(): string;

    /**
     * @param list<array<string, mixed>> $transactions new and modified transactions from this run
     * @param array<string, mixed> $config the rule's "config" object
     * @return list<Flag>
     */
    public function evaluate(Integration $integration, array $transactions, array $config): array;
}
