<?php
/* mark_read_health_worker.php — Mark single or all health worker notifications as read */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once "../../config/config.php";

/* Security — health worker only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'health_worker' || !isset($_SESSION['worker_id'])) {
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

$workerId = (int) $_SESSION['worker_id'];
$data     = json_decode(file_get_contents('php://input'), true);
$id       = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : 0;

if ($id > 0) {
    /* Mark single notification as read */
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET    is_read = 1
        WHERE  notification_id = :id
          AND  recipient_id    = :rid
          AND  recipient_role  = 'health_worker'
    ");
    $stmt->execute([':id' => $id, ':rid' => $workerId]);
} else {
    /* Mark all unread notifications as read for this health worker */
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET    is_read = 1
        WHERE  recipient_id   = :rid
          AND  recipient_role = 'health_worker'
          AND  is_read        = 0
    ");
    $stmt->execute([':rid' => $workerId]);
}

header('Content-Type: application/json');
echo json_encode(['success' => true]);