<?php
// backend/helpers/NotificationService.php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;

class NotificationService
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * Send notification to a user via best available channel.
     */
    public function notify(string $userId, string $type, array $data): void
    {
        // Persist notification record
        $this->pdo->prepare(
            'INSERT INTO notifications (id, user_id, type, title, body, data, channel, created_at)
             VALUES (UUID(), ?, ?, ?, ?, ?, "push", UTC_TIMESTAMP())'
        )->execute([
            $userId,
            $type,
            $data['title'] ?? 'Notification',
            $data['body']  ?? null,
            json_encode($data),
        ]);

        // Fetch user push token
        $stmt = $this->pdo->prepare(
            'SELECT push_token, email, phone FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return;
        }

        if ($user['push_token']) {
            $sent = $this->sendWebPush($user['push_token'], $data);
            if ($sent) {
                $this->pdo->prepare(
                    'UPDATE notifications SET sent_at = UTC_TIMESTAMP() WHERE user_id = ? AND type = ? ORDER BY created_at DESC LIMIT 1'
                )->execute([$userId, $type]);
                return;
            }
        }

        // Fallback: email
        if ($user['email']) {
            $this->sendEmail($user['email'], $data['title'], $data['body'] ?? '');
        }
    }

    private function sendWebPush(string $subscriptionJson, array $payload): bool
    {
        $subscription = json_decode($subscriptionJson, true);
        if (!$subscription || empty($subscription['endpoint'])) {
            return false;
        }

        if (empty(VAPID_PUBLIC_KEY) || empty(VAPID_PRIVATE_KEY)) {
            error_log('VAPID keys not configured — push skipped');
            return false;
        }

        // Production: use web-push library (minishlink/web-push)
        // For standalone: raw VAPID + curl
        $endpoint = $subscription['endpoint'];
        $body     = json_encode([
            'title' => $payload['title'],
            'body'  => $payload['body'] ?? '',
            'data'  => $payload,
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/badge-96.png',
        ]);

        // Build VAPID JWT (simplified — production use minishlink/web-push)
        $jwt = $this->buildVapidJwt($endpoint);

        $headers = [
            'Content-Type: application/json',
            'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY,
            'TTL: 86400',
        ];

        if (isset($subscription['keys']['auth'], $subscription['keys']['p256dh'])) {
            // Encrypt body (use web-push library in production)
            $headers[] = 'Content-Encoding: aes128gcm';
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    private function sendEmail(string $to, string $subject, string $body): void
    {
        // Production: swap with SendGrid / SES / Mailgun
        $headers = 'From: noreply@airport-parking.com' . "\r\n";
        mail($to, $subject, $body, $headers);
    }

    /** Stub: inject SMS API (Twilio, Vonage, etc.) */
    public function sendSms(string $phone, string $message): void
    {
        // TODO: integrate SMS API
        error_log("SMS to {$phone}: {$message}");
    }

    private function buildVapidJwt(string $endpoint): string
    {
        // Simplified — use minishlink/web-push in production
        $parsed = parse_url($endpoint);
        $audience = $parsed['scheme'] . '://' . $parsed['host'];

        $header  = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = base64_encode(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => 'mailto:admin@airport-parking.com',
        ]));

        // Sign with VAPID private key (omitted — requires openssl with EC key)
        $sig = '';  // TODO: implement EC signature

        return rtrim(strtr($header, '+/', '-_'), '=') . '.'
             . rtrim(strtr($payload, '+/', '-_'), '=') . '.'
             . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    }
}
