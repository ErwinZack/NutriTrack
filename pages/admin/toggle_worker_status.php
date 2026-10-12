<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_admin.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}
$barangay_id = $_SESSION['barangay_id'];

$worker_id = (int)($_GET['worker_id'] ?? 0);
$action    = $_GET['action'] ?? '';

if ($worker_id && in_array($action, ['activate', 'deactivate'])) {
    $status = $action === 'activate' ? 'Active' : 'Inactive';
    $stmt   = $pdo->prepare("UPDATE health_workers SET status = :status WHERE worker_id = :id AND barangay_id = :brgy");
    $stmt->execute([':status' => $status, ':id' => $worker_id, ':brgy' => $barangay_id]);
}

header("Location: user_management.php");
exit();