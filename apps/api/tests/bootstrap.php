<?php

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../bootstrap/cache/config.php')) {
    fwrite(STDERR, "\nLa configuración está en caché y las pruebas irían contra tu base real. Corre primero: php artisan config:clear\n\n");
    exit(1);
}

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');

if (!str_ends_with($database, '_test')) {
    fwrite(STDERR, "\nLas pruebas solo corren en una base terminada en _test (se pidió \"$database\").\n\n");
    exit(1);
}

$psqlFolder = 'C:\\Program Files\\PostgreSQL\\17\\bin';

if (PHP_OS_FAMILY === 'Windows' && is_dir($psqlFolder) && !str_contains((string) getenv('PATH'), $psqlFolder)) {
    putenv('PATH=' . $psqlFolder . PATH_SEPARATOR . getenv('PATH'));
}

$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432';
$user = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'postgres';
$password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=postgres", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $exists = $pdo->query('SELECT 1 FROM pg_database WHERE datname = ' . $pdo->quote($database))->fetchColumn();

    if (!$exists) {
        $pdo->exec('CREATE DATABASE "' . str_replace('"', '', $database) . '"');
    }
} catch (PDOException $e) {
    fwrite(STDERR, "\nNo se pudo conectar a PostgreSQL en $host:$port. ¿Está abierto Docker con erp_postgres?\n" . $e->getMessage() . "\n\n");
    exit(1);
}
