<?php
// includes/auth_admin.php
require_once __DIR__ . '/../config/config.php';

// Redirect to login if not logged in or wrong role
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /NutriTrack/pages/admin/login_admin.php");
    exit();
}

// [SECURITY] Auto-logout if session has been idle longer than SESSION_TIMEOUT
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header("Location: /NutriTrack/pages/admin/login_admin.php?timeout=1");
    exit();
}

// [SECURITY] Update last activity time on every page load
$_SESSION['last_activity'] = time();