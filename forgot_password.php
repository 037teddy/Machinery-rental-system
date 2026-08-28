<?php
require_once '../config/db.php';
require_once '../includes/session.php';
require_once '../config/mailer.php';

$message = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $otp = sprintf('%06d', random_int(0, 999999));
            $pdo->prepare('UPDATE users SET otp_hash = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?')
                ->execute([password_hash($otp, PASSWORD_DEFAULT), $user['id']]);
            $sent = send_app_email($email, 'Machinery Rental password reset code', '<p>Hello ' . htmlspecialchars($user['full_name']) . ',</p><p>Your password reset code is:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . $otp . '</p><p>This code expires in 15 minutes.</p>');
            if ($sent) {
                $_SESSION['pending_otp_user_id'] = (int) $user['id'];
                $_SESSION['pending_otp_email'] = $email;
                $_SESSION['pending_otp_context'] = 'reset';
                header('Location: otp_verify.php');
                exit;
            }
        }
        $message = 'If that email exists, a reset code has been sent. Check your inbox.';
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Forgot Password - Machinery Rental</title><link rel="stylesheet" href="../assets/css/style.css"></head><body><main class="auth-container"><h2>Forgot Password?</h2><p>Enter your account email and we will send a reset code.</p><?php if ($message): ?><div class="alert alert-error"><?= htmlspecialchars($message) ?></div><?php endif; ?><form method="POST"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required><button type="submit">Send reset code</button></form><p><a href="login.php">Back to login</a></p></main></body></html>
