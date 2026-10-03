<?php
declare(strict_types=1);

namespace PlaidMonitor\Webhook;

use PlaidMonitor\Storage\LockStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Serializes processing per Plaid item.
 *
 * If the item is already locked, the caller sets the rerun flag and returns;
 * the holder will run the work again before releasing, so the webhook is not
 * lost. The lock is extended before each pass, and the work gets a heartbeat
 * callback to extend it during long passes.
 */
class ItemLock
{
    public function __construct(
        private readonly LockStore $locks,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds,
        private readonly LoggerInterface $logger,
        private readonly int $maxPasses = 5
    ) {
    }

    /**
     * @param callable(callable(): void): void $work receives the heartbeat callback
     * @return bool true if this call ran the work, false if it was handed to the current holder
     */
    public function run(string $itemId, callable $work): bool
    {
        $token = bin2hex(random_bytes(16));

        if (!$this->acquire($itemId, $token)) {
            $this->logger->info('Item is being processed; queued a rerun for the current holder', ['item_id' => $itemId]);
            return false;
        }

        $heartbeat = function () use ($itemId, $token): void {
            if (!$this->locks->extend($itemId, $token, $this->expiry())) {
                throw new \RuntimeException("Lost the lock on item {$itemId}");
            }
        };

        try {
            for ($pass = 1; ; $pass++) {
                $heartbeat();

                $work($heartbeat);

                if ($this->locks->release($itemId, $token)) {
                    return true;
                }

                if ($pass >= $this->maxPasses) {
                    $this->logger->warning('Rerun limit reached; releasing the item lock', [
                        'item_id' => $itemId,
                        'passes'  => $pass,
                    ]);
                    $this->locks->forceRelease($itemId, $token);
                    return true;
                }

                $this->locks->consumeRerun($itemId, $token);
                $this->logger->info('Webhook arrived during processing; running again', ['item_id' => $itemId, 'pass' => $pass + 1]);
            }
        } catch (\Throwable $e) {
            $this->locks->forceRelease($itemId, $token);
            throw $e;
        }
    }

    private function acquire(string $itemId, string $token): bool
    {
        // Two rounds cover the case where the holder releases between our
        // failed acquire and our rerun request.
        for ($round = 0; $round < 2; $round++) {
            $now = $this->clock->now();
            if ($this->locks->tryAcquire($itemId, $token, $now, $this->expiry())) {
                return true;
            }
            if ($this->locks->requestRerun($itemId, $now)) {
                return false;
            }
        }

        throw new \RuntimeException("Could not lock item {$itemId} or hand off to its holder");
    }

    private function expiry(): \DateTimeImmutable
    {
        return $this->clock->now()->modify("+{$this->ttlSeconds} seconds");
    }
}
