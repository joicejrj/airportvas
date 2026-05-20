<?php
// backend/controllers/EmployeeController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;
use App\Helpers\ImageStore;

/**
 * Employee management.
 *
 *   GET    /api/employees                  index()   — admin list with filters
 *   GET    /api/employees/lookup?code=…    lookup()  — TL searches their team by code
 *   GET    /api/employees/{id}             show()    — admin or TL (own team only)
 *   POST   /api/employees                  store()   — admin create
 *   PUT    /api/employees/{id}             update()  — admin edit
 *   DELETE /api/employees/{id}             destroy() — admin soft-delete
 *
 * Important: `lookup` is the endpoint the TL PWA hits when the
 * Team Leader types an employee code in the "New Order" wizard.
 * It only returns employees in the TL's own team + own service.
 */
class EmployeeController
{
    // =================================================================
    // GET /api/employees
    // =================================================================
    /**
     * Admin-only listing with filters.
     * Query params:
     *   service_id     filter by service
     *   team_leader_id filter by TL
     *   q              search code/name/phone
     *   active         "1" or "0"
     *   page, limit    pagination
     */
    public function index(array $params = []): void
    {
        AuthMiddleware::require(['admin']);

        $pdo   = Database::getInstance();
        $where = [];
        $bind  = [];

        if (!empty($_GET['service_id'])) {
            $where[] = 'e.service_id = ?';
            $bind[]  = (string)$_GET['service_id'];
        }
        if (!empty($_GET['team_leader_id'])) {
            $where[] = 'e.team_leader_id = ?';
            $bind[]  = (string)$_GET['team_leader_id'];
        }
        if (isset($_GET['active']) && $_GET['active'] !== '') {
            $where[] = 'e.is_active = ?';
            $bind[]  = (int)$_GET['active'];
        }
        if (!empty($_GET['q'])) {
            $q = '%' . trim((string)$_GET['q']) . '%';
            $where[] = '(e.employee_code LIKE ? OR e.name LIKE ? OR e.phone LIKE ?)';
            array_push($bind, $q, $q, $q);
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Pagination
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        // Total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e $whereSQL");
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        // Data
        $sql = "
            SELECT e.id, e.employee_code, e.name, e.phone, e.emirates_id,
                   e.image_path,
                   e.is_active, e.created_at,
                   e.service_id,
                   s.name AS service_name,
                   e.team_leader_id,
                   tl.name AS team_leader_name
              FROM employees e
              JOIN services s   ON s.id  = e.service_id
              LEFT JOIN users tl ON tl.id = e.team_leader_id
              $whereSQL
             ORDER BY e.is_active DESC, e.employee_code ASC
             LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($bind as $b) {
            $stmt->bindValue($i++, $b);
        }
        $stmt->bindValue($i++, $limit,  \PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, \PDO::PARAM_INT);
        $stmt->execute();

        Response::success([
            'items' => $stmt->fetchAll(),
            'meta'  => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => max(1, (int)ceil($total / $limit)),
            ],
        ]);
    }

    // =================================================================
    // GET /api/employees/lookup?code=EMP001
    // =================================================================
    /**
     * TL-facing employee search by code.
     *
     * Crucial scoping rules:
     *   - Only employees in the TL's own service are returned.
     *   - Only employees on the TL's own team are returned.
     *   - Only active employees are returned.
     *
     * Empty string code or no match returns 404 so the PWA can show
     * a clean "no such employee in your team" message.
     */
    public function lookup(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $code = trim((string)($_GET['code'] ?? ''));

        if ($code === '') {
            Response::error('Employee code is required', 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, employee_code, name, phone, emirates_id, image_path,
                    service_id, team_leader_id, is_active
             FROM employees
             WHERE employee_code = ?
               AND service_id    = ?
               AND team_leader_id = ?
               AND is_active     = 1
             LIMIT 1'
        );
        $stmt->execute([$code, $user['service_id'], $user['id']]);
        $emp = $stmt->fetch();

        if (!$emp) {
            Response::error('No employee in your team with that code', 404);
        }

        Response::success($emp);
    }

    // =================================================================
    // GET /api/employees/search?q=…
    // =================================================================
    /**
     * TL-facing employee search by name OR code (free-text).
     * Returns up to 10 active employees on the TL's own team in the
     * TL's own service, ordered by best match.
     *
     * Empty/short queries return an empty list (not an error) so the
     * PWA can debounce-search safely.
     */
    public function searchByName(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $q    = trim((string)($_GET['q'] ?? ''));

        // Don't fire on tiny queries — let the PWA show the empty state
        if (mb_strlen($q) < 2) {
            Response::success([]);
        }

        // Match on name OR employee_code, case-insensitive
        $like = '%' . $q . '%';

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, employee_code, name, phone, emirates_id, image_path,
                    service_id, team_leader_id, is_active
             FROM employees
             WHERE service_id     = ?
               AND team_leader_id = ?
               AND is_active      = 1
               AND (name LIKE ? OR employee_code LIKE ?)
             ORDER BY
               CASE
                 WHEN employee_code = ? THEN 0   -- exact code match first
                 WHEN name = ?          THEN 1   -- exact name match next
                 WHEN name LIKE ?       THEN 2   -- starts-with on name
                 ELSE 3
               END,
               name ASC
             LIMIT 10'
        );
        $stmt->execute([
            $user['service_id'], $user['id'],
            $like, $like,
            $q, $q, $q . '%',
        ]);

        Response::success($stmt->fetchAll());
    }

