<?php
/* mark_read_admin.php — Mark single or all admin notifications as read */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once "../../config/config.php";

/* Security — admin only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin' || !isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

/* Accept POST requests only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

$adminId = (int) $_SESSION['admin_id'];
$data    = json_decode(file_get_contents('php://input'), true);
$id      = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : 0;

if ($id > 0) {
    /* Mark single notification as read */
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET    is_read = 1
        WHERE  notification_id = :id
          AND  recipient_id    = :rid
          AND  recipient_role  = 'admin'
    ");
    $stmt->execute([':id' => $id, ':rid' => $adminId]);
} else {
    /* Mark all unread notifications as read for this admin */
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET    is_read = 1
        WHERE  recipient_id   = :rid
          AND  recipient_role = 'admin'
          AND  is_read        = 0
    ");
    $stmt->execute([':rid' => $adminId]);
}

header('Content-Type: application/json');
echo json_encode(['success' => true]);