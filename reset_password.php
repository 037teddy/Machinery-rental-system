<?php
require_once '../config/db.php';
require_once '../includes/session.php';

$user_id = (int) ($_SESSION['password_reset_user_id'] ?? 0);
if (!$user_id) {
    header('Location: forgot_password.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,}$/', $password)) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, and a number.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user_id]);
        unset($_SESSION['password_reset_user_id']);
        $_SESSION['flash_success'] = 'Password changed successfully. Please log in.';
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset Password - Machinery Rental</title><link rel="stylesheet" href="../assets/css/style.css"></head><body><main class="auth-container"><h2>Choose a new password</h2><?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?><form method="POST"><label for="password">New password</label><input type="password" id="password" name="password" minlength="8" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9]).{8,}" title="At least 8 characters, including uppercase, lowercase, and a number" required><small>Password needs uppercase, lowercase, and a number.</small><label for="confirm_password">Confirm password</label><input type="password" id="confirm_password" name="confirm_password" minlength="8" required><button type="submit">Update password</button></form></main></body></html>
