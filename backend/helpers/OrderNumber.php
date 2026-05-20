<?php
// backend/helpers/OrderNumber.php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

/**
 * Global monotonic order_number generator.
 *
 * Unlike DailySerial (which resets each day per service), this is a
 * single ever-increasing integer used as the order's external reference
 * number (e.g. "Order #1042"). Customers refer to this when calling
 * support; daily_serial is for the team on the ground.
 *
 * Uses the counters table with row-level locking inside the caller's
 * transaction.
 */
class OrderNumber
{
    public static function next(PDO $pdo): int
    {
        // Atomic increment via INSERT … ON DUPLICATE KEY UPDATE
        $pdo->prepare(
            'INSERT INTO counters (name, value) VALUES ("order_number", 1)
             ON DUPLICATE KEY UPDATE value = value + 1'
        )->execute();

        $stmt = $pdo->prepare(
            'SELECT value FROM counters WHERE name = "order_number"'
        );
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
}
