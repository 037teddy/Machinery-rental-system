<?php
require_once '../config/db.php';
require_once '../includes/session.php';

require_login(); // customers and operators land here after login

$user_id = $_SESSION['user_id'];
$flash_success = null;
$flash_error = null;
$active_tab = $_GET['tab'] ?? 'browse';

// -----------------------------------------------------------------
// Handle form submissions (Post/Redirect/Get pattern to avoid
// duplicate bookings/payments on page refresh)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Create a booking ----
    if ($action === 'create_booking') {
        $machinery_id = (int) ($_POST['machinery_id'] ?? 0);
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $id_document_type = $_POST['id_document_type'] ?? '';
        $id_document_number = trim($_POST['id_document_number'] ?? '');

        $today = date('Y-m-d');
        $error = null;

        if (!$machinery_id || !$start_date || !$end_date || !$id_document_type || !$id_document_number) {
            $error = 'All booking fields are required.';
        } elseif (!in_array($id_document_type, ['identification_card', 'passport'], true)) {
            $error = 'Please select a valid identification document.';
        } elseif (strlen($id_document_number) < 4 || strlen($id_document_number) > 100) {
            $error = 'Please enter a valid identification or passport number.';
        } elseif ($start_date < $today) {
            $error = 'Start date cannot be in the past.';
        } elseif ($end_date < $start_date) {
            $error = 'End date must be on or after the start date.';
        }

        if (!$error) {
            $pdo->beginTransaction();

            // Lock this machine while checking and creating the booking so
            // simultaneous requests cannot both pass the overlap check.
            $stmt = $pdo->prepare('SELECT price_per_day, status FROM machinery WHERE id = ? FOR UPDATE');
            $stmt->execute([$machinery_id]);
            $machine = $stmt->fetch();

            if (!$machine) {
                $error = 'Selected machine no longer exists.';
            } elseif ($machine['status'] !== 'available') {
                $error = 'That machine is not currently available.';
            } else {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM bookings
                     WHERE machinery_id = ?
                      AND status IN ('pending', 'confirmed', 'active')
                       AND start_date <= ? AND end_date >= ?"
                );
                $stmt->execute([$machinery_id, $end_date, $start_date]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $error = 'That machine is already booked for part of those dates.';
                }
            }
        }

        if (!$error) {
            $days = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
            $total_price = $days * $machine['price_per_day'];

            $stmt = $pdo->prepare(
                 'INSERT INTO bookings (user_id, id_document_type, id_document_number, driver_included, machinery_id, start_date, end_date, total_price, status)
                  VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)'
            );
              $stmt->execute([$user_id, $id_document_type, $id_document_number, $machinery_id, $start_date, $end_date, $total_price, 'pending']);
            $pdo->commit();

            $_SESSION['flash_success'] = 'Booking created. Complete payment to confirm it.';
            header('Location: index.php?tab=bookings');
            exit;
        } else {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $error;
            header('Location: index.php?tab=browse');
            exit;
        }
    }

    // ---- Cancel a booking ----
    if ($action === 'cancel_booking') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT id, status FROM bookings WHERE id = ? AND user_id = ?");
        $stmt->execute([$booking_id, $user_id]);
        $booking = $stmt->fetch();

        if ($booking && in_array($booking['status'], ['pending', 'confirmed'], true)) {
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$booking_id]);
            $_SESSION['flash_success'] = 'Booking cancelled.';
        } else {
            $_SESSION['flash_error'] = 'Booking could not be cancelled.';
        }
        header('Location: index.php?tab=bookings');
        exit;
    }

    // ---- Leave a verified review after a completed rental ----
    if ($action === 'submit_review') {
        $machinery_id = (int) ($_POST['machinery_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM bookings
             WHERE user_id = ? AND machinery_id = ? AND status = 'completed'"
        );
        $stmt->execute([$user_id, $machinery_id]);
        $has_completed_rental = (int) $stmt->fetchColumn() > 0;

        if (!$has_completed_rental) {
            $_SESSION['flash_error'] = 'Reviews are available after you complete a rental.';
        } elseif ($rating < 1 || $rating > 5 || $comment === '') {
            $_SESSION['flash_error'] = 'Please choose a rating and write a review.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO reviews (user_id, machinery_id, rating, comment) VALUES (?, ?, ?, ?)'
            );
            try {
                $stmt->execute([$user_id, $machinery_id, $rating, $comment]);
                $_SESSION['flash_success'] = 'Thank you for sharing your verified review.';
            } catch (PDOException $exception) {
                $_SESSION['flash_error'] = 'You have already reviewed this machine.';
            }
        }
        header('Location: index.php?tab=reviews#reviews');
        exit;
    }
}

// Pull flash messages set by the redirect above
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
$machines = $pdo->query(
    "SELECT m.id, m.name, m.type, m.price_per_day, m.status, m.image,
            COALESCE(AVG(r.rating), 0) AS average_rating, COUNT(r.id) AS review_count
     FROM machinery m
     LEFT JOIN reviews r ON r.machinery_id = m.id
     GROUP BY m.id, m.name, m.type, m.price_per_day, m.status, m.image
     ORDER BY m.type, m.name"
)->fetchAll();

$stmt = $pdo->prepare(
    "SELECT b.*, m.name AS machine_name, m.image AS machine_image
     FROM bookings b
     JOIN machinery m ON m.id = b.machinery_id
     WHERE b.user_id = ?
     ORDER BY b.created_at DESC"
);
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT p.*, b.start_date, b.end_date, m.name AS machine_name
     FROM payments p
     JOIN bookings b ON b.id = p.booking_id
     JOIN machinery m ON m.id = b.machinery_id
     WHERE b.user_id = ?
     ORDER BY p.paid_at DESC"
);
$stmt->execute([$user_id]);
$payments = $stmt->fetchAll();

$pending_bookings = array_filter($bookings, fn($b) => $b['status'] === 'pending');
$total_spent = array_sum(array_column($payments, 'amount'));
$completed_machines = $pdo->prepare(
        "SELECT DISTINCT m.id, m.name
         FROM bookings b JOIN machinery m ON m.id = b.machinery_id
         WHERE b.user_id = ? AND b.status = 'completed'
             AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.user_id = ? AND r.machinery_id = m.id)
         ORDER BY m.name"
);
$completed_machines->execute([$user_id, $user_id]);
$completed_machines = $completed_machines->fetchAll();
$reviews = $pdo->query(
        "SELECT r.rating, r.comment, r.created_at, u.full_name, m.name AS machine_name
         FROM reviews r JOIN users u ON u.id = r.user_id JOIN machinery m ON m.id = r.machinery_id
         ORDER BY r.created_at DESC LIMIT 6"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Dashboard - Machinery Rental System</title>
<style>
    * { box-sizing: border-box; }

    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: #f4f6f8;
        margin: 0;
        color: #1f2d3d;
    }

    /* ---- Header / nav ---- */
    .site-header {
        background: #1f2d3d;
        color: #fff;
        padding: 14px 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .site-header .brand { color: #fff; font-weight: 700; font-size: 18px; text-decoration: none; }
    .site-header nav a { color: #d7dee6; text-decoration: none; margin-left: 20px; font-size: 14px; }
    .site-header nav a:hover { color: #fff; }
    .nav-user { color: #a9b6c4; margin-left: 20px; font-size: 14px; }

    .page-content { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }
    .page-content h1 { margin-bottom: 4px; }
    .page-subtitle { color: #666; margin-bottom: 24px; }

    .home-hero { max-width: 1100px; margin: 0 auto; padding: 42px 24px 22px; display: grid; grid-template-columns: 1fr 1fr; gap: 28px; align-items: center; }
    .hero-copy h1 { font-size: clamp(32px, 5vw, 58px); line-height: 1.02; margin: 0 0 16px; letter-spacing: 0; color: #1f2d3d; }
    .hero-copy p { max-width: 490px; color: #58636d; font-size: 17px; line-height: 1.6; }
    .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 22px; }
    .hero-visual { position: relative; min-height: 310px; overflow: hidden; border-radius: 10px; background: #203b35; box-shadow: 0 16px 32px rgba(31,45,61,.18); }
    .hero-slide { position: absolute; inset: 0; opacity: 0; animation: hero-fade 15s infinite; }
    .hero-slide:nth-child(2) { animation-delay: 5s; } .hero-slide:nth-child(3) { animation-delay: 10s; }
    .hero-slide img { width: 100%; height: 100%; object-fit: cover; opacity: .78; }
    .hero-slide span { position: absolute; left: 20px; bottom: 18px; color: #fff; font-weight: 700; font-size: 18px; text-shadow: 0 1px 5px #000; }
    @keyframes hero-fade { 0%, 28%, 100% { opacity: 0; transform: scale(1.04); } 8%, 22% { opacity: 1; transform: scale(1); } }
    .trust-strip, .service-grid, .review-grid { max-width: 1100px; margin: 0 auto; padding: 18px 24px 32px; }
    .trust-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .trust-item, .service-card, .review-card { background: #fff; border: 1px solid #e5e9eb; padding: 18px; border-radius: 8px; }
    .trust-item strong { display: block; margin-bottom: 5px; color: #2d6a4f; } .trust-item span { color: #68727a; font-size: 13px; }
    .home-section-heading { max-width: 1100px; margin: 0 auto; padding: 24px 24px 8px; } .home-section-heading h2 { margin-bottom: 5px; } .home-section-heading p { color: #69747c; }
    .service-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; padding-top: 8px; } .service-card h3 { margin: 0 0 8px; font-size: 16px; } .service-card p { color: #68727a; font-size: 13px; line-height: 1.5; margin: 0; }
    .contact-band { max-width: 1100px; margin: 0 auto 24px; padding: 22px 24px; background: #1f2d3d; color: #fff; display: flex; justify-content: space-between; gap: 16px; align-items: center; } .contact-band p { margin: 4px 0 0; color: #d7dee6; }
    .review-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; padding-top: 8px; } .review-card p { color: #53616a; line-height: 1.5; font-size: 14px; } .stars { color: #c47a19; letter-spacing: 1px; } .review-author { font-size: 12px; color: #7b858c; }
    .rating-line { color: #c47a19; font-size: 13px; margin: 2px 0 10px; }
    .review-form { max-width: 620px; background: #fff; border: 1px solid #e5e9eb; padding: 20px; border-radius: 8px; margin-bottom: 22px; } .review-form label { display: block; font-size: 13px; font-weight: 600; margin: 10px 0 5px; } .review-form select, .review-form textarea { width: 100%; padding: 9px; border: 1px solid #cbd2d8; border-radius: 4px; font: inherit; } .review-form textarea { min-height: 90px; resize: vertical; }

    /* ---- Tabs ---- */
    .tabs {
        display: flex;
        gap: 6px;
        margin-bottom: 24px;
        border-bottom: 2px solid #e0e4e8;
        flex-wrap: wrap;
    }
    .tab-btn {
        background: none;
        border: none;
        padding: 10px 18px;
        font-size: 14px;
        font-weight: 600;
        color: #666;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
    }
    .tab-btn.active { color: #2d6a4f; border-bottom-color: #2d6a4f; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* ---- Alerts ---- */
    .alert { padding: 10px 14px; border-radius: 4px; margin-bottom: 18px; font-size: 14px; }
    .alert-error { background: #fdecea; color: #b3261e; }
    .alert-success { background: #e6f4ea; color: #2d6a4f; }

    /* ---- Summary cards ---- */
    .summary-row { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
    .summary-card {
        background: #fff; border-radius: 8px; padding: 16px 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06); flex: 1; min-width: 160px;
    }
    .summary-card .label { font-size: 13px; color: #888; margin-bottom: 4px; }
    .summary-card .value { font-size: 22px; font-weight: 700; color: #1f2d3d; }

    /* ---- Machinery grid ---- */
    .machinery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
    .machine-card { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.07); display: flex; flex-direction: column; }
    .machine-card img { width: 100%; height: 150px; object-fit: cover; background: #e9edf1; }
    .machine-card-body { padding: 16px; display: flex; flex-direction: column; flex-grow: 1; }
    .machine-card-body h3 { margin: 0 0 4px; font-size: 16px; }
    .machine-type { color: #888; font-size: 13px; margin-bottom: 8px; }
    .machine-price { font-weight: 700; color: #2d6a4f; font-size: 15px; margin-bottom: 12px; }

    .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; margin-bottom: 10px; width: fit-content; }
    .status-available { background: #e6f4ea; color: #2d6a4f; }
    .status-rented { background: #fdecea; color: #b3261e; }
    .status-maintenance { background: #fff4e0; color: #a15c00; }
    .status-pending { background: #fff4e0; color: #a15c00; }
    .status-confirmed { background: #e6f4ea; color: #2d6a4f; }
    .status-completed { background: #e3edfb; color: #1a4c8f; }
    .status-cancelled { background: #f0f0f0; color: #777; }

    .btn { border: none; padding: 9px 16px; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-primary { background: #2d6a4f; color: #fff; }
    .btn-primary:hover { background: #245a41; }
    .btn-primary:disabled { background: #b7c4bd; cursor: not-allowed; }
    .btn-secondary { background: #e0e4e8; color: #333; }
    .btn-secondary:hover { background: #cfd5db; }
    .btn-danger { background: #fdecea; color: #b3261e; }
    .btn-danger:hover { background: #f9d5d1; }
    .btn-sm { padding: 6px 12px; font-size: 13px; }
    .mt-auto { margin-top: auto; }

    /* ---- Tables ---- */
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
    th, td { text-align: left; padding: 12px 14px; font-size: 14px; border-bottom: 1px solid #eef0f2; }
    th { background: #f0f2f4; color: #555; font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; }
    tr:last-child td { border-bottom: none; }
    .empty-state { color: #888; padding: 24px; text-align: center; }

    /* ---- Modal ---- */
    .modal-backdrop {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45);
        align-items: center; justify-content: center; z-index: 100; padding: 16px;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
        background: #fff; border-radius: 8px; padding: 28px; width: 100%; max-width: 400px;
    }
    .modal h3 { margin-top: 0; }
    .modal label { font-size: 14px; font-weight: 600; margin-bottom: 4px; display: block; }
    .modal input, .modal select {
        width: 100%; padding: 9px; margin-bottom: 14px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;
    }
    .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
    .modal-price { font-weight: 700; color: #2d6a4f; margin-bottom: 14px; }
    .driver-note { margin: 4px 0 14px; padding: 10px 12px; background: #e8f1ed; border-left: 3px solid #2d6a4f; color: #315343; font-size: 13px; line-height: 1.45; }

    @media (max-width: 760px) { .home-hero { grid-template-columns: 1fr; padding-top: 28px; } .hero-visual { min-height: 240px; } .trust-strip, .service-grid { grid-template-columns: repeat(2, 1fr); } .review-grid { grid-template-columns: 1fr; } .contact-band { display: block; } }

    /* ---- Print report ---- */
    #report-content { background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
    #report-content h2 { margin-top: 0; }
    .report-meta { color: #666; font-size: 13px; margin-bottom: 20px; }

    @media print {
        .site-header, .tabs, .btn-print, .no-print { display: none !important; }
        .page-content { padding: 0; max-width: none; }
        .tab-panel { display: none !important; }
        #report-panel { display: block !important; }
        #report-content { box-shadow: none; padding: 0; }
        body { background: #fff; }
    }
</style>
</head>
<body>

<header class="site-header">
    <a href="index.php" class="brand">Machinery Rental</a>
    <nav>
        <span class="nav-user">Hi, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
        <a href="../auth/logout.php">Log out</a>
    </nav>
</header>

<section class="home-hero" id="home">
    <div class="hero-copy">
        <p class="machine-type">POWER YOUR NEXT PROJECT</p>
        <h1>Reliable machinery, ready when you are.</h1>
        <p>Rent well-maintained equipment with clear daily prices, flexible dates, and a team that keeps your work moving.</p>
        <div class="hero-actions">
            <button class="btn btn-primary" onclick="switchTab('browse')">Explore machinery</button>
            <a class="btn btn-secondary" href="#services">View services</a>
        </div>
    </div>
    <div class="hero-visual" aria-label="Machinery showcase">
        <div class="hero-slide"><img src="../assets/images/excavator.jpg" alt="Excavator at work"><span>Heavy-duty earthmoving</span></div>
        <div class="hero-slide"><img src="../assets/images/tractor.jpg" alt="Tractor ready for rental"><span>Dependable field equipment</span></div>
        <div class="hero-slide"><img src="../assets/images/forklift.jpg" alt="Forklift for material handling"><span>Move materials with confidence</span></div>
    </div>
</section>

<section class="trust-strip" aria-label="Why rent with us">
    <div class="trust-item"><strong>Verified &amp; safe</strong><span>Equipment checked before every rental.</span></div>
    <div class="trust-item"><strong>Best daily prices</strong><span>Transparent rates with no surprise fees.</span></div>
    <div class="trust-item"><strong>24/7 support</strong><span>Help is available whenever your project needs it.</span></div>
    <div class="trust-item"><strong>Flexible booking</strong><span>Choose the dates that fit your schedule.</span></div>
</section>

<div class="home-section-heading" id="services"><h2>Services built around your work</h2><p>From the first booking to the final return, we keep the rental simple.</p></div>
<section class="service-grid">
    <div class="service-card"><h3>Equipment rental</h3><p>Excavators, tractors, forklifts, generators, and more for jobs of every scale.</p></div>
    <div class="service-card"><h3>Flexible scheduling</h3><p>Reserve equipment for the exact dates you need and avoid double bookings.</p></div>
    <div class="service-card"><h3>Machine with a driver</h3><p>Every rental is supported by a trained company driver to operate the equipment safely.</p></div>
    <div class="service-card"><h3>Simple M-Pesa payments</h3><p>Receive a secure M-Pesa prompt and track your payment until the booking is confirmed.</p></div>
</section>

<section class="contact-band" id="contact"><div><strong>Need help choosing a machine?</strong><p>Talk to our support team about your project and rental dates.</p></div><div><strong>Call: +254 798 555 338</strong><p>Email: samkemei95@gmail.com</p></div></section>

<div class="home-section-heading" id="reviews"><h2>What customers say</h2><p>Real feedback from completed rentals.</p></div>
<section class="review-grid">
    <?php if (empty($reviews)): ?>
        <div class="review-card"><p>Be the first to share how your machinery performed.</p></div>
    <?php else: ?>
        <?php foreach ($reviews as $review): ?>
            <article class="review-card"><div class="stars"><?= str_repeat('&#9733;', (int) $review['rating']) ?><?= str_repeat('&#9734;', 5 - (int) $review['rating']) ?></div><p><?= nl2br(htmlspecialchars($review['comment'])) ?></p><div class="review-author">Verified rental by <?= htmlspecialchars($review['full_name']) ?> · <?= htmlspecialchars($review['machine_name']) ?></div></article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<div class="page-content">
    <h1>My Dashboard</h1>
    <p class="page-subtitle">Browse machinery, manage your bookings and payments, and print reports.</p>

    <?php if ($flash_success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <div class="summary-row">
        <div class="summary-card">
            <div class="label">Active Bookings</div>
            <div class="value"><?= count(array_filter($bookings, fn($b) => in_array($b['status'], ['pending', 'confirmed'], true))) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Awaiting Payment</div>
            <div class="value"><?= count($pending_bookings) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Paid</div>
            <div class="value">KSh <?= number_format($total_spent, 2) ?></div>
        </div>
    </div>

    <div class="tabs">
        <button class="tab-btn" data-tab="browse">Browse Machinery</button>
        <button class="tab-btn" data-tab="bookings">My Bookings</button>
        <button class="tab-btn" data-tab="payments">Payments</button>
        <button class="tab-btn" data-tab="reviews">My Reviews</button>
        <button class="tab-btn" data-tab="report">Print Report</button>
    </div>

    <!-- ================= BROWSE ================= -->
    <div class="tab-panel" id="panel-browse">
        <?php if (empty($machines)): ?>
            <p class="empty-state">No machinery available yet.</p>
        <?php else: ?>
            <div class="machinery-grid">
                <?php foreach ($machines as $machine): ?>
                    <div class="machine-card">
                        <img src="../assets/images/<?= htmlspecialchars($machine['image']) ?>"
                             alt="<?= htmlspecialchars($machine['name']) ?>"
                             onerror="this.onerror=null; this.src='../assets/images/no-image.jpg'">
                        <div class="machine-card-body">
                            <h3><?= htmlspecialchars($machine['name']) ?></h3>
                            <div class="machine-type"><?= htmlspecialchars($machine['type']) ?></div>
                            <?php if ((int) $machine['review_count'] > 0): ?>
                                <div class="rating-line" aria-label="<?= number_format((float) $machine['average_rating'], 1) ?> out of 5 stars"><?= str_repeat('&#9733;', (int) round($machine['average_rating'])) ?><?= str_repeat('&#9734;', 5 - (int) round($machine['average_rating'])) ?> <span><?= number_format((float) $machine['average_rating'], 1) ?> (<?= (int) $machine['review_count'] ?>)</span></div>
                            <?php endif; ?>
                            <span class="status-badge status-<?= htmlspecialchars($machine['status']) ?>">
                                <?= htmlspecialchars(ucfirst($machine['status'])) ?>
                            </span>
                            <div class="machine-price">KSh <?= number_format($machine['price_per_day'], 2) ?> / day</div>
                            <?php if ($machine['status'] === 'available'): ?>
                                <button class="btn btn-primary mt-auto"
                                        onclick="openBookingModal(<?= (int) $machine['id'] ?>, '<?= htmlspecialchars(addslashes($machine['name'])) ?>', <?= (float) $machine['price_per_day'] ?>)">
                                    Book Now
                                </button>
                            <?php else: ?>
                                <button class="btn btn-secondary mt-auto" disabled>Unavailable</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================= MY BOOKINGS ================= -->
    <div class="tab-panel" id="panel-bookings">
        <?php if (empty($bookings)): ?>
            <p class="empty-state">You haven't made any bookings yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>Dates</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['machine_name']) ?></td>
                            <td><?= htmlspecialchars($b['start_date']) ?> &rarr; <?= htmlspecialchars($b['end_date']) ?></td>
                            <td>KSh <?= number_format($b['total_price'], 2) ?></td>
                            <td><span class="status-badge status-<?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars(ucfirst($b['status'])) ?></span></td>
                            <td>
                                <?php if ($b['status'] === 'pending'): ?>
                                    <button class="btn btn-primary btn-sm" onclick="switchTab('payments')">Pay Now</button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this booking?');">
                                        <input type="hidden" name="action" value="cancel_booking">
                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Cancel</button>
                                    </form>
                                <?php elseif ($b['status'] === 'confirmed'): ?>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this booking?');">
                                        <input type="hidden" name="action" value="cancel_booking">
                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Cancel</button>
                                    </form>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================= PAYMENTS ================= -->
    <div class="tab-panel" id="panel-payments">
        <?php if (!empty($pending_bookings)): ?>
            <h3>Awaiting Payment</h3>
            <table style="margin-bottom: 24px;">
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>Dates</th>
                        <th>Amount Due</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_bookings as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['machine_name']) ?></td>
                            <td><?= htmlspecialchars($b['start_date']) ?> &rarr; <?= htmlspecialchars($b['end_date']) ?></td>
                            <td>KSh <?= number_format($b['total_price'], 2) ?></td>
                            <td>
                                <button class="btn btn-primary btn-sm"
                                        onclick="openPaymentModal(<?= (int) $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['machine_name'])) ?>', <?= (float) $b['total_price'] ?>)">
                                    Pay Now
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Payment History</h3>
        <?php if (empty($payments)): ?>
            <p class="empty-state">No payments recorded yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Paid At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['machine_name']) ?></td>
                            <td>KSh <?= number_format($p['amount'], 2) ?></td>
                            <td><?= htmlspecialchars(ucfirst($p['method'])) ?></td>
                            <td><span class="status-badge status-confirmed"><?= htmlspecialchars(ucfirst($p['status'])) ?></span></td>
                            <td><?= htmlspecialchars($p['paid_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================= REVIEWS ================= -->
    <div class="tab-panel" id="panel-reviews">
        <h3>Share your experience</h3>
        <p class="page-subtitle">Reviews are verified against completed rentals.</p>
        <?php if (empty($completed_machines)): ?>
            <p class="empty-state">Complete a rental to leave a verified machinery review.</p>
        <?php else: ?>
            <form method="POST" class="review-form">
                <input type="hidden" name="action" value="submit_review">
                <label for="review-machine">Machine</label>
                <select name="machinery_id" id="review-machine" required>
                    <option value="">Select completed rental</option>
                    <?php foreach ($completed_machines as $machine): ?>
                        <option value="<?= (int) $machine['id'] ?>"><?= htmlspecialchars($machine['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="review-rating">Rating</label>
                <select name="rating" id="review-rating" required>
                    <option value="">Choose stars</option>
                    <option value="5">5 - Excellent</option>
                    <option value="4">4 - Very good</option>
                    <option value="3">3 - Good</option>
                    <option value="2">2 - Needs improvement</option>
                    <option value="1">1 - Poor</option>
                </select>
                <label for="review-comment">Your review</label>
                <textarea name="comment" id="review-comment" maxlength="1000" placeholder="How did the machinery perform?" required></textarea>
                <button type="submit" class="btn btn-primary">Publish verified review</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- ================= PRINT REPORT ================= -->
    <div class="tab-panel" id="panel-report">
        <button class="btn btn-primary btn-print no-print" onclick="window.print()" style="margin-bottom: 16px;">
            Print Report
        </button>

        <div id="report-content">
            <h2>Rental Report</h2>
            <div class="report-meta">
                Customer: <?= htmlspecialchars($_SESSION['full_name']) ?> &nbsp;|&nbsp;
                Generated: <?= date('d M Y, H:i') ?>
            </div>

            <h3>Bookings</h3>
            <?php if (empty($bookings)): ?>
                <p>No bookings to report.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Machine</th><th>Dates</th><th>Total</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['machine_name']) ?></td>
                                <td><?= htmlspecialchars($b['start_date']) ?> to <?= htmlspecialchars($b['end_date']) ?></td>
                                <td>KSh <?= number_format($b['total_price'], 2) ?></td>
                                <td><?= htmlspecialchars(ucfirst($b['status'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3 style="margin-top: 24px;">Payments</h3>
            <?php if (empty($payments)): ?>
                <p>No payments to report.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Machine</th><th>Amount</th><th>Method</th><th>Paid At</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['machine_name']) ?></td>
                                <td>KSh <?= number_format($p['amount'], 2) ?></td>
                                <td><?= htmlspecialchars(ucfirst($p['method'])) ?></td>
                                <td><?= htmlspecialchars($p['paid_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top: 12px; font-weight: 700;">Total Paid: KSh <?= number_format($total_spent, 2) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ================= BOOKING MODAL ================= -->
<div class="modal-backdrop" id="booking-modal">
    <div class="modal">
        <h3>Book Machinery</h3>
        <form method="POST" id="booking-form">
            <input type="hidden" name="action" value="create_booking">
            <input type="hidden" name="machinery_id" id="booking-machinery-id">

            <label>Machine</label>
            <input type="text" id="booking-machine-name" disabled>

            <label for="booking-start">Start Date</label>
            <input type="date" name="start_date" id="booking-start" required>

            <label for="booking-end">End Date</label>
            <input type="date" name="end_date" id="booking-end" required>

            <label for="booking-document-type">Identification document</label>
            <select name="id_document_type" id="booking-document-type" required>
                <option value="">Select document</option>
                <option value="identification_card">Identification Card</option>
                <option value="passport">Passport</option>
            </select>

            <label for="booking-document-number">Document number</label>
            <input type="text" name="id_document_number" id="booking-document-number" maxlength="100" required>

            <div class="driver-note"><strong>Company driver included</strong><br>Every machinery rental comes with a trained company driver.</div>

            <div class="modal-price" id="booking-price-preview"></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('booking-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Booking</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= PAYMENT MODAL ================= -->
<div class="modal-backdrop" id="payment-modal">
    <div class="modal">
        <h3>Make Payment</h3>
            <form id="payment-form">
            <input type="hidden" name="booking_id" id="payment-booking-id">

            <label>Machine</label>
            <input type="text" id="payment-machine-name" disabled>

            <div class="modal-price" id="payment-amount-preview"></div>

            <label for="payment-phone">M-Pesa phone number</label>
            <input type="tel" name="phone_number" id="payment-phone" placeholder="0712345678 or +254712345678" pattern="(0[17][0-9]{8}|\+254[17][0-9]{8})" inputmode="tel" required>
            <p class="driver-note">You will receive an M-Pesa prompt on this phone. Enter your PIN to complete payment.</p>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('payment-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="payment-submit">Pay with M-Pesa</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentPricePerDay = 0;

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

    // Reconcile pending M-Pesa payments when a customer returns to the dashboard.
    fetch('../payments/mpesa_autocheck.php', { method: 'POST' }).catch(() => {});

    // Activate the tab from ?tab= on load
    const initialTab = <?= json_encode($active_tab) ?>;
    switchTab(['browse', 'bookings', 'payments', 'reviews', 'report'].includes(initialTab) ? initialTab : 'browse');

    // ---- Booking modal ----
    function openBookingModal(machineryId, machineName, pricePerDay) {
        currentPricePerDay = pricePerDay;
        document.getElementById('booking-machinery-id').value = machineryId;
        document.getElementById('booking-machine-name').value = machineName;
        document.getElementById('booking-price-preview').textContent = '';

        const today = new Date().toISOString().split('T')[0];
        document.getElementById('booking-start').min = today;
        document.getElementById('booking-end').min = today;
        document.getElementById('booking-start').value = '';
        document.getElementById('booking-end').value = '';

        document.getElementById('booking-modal').classList.add('open');
    }

    function updateBookingPricePreview() {
        const start = document.getElementById('booking-start').value;
        const end = document.getElementById('booking-end').value;
        const preview = document.getElementById('booking-price-preview');

        if (start && end && end >= start) {
            const days = Math.round((new Date(end) - new Date(start)) / 86400000) + 1;
            preview.textContent = days + ' day(s) x KSh ' + currentPricePerDay.toFixed(2) + ' = KSh ' + (days * currentPricePerDay).toFixed(2);
        } else {
            preview.textContent = '';
        }
    }
    document.getElementById('booking-start').addEventListener('change', updateBookingPricePreview);
    document.getElementById('booking-end').addEventListener('change', updateBookingPricePreview);

    // ---- Payment modal ----
    function openPaymentModal(bookingId, machineName, amount) {
        document.getElementById('payment-booking-id').value = bookingId;
        document.getElementById('payment-machine-name').value = machineName;
        document.getElementById('payment-amount-preview').textContent = 'Amount due: KSh ' + amount.toFixed(2);
        document.getElementById('payment-phone').value = '';
        document.getElementById('payment-submit').disabled = false;
        document.getElementById('payment-modal').classList.add('open');
    }

    document.getElementById('payment-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = document.getElementById('payment-submit');
        const bookingId = document.getElementById('payment-booking-id').value;
        const phone = document.getElementById('payment-phone').value.trim();
        submit.disabled = true;
        submit.textContent = 'Sending prompt...';
        try {
            const response = await fetch('../payments/mpesa_initiate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ booking_id: bookingId, phone_number: phone })
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.error || 'M-Pesa initiation failed.');
            submit.textContent = 'Waiting for payment...';
            pollMpesaStatus(bookingId, result.checkout_request_id);
        } catch (error) {
            alert(error.message);
            submit.disabled = false;
            submit.textContent = 'Pay with M-Pesa';
        }
    });

    function pollMpesaStatus(bookingId, checkoutRequestId) {
        let attempts = 0;
        const poll = async () => {
            attempts++;
            const query = new URLSearchParams({ booking_id: bookingId, checkout_request_id: checkoutRequestId || '' });
            const result = await fetch('../payments/mpesa_status.php?' + query).then(response => response.json());
            if (result.status === 'completed') {
                window.location.href = 'index.php?tab=payments';
            } else if (result.status === 'failed' || attempts >= 24) {
                alert(result.error || 'Payment was not completed. You can try again.');
                document.getElementById('payment-submit').disabled = false;
                document.getElementById('payment-submit').textContent = 'Pay with M-Pesa';
            } else {
                setTimeout(poll, 5000);
            }
        };
        poll();
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
    }

    // Close modal when clicking outside it
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) backdrop.classList.remove('open');
        });
    });
</script>

</body>
</html>