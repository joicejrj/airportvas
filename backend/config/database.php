<?php
// backend/config/Database.php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

/**
 * PDO singleton.
 *
 * CRITICAL: We force the connection timezone to +04:00 (Asia/Dubai)
 * so that CURDATE() and NOW() resolve to Dubai calendar time.
 * This is what makes the daily_serials counter reset at midnight
 * Dubai time instead of UTC.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    DB_HOST,
                    defined('DB_PORT') ? DB_PORT : 3306,
                    DB_NAME
                );

                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND =>
                        "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, "
                      . "time_zone = '+04:00', "
                      . "sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
                ]);
            } catch (PDOException $e) {
                error_log('DB connection failed: ' . $e->getMessage());
                http_response_code(503);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Database connection failed',
                ]);
                exit;
            }
        }
        return self::$instance;
    }

    /**
     * Execute a callable inside a transaction.
     * Auto-commits on success, rolls back on any throwable.
     *
     * Usage:
     *   Database::transaction(function ($pdo) use (&$result) {
     *       $pdo->prepare('UPDATE …')->execute(…);
     *       $result = …;
     *   });
     */
    public static function transaction(callable $fn): void
    {
        $pdo = self::getInstance();

        // Nested-transaction safety: if a transaction is already running,
        // execute inline rather than starting a new one.
        if ($pdo->inTransaction()) {
            $fn($pdo);
            return;
        }

        $pdo->beginTransaction();
        try {
            $fn($pdo);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
