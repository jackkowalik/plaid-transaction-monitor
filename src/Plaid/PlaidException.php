<?php
declare(strict_types=1);

namespace PlaidMonitor\Plaid;

class PlaidException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorType = null,
        public readonly int $httpStatus = 0,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isMutationDuringPagination(): bool
    {
        return $this->errorCode === 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION';
    }
}
