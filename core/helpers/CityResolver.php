<?php
/**
 * CityResolver — WorkToGo V2 multi-city foundation helper.
 *
 * Resolves an incoming city hint (a selected_city / slug / name), or an owning
 * user / vendor, to a cities.id — ALWAYS defaulting to the active Haldwani city
 * (slug 'haldwani', the anchor seeded by
 * migrations/2026_07_04_001_cities_and_city_id_foundation.sql) so a newly
 * created row is never city-less.
 *
 * Used at INSERT time only. It never modifies existing rows and never touches
 * the free-text area/locality fields — city_id is the parent dimension above
 * them. Every lookup is wrapped defensively: if the cities table or a city_id
 * column does not exist yet (migration not applied on an environment), the
 * resolver returns 0 / false and callers simply omit city_id, so login and
 * booking creation keep working.
 */
class CityResolver
{
    /** Anchor slug seeded by the city-foundation migration. */
    const DEFAULT_SLUG = 'haldwani';

    /** @var array<string,bool> per-table city_id column-existence cache (per request) */
    private static $colCache = [];

    /** @var int|null cached default (Haldwani) city id for this request */
    private static $defaultId = null;

    /**
     * Resolve a city id from a free-text hint (city name or slug).
     * Falls back to the default (Haldwani) city when the hint is empty, generic,
     * or unmatched. Returns 0 only when the cities table is absent/empty — the
     * caller must guard with hasCityColumn() and id > 0 before inserting.
     */
    public static function resolveId(PDO $db, $cityInput = null): int
    {
        try {
            $raw = is_string($cityInput) ? trim($cityInput) : '';
            if ($raw !== '') {
                $slug = self::slugify($raw);
                // Ignore generic placeholders that really mean "no city given".
                if ($slug !== '' && !in_array($slug, ['local-city', 'unknown', 'na', 'none'], true)) {
                    $stmt = $db->prepare(
                        "SELECT id FROM cities
                         WHERE is_active = 1 AND (slug = :slug OR LOWER(name) = :name)
                         ORDER BY (slug = :slug2) DESC, sort_order ASC, id ASC
                         LIMIT 1"
                    );
                    $stmt->execute([':slug' => $slug, ':name' => strtolower($raw), ':slug2' => $slug]);
                    $id = (int) ($stmt->fetchColumn() ?: 0);
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        } catch (\Throwable $e) {
            // cities table may not exist yet (migration not applied) — treat as unresolved.
            return 0;
        }
        return self::defaultId($db);
    }

    /** Resolve from the owning user's city_id (used when creating a vendor). */
    public static function resolveFromUser(PDO $db, ?int $userId): int
    {
        return self::resolveFromOwner($db, 'users', $userId);
    }

    /** Resolve from the owning vendor's city_id (used when creating a service row). */
    public static function resolveFromVendor(PDO $db, ?int $vendorId): int
    {
        return self::resolveFromOwner($db, 'vendors', $vendorId);
    }

    /**
     * Does <table>.city_id exist? Cached per request. Lets callers stay safe on
     * an environment where the city-foundation migration has not been applied.
     */
    public static function hasCityColumn(PDO $db, string $table): bool
    {
        if (array_key_exists($table, self::$colCache)) {
            return self::$colCache[$table];
        }
        $exists = false;
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'city_id'"
            );
            $stmt->execute([$table]);
            $exists = (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $exists = false;
        }
        return self::$colCache[$table] = $exists;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** $table is always a hard-coded literal ('users' | 'vendors') — never user input. */
    private static function resolveFromOwner(PDO $db, string $table, ?int $ownerId): int
    {
        if ($ownerId && self::hasCityColumn($db, $table)) {
            try {
                $stmt = $db->prepare("SELECT city_id FROM {$table} WHERE id = ? LIMIT 1");
                $stmt->execute([$ownerId]);
                $id = (int) ($stmt->fetchColumn() ?: 0);
                if ($id > 0) {
                    return $id;
                }
            } catch (\Throwable $e) {
                // fall through to default
            }
        }
        return self::defaultId($db);
    }

    private static function defaultId(PDO $db): int
    {
        if (self::$defaultId !== null) {
            return self::$defaultId;
        }
        $id = 0;
        try {
            $stmt = $db->prepare("SELECT id FROM cities WHERE slug = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([self::DEFAULT_SLUG]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id === 0) {
                // No Haldwani row — fall back to the first active city so we still
                // never insert an invalid/absent city reference.
                $id = (int) ($db->query(
                    "SELECT id FROM cities WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1"
                )->fetchColumn() ?: 0);
            }
        } catch (\Throwable $e) {
            $id = 0;
        }
        return self::$defaultId = $id;
    }

    private static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string) $s, '-');
    }
}
