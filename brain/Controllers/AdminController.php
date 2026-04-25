<?php

/**
 * BrainCore v3 — AdminController
 *
 * ─── v3 CHANGES ──────────────────────────────────────────────
 *   SECURITY: api_key moved from GET param → X-Api-Key header.
 *   SECURITY: Admin endpoints also require X-Admin-Key header.
 *   RATE LIMIT: Admin endpoints capped at 30 req/min.
 *   Added: GET /api/health endpoint for Heart integration checks.
 *
 * ─── AUTH: TWO LAYERS ────────────────────────────────────────
 *   Layer 1: X-Api-Key header  (same as public endpoints)
 *   Layer 2: X-Admin-Key header (must match ADMIN_KEY in .env)
 */

class AdminController
{
    // ── Admin key guard ────────────────────────────────────────

    private static function requireAdminKey(): void
    {
        $envKey = trim(getenv('ADMIN_KEY') ?: '');
        if ($envKey === '') {
            Response::error('Admin access is not configured. Set ADMIN_KEY in .env.', 503);
        }
        $headerKey = trim($_SERVER['HTTP_X_ADMIN_KEY'] ?? '');
        if ($headerKey === '') {
            Response::error('Admin access requires X-Admin-Key header.', 403);
        }
        if (!hash_equals($envKey, $headerKey)) {
            Response::error('Invalid admin key.', 403);
        }
    }

    private static function requireClientAuth(): string
    {
        $apiKey   = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
        $input    = array_merge($_GET, (json_decode(file_get_contents('php://input'), true) ?? []));
        $clientId = trim($input['client_id'] ?? '');

        if ($apiKey === '' || $clientId === '') {
            Response::error('Missing required: client_id param and X-Api-Key header', 422);
        }
        if (!ClientModel::validateApiKey($clientId, $apiKey)) {
            Response::error('Invalid client_id or X-Api-Key', 401);
        }

        // Admin-specific rate limit: 30 req/min
        if (!RateLimiter::isAllowed($clientId, '/admin')) {
            Response::error('Admin rate limit exceeded.', 429);
        }

        return $clientId;
    }

    // ── GET /api/health ────────────────────────────────────────

