<?php
declare(strict_types=1);

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
    fwrite(STDERR, "MYSQL_SERVER_NOT_AVAILABLE\n");
    fwrite(STDERR, $conn->connect_error . "\n");
    exit(10);
}

$dbName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($db['database'] ?? 'aio_local'));
$q = $conn->query(
    "SELECT COUNT(*) c
       FROM information_schema.schemata
      WHERE schema_name='" . $conn->real_escape_string($dbName) . "'"
);
if (!$q || (int)$q->fetch_assoc()['c'] === 0) {
    echo "DATABASE_MISSING\n";
    exit(20);
}

$q = $conn->query(
    "SELECT COUNT(*) c
       FROM information_schema.tables
      WHERE table_schema='" . $conn->real_escape_string($dbName) . "'
        AND table_name IN ('tenants','users','orders','inventory_items')"
);
if (!$q || (int)$q->fetch_assoc()['c'] < 4) {
    echo "DATABASE_SCHEMA_INCOMPLETE\n";
    exit(21);
}

if (!$conn->select_db($dbName)) {
    fwrite(STDERR, "DATABASE_SELECT_FAILED\n");
    exit(22);
}

$q = $conn->query("SELECT COUNT(*) c FROM tenants");
if (!$q) {
    echo "DATABASE_SCHEMA_INCOMPLETE\n";
    exit(21);
}

echo "EXISTING_DATABASE_READY\n";
