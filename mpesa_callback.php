<?php
require_once '../config/db.php';

header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$result = $payload['result'] ?? [];
$checkout_id = $payload['checkout_request_id'] ?? null;
$external_reference = $payload['external_reference'] ?? null;
$transaction_id = $payload['transaction_id'] ?? null;
$status = strtolower((string) ($payload['status'] ?? ''));
$success = ($payload['success'] ?? false) === true;
$result_code = isset($result['ResultCode']) ? (int) $result['ResultCode'] : (isset($payload['ResultCode']) ? (int) $payload['ResultCode'] : null);
$receipt = $result['MpesaReceiptNumber'] ?? $payload['MpesaReceiptNumber'] ?? $transaction_id;
$failure_reason = $result['ResultDesc'] ?? $payload['ResultDesc'] ?? 'Payment failed or was cancelled.';

$stmt = $pdo->prepare(
    'SELECT p.id, p.booking_id, p.status AS payment_status, b.total_price
     FROM payments p JOIN bookings b ON b.id = p.booking_id
     WHERE (? IS NOT NULL AND p.checkout_request_id = ?)
        OR (? IS NOT NULL AND p.external_reference = ?)
     ORDER BY p.id DESC LIMIT 1'
);
$stmt->execute([$checkout_id, $checkout_id, $external_reference, $external_reference]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Payment reference not found']);
    exit;
}
if ($payment['payment_status'] === 'paid') {
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Already processed']);
    exit;
}

$paid = $result_code === 0 || ($success && $status === 'completed' && $receipt);
try {
    $pdo->beginTransaction();
    if ($paid) {
        $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW(), transaction_reference = ?, failure_reason = NULL WHERE id = ?")
            ->execute([$receipt ?: $checkout_id ?: $transaction_id, $payment['id']]);
        $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'pending'")
            ->execute([$payment['booking_id']]);
    } else {
        $pdo->prepare("UPDATE payments SET status = 'failed', failure_reason = ? WHERE id = ? AND status = 'pending'")
            ->execute([substr($failure_reason, 0, 255), $payment['id']]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('M-Pesa callback error: ' . $exception->getMessage());
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => $paid ? 'Payment processed' : 'Payment failed']);
