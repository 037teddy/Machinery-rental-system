<?php
require_once '../config/db.php';
require_once '../includes/session.php';

require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT * FROM machinery WHERE id = ?');
$stmt->execute([$id]);
$machine = $stmt->fetch();

if (!$machine) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($machine['name']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="site-body">
    <?php include '../includes/header.php'; ?>

    <div class="page-content" style="max-width: 700px;">
        <a href="index.php">&larr; Back to listing</a>

        <div class="machine-card" style="margin-top: 16px;">
            <img src="../assets/images/<?= htmlspecialchars($machine['image']) ?>"
                 alt="<?= htmlspecialchars($machine['name']) ?>"
                 style="height: 280px;"
                 onerror="this.onerror=null; this.src='../assets/images/no-image.jpg'">
            <div class="machine-card-body">
                <h1 style="margin-bottom: 6px;"><?= htmlspecialchars($machine['name']) ?></h1>
                <div class="machine-type"><?= htmlspecialchars($machine['type']) ?></div>
                <span class="status-badge status-<?= htmlspecialchars($machine['status']) ?>">
                    <?= htmlspecialchars(ucfirst($machine['status'])) ?>
                </span>
                <p><?= nl2br(htmlspecialchars($machine['description'] ?? '')) ?></p>
                <div class="machine-price">KSh <?= number_format($machine['price_per_day'], 2) ?> / day</div>

                <?php if ($machine['status'] === 'available'): ?>
                    <a href="#" class="btn-view">Book This Machine</a>
                    <p style="font-size: 13px; color: #888; margin-top: 8px;">
                        (Booking form coming next.)
                    </p>
                <?php else: ?>
                    <p style="color: #b3261e; font-weight: 600;">Currently unavailable for booking.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body> 
</html>