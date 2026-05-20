<?php
// payments_diag.php
//
// Drop into backend/ and open in browser:
//   https://airportvas.jrjapp.com/backend/payments_diag.php?order=310f06b9-4f70-11f1-8abf-0201e541d724
//
// Shows the full payment picture for one order so we can see exactly
// why the success page thinks it's unpaid.
//
// DELETE AFTER USE.

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/bootstrap.php';

use App\Config\Database;

$orderId = $_GET['order'] ?? '';
if (!$orderId) {
    echo "Pass ?order=<uuid> in the URL.\n";
    exit;
}

$pdo = Database::getInstance();

echo "═════════════════════════════════════════════════════════════════\n";
echo " ORDER\n";
echo "═════════════════════════════════════════════════════════════════\n";
$o = $pdo->prepare(
    'SELECT id, order_number, vehicle_plate, total_amount, paid_amount,
            payment_status, source, tracking_token, stripe_session_id, created_at
     FROM orders WHERE id = ?'
);
$o->execute([$orderId]);
$order = $o->fetch();
if (!$order) {
    echo "  No such order.\n";
    exit;
}
foreach ($order as $k => $v) {
    printf("  %-22s %s\n", $k, $v ?? '(null)');
}

echo "\n═════════════════════════════════════════════════════════════════\n";
echo " PAYMENTS (rows in payments table for this order)\n";
echo "═════════════════════════════════════════════════════════════════\n";
$p = $pdo->prepare(
    'SELECT id, payment_method, amount, transaction_ref, status, recorded_by, created_at
     FROM payments WHERE order_id = ? ORDER BY created_at DESC'
);
$p->execute([$orderId]);
$rows = $p->fetchAll();
if (!$rows) {
    echo "  ⚠ NO PAYMENT ROWS — verify-payment never inserted one.\n";
    echo "  Likely cause: the success page didn't call verify-payment,\n";
    echo "  or the Stripe Session::retrieve() failed (check error_log).\n";
} else {
    foreach ($rows as $r) {
        echo "  ────────────────────────────────────────\n";
        foreach ($r as $k => $v) printf("  %-18s %s\n", $k, $v ?? '(null)');
    }
    $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ? AND status = 'success'");
    $sum->execute([$orderId]);
    echo "\n  Sum of SUCCESS payments: " . $sum->fetchColumn() . "\n";
    echo "  Order.paid_amount:       " . $order['paid_amount'] . "\n";
    echo "  Order.total_amount:      " . $order['total_amount'] . "\n";
    if ($sum->fetchColumn() == 0) { /* re-fetch */ }
    $sum->execute([$orderId]);
    $paid = (float)$sum->fetchColumn();
    $total = (float)$order['total_amount'];
    if ($paid > 0 && $order['payment_status'] !== 'paid') {
        echo "  ⚠ MISMATCH — payments exist but order is still '{$order['payment_status']}'.\n";
        echo "    The recomputeOrderPayment() update never ran or was rolled back.\n";
    }
}

echo "\n═════════════════════════════════════════════════════════════════\n";
echo " STRIPE SESSION (live check)\n";
echo "═════════════════════════════════════════════════════════════════\n";
if (!$order['stripe_session_id']) {
    echo "  ⚠ Order has NO stripe_session_id.\n";
    echo "  That means createCheckoutSession() failed at booking time —\n";
    echo "  check error_log for 'Stripe Checkout session failed'.\n";
} elseif (!class_exists('\Stripe\Stripe')) {
    echo "  ⚠ Stripe SDK not autoloaded — vendor/autoload.php not found.\n";
    echo "  Check that backend/bootstrap.php finds vendor/autoload.php.\n";
} elseif (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    echo "  ⚠ STRIPE_SECRET_KEY not configured.\n";
} else {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    try {
        $s = \Stripe\Checkout\Session::retrieve($order['stripe_session_id']);
        echo "  session.id:             " . $s->id . "\n";
        echo "  session.status:         " . $s->status . "\n";
        echo "  session.payment_status: " . $s->payment_status . "\n";
        echo "  session.amount_total:   " . $s->amount_total . " (" . ($s->amount_total/100) . " " . strtoupper($s->currency) . ")\n";
        echo "  session.metadata:       " . json_encode($s->metadata) . "\n";

        if ($s->payment_status === 'paid' && $order['payment_status'] !== 'paid') {
            echo "\n  ⚠ STRIPE SAYS PAID, BUT ORDER SAYS UNPAID.\n";
            echo "    verify-payment never ran, or it failed silently.\n";
            echo "    Click the link below to force-record the payment now:\n";
            $self = strtok($_SERVER['REQUEST_URI'], '?');
            echo "    {$self}?order={$orderId}&fix=1\n";
        }

        if ($s->payment_status === 'paid' && ($_GET['fix'] ?? '') === '1') {
            echo "\n  ─── FORCING PAYMENT RECORD ────────────────────────\n";
            $amountPaid = (int)$s->amount_total / 100;
            $sessionId  = $s->id;
            try {
                $pdo->beginTransaction();
                $dup = $pdo->prepare('SELECT id FROM payments WHERE transaction_ref = ? FOR UPDATE');
                $dup->execute([$sessionId]);
                if ($dup->fetch()) {
                    echo "  Payment row already existed — just recomputing order.\n";
                } else {
                    $pdo->prepare(
                        'INSERT INTO payments (id, order_id, payment_method, amount, transaction_ref, uuid_ref,
                                                status, recorded_by, created_at)
                         VALUES (UUID(), ?, "online", ?, ?, UUID(), "success", NULL, UTC_TIMESTAMP())'
                    )->execute([$orderId, $amountPaid, $sessionId]);
                    echo "  Inserted payment row for AED $amountPaid.\n";
                }
                // Recompute
                $sum = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ? AND status = "success"');
                $sum->execute([$orderId]);
                $paid  = (float)$sum->fetchColumn();
                $total = (float)$order['total_amount'];
                $status = 'unpaid';
                if ($paid >= $total - 0.005 && $total > 0) $status = 'paid';
                elseif ($paid > 0) $status = 'partial';
                $pdo->prepare(
                    'UPDATE orders SET paid_amount = ?, payment_status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
                )->execute([$paid, $status, $orderId]);
                $pdo->commit();
                echo "  Order now: paid_amount=$paid, payment_status=$status.\n";
                echo "  ✓ Done. Reload the /pay/success/ page.\n";
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo "  ✗ Failed: " . $e->getMessage() . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  ✗ Stripe error: " . $e->getMessage() . "\n";
    }
}

echo "\n═════════════════════════════════════════════════════════════════\n";
echo " DELETE THIS FILE AFTER USE\n";
echo "═════════════════════════════════════════════════════════════════\n";
