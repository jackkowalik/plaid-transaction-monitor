<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage\Pdo;

use PlaidMonitor\Storage\Job;
use PlaidMonitor\Storage\JobStore;
use PlaidMonitor\Time\Time;

class PdoJobStore extends PdoStore implements JobStore
{
    private const CLAIM_CANDIDATES = 5;

    public function enqueue(string $itemId, string $webhookType, string $webhookCode, array $payload, \DateTimeImmutable $now): void
    {
        $this->execute(
            'INSERT INTO webhook_jobs (item_id, webhook_type, webhook_code, payload, status, attempts, available_at, created_at)
             VALUES (:item_id, :webhook_type, :webhook_code, :payload, :status, 0, :available_at, :created_at)',
            [
                'item_id'      => $itemId,
                'webhook_type' => $webhookType,
                'webhook_code' => $webhookCode,
                'payload'      => $this->encodeJson($payload),
                'status'       => 'pending',
                'available_at' => Time::format($now),
                'created_at'   => Time::format($now),
            ]
        );
    }

    public function claim(\DateTimeImmutable $now, \DateTimeImmutable $claimUntil): ?Job
    {
        $nowString = Time::format($now);
        $limit = self::CLAIM_CANDIDATES;

        $candidates = $this->fetchAll(
            "SELECT id FROM webhook_jobs
             WHERE (status = 'pending' AND available_at <= :now)
                OR (status = 'processing' AND claimed_until <= :now_claimed)
             ORDER BY id ASC
             LIMIT {$limit}",
            ['now' => $nowString, 'now_claimed' => $nowString]
        );

        foreach ($candidates as $candidate) {
            // Claim with a conditional update so two workers cannot take the
            // same job; the loser moves on to the next candidate.
            $claimed = $this->execute(
                "UPDATE webhook_jobs
                 SET status = 'processing', claimed_until = :claimed_until, attempts = attempts + 1
                 WHERE id = :id
                   AND ((status = 'pending' AND available_at <= :now)
                     OR (status = 'processing' AND claimed_until <= :now_claimed))",
                [
                    'claimed_until' => Time::format($claimUntil),
                    'id'            => $candidate['id'],
                    'now'           => $nowString,
                    'now_claimed'   => $nowString,
                ]
            )->rowCount();

            if ($claimed !== 1) {
                continue;
            }

            $row = $this->fetchRow(
                'SELECT id, item_id, webhook_type, webhook_code, payload, attempts FROM webhook_jobs WHERE id = :id',
                ['id' => $candidate['id']]
            );
            if ($row === null) {
                continue;
            }

            return new Job(
                (int) $row['id'],
                $row['item_id'],
                $row['webhook_type'],
                $row['webhook_code'],
                $this->decodeJson($row['payload']),
                (int) $row['attempts']
            );
        }

        return null;
    }

    public function complete(int $jobId): void
    {
        $this->execute('DELETE FROM webhook_jobs WHERE id = :id', ['id' => $jobId]);
    }

    public function fail(int $jobId, string $error, ?\DateTimeImmutable $retryAt): void
    {
        $this->execute(
            'UPDATE webhook_jobs
             SET status = :status, available_at = COALESCE(:retry_at, available_at), claimed_until = NULL, last_error = :error
             WHERE id = :id',
            [
                'status'   => $retryAt !== null ? 'pending' : 'failed',
                'retry_at' => $retryAt !== null ? Time::format($retryAt) : null,
                'error'    => mb_substr($error, 0, 2000),
                'id'       => $jobId,
            ]
        );
    }
}
