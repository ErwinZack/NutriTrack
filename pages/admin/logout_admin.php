<?php
session_start();
require_once "../../config/config.php";
require_once "../../includes/audit_log.php";

// [AUDIT] Capture session data before destroying it
$admin_id        = (int) ($_SESSION['admin_id'] ?? 0);
$admin_full_name = $_SESSION['full_name'] ?? 'Unknown';
$admin_barangay_id = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null;

// [AUDIT] Log the logout before session is destroyed
if ($admin_id > 0) {
    logAudit(
        $pdo,
        'admin',
        $admin_id,
        'LOGOUT',
        'Admin \'' . $admin_full_name . '\' logged out.',
        $admin_barangay_id
    );
}

// NOTE: MFA trust cookie is intentionally NOT cleared on logout.
// The trusted device window (8 hours) expires naturally.
// This allows the user to log back in without OTP within the cooldown window.

// Destroy all session data
session_unset();
session_destroy();

// Redirect to admin login page
header("Location: login_admin.php");
exit();