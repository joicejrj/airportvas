<?php
// backend/controllers/CustomerController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Helpers\{AssignmentEngine, NotificationService};
use App\Helpers\OrderNumber;

/**
 * Customer-facing public API (no auth required — public booking flow).
 *
 * POST /api/customer/booking              – create booking (customer QR + agent)
 * GET  /api/customer/booking/{id}         – track booking
 * POST /api/customer/payment/callback     – payment gateway callback
 */
class CustomerController
{
	/**
	 * Accepts an image_path value that may be either:
	 *   - A real /uploads/... path (returned as-is)
	 *   - A data: URI (base64) — decodes, saves to /uploads/, returns the new path
	 *   - Empty/null (returned as null)
	 *
	 * Throws on invalid mime/size. Caller should let exception propagate
	 * via Response::error.
	 */
	private function normalizeImagePath(?string $imagePath): ?string
	{
		if (empty($imagePath)) return null;

		// Already a real path — pass through
		if (str_starts_with($imagePath, '/uploads/') ||
			str_starts_with($imagePath, 'http://')   ||
			str_starts_with($imagePath, 'https://')) {
			return $imagePath;
		}

		// Data URI: decode and save as file
		if (!str_starts_with($imagePath, 'data:image/')) {
			// Not recognized — reject
			Response::error('image_path must be a /uploads path or data:image URI', 422);
		}

		// Parse: data:image/jpeg;base64,/9j/4AAQSkZ...
		if (!preg_match('#^data:image/(jpeg|jpg|png|webp|heic|heif);base64,(.+)$#i', $imagePath, $m)) {
			Response::error('Unsupported image format. Use JPEG, PNG, WebP, or HEIC', 422);
		}

		$ext       = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
		$base64Raw = $m[2];
		$binary    = base64_decode($base64Raw, true);

		if ($binary === false) {
			Response::error('Invalid base64 image data', 422);
		}

		// Size cap: 8 MB
		if (strlen($binary) > 8 * 1024 * 1024) {
			Response::error('Image too large (max 8 MB)', 422);
		}

		// Save to /uploads/YYYY/MM/
		$year   = date('Y');
		$month  = date('m');
		$relDir = "uploads/{$year}/{$month}";
		$absDir = BASE_PATH . '/../' . $relDir;

		if (!is_dir($absDir)) {
			@mkdir($absDir, 0775, true);
		}
		if (!is_writable($absDir)) {
			error_log('Upload dir not writable: ' . $absDir);
			Response::error('Server upload directory not writable', 500);
		}

		$filename = bin2hex(random_bytes(12)) . '.' . $ext;
		$absPath  = $absDir . '/' . $filename;
		$relPath  = '/' . $relDir . '/' . $filename;

		if (file_put_contents($absPath, $binary) === false) {
			error_log('file_put_contents failed: ' . $absPath);
			Response::error('Failed to save image', 500);
		}

		// Strip EXIF on JPEGs
		if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('imagecreatefromjpeg')) {
			$img = @imagecreatefromjpeg($absPath);
			if ($img) {
				imagejpeg($img, $absPath, 85);
				imagedestroy($img);
			}
		}

		return $relPath;
	}
	
    // ── POST /api/customer/booking ────────────────────────────────
	public function createBooking(): void
	{
		$req = new Request();
		$pdo = Database::getInstance();

		$plate         = strtoupper(trim((string)$req->input('vehicle_plate', '')));
		$services      = $req->input('services');

		$email         = trim((string)($req->input('customer_email') ?? $req->input('email', '')));
		$phone         = trim((string)($req->input('customer_phone') ?? $req->input('phone', '')));
		$customerName  = trim((string)$req->input('customer_name', ''));
		$source        = (string)$req->input('source', 'customer_qr');

		if (!in_array($source, ['agent', 'customer_qr', 'customer_web'], true)) {
			$source = 'customer_qr';
		}

		if (!$plate) {
			Response::error('vehicle_plate is required', 422);
		}
		if (empty($services) || !is_array($services)) {
			Response::error('At least one service is required', 422);
		}
		if ($source !== 'agent' && !$email && !$phone) {
			Response::error('email or phone is required for booking confirmation', 422);
		}

		// Photo check (porter exempt)
		$svcIds = array_column($services, 'service_id');
		if (!empty($svcIds)) {
			$placeholders = implode(',', array_fill(0, count($svcIds), '?'));
			$nameStmt = $pdo->prepare("SELECT id, name FROM services WHERE id IN ({$placeholders})");
			$nameStmt->execute($svcIds);
			$svcNames = [];
			foreach ($nameStmt->fetchAll() as $row) {
				$svcNames[$row['id']] = strtolower($row['name']);
			}
			foreach ($services as $i => $svcInput) {
				$sid  = $svcInput['service_id'] ?? '';
				$name = $svcNames[$sid] ?? '';
				if (str_contains($name, 'porter')) continue;
				if (empty($svcInput['image_path'])) {
					Response::error('A photo is required for service: ' . ($name ?: 'unknown'), 422);
				}
			}
		}

		// Pre-fetch service prices
		$placeholders = implode(',', array_fill(0, count($svcIds), '?'));
		$svcStmt = $pdo->prepare(
			"SELECT id, name, price FROM services
			 WHERE id IN ({$placeholders}) AND is_active = 1"
		);
		$svcStmt->execute($svcIds);
		$catalogue = [];
		foreach ($svcStmt->fetchAll() as $row) {
			$catalogue[$row['id']] = $row;
		}
		foreach ($svcIds as $sid) {
			if (!isset($catalogue[$sid])) {
				Response::error("Service {$sid} not found or inactive", 422);
			}
		}

		$totalAmount   = array_sum(array_column($catalogue, 'price'));
		$orderId       = $this->generateUUID();
		$trackingToken = bin2hex(random_bytes(8));

		// Resolve created_by for agent
		$createdBy = null;
		if ($source === 'agent') {
			$agentId = $req->input('agent_id');
			if ($agentId) {
				$check = $pdo->prepare(
					"SELECT id FROM users WHERE id = ? AND role IN ('agent','admin') AND is_active = 1"
				);
				$check->execute([$agentId]);
				if ($check->fetch()) $createdBy = $agentId;
			}
		}

		$serviceIdsCreated = [];
		$orderNumberOut = null;
		Database::transaction(function ($pdo) use (
			$orderId, $plate, $req, $services, $catalogue,
			$email, $phone, $customerName, $source, $createdBy, $totalAmount, $trackingToken,
			&$serviceIdsCreated, &$orderNumberOut
		) {
			$orderNumber = OrderNumber::next($pdo);
			$orderNumberOut = $orderNumber;
			$pdo->prepare(
				'INSERT INTO orders
				 (id, order_number, vehicle_plate, vehicle_make, vehicle_model,
				  customer_name, customer_phone, customer_email,
				  notes, total_amount, paid_amount, payment_status,
				  created_by, source, tracking_token, created_at, updated_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, "unpaid", ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
			)->execute([
				$orderId, $orderNumber, $plate,
				$req->input('vehicle_make'), $req->input('vehicle_model'),
				$customerName ?: null, $phone ?: null, $email ?: null,
				$req->input('notes'),
				$totalAmount, $createdBy, $source, $trackingToken,
			]);

			$svcInsert = $pdo->prepare(
				'INSERT INTO order_services
				 (id, order_id, service_id, status, price, location_details, image_path, version, created_at, updated_at)
				 VALUES (?, ?, ?, "pending", ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
			);
			foreach ($services as $svcInput) {
				$sid  = $svcInput['service_id'];
				$osId = $this->generateUUID();
				$svcInsert->execute([
					$osId, $orderId, $sid,
					$catalogue[$sid]['price'],
					$svcInput['location_details'] ?? null,
					$svcInput['image_path']       ?? null,
				]);
				$serviceIdsCreated[] = $osId;
			}
		});

		// Auto-assignment after commit (don't fail the response if it errors)
		// $engine = new AssignmentEngine();
		// foreach ($serviceIdsCreated as $osId) {
		// 	try { $engine->assign($osId); }
		//	catch (\Throwable $e) { error_log('Assign failed for ' . $osId . ': ' . $e->getMessage()); }
		// }

		// Create Stripe Checkout Session
		$checkoutUrl = $this->createStripeCheckout(
			$orderId, $trackingToken, $totalAmount, $catalogue, $customerName, $email
		);

		Response::json([
			'success'        => true,
			'order_id'       => $orderId,
			'order_number'   => $orderNumberOut,   // ← add this
			'tracking_token' => $trackingToken,
			'total_amount'   => $totalAmount,
			'service_count'  => count($services),
			'checkout_url'   => $checkoutUrl,
		], 201);
	}

	// ── Create Stripe Checkout Session ────────────────────────────
	private function createStripeCheckout(
		string $orderId,
		string $trackingToken,
		float $totalAmount,
		array $catalogue,
		string $customerName,
		string $email
	): string {
		\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

		$lineItems = [];
		foreach ($catalogue as $svc) {
			$lineItems[] = [
				'price_data' => [
					'currency'     => STRIPE_CURRENCY,
					'product_data' => ['name' => $svc['name']],
					'unit_amount'  => (int)round($svc['price'] * 100),  // cents/fils
				],
				'quantity' => 1,
			];
		}

		$sessionParams = [
			'mode'                 => 'payment',
			'payment_method_types' => ['card'],
			'line_items'           => $lineItems,
			'success_url'          => PUBLIC_BASE_URL . '/pay/success?order=' . $orderId . '&token=' . $trackingToken,
			'cancel_url'           => PUBLIC_BASE_URL . '/pay/cancel?order=' . $orderId . '&token=' . $trackingToken,
			'metadata'             => [
				'order_id'       => $orderId,
				'tracking_token' => $trackingToken,
			],
		];

		if ($email) {
			$sessionParams['customer_email'] = $email;
		}

		try {
			$session = \Stripe\Checkout\Session::create($sessionParams);
		} catch (\Throwable $e) {
			error_log('Stripe session create failed: ' . $e->getMessage());
			Response::error('Could not create payment session. Please try again.', 502);
		}

		// Persist session id for later reference
		$pdo = Database::getInstance();
		$pdo->prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?')
			->execute([$session->id, $orderId]);

		return $session->url;
	}

    // ── GET /api/customer/booking/{id}?token=... ──────────────────
	public function getBooking(array $params): void
	{
		$pdo   = Database::getInstance();
		$token = $_GET['token'] ?? '';

		if (!$token) {
			Response::error('Tracking token required', 401);
		}

		$stmt = $pdo->prepare(
			'SELECT o.id, o.vehicle_plate, o.vehicle_make, o.vehicle_model,
					o.total_amount, o.paid_amount, o.payment_status,
					o.created_at, o.notes, o.source,
					o.customer_name, o.customer_phone, o.customer_email,
					(SELECT MIN(created_at) FROM payments
					  WHERE order_id = o.id AND status = "success") AS paid_at
			 FROM orders o
			 WHERE o.id = ? AND o.tracking_token = ?'
		);
		$stmt->execute([$params['id'], $token]);
		$order = $stmt->fetch();

		if (!$order) {
			Response::error('Booking not found or invalid token', 404);
		}

		$svcs = $pdo->prepare(
			'SELECT os.id, s.name AS service_name, os.status,
					os.location_details, os.assigned_at, os.started_at, os.completed_at,
					os.image_path
			 FROM order_services os
			 JOIN services s ON s.id = os.service_id
			 WHERE os.order_id = ?
			 ORDER BY os.created_at ASC'
		);
		$svcs->execute([$params['id']]);
		$order['services'] = $svcs->fetchAll();

		Response::success($order);
	}
	
	// ── GET /api/customer/order-preview/{id} ──────────────────────
	/**
	 * Public, no-auth endpoint that returns minimal order info
	 * for the /pay/?order=X landing page. Only enough to display
	 * the amount and service. Returns nothing sensitive.
	 */
	public function orderPreview(array $params): void
	{
		$pdo = Database::getInstance();

		$stmt = $pdo->prepare(
			'SELECT o.id, o.vehicle_plate, o.total_amount, o.payment_status,
					o.customer_name
			 FROM orders o
			 WHERE o.id = ?'
		);
		$stmt->execute([$params['id']]);
		$order = $stmt->fetch();

		if (!$order) {
			Response::error('Order not found', 404);
		}

		// Fetch service names (one-line summary)
		$svc = $pdo->prepare(
			'SELECT s.name FROM order_services os
			 JOIN services s ON s.id = os.service_id
			 WHERE os.order_id = ?
			 ORDER BY os.created_at ASC'
		);
		$svc->execute([$params['id']]);
		$serviceNames = array_column($svc->fetchAll(), 'name');

		Response::success([
			'id'             => $order['id'],
			'vehicle_plate'  => $order['vehicle_plate'],
			'customer_name'  => $order['customer_name'],
			'total_amount'   => $order['total_amount'],
			'payment_status' => $order['payment_status'],
			'services'       => $serviceNames,
		]);
	}
	
	// ── POST /api/customer/upload ─────────────────────────────────
	/**
	 * Accepts a multipart file upload and stores it on disk.
	 * Returns { success, path } — `path` is what callers store in image_path.
	 *
	 * Auth: open (called by customer QR and authenticated agent).
	 */
	public function uploadImage(): void
	{
		if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
			Response::error('No image uploaded or upload error', 422);
		}

		$file = $_FILES['image'];

		// Size cap: 8 MB
		if ($file['size'] > 8 * 1024 * 1024) {
			Response::error('Image too large (max 8 MB)', 422);
		}

		// Mime sniff (don't trust the extension)
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime  = finfo_file($finfo, $file['tmp_name']);
		finfo_close($finfo);

		$allowed = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/heic' => 'heic',
			'image/heif' => 'heif',
		];
		if (!isset($allowed[$mime])) {
			Response::error('Invalid image type. Allowed: JPEG, PNG, WebP, HEIC', 422);
		}

		$ext     = $allowed[$mime];
		$year    = date('Y');
		$month   = date('m');
		$relDir  = "uploads/{$year}/{$month}";
		$absDir  = BASE_PATH . '/../' . $relDir;  // BASE_PATH = backend/

		if (!is_dir($absDir)) {
			@mkdir($absDir, 0775, true);
		}
		if (!is_writable($absDir)) {
			error_log('Upload dir not writable: ' . $absDir);
			Response::error('Server upload directory not writable', 500);
		}

		$filename = bin2hex(random_bytes(12)) . '.' . $ext;
		$absPath  = $absDir . '/' . $filename;
		$relPath  = '/' . $relDir . '/' . $filename;  // public URL path

		if (!move_uploaded_file($file['tmp_name'], $absPath)) {
			Response::error('Failed to save upload', 500);
		}

		// Optional: re-encode JPEGs to strip EXIF (location data, etc.)
		if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
			$img = @imagecreatefromjpeg($absPath);
			if ($img) {
				imagejpeg($img, $absPath, 85);
				imagedestroy($img);
			}
		}

		Response::json([
			'success' => true,
			'path'    => $relPath,
			'url'     => $relPath,
		]);
	}
	
	// ── POST /api/customer/stripe-link ────────────────────────────
	/**
	 * Generate a payment link for an existing or yet-to-be-created order.
	 * Called by the agent during the "Payment Link" flow.
	 *
	 * Input:
	 *   - order_id (optional): if provided, generate a link for this order
	 *   - else creates a minimal order first using the same shape as createBooking
	 *
	 * Returns:
	 *   { success, url, order_id }
	 */
	public function stripeLink(): void
	{
		$req = new Request();
		$pdo = Database::getInstance();

		$orderId        = $req->input('order_id');
		$orderNumberOut = null;   // initialize here so it's accessible in both branches

		// If no order_id, build one inline from the agent's data (so the agent can
		// generate a link in a single round-trip)
		if (!$orderId) {
			// Pull the agent's payload — same shape as createBooking
			$plate         = strtoupper(trim((string)$req->input('vehicle_plate', '')));
			$services      = $req->input('services');

			$email         = trim((string)($req->input('customer_email') ?? $req->input('email', '')));
			$phone         = trim((string)($req->input('customer_phone') ?? $req->input('phone', '')));
			$customerName  = trim((string)$req->input('customer_name', ''));
			$source        = (string)$req->input('source', 'agent');

			if (!$plate) {
				Response::error('vehicle_plate is required', 422);
			}
			if (empty($services) || !is_array($services)) {
				Response::error('At least one service is required', 422);
			}

			// Validate services + sum total
			$svcIds = array_column($services, 'service_id');
			$placeholders = implode(',', array_fill(0, count($svcIds), '?'));
			$svcStmt = $pdo->prepare(
				"SELECT id, price FROM services WHERE id IN ({$placeholders}) AND is_active = 1"
			);
			$svcStmt->execute($svcIds);
			$catalogue = [];
			foreach ($svcStmt->fetchAll() as $row) {
				$catalogue[$row['id']] = $row;
			}
			foreach ($svcIds as $sid) {
				if (!isset($catalogue[$sid])) {
					Response::error("Service {$sid} not found or inactive", 422);
				}
			}

			$totalAmount = array_sum(array_column($catalogue, 'price'));
			$newOrderId  = $this->generateUUID();

			// Resolve created_by for agent
			$createdBy = null;
			$agentId   = $req->input('agent_id');
			if ($agentId) {
				$check = $pdo->prepare(
					"SELECT id FROM users WHERE id = ? AND role IN ('agent','admin') AND is_active = 1"
				);
				$check->execute([$agentId]);
				if ($check->fetch()) {
					$createdBy = $agentId;
				}
			}

			// Normalize images BEFORE the transaction (Response::error inside a
			// transaction kills the script and leaves the DB in a bad state)
			$normalizedImages = [];
			foreach ($services as $i => $svcInput) {
				$normalizedImages[$i] = $this->normalizeImagePath($svcInput['image_path'] ?? null);
			}

			// Collect created service IDs so we can auto-assign after commit
			$serviceIdsCreated = [];

			Database::transaction(function ($pdo) use (
				$newOrderId, $plate, $req, $services, $normalizedImages, $catalogue,
				$email, $phone, $customerName, $createdBy, $totalAmount,
				&$orderNumberOut, &$serviceIdsCreated
			) {
				// Reserve sequential order number atomically
				$orderNumber    = OrderNumber::next($pdo);
				$orderNumberOut = $orderNumber;

				$pdo->prepare(
					'INSERT INTO orders
					 (id, order_number, vehicle_plate, vehicle_make, vehicle_model,
					  customer_name, customer_phone, customer_email,
					  notes, total_amount, paid_amount, payment_status,
					  created_by, source, created_at, updated_at)
					 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, "unpaid", ?, "agent", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
				)->execute([
					$newOrderId, $orderNumber, $plate,
					$req->input('vehicle_make'), $req->input('vehicle_model'),
					$customerName ?: null, $phone ?: null, $email ?: null,
					$req->input('notes'),
					$totalAmount, $createdBy,
				]);

				$svcInsert = $pdo->prepare(
					'INSERT INTO order_services
					 (id, order_id, service_id, status, price, location_details, image_path, version, created_at, updated_at)
					 VALUES (?, ?, ?, "pending", ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
				);
				foreach ($services as $i => $svcInput) {
					$sid  = $svcInput['service_id'];
					$osId = $this->generateUUID();
					$svcInsert->execute([
						$osId,
						$newOrderId,
						$sid,
						$catalogue[$sid]['price'],
						$svcInput['location_details'] ?? null,
						$normalizedImages[$i],
					]);
					$serviceIdsCreated[] = $osId;
				}
			});

			$orderId = $newOrderId;

			// Auto-assignment after commit (don't fail the response if it errors)
			// $engine = new AssignmentEngine();
			// foreach ($serviceIdsCreated as $osId) {
			//	try {
			//		$engine->assign($osId);
			//	} catch (\Throwable $e) {
			//		error_log('stripeLink assign failed for ' . $osId . ': ' . $e->getMessage());
			//	}
			// }
		} else {
			// Verify the order exists; also fetch its existing order_number
			$check = $pdo->prepare('SELECT order_number FROM orders WHERE id = ?');
			$check->execute([$orderId]);
			$row = $check->fetch();
			if (!$row) {
				Response::error('Order not found', 404);
			}
			$orderNumberOut = (int)$row['order_number'];
		}

		// Build the payment URL. Same-origin so QR/share works on the agent's phone.
		$base = rtrim($_SERVER['HTTP_HOST'] ?? 'localhost', '/');
		$scheme = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
		$payUrl = $scheme . '://' . $base . '/pay/?order=' . urlencode($orderId);

		Response::json([
			'success'      => true,
			'order_id'     => $orderId,
			'order_number' => $orderNumberOut,
			'url'          => $payUrl,
		]);
	}
	
	// ── POST /api/customer/verify-payment ─────────────────────────
	/**
	 * Called by the success page after Stripe redirects the customer back.
	 * Asks Stripe directly whether the checkout session was paid, and if so
	 * records the payment row (which flips orders.payment_status via trigger).
	 *
	 * Idempotent: safe to call multiple times.
	 *
	 * Body: { order_id, token }
	 */
	public function verifyPayment(): void
	{
		$req = new Request();
		$orderId = (string)$req->input('order_id', '');
		$token   = (string)$req->input('token', '');
		if (!$orderId || !$token) {
			Response::error('order_id and token are required', 422);
		}

		$pdo = Database::getInstance();
		$stmt = $pdo->prepare(
			'SELECT id, total_amount, payment_status, stripe_session_id
			 FROM orders
			 WHERE id = ? AND tracking_token = ?'
		);
		$stmt->execute([$orderId, $token]);
		$order = $stmt->fetch();
		if (!$order) {
			Response::error('Order not found or invalid token', 404);
		}

		// Already paid → nothing to do
		if ($order['payment_status'] === 'paid') {
			Response::success([
				'payment_status' => 'paid',
				'verified'       => true,
				'note'           => 'already_paid',
			]);
		}

		if (!$order['stripe_session_id']) {
			Response::success([
				'payment_status' => $order['payment_status'],
				'verified'       => false,
				'note'           => 'no_stripe_session',
			]);
		}

		// Ask Stripe directly
		\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
		try {
			$session = \Stripe\Checkout\Session::retrieve($order['stripe_session_id']);
		} catch (\Throwable $e) {
			error_log('Stripe verify failed for order ' . $orderId . ': ' . $e->getMessage());
			Response::error('Could not verify with Stripe', 502);
		}

		if ($session->payment_status !== 'paid') {
			// Customer hasn't completed checkout yet (or it failed)
			Response::success([
				'payment_status' => $order['payment_status'],
				'verified'       => false,
				'stripe_status'  => $session->payment_status,
			]);
		}

		// Stripe says paid. Record it idempotently and detect the transition.
		$sessionId          = $session->id;
		$amountPaid         = (int)$session->amount_total / 100;
		$transitionedToPaid = false;

		Database::transaction(function ($pdo) use ($orderId, $amountPaid, $sessionId, &$transitionedToPaid) {
			// Lock the order and capture state BEFORE we touch anything
			$os = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ? FOR UPDATE');
			$os->execute([$orderId]);
			$row = $os->fetch();
			if (!$row) return;
			$beforeStatus = $row['payment_status'];

			// Dedup inside the lock to prevent a race between two concurrent verify calls
			$dup = $pdo->prepare('SELECT id FROM payments WHERE transaction_ref = ? FOR UPDATE');
			$dup->execute([$sessionId]);

			if (!$dup->fetch()) {
				$uuidRef = $this->generateUUID();
				$pdo->prepare(
					'INSERT INTO payments
					 (id, order_id, payment_method, amount, transaction_ref, uuid_ref,
					  status, recorded_by, created_at)
					 VALUES (UUID(), ?, "online", ?, ?, ?, "success", NULL, UTC_TIMESTAMP())'
				)->execute([$orderId, $amountPaid, $sessionId, $uuidRef]);
			}

			// Re-read order — trigger has updated payment_status
			$os->execute([$orderId]);
			$afterStatus = $os->fetch()['payment_status'];

			if ($beforeStatus !== 'paid' && $afterStatus === 'paid') {
				$pdo->prepare('UPDATE orders SET was_paid = 1 WHERE id = ?')->execute([$orderId]);
				$transitionedToPaid = true;
			}
		});

		// Fire assignment OUTSIDE the transaction so the engine sees committed state
		if ($transitionedToPaid) {
			try {
				(new AssignmentEngine())->assignAllPendingForOrder($orderId);
			} catch (\Throwable $e) {
				error_log('Auto-assign after verify failed: ' . $e->getMessage());
			}

			// Notify customer (only on the actual transition, not on every duplicate verify call)
			try {
				(new NotificationService())->dispatch(
					$orderId,
					'payment_received',
					"Online payment of " . strtoupper(STRIPE_CURRENCY) . " {$amountPaid} confirmed"
				);
			} catch (\Throwable $e) {
				error_log('Notify failed: ' . $e->getMessage());
			}
		}

		Response::success([
			'payment_status'        => 'paid',
			'verified'              => true,
			'amount'                => $amountPaid,
			'transitioned_to_paid'  => $transitionedToPaid,
		]);
	}

    // ── POST /api/customer/payment/callback ───────────────────────
	/**
	 * Stripe webhook endpoint.
	 * Stripe POSTs JSON like { type, data: { object: { ... } } }.
	 * We listen for `checkout.session.completed`.
	 */
	public function paymentCallback(): void
	{
		\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

		$rawBody = file_get_contents('php://input');
		$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

		try {
			$event = \Stripe\Webhook::constructEvent(
				$rawBody, $signature, STRIPE_WEBHOOK_SECRET
			);
		} catch (\Stripe\Exception\SignatureVerificationException $e) {
			error_log('Stripe webhook signature failed: ' . $e->getMessage());
			Response::error('Invalid signature', 401);
		} catch (\Throwable $e) {
			error_log('Stripe webhook parse failed: ' . $e->getMessage());
			Response::error('Bad payload', 400);
		}

		if ($event->type !== 'checkout.session.completed') {
			// Acknowledge other events but do nothing
			Response::json(['received' => true]);
		}

		$session = $event->data->object;
		$orderId    = $session->metadata->order_id ?? null;
		$sessionId  = $session->id;
		$amountPaid = (int)$session->amount_total / 100;
		$paymentStatus = $session->payment_status; // "paid" on success

		if (!$orderId || $paymentStatus !== 'paid') {
			Response::json(['received' => true, 'note' => 'no_action']);
		}

		$pdo = Database::getInstance();

		// Idempotency: if a payment row for this session already exists, skip
		$dup = $pdo->prepare('SELECT id FROM payments WHERE transaction_ref = ?');
		$dup->execute([$sessionId]);
		if ($dup->fetch()) {
			Response::json(['received' => true, 'note' => 'already_processed']);
		}

		// Verify order exists
		$order = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
		$order->execute([$orderId]);
		if (!$order->fetch()) {
			error_log("Stripe webhook: order $orderId not found");
			Response::json(['received' => true, 'note' => 'order_not_found']);
		}

		// Insert payment row — trigger flips orders.payment_status to 'paid'
		$uuidRef = $this->generateUUID();
		Database::transaction(function ($pdo) use ($orderId, $amountPaid, $sessionId, $uuidRef) {
			$pdo->prepare(
				'INSERT INTO payments
				 (id, order_id, payment_method, amount, transaction_ref, uuid_ref,
				  status, recorded_by, created_at)
				 VALUES (UUID(), ?, "online", ?, ?, ?, "success", NULL, UTC_TIMESTAMP())'
			)->execute([$orderId, $amountPaid, $sessionId, $uuidRef]);
		});

		try {
			(new NotificationService())->dispatch(
				$orderId, 'payment_received',
				"Online payment of " . STRIPE_CURRENCY . " {$amountPaid} confirmed"
			);
		} catch (\Throwable $e) {
			error_log('Notify failed: ' . $e->getMessage());
		}

		Response::json(['received' => true, 'recorded' => true]);
	}
	
	// ── POST /api/customer/pay-existing ───────────────────────────
	/**
	 * Creates a Stripe Checkout Session for an order that already exists.
	 * Used by the /pay/?order=X landing page (agent-shared payment links).
	 *
	 * Body: { order_id }
	 * Returns: { success, checkout_url }
	 *
	 * Idempotency: if the order already has a recent Stripe session that's
	 * still open (not expired and not paid), we reuse it. Otherwise we make
	 * a new one.
	 */
	public function payExistingOrder(): void
	{
		$req = new Request();
		$orderId = (string)$req->input('order_id', '');

		if (!$orderId) {
			Response::error('order_id is required', 422);
		}

		$pdo = Database::getInstance();
		$stmt = $pdo->prepare(
			'SELECT id, total_amount, payment_status, tracking_token,
					customer_name, customer_email, stripe_session_id
			 FROM orders WHERE id = ?'
		);
		$stmt->execute([$orderId]);
		$order = $stmt->fetch();

		if (!$order) {
			Response::error('Order not found', 404);
		}
		if ($order['payment_status'] === 'paid') {
			Response::error('This order has already been paid', 409);
		}
		if (!$order['tracking_token']) {
			// Older orders may not have a token; generate one now
			$newToken = bin2hex(random_bytes(8));
			$pdo->prepare('UPDATE orders SET tracking_token = ? WHERE id = ?')
				->execute([$newToken, $orderId]);
			$order['tracking_token'] = $newToken;
		}

		\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

		// Try to reuse existing session if it's still usable
		if ($order['stripe_session_id']) {
			try {
				$existing = \Stripe\Checkout\Session::retrieve($order['stripe_session_id']);
				if ($existing->status === 'open' && $existing->url) {
					Response::json([
						'success'      => true,
						'checkout_url' => $existing->url,
						'reused'       => true,
					]);
				}
			} catch (\Throwable $e) {
				// Session expired/missing → fall through and create a new one
			}
		}

		// Build line items from the order's services
		$svcStmt = $pdo->prepare(
			'SELECT s.name, os.price
			 FROM order_services os
			 JOIN services s ON s.id = os.service_id
			 WHERE os.order_id = ?'
		);
		$svcStmt->execute([$orderId]);
		$services = $svcStmt->fetchAll();

		if (!$services) {
			Response::error('Order has no services to pay for', 422);
		}

		$lineItems = [];
		foreach ($services as $svc) {
			$lineItems[] = [
				'price_data' => [
					'currency'     => STRIPE_CURRENCY,
					'product_data' => ['name' => $svc['name']],
					'unit_amount'  => (int)round($svc['price'] * 100),
				],
				'quantity' => 1,
			];
		}

		$sessionParams = [
			'mode'                 => 'payment',
			'payment_method_types' => ['card'],
			'line_items'           => $lineItems,
			'success_url'          => PUBLIC_BASE_URL . '/pay/success?order=' . $orderId . '&token=' . $order['tracking_token'],
			'cancel_url'           => PUBLIC_BASE_URL . '/pay/?order=' . $orderId,
			'metadata'             => [
				'order_id'       => $orderId,
				'tracking_token' => $order['tracking_token'],
			],
		];

		if ($order['customer_email']) {
			$sessionParams['customer_email'] = $order['customer_email'];
		}

		try {
			$session = \Stripe\Checkout\Session::create($sessionParams);
		} catch (\Throwable $e) {
			error_log('Stripe session create (pay-existing) failed: ' . $e->getMessage());
			Response::error('Could not create payment session', 502);
		}

		$pdo->prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?')
			->execute([$session->id, $orderId]);

		Response::json([
			'success'      => true,
			'checkout_url' => $session->url,
		]);
	}

    // ── Helper ────────────────────────────────────────────────────
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}