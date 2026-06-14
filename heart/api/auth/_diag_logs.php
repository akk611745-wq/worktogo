<?php
// ============================================================
//  TEMPORARY DIAGNOSTIC — DELETE AFTER USE
//  GET /heart/api/auth/_diag_logs?key=<ADMIN_KEY>
//  Returns today's log lines matching MSG91_RESPONSE or OTP.
// ============================================================

// Load env manually (bootstrap not available here)
$_envFile = realpath(__DIR__ . '/../../../.env');
if ($_envFile && file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || str_starts_with($_line, '#') || !str_contains($_line, '=')) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k);
        $_v = trim($_v, " \t\n\r\0\x0B\"'");
        if (!empty($_k) && getenv($_k) === false) {
            putenv("{$_k}={$_v}");
            $_ENV[$_k] = $_v;
        }
    }
}

header('Content-Type: text/plain; charset=utf-8');

// ── Auth guard ────────────────────────────────────────────────
$adminKey = getenv('ADMIN_KEY') ?: '';
$provided = $_GET['key'] ?? '';

if ($adminKey === '' || $provided === '' || !hash_equals($adminKey, $provided)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// ── Locate logs dir ───────────────────────────────────────────
$logsDir  = realpath(__DIR__ . '/../../../../logs');
$today    = date('Y-m-d');
$todayLog = $logsDir . '/' . $today . '.log';

// ── List all .log files ───────────────────────────────────────
function listLogFiles(string $dir): string
{
    if (!$dir || !is_dir($dir)) {
        return "logs/ directory not found at expected path: {$dir}";
    }
    $files = glob($dir . '/*.log');
    if (empty($files)) {
        return "No .log files found in {$dir}";
    }
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $out = "Log files in {$dir}:\n";
    foreach ($files as $f) {
        $out .= '  ' . basename($f) . '  (' . date('Y-m-d H:i:s', filemtime($f)) . ', ' . filesize($f) . ' bytes)' . "\n";
    }
    return $out;
}

// ── Today's file missing ──────────────────────────────────────
if (!$logsDir || !is_dir($logsDir)) {
    echo "logs/ directory not found.\n";
    echo "Expected: " . realpath(__DIR__ . '/../../../../') . "/logs/\n\n";
    echo listLogFiles((string)$logsDir);
    exit;
}

if (!file_exists($todayLog)) {
    echo "Today's log file not found: {$today}.log\n\n";
    echo listLogFiles($logsDir);
    exit;
}

// ── Filter matching lines ─────────────────────────────────────
$lines   = file($todayLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$matched = array_filter($lines, fn($l) => str_contains($l, 'MSG91_RESPONSE') || str_contains($l, 'OTP'));

if (empty($matched)) {
    echo "NO MATCHES FOUND in {$today}.log\n\n";
    echo listLogFiles($logsDir);
    exit;
}

echo "=== Matches in {$today}.log (" . count($matched) . " lines) ===\n\n";
foreach ($matched as $line) {
    echo $line . "\n";
}
