<?php
// backend/helpers/DailySerial.php

declare(strict_types=1);

namespace App\Helpers;

use PDO;
use RuntimeException;

/**
 * Per-service per-day serial number generator.
 *
 * Every order_service gets a small, human-friendly number that resets
 * each Dubai-midnight:
 *
 *   Car Wash #1, #2, #3 …
 *   Porter   #1, #2, #3 …
 *
 * This is the number printed on the slip and shown on the Team Leader's
 * job card. Used by customers and TLs to refer to a job verbally.
 *
 * ─── How it works ────────────────────────────────────────────────────
 * `daily_serials` is a tiny table keyed by (service_id, serial_date).
 * We upsert it with ON DUPLICATE KEY UPDATE last_serial = last_serial + 1
 * and then read the new value back. Because the PDO connection sets
 * time_zone = '+04:00', CURDATE() resolves to the Dubai calendar date,
 * so the counter naturally resets at 00:00 Dubai time.
 *
 * Call this INSIDE the same DB transaction that inserts the
 * order_services row — that way, if the order insert fails, the
 * counter increment rolls back too.
 *
 * The unique key uniq_os_daily_serial (service_id, serial_date,
 * daily_serial) on order_services guarantees we never hand out the
 * same serial twice — if a race somehow occurred, the second insert
 * would fail with a duplicate-key error.
 */
class DailySerial
{
    /**
     * Increment and return the next serial for this service for today
     * (Dubai date). MUST be called inside a transaction.
     *
     * @return array{serial: int, date: string}
     */
    public static function next(PDO $pdo, string $serviceId): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException(
                'DailySerial::next() must be called inside a DB transaction'
            );
        }

        // Atomic upsert. Single statement = single row lock; safe under
        // concurrent inserts.
        $upsert = $pdo->prepare(
            'INSERT INTO daily_serials (service_id, serial_date, last_serial)
             VALUES (?, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE last_serial = last_serial + 1'
        );
        $upsert->execute([$serviceId]);

        // Read back what we just wrote
        $sel = $pdo->prepare(
            'SELECT last_serial, serial_date
             FROM daily_serials
             WHERE service_id = ? AND serial_date = CURDATE()'
        );
        $sel->execute([$serviceId]);
        $row = $sel->fetch();

        if (!$row) {
            // Should be impossible — we just inserted/updated it
            throw new RuntimeException('Failed to read back daily serial');
        }

        return [
            'serial' => (int)$row['last_serial'],
            'date'   => $row['serial_date'],
        ];
    }

    /**
     * Read-only: return today's current count for a service (does not
     * increment). Used by admin dashboards.
     */
    public static function currentCount(PDO $pdo, string $serviceId): int
    {
        $stmt = $pdo->prepare(
            'SELECT last_serial FROM daily_serials
             WHERE service_id = ? AND serial_date = CURDATE()'
        );
        $stmt->execute([$serviceId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * Format a serial for display: "#7 · Car Wash · 14 May"
     */
    public static function format(int $serial, string $serviceName, ?string $date = null): string
    {
        $datePart = $date
            ? ' · ' . date('j M', strtotime($date))
            : '';
        return sprintf('#%d · %s%s', $serial, $serviceName, $datePart);
    }
}
