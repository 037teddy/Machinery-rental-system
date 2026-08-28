<?php
require_once '../config/db.php';
require_once '../includes/session.php';
require_once '../config/mailer.php';

if (!isset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_email'], $_SESSION['pending_otp_context'])) {
    header('Location: register.php');
    exit;
}

$user_id = (int) $_SESSION['pending_otp_user_id'];
$email = $_SESSION['pending_otp_email'];
$context = $_SESSION['pending_otp_context'];
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['otp'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !preg_match('/^\d{6}$/', $code) || !$user['otp_hash'] || !password_verify($code, $user['otp_hash'])) {
        $error = 'Invalid verification code.';
    } elseif (!$user['otp_expires'] || strtotime($user['otp_expires']) < time()) {
        $error = 'This verification code has expired. Request a new one.';
    } else {
        $pdo->prepare('UPDATE users SET email_verified = 1, otp_hash = NULL, otp_expires = NULL WHERE id = ?')->execute([$user_id]);
        unset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_email'], $_SESSION['pending_otp_context']);

        if ($context === 'reset') {
            $_SESSION['password_reset_user_id'] = $user_id;
            header('Location: reset_password.php');
        } elseif ($context === 'login') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            if ($user['role'] === 'admin') {
                header('Location: ../admin/dashboard.php');
            } elseif ($user['role'] === 'operator') {
                header('Location: ../machinery/operator-dashboard.php');
            } else {
                header('Location: ../machinery/index.php');
            }
        } else {
            $_SESSION['flash_success'] = 'Account verified successfully. Please log in.';
            header('Location: login.php');
        }
        exit;
    }
}

if (isset($_GET['resend'])) {
    $otp = sprintf('%06d', random_int(0, 999999));
    $pdo->prepare('UPDATE users SET otp_hash = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?')
        ->execute([password_hash($otp, PASSWORD_DEFAULT), $user_id]);
    $sent = send_app_email($email, 'Your Machinery Rental verification code', '<p>Your new verification code is:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . $otp . '</p><p>This code expires in 15 minutes.</p>');
    $message = $sent ? 'A new code was sent to your email.' : 'The email could not be sent. Check SMTP configuration.';
}

$masked = preg_replace('/(^.).+(@.*$)/', '$1***$2', $email);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Verify Email - Machinery Rental</title><link rel="stylesheet" href="../assets/css/style.css"><style>body{background:#dfe7e5}.otp-card{width:min(440px,calc(100% - 24px));background:#fff;padding:38px;border-radius:12px;box-shadow:0 18px 45px #1f2d3d2e;text-align:center}.otp-card input{width:100%;padding:13px;text-align:center;font-size:24px;letter-spacing:8px;border:1px solid #ccd5d2;border-radius:5px;margin:16px 0}.otp-card button{width:100%}.otp-card a{color:#2d6a4f}.otp-error{background:#fdecea;color:#b3261e;padding:10px;margin-bottom:14px}.otp-message{background:#e6f4ea;color:#2d6a4f;padding:10px;margin-bottom:14px}</style></head>
<body><main class="otp-card"><h2>Verify your email</h2><p>Enter the 6-digit code sent to <strong><?= htmlspecialchars($masked) ?></strong>.</p><?php if ($error): ?><div class="otp-error"><?= htmlspecialchars($error) ?></div><?php endif; ?><?php if ($message): ?><div class="otp-message"><?= htmlspecialchars($message) ?></div><?php endif; ?><form method="POST"><input name="otp" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" required><button class="btn btn-primary" type="submit">Verify code</button></form><p><a href="otp_verify.php?resend=1">Resend code</a></p><p><a href="login.php">Back to login</a></p></main></body></html>