    public static function health(): void
    {
        $dbOk      = false;
        $dbVersion = 'unknown';
        try {
            $row = getDB()->query("SELECT VERSION() AS v")->fetch();
            $dbOk = true;
            $dbVersion = $row['v'] ?? 'unknown';
        } catch (\Throwable $e) {}

        $ruleCount = 0;
        try {
            $ruleCount = (int) getDB()->query("SELECT COUNT(*) AS c FROM brain_rules WHERE is_active=1")->fetch()['c'];
        } catch (\Throwable $e) {}

        $habitCount = 0;
        try {
            $habitCount = (int) getDB()->query("SELECT COUNT(*) AS c FROM brain_habits")->fetch()['c'];
        } catch (\Throwable $e) {}

        Response::success([
            'status'          => $dbOk ? 'healthy' : 'degraded',
            'version'         => getenv('APP_VERSION') ?: '3.0',
            'php_version'     => PHP_VERSION,
            'db_ok'           => $dbOk,
            'db_version'      => $dbVersion,
            'apcu_enabled'    => function_exists('apcu_store'),
            'active_rules'    => $ruleCount,
            'trained_habits'  => $habitCount,
            'integration_mode'=> getenv('INTEGRATION_MODE') ?: 'simulation',
            'server_timezone' => date_default_timezone_get(),
            'server_time_utc' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // ── Rules ──────────────────────────────────────────────────

    public static function listRules(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();

        $db  = getDB();
        $st  = $db->prepare("
            SELECT id, name, description, trigger_exp, condition_exp,
                   action_exp, priority, rule_type, is_active, created_by, created_at
            FROM   brain_rules
            WHERE  client_id = :cid OR client_id IS NULL
            ORDER  BY priority ASC, created_at DESC
        ");
        $st->execute([':cid' => $clientId]);
        $rules = $st->fetchAll();

        Response::success(['total' => count($rules), 'rules' => $rules]);
    }

    public static function createRule(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();

        $input    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $required = ['name', 'trigger_exp', 'condition_exp', 'action_exp'];
        foreach ($required as $f) {
            if (empty($input[$f])) Response::error("Missing required field: $f", 422);
        }

        $errors = RuleValidator::validate($input['condition_exp'], $input['action_exp']);
        if (!empty($errors)) {
            Response::error('Rule validation failed: ' . implode('; ', $errors), 422);
        }

        $db = getDB();
        $id = self::generateUuid();
        $st = $db->prepare("
            INSERT INTO brain_rules
                (id, client_id, name, description, trigger_exp, condition_exp,
                 action_exp, priority, rule_type, is_active, created_by, created_at, updated_at)
            VALUES
                (:id, :cid, :name, :desc, :trigger, :condition, :action,
                 :priority, :type, 1, :by, NOW(), NOW())
        ");
        $st->execute([
            ':id'       => $id,
            ':cid'      => $clientId,
            ':name'     => mb_substr(trim($input['name']), 0, 150),
            ':desc'     => trim($input['description'] ?? ''),
            ':trigger'  => trim($input['trigger_exp']),
            ':condition'=> trim($input['condition_exp']),
            ':action'   => trim($input['action_exp']),
            ':priority' => (int) ($input['priority'] ?? 5),
            ':type'     => in_array($input['rule_type'] ?? '', ['geo','user','global']) ? $input['rule_type'] : 'global',
            ':by'       => 'admin',
        ]);

        Response::success(['rule_id' => $id, 'status' => 'created'], 201);
    }

    public static function updateRule(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();

        $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $ruleId = trim($input['rule_id'] ?? '');
        if (!$ruleId) Response::error('Missing required field: rule_id', 422);

        $allowed = ['name','description','trigger_exp','condition_exp','action_exp','priority','rule_type','is_active'];
        $sets    = [];
        $params  = [':rule_id' => $ruleId, ':cid' => $clientId];

        if (isset($input['condition_exp']) || isset($input['action_exp'])) {
            $cExp   = $input['condition_exp'] ?? '';
            $aExp   = $input['action_exp']    ?? '';
            $errors = [];
            if ($cExp) $errors = array_merge($errors, RuleValidator::validateCondition($cExp));
            if ($aExp) $errors = array_merge($errors, RuleValidator::validateAction($aExp));
            if (!empty($errors)) {
                Response::error('Rule validation failed: ' . implode('; ', $errors), 422);
            }
        }

        foreach ($allowed as $field) {
            if (isset($input[$field])) {
                $sets[]            = "$field = :$field";
                $params[":$field"] = $input[$field];
            }
        }
        if (empty($sets)) Response::error('No fields to update.', 422);

        $sets[] = 'updated_at = NOW()';
        $db = getDB();
        $st = $db->prepare("UPDATE brain_rules SET " . implode(', ', $sets) . " WHERE id = :rule_id AND client_id = :cid");
        $st->execute($params);

        Response::success(['updated' => $st->rowCount() > 0]);
    }

    public static function deleteRule(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();

        $input  = json_decode(file_get_contents('php://input'), true) ?? $_GET;
        $ruleId = trim($input['rule_id'] ?? '');
        if (!$ruleId) Response::error('Missing required field: rule_id', 422);

        $db = getDB();
        $st = $db->prepare("UPDATE brain_rules SET is_active = 0, updated_at = NOW() WHERE id = :id AND client_id = :cid");
        $st->execute([':id' => $ruleId, ':cid' => $clientId]);

        Response::success(['deactivated' => $st->rowCount() > 0]);
    }

    // ── Logs + Improvements ───────────────────────────────────

    public static function viewLogs(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();
        $limit    = min((int) ($_GET['limit'] ?? 50), 200);

        $db = getDB();
        $st = $db->prepare("
            SELECT id, rule_id, input_data, output_data, status, created_at
            FROM   brain_decisions
            WHERE  client_id = :cid
            ORDER  BY created_at DESC
            LIMIT  :limit
        ");
        $st->execute([':cid' => $clientId, ':limit' => $limit]);
        $rows = $st->fetchAll();

        foreach ($rows as &$row) {
            $row['input_data']  = json_decode($row['input_data'],  true);
            $row['output_data'] = json_decode($row['output_data'], true);
        }

        Response::success(['total' => count($rows), 'decisions' => $rows]);
    }

    public static function viewImprovements(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();
        $limit    = min((int) ($_GET['limit'] ?? 50), 200);

        Response::success([
            'improvements' => ImprovementModel::getPendingImprovements($clientId, $limit)
        ]);
    }

    public static function viewSuggestions(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();

        $db = getDB();
        $st = $db->prepare("
            SELECT id, suggestion_data, pattern_summary, event_count, status, created_at
            FROM   brain_suggestions
            WHERE  client_id = :cid
            ORDER  BY event_count DESC, created_at DESC
            LIMIT  100
        ");
        $st->execute([':cid' => $clientId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$row) {
            $row['suggestion_data'] = json_decode($row['suggestion_data'], true);
        }

        Response::success(['total' => count($rows), 'suggestions' => $rows]);
    }

    public static function approveSuggestion(): void
    {
        self::requireAdminKey();
        $clientId     = self::requireClientAuth();
        $input        = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $suggestionId = trim($input['suggestion_id'] ?? '');

        if (!$suggestionId) Response::error('Missing required field: suggestion_id', 422);

        $db = getDB();
        $st = $db->prepare("
            UPDATE brain_suggestions
            SET    status = 'approved', reviewed_at = NOW()
            WHERE  id = :id AND client_id = :cid
        ");
        $st->execute([':id' => $suggestionId, ':cid' => $clientId]);

        Response::success(['approved' => $st->rowCount() > 0]);
    }

    public static function runSuggestionEngine(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();

        if (!class_exists('SuggestionEngine')) {
            Response::error('SuggestionEngine not available.', 503);
        }

        $result = SuggestionEngine::run($clientId);
        Response::success($result);
    }

    public static function viewActions(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();
        $limit    = min((int) ($_GET['limit'] ?? 50), 200);

        Response::success(['actions' => ActionModel::getRecent($clientId, $limit)]);
    }

    public static function viewAlerts(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();

        $db = getDB();
        $st = $db->prepare("
            SELECT id, type, severity, message, context_json, status, created_at
            FROM   brain_alerts
            WHERE  client_id = :cid
            ORDER  BY created_at DESC
            LIMIT  100
        ");
        $st->execute([':cid' => $clientId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$row) {
            if ($row['context_json']) $row['context_json'] = json_decode($row['context_json'], true);
        }

        Response::success(['total' => count($rows), 'alerts' => $rows]);
    }

    // ── Habits (new in v3) ─────────────────────────────────────

    public static function viewHabits(): void
    {
        self::requireAdminKey();
        $clientId = self::requireClientAuth();
        $limit    = min((int) ($_GET['limit'] ?? 50), 200);

        $db = getDB();
        $st = $db->prepare("
            SELECT location, category, time_of_day, score, hit_count, last_seen, created_at
            FROM   brain_habits
            WHERE  client_id = :cid
            ORDER  BY score DESC
            LIMIT  :limit
        ");
        $st->execute([':cid' => $clientId, ':limit' => $limit]);

        $rows    = $st->fetchAll();
        $summary = HabitModel::summary();

        Response::success([
            'summary'         => $summary,
            'bypass_threshold'=> HabitModel::BYPASS_THRESHOLD,
            'habits'          => $rows,
        ]);
    }

    // ── Private helper ─────────────────────────────────────────

    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
