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
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}

// Get admin info for PDF
$admin_id    = (int) $_SESSION['admin_id'];
$barangay_id = $_SESSION['barangay_id'];

$adminStmt = $pdo->prepare("SELECT full_name FROM admins WHERE admin_id = :id LIMIT 1");
$adminStmt->execute([':id' => $admin_id]);
$adminRow = $adminStmt->fetch();
$admin_full_name = $adminRow['full_name'] ?? 'Admin';

$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $barangay_id]);
$barangay_name = $brgyStmt->fetchColumn();

// --------------------------------------
// FETCH HEALTH WORKERS
// --------------------------------------
$stmt = $pdo->prepare("
    SELECT worker_id, full_name, username, email, department, status, created_at, profile_image
    FROM health_workers
    WHERE barangay_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$barangay_id]);
$healthWorkers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management | NutriTrack Admin</title>
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
    window.CURRENT_WORKER_NAME   = <?= json_encode($admin_full_name) ?>;
    window.CURRENT_BARANGAY_NAME = <?= json_encode($barangay_name ?? 'Barangay Health Center') ?>;
</script>

<!-- User Management PDF Export -->
<script src="../../assets/js/user_management_pdf.js?v=<?= time() ?>"></script>

<!-- Hidden logos for jsPDF -->
<img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
<img id="logoDOH"    src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png" style="display:none;">

<main class="dashboard">
    <h1 class="page-title">User Management</h1>

    <section class="section">

        <!-- ── Top bar: Export PDF + Add button ── -->
        <div class="admin-table-topbar">
            <button onclick="generateUsersPDF()" class="admin-btn admin-btn-export">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
            <a href="add_user.php" class="admin-btn admin-btn-submit">
                <i class="fas fa-user-plus"></i> Add Health Worker
            </a>
        </div>

        <!-- ── Filter Bar ── -->
        <div class="admin-filter-bar">
            <select id="workerStatusFilter" onchange="filterWorkersTable()">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <div class="admin-filter-search">
                <input type="text"
                       id="workerSearch"
                        placeholder="Search by name, username, email…"
                       oninput="filterWorkersTable()">
            </div>
            <span class="admin-filter-count" id="workerCount" style="display:none;"></span>
        </div>

        <?php if (!empty($healthWorkers)): ?>
            <div class="admin-table-wrapper">
                <table class="admin-table" id="workersTable">
                    <thead class="admin-table-head">
                        <tr>
                            <th>#</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody class="admin-table-body" id="workersTableBody">
                        <?php foreach ($healthWorkers as $index => $worker): ?>
                            <tr data-name="<?= strtolower(htmlspecialchars($worker['full_name'])) ?>"
                                data-username="<?= strtolower(htmlspecialchars($worker['username'])) ?>"
                                data-email="<?= strtolower(htmlspecialchars($worker['email'] ?? '')) ?>"
                                data-status="<?= strtolower(htmlspecialchars($worker['status'])) ?>">
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <div class="admin-table-worker-cell">
                                        <?php if (!empty($worker['profile_image']) && file_exists("../../" . $worker['profile_image'])): ?>
                                            <img src="../../<?= htmlspecialchars($worker['profile_image']) ?>"
                                                 alt="<?= htmlspecialchars($worker['full_name']) ?>"
                                                 class="admin-table-worker-avatar">
                                        <?php else: ?>
                                            <div class="admin-table-worker-avatar-initials">
                                                <?php
                                                $parts = explode(' ', trim($worker['full_name']));
                                                $initials = strtoupper(substr($parts[0], 0, 1));
                                                if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
                                                echo $initials;
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($worker['full_name']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($worker['username']) ?></td>
                                <td><?= htmlspecialchars($worker['email'] ?? '—') ?></td>
                                <td>
                                    <span class="admin-badge <?= $worker['status'] === 'Active' ? 'admin-badge-active' : 'admin-badge-inactive' ?>">
                                        <?= htmlspecialchars($worker['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($worker['created_at']) ?></td>
                                <td class="admin-table-actions">
                                    <a href="view_worker.php?worker_id=<?= $worker['worker_id'] ?>"
                                       class="admin-btn admin-btn-view"
                                       title="View Activity">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit_user.php?worker_id=<?= $worker['worker_id'] ?>"
                                       class="admin-btn admin-btn-edit"
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                 <?php if ($worker['status'] === 'Active'): ?>
                                    <a href="toggle_worker_status.php?worker_id=<?= $worker['worker_id'] ?>&action=deactivate"
                                       class="admin-btn admin-btn-activate"
                                       title="Deactivate Worker"
                                       onclick="return confirm('Deactivate <?= htmlspecialchars(addslashes($worker['full_name'])) ?>?');">
                                        <i class="fas fa-toggle-on"></i>
                                    </a>
                                    <?php else: ?>
                                    <a href="toggle_worker_status.php?worker_id=<?= $worker['worker_id'] ?>&action=activate"
                                       class="admin-btn admin-btn-deactivate"
                                       title="Activate Worker"
                                       onclick="return confirm('Activate <?= htmlspecialchars(addslashes($worker['full_name'])) ?>?');">
                                        <i class="fas fa-toggle-off"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ── Empty state: shown by JS when filters return no results ── -->
            <div id="workersEmptyState" style="display:none;" class="admin-empty-state">
                <i class="fas fa-users"></i>
                <p id="workersEmptyMsg">No health workers match your current filters.</p>
            </div>

        <?php else: ?>
            <!-- ── Empty state: no records in DB at all ── -->
            <div class="admin-empty-state">
                <i class="fas fa-users"></i>
                <p>No health workers found.</p>
                <a href="add_user.php" class="admin-btn admin-btn-submit">Add your first health worker</a>
            </div>
        <?php endif; ?>

    </section>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
// ============================================================
//  USER MANAGEMENT — Filter + Search
// ============================================================

function filterWorkersTable() {
    const status  = (document.getElementById('workerStatusFilter')?.value || '').toLowerCase();
    const query   = (document.getElementById('workerSearch')?.value       || '').toLowerCase().trim();

    const rows     = document.querySelectorAll('#workersTableBody tr');
    const emptyEl  = document.getElementById('workersEmptyState');
    const emptyMsg = document.getElementById('workersEmptyMsg');
    const countEl  = document.getElementById('workerCount');

    let visible = 0;

    rows.forEach(row => {
        const matchStatus = !status || row.dataset.status === status;
             const matchQuery  = !query  || [
            row.dataset.name,
            row.dataset.username,
            row.dataset.email
        ].some(v => (v || '').includes(query));

        const show = matchStatus && matchQuery;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    if (countEl) {
        countEl.textContent   = visible + ' worker' + (visible !== 1 ? 's' : '');
        countEl.style.display = (status || query) ? '' : 'none';
    }

    if (emptyEl) {
        emptyEl.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
        if (emptyMsg && visible === 0) {
            const parts = [];
            if (query)  parts.push('"' + query + '"');
            if (status) parts.push(status + ' status');
            emptyMsg.textContent = parts.length
                ? 'No workers match: ' + parts.join(' · ') + '. Try adjusting your filters.'
                : 'No health workers match your current filters.';
        }
    }
}

document.addEventListener('DOMContentLoaded', filterWorkersTable);
</script>

</body>
</html>