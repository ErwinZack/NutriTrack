<?php
// --------------------------------------
// DATABASE & FUNCTIONS
// --------------------------------------
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit_log.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}

$admin_id        = $_SESSION['admin_id'];
$admin_full_name = $_SESSION['admin_full_name'] ?? $_SESSION['full_name'] ?? 'Unknown';
$barangay_id     = $_SESSION['barangay_id'];

// --------------------------------------
// VALIDATE worker_id
// --------------------------------------
if (!isset($_GET['worker_id']) || !is_numeric($_GET['worker_id'])) {
    header("Location: user_management.php");
    exit();
}

$worker_id = (int) $_GET['worker_id'];

// Fetch worker to confirm existence and get name for audit log
$stmt = $pdo->prepare("SELECT worker_id, full_name, username FROM health_workers WHERE worker_id = :id AND barangay_id = :brgy LIMIT 1");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id]);
$worker = $stmt->fetch();

if (!$worker) {
    header("Location: user_management.php");
    exit();
}

// --------------------------------------
// DELETE
// --------------------------------------
$stmt = $pdo->prepare("DELETE FROM health_workers WHERE worker_id = :id AND barangay_id = :brgy");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id]);

logAudit(
    $pdo,
    'admin',
    (int) $admin_id,
    'DELETE_USER',
    'Admin \'' . $admin_full_name . '\' deleted health worker: ' . $worker['full_name'] .
    ', Username: ' . $worker['username'],
    $barangay_id
);

// [NOTIFICATION] Notify municipal — admin deleted a health worker
$superadmins = $pdo->query("SELECT superadmin_id FROM superadmins");
foreach ($superadmins->fetchAll() as $sa) {
    $saStmt = $pdo->prepare("
        INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message, barangay_id)
        VALUES (:id, 'superadmin', :sid, 'admin', 'warning', :msg, :barangay_id)
    ");
    $saStmt->execute([
        ':id'          => $sa['superadmin_id'],
        ':sid'         => $admin_id,
        ':msg'         => "Admin '{$admin_full_name}' deleted health worker: {$worker['full_name']}.",
        ':barangay_id' => $barangay_id,
    ]);
}

// --------------------------------------
// REDIRECT BACK
// --------------------------------------
header("Location: user_management.php");
exit();