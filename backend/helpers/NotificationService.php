<?php
// backend/helpers/NotificationService.php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Notification dispatcher.
 *
 * Layered delivery, in order:
 *   1. Web Push (minishlink/web-push) — every device the user has subscribed
 *   2. Email fallback — if no live push subscription succeeded
 *   3. SMS — stub for future
 *
 * Every call also persists a row in the `notifications` table so the in-app
 * bell/inbox can show history regardless of whether push reached the device.
 */
class NotificationService
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /* ─────────────────────────────────────────────────────────────
     *  PUBLIC API
     * ─────────────────────────────────────────────────────────── */

    /**
     * Send a notification to ONE user.
     * Logs to `notifications`, pushes to every subscribed device,
     * falls back to email if no push survives.
     */
    public function notify(string $userId, string $type, array $data): void
    {
        $notifId = $this->logNotification($userId, $type, $data);

        if (empty(VAPID_PUBLIC_KEY) || empty(VAPID_PRIVATE_KEY)) {
            error_log('VAPID keys missing — push skipped, trying email');
            $this->emailFallback($userId, $data);
            return;
        }

        $subs = $this->getUserSubscriptions($userId);
        if (!$subs) {
            $this->emailFallback($userId, $data);
            return;
        }

        $sent = $this->sendWebPush($subs, [
            'title' => $data['title'] ?? 'Notification',
            'body'  => $data['body']  ?? '',
            'type'  => $type,
            'data'  => $data,
        ]);

        if ($sent > 0) {
            $this->pdo->prepare(
                'UPDATE notifications SET sent_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([$notifId]);
        } else {
            $this->emailFallback($userId, $data);
        }
    }

    /**
     * Dispatch a notification tied to an ORDER.
     * Notifies the assigned provider(s) on that order, and the customer.
     * Used by accept / start / complete / payment-received flows.
     *
     * @return int number of users notified
     */
    public function dispatch(string $orderId, string $type, string $message): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT user_id FROM (
                SELECT os.provider_id AS user_id
                  FROM order_services os
                 WHERE os.order_id = ? AND os.provider_id IS NOT NULL
                UNION
                SELECT o.customer_id AS user_id
                  FROM orders o
                 WHERE o.id = ? AND o.customer_id IS NOT NULL
             ) t WHERE user_id IS NOT NULL'
        );
        $stmt->execute([$orderId, $orderId]);

        $count = 0;
        while ($row = $stmt->fetch()) {
            $this->notify($row['user_id'], $type, [
                'title'    => $this->titleFor($type),
                'body'     => $message,
                'order_id' => $orderId,
            ]);
            $count++;
        }
        return $count;
    }

    /** Stub: inject SMS API (Twilio, Vonage, etc.) */
    public function sendSms(string $phone, string $message): void
    {
        error_log("SMS to {$phone}: {$message}");
    }

    /* ─────────────────────────────────────────────────────────────
     *  INTERNALS
     * ─────────────────────────────────────────────────────────── */

    private function logNotification(string $userId, string $type, array $data): string
    {
        $id = $this->uuidv4();
        $this->pdo->prepare(
            'INSERT INTO notifications (id, user_id, type, title, body, data, channel, created_at)
             VALUES (?, ?, ?, ?, ?, ?, "push", UTC_TIMESTAMP())'
        )->execute([
            $id,
            $userId,
            $type,
            $data['title'] ?? 'Notification',
            $data['body']  ?? null,
            json_encode($data, JSON_UNESCAPED_SLASHES),
        ]);
        return $id;
    }

    /** @return array<int, array{id:int, endpoint:string, p256dh:string, auth:string}> */
    private function getUserSubscriptions(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, endpoint, p256dh, auth
             FROM push_subscriptions
             WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Send to a list of subscriptions via minishlink/web-push.
     * Returns the count of successful sends. Deletes dead subscriptions.
     */
    private function sendWebPush(array $subs, array $payload): int
    {
        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => 'mailto:joicekurups@gmail.com',
                    'publicKey'  => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ],
            ], [], 5); // 5-second per-request timeout

            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

            // endpoint -> row id, for cleanup on 404/410
            $endpointToRowId = [];

            foreach ($subs as $s) {
                $sub = Subscription::create([
                    'endpoint'        => $s['endpoint'],
                    'publicKey'       => $s['p256dh'],
                    'authToken'       => $s['auth'],
                    'contentEncoding' => 'aes128gcm',
                ]);
                $endpointToRowId[$s['endpoint']] = $s['id'];

                $webPush->queueNotification($sub, $body, [
                    'TTL'     => 86400,   // hold for 24h if device offline
                    'urgency' => 'high',  // wakes the device immediately
                ]);
            }

            $success = 0;
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();

                if ($report->isSuccess()) {
                    $success++;
                    continue;
                }

                if ($report->isSubscriptionExpired()) {
                    // 404 or 410 — browser revoked / uninstalled. Purge.
                    if (isset($endpointToRowId[$endpoint])) {
                        $this->pdo->prepare(
                            'DELETE FROM push_subscriptions WHERE id = ?'
                        )->execute([$endpointToRowId[$endpoint]]);
                    }
                    continue;
                }

                error_log('Push failed: ' . $report->getReason() . ' for ' . $endpoint);
            }

            return $success;
        } catch (\Throwable $e) {
            error_log('WebPush exception: ' . $e->getMessage());
            return 0;
        }
    }

    private function emailFallback(string $userId, array $data): void
    {
        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return;

        $this->sendEmail(
            $user['email'],
            $data['title'] ?? 'Notification',
            $data['body']  ?? ''
        );
    }

    private function sendEmail(string $to, string $subject, string $body): void
    {
        // Swap with PHPMailer/SMTP for production deliverability.
        $headers = "From: " . SMTP_FROM . "\r\n"
                 . "Content-Type: text/plain; charset=utf-8\r\n";
        @mail($to, $subject, $body, $headers);
    }

    private function titleFor(string $type): string
    {
        switch ($type) {
            case 'new_assignment':        return 'New job assigned';
            case 'reassigned':            return 'Job reassigned';
            case 'job_accepted':          return 'Job accepted';
            case 'job_started':           return 'Job started';
            case 'job_completed':         return 'Job completed';
            case 'job_cancelled':         return 'Job cancelled';
            case 'assignment_escalation': return '⚠️ Manual assignment needed';
            case 'payment_received':      return 'Payment received';
            default:                      return 'Update';
        }
    }

    private function uuidv4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
?>