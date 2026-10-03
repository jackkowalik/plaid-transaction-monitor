<?php
declare(strict_types=1);

namespace PlaidMonitor\Cache;

use PDO;
use PlaidMonitor\Time\Time;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache in the cache_entries table. Used for geocoding results and
 * Plaid webhook verification keys; swap in any PSR-16 implementation.
 */
class PdoCache implements CacheInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $stmt = $this->pdo->prepare('SELECT value, expires_at FROM cache_entries WHERE cache_key = :cache_key');
        $stmt->execute(['cache_key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return $default;
        }

        if ($row['expires_at'] !== null && $row['expires_at'] <= Time::format($this->clock->now())) {
            $this->delete($key);
            return $default;
        }

        return unserialize($row['value'], ['allowed_classes' => false]);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $expiresAt = $this->expiry($ttl);
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            return $this->delete($key);
        }

        $params = [
            'cache_key'  => $key,
            'value'      => serialize($value),
            'expires_at' => $expiresAt ? Time::format($expiresAt) : null,
        ];

        $this->pdo->prepare('DELETE FROM cache_entries WHERE cache_key = :cache_key')->execute(['cache_key' => $key]);
        try {
            $this->pdo->prepare(
                'INSERT INTO cache_entries (cache_key, value, expires_at) VALUES (:cache_key, :value, :expires_at)'
            )->execute($params);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '23000') {
                throw $e;
            }
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->pdo->prepare('DELETE FROM cache_entries WHERE cache_key = :cache_key')->execute(['cache_key' => $key]);
        return true;
    }

    public function clear(): bool
    {
        $this->pdo->exec('DELETE FROM cache_entries');
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    private function expiry(null|int|\DateInterval $ttl): ?\DateTimeImmutable
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            return $this->clock->now()->add($ttl);
        }
        return $this->clock->now()->modify("+{$ttl} seconds");
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > 191 || preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new InvalidCacheKey("Invalid cache key '{$key}'");
        }
    }
}
