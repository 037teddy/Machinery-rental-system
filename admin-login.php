<?php
require_once '../config/db.php';
require_once '../includes/session.php';

if (is_logged_in() && current_user_role() === 'admin') {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, full_name, email, password_hash, role FROM users WHERE email = ? AND role = ?'
        );
        $stmt->execute([$email, 'admin']);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['full_name'] = $admin['full_name'];
            $_SESSION['role'] = $admin['role'];
            header('Location: dashboard.php');
            exit;
        }

        $errors[] = 'Invalid administrator email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - Machinery Rental System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #edf1f3; }
        .admin-login-container { border-top: 5px solid #1f2d3d; }
        .admin-login-container h2 { color: #1f2d3d; }
        .admin-badge { display: inline-block; margin-bottom: 12px; padding: 5px 10px; border-radius: 4px; background: #e3eaf0; color: #1f2d3d; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .admin-login-container input:focus { outline: none; border-color: #1f2d3d; box-shadow: 0 0 0 3px rgba(31, 45, 61, .14); }
        .admin-login-container button { background: #1f2d3d; }
        .admin-login-container button:hover { background: #15202a; }
    </style>
</head>
<body>
    <div class="auth-container admin-login-container">
        <span class="admin-badge">Administrator access</span>
        <h2>Admin Login</h2>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="admin-login.php">
            <label for="email">Admin Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Sign In as Admin</button>
        </form>

        <p><a href="../auth/login.php">Return to general login</a></p>
    </div>
</body>
</html>