    // =================================================================
    // GET /api/employees/{id}
    // =================================================================
    public function show(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'team_leader']);
        $id   = $params['id'] ?? '';

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT e.*, s.name AS service_name, tl.name AS team_leader_name
             FROM employees e
             JOIN services s   ON s.id  = e.service_id
             LEFT JOIN users tl ON tl.id = e.team_leader_id
             WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        $emp = $stmt->fetch();

        if (!$emp) {
            Response::error('Employee not found', 404);
        }

        // TL can only view employees in their own team
        if ($user['role'] === 'team_leader'
            && $emp['team_leader_id'] !== $user['id']) {
            Response::error('Forbidden', 403);
        }

        Response::success($emp);
    }

    // =================================================================
    // POST /api/employees
    // =================================================================
    public function store(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $req = new Request();

        $code         = trim((string)$req->input('employee_code', ''));
        $name         = trim((string)$req->input('name', ''));
        $phone        = trim((string)$req->input('phone', ''));
        $emiratesId   = trim((string)$req->input('emirates_id', ''));
        $serviceId    = (string)$req->input('service_id', '');
        $teamLeaderId = $req->input('team_leader_id');
        // image_path may arrive as either a data URL (recently picked file)
        // or an already-stored path. Normalize keeps both shapes valid.
        $imagePath    = ImageStore::normalize($req->input('image_path'), 'employee');

        // Validation
        if (!$code)      Response::error('employee_code is required', 422);
        if (!$name)      Response::error('name is required', 422);
        if (!$serviceId) Response::error('service_id is required', 422);

        // Strip dashes/spaces from emirates_id before validating
        if ($emiratesId !== '') {
            $clean = preg_replace('/[\s-]/', '', $emiratesId);
            if (!preg_match('/^[0-9]{15}$/', $clean)) {
                Response::error('emirates_id must be 15 digits', 422);
            }
        }

        if ($phone !== '' && !preg_match(UAE_PHONE_REGEX, $phone)) {
            Response::error('Phone must be in +971XXXXXXXXX format', 422);
        }

        $pdo = Database::getInstance();

        // Service must exist + be active
        $svc = $pdo->prepare('SELECT id FROM services WHERE id = ? AND is_active = 1');
        $svc->execute([$serviceId]);
        if (!$svc->fetch()) {
            Response::error('Service not found or inactive', 422);
        }

        // If TL specified, must exist, be active, and have the same service
        if ($teamLeaderId) {
            $tl = $pdo->prepare(
                'SELECT id, service_id FROM users
                 WHERE id = ? AND role = "team_leader" AND is_active = 1'
            );
            $tl->execute([$teamLeaderId]);
            $tlRow = $tl->fetch();
            if (!$tlRow) {
                Response::error('Team leader not found or inactive', 422);
            }
            if ($tlRow['service_id'] !== $serviceId) {
                Response::error("Team leader's service does not match this employee's service", 422);
            }
        }

        // Unique code
        $dup = $pdo->prepare('SELECT id FROM employees WHERE employee_code = ? LIMIT 1');
        $dup->execute([$code]);
        if ($dup->fetch()) {
            Response::error('Employee code already exists', 409);
        }

        // Insert
        $id = $pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO employees
             (id, employee_code, name, phone, emirates_id, image_path,
              service_id, team_leader_id,
              is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            $id, $code, $name,
            $phone       !== '' ? $phone : null,
            $emiratesId  !== '' ? $emiratesId : null,
            $imagePath,
            $serviceId,
            $teamLeaderId ?: null,
        ]);

        Response::json([
            'success' => true,
            'message' => 'Employee created',
            'id'      => $id,
        ], 201);
    }

    // =================================================================
    // PUT /api/employees/{id}
    // =================================================================
    public function update(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $id  = $params['id'] ?? '';
        $req = new Request();
        $pdo = Database::getInstance();

        // Verify employee exists
        $existing = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
        $existing->execute([$id]);
        $emp = $existing->fetch();
        if (!$emp) {
            Response::error('Employee not found', 404);
        }

        // Collect provided fields
        $fields = [];
        $values = [];

        if ($req->has('employee_code')) {
            $code = trim((string)$req->input('employee_code'));
            if ($code === '') {
                Response::error('employee_code cannot be empty', 422);
            }
            // Unique check (excluding current row)
            $dup = $pdo->prepare('SELECT id FROM employees WHERE employee_code = ? AND id != ? LIMIT 1');
            $dup->execute([$code, $id]);
            if ($dup->fetch()) {
                Response::error('Employee code already in use', 409);
            }
            $fields[] = 'employee_code = ?';
            $values[] = $code;
        }

        if ($req->has('name')) {
            $name = trim((string)$req->input('name'));
            if ($name === '') Response::error('name cannot be empty', 422);
            $fields[] = 'name = ?';
            $values[] = $name;
        }

        if ($req->has('phone')) {
            $phone = trim((string)$req->input('phone'));
            if ($phone !== '' && !preg_match(UAE_PHONE_REGEX, $phone)) {
                Response::error('Phone must be in +971XXXXXXXXX format', 422);
            }
            $fields[] = 'phone = ?';
            $values[] = $phone !== '' ? $phone : null;
        }

        if ($req->has('emirates_id')) {
            $eid = trim((string)$req->input('emirates_id'));
            if ($eid !== '') {
                $clean = preg_replace('/[\s-]/', '', $eid);
                if (!preg_match('/^[0-9]{15}$/', $clean)) {
                    Response::error('emirates_id must be 15 digits', 422);
                }
            }
            $fields[] = 'emirates_id = ?';
            $values[] = $eid !== '' ? $eid : null;
        }

        // service_id change requires re-checking team_leader compatibility
        $newServiceId = $req->has('service_id')
                      ? (string)$req->input('service_id')
                      : $emp['service_id'];

        if ($req->has('service_id')) {
            $svc = $pdo->prepare('SELECT id FROM services WHERE id = ? AND is_active = 1');
            $svc->execute([$newServiceId]);
            if (!$svc->fetch()) {
                Response::error('Service not found or inactive', 422);
            }
            $fields[] = 'service_id = ?';
            $values[] = $newServiceId;
        }

        if ($req->has('team_leader_id')) {
            $tlId = $req->input('team_leader_id') ?: null;
            if ($tlId) {
                $tl = $pdo->prepare(
                    'SELECT id, service_id FROM users
                     WHERE id = ? AND role = "team_leader" AND is_active = 1'
                );
                $tl->execute([$tlId]);
                $tlRow = $tl->fetch();
                if (!$tlRow) {
                    Response::error('Team leader not found or inactive', 422);
                }
                if ($tlRow['service_id'] !== $newServiceId) {
                    Response::error("Team leader's service does not match employee's service", 422);
                }
            }
            $fields[] = 'team_leader_id = ?';
            $values[] = $tlId;
        }

        if ($req->has('is_active')) {
            $fields[] = 'is_active = ?';
            $values[] = (int)(bool)$req->input('is_active');
        }

        // Image path — accepts either a data URL (newly picked) or an already
        // stored path. Pass an empty string to clear the image.
        if ($req->has('image_path')) {
            $raw = $req->input('image_path');
            if ($raw === '' || $raw === null) {
                $fields[] = 'image_path = ?';
                $values[] = null;
            } else {
                $normalized = ImageStore::normalize((string)$raw, 'employee');
                $fields[] = 'image_path = ?';
                $values[] = $normalized;
            }
        }

        if (!$fields) {
            Response::error('No fields to update', 422);
        }

        $fields[] = 'updated_at = UTC_TIMESTAMP()';
        $values[] = $id;

        $sql = 'UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($values);

        Response::success(null, 'Employee updated');
    }

    // =================================================================
    // DELETE /api/employees/{id}  (soft delete)
    // =================================================================
    public function destroy(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $id  = $params['id'] ?? '';
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE employees
                SET is_active = 0, updated_at = UTC_TIMESTAMP()
              WHERE id = ?'
        );
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Employee not found', 404);
        }

        Response::success(null, 'Employee deactivated');
    }
}