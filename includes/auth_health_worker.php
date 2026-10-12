<?php
// includes/auth_health_worker.php
require_once __DIR__ . '/../config/config.php';

// Redirect to login if not logged in or wrong role
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: /NutriTrack/pages/health_worker/login_health_worker.php");
    exit();
}

// [SECURITY] Auto-logout if session has been idle longer than SESSION_TIMEOUT
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header("Location: /NutriTrack/pages/health_worker/login_health_worker.php?timeout=1");
    exit();
}

// [SECURITY] Update last activity time on every page load
$_SESSION['last_activity'] = time();