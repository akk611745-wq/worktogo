<?php
/**
 * ================================================================
 *  WorkToGo — HEART SYSTEM v1.2.0
 *  heart/health.php
 *
 *  GET /heart/health.php         → quick liveness (no auth)
 *  GET /heart/health.php?deep=1  → full dependency check (admin key required)
 *
 *  v1.2.0: circuit breaker status included in deep check
 * ================================================================
 */

declare(strict_types=1);

define('HEART_ROOT',  __DIR__);
define('SYSTEM_ROOT', dirname(__DIR__));
define('HEART_START', microtime(true));

// Load .env
$envFile = SYSTEM_ROOT . '/config/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\n\r\"'");
        if (!empty($k) && getenv($k) === false) { putenv("{$k}={$v}"); $_ENV[$k] = $v; }
    }
}

require_once HEART_ROOT . '/config.php';
require_once HEART_ROOT . '/router.php';    // for HeartLogger, AppRouter, HEART_VERSION
require_once HEART_ROOT . '/reflex_engine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Heart-Version: ' . HEART_VERSION);

$deep = ($_GET['deep'] ?? '') === '1';

// Protect deep check always (even in dev — admin key required)
if ($deep) {
    $key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
    if (empty(HEART_ADMIN_KEY) || !hash_equals(HEART_ADMIN_KEY, $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'X-Admin-Key required for deep health check.']);
        exit;
    }
}

$report = [
    'status'        => 'ok',
    'heart_version' => HEART_VERSION,
    'env'           => HEART_ENV,
    'timestamp'     => date('Y-m-d H:i:s'),
    'timezone'      => date_default_timezone_get(),
    'php_version'   => PHP_VERSION,
];

if ($deep) {
    // Brain status
    $brainUrl        = BRAIN_API_URL;
    $report['brain'] = empty($brainUrl)
        ? ['ok' => null, 'status' => 'not_configured', 'note' => 'Set BRAIN_API_URL in .env to enable']
        : pingUrl($brainUrl . '/health');

    // Brain circuit breaker status
    $report['brain_circuit'] = [
        'open'      => function_exists('apcu_fetch') ? (bool)(apcu_fetch('heart_brain_cb_open') ?: false) : null,
        'threshold' => BRAIN_CIRCUIT_THRESHOLD,
        'window_s'  => BRAIN_CIRCUIT_WINDOW,
    ];

    // Engine statuses
    $report['engines'] = [];
    foreach (ENGINE_URLS as $name => $url) {
        $report['engines'][$name] = empty($url)
            ? ['ok' => null, 'status' => 'not_configured']
            : pingUrl($url . '/health');
    }

    // Cache driver
    $report['cache'] = detectCache();

    // Rate limiter
    $report['rate_limiter'] = [
        'available' => function_exists('apcu_fetch'),
        'ip_max'    => 200,
        'user_max'  => 300,
        'window_s'  => 60,
    ];

    // Routes summary
    $report['routes_count'] = count(AppRouter::all());

    // Overall status: degraded if any configured dependency is down
    $degraded = false;
    if ($brainUrl && isset($report['brain']['ok']) && $report['brain']['ok'] === false) {
        $degraded = true;
    }
    foreach ($report['engines'] as $e) {
        if (isset($e['ok']) && $e['ok'] === false) {
            $degraded = true;
        }
    }

    $report['status'] = $degraded ? 'degraded' : 'ok';
}

http_response_code($report['status'] === 'ok' ? 200 : 503);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);


function pingUrl(string $url): array
{
    $start = microtime(true);
    $ch    = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => ['X-Health-Check: 1'],
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($ch);
    curl_close($ch);
    $ms = round((microtime(true) - $start) * 1000, 1);
    $ok = ($errno === 0 && $httpCode >= 200 && $httpCode < 300);
    return ['ok' => $ok, 'http' => $httpCode, 'ms' => $ms];
}

function detectCache(): array
{
    if (extension_loaded('redis')) {
        return ['driver' => 'redis', 'available' => true];
    }
    if (function_exists('apcu_fetch')) {
        return ['driver' => 'apcu', 'available' => true];
    }
    return ['driver' => 'file', 'available' => true, 'note' => 'File cache — suitable for shared hosting'];
}
