<?php
$db = null;
try {
    require_once __DIR__ . '/config/database.php';
    if (function_exists('getDB')) {
        $db = getDB();
    }
} catch (Exception $e) {
    echo "DB Connect Error: " . $e->getMessage() . "\n";
}

$tables = [];
if ($db) {
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables found: " . count($tables) . "\n";
    echo implode(", ", $tables) . "\n";
} else {
    echo "DB is null\n";
}

// Check PHP syntax errors
$output = [];
exec("php -l heart/index.php", $output);
exec("php -l heart/api/auth/router.php", $output);
exec("php -l heart/api/auth/AuthController.php", $output);
echo "Syntax check:\n" . implode("\n", $output) . "\n";

// Check missing files
$files = [
    'heart/router.php',
    'heart/api/auth/register.php',
    'heart/api/auth/login.php',
    'heart/api/auth/logout.php',
    'heart/api/auth/me.php',
    'heart/api/auth/refresh.php',
    'heart/api/auth/send-otp.php',
    'heart/api/auth/verify-otp.php',
    'heart/api/auth/AuthController.php',
    'heart/api/admin/index.php',
    'heart/api/delivery/index.php',
    'heart/api/search/index.php',
    'system/deploy-hook.php',
    'system/migrate.php'
];

foreach ($files as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        echo "Missing file: $file\n";
    }
}
