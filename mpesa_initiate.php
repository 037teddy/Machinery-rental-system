<?php
require_once '../config/db.php';
require_once '../includes/session.php';
require_once '../config/mpesa.php';

require_login();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$booking_id = (int) ($data['booking_id'] ?? 0);
$phone_input = trim($data['phone_number'] ?? '');
$user_id = (int) $_SESSION['user_id'];

if (!$booking_id || !$phone_input) {
    echo json_encode(['success' => false, 'error' => 'Booking and M-Pesa phone number are required.']);
    exit;
}

$phone = normalize_mpesa_phone($phone_input);
if (!$phone) {
    echo json_encode(['success' => false, 'error' => 'Use a valid Kenyan number such as 0712345678 or +254712345678.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT b.id, b.total_price, b.status, u.full_name
     FROM bookings b JOIN users u ON u.id = b.user_id
     WHERE b.id = ? AND b.user_id = ?'
);
$stmt->execute([$booking_id, $user_id]);
$booking = $stmt->fetch();
if (!$booking || $booking['status'] !== 'pending') {
    echo json_encode(['success' => false, 'error' => 'This booking is not awaiting payment.']);
    exit;
}

if (!$mpesa_api_key || !filter_var($mpesa_callback_url, FILTER_VALIDATE_URL) || str_contains($mpesa_callback_url, 'YOUR-PUBLIC-DOMAIN')) {
    echo json_encode(['success' => false, 'error' => 'M-Pesa callback URL is not configured with a public HTTPS domain.']);
    exit;
}

$external_reference = 'MACH-' . str_pad((string) $booking_id, 8, '0', STR_PAD_LEFT) . '-' . time();
$payload = [
    'amount' => (int) ceil((float) $booking['total_price']),
    'phone_number' => $phone,
    'external_reference' => $external_reference,
    'customer_name' => $booking['full_name'],
    'callback_url' => $mpesa_callback_url,
];

$stmt = $pdo->prepare(
    "SELECT id FROM payments WHERE booking_id = ? AND status = 'pending' LIMIT 1"
);
$stmt->execute([$booking_id]);
$existing_payment = $stmt->fetchColumn();
if ($existing_payment) {
    $pdo->prepare('UPDATE payments SET amount = ?, method = \'mpesa\', phone_number = ?, external_reference = ?, failure_reason = NULL WHERE id = ?')
        ->execute([$booking['total_price'], $phone, $external_reference, $existing_payment]);
} else {
    $pdo->prepare("INSERT INTO payments (booking_id, amount, method, phone_number, external_reference, status) VALUES (?, ?, 'mpesa', ?, ?, 'pending')")
        ->execute([$booking_id, $booking['total_price'], $phone, $external_reference]);
}

$ch = curl_init($mpesa_api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $mpesa_api_key],
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error || !$response) {
    echo json_encode(['success' => false, 'error' => 'Could not reach the M-Pesa service.']);
    exit;
}
$api_response = json_decode($response, true);
if (!is_array($api_response)) {
    echo json_encode(['success' => false, 'error' => 'The M-Pesa service returned an invalid response.']);
    exit;
}

if (($http_code !== 200 && $http_code !== 201) || ($api_response['success'] ?? false) !== true) {
    $error = $api_response['message'] ?? $api_response['error'] ?? 'M-Pesa payment initiation failed.';
    $pdo->prepare("UPDATE payments SET status = 'failed', failure_reason = ? WHERE booking_id = ? AND status = 'pending'")
        ->execute([substr($error, 0, 255), $booking_id]);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

$checkout_id = $api_response['checkout_request_id'] ?? null;
$pdo->prepare('UPDATE payments SET checkout_request_id = ? WHERE booking_id = ? AND external_reference = ?')
    ->execute([$checkout_id, $booking_id, $external_reference]);

echo json_encode(['success' => true, 'message' => 'M-Pesa prompt sent. Enter your PIN on your phone.', 'booking_id' => $booking_id, 'checkout_request_id' => $checkout_id]);
