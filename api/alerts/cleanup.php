<?php
/**
 * Alert System Cleanup Script
 * -------------------------------------------------------
 * Intended to be run via cron job.
 * Deletes seen alerts older than the threshold defined in config.php.
 * -------------------------------------------------------
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/helpers/Database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AlertEngine.php';

try {
    $pdo = Database::getConnection();
    $engine = new AlertEngine($pdo);
    $deleted = $engine->cleanup();

    echo "Cleanup successful. Deleted $deleted old alerts.\n";
} catch (Throwable $e) {
    error_log('[AlertCleanup] ' . $e->getMessage());
    echo "Cleanup failed: " . $e->getMessage() . "\n";
    exit(1);
}
