<?php
require_once '../config/db.php';
require_once '../includes/session.php';

require_login(['admin']);

$errors = [];
$success = $_SESSION['admin_success'] ?? null;
unset($_SESSION['admin_success']);

function admin_upload_image(): array
{
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return ['no-image.jpg', null];
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] > 5 * 1024 * 1024) {
        return ['no-image.jpg', 'Image upload failed or is larger than 5 MB.'];
    }
    $info = getimagesize($_FILES['image']['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        return ['no-image.jpg', 'Please upload a JPG, PNG, WEBP, or GIF image.'];
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../assets/images/' . $filename)) {
        return ['no-image.jpg', 'The image could not be saved.'];
    }
    return [$filename, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_user_role') {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? '';
        if ($user_id === (int) $_SESSION['user_id']) {
            $errors[] = 'You cannot change your own admin role.';
        } elseif (in_array($role, ['customer', 'operator', 'admin'], true)) {
            $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->execute([$role, $user_id]);
            $_SESSION['admin_success'] = 'User role updated.';
        }
    } elseif ($action === 'update_operator_approval') {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $approval_status = $_POST['approval_status'] ?? '';
        if ($user_id === (int) $_SESSION['user_id']) {
            $errors[] = 'You cannot change your own approval status.';
        } elseif (in_array($approval_status, ['pending', 'approved', 'rejected'], true)) {
            $stmt = $pdo->prepare("UPDATE users SET approval_status = ? WHERE id = ? AND role = 'operator'");
            $stmt->execute([$approval_status, $user_id]);
            $_SESSION['admin_success'] = 'Operator approval status updated.';
        }
    } elseif ($action === 'save_machinery') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float) ($_POST['price_per_day'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['available', 'maintenance'], true) ? $_POST['status'] : 'available';
        [$image, $image_error] = admin_upload_image();
        if ($name === '' || $type === '' || $price <= 0 || $image_error) {
            $errors[] = $image_error ?: 'Name, type, and a valid price are required.';
        } elseif ($id) {
            if ($image === 'no-image.jpg') {
                $stmt = $pdo->prepare('UPDATE machinery SET name = ?, type = ?, description = ?, price_per_day = ?, status = ? WHERE id = ?');
                $stmt->execute([$name, $type, $description, $price, $status, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE machinery SET name = ?, type = ?, description = ?, price_per_day = ?, status = ?, image = ? WHERE id = ?');
                $stmt->execute([$name, $type, $description, $price, $status, $image, $id]);
            }
            $_SESSION['admin_success'] = 'Machinery updated.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO machinery (name, type, description, price_per_day, status, image) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $type, $description, $price, $status, $image]);
            $_SESSION['admin_success'] = 'Machinery added.';
        }
    } elseif ($action === 'delete_machinery') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            try {
                $pdo->beginTransaction();
                $booking_ids = $pdo->prepare('SELECT id FROM bookings WHERE machinery_id = ?');
                $booking_ids->execute([$id]);
                $ids = array_column($booking_ids->fetchAll(), 'id');
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM payments WHERE booking_id IN ($placeholders)")->execute($ids);
                    $pdo->prepare("DELETE FROM bookings WHERE machinery_id = ?")->execute([$id]);
                }
                $pdo->prepare('DELETE FROM reviews WHERE machinery_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM machinery WHERE id = ?')->execute([$id]);
                $pdo->commit();
                $_SESSION['admin_success'] = 'Machinery deleted.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Machinery could not be deleted.';
            }
        }
    } elseif ($action === 'update_booking') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['pending', 'confirmed', 'active', 'completed', 'cancelled'], true)) {
            $stmt = $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?');
            $stmt->execute([$status, $booking_id]);
            $_SESSION['admin_success'] = 'Booking status updated.';
        }
    } elseif ($action === 'update_payment') {
        $payment_id = (int) ($_POST['payment_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['pending', 'paid', 'failed', 'refunded'], true)) {
            $stmt = $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?');
            $stmt->execute([$status, $payment_id]);
            $_SESSION['admin_success'] = 'Payment status updated.';
        }
    }

    if (!$errors) {
        header('Location: dashboard.php');
        exit;
    }
}

$summary = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'machines' => (int) $pdo->query('SELECT COUNT(*) FROM machinery')->fetchColumn(),
    'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'revenue' => (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'")->fetchColumn(),
];
$users = $pdo->query('SELECT id, full_name, email, phone, role, approval_status, created_at FROM users ORDER BY created_at DESC')->fetchAll();
$machines = $pdo->query('SELECT * FROM machinery ORDER BY type, name')->fetchAll();
$bookings = $pdo->query(
    'SELECT b.*, u.full_name AS customer_name, m.name AS machine_name
     FROM bookings b JOIN users u ON u.id = b.user_id JOIN machinery m ON m.id = b.machinery_id
     ORDER BY b.created_at DESC'
)->fetchAll();
$payments = $pdo->query(
    'SELECT p.*, u.full_name AS customer_name, m.name AS machine_name
     FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN users u ON u.id = b.user_id JOIN machinery m ON m.id = b.machinery_id
     ORDER BY p.paid_at DESC'
)->fetchAll();
$report_rows = $pdo->query(
    'SELECT b.start_date, b.end_date, b.total_price, b.status, u.full_name AS customer_name, m.name AS machine_name,
            COALESCE(p.status, \'unpaid\') AS payment_status
     FROM bookings b JOIN users u ON u.id = b.user_id JOIN machinery m ON m.id = b.machinery_id
     LEFT JOIN payments p ON p.booking_id = b.id ORDER BY b.start_date DESC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - Machinery Rental System</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f6f8; color: #1f2d3d; font-family: 'Segoe UI', Arial, sans-serif; }
        header { background: #1f2d3d; color: #fff; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #d7dee6; text-decoration: none; margin-left: 20px; }
        main { max-width: 1250px; margin: auto; padding: 28px 24px; }
        h1, h2 { margin-top: 0; } h2 { font-size: 20px; }
        .summary, .tabs { display: flex; gap: 12px; flex-wrap: wrap; }
        .summary { margin: 22px 0; } .metric { background: #fff; padding: 18px; flex: 1; min-width: 180px; border-left: 4px solid #2d6a4f; box-shadow: 0 2px 8px #0000000d; }
        .metric small { display: block; color: #777; } .metric strong { font-size: 24px; }
        .tabs { border-bottom: 2px solid #dfe4e8; margin-bottom: 22px; } .tab { border: 0; background: none; padding: 11px 16px; font-weight: 600; cursor: pointer; color: #566; } .tab.active { color: #2d6a4f; border-bottom: 3px solid #2d6a4f; }
        .panel { display: none; } .panel.active { display: block; }
        .section { background: #fff; padding: 20px; margin-bottom: 20px; overflow-x: auto; box-shadow: 0 2px 8px #0000000d; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; } th, td { text-align: left; padding: 10px; border-bottom: 1px solid #edf0f2; font-size: 13px; vertical-align: top; } th { background: #f1f3f5; font-size: 12px; text-transform: uppercase; color: #667; }
        input, select, textarea { width: 100%; padding: 9px; border: 1px solid #cbd2d8; border-radius: 4px; font: inherit; } textarea { min-height: 70px; } label { display: block; font-size: 13px; font-weight: 600; margin: 8px 0 4px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px 16px; } .full { grid-column: 1 / -1; }
        button, .button { border: 0; border-radius: 4px; padding: 8px 12px; background: #2d6a4f; color: #fff; cursor: pointer; text-decoration: none; display: inline-block; font-weight: 600; } .button-light { background: #e1e6ea; color: #25313b; }
        .alert { padding: 11px 14px; margin-bottom: 16px; background: #fdecea; color: #b3261e; } .success { background: #e6f4ea; color: #2d6a4f; }
        .status { font-weight: 600; } .print-only { display: none; }
        @media print { header, .no-print, .tabs, .summary, .panel:not(#report), button, .button { display: none !important; } .print-only { display: block; } body, main, .section { background: #fff; padding: 0; box-shadow: none; } #report { display: block !important; } }
        @media (max-width: 650px) { header { padding: 14px 18px; } main { padding: 20px 14px; } .form-grid { grid-template-columns: 1fr; } .full { grid-column: auto; } }
    </style>
</head>
<body>
<header><strong>Machinery Rental | Admin</strong><nav>Hi, <?= htmlspecialchars($_SESSION['full_name']) ?><a href="../auth/logout.php">Log out</a></nav></header>
<main>
    <h1>Admin Dashboard</h1>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endforeach; ?>
    <div class="summary"><div class="metric"><small>Total Users</small><strong><?= $summary['users'] ?></strong></div><div class="metric"><small>Machines</small><strong><?= $summary['machines'] ?></strong></div><div class="metric"><small>Bookings</small><strong><?= $summary['bookings'] ?></strong></div><div class="metric"><small>Paid Revenue</small><strong>KSh <?= number_format($summary['revenue'], 2) ?></strong></div></div>
    <div class="tabs no-print"><button class="tab active" data-panel="users">Users</button><button class="tab" data-panel="machinery">Machinery</button><button class="tab" data-panel="bookings">Bookings</button><button class="tab" data-panel="payments">Payments</button><button class="tab" data-panel="report">Reports</button></div>

    <section class="panel active" id="users"><div class="section"><h2>Manage Users</h2><table><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Operator Approval</th><th>Created</th></tr><?php foreach ($users as $user): ?><tr><td><?= htmlspecialchars($user['full_name']) ?></td><td><?= htmlspecialchars($user['email']) ?></td><td><?= htmlspecialchars($user['phone'] ?? '-') ?></td><td><form method="POST"><input type="hidden" name="action" value="update_user_role"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><select name="role" onchange="this.form.submit()" <?= (int) $user['id'] === (int) $_SESSION['user_id'] ? 'disabled' : '' ?>><option value="customer" <?= $user['role'] === 'customer' ? 'selected' : '' ?>>Customer</option><option value="operator" <?= $user['role'] === 'operator' ? 'selected' : '' ?>>Operator</option><option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select></form></td><td><?php if ($user['role'] === 'operator'): ?><form method="POST"><input type="hidden" name="action" value="update_operator_approval"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><select name="approval_status" onchange="this.form.submit()"><option value="pending" <?= $user['approval_status'] === 'pending' ? 'selected' : '' ?>>Pending</option><option value="approved" <?= $user['approval_status'] === 'approved' ? 'selected' : '' ?>>Approved</option><option value="rejected" <?= $user['approval_status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option></select></form><?php else: ?>Not required<?php endif; ?></td><td><?= htmlspecialchars($user['created_at']) ?></td></tr><?php endforeach; ?></table></div></section>

    <section class="panel" id="machinery"><div class="section"><h2>Add Machinery</h2><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="save_machinery"><div class="form-grid"><div><label>Name</label><input name="name" required></div><div><label>Type</label><input name="type" required></div><div><label>Price per day</label><input type="number" name="price_per_day" min="1" step="0.01" required></div><div><label>Status</label><select name="status"><option value="available">Available</option><option value="maintenance">Maintenance</option></select></div><div class="full"><label>Description</label><textarea name="description"></textarea></div><div class="full"><label>Image</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"></div></div><br><button type="submit">Add Machine</button></form></div><div class="section"><h2>Machinery Inventory</h2><table><tr><th>Name</th><th>Type</th><th>Price</th><th>Status</th><th>Image</th><th>Actions</th></tr><?php foreach ($machines as $machine): ?><tr><td><?= htmlspecialchars($machine['name']) ?></td><td><?= htmlspecialchars($machine['type']) ?></td><td>KSh <?= number_format($machine['price_per_day'], 2) ?></td><td class="status"><?= htmlspecialchars(ucfirst($machine['status'])) ?></td><td><?= htmlspecialchars($machine['image']) ?></td><td><form method="POST" onsubmit="return confirm('Delete this machinery?');"><input type="hidden" name="action" value="delete_machinery"><input type="hidden" name="id" value="<?= (int) $machine['id'] ?>"><button type="submit" style="background:#b3261e;">Delete</button></form></td></tr><?php endforeach; ?></table></div></section>

    <section class="panel" id="bookings"><div class="section"><h2>Manage Bookings</h2><table><tr><th>Customer</th><th>Machine</th><th>Dates</th><th>Total</th><th>Status</th><th>Update</th></tr><?php foreach ($bookings as $booking): ?><tr><td><?= htmlspecialchars($booking['customer_name']) ?></td><td><?= htmlspecialchars($booking['machine_name']) ?></td><td><?= htmlspecialchars($booking['start_date']) ?> to <?= htmlspecialchars($booking['end_date']) ?></td><td>KSh <?= number_format($booking['total_price'], 2) ?></td><td><?= htmlspecialchars(ucfirst($booking['status'])) ?></td><td><form method="POST"><input type="hidden" name="action" value="update_booking"><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>"><select name="status"><option value="pending" <?= $booking['status'] === 'pending' ? 'selected' : '' ?>>Pending</option><option value="confirmed" <?= $booking['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option><option value="active" <?= $booking['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="completed" <?= $booking['status'] === 'completed' ? 'selected' : '' ?>>Completed</option><option value="cancelled" <?= $booking['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select><button type="submit">Save</button></form></td></tr><?php endforeach; ?></table></div></section>

    <section class="panel" id="payments"><div class="section"><h2>Manage Payments</h2><table><tr><th>Customer</th><th>Machine</th><th>Amount</th><th>Method</th><th>Status</th><th>Update</th></tr><?php foreach ($payments as $payment): ?><tr><td><?= htmlspecialchars($payment['customer_name']) ?></td><td><?= htmlspecialchars($payment['machine_name']) ?></td><td>KSh <?= number_format($payment['amount'], 2) ?></td><td><?= htmlspecialchars($payment['method']) ?></td><td><?= htmlspecialchars(ucfirst($payment['status'])) ?></td><td><form method="POST"><input type="hidden" name="action" value="update_payment"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><select name="status"><option value="pending" <?= $payment['status'] === 'pending' ? 'selected' : '' ?>>Pending</option><option value="paid" <?= $payment['status'] === 'paid' ? 'selected' : '' ?>>Paid</option><option value="failed" <?= $payment['status'] === 'failed' ? 'selected' : '' ?>>Failed</option><option value="refunded" <?= $payment['status'] === 'refunded' ? 'selected' : '' ?>>Refunded</option></select><button type="submit">Save</button></form></td></tr><?php endforeach; ?></table></div></section>

    <section class="panel" id="report"><div class="section"><button class="no-print" onclick="window.print()">Print Report</button><div class="print-only"><h1>Machinery Rental Report</h1><p>Generated <?= date('Y-m-d H:i') ?></p></div><h2>Booking Report</h2><table><tr><th>Customer</th><th>Machine</th><th>Dates</th><th>Booking Status</th><th>Payment</th><th>Total</th></tr><?php foreach ($report_rows as $row): ?><tr><td><?= htmlspecialchars($row['customer_name']) ?></td><td><?= htmlspecialchars($row['machine_name']) ?></td><td><?= htmlspecialchars($row['start_date']) ?> to <?= htmlspecialchars($row['end_date']) ?></td><td><?= htmlspecialchars(ucfirst($row['status'])) ?></td><td><?= htmlspecialchars(ucfirst($row['payment_status'])) ?></td><td>KSh <?= number_format($row['total_price'], 2) ?></td></tr><?php endforeach; ?></table></div></section>
</main>
<script>document.querySelectorAll('.tab').forEach(function (tab) { tab.addEventListener('click', function () { document.querySelectorAll('.tab').forEach(function (item) { item.classList.remove('active'); }); document.querySelectorAll('.panel').forEach(function (panel) { panel.classList.remove('active'); }); tab.classList.add('active'); document.getElementById(tab.dataset.panel).classList.add('active'); }); });</script>
</body>
</html>
