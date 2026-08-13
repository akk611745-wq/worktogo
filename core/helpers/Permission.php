<?php
/**
 * Permission — WorkToGo V2 data-driven RBAC check (DB layer only).
 *
 * Answers one question defensively: does a given user hold permission X
 * (optionally scoped to a category / city)? It reads the RBAC tables laid down
 * by migrations/2026_08_13_001_rbac_foundation.sql (roles, permissions,
 * role_permissions, user_roles) and nothing else — no UI, no role management
 * (that lives in the admin endpoints).
 *
 * BACKWARD COMPATIBILITY IS THE POINT. This is an ADDITIVE gate that must never
 * lock out today's admin:
 *   1. A user whose legacy users.role === 'admin' passes EVERY check — exactly
 *      as today — even before the migration runs or if the RBAC tables are empty.
 *   2. A user holding the 'super_admin' role also passes everything.
 *   3. Everyone else is checked against their granted permissions, fail-closed:
 *      if the tables are missing or a query errors, a non-admin is DENIED, never
 *      accidentally allowed.
 *
 * SCOPE. A permission may be checked globally (does the user have it anywhere?)
 * or against a specific scope (e.g. category 5). A role assignment is either
 * 'global' (grants everywhere) or scoped ('category'/'city' + id); a scoped
 * grant satisfies a scoped check only when type+id match. Passing no scope asks
 * "can this user ever do X?" — useful for showing/hiding a window; passing a
 * scope is the enforcement path for Category Head / city ops.
 */
class Permission
{
    /** Legacy enum role that is all-access today (config/app.php ROLE_ADMIN). */
    const LEGACY_ADMIN_ROLE = 'admin';
    /** System role slug that grants everything (migration seed). */
    const SUPER_ADMIN_SLUG  = 'super_admin';

    /** @var bool|null cached table-existence flag for this request */
    private static $tableExists = null;

    /**
     * Does $userId hold $permKey? $userRole is the caller's legacy users.role
     * (from the JWT payload) and drives the admin all-access bypass.
     *
     * @param string|null $scopeType null = "anywhere"; else 'category'|'city'|'global'
     * @param int|null    $scopeId   the category/city id when scoped
     */
    public static function userHas(PDO $db, int $userId, ?string $userRole, string $permKey,
                                   ?string $scopeType = null, ?int $scopeId = null): bool
    {
        // 1. Legacy admin bypass — unchanged behaviour, works without RBAC tables.
        if ($userRole === self::LEGACY_ADMIN_ROLE) {
            return true;
        }
        if ($userId <= 0 || $permKey === '') {
            return false;
        }
        // 2. No tables yet -> only admins (handled above) pass; deny everyone else.
        if (!self::hasTable($db)) {
            return false;
        }

        try {
            // 3. super_admin role holder -> all-access (covers perms not yet linked).
            if (self::holdsSuperAdmin($db, $userId)) {
                return true;
            }

            // 4. Granted-permission check, honouring scope.
            $sql = "SELECT 1
                      FROM user_roles ur
                      JOIN roles r             ON r.id = ur.role_id AND r.is_active = 1
                      JOIN role_permissions rp ON rp.role_id = r.id
                      JOIN permissions p       ON p.id = rp.permission_id AND p.is_active = 1
                     WHERE ur.user_id = :uid
                       AND p.perm_key = :key";
            $bind = [':uid' => $userId, ':key' => $permKey];

            // Scoped check: a global grant always counts; a scoped grant must match.
            if ($scopeType !== null && $scopeType !== '' && $scopeType !== 'global') {
                $sql .= " AND (ur.scope_type = 'global'
                              OR (ur.scope_type = :st AND ur.scope_id = :sid))";
                $bind[':st']  = $scopeType;
                $bind[':sid'] = (int) ($scopeId ?? 0);
            }
            $sql .= " LIMIT 1";

            $stmt = $db->prepare($sql);
            $stmt->execute($bind);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Fail-closed: a non-admin is denied if anything goes wrong.
            error_log('[Permission::userHas] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gate helper: continue if the user has $permKey, else Response::forbidden().
     * Takes the already-resolved JWT payload so admin endpoints reuse it.
     */
    public static function enforce(PDO $db, array $auth, string $permKey,
                                   ?string $scopeType = null, ?int $scopeId = null): void
    {
        $userId   = (int) ($auth['user_id'] ?? 0);
        $userRole = isset($auth['role']) ? (string) $auth['role'] : null;
        if (self::userHas($db, $userId, $userRole, $permKey, $scopeType, $scopeId)) {
            return;
        }
        if (class_exists('Logger')) {
            Logger::warning('Forbidden: missing permission', [
                'user_id' => $userId, 'permission' => $permKey,
                'scope'   => $scopeType, 'scope_id' => $scopeId,
            ]);
        }
        Response::forbidden('You do not have permission to perform this action');
    }

    /**
     * Every permission key the user effectively has (admin / super_admin -> all
     * active). For building a role-scoped "window" and hiding what a user can't do.
     */
    public static function listForUser(PDO $db, int $userId, ?string $userRole): array
    {
        if (!self::hasTable($db)) {
            // Without tables, only the legacy admin has (implicitly) everything;
            // we cannot enumerate the catalog, so return [] for non-admins.
            return [];
        }
        try {
            if ($userRole === self::LEGACY_ADMIN_ROLE || self::holdsSuperAdmin($db, $userId)) {
                $stmt = $db->query("SELECT perm_key FROM permissions WHERE is_active = 1 ORDER BY perm_key");
                return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
            $stmt = $db->prepare(
                "SELECT DISTINCT p.perm_key
                   FROM user_roles ur
                   JOIN roles r             ON r.id = ur.role_id AND r.is_active = 1
                   JOIN role_permissions rp ON rp.role_id = r.id
                   JOIN permissions p       ON p.id = rp.permission_id AND p.is_active = 1
                  WHERE ur.user_id = :uid
                  ORDER BY p.perm_key"
            );
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            error_log('[Permission::listForUser] ' . $e->getMessage());
            return [];
        }
    }

    /** Role slugs the user currently holds (any scope). */
    public static function roleSlugsForUser(PDO $db, int $userId): array
    {
        if (!self::hasTable($db) || $userId <= 0) {
            return [];
        }
        try {
            $stmt = $db->prepare(
                "SELECT DISTINCT r.slug
                   FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                  WHERE ur.user_id = :uid AND r.is_active = 1"
            );
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            error_log('[Permission::roleSlugsForUser] ' . $e->getMessage());
            return [];
        }
    }

    /** Does the user hold the active super_admin role? */
    private static function holdsSuperAdmin(PDO $db, int $userId): bool
    {
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
                  WHERE ur.user_id = :uid AND r.slug = :slug LIMIT 1"
            );
            $stmt->execute([':uid' => $userId, ':slug' => self::SUPER_ADMIN_SLUG]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Do the RBAC tables exist yet? Cached per request (checks `permissions`). */
    public static function hasTable(PDO $db): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissions'"
            );
            $stmt->execute();
            return self::$tableExists = ((int) $stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$tableExists = false;
        }
    }
}
