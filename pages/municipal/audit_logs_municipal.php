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

// --------------------------------------
// AUTHENTICATION (Municipal / Superadmin)
// --------------------------------------
if (!isset($_SESSION['superadmin_id'])) {
    header("Location: login_municipal.php");
    exit();
}

// --------------------------------------
// HANDLE BULK DELETE (POST)
// --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ids'])) {
    $ids = array_filter(array_map('intval', (array) $_POST['delete_ids']));
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM audit_log WHERE log_id IN ($ph)");
        $stmt->execute($ids);
    }
    header("Location: audit_logs_municipal.php");
    exit();
}

// --------------------------------------
// LOAD ALL LOGS (no barangay filter - town-wide view)
// --------------------------------------
$stmt = $pdo->query("
    SELECT a.*, b.barangay_name
    FROM audit_log a
    LEFT JOIN barangays b ON a.barangay_id = b.barangay_id
    ORDER BY a.created_at DESC
");
$logs = $stmt->fetchAll();

// Barangay list for the filter dropdown
$barangays = $pdo->query("
    SELECT barangay_id, barangay_name
    FROM barangays
    ORDER BY barangay_name ASC
")->fetchAll();

// Municipal-relevant actions only (whitelist)
$actions_in_logs = [
    'ADD_USER',
    'LOGIN_FAILED',
    'LOGIN_TRUSTED_DEVICE',
    'LOGOUT',
    'PROGRAM_CREATED',
    'PROGRAM_DELETED',
    'PROGRAM_UPDATED',
    'PROGRAM_PROGRESS_UPDATED',
    'PROGRAM_STATUS_UPDATED',
];

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

    $fallback = ucwords(strtolower(str_replace('_', ' ', $action)));
    $entry = $map[$action] ?? ['label' => $fallback, 'color' => 'gray'];
    return '<span class="audit-action-badge audit-action-badge--' . $entry['color'] . '">'
        . htmlspecialchars($entry['label'])
        . '</span>';
}

function actionLabel(string $action): string
{
    return ucwords(strtolower(str_replace('_', ' ', $action)));
}

// --------------------------------------
// ROLE BADGE HELPER
// --------------------------------------
function roleBadge(string $role): string
{
    if ($role === 'superadmin') {
        return '<span class="audit-role-badge audit-role-badge--admin">
                    <i class="fas fa-landmark"></i> Municipal
                </span>';
    }
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
    <title>Audit Logs | NutriTrack Municipal</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/municipal.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>window.MUNICIPAL_ADMIN_NAME = <?= json_encode($_SESSION['full_name'] ?? 'Municipal Admin') ?>;</script>
    <script src="../../assets/js/audit_logs_municipal_pdf.js"></script>
    <img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
    <img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
        style="display:none;">
</head>

<body>

    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <?php include __DIR__ . '/../../includes/sidebar_municipal.php'; ?>

    <main class="dashboard">

        <h1 class="page-title">Audit Logs</h1>

        <section class="section">

            <form method="POST" id="auditDeleteForm">

                <!-- ── Top bar ── -->
                <div class="admin-table-topbar">
                    <button onclick="generateAuditLogsMunicipalPDF()" class="admin-btn admin-btn-export">
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

                    <select id="auditBarangayFilter" onchange="filterAuditTable()">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int) $b['barangay_id'] ?>">
                                <?= htmlspecialchars($b['barangay_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="auditRoleFilter" onchange="filterAuditTable()">
                        <option value="">All Roles</option>
                        <option value="superadmin">Municipal</option>
                        <option value="admin">Admin</option>
                        <option value="health_worker">Health Worker</option>
                    </select>

                    <select id="auditActionFilter" onchange="filterAuditTable()">
                        <option value="">All Actions</option>
                        <option value="ADD_USER">Add User</option>
                        <option value="LOGIN_FAILED">Login Failed</option>
                        <option value="LOGIN_TRUSTED_DEVICE">Login Trusted Device</option>
                        <option value="LOGOUT">Logout</option>
                        <optgroup label="Programs">
                            <option value="PROGRAM_CREATED">Program Created</option>
                            <option value="PROGRAM_DELETED">Program Deleted</option>
                            <option value="PROGRAM_UPDATED">Program Updated</option>
                            <option value="PROGRAM_PROGRESS_UPDATED">Program Progress Updated</option>
                            <option value="PROGRAM_STATUS_UPDATED">Program Status Updated</option>
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
                                    <th>Barangay</th>
                                    <th>Role</th>
                                    <!-- <th>User ID</th> -->
                                    <th>Action</th>
                                    <th>Description</th>
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody class="admin-table-body" id="auditTableBody">
                                <?php foreach ($logs as $i => $log): ?>
                                    <tr data-log-id="<?= (int) $log['log_id'] ?>"
                                        data-barangay="<?= (int) ($log['barangay_id'] ?? 0) ?>"
                                        data-role="<?= htmlspecialchars($log['role']) ?>"
                                        data-action="<?= htmlspecialchars($log['action']) ?>"
                                        data-date="<?= date('Y-m-d', strtotime($log['created_at'])) ?>"
                                        data-description="<?= htmlspecialchars(mb_strtolower($log['description'] ?? '')) ?>">
                                        <td style="width:40px;">
                                            <input type="checkbox" name="delete_ids[]" value="<?= (int) $log['log_id'] ?>"
                                                class="audit-checkbox audit-row-checkbox" onchange="updateDeleteBtn()">
                                        </td>
                                        <td class="audit-td-num audit-row-num">
                                            <?= $i + 1 ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($log['barangay_name'] ?? '—') ?>
                                        </td>
                                        <td>
                                            <?= roleBadge($log['role']) ?>
                                        </td>
                                        <!-- <td class="audit-td-id">
                                            #<?= htmlspecialchars($log['user_id']) ?>
                                        </td> -->
                                        <td>
                                            <?= actionBadge($log['action']) ?>
                                        </td>
                                        <td class="audit-td-desc">
                                            <?= htmlspecialchars($log['description'] ?? '—') ?>
                                        </td>
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
            </form>
        </section>
    </main>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>

    <script>
        // ============================================================
        //  AUDIT LOGS (MUNICIPAL) — Filter + Search
        // ============================================================

        function filterAuditTable() {
            const search = (document.getElementById('auditSearch')?.value || '').toLowerCase().trim();
            const barangay = (document.getElementById('auditBarangayFilter')?.value || '');
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
                const matchBarangay = !barangay || row.dataset.barangay === barangay;
                const matchRole = !role || row.dataset.role === role;
                const matchAction = !action || row.dataset.action === action;
                const matchDate = !date || row.dataset.date === date;
                const matchSearch = !search || row.dataset.description.includes(search);

                const show = matchBarangay && matchRole && matchAction && matchDate && matchSearch;
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
                clearBtn.style.display = (search || barangay || role || action || date) ? '' : 'none';
            }

            if (emptyEl) {
                if (visible === 0 && rows.length > 0) {
                    const parts = [];
                    if (search) parts.push('"' + search + '"');
                    if (barangay) {
                        const sel = document.getElementById('auditBarangayFilter');
                        parts.push(sel.options[sel.selectedIndex].text.trim());
                    }
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
            // Reset master checkbox when filters change
            const selectAll = document.getElementById('auditSelectAll');
            if (selectAll) selectAll.checked = false;
            updateDeleteBtn();
        }

        function clearAuditFilters() {
            ['auditSearch', 'auditBarangayFilter', 'auditRoleFilter', 'auditActionFilter', 'auditDateFilter']
                .forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
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

            if (label) {
                label.textContent = checked > 0
                    ? 'Delete Selected (' + checked + ')'
                    : 'Delete Selected';
            }

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

            // Hide clear button since the date was set by default, not by the user
            const clearBtn = document.getElementById('auditClearBtn');
            if (clearBtn) clearBtn.style.display = 'none';
        });
    </script>

</body>

</html>