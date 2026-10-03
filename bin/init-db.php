<?php
declare(strict_types=1);

// Creates the tables for the configured DB_DSN, using schema/mysql.sql or
// schema/sqlite.sql depending on the driver. Safe to run more than once.

require __DIR__ . '/bootstrap.php';

$app = bootstrapCli();
$pdo = $app->pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$file = match ($driver) {
    'mysql' => __DIR__ . '/../schema/mysql.sql',
    'sqlite' => __DIR__ . '/../schema/sqlite.sql',
    default => null,
};

if ($file === null) {
    fwrite(STDERR, "No schema for PDO driver '{$driver}'\n");
    exit(1);
}

$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
$statements = array_filter(array_map('trim', explode(';', (string) $sql)));

foreach ($statements as $statement) {
    $pdo->exec($statement);
}

echo 'Applied ' . count($statements) . " statements from schema/{$driver}.sql\n";
