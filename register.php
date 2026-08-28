<?php
require_once '../config/db.php';
require_once '../includes/session.php';
require_once '../config/mailer.php';

$errors = [];
$full_name = $email = $phone = '';
$role = 'customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'customer';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    } elseif (!preg_match("/^[A-Za-z]+(?:[ .'-][A-Za-z]+)*$/", $full_name)) {
        $errors[] = 'Full name may contain letters, spaces, apostrophes, and hyphens only.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if ($phone !== '' && !preg_match('/^(0\d{9}|\+254\d{9})$/', $phone)) {
        $errors[] = 'Phone must be 10 digits starting with 0 or in the format +254XXXXXXXXX.';
    }
    if (!in_array($role, ['customer', 'operator'], true)) {
        $errors[] = 'Please select a valid account type.';
    }
    if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,}$/', $password)) {
        $errors[] = 'Password must be at least 8 characters and include uppercase, lowercase, and a number.';
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    // Check email uniqueness
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    // Create account
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $otp = sprintf('%06d', random_int(0, 999999));
        $stmt = $pdo->prepare(
              'INSERT INTO users (full_name, email, password_hash, phone, role, approval_status, email_verified, otp_hash, otp_expires)
               VALUES (?, ?, ?, ?, ?, ?, 0, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
        );
           $approval_status = $role === 'operator' ? 'pending' : 'approved';
           $stmt->execute([$full_name, $email, $hash, $phone, $role, $approval_status, password_hash($otp, PASSWORD_DEFAULT)]);

        $user_id = (int) $pdo->lastInsertId();
        $sent = send_app_email(
            $email,
            'Verify your Machinery Rental account',
            '<p>Hello ' . htmlspecialchars($full_name) . ',</p><p>Your verification code is:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . $otp . '</p><p>This code expires in 15 minutes.</p>'
        );
        if (!$sent) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user_id]);
            $errors[] = 'We could not send the verification email. Please configure SMTP and try again.';
        } else {
            $_SESSION['pending_otp_user_id'] = $user_id;
            $_SESSION['pending_otp_email'] = $email;
            $_SESSION['pending_otp_context'] = 'register';
            header('Location: otp_verify.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - Machinery Rental System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <main class="auth-layout">
        <section class="auth-showcase" aria-label="Machinery rental showcase">
            <div class="auth-slide"><img src="../assets/images/bulldozer.jpg" alt="Bulldozer ready for rental"><span>Build with confidence</span></div>
            <div class="auth-slide"><img src="../assets/images/concrete-mixer.jpg" alt="Concrete mixer ready for rental"><span>Equipment that keeps work moving</span></div>
            <div class="auth-slide"><img src="../assets/images/generator.jpg" alt="Generator ready for rental"><span>Support for every stage of the job</span></div>
            <div class="auth-showcase-content"><strong>Start your rental journey</strong><p>Choose your account type and find the right machine for the job.</p></div>
        </section>
        <div class="auth-container">
        <h2>Create an Account</h2>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($full_name) ?>" pattern="[A-Za-z]+(?:[ .'-][A-Za-z]+)*" title="Letters, spaces, apostrophes, and hyphens only" required>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>

            <label for="phone">Phone (optional)</label>
            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" pattern="(0[0-9]{9}|\+254[0-9]{9})" title="Use 10 digits starting with 0 or +254 followed by 9 digits" inputmode="tel">
            <small>Use 07XXXXXXXX or +2547XXXXXXXX.</small>

            <label for="role">Account type</label>
            <select id="role" name="role" required>
                <option value="customer" <?= $role === 'customer' ? 'selected' : '' ?>>Customer</option>
                <option value="operator" <?= $role === 'operator' ? 'selected' : '' ?>>Operator</option>
            </select>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" minlength="8" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9]).{8,}" title="At least 8 characters, including uppercase, lowercase, and a number" required>
            <small>Password needs uppercase, lowercase, and a number.</small>

            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

            <button type="submit">Register</button>
        </form>

        <p>Already have an account? <a href="login.php">Log in</a></p>
        </div>
    </main>
</body>
</html>
