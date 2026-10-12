<?php
/* ============================================================
   delete_notification.php — Shared notification delete endpoint
   Location: NutriTrack/includes/delete_notification.php
   Accepts: POST JSON { notification_id: int }
   Security: Only deletes if recipient_id + recipient_role match session
   ============================================================ */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/../config/config.php";

header('Content-Type: application/json');

/* ── Auth check — must be logged in as admin or health worker ── */
if (!isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'];

if ($role === 'admin' && isset($_SESSION['admin_id'])) {
    $recipientId   = (int) $_SESSION['admin_id'];
    $recipientRole = 'admin';
} elseif ($role === 'health_worker' && isset($_SESSION['worker_id'])) {
    $recipientId   = (int) $_SESSION['worker_id'];
    $recipientRole = 'health_worker';
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

/* ── Only accept POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/* ── Parse JSON body ── */
$data = json_decode(file_get_contents('php://input'), true);
$notificationId = isset($data['notification_id']) && is_numeric($data['notification_id'])
    ? (int) $data['notification_id']
    : 0;

if ($notificationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
    exit;
}

/* ── Delete — only if it belongs to the logged-in user ── */
$stmt = $pdo->prepare("
    DELETE FROM notifications
    WHERE notification_id = :notif_id
      AND recipient_id    = :recipient_id
      AND recipient_role  = :recipient_role
");
$stmt->execute([
    ':notif_id'       => $notificationId,
    ':recipient_id'   => $recipientId,
    ':recipient_role' => $recipientRole,
]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true, 'message' => 'Notification deleted']);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Notification not found or unauthorized']);
}