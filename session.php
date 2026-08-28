<?php
// Session bootstrap + helper functions used across the site

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function current_user_role(): ?string {
    return $_SESSION['role'] ?? null;
}

// Call at the top of any page that requires login.
// Optionally restrict to specific roles, e.g. require_login(['admin']).
function require_login(array $allowed_roles = []): void {
    if (!is_logged_in()) {
        header('Location: /machinery-rental-system/auth/login.php');
        exit;
    }
    if (!empty($allowed_roles) && !in_array(current_user_role(), $allowed_roles, true)) {
        http_response_code(403);
        die('Access denied: you do not have permission to view this page.');
    }
}
