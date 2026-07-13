<?php

namespace App\Database;

use PDO;
use PDOException;

/**
 * Singleton database connection manager
 */
class Connection
{
    private static ?PDO $instance = null;

    /**
     * Get the PDO instance (singleton)
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            ]);
        }
        return self::$instance;
    }

    /**
     * Reset the connection (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
