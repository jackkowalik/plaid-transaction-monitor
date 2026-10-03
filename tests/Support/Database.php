<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Support;

use PDO;

final class Database
{
    public static function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/../../schema/sqlite.sql'));
        foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) {
            $pdo->exec($statement);
        }

        return $pdo;
    }
}
