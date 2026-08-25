<?php
declare(strict_types=1);

/**
 * First-start bootstrap:
 * - connects to MySQL without requiring aio_local to exist
 * - creates/imports schema if required
 * - imports base seed if required
 *
 * This script is idempotent at the database level by checking core tables/data first.
 */

$config = require dirname(__DIR__) . '/config/local.php';
$db = $config['db'];

mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli(
    $db['host'] ?? '127.0.0.1',
    $db['username'] ?? 'root',
    $db['password'] ?? '',
    '',
    (int)($db['port'] ?? 3306)
);

if ($conn->connect_errno) {
    fwrite(STDERR, "MYSQL_CONNECTION_FAILED\n");
    fwrite(STDERR, $conn->connect_error . "\n");
    exit(10);
}

$conn->set_charset('utf8mb4');

function runSqlFile(mysqli $conn, string $file): void {
    if (!is_file($file)) {
        throw new RuntimeException("Missing SQL file: $file");
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Unable to read SQL file: $file");
    }

    if (!$conn->multi_query($sql)) {
        throw new RuntimeException("SQL import failed: " . $conn->error);
    }

    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
        if (!$conn->more_results()) {
            break;
        }
        if (!$conn->next_result()) {
            throw new RuntimeException("SQL import failed: " . $conn->error);
        }
    } while (true);
}

$dbName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$db['database']);
if ($dbName === '') {
    throw new RuntimeException('Invalid local database name.');
}

$exists = $conn->query(
    "SELECT COUNT(*) c FROM information_schema.tables
     WHERE table_schema='" . $conn->real_escape_string($dbName) . "'
       AND table_name='tenants'"
);
$schemaReady = $exists && (int)$exists->fetch_assoc()['c'] > 0;

if (!$schemaReady) {
    echo "Creating local database schema...\n";
    runSqlFile($conn, dirname(__DIR__) . '/docs/02_local_mysql_schema.sql');
    echo "Database schema created.\n";
}

if (!$conn->select_db($dbName)) {
    throw new RuntimeException("Cannot select local database: " . $conn->error);
}

$tenantId = (string)$config['app']['tenant_id'];
$q = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE id=?");
$q->bind_param('s', $tenantId);
$q->execute();
$q->bind_result($tenantCount);
$q->fetch();
$q->close();

if ((int)$tenantCount === 0) {
    echo "Loading restaurant base data...\n";
    runSqlFile($conn, dirname(__DIR__) . '/docs/03_seed_restaurant_base.sql');
    echo "Restaurant base data loaded.\n";
}

echo "DATABASE_READY\n";

// build: V17.1 build 2026-08-25
