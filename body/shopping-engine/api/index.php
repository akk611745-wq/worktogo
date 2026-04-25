<?php
/**
 * WorkToGo — Shopping Engine (Placeholder)
 */

require_once dirname(dirname(__DIR__)) . '/core/helpers/Database.php';
require_once dirname(dirname(__DIR__)) . '/core/helpers/Response.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (defined('HEART_INTERNAL_INC')) {
    $input = json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true);
}

Response::success([
    'items' => [],
    'total' => 0,
    'message' => 'Shopping engine is active but empty.'
]);
