<?php
// migrate.php

// 1. Read .env file
$envFile = __DIR__ . '/.env';
$env = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $env[$name] = $value;
        }
    }
}

// 2. Check Authorization
$providedSecret = $_GET['secret'] ?? $argv[1] ?? '';
$envSecret = $env['MIGRATE_SECRET'] ?? null;

if (empty($envSecret) || $providedSecret !== $envSecret) {
    http_response_code(403);
    die(json_encode(["success" => false, "message" => "Error: Invalid or missing MIGRATE_SECRET."]));
}

// 3. Connect to Database
$dbHost = $env['DB_HOST'] ?? 'localhost';
$dbName = $env['DB_NAME'] ?? '';
$dbUser = $env['DB_USER'] ?? '';
$dbPass = $env['DB_PASS'] ?? '';
$dbPort = $env['DB_PORT'] ?? '3306';

try {
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 4. Create migrations_log table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_file VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 5. Get already applied migrations
    $stmt = $pdo->query("SELECT migration_file FROM migrations_log");
    $appliedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 6. Get available migrations from /migrations folder
    $migrationsDir = __DIR__ . '/migrations';
    if (!is_dir($migrationsDir)) {
        throw new Exception("Migrations directory not found.");
    }

    $files = scandir($migrationsDir);
    if ($files === false) {
        throw new Exception("Failed to read migrations directory.");
    }

    $migrationFiles = [];
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $migrationFiles[] = $file;
        }
    }
    sort($migrationFiles);

    // 7. Run pending migrations
    $appliedCount = 0;
    $log = [];
    foreach ($migrationFiles as $file) {
        if (!in_array($file, $appliedMigrations)) {
            $log[] = "Applying: $file...";
            
            $sql = file_get_contents($migrationsDir . '/' . $file);
            if ($sql === false) {
                throw new Exception("Failed to read migration file: $file");
            }
            
            $pdo->exec($sql);
            
            $stmt = $pdo->prepare("INSERT INTO migrations_log (migration_file) VALUES (?)");
            $stmt->execute([$file]);
            
            $log[] = "Successfully applied: $file";
            $appliedCount++;
        }
    }

    echo json_encode([
        "success" => true,
        "message" => $appliedCount === 0 ? "No pending migrations to apply." : "Successfully applied $appliedCount migration(s).",
        "logs" => $log
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Migration process failed.",
        "details" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
    exit;
}
