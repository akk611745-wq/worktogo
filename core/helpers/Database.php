<?php
/**
 * WorkToGo Core — Unified Database Helper
 */

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $root = defined('SYSTEM_ROOT') ? SYSTEM_ROOT : dirname(dirname(__DIR__));
           $config = require $root . '/config/database.php';
            
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $config['username'], $config['password'], $options);
                
                // Set timezone if provided
                $tz = getenv('DB_TIMEZONE') ?: '+00:00';
                self::$instance->exec("SET time_zone = '{$tz}'");
            } catch (PDOException $e) {
                // Log error through centralized logger if available
                if (class_exists('Logger')) {
                    Logger::error("Database connection failed", ['error' => $e->getMessage()]);
                }
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
                exit;
            }
        }
        return self::$instance;
    }

    public static function getConnection(): PDO
    {
        return self::getInstance();
    }
}

/**
 * Global helper function for ease of use
 */
function getDB(): PDO {
    return Database::getInstance();
}
