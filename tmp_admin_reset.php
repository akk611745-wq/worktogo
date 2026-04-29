<?php

declare(strict_types=1);

/**
 * One-time script to:
 * 1) Show admin users with hash prefix
 * 2) Reset admin password hash
 */

function loadEnvFile(string $path): array
{
    $env = [];
    if (!file_exists($path)) {
        return $env;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $env[$name] = $value;
    }

    return $env;
}

try {
    $env = loadEnvFile(__DIR__ . '/.env');

    $host = $env['DB_HOST'] ?? 'localhost';
    $port = $env['DB_PORT'] ?? '3306';
    $name = $env['DB_NAME'] ?? '';
    $user = $env['DB_USER'] ?? '';
    $pass = $env['DB_PASS'] ?? '';

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database configuration missing in .env (DB_NAME / DB_USER).');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $selectSql = "SELECT id, email, role, status, LEFT(password,7) as hash_check FROM users WHERE role = 'admin'";
    $selectStmt = $pdo->query($selectSql);
    $admins = $selectStmt->fetchAll();

    $updateSql = "UPDATE users SET password = :hash WHERE role = 'admin'";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        ':hash' => '$2y$10$TKh8H1.PJy3GJx8l.BE3eOSzKH7jIqJNfBv3j4aEE7wKd9pJJKM2',
    ]);

    echo json_encode([
        'success' => true,
        'select_sql' => $selectSql,
        'select_result' => $admins,
        'update_sql' => "UPDATE users SET password = '***hidden***' WHERE role = 'admin'",
        'update_affected_rows' => $updateStmt->rowCount(),
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
    exit(1);
}

