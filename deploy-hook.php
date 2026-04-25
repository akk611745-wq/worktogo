<?php
// deploy-hook.php

// 1. Read .env file for MIGRATE_SECRET
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
$providedSecret = $_GET['secret'] ?? '';
$envSecret = $env['MIGRATE_SECRET'] ?? null;

if (empty($envSecret) || $providedSecret !== $envSecret) {
    http_response_code(403);
    die(json_encode(["success" => false, "message" => "Error: Invalid or missing MIGRATE_SECRET."]));
}

// 3. Run migrate.php
$_GET['secret'] = $providedSecret;
include __DIR__ . '/migrate.php';
