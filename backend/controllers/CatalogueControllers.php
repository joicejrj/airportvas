<?php
// backend/controllers/ServiceController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * Service Catalogue
 * Public for customers; agents/providers also need it for PWA seeding.
 *
 * GET  /api/services          – list all active services
 * GET  /api/services/{id}     – single service
 * POST /api/services          – create (admin only)
 * PUT  /api/services/{id}     – update (admin only)
 */
class ServiceController
{
    public function index(): void
    {
        // Public endpoint – no auth required for catalogue reads
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            'SELECT id, name, description, price, duration_minutes, icon, is_active, updated_at
             FROM services
             WHERE is_active = 1
             ORDER BY sort_order ASC, name ASC'
        );
        Response::success($stmt->fetchAll());
    }

    public function show(array $params): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
        $stmt->execute([$params['id']]);
        $svc = $stmt->fetch();

        $svc ? Response::success($svc) : Response::error('Service not found', 404);
    }

    public function store(): void
    {
        AuthMiddleware::require(['admin']);
        $req = new Request();

        $name  = trim((string)$req->input('name', ''));
        $price = (float)$req->input('price', 0);

        if (!$name) {
            Response::error('name is required', 422);
        }
        if ($price < 0) {
            Response::error('price must be >= 0', 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO services (id, name, description, price, duration_minutes, icon, is_active, sort_order, created_at, updated_at)
             VALUES (UUID(), ?, ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $name,
            $req->input('description'),
            $price,
            (int)$req->input('duration_minutes', 30),
            $req->input('icon', 'wrench'),
            (int)$req->input('sort_order', 99),
        ]);

        Response::success(['id' => $pdo->lastInsertId()], 'Service created', 201);
    }

    public function update(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $req = new Request();
        $pdo = Database::getInstance();

        // Fetch existing
        $existing = $pdo->prepare('SELECT id FROM services WHERE id = ?');
        $existing->execute([$params['id']]);
        if (!$existing->fetch()) {
            Response::error('Service not found', 404);
        }

        $fields = [];
        $values = [];

        foreach (['name', 'description', 'icon'] as $col) {
            if (($v = $req->input($col)) !== null) {
                $fields[] = "{$col} = ?";
                $values[] = trim((string)$v);
            }
        }
        foreach (['price', 'duration_minutes', 'sort_order'] as $col) {
            if (($v = $req->input($col)) !== null) {
                $fields[] = "{$col} = ?";
                $values[] = (float)$v;
            }
        }
        if (($active = $req->input('is_active')) !== null) {
            $fields[] = 'is_active = ?';
            $values[] = (int)(bool)$active;
        }

        if (!$fields) {
            Response::error('No fields to update', 422);
        }

        $fields[]   = 'updated_at = UTC_TIMESTAMP()';
        $values[]   = $params['id'];
        $pdo->prepare('UPDATE services SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($values);

        Response::success(null, 'Service updated');
    }
}


// ============================================================
// backend/controllers/LocationController.php
// ============================================================

/**
 * Parking Locations
 *
 * GET  /api/locations         – list all active locations
 * GET  /api/locations/{id}    – single location
 * POST /api/locations         – create (admin only)
 * PUT  /api/locations/{id}    – update (admin only)
 */
class LocationController
{
    public function index(): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            'SELECT id, name, code, zone, capacity, description, is_active, updated_at
             FROM parking_locations
             WHERE is_active = 1
             ORDER BY code ASC'
        );
        Response::success($stmt->fetchAll());
    }

    public function show(array $params): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM parking_locations WHERE id = ?');
        $stmt->execute([$params['id']]);
        $loc = $stmt->fetch();

        $loc ? Response::success($loc) : Response::error('Location not found', 404);
    }

    public function store(): void
    {
        AuthMiddleware::require(['admin']);
        $req  = new Request();
        $pdo  = Database::getInstance();

        $code = strtoupper(trim((string)$req->input('code', '')));
        $name = trim((string)$req->input('name', ''));

        if (!$code || !$name) {
            Response::error('name and code are required', 422);
        }

        // Unique code check
        $dup = $pdo->prepare('SELECT id FROM parking_locations WHERE code = ?');
        $dup->execute([$code]);
        if ($dup->fetch()) {
            Response::error("Location code '{$code}' already exists", 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO parking_locations (id, name, code, zone, capacity, description, is_active, created_at, updated_at)
             VALUES (UUID(), ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $name,
            $code,
            $req->input('zone'),
            (int)$req->input('capacity', 0),
            $req->input('description'),
        ]);

        Response::success(['id' => $pdo->lastInsertId()], 'Location created', 201);
    }

    public function update(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $req = new Request();
        $pdo = Database::getInstance();

        $existing = $pdo->prepare('SELECT id FROM parking_locations WHERE id = ?');
        $existing->execute([$params['id']]);
        if (!$existing->fetch()) {
            Response::error('Location not found', 404);
        }

        $fields = [];
        $values = [];

        foreach (['name', 'description', 'zone'] as $col) {
            if (($v = $req->input($col)) !== null) {
                $fields[] = "{$col} = ?";
                $values[] = trim((string)$v);
            }
        }
        if (($v = $req->input('code')) !== null) {
            $fields[] = 'code = ?';
            $values[] = strtoupper(trim((string)$v));
        }
        if (($v = $req->input('capacity')) !== null) {
            $fields[] = 'capacity = ?';
            $values[] = (int)$v;
        }
        if (($v = $req->input('is_active')) !== null) {
            $fields[] = 'is_active = ?';
            $values[] = (int)(bool)$v;
        }

        if (!$fields) {
            Response::error('No fields to update', 422);
        }

        $fields[]   = 'updated_at = UTC_TIMESTAMP()';
        $values[]   = $params['id'];
        $pdo->prepare('UPDATE parking_locations SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($values);

        Response::success(null, 'Location updated');
    }
}
