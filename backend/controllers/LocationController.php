<?php
// backend/controllers/LocationController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * Parking locations CRUD (UAE airports).
 *
 *   GET    /api/locations         index()
 *   GET    /api/locations/{id}    show()
 *   POST   /api/locations         store()    — admin
 *   PUT    /api/locations/{id}    update()   — admin
 *   DELETE /api/locations/{id}    destroy()  — admin (soft delete)
 */
class LocationController
{
    public function index(array $params = []): void
    {
        $pdo  = Database::getInstance();
        $where = [];
        $bind  = [];
        $isAdmin = false;
        try {
            $u = AuthMiddleware::optional();
            $isAdmin = ($u && $u['role'] === 'admin');
        } catch (\Throwable $e) {}

        if (!$isAdmin) $where[] = 'is_active = 1';

        if (!empty($_GET['airport'])) {
            $where[] = 'airport = ?';
            $bind[]  = (string)$_GET['airport'];
        }
        if (!empty($_GET['terminal'])) {
            $where[] = 'terminal = ?';
            $bind[]  = (string)$_GET['terminal'];
        }

        $sql = 'SELECT * FROM parking_locations'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY airport ASC, terminal ASC, name ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        Response::success($stmt->fetchAll());
    }

    public function show(array $params): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM parking_locations WHERE id = ?');
        $stmt->execute([$params['id']]);
        $row = $stmt->fetch();
        if (!$row) Response::error('Location not found', 404);
        Response::success($row);
    }

    public function store(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $req  = new Request();
        $name = trim((string)$req->input('name', ''));
        if (!$name) Response::error('name is required', 422);

        $pdo = Database::getInstance();
        $id  = $pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO parking_locations
             (id, name, code, zone, capacity, description, terminal, airport, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            $id, $name,
            (string)($req->input('code', '')),
            $req->input('zone'),
            (int)($req->input('capacity') ?? 0),
            $req->input('description'),
            $req->input('terminal'),
            (string)($req->input('airport', 'DXB')),
        ]);
        Response::json(['success'=>true, 'message'=>'Location created', 'id'=>$id], 201);
    }

    public function update(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $req  = new Request();
        $pdo  = Database::getInstance();

        $exists = $pdo->prepare('SELECT id FROM parking_locations WHERE id = ?');
        $exists->execute([$params['id']]);
        if (!$exists->fetch()) Response::error('Location not found', 404);

        $map = [
            'name'        => 'name',
            'code'        => 'code',
            'zone'        => 'zone',
            'capacity'    => 'capacity',
            'description' => 'description',
            'terminal'    => 'terminal',
            'airport'     => 'airport',
            'is_active'   => 'is_active',
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
        $pdo->prepare('UPDATE parking_locations SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($vals);
        Response::success(null, 'Location updated');
    }

    public function destroy(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE parking_locations SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$params['id']]);
        if ($stmt->rowCount() === 0) Response::error('Location not found', 404);
        Response::success(null, 'Location deactivated');
    }
}