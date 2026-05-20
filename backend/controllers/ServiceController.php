<?php
// backend/controllers/ServiceController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * Service catalogue.
 *
 *   GET    /api/services        index()    — public, only active
 *   GET    /api/services/{id}   show()     — public
 *   POST   /api/services        store()    — admin
 *   PUT    /api/services/{id}   update()   — admin
 *   DELETE /api/services/{id}   destroy()  — admin (soft delete)
 */
class ServiceController
{
    public function index(array $params = []): void
    {
        $pdo = Database::getInstance();
        $isAdmin = false;
        try {
            $u = AuthMiddleware::optional();
            $isAdmin = ($u && $u['role'] === 'admin');
        } catch (\Throwable $e) {}

        $sql = 'SELECT id, name, description, base_price AS price,
                       duration_minutes, icon, sort_order, is_active
                FROM services'
             . ($isAdmin ? '' : ' WHERE is_active = 1')
             . ' ORDER BY sort_order ASC, name ASC';
        Response::success($pdo->query($sql)->fetchAll());
    }

    public function show(array $params): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
        $stmt->execute([$params['id']]);
        $row = $stmt->fetch();
        if (!$row) Response::error('Service not found', 404);
        Response::success($row);
    }

    public function store(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $req  = new Request();
        $name  = trim((string)$req->input('name', ''));
        $price = (float)$req->input('base_price', $req->input('price', 0));

        if (!$name)        Response::error('name is required', 422);
        if ($price <= 0)   Response::error('base_price must be > 0', 422);

        $pdo = Database::getInstance();
        $id  = $pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO services (id, name, description, base_price, duration_minutes,
                                   icon, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            $id, $name,
            $req->input('description'),
            $price,
            (int)($req->input('duration_minutes') ?? 30),
            $req->input('icon') ?: 'wrench',
            (int)($req->input('sort_order') ?? 99),
        ]);

        Response::json(['success'=>true, 'message'=>'Service created', 'id'=>$id], 201);
    }

    public function update(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $req  = new Request();
        $pdo  = Database::getInstance();

        $exists = $pdo->prepare('SELECT id FROM services WHERE id = ?');
        $exists->execute([$params['id']]);
        if (!$exists->fetch()) Response::error('Service not found', 404);

        $map = [
            'name'             => 'name',
            'description'      => 'description',
            'base_price'       => 'base_price',
            'duration_minutes' => 'duration_minutes',
            'icon'             => 'icon',
            'sort_order'       => 'sort_order',
            'is_active'        => 'is_active',
        ];
        $sets = [];
        $vals = [];
        foreach ($map as $field => $col) {
            if ($req->has($field)) {
                $sets[] = "$col = ?";
                $vals[] = $req->input($field);
            }
        }
        if (!$sets) Response::error('No fields to update', 422);
        $sets[] = 'updated_at = UTC_TIMESTAMP()';
        $vals[] = $params['id'];
        $pdo->prepare('UPDATE services SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($vals);
        Response::success(null, 'Service updated');
    }

    public function destroy(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE services SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$params['id']]);
        if ($stmt->rowCount() === 0) Response::error('Service not found', 404);
        Response::success(null, 'Service deactivated');
    }
}