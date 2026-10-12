<?php
/* mark_read_municipal.php — Mark all unread notifications as read for superadmin */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once "../../config/config.php";

/* Security — superadmin only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin' || !isset($_SESSION['superadmin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

$superadminId = (int) $_SESSION['superadmin_id'];

$stmt = $pdo->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE recipient_id   = :id
      AND recipient_role = 'superadmin'
      AND is_read        = 0
");
$stmt->execute([':id' => $superadminId]);

header('Content-Type: application/json');
echo json_encode(['success' => true]);