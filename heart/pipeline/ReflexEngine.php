<?php
/**
 * ================================================================
 *  WorkToGo — HEART SYSTEM v1.2.0
 *  Pipeline Step 2 :: heart/reflex_engine.php
 *
 *  Fast memory layer. Returns cached responses instantly,
 *  before Brain or Engines are called.
 *
 *  Cache driver priority:
 *    1. Redis  (best — shared across workers)
 *    2. APCu   (good — single server, in-process)
 *    3. File   (fallback — always on Hostinger)
 *
 *  v1.2.0 fixes:
 *  - File cache: JSON instead of unserialize() (was RCE risk)
 *  - FileReflexDriver::deleteByPrefix() now works (glob-based)
 *  - Python module layer removed — PHP only
 *  - Cache key includes app type (prevents cross-app pollution)
 * ================================================================
 */

declare(strict_types=1);

class ReflexEngine
{
    private static ?ReflexCacheDriver $driver = null;

    // ── Public API ───────────────────────────────────────────

    /**
     * Check for a valid cached response for this context.
     * Returns payload with _cache_hit=true, or null.
     */
    public static function check(array $context): ?array
    {
        if (!self::isCacheable($context)) {
            return null;
        }

        $key  = self::buildKey($context);
        $data = self::driver()->get($key);

        if ($data === null) {
            return null;
        }

        HeartLogger::debug('Reflex cache hit', ['key_prefix' => substr($key, 0, 40)]);
        return array_merge($data, ['_cache_hit' => true]);
    }

    /**
     * Store a pipeline response in cache after a successful run.
     */
    public static function store(array $context, array $route, HeartResponse $response): void
    {
        if (!self::isCacheable($context)) {
            return;
        }

        $ttl = (int)($route['cache_ttl'] ?? self::getTtl($context['intent']));
        if ($ttl <= 0) {
            return;
        }

        $key = self::buildKey($context);
        self::driver()->set($key, $response->toArray(), $ttl);
        HeartLogger::debug('Reflex cache stored', ['ttl' => $ttl]);
    }

    /**
     * Invalidate all cache entries for a user (call after write ops).
     */
    public static function invalidate(string $userId): void
    {
        self::driver()->deleteByPrefix('reflex:' . md5($userId) . ':');
    }

    /**
     * Flush entire reflex cache (admin/emergency use).
     */
    public static function flush(): void
    {
        self::driver()->flush();
    }

    // ── Internals ────────────────────────────────────────────

    private static function driver(): ReflexCacheDriver
    {
        if (self::$driver === null) {
            self::$driver = self::pickDriver();
        }
        return self::$driver;
    }

    private static function pickDriver(): ReflexCacheDriver
    {
        // Try Redis first
        if (extension_loaded('redis') && !empty(REDIS_HOST)) {
            try {
                return new RedisReflexDriver(REDIS_HOST, REDIS_PORT, REDIS_PASS, REDIS_DB);
            } catch (\Throwable $e) {
                HeartLogger::warn('Redis unavailable — falling back to APCu', ['error' => $e->getMessage()]);
            }
        }

        // APCu second
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            return new ApcuReflexDriver();
        }

        // File fallback (always works on Hostinger shared hosting)
        $cacheDir = SYSTEM_ROOT . '/logs/reflex_cache';
        HeartLogger::debug('Using file cache driver', ['dir' => $cacheDir]);
        return new FileReflexDriver($cacheDir);
    }

    private static function isCacheable(array $context): bool
    {
        static $noCache = ['add_to_cart', 'checkout', 'book_service', 'schedule_delivery'];
        return !in_array($context['intent'], $noCache, true);
    }

    private static function buildKey(array $context): string
    {
        $uid     = $context['user']['is_anonymous'] ? 'anon' : ($context['user']['id'] ?? 'anon');
        $intent  = $context['intent'];
        $query   = md5($context['query']);
        $filters = md5(json_encode($context['filters']));
        $page    = $context['pagination']['page'];
        $app     = $context['channel']['app'];

        // Location rounded to ~1 km
        $lat = $context['location']['has_coords']
            ? round((float)$context['location']['lat'], 2)
            : 'x';
        $lng = $context['location']['has_coords']
            ? round((float)$context['location']['lng'], 2)
            : 'x';

        return "reflex:{$uid}:{$intent}:{$app}:{$query}:{$filters}:{$lat}:{$lng}:{$page}";
    }

    private static function getTtl(string $intent): int
    {
        $ttlMap = CACHE_TTL;
        return match(true) {
            str_contains($intent, 'list')   => $ttlMap['list'],
            str_contains($intent, 'search') => $ttlMap['search'],
            str_contains($intent, 'status') => $ttlMap['status'],
            str_contains($intent, 'track')  => $ttlMap['track'],
            default                         => $ttlMap['default'],
        };
    }
}


