<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --------------------------------------
// DATABASE & FUNCTIONS
// --------------------------------------
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once '../../includes/auth_admin.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}

// Get admin info for PDF
$admin_id = (int) $_SESSION['admin_id'];
$barangay_id = $_SESSION['barangay_id'];

$adminStmt = $pdo->prepare("SELECT full_name FROM admins WHERE admin_id = :id LIMIT 1");
$adminStmt->execute([':id' => $admin_id]);
$adminRow = $adminStmt->fetch();
$admin_full_name = $adminRow['full_name'] ?? 'Admin';

$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $barangay_id]);
$barangay_name = $brgyStmt->fetchColumn();

// --------------------------------------
// HANDLE BULK DELETE (POST)
// --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ids'])) {
    $ids = array_filter(array_map('intval', (array) $_POST['delete_ids']));
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM audit_log WHERE log_id IN ($ph) AND barangay_id = ?");
        $ids[] = $barangay_id;
        $stmt->execute($ids);
    }
    header("Location: audit_logs.php");
    exit();
}

// --------------------------------------
// SIGNIFICANT ACTIONS ONLY — load all at once (Barangay-scoped)
// --------------------------------------
$significant_actions = [
    'ADD_RESIDENT',
    'EDIT_RESIDENT',
    'DELETE_RESIDENT',
    'ADD_HEALTH_RECORD',
    'EDIT_HEALTH_RECORD',
    'DELETE_HEALTH_RECORD',
];

$placeholders = implode(',', array_fill(0, count($significant_actions), '?'));
$significant_actions[] = $barangay_id;

