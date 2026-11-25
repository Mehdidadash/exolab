<?php

namespace App\Database;

use PDO;
use PDOException;

/**
 * Singleton database connection manager
 *
 * نکته‌های پایداری (خطاهای واقعی هاست):
 *   • SQLSTATE[HY000] [2002] No such file or directory
 *     یعنی PHP می‌خواهد از سوکتِ یونیکسِ localhost وصل شود و مسیر سوکت روی هاست
 *     متفاوت است (یا MySQL برای چند لحظه restart شده). راه‌حل: تلاش دوباره و
 *     فالبک به 127.0.0.1 (TCP) — و اگر لازم شد DB_SOCKET در .env.
 *   • SQLSTATE[HY000] [2006] MySQL server has gone away
 *     اتصالِ نیمه‌باز بعد از restart سرور؛ با یک تلاش دوباره حل می‌شود.
 */
class Connection
{
    private static ?PDO $instance = null;

    /**
     * Get the PDO instance (singleton)
     *
     * @throws PDOException اگر بعد از تلاش‌ها هم اتصال برقرار نشد
     */
    public static function getInstance(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = defined('DB_HOST') ? trim((string) DB_HOST) : 'localhost';
        if ($host === '') {
            $host = 'localhost';
        }
        $port   = defined('DB_PORT') ? trim((string) DB_PORT) : '';
        $socket = defined('DB_SOCKET') ? trim((string) DB_SOCKET) : '';

        // اگر روی localhost وصل نشد، 127.0.0.1 (TCP) را هم امتحان می‌کنیم.
        $hosts = [$host];
        if ($host === 'localhost') {
            $hosts[] = '127.0.0.1';
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4';
        }
        if (defined('PDO::ATTR_TIMEOUT')) {
            $options[PDO::ATTR_TIMEOUT] = 5;   // ~۵ ثانیه؛ صفحه معطل نماند
        }

        $lastError = null;

        foreach ($hosts as $h) {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $dsn = 'mysql:';
                    if ($socket !== '' && ($h === 'localhost' || $h === '')) {
                        $dsn .= 'unix_socket=' . $socket . ';';
                    } else {
                        $dsn .= 'host=' . $h . ';';
                        if ($port !== '') {
                            $dsn .= 'port=' . $port . ';';
                        }
                    }
                    $dsn .= 'dbname=' . DB_NAME . ';charset=utf8mb4';

                    self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                    return self::$instance;
                } catch (PDOException $e) {
                    $lastError = $e;
                    $code = (int) ($e->errorInfo[1] ?? 0);
                    $msg  = $e->getMessage();

                    // فقط خطاهای گذرا (سوکت/ری‌استارت) ارزشِ تلاشِ دوباره دارند؛
                    // نام کاربری/رمز اشتباه یا دیتابیسِ ناموجود را تکرار نمی‌کنیم.
                    $transient = in_array($code, [2002, 2006, 2013], true)
                        || stripos($msg, 'No such file or directory') !== false
                        || stripos($msg, 'gone away') !== false
                        || stripos($msg, 'Lost connection') !== false
                        || stripos($msg, 'Connection refused') !== false;

                    if (!$transient) {
                        throw $e;
                    }
                    if ($attempt < 2) {
                        usleep(250000);   // ۰.۲۵ ثانیه
                    }
                }
            }
        }

        throw $lastError ?? new PDOException('Database connection failed');
    }

    /**
     * Reset the connection (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
