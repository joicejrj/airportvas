<?php
declare(strict_types=1);

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/core/Router.php';
require_once BASE_PATH . '/core/Response.php';
require_once BASE_PATH . '/middleware/AuthMiddleware.php';

// Controllers
require_once BASE_PATH . '/controllers/AuthController.php';
require_once BASE_PATH . '/controllers/SyncController.php';
require_once BASE_PATH . '/controllers/OrderController.php';
require_once BASE_PATH . '/controllers/ProviderController.php';
require_once BASE_PATH . '/controllers/CatalogueControllers.php';   // ServiceController + LocationController
require_once BASE_PATH . '/controllers/CustomerController.php';
require_once BASE_PATH . '/controllers/PushController.php';

// Helpers
require_once BASE_PATH . '/helpers/AssignmentEngine.php';
require_once BASE_PATH . '/helpers/NotificationService.php';

use App\Core\{Router, Response};
use App\Controllers\{
    AuthController,
    SyncController,
    OrderController,
    PaymentController,
    ReportController,
    ProviderController,
    ServiceController,
    LocationController,
    CustomerController,
    PushController
};

// ── CORS ─────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── ROUTER ───────────────────────────────────────────────────────
$router = new Router();

// Auth
$router->post('/api/auth/login',  [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me',      [AuthController::class, 'me']);

// Sync — offline PWA heartbeat
$router->post('/api/sync', [SyncController::class, 'handle']);

// Orders
$router->get('/api/orders',                                [OrderController::class, 'index']);
$router->get('/api/orders/{id}',                           [OrderController::class, 'show']);
$router->patch('/api/orders/{id}/services/{svcId}/assign', [OrderController::class, 'manualAssign']);

// Payments
$router->get('/api/orders/{id}/payments',  [PaymentController::class, 'index']);
$router->post('/api/orders/{id}/payments', [PaymentController::class, 'store']);

// Provider
$router->get('/api/provider/jobs',                    [ProviderController::class, 'jobs']);
$router->patch('/api/provider/jobs/{svcId}/accept',   [ProviderController::class, 'accept']);
$router->patch('/api/provider/jobs/{svcId}/reject',   [ProviderController::class, 'reject']);
$router->patch('/api/provider/jobs/{svcId}/start',    [ProviderController::class, 'start']);
$router->patch('/api/provider/jobs/{svcId}/complete', [ProviderController::class, 'complete']);

// Service catalogue
$router->get('/api/services',       [ServiceController::class, 'index']);
$router->get('/api/services/{id}',  [ServiceController::class, 'show']);
$router->post('/api/services',      [ServiceController::class, 'store']);
$router->put('/api/services/{id}',  [ServiceController::class, 'update']);

// Parking locations
$router->get('/api/locations',      [LocationController::class, 'index']);
$router->get('/api/locations/{id}', [LocationController::class, 'show']);
$router->post('/api/locations',     [LocationController::class, 'store']);
$router->put('/api/locations/{id}', [LocationController::class, 'update']);

// Reports
$router->get('/api/reports/daily',      [ReportController::class, 'daily']);
$router->get('/api/reports/agent/{id}', [ReportController::class, 'agentReport']);

// Customer booking (public)
$router->post('/api/customer/booking',          [CustomerController::class, 'createBooking']);
$router->get('/api/customer/booking/{id}',      [CustomerController::class, 'getBooking']);
$router->post('/api/customer/payment/callback', [CustomerController::class, 'paymentCallback']);

// Push subscriptions
$router->post('/api/push/subscribe',   [PushController::class, 'subscribe']);
$router->delete('/api/push/subscribe', [PushController::class, 'unsubscribe']);

// Cron: assignment timeout processor (internal, secured by secret header)
$router->post('/api/cron/process-timeouts', function () {
    $secret = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    if (!hash_equals(APP_SECRET, $secret)) {
        Response::error('Forbidden', 403);
    }
    $engine = new \App\Helpers\AssignmentEngine();
    $count  = $engine->processTimeouts();
    Response::success(['processed' => $count]);
});

$router->dispatch();
