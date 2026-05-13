<?php
// backend/helpers/OrderNumber.php

declare(strict_types=1);

namespace App\Helpers;

class OrderNumber
{
    public static function next(\PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            "SELECT value FROM counters WHERE name = 'order_number' FOR UPDATE"
        );
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->prepare(
                "INSERT INTO counters (name, value) VALUES ('order_number', 1000)"
            )->execute();
            $current = 1000;
        } else {
            $current = (int)$row['value'];
        }

        $next = $current + 1;

        $pdo->prepare(
            "UPDATE counters SET value = ? WHERE name = 'order_number'"
        )->execute([$next]);

        return $next;
    }
}

?>