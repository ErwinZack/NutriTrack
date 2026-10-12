<?php
session_start();
require_once "../../config/config.php";
require_once "../../includes/audit_log.php";

// [AUDIT] Capture session data before destroying it
$superadmin_id        = (int) ($_SESSION['superadmin_id'] ?? 0);
$superadmin_full_name = $_SESSION['full_name'] ?? 'Unknown';

// [AUDIT] Log the logout before session is destroyed
logAudit(
    $pdo,
    'superadmin',
    $superadmin_id,
    'LOGOUT',
    'Municipal \'' . $superadmin_full_name . '\' logged out.'
);

// NOTE: MFA trust cookie is intentionally NOT cleared on logout.
// The trusted device window (8 hours) expires naturally.
// This allows the user to log back in without OTP within the cooldown window.

// Destroy all session data
session_unset();
session_destroy();

// Redirect to municipal login page
header("Location: login_municipal.php");
exit();