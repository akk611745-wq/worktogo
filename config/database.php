<?php

return [
    'host'     => getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? 'localhost',
    'port'     => getenv('DB_PORT') ?: $_ENV['DB_PORT'] ?? '3306',
    'database' => getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? '',
    'username' => getenv('DB_USER') ?: $_ENV['DB_USER'] ?? '',
    'password' => getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?? '',
    'charset'  => 'utf8mb4',
    'timezone' => getenv('DB_TIMEZONE') ?: $_ENV['DB_TIMEZONE'] ?? '+05:30',
];