$stmt = $pdo->prepare("
    SELECT *
    FROM audit_log
    WHERE action IN ($placeholders)
      AND barangay_id = ?
    ORDER BY created_at DESC
");
$stmt->execute($significant_actions);
$logs = $stmt->fetchAll();

// --------------------------------------
// ACTION BADGE HELPER
// --------------------------------------
function actionBadge(string $action): string
{
    $map = [
        'ADD_USER' => ['label' => 'Add User', 'color' => 'green'],
        'EDIT_USER' => ['label' => 'Edit User', 'color' => 'amber'],
        'DELETE_USER' => ['label' => 'Delete User', 'color' => 'red'],
        'ADD_RESIDENT' => ['label' => 'Add Resident', 'color' => 'green'],
        'EDIT_RESIDENT' => ['label' => 'Edit Resident', 'color' => 'amber'],
        'DELETE_RESIDENT' => ['label' => 'Delete Resident', 'color' => 'red'],
        'ADD_HEALTH_RECORD' => ['label' => 'Add Record', 'color' => 'green'],
        'EDIT_HEALTH_RECORD' => ['label' => 'Edit Record', 'color' => 'amber'],
        'DELETE_HEALTH_RECORD' => ['label' => 'Delete Record', 'color' => 'red'],
    ];

    $entry = $map[$action] ?? ['label' => $action, 'color' => 'gray'];
    return '<span class="audit-action-badge audit-action-badge--' . $entry['color'] . '">'
        . htmlspecialchars($entry['label'])
        . '</span>';
}

// --------------------------------------
// ROLE BADGE HELPER
// --------------------------------------
function roleBadge(string $role): string
{
    if ($role === 'admin') {
        return '<span class="audit-role-badge audit-role-badge--admin">
                    <i class="fas fa-shield-alt"></i> Admin
                </span>';
    }
    return '<span class="audit-role-badge audit-role-badge--worker">
                <i class="fas fa-user-nurse"></i> Health Worker
            </span>';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Audit Logs | NutriTrack Admin</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>

<body>

    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <?php include __DIR__ . '/../../includes/sidebar_admin.php'; ?>

    <!-- jsPDF Core -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <!-- jsPDF AutoTable Plugin -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>
        window.CURRENT_WORKER_NAME = <?= json_encode($admin_full_name) ?>;
        window.CURRENT_BARANGAY_NAME = <?= json_encode($barangay_name ?? 'Barangay Health Center') ?>;
    </script>
    <!-- Audit Logs PDF Export -->
    <script src="../../assets/js/audit_logs_pdf.js"></script>
    <!-- Hidden logos for jsPDF -->
    <img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
    <img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
        style="display:none;">

    <main class="dashboard">

        <h1 class="page-title">Audit Logs</h1>

        <section class="section">

            <form method="POST" id="auditDeleteForm">

                <!-- ── Top bar: Delete Selected (always visible) ── -->
                <div class="admin-table-topbar">
                    <button onclick="generateAuditLogsPDF()" class="admin-btn admin-btn-export">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button type="button" id="auditDeleteBtn" onclick="confirmBulkDelete()"
                        class="admin-btn admin-btn-delete">
                        <i class="fas fa-trash"></i>
                        <span id="auditDeleteLabel">Delete Selected</span>
                    </button>
                </div>

                <!-- ── Filter Bar ── -->
                <div class="admin-filter-bar">

                    <div class="admin-filter-search">
                        <input type="text" id="auditSearch" placeholder="Search description..."
                            oninput="filterAuditTable()" autocomplete="off">
                    </div>

                    <select id="auditRoleFilter" onchange="filterAuditTable()">
                        <option value="">All Roles</option>
                        <option value="admin">Admin</option>
                        <option value="health_worker">Health Worker</option>
                    </select>

                    <select id="auditActionFilter" onchange="filterAuditTable()">
                        <option value="">All Actions</option>
                        <optgroup label="Health Worker - Residents">
                            <option value="ADD_RESIDENT">Add Resident</option>
                            <option value="EDIT_RESIDENT">Edit Resident</option>
                            <option value="DELETE_RESIDENT">Delete Resident</option>
                        </optgroup>
                        <optgroup label="Health Worker - Records">
                            <option value="ADD_HEALTH_RECORD">Add Record</option>
                            <!-- <option value="EDIT_HEALTH_RECORD">Edit Record</option> -->
                            <option value="DELETE_HEALTH_RECORD">Delete Record</option>
                        </optgroup>
                    </select>

                    <div class="admin-filter-date-wrap">
                        <input type="date" id="auditDateFilter" onchange="filterAuditTable()">
                    </div>

                    <button type="button" onclick="clearAuditFilters()" class="admin-btn admin-btn-cancel"
                        id="auditClearBtn" style="display:none;">
                        <i class="fas fa-times"></i> Clear
                    </button>

                    <span class="admin-filter-count" id="auditCount"></span>

                </div>

                <!-- ── Table ── -->
                <?php if (empty($logs)): ?>
                    <div class="admin-empty-state">
                        <i class="fas fa-clock-rotate-left"></i>
                        <p>No audit log entries found.</p>
                    </div>
                <?php else: ?>

                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead class="admin-table-head">
                                <tr>
                                    <th style="width:40px;">
                                        <input type="checkbox" id="auditSelectAll" class="audit-checkbox"
                                            onchange="toggleSelectAll(this)" title="Select all visible">
                                    </th>
                                    <th>#</th>
                                    <th>Role</th>
                                    <th>User ID</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                    <!-- <th>IP Address</th> -->
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody class="admin-table-body" id="auditTableBody">
                                <?php foreach ($logs as $i => $log): ?>
                                    <tr data-log-id="<?= (int) $log['log_id'] ?>"
                                        data-role="<?= htmlspecialchars($log['role']) ?>"
                                        data-action="<?= htmlspecialchars($log['action']) ?>"
                                        data-date="<?= date('Y-m-d', strtotime($log['created_at'])) ?>"
                                        data-description="<?= strtolower(htmlspecialchars($log['description'] ?? '')) ?>">
                                        <td style="width:40px;">
                                            <input type="checkbox" name="delete_ids[]" value="<?= (int) $log['log_id'] ?>"
                                                class="audit-checkbox audit-row-checkbox" onchange="updateDeleteBtn()">
                                        </td>
                                        <td class="audit-td-num audit-row-num">
                                            <?= $i + 1 ?>
                                        </td>
                                        <td>
                                            <?= roleBadge($log['role']) ?>
                                        </td>
                                        <td class="audit-td-id">
                                            #<?= htmlspecialchars($log['user_id']) ?>
                                        </td>
                                        <td>
                                            <?= actionBadge($log['action']) ?>
                                        </td>
                                        <td class="audit-td-desc">
                                            <?= htmlspecialchars($log['description'] ?? '—') ?>
                                        </td>
                                        <!-- <td class="audit-td-ip">
                                            <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
                                        </td> -->
                                        <td class="audit-td-date">
                                            <?= date('M d, Y h:i A', strtotime($log['created_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Empty state shown by JS ── -->
                    <div id="auditEmptyState" style="display:none;" class="admin-empty-state">
                        <i class="fas fa-clock-rotate-left"></i>
                        <p id="auditEmptyMsg">No audit log entries match your filters.</p>
                    </div>

                <?php endif; ?>

            </form><!-- end auditDeleteForm -->

        </section>
    </main>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>

    <script>
        // ============================================================
        //  AUDIT LOGS — Filter + Search
        // ============================================================

        function filterAuditTable() {
            const search = (document.getElementById('auditSearch')?.value || '').toLowerCase().trim();
            const role = (document.getElementById('auditRoleFilter')?.value || '').toLowerCase();
            const action = (document.getElementById('auditActionFilter')?.value || '');
            const date = (document.getElementById('auditDateFilter')?.value || '');

            const rows = document.querySelectorAll('#auditTableBody tr');
            const emptyEl = document.getElementById('auditEmptyState');
            const emptyMsg = document.getElementById('auditEmptyMsg');
            const countEl = document.getElementById('auditCount');
            const clearBtn = document.getElementById('auditClearBtn');

            let visible = 0;
            let counter = 1;

            rows.forEach(row => {
                const matchRole = !role || row.dataset.role === role;
                const matchAction = !action || row.dataset.action === action;
                const matchDate = !date || row.dataset.date === date;
                const matchSearch = !search || row.dataset.description.includes(search);

                const show = matchRole && matchAction && matchDate && matchSearch;
                row.style.display = show ? '' : 'none';

                if (show) {
                    const numCell = row.querySelector('.audit-row-num');
                    if (numCell) numCell.textContent = counter;
                    counter++;
                    visible++;
                }
            });

            if (countEl) {
                countEl.textContent = visible + ' ' + (visible === 1 ? 'entry' : 'entries');
                countEl.style.display = '';
            }

            if (clearBtn) {
                clearBtn.style.display = (search || role || action || date) ? '' : 'none';
            }

            if (emptyEl) {
                if (visible === 0 && rows.length > 0) {
                    const parts = [];
                    if (search) parts.push('"' + search + '"');
                    if (role) parts.push(role.replace('_', ' '));
                    if (action) parts.push(action.replace(/_/g, ' ').toLowerCase());
                    if (date) parts.push(date);
                    if (emptyMsg) emptyMsg.textContent = parts.length
                        ? 'No entries match: ' + parts.join(' · ') + '. Try adjusting your filters.'
                        : 'No audit log entries match your filters.';
                    emptyEl.style.display = '';
                } else {
                    emptyEl.style.display = 'none';
                }
            }

            // Uncheck select-all when filters change
            const selectAll = document.getElementById('auditSelectAll');
            if (selectAll) selectAll.checked = false;
            updateDeleteBtn();
        }

        function clearAuditFilters() {
            const s = document.getElementById('auditSearch');
            const r = document.getElementById('auditRoleFilter');
            const a = document.getElementById('auditActionFilter');
            const d = document.getElementById('auditDateFilter');
            if (s) s.value = '';
            if (r) r.value = '';
            if (a) a.value = '';
            if (d) d.value = '';
            filterAuditTable();
        }

        // ============================================================
        //  CHECKBOX + BULK DELETE
        // ============================================================

        function toggleSelectAll(masterCb) {
            document.querySelectorAll('#auditTableBody tr').forEach(row => {
                if (row.style.display === 'none') return;
                const cb = row.querySelector('.audit-row-checkbox');
                if (cb) cb.checked = masterCb.checked;
            });
            updateDeleteBtn();
        }

        function updateDeleteBtn() {
            const checked = document.querySelectorAll('.audit-row-checkbox:checked').length;
            const label = document.getElementById('auditDeleteLabel');

            // Update label with count — button stays visible always
            if (label) {
                label.textContent = checked > 0
                    ? 'Delete Selected (' + checked + ')'
                    : 'Delete Selected';
            }

            // Sync master checkbox indeterminate state
            const allVisible = Array.from(document.querySelectorAll('#auditTableBody tr'))
                .filter(r => r.style.display !== 'none')
                .map(r => r.querySelector('.audit-row-checkbox'))
                .filter(Boolean);

            const selectAll = document.getElementById('auditSelectAll');
            if (selectAll && allVisible.length > 0) {
                const allChecked = allVisible.every(cb => cb.checked);
                const someChecked = allVisible.some(cb => cb.checked);
                selectAll.checked = allChecked;
                selectAll.indeterminate = !allChecked && someChecked;
            }
        }

        function confirmBulkDelete() {
            const checked = document.querySelectorAll('.audit-row-checkbox:checked').length;
            if (checked === 0) {
                alert('Please select at least one entry to delete.');
                return;
            }
            if (confirm('Are you sure you want to delete ' + checked + ' audit log ' + (checked === 1 ? 'entry' : 'entries') + '? This cannot be undone.')) {
                document.getElementById('auditDeleteForm').submit();
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const now = new Date();
            const today = now.getFullYear() + '-' +
                String(now.getMonth() + 1).padStart(2, '0') + '-' +
                String(now.getDate()).padStart(2, '0');

            const dateInput = document.getElementById('auditDateFilter');
            if (dateInput) dateInput.value = today;

            filterAuditTable();

            const clearBtn = document.getElementById('auditClearBtn');
            if (clearBtn) clearBtn.style.display = 'none';
        });
    </script>

</body>

</html>