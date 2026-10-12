<?php
// includes/audit_log.php

// --------------------------------------
// [SECURITY] Audit Logging
// Records sensitive actions in the audit_log table.
// Only ADD_HEALTH_RECORD is written to the Ganache blockchain
// as the significant tamper-evident transaction for the
// Barangay Health Center of San Miguel, Calasiao.
// --------------------------------------

require_once __DIR__ . '/../blockchain/blockchain_service.php';

/**
 * Log a sensitive action to the audit_log table.
 * Only ADD_HEALTH_RECORD is also written to the blockchain.
 *
 * @param PDO    $pdo         Database connection
 * @param string $role        Who performed the action: 'admin' or 'health_worker'
 * @param int    $user_id     ID of the admin or health worker performing the action
 * @param string $action      Short action name e.g. 'LOGIN', 'ADD_RESIDENT', 'ADD_HEALTH_RECORD'
 * @param string $description Longer description of what happened
 */
function logAudit(PDO $pdo, string $role, int $user_id, string $action, string $description = '', ?int $barangay_id = null): void {

    // ── 1. Write to MySQL audit_log table (all actions) ───────
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $stmt = $pdo->prepare("
            INSERT INTO audit_log (role, user_id, action, description, ip_address, created_at, barangay_id)
            VALUES (:role, :user_id, :action, :description, :ip_address, NOW(), :barangay_id)
        ");
        $stmt->execute([
            ':role'        => $role,
            ':user_id'     => $user_id,
            ':action'      => $action,
            ':description' => $description,
            ':ip_address'  => $ip_address,
            ':barangay_id' => $barangay_id,
        ]);
    } catch (PDOException $e) {
        // Silently fail — audit logging should never crash the app
        error_log('Audit log error: ' . $e->getMessage());
    }

    // ── 2. Write to Blockchain (ADD_HEALTH_RECORD only) ───────
    // Only the most significant action is recorded on-chain:
    // adding a health/nutrition record for a barangay resident.
    if ($action === 'ADD_HEALTH_RECORD') {
        try {
            logToBlockchain($role, $user_id, $action, $description);
        } catch (Exception $e) {
            // Silently fail — blockchain logging should never crash the app
            error_log('Blockchain log error: ' . $e->getMessage());
        }
    }
}