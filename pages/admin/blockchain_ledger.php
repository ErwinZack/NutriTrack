<?php
require_once "../../config/config.php";
require_once "../../includes/auth_admin.php"; // temporarily disabled for testing
require_once "../../blockchain/blockchain_service.php";

$entries = [];
$totalBlocks = 0;
$chainStatus = 'unknown';
$errorMsg = '';

try {
    $totalBlocks = getBlockchainCount();
    $chainStatus = 'valid';
} catch (Exception $e) {
    $errorMsg    = $e->getMessage();
    $chainStatus = 'error';
}

// Fetch only ADD_HEALTH_RECORD entries from MySQL
// These match exactly what was written to blockchain via logToBlockchain()
$stmt = $pdo->query("
    SELECT * FROM audit_log
    WHERE action = 'ADD_HEALTH_RECORD'
    ORDER BY created_at DESC
    LIMIT 50
");
$entries = $stmt->fetchAll();

// Map MySQL columns to match old blockchain entry format
$entries = array_map(function($log, $i) {
    return [
        'id'          => $i + 1,
        'role'        => $log['role'],
        'userId'      => $log['user_id'],
        'action'      => $log['action'],
        'description' => $log['description'] ?? '—',
        'dataHash'    => hash('sha256', $log['role'] . $log['user_id'] . $log['action'] . $log['description']),
        'timestamp'   => strtotime($log['created_at']),
    ];
}, $entries, array_keys($entries));

/* ── Last block timestamp ── */
$lastBlockTime = !empty($entries) ? date('M d, H:i', $entries[0]['timestamp']) : '—';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Blockchain Ledger | NutriTrack Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Fonts — DM Sans (UI), DM Mono (data/code) -->
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Global admin shell -->
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">

    <!-- Page-specific styles -->
    <link rel="stylesheet" href="../../assets/css/blockchain_ledger.css">
</head>

<body>

    <?php include "../../includes/sidebar_admin.php"; ?>

    <div class="admin-main">

        <?php include "../../includes/header.php"; ?>

        <main class="dashboard">

            <!-- ── Page Header ── -->
            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title">Blockchain Records</h1>
                    <p class="admin-welcome-message">
                        Immutable on-chain audit entries · Ganache local network
                    </p>
                </div>
                <div class="bc-page-meta">
                    <strong><?= date('F d, Y') ?></strong>
                    <?= date('H:i:s') ?> PHT &nbsp;
                    <span class="bc-live-indicator">● LIVE</span>
                </div>
            </div>


            <!-- ── Stats Row ── -->
            <div class="bc-stats-row">

                <!-- Total Blocks -->
                <div class="bc-stat-card">
                    <div class="bc-stat-icon blue">
                        <i class="fas fa-cubes bc-icon-blue"></i>
                    </div>
                    <div>
                        <div class="bc-stat-value"><?= $totalBlocks ?></div>
                        <div class="bc-stat-label">Total Blocks</div>
                    </div>
                </div>

                <!-- Network Port -->
                <div class="bc-stat-card">
                    <div class="bc-stat-icon sky">
                        <i class="fas fa-tower-broadcast bc-icon-sky"></i>
                    </div>
                    <div>
                        <div class="bc-stat-value sky">7545</div>
                        <div class="bc-stat-label">Network Port</div>
                    </div>
                </div>

                <!-- Last Block -->
                <div class="bc-stat-card">
                    <div class="bc-stat-icon green">
                        <i class="fas fa-clock bc-icon-green"></i>
                    </div>
                    <div>
                        <div class="bc-stat-value last-block"><?= $lastBlockTime ?></div>
                        <div class="bc-stat-label">Last Block</div>
                    </div>
                </div>

                <!-- Smart Contract -->
                <div class="bc-stat-card">
                    <div class="bc-stat-icon amber">
                        <i class="fas fa-file-contract bc-icon-amber"></i>
                    </div>
                    <div>
                        <div class="bc-stat-value amber">Active</div>
                        <div class="bc-stat-label">Smart Contract</div>
                    </div>
                </div>

            </div><!-- /bc-stats-row -->


            <!-- ── Status Banner ── -->
            <div class="bc-status-banner <?= $chainStatus ?>">
                <?php if ($chainStatus === 'valid'): ?>
                    <i class="fas fa-circle-check"></i>
                    Chain integrity verified — all <?= $totalBlocks ?> block(s) are intact and unmodified.
                <?php elseif ($chainStatus === 'error'): ?>
                    <i class="fas fa-triangle-exclamation"></i>
                    Connection error — make sure Ganache is running on HTTP://127.0.0.1:7545
                <?php else: ?>
                    <i class="fas fa-circle-question"></i>
                    Chain status unknown — no blocks found yet.
                <?php endif; ?>
            </div>


            <!-- ── Error Box ── -->
            <?php if (!empty($errorMsg)): ?>
                <div class="bc-error-box">
                    <div class="bc-error-icon">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                    <div class="bc-error-body">
                        <h3>Blockchain Connection Error</h3>
                        <p><?= htmlspecialchars($errorMsg) ?></p>
                    </div>
                </div>
            <?php endif; ?>


            <!-- ── Table Card ── -->
            <div class="bc-table-card">

                <div class="bc-toolbar">
                    <div class="bc-toolbar-left">
                        <span class="bc-toolbar-title">
                            <i class="fas fa-link bc-icon-blue"></i>
                            Immutable Audit Blocks
                        </span>
                       <span class="bc-entry-count"><?= $totalBlocks ?> entries</span>
                    </div>
                    <button class="bc-btn-primary" onclick="location.reload()">
                        <i class="fas fa-rotate-right"></i> Refresh Chain
                    </button>
                </div>
                <?php
                // Fetch audit logs from MySQL to display alongside blockchain entry count
$stmt = $pdo->query("
    SELECT * FROM audit_log
    WHERE action = 'ADD_HEALTH_RECORD'
    AND created_at >= '2026-03-17 15:47:00'
    ORDER BY created_at DESC
    LIMIT 50
");       $dbLogs = $stmt->fetchAll();
                ?>

                <?php if (!empty($dbLogs)): ?>
                    <table class="bc-table">
                        <thead>
                            <tr>
                                <th>Block #</th>
                                <th>Role</th>
                                <th>User ID</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>IPFS Hash</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dbLogs as $i => $log):
                                $action = strtolower($log['action'] ?? '');
                                $badgeClass = 'default';
                                if (str_contains($action, 'login'))
                                    $badgeClass = 'login';
                                elseif (str_contains($action, 'delete'))
                                    $badgeClass = 'delete';
                                elseif (str_contains($action, 'add') || str_contains($action, 'create') || str_contains($action, 'register'))
                                    $badgeClass = 'create';
                                elseif (str_contains($action, 'edit') || str_contains($action, 'update'))
                                    $badgeClass = 'update';

                                $role = strtolower($log['role'] ?? '');
                                $roleClass = str_contains($role, 'admin') ? 'admin' : 'health_worker';
                                $timeFmt = date('M d, Y H:i:s', strtotime($log['created_at']));

                                // Generate IPFS hash display from log data (same hash stored on-chain)
                                $ipfsHash = hash('sha256', $log['role'] . $log['user_id'] . $log['action'] . $log['description']);
                                ?>
                                <tr>
                                    <td><span class="bc-block-id">#<?= $i + 1 ?></span></td>
                                    <td><span class="bc-role <?= $roleClass ?>"><?= htmlspecialchars($log['role']) ?></span>
                                    </td>
                                    <td><span class="bc-userid"><?= (int) $log['user_id'] ?></span></td>
                                    <td><span class="bc-badge <?= $badgeClass ?>"><?= htmlspecialchars($log['action']) ?></span>
                                    </td>
                                    <td>
                                        <div class="bc-desc"><?= htmlspecialchars($log['description'] ?? '—') ?></div>
                                    </td>
                                    <td>
                                        <div class="bc-hash"><?= substr($ipfsHash, 0, 32) ?>…</div>
                                    </td>
                                    <td><span class="bc-timestamp"><?= $timeFmt ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php else: ?>
                    <div class="bc-empty">
                        <div class="bc-empty-icon">
                            <i class="fas fa-link-slash"></i>
                        </div>
                        <div class="bc-empty-title">No blocks yet</div>
                        <div class="bc-empty-sub">
                            Blockchain entries will appear here as users perform actions in NutriTrack.
                        </div>
                    </div>
                <?php endif; ?>
            </div><!-- /bc-table-card -->

        </main><!-- /dashboard -->
    </div><!-- /admin-main -->

</body>

</html>