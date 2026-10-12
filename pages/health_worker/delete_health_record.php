<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit_log.php';

header('Content-Type: application/json');

if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$barangay_id = $_SESSION['barangay_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;

    if ($id > 0) {
        // Fetch record info before deleting — for a meaningful audit log entry
        $stmt = $pdo->prepare("
            SELECT hr.record_id, hr.record_date, hr.weight, hr.height, hr.bmi,
                   r.first_name, r.last_name
            FROM health_records hr
            INNER JOIN residents r ON hr.resident_id = r.resident_id
            WHERE hr.record_id = :id AND hr.barangay_id = :brgy
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':brgy' => $barangay_id]);
        $record = $stmt->fetch();

        // [SECURITY] Block deletion if record doesn't belong to this worker's barangay
        if (!$record) {
            echo json_encode(['success' => false, 'message' => 'Record not found']);
            exit();
        }

        $record_info = "{$record['first_name']} {$record['last_name']} on {$record['record_date']} (Weight: {$record['weight']}kg, Height: {$record['height']}cm, BMI: {$record['bmi']})";

        $resident_name = "{$record['first_name']} {$record['last_name']}";

        // Delete the record
        $stmt = $pdo->prepare("DELETE FROM health_records WHERE record_id = :id AND barangay_id = :brgy");
        $stmt->execute([':id' => $id, ':brgy' => $barangay_id]);

        // [SECURITY] Log delete health record action
        $worker_id = (int) $_SESSION['worker_id'];
        $nameStmt = $pdo->prepare("SELECT full_name FROM health_workers WHERE worker_id = :id LIMIT 1");
        $nameStmt->execute([':id' => $worker_id]);
        $nameRow = $nameStmt->fetch();
        $worker_full_name = $nameRow['full_name'] ?? 'Unknown';
        logAudit($pdo, 'health_worker', $worker_id, 'DELETE_HEALTH_RECORD', "Worker '$worker_full_name' deleted health record for: $record_info.", $barangay_id);

        // Notify only this barangay's admin(s) — health worker deleted a health record
        $adminStmt = $pdo->prepare("SELECT admin_id FROM admins WHERE barangay_id = :brgy");
        $adminStmt->execute([':brgy' => $barangay_id]);
        foreach ($adminStmt->fetchAll() as $admin) {
            $nStmt = $pdo->prepare("INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message) VALUES (:id, 'admin', :sid, 'health_worker', 'info', :msg)");
            $nStmt->execute([':id' => $admin['admin_id'], ':sid' => $worker_id, ':msg' => "$worker_full_name deleted a health record for $resident_name."]);
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}