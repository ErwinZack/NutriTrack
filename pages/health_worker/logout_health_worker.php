<?php
require_once "../../config/config.php";
require_once "../../includes/audit_log.php";

// [SECURITY] Save session data BEFORE destroying — needed for audit log
$worker_id = (int) ($_SESSION['worker_id'] ?? 0);
$full_name = $_SESSION['full_name'] ?? 'Unknown';
$barangay_id = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null;

// [SECURITY] Log logout before session is destroyed
// Only log if this was an actual logged-in session (avoids "Unknown" #0 entries)
if ($worker_id > 0) {
    logAudit($pdo, 'health_worker', $worker_id, 'LOGOUT', "Health worker '$full_name' logged out.", $barangay_id);
}

// NOTE: MFA trust cookie is intentionally NOT cleared on logout.
// The trusted device window (8 hours) expires naturally.
// This allows the user to log back in without OTP within the cooldown window.

// [SECURITY] Unset all session variables
$_SESSION = [];

// [SECURITY] Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// [SECURITY] Destroy the session
session_destroy();

// Redirect to login page
header("Location: /NutriTrack/pages/health_worker/login_health_worker.php");
exit();