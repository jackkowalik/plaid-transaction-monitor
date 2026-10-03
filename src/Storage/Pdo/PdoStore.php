<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage\Pdo;

use PDO;

/**
 * Shared helpers for the MySQL and SQLite implementations. Queries stick to
 * syntax both engines accept, and timestamps are bound as UTC strings
 * instead of using NOW() or engine-specific date functions.
 */
abstract class PdoStore
{
    public function __construct(protected readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function execute(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function fetchRow(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    protected function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->errorInfo[0] ?? $e->getCode()) === '23000';
    }

    /**
     * @param mixed $value
     */
    protected function encodeJson($value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<mixed>
     */
    protected function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
