<?php
/* get_notifications_admin.php — Fetch latest 5 notifications for admin */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once "../../config/config.php";

/* Security — admin only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin' || !isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$adminId = (int) $_SESSION['admin_id'];

/* Fetch latest 5 notifications with sender info */
$stmt = $pdo->prepare("
    SELECT
        n.notification_id,
        n.message,
        n.is_read,
        n.created_at,
        n.sender_id,
        n.sender_role,
        hw.full_name     AS hw_full_name,
        hw.profile_image  AS hw_profile_image,
        a.full_name      AS a_full_name,
        a.profile_image   AS a_profile_image
    FROM notifications n
    LEFT JOIN health_workers hw ON n.sender_id = hw.worker_id AND n.sender_role = 'health_worker'
    LEFT JOIN admins a          ON n.sender_id = a.admin_id   AND n.sender_role = 'admin'
    WHERE n.recipient_id   = :id
      AND n.recipient_role = 'admin'
    ORDER BY n.created_at DESC
    LIMIT 5
");
$stmt->execute([':id' => $adminId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Resolve sender_name and sender_image per row */
$notifications = array_map(function($n) {
    if (!empty($n['sender_id']) && $n['sender_role'] === 'health_worker') {
        $n['sender_name']  = $n['hw_full_name']     ?? null;
        $n['sender_image'] = $n['hw_profile_image'] ?? null;
    } elseif (!empty($n['sender_id']) && $n['sender_role'] === 'admin') {
        $n['sender_name']  = $n['a_full_name']     ?? null;
        $n['sender_image'] = $n['a_profile_image'] ?? null;
    } else {
        $n['sender_name']  = null;
        $n['sender_image'] = null;
    }
    /* Remove raw join columns — keep response clean */
    unset($n['hw_full_name'], $n['hw_profile_image'], $n['a_full_name'], $n['a_profile_image']);
    return $n;
}, $rows);

header('Content-Type: application/json');
echo json_encode($notifications);