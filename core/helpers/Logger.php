<?php
class Logger
{
    const DEBUG   = 0;
    const INFO    = 1;
    const WARNING = 2;
    const ERROR   = 3;

    private static array $levelNames = [
        self::DEBUG   => 'DEBUG',
        self::INFO    => 'INFO',
        self::WARNING => 'WARNING',
        self::ERROR   => 'ERROR',
    ];

    private static int $minLevel = self::ERROR;

    public static function init(): void
    {
        $level = strtolower(getenv('LOG_LEVEL') ?: 'error');
        self::$minLevel = match ($level) {
            'debug'   => self::DEBUG,
            'info'    => self::INFO,
            'warning' => self::WARNING,
            default   => self::ERROR,
        };
        $root = defined('SYSTEM_ROOT') ? SYSTEM_ROOT : dirname(dirname(__DIR__));
        $dir = $root . '/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    private static function write(int $level, string $message, array $context): void
    {
        if ($level < self::$minLevel) {
            return;
        }
        $root = defined('SYSTEM_ROOT') ? SYSTEM_ROOT : dirname(dirname(__DIR__));
        $logFile = $root . '/logs/' . date('Y-m-d') . '.log';
        $levelName = self::$levelNames[$level] ?? 'UNKNOWN';
        $line = implode(' | ', array_filter([
            date('Y-m-d H:i:s'),
            $levelName,
            self::requestInfo(),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ])) . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private static function requestInfo(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri    = $_SERVER['REQUEST_URI']    ?? '';
        $ip     = self::clientIp();
        return "{$method} {$uri} [{$ip}]";
    }

    private static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public static function error($message, $context = []) {
        self::write(self::ERROR, $message, $context);
    }

    public static function info($message, $context = []) {
        self::write(self::INFO, $message, $context);
    }

    public static function warning($message, $context = []) {
        self::write(self::WARNING, $message, $context);
    }
}