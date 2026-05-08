<?php
// backend/controllers/PushController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * Web Push Subscription Management
 *
 * POST   /api/push/subscribe     – save / refresh a push subscription
 * DELETE /api/push/subscribe     – remove subscription (device logout)
 */
class PushController
{
    // POST /api/push/subscribe
    public function subscribe(): void
    {
        $user = AuthMiddleware::require(['admin', 'agent', 'provider']);
        $req  = new Request();
        $pdo  = Database::getInstance();

        // The browser PushSubscription JSON: {endpoint, keys: {p256dh, auth}}
        $endpoint = $req->input('endpoint');
        $p256dh   = $req->input('p256dh');
        $auth     = $req->input('auth');

        if (!$endpoint || !$p256dh || !$auth) {
            Response::error('endpoint, p256dh, and auth are required', 422);
        }

        // Upsert: update keys if endpoint already registered for this user
        $pdo->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 p256dh     = VALUES(p256dh),
                 auth       = VALUES(auth),
                 updated_at = UTC_TIMESTAMP()'
        )->execute([$user['id'], $endpoint, $p256dh, $auth]);

        Response::success(null, 'Subscribed', 201);
    }

    // DELETE /api/push/subscribe
    public function unsubscribe(): void
    {
        $user = AuthMiddleware::require(['admin', 'agent', 'provider']);
        $req  = new Request();
        $pdo  = Database::getInstance();

        $endpoint = $req->input('endpoint');

        if ($endpoint) {
            $pdo->prepare(
                'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?'
            )->execute([$user['id'], $endpoint]);
        } else {
            // Remove ALL subscriptions for this user (full logout)
            $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')
                ->execute([$user['id']]);
        }

        Response::success(null, 'Unsubscribed');
    }
}
