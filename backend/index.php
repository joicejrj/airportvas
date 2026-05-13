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
require_once BASE_PATH . '/controllers/UserController.php';

// Helpers
require_once BASE_PATH . '/helpers/AssignmentEngine.php';
require_once BASE_PATH . '/helpers/NotificationService.php';
require_once BASE_PATH . '/helpers/OrderNumber.php';

require_once BASE_PATH . '/../vendor/autoload.php';  // Stripe SDK

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
    PushController,
    UserController
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
$router->get('/api/orders/summary', [OrderController::class, 'summary']);
$router->get('/api/orders/{id}',                           [OrderController::class, 'show']);
$router->patch('/api/orders/{id}/services/{svcId}/assign', [OrderController::class, 'manualAssign']);

// Payments
$router->get('/api/orders/{id}/payments',  [PaymentController::class, 'index']);
$router->post('/api/orders/{id}/payments', [PaymentController::class, 'store']);
$router->get('/api/payments',              [PaymentController::class, 'adminList']);
$router->post('/api/orders/{id}/payments/{paymentId}/reverse', [PaymentController::class, 'reverse']);

// Provider job queue (PWA endpoints — for providers)
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

// Users (admin only)
$router->get('/api/users/agents', [AuthController::class, 'listAgents']);
$router->get('/api/users',          [UserController::class, 'index']);
$router->get('/api/users/{id}',     [UserController::class, 'show']);
$router->post('/api/users',         [UserController::class, 'store']);
$router->put('/api/users/{id}',     [UserController::class, 'update']);
$router->delete('/api/users/{id}',  [UserController::class, 'destroy']);

// Providers list w/ stats (admin)
$router->get('/api/providers',      [UserController::class, 'providers']);
$router->get('/api/providers/for-service/{svcId}', [UserController::class, 'providersForService']);

// Reports
$router->get('/api/reports/daily',      [ReportController::class, 'daily']);
$router->get('/api/reports/agent/{id}', [ReportController::class, 'agentReport']);

// Customer booking (public)
$router->post('/api/customer/booking',          [CustomerController::class, 'createBooking']);
$router->get('/api/customer/booking/{id}',      [CustomerController::class, 'getBooking']);
$router->post('/api/customer/payment/callback', [CustomerController::class, 'paymentCallback']); // webhook - not using
$router->post('/api/customer/verify-payment', [CustomerController::class, 'verifyPayment']);
$router->post('/api/customer/pay-existing', [CustomerController::class, 'payExistingOrder']);
$router->get('/api/customer/order-preview/{id}', [CustomerController::class, 'orderPreview']); // for public payment - to view order details
$router->post('/api/customer/upload', [CustomerController::class, 'uploadImage']);

// Push subscriptions
$router->post('/api/push/subscribe',   [PushController::class, 'subscribe']);
$router->delete('/api/push/subscribe', [PushController::class, 'unsubscribe']);

// Stripe Payment Link
$router->post('/api/customer/stripe-link', [CustomerController::class, 'stripeLink']);
// $router->post('/api/customer/link-pay', [CustomerController::class, 'linkPay']);

$router->get('/api/admin/assignment-stats', [ProviderController::class, 'assignmentStats']);

// Cron: assignment timeout processor (internal, secured by secret header)
$router->post('/api/cron/process-timeouts', function () {
    // Accept either the cron secret header (for scheduled jobs)
    // OR an authenticated admin (for the manual button in the UI)
    $secret = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    $isCron = $secret !== '' && hash_equals(APP_SECRET, $secret);

    if (!$isCron) {
        \App\Middleware\AuthMiddleware::require(['admin']);
    }

    $engine = new \App\Helpers\AssignmentEngine();
    $count  = $engine->processTimeouts();
    \App\Core\Response::success(['processed' => $count]);
});

$router->dispatch();