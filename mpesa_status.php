<?php
require_once '../config/db.php';
require_once '../includes/session.php';
require_once '../config/mpesa.php';

require_login();
header('Content-Type: application/json');
$booking_id = (int) ($_GET['booking_id'] ?? 0);
$checkout_id = trim($_GET['checkout_request_id'] ?? '');
$user_id = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT p.*, b.total_price, b.status AS booking_status
     FROM payments p JOIN bookings b ON b.id = p.booking_id
     WHERE b.user_id = ? AND (' . ($booking_id ? 'b.id = ?' : 'p.checkout_request_id = ?') . ')
     ORDER BY p.id DESC LIMIT 1'
);
$stmt->execute([$user_id, $booking_id ?: $checkout_id]);
$payment = $stmt->fetch();
if (!$payment) {
    echo json_encode(['status' => 'unknown', 'error' => 'Payment not found.']);
    exit;
}
if ($payment['status'] === 'paid') {
    echo json_encode(['status' => 'completed', 'booking_id' => $payment['booking_id'], 'receipt' => $payment['transaction_reference']]);
    exit;
}
if ($payment['status'] === 'failed') {
    echo json_encode(['status' => 'failed', 'error' => $payment['failure_reason']]);
    exit;
}

if (!$mpesa_api_key || !$payment['checkout_request_id']) {
    echo json_encode(['status' => 'pending', 'booking_id' => $payment['booking_id']]);
    exit;
}

$url = $mpesa_base_url . '/transactions/?checkout_request_id=' . urlencode($payment['checkout_request_id']);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $mpesa_api_key, 'Accept: application/json'], CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode($response ?: '', true);
$txn = $data['data']['transaction'] ?? ($data['data'][0] ?? null);
$gateway_status = strtolower((string) ($txn['status'] ?? ''));
$receipt = $txn['result']['MpesaReceiptNumber'] ?? $txn['mpesa_receipt'] ?? $txn['transaction_id'] ?? null;

if ($http_code === 200 && $gateway_status === 'completed') {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW(), transaction_reference = ? WHERE id = ? AND status = 'pending'")
        ->execute([$receipt ?: $payment['checkout_request_id'], $payment['id']]);
    $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'pending'")
        ->execute([$payment['booking_id']]);
    $pdo->commit();
    echo json_encode(['status' => 'completed', 'booking_id' => $payment['booking_id'], 'receipt' => $receipt]);
    exit;
}
if (in_array($gateway_status, ['failed', 'cancelled'], true)) {
    $pdo->prepare("UPDATE payments SET status = 'failed', failure_reason = ? WHERE id = ? AND status = 'pending'")
        ->execute([$gateway_status, $payment['id']]);
    echo json_encode(['status' => 'failed', 'error' => 'M-Pesa payment was ' . $gateway_status . '.']);
    exit;
}
echo json_encode(['status' => 'pending', 'booking_id' => $payment['booking_id']]);