// ════════════════════════════════════════════════════════════
//  DRIVER INTERFACE
// ════════════════════════════════════════════════════════════
interface ReflexCacheDriver
{
    public function get(string $key): ?array;
    public function set(string $key, array $value, int $ttl): void;
    public function deleteByPrefix(string $prefix): void;
    public function flush(): void;
}


// ════════════════════════════════════════════════════════════
//  REDIS DRIVER
// ════════════════════════════════════════════════════════════
class RedisReflexDriver implements ReflexCacheDriver
{
    private \Redis $redis;

    public function __construct(string $host, int $port, string $password, int $db)
    {
        $this->redis = new \Redis();
        $this->redis->connect($host, $port, timeout: 0.5);
        if (!empty($password)) {
            $this->redis->auth($password);
        }
        $this->redis->select($db);
        // Use JSON serializer for safety (not PHP serializer)
        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
    }

    public function get(string $key): ?array
    {
        $val = $this->redis->get($key);
        return ($val !== false && $val !== null) ? (array)$val : null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        $this->redis->setEx($key, $ttl, $value);
    }

    public function deleteByPrefix(string $prefix): void
    {
        // Use SCAN to avoid blocking O(N) KEYS command
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $prefix . '*', 100);
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        } while ($cursor !== 0 && $cursor !== false);
    }

    public function flush(): void
    {
        $this->redis->flushDb();
    }
}


// ════════════════════════════════════════════════════════════
//  APCU DRIVER
// ════════════════════════════════════════════════════════════
class ApcuReflexDriver implements ReflexCacheDriver
{
    public function get(string $key): ?array
    {
        $val = apcu_fetch($key, $success);
        return ($success && is_array($val)) ? $val : null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        apcu_store($key, $value, $ttl);
    }

    public function deleteByPrefix(string $prefix): void
    {
        if (class_exists('APCUIterator')) {
            $it = new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');
            apcu_delete($it);
        }
    }

    public function flush(): void
    {
        apcu_clear_cache();
    }
}


// ════════════════════════════════════════════════════════════
//  FILE DRIVER — Hostinger shared hosting fallback
//
//  v1.2.0 fixes:
//  - JSON encode/decode instead of serialize/unserialize (was RCE)
//  - deleteByPrefix() now works via glob scan
//  - Atomic write via temp file + rename
// ════════════════════════════════════════════════════════════
class FileReflexDriver implements ReflexCacheDriver
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . md5($key) . '.cache';
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        // v1.2.0: JSON decode (was unserialize — RCE risk)
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['_exp'], $data['payload'])) {
            @unlink($path);
            return null;
        }

        if ($data['_exp'] < time()) {
            @unlink($path);
            return null;
        }

        return is_array($data['payload']) ? $data['payload'] : null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        $path    = $this->path($key);
        $content = json_encode([
            '_exp'    => time() + $ttl,
            '_key'    => $key,          // v1.2.1: stored so deleteByPrefix() can match
            'payload' => $value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($content === false) {
            return;
        }

        // Atomic write: write to temp file, then rename
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content, LOCK_EX) !== false) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * v1.2.0: deleteByPrefix now works via glob scan.
     * We store the original key prefix in the file for prefix-matching.
     * For simplicity: scan all files and check JSON content.
     * This is O(N files) — acceptable for Hostinger scale.
     */
    public function deleteByPrefix(string $prefix): void
    {
        $files = glob($this->dir . '/*.cache') ?: [];
        foreach ($files as $file) {
            $raw  = @file_get_contents($file);
            $data = $raw ? json_decode($raw, true) : null;
            if (is_array($data) && isset($data['_key'])) {
                if (str_starts_with($data['_key'], $prefix)) {
                    @unlink($file);
                }
            }
        }
    }

    public function flush(): void
    {
        foreach (glob($this->dir . '/*.cache') ?: [] as $f) {
            @unlink($f);
        }
    }
}
