<?php
require_once '../config/db.php';
require_once '../includes/session.php';
require_once '../config/mailer.php';

$errors = [];
$email = '';

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role, approval_status FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['role'] === 'operator' && $user['approval_status'] !== 'approved') {
                $errors[] = $user['approval_status'] === 'rejected'
                    ? 'Your operator account was rejected. Please contact the administrator.'
                    : 'Your operator account is awaiting admin approval.';
            } else {
            $otp = sprintf('%06d', random_int(0, 999999));
            $stmt = $pdo->prepare(
                'UPDATE users SET otp_hash = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?'
            );
            $stmt->execute([password_hash($otp, PASSWORD_DEFAULT), $user['id']]);

            $sent = send_app_email(
                $user['email'],
                'Your Machinery Rental login code',
                '<p>Hello ' . htmlspecialchars($user['full_name']) . ',</p><p>Your login verification code is:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . $otp . '</p><p>This code expires in 15 minutes.</p>'
            );

            if ($sent) {
                $_SESSION['pending_otp_user_id'] = (int) $user['id'];
                $_SESSION['pending_otp_email'] = $user['email'];
                $_SESSION['pending_otp_context'] = 'login';
                header('Location: otp_verify.php');
                exit;
            }

            $errors[] = 'We could not send the verification code. Please try again.';
            }
        } else {
            $errors[] = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Machinery Rental System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <main class="auth-layout">
        <section class="auth-showcase" aria-label="Machinery rental showcase">
            <div class="auth-slide"><img src="../assets/images/excavator.jpg" alt="Excavator ready for rental"><span>Power your next project</span></div>
            <div class="auth-slide"><img src="../assets/images/tractor.jpg" alt="Tractor ready for rental"><span>Reliable equipment, ready when you are</span></div>
            <div class="auth-slide"><img src="../assets/images/forklift.jpg" alt="Forklift ready for rental"><span>Safe machinery with company support</span></div>
            <div class="auth-showcase-content"><strong>Machinery Rental</strong><p>Verified equipment. Flexible dates. Dependable service.</p></div>
        </section>
        <div class="auth-container">
        <h2>Log In</h2>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Log In</button>
        </form>

        <p><a href="forgot_password.php">Forgot your password?</a></p>
        <p>Don't have an account? <a href="register.php">Register</a></p>
        </div>
    </main>
</body>
</html>