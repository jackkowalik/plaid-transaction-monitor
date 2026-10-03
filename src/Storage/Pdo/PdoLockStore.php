<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage\Pdo;

use PlaidMonitor\Storage\LockStore;
use PlaidMonitor\Time\Time;

class PdoLockStore extends PdoStore implements LockStore
{
    public function tryAcquire(string $itemId, string $token, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): bool
    {
        $params = [
            'item_id'    => $itemId,
            'token'      => $token,
            'locked_at'  => Time::format($now),
            'expires_at' => Time::format($expiresAt),
        ];

        try {
            $this->execute(
                'INSERT INTO item_locks (item_id, token, locked_at, expires_at, rerun_requested)
                 VALUES (:item_id, :token, :locked_at, :expires_at, 0)',
                $params
            );
            return true;
        } catch (\PDOException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }

        // Take over an expired lock. The expiry condition and the row count
        // make sure only one of several racing workers wins.
        $params['now'] = Time::format($now);
        $taken = $this->execute(
            'UPDATE item_locks
             SET token = :token, locked_at = :locked_at, expires_at = :expires_at, rerun_requested = 0
             WHERE item_id = :item_id AND expires_at <= :now',
            $params
        )->rowCount();

        return $taken === 1;
    }

    public function extend(string $itemId, string $token, \DateTimeImmutable $expiresAt): bool
    {
        $this->execute(
            'UPDATE item_locks SET expires_at = :expires_at WHERE item_id = :item_id AND token = :token',
            ['expires_at' => Time::format($expiresAt), 'item_id' => $itemId, 'token' => $token]
        );

        // MySQL counts unchanged rows as unaffected, so a second extend within
        // the same second would report 0. Check ownership instead.
        return $this->fetchRow(
            'SELECT 1 AS found FROM item_locks WHERE item_id = :item_id AND token = :token',
            ['item_id' => $itemId, 'token' => $token]
        ) !== null;
    }

    public function requestRerun(string $itemId, \DateTimeImmutable $now): bool
    {
        $this->execute(
            'UPDATE item_locks SET rerun_requested = 1 WHERE item_id = :item_id AND expires_at > :now',
            ['item_id' => $itemId, 'now' => Time::format($now)]
        );

        // MySQL reports zero affected rows when the flag was already set, so
        // check for a live lock instead of trusting the row count.
        return $this->fetchRow(
            'SELECT 1 AS found FROM item_locks WHERE item_id = :item_id AND expires_at > :now AND rerun_requested = 1',
            ['item_id' => $itemId, 'now' => Time::format($now)]
        ) !== null;
    }

    public function consumeRerun(string $itemId, string $token): bool
    {
        return $this->execute(
            'UPDATE item_locks SET rerun_requested = 0
             WHERE item_id = :item_id AND token = :token AND rerun_requested = 1',
            ['item_id' => $itemId, 'token' => $token]
        )->rowCount() === 1;
    }

    public function release(string $itemId, string $token): bool
    {
        $this->execute(
            'DELETE FROM item_locks WHERE item_id = :item_id AND token = :token AND rerun_requested = 0',
            ['item_id' => $itemId, 'token' => $token]
        );

        // Released, or someone else owns it now. Either way this token no
        // longer holds a lock with a pending rerun.
        return $this->fetchRow(
            'SELECT 1 AS found FROM item_locks WHERE item_id = :item_id AND token = :token',
            ['item_id' => $itemId, 'token' => $token]
        ) === null;
    }

    public function forceRelease(string $itemId, string $token): void
    {
        $this->execute(
            'DELETE FROM item_locks WHERE item_id = :item_id AND token = :token',
            ['item_id' => $itemId, 'token' => $token]
        );
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return $this->execute(
            'DELETE FROM item_locks WHERE expires_at <= :now',
            ['now' => Time::format($now)]
        )->rowCount();
    }
}
