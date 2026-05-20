<?php
// backend/index.php
//
// Entry point for the API. All requests hit this file via your
// web-server rewrite rules (Apache .htaccess or nginx try_files).
//
// Pipeline:
//   1. bootstrap.php — config, timezone, autoloader, error handlers
//   2. Router instance — register every endpoint
//   3. $router->dispatch() — match the request, call the controller method

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\TeamLeaderController;
use App\Controllers\EmployeeController;
use App\Controllers\HandoverController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\CustomerController;
use App\Controllers\ServiceController;
use App\Controllers\LocationController;
use App\Controllers\UserController;
use App\Controllers\ReportController;
use App\Controllers\SyncController;
use App\Controllers\UploadController;

// ─── CORS preflight short-circuit ─────────────────────────────────────
// Router::dispatch() handles OPTIONS too, but a clean global handler
// here means even unmapped paths return 204 instead of a confusing 404
// for the browser's preflight check.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . (defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : '*'));
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

$router = new Router();

// =====================================================================
// AUTH
// =====================================================================
$router->post('/api/auth/login',  [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get ('/api/auth/me',     [AuthController::class, 'me']);

// Admin lookup of TLs for filter dropdowns
$router->get ('/api/users/team-leaders', [AuthController::class, 'listTeamLeaders']);

// =====================================================================
// TEAM LEADER (the bulk of the work)
// =====================================================================
$router->get  ('/api/tl/available',                  [TeamLeaderController::class, 'available']);
$router->get  ('/api/tl/jobs',                       [TeamLeaderController::class, 'jobs']);
$router->get  ('/api/tl/jobs/{svcId}',               [TeamLeaderController::class, 'jobDetail']);
$router->get  ('/api/tl/jobs/{svcId}/slip',          [TeamLeaderController::class, 'printSlip']);
$router->patch('/api/tl/jobs/{svcId}/accept',        [TeamLeaderController::class, 'accept']);
$router->patch('/api/tl/jobs/{svcId}/reject',        [TeamLeaderController::class, 'reject']);
$router->patch('/api/tl/jobs/{svcId}/start',         [TeamLeaderController::class, 'start']);
$router->patch('/api/tl/jobs/{svcId}/complete',      [TeamLeaderController::class, 'complete']);
$router->post ('/api/tl/orders',                     [TeamLeaderController::class, 'directBook']);
$router->get  ('/api/tl/stats/today',                [TeamLeaderController::class, 'statsToday']);
$router->get  ('/api/tl/badges',                     [TeamLeaderController::class, 'badges']);

// =====================================================================
// EMPLOYEES
// =====================================================================
// Lookup route MUST come before /api/employees/{id} so {id} doesn't
// match the literal "lookup". Same for "search".
$router->get   ('/api/employees/lookup', [EmployeeController::class, 'lookup']);
$router->get   ('/api/employees/search', [EmployeeController::class, 'searchByName']);
$router->get   ('/api/employees',        [EmployeeController::class, 'index']);
$router->get   ('/api/employees/{id}',   [EmployeeController::class, 'show']);
$router->post  ('/api/employees',        [EmployeeController::class, 'store']);
$router->put   ('/api/employees/{id}',   [EmployeeController::class, 'update']);
$router->delete('/api/employees/{id}',   [EmployeeController::class, 'destroy']);

// =====================================================================
// HANDOVERS (payment handover flow)
// =====================================================================
// TL-side
$router->get  ('/api/tl/handovers/today',  [HandoverController::class, 'todayCash']);
$router->get  ('/api/tl/handovers/status', [HandoverController::class, 'myStatus']);
$router->get  ('/api/tl/handovers',        [HandoverController::class, 'myHistory']);
$router->post ('/api/tl/handovers',        [HandoverController::class, 'submit']);

// Admin-side
$router->get  ('/api/handovers',                       [HandoverController::class, 'adminList']);
$router->get  ('/api/handovers/unsettled-by-tl',       [HandoverController::class, 'unsettledByTL']);
$router->get  ('/api/handovers/export.csv',            [HandoverController::class, 'adminExport']);
$router->get  ('/api/handovers/{id}',                  [HandoverController::class, 'adminDetail']);
$router->patch('/api/handovers/{id}/confirm',          [HandoverController::class, 'adminConfirm']);
// "reject" is the new UI label for the existing dispute action — same handler.
$router->patch('/api/handovers/{id}/reject',           [HandoverController::class, 'adminDispute']);
$router->patch('/api/handovers/{id}/dispute',          [HandoverController::class, 'adminDispute']);

// Public token-confirm
$router->get  ('/api/handovers/confirm/{token}', [HandoverController::class, 'viewByToken']);
$router->post ('/api/handovers/confirm/{token}', [HandoverController::class, 'confirmByToken']);

// =====================================================================
// ORDERS
// =====================================================================
$router->get  ('/api/orders/summary',      [OrderController::class, 'summary']);
$router->get  ('/api/orders',              [OrderController::class, 'index']);
$router->get  ('/api/orders/{id}',         [OrderController::class, 'show']);
$router->patch('/api/orders/{id}/cancel',  [OrderController::class, 'cancel']);

// =====================================================================
// PAYMENTS
// =====================================================================
$router->get  ('/api/payments',                  [PaymentController::class, 'index']);
$router->get  ('/api/orders/{id}/payments',      [PaymentController::class, 'byOrder']);
$router->post ('/api/orders/{id}/payments',      [PaymentController::class, 'record']);
$router->post ('/api/orders/{id}/payment-links', [PaymentController::class, 'createPaymentLink']);

// =====================================================================
// CUSTOMER (public)
// =====================================================================
$router->post ('/api/customer/booking',            [CustomerController::class, 'booking']);
$router->get  ('/api/customer/booking/{id}',       [CustomerController::class, 'getBooking']);
$router->get  ('/api/customer/booking-by-token/{token}', [CustomerController::class, 'getBookingByToken']);
$router->post ('/api/customer/upload',             [CustomerController::class, 'customerUpload']);
$router->post ('/api/customer/verify-payment',     [CustomerController::class, 'verifyPayment']);
$router->post ('/api/customer/payment/callback',   [CustomerController::class, 'paymentCallback']);
$router->get  ('/api/customer/track/{token}',      [CustomerController::class, 'track']);
$router->get  ('/api/customer/services',           [CustomerController::class, 'listServices']);
$router->get  ('/api/customer/employees/search',   [CustomerController::class, 'searchEmployees']);
$router->get  ('/api/customer/pay-by-token/{token}', [CustomerController::class, 'payByToken']);
// Public-friendly URL — same handler. Apache rewrites /pay/go/<token>
// to backend/index.php; the router matches this exact path.
$router->get  ('/pay/go/{token}',                   [CustomerController::class, 'payByToken']);

// =====================================================================
// SERVICES (catalogue)
// =====================================================================
$router->get   ('/api/services',         [ServiceController::class, 'index']);
$router->get   ('/api/services/{id}',    [ServiceController::class, 'show']);
$router->post  ('/api/services',         [ServiceController::class, 'store']);
$router->put   ('/api/services/{id}',    [ServiceController::class, 'update']);
$router->delete('/api/services/{id}',    [ServiceController::class, 'destroy']);

// =====================================================================
// PARKING LOCATIONS
// =====================================================================
$router->get   ('/api/locations',        [LocationController::class, 'index']);
$router->get   ('/api/locations/{id}',   [LocationController::class, 'show']);
$router->post  ('/api/locations',        [LocationController::class, 'store']);
$router->put   ('/api/locations/{id}',   [LocationController::class, 'update']);
$router->delete('/api/locations/{id}',   [LocationController::class, 'destroy']);

// =====================================================================
// USERS (admin only)
// =====================================================================
$router->get   ('/api/team-leaders',     [UserController::class, 'teamLeadersWithStats']);
$router->get   ('/api/users',            [UserController::class, 'index']);
$router->get   ('/api/users/{id}',       [UserController::class, 'show']);
$router->post  ('/api/users',            [UserController::class, 'store']);
$router->put   ('/api/users/{id}',       [UserController::class, 'update']);
$router->delete('/api/users/{id}',       [UserController::class, 'destroy']);

// =====================================================================
// REPORTS
// =====================================================================
$router->get  ('/api/reports/daily',                 [ReportController::class, 'daily']);
$router->get  ('/api/reports/team-leader/{id}',      [ReportController::class, 'teamLeader']);
$router->get  ('/api/reports/employee/{id}',         [ReportController::class, 'employee']);
$router->get  ('/api/reports/payments',              [ReportController::class, 'payments']);
$router->get  ('/api/reports/orders',                [ReportController::class, 'orders']);
$router->get  ('/api/reports/by-employee',           [ReportController::class, 'byEmployee']);
$router->get  ('/api/reports/by-team-leader',        [ReportController::class, 'byTeamLeader']);
$router->get  ('/api/reports/by-service',            [ReportController::class, 'byService']);

// =====================================================================
// SYNC (offline-first PWA)
// =====================================================================
$router->post ('/api/sync', [SyncController::class, 'sync']);

// =====================================================================
// UPLOADS
// =====================================================================
$router->post ('/api/uploads/image', [UploadController::class, 'image']);

// ─── Dispatch ─────────────────────────────────────────────────────────
$router->dispatch();