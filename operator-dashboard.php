<?php
require_once '../config/db.php';
require_once '../includes/session.php';

require_login(['operator', 'admin']); // operators (and admins) only

$flash_success = null;
$flash_error = null;
$active_tab = $_GET['tab'] ?? 'machinery';
$operator_dashboard_url = 'operator-dashboard.php';

function upload_machine_image(string $field_name, ?string $current_image = null): array
{
    if (!isset($_FILES[$field_name]) || $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return [$current_image ?: 'no-image.jpg', null];
    }

    if ($_FILES[$field_name]['error'] !== UPLOAD_ERR_OK) {
        return [$current_image ?: 'no-image.jpg', 'The image upload failed. Please try again.'];
    }

    if ($_FILES[$field_name]['size'] > 5 * 1024 * 1024) {
        return [$current_image ?: 'no-image.jpg', 'Image must be smaller than 5 MB.'];
    }

    $image_info = getimagesize($_FILES[$field_name]['tmp_name']);
    $allowed_types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$image_info || !isset($allowed_types[$image_info[2]])) {
        return [$current_image ?: 'no-image.jpg', 'Please upload a JPG, PNG, WEBP, or GIF image.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed_types[$image_info[2]];
    $destination = __DIR__ . '/../assets/images/' . $filename;
    if (!move_uploaded_file($_FILES[$field_name]['tmp_name'], $destination)) {
        return [$current_image ?: 'no-image.jpg', 'The image could not be saved. Check the images folder permissions.'];
    }

    return [$filename, null];
}

// -----------------------------------------------------------------
// Handle form submissions (Post/Redirect/Get pattern)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Add new machinery ----
    if ($action === 'add_machinery') {
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price_per_day = (float) ($_POST['price_per_day'] ?? 0);
        [$image, $image_error] = upload_machine_image('image');

        if ($name === '' || $type === '' || $price_per_day <= 0 || $image_error) {
            $_SESSION['flash_error'] = $image_error ?: 'Name, type, and a valid price are required.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO machinery (name, type, description, price_per_day, status, image)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $type, $description, $price_per_day, 'available', $image]);
            $_SESSION['flash_success'] = 'Machine added successfully.';
        }
        header('Location: ' . $operator_dashboard_url . '?tab=machinery');
        exit;
    }

    // ---- Edit machinery ----
    if ($action === 'edit_machinery') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price_per_day = (float) ($_POST['price_per_day'] ?? 0);
        [$image, $image_error] = upload_machine_image('image', trim($_POST['current_image'] ?? '') ?: 'no-image.jpg');
        // Operators only choose between available / maintenance manually —
        // "rented" is computed automatically from active bookings, never set by hand.
        $status = ($_POST['status'] ?? 'available') === 'maintenance' ? 'maintenance' : 'available';

        if (!$id || $name === '' || $type === '' || $price_per_day <= 0 || $image_error) {
            $_SESSION['flash_error'] = $image_error ?: 'Name, type, and a valid price are required.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE machinery SET name = ?, type = ?, description = ?, price_per_day = ?, status = ?, image = ? WHERE id = ?'
            );
            $stmt->execute([$name, $type, $description, $price_per_day, $status, $image, $id]);
            $_SESSION['flash_success'] = 'Machine updated.';
        }
        header('Location: ' . $operator_dashboard_url . '?tab=machinery');
        exit;
    }

    // ---- Mark a confirmed booking as handed over ----
    if ($action === 'mark_handover') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT id, status FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if ($booking && $booking['status'] === 'confirmed') {
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'active', handed_over_at = NOW() WHERE id = ?");
            $stmt->execute([$booking_id]);
            $_SESSION['flash_success'] = 'Machine marked as handed over.';
        } else {
            $_SESSION['flash_error'] = 'That booking is not ready for handover.';
        }
        header('Location: ' . $operator_dashboard_url . '?tab=handover');
        exit;
    }

    // ---- Mark an active rental as returned ----
    if ($action === 'mark_return') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        $condition_notes = trim($_POST['condition_notes'] ?? '');

        $stmt = $pdo->prepare("SELECT id, status FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if ($booking && $booking['status'] === 'active') {
            $stmt = $pdo->prepare(
                "UPDATE bookings SET status = 'completed', returned_at = NOW(), condition_notes = ? WHERE id = ?"
            );
            $stmt->execute([$condition_notes !== '' ? $condition_notes : null, $booking_id]);
            $_SESSION['flash_success'] = 'Return logged. Machine is now available again.';
        } else {
            $_SESSION['flash_error'] = 'That booking is not currently active.';
        }
        header('Location: ' . $operator_dashboard_url . '?tab=handover');
        exit;
    }
}

// Flash messages
if (isset($_SESSION['flash_success'])) {
    $flash_success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $flash_error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// -----------------------------------------------------------------
// Data for the page
// -----------------------------------------------------------------

// Show the current booking or the next upcoming booking for each machine.
$machines = $pdo->query(
    "SELECT m.*,
                (SELECT b.start_date FROM bookings b
         WHERE b.machinery_id = m.id
                     AND b.status IN ('pending', 'confirmed', 'active')
                     AND b.end_date >= CURDATE()
                 ORDER BY b.start_date ASC LIMIT 1) AS booked_from,
                (SELECT b.end_date FROM bookings b
                 WHERE b.machinery_id = m.id
                     AND b.status IN ('pending', 'confirmed', 'active')
                     AND b.end_date >= CURDATE()
                 ORDER BY b.start_date ASC LIMIT 1) AS booked_until
     FROM machinery m
     ORDER BY m.type, m.name"
)->fetchAll();

// Bookings ready for handover (paid, not yet picked up)
$ready_for_handover = $pdo->query(
    "SELECT b.*, u.full_name AS customer_name, u.phone AS customer_phone, m.name AS machine_name
     FROM bookings b
     JOIN users u ON u.id = b.user_id
     JOIN machinery m ON m.id = b.machinery_id
     WHERE b.status = 'confirmed'
     ORDER BY b.start_date ASC"
)->fetchAll();

// Currently active rentals (handed over, not yet returned)
$active_rentals = $pdo->query(
    "SELECT b.*, u.full_name AS customer_name, u.phone AS customer_phone, m.name AS machine_name
     FROM bookings b
     JOIN users u ON u.id = b.user_id
     JOIN machinery m ON m.id = b.machinery_id
     WHERE b.status = 'active'
     ORDER BY b.end_date ASC"
)->fetchAll();

// Recently completed rentals (for condition-notes history)
$recent_returns = $pdo->query(
    "SELECT b.*, u.full_name AS customer_name, m.name AS machine_name
     FROM bookings b
     JOIN users u ON u.id = b.user_id
     JOIN machinery m ON m.id = b.machinery_id
     WHERE b.status = 'completed'
     ORDER BY b.returned_at DESC
     LIMIT 15"
)->fetchAll();

// Consolidated operator report data.
$report_bookings = $pdo->query(
    "SELECT b.*, u.full_name AS customer_name, u.phone AS customer_phone,
          m.name AS machine_name, p.status AS payment_status,
          p.transaction_reference
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN machinery m ON m.id = b.machinery_id
    LEFT JOIN payments p ON p.booking_id = b.id
    ORDER BY b.created_at DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Operator Dashboard - Machinery Rental System</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f8; margin: 0; color: #1f2d3d; }

    .site-header { background: #1f2d3d; color: #fff; padding: 14px 32px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
    .site-header .brand { color: #fff; font-weight: 700; font-size: 18px; text-decoration: none; }
    .site-header nav a { color: #d7dee6; text-decoration: none; margin-left: 20px; font-size: 14px; }
    .site-header nav a:hover { color: #fff; }
    .nav-user { color: #a9b6c4; margin-left: 20px; font-size: 14px; }

    .page-content { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }
    .page-content h1 { margin-bottom: 4px; }
    .page-subtitle { color: #666; margin-bottom: 24px; }

    .tabs { display: flex; gap: 6px; margin-bottom: 24px; border-bottom: 2px solid #e0e4e8; flex-wrap: wrap; }
    .tab-btn { background: none; border: none; padding: 10px 18px; font-size: 14px; font-weight: 600; color: #666; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; }
    .tab-btn.active { color: #2d6a4f; border-bottom-color: #2d6a4f; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    .alert { padding: 10px 14px; border-radius: 4px; margin-bottom: 18px; font-size: 14px; }
    .alert-error { background: #fdecea; color: #b3261e; }
    .alert-success { background: #e6f4ea; color: #2d6a4f; }

    .summary-row { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
    .summary-card { background: #fff; border-radius: 8px; padding: 16px 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); flex: 1; min-width: 160px; }
    .summary-card .label { font-size: 13px; color: #888; margin-bottom: 4px; }
    .summary-card .value { font-size: 22px; font-weight: 700; color: #1f2d3d; }

    .section-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .section-heading h3 { margin: 0; }

    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 28px; }
    th, td { text-align: left; padding: 12px 14px; font-size: 14px; border-bottom: 1px solid #eef0f2; vertical-align: top; }
    th { background: #f0f2f4; color: #555; font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; }
    tr:last-child td { border-bottom: none; }
    .empty-state { color: #888; padding: 20px 4px; }

    .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
    .status-available { background: #e6f4ea; color: #2d6a4f; }
    .status-rented { background: #fdecea; color: #b3261e; }
    .status-maintenance { background: #fff4e0; color: #a15c00; }
    .status-confirmed { background: #fff4e0; color: #a15c00; }
    .status-active { background: #e3edfb; color: #1a4c8f; }
    .status-completed { background: #f0f0f0; color: #555; }

    .btn { border: none; padding: 8px 14px; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-primary { background: #2d6a4f; color: #fff; }
    .btn-primary:hover { background: #245a41; }
    .btn-secondary { background: #e0e4e8; color: #333; }
    .btn-secondary:hover { background: #cfd5db; }
    .btn-add { margin-bottom: 16px; }

    .notes-cell { max-width: 220px; font-size: 13px; color: #555; }

    .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); align-items: center; justify-content: center; z-index: 100; padding: 16px; }
    .modal-backdrop.open { display: flex; }
    .modal { background: #fff; border-radius: 8px; padding: 28px; width: 100%; max-width: 440px; max-height: 90vh; overflow-y: auto; }
    .modal h3 { margin-top: 0; }
    .modal label { font-size: 14px; font-weight: 600; margin-bottom: 4px; display: block; }
    .modal input, .modal select, .modal textarea { width: 100%; padding: 9px; margin-bottom: 14px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; font-family: inherit; }
    .modal textarea { resize: vertical; min-height: 70px; }
    .form-hint { color: #888; font-size: 12px; margin: -8px 0 14px; }
    .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
    .report-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; }
    .report-meta { color: #666; font-size: 13px; margin-bottom: 18px; }
    .report-table { min-width: 1150px; }
    @media print {
        .site-header, .tabs, .no-print, .modal-backdrop { display: none !important; }
        body, .page-content { background: #fff; }
        .page-content { max-width: none; padding: 0; }
        .tab-panel { display: none !important; }
        #panel-report { display: block !important; }
        #panel-report table { box-shadow: none; }
        .report-table { min-width: 0; }
    }
</style>
</head>
<body>

<header class="site-header">
    <a href="index.php" class="brand">Machinery Rental &mdash; Operator</a>
    <nav>
        <span class="nav-user">Hi, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
        <a href="../auth/logout.php">Log out</a>
    </nav>
</header>

<div class="page-content">
    <h1>Operator Dashboard</h1>
    <p class="page-subtitle">Manage machinery, and track handovers and returns.</p>

    <?php if ($flash_success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <div class="summary-row">
        <div class="summary-card">
            <div class="label">Total Machines</div>
            <div class="value"><?= count($machines) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Ready for Handover</div>
            <div class="value"><?= count($ready_for_handover) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Currently Out</div>
            <div class="value"><?= count($active_rentals) ?></div>
        </div>
    </div>

    <div class="tabs">
        <button class="tab-btn" data-tab="machinery">Manage Machinery</button>
        <button class="tab-btn" data-tab="handover">Handover &amp; Returns</button>
        <button class="tab-btn" data-tab="report">Print Report</button>
    </div>

    <!-- ================= MANAGE MACHINERY ================= -->
    <div class="tab-panel" id="panel-machinery">
        <button class="btn btn-primary btn-add" onclick="openAddModal()">+ Add New Machine</button>

        <?php if (empty($machines)): ?>
            <p class="empty-state">No machinery has been added yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Price / Day</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($machines as $m): ?>
                        <?php
                        if ($m['status'] === 'maintenance') {
                            $badge_class = 'status-maintenance';
                            $badge_text = 'Maintenance';
                        } elseif ($m['booked_from']) {
                            $badge_class = 'status-rented';
                            $badge_text = 'Booked ' . htmlspecialchars($m['booked_from']) . ' to ' . htmlspecialchars($m['booked_until']);
                        } else {
                            $badge_class = 'status-available';
                            $badge_text = 'Available';
                        }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($m['name']) ?></td>
                            <td><?= htmlspecialchars($m['type']) ?></td>
                            <td>KSh <?= number_format($m['price_per_day'], 2) ?></td>
                            <td><span class="status-badge <?= $badge_class ?>"><?= $badge_text ?></span></td>
                            <td>
                                <button class="btn btn-secondary"
                                    onclick='openEditModal(<?= json_encode([
                                        "id" => (int) $m["id"],
                                        "name" => $m["name"],
                                        "type" => $m["type"],
                                        "description" => $m["description"],
                                        "price_per_day" => (float) $m["price_per_day"],
                                        "image" => $m["image"],
                                        "status" => $m["status"],
                                    ]) ?>)'>
                                    Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================= HANDOVER & RETURNS ================= -->
    <div class="tab-panel" id="panel-handover">

        <div class="section-heading"><h3>Ready for Handover</h3></div>
        <?php if (empty($ready_for_handover)): ?>
            <p class="empty-state">No paid bookings waiting for handover.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>Customer</th>
                        <th>Identification</th>
                        <th>Dates</th>
                        <th>Driver</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ready_for_handover as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['machine_name']) ?></td>
                            <td><?= htmlspecialchars($b['customer_name']) ?><?php if ($b['customer_phone']): ?><br><span style="color:#888;font-size:12px;"><?= htmlspecialchars($b['customer_phone']) ?></span><?php endif; ?></td>
                            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $b['id_document_type']))) ?><br><?= htmlspecialchars($b['id_document_number']) ?></td>
                            <td><?= htmlspecialchars($b['start_date']) ?> &rarr; <?= htmlspecialchars($b['end_date']) ?></td>
                            <td><?= (int) $b['driver_included'] === 1 ? 'Company driver' : 'Not included' ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Confirm this machine is being handed to the customer now?');">
                                    <input type="hidden" name="action" value="mark_handover">
                                    <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                    <button type="submit" class="btn btn-primary">Mark Handed Over</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="section-heading"><h3>Currently Out</h3></div>
        <?php if (empty($active_rentals)): ?>
            <p class="empty-state">No machines are currently out on rental.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>Customer</th>
                        <th>Due Back</th>
                        <th>Handed Over</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_rentals as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['machine_name']) ?></td>
                            <td><?= htmlspecialchars($b['customer_name']) ?><?php if ($b['customer_phone']): ?><br><span style="color:#888;font-size:12px;"><?= htmlspecialchars($b['customer_phone']) ?></span><?php endif; ?></td>
                            <td><?= htmlspecialchars($b['end_date']) ?></td>
                            <td><?= htmlspecialchars($b['handed_over_at']) ?></td>
                            <td>
                                <button class="btn btn-primary" onclick="openReturnModal(<?= (int) $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['machine_name'])) ?>')">
                                    Mark Returned
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="section-heading"><h3>Recent Returns</h3></div>
        <?php if (empty($recent_returns)): ?>
            <p class="empty-state">No returns logged yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>Customer</th>
                        <th>Returned At</th>
                        <th>Condition Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_returns as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['machine_name']) ?></td>
                            <td><?= htmlspecialchars($b['customer_name']) ?></td>
                            <td><?= htmlspecialchars($b['returned_at']) ?></td>
                            <td class="notes-cell"><?= $b['condition_notes'] ? nl2br(htmlspecialchars($b['condition_notes'])) : '&mdash;' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================= OPERATOR REPORT ================= -->
    <div class="tab-panel" id="panel-report">
        <div class="report-toolbar no-print">
            <div>
                <h2>Operator Rental Report</h2>
                <p class="page-subtitle">Bookings, payments, handovers, returns, and machine condition history.</p>
            </div>
            <button class="btn btn-primary" onclick="window.print()">Print Report</button>
        </div>
        <div id="operator-report">
            <h1>Machinery Rental Operator Report</h1>
            <p class="report-meta">Generated: <?= date('d M Y, H:i') ?> | Operator: <?= htmlspecialchars($_SESSION['full_name']) ?></p>
            <?php if (empty($report_bookings)): ?>
                <p class="empty-state">No bookings to report.</p>
            <?php else: ?>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Machine</th>
                            <th>Customer</th>
                            <th>Identification</th>
                            <th>Rental Dates</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Booking</th>
                            <th>Driver</th>
                            <th>Handed Over</th>
                            <th>Returned</th>
                            <th>Condition Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_bookings as $booking): ?>
                            <tr>
                                <td><?= htmlspecialchars($booking['machine_name']) ?></td>
                                <td><?= htmlspecialchars($booking['customer_name']) ?><br><?= htmlspecialchars($booking['customer_phone'] ?? '') ?></td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $booking['id_document_type']))) ?><br><?= htmlspecialchars($booking['id_document_number']) ?></td>
                                <td><?= htmlspecialchars($booking['start_date']) ?> to <?= htmlspecialchars($booking['end_date']) ?></td>
                                <td>KSh <?= number_format($booking['total_price'], 2) ?></td>
                                <td><?= htmlspecialchars(ucfirst($booking['payment_status'] ?? 'unpaid')) ?><br><?= htmlspecialchars($booking['transaction_reference'] ?? '') ?></td>
                                <td><?= htmlspecialchars(ucfirst($booking['status'])) ?></td>
                                <td><?= (int) $booking['driver_included'] === 1 ? 'Company driver' : 'Not included' ?></td>
                                <td><?= htmlspecialchars($booking['handed_over_at'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($booking['returned_at'] ?? '-') ?></td>
                                <td><?= $booking['condition_notes'] ? nl2br(htmlspecialchars($booking['condition_notes'])) : '&mdash;' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ================= ADD MACHINE MODAL ================= -->
<div class="modal-backdrop" id="add-modal">
    <div class="modal">
        <h3>Add New Machine</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_machinery">

            <label for="add-name">Name</label>
            <input type="text" name="name" id="add-name" required>

            <label for="add-type">Type</label>
            <input type="text" name="type" id="add-type" placeholder="e.g. Tractor, Excavator" required>

            <label for="add-description">Description</label>
            <textarea name="description" id="add-description"></textarea>

            <label for="add-price">Price per Day (KSh)</label>
            <input type="number" name="price_per_day" id="add-price" min="1" step="0.01" required>

            <label for="add-image">Machine Image</label>
            <input type="file" name="image" id="add-image" accept="image/jpeg,image/png,image/webp,image/gif">
            <p class="form-hint">JPG, PNG, WEBP, or GIF. Maximum 5 MB.</p>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('add-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Machine</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= EDIT MACHINE MODAL ================= -->
<div class="modal-backdrop" id="edit-modal">
    <div class="modal">
        <h3>Edit Machine</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_machinery">
            <input type="hidden" name="id" id="edit-id">
            <input type="hidden" name="current_image" id="edit-current-image">

            <label for="edit-name">Name</label>
            <input type="text" name="name" id="edit-name" required>

            <label for="edit-type">Type</label>
            <input type="text" name="type" id="edit-type" required>

            <label for="edit-description">Description</label>
            <textarea name="description" id="edit-description"></textarea>

            <label for="edit-price">Price per Day (KSh)</label>
            <input type="number" name="price_per_day" id="edit-price" min="1" step="0.01" required>

            <label for="edit-image">Machine Image</label>
            <input type="file" name="image" id="edit-image" accept="image/jpeg,image/png,image/webp,image/gif">
            <p class="form-hint">Leave empty to keep the current image.</p>

            <label for="edit-status">Status</label>
            <select name="status" id="edit-status">
                <option value="available">Available</option>
                <option value="maintenance">Maintenance</option>
            </select>
            <p style="font-size:12px;color:#888;margin-top:-8px;margin-bottom:14px;">
                "Rented" is set automatically while a booking covers today's date &mdash; it isn't chosen here.
            </p>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('edit-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= RETURN MODAL ================= -->
<div class="modal-backdrop" id="return-modal">
    <div class="modal">
        <h3>Log Return</h3>
        <form method="POST">
            <input type="hidden" name="action" value="mark_return">
            <input type="hidden" name="booking_id" id="return-booking-id">

            <label>Machine</label>
            <input type="text" id="return-machine-name" disabled>

            <label for="return-notes">Condition Notes (optional)</label>
            <textarea name="condition_notes" id="return-notes" placeholder="e.g. minor scratches on left panel, full tank, all attachments returned"></textarea>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('return-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Return</button>
            </div>
        </form>
    </div>
</div>

<script>
    function switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        document.querySelectorAll('.tab-panel').forEach(panel => {
            panel.classList.toggle('active', panel.id === 'panel-' + tab);
        });
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    }
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });
    const initialTab = <?= json_encode($active_tab) ?>;
    switchTab(['machinery', 'handover', 'report'].includes(initialTab) ? initialTab : 'machinery');

    function openAddModal() {
        document.getElementById('add-modal').classList.add('open');
    }

    function openEditModal(machine) {
        document.getElementById('edit-id').value = machine.id;
        document.getElementById('edit-name').value = machine.name;
        document.getElementById('edit-type').value = machine.type;
        document.getElementById('edit-description').value = machine.description || '';
        document.getElementById('edit-price').value = machine.price_per_day;
        document.getElementById('edit-current-image').value = machine.image || 'no-image.jpg';
        document.getElementById('edit-status').value = machine.status === 'maintenance' ? 'maintenance' : 'available';
        document.getElementById('edit-modal').classList.add('open');
    }

    function openReturnModal(bookingId, machineName) {
        document.getElementById('return-booking-id').value = bookingId;
        document.getElementById('return-machine-name').value = machineName;
        document.getElementById('return-notes').value = '';
        document.getElementById('return-modal').classList.add('open');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
    }

    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) backdrop.classList.remove('open');
        });
    });
</script>

</body>
</html>