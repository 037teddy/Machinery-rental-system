<?php
require_once '../config/db.php';
require_once '../includes/session.php';
require_once '../config/mpesa.php';

require_login();
header('Content-Type: application/json');
$user_id = (int) $_SESSION['user_id'];
if (!$mpesa_api_key) {
    echo json_encode(['checked' => 0, 'confirmed' => 0]);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.id, p.booking_id, p.checkout_request_id
     FROM payments p JOIN bookings b ON b.id = p.booking_id
     WHERE b.user_id = ? AND p.method = 'mpesa' AND p.status = 'pending'
       AND p.checkout_request_id IS NOT NULL AND p.checkout_request_id <> ''
     ORDER BY p.id DESC LIMIT 20"
);
$stmt->execute([$user_id]);
$payments = $stmt->fetchAll();
$confirmed = 0;
foreach ($payments as $payment) {
    $ch = curl_init($mpesa_base_url . '/transactions/?checkout_request_id=' . urlencode($payment['checkout_request_id']));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $mpesa_api_key, 'Accept: application/json'], CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response ?: '', true);
    $txn = $data['data']['transaction'] ?? ($data['data'][0] ?? null);
    $status = strtolower((string) ($txn['status'] ?? ''));
    $receipt = $txn['result']['MpesaReceiptNumber'] ?? $txn['mpesa_receipt'] ?? $txn['transaction_id'] ?? $payment['checkout_request_id'];
    if ($http_code === 200 && $status === 'completed') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW(), transaction_reference = ? WHERE id = ? AND status = 'pending'")->execute([$receipt, $payment['id']]);
        $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'pending'")->execute([$payment['booking_id']]);
        $pdo->commit();
        $confirmed++;
    } elseif (in_array($status, ['failed', 'cancelled'], true)) {
        $pdo->prepare("UPDATE payments SET status = 'failed', failure_reason = ? WHERE id = ? AND status = 'pending'")->execute([$status, $payment['id']]);
    }
}
echo json_encode(['checked' => count($payments), 'confirmed' => $confirmed]);
