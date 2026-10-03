<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

/**
 * Per-item locks with an owner token and a rerun flag.
 *
 * A worker that cannot take the lock sets the rerun flag instead of dropping
 * its work. The holder can only release while the flag is clear, so a webhook
 * that arrives mid-processing always causes one more pass.
 */
interface LockStore
{
    public function tryAcquire(string $itemId, string $token, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): bool;

    public function extend(string $itemId, string $token, \DateTimeImmutable $expiresAt): bool;

    /**
     * Ask the current holder to run again. False when no live lock exists.
     */
    public function requestRerun(string $itemId, \DateTimeImmutable $now): bool;

    /**
     * Clear the rerun flag. True when it was set.
     */
    public function consumeRerun(string $itemId, string $token): bool;

    /**
     * Release the lock if this token holds it and no rerun is pending.
     */
    public function release(string $itemId, string $token): bool;

    /**
     * Release regardless of the rerun flag.
     */
    public function forceRelease(string $itemId, string $token): void;

    public function deleteExpired(\DateTimeImmutable $now): int;
}
