<?php
// pages/municipal/user_management_municipal.php
session_start();
require_once "../../config/config.php";
require_once "../../includes/functions.php";

// Redirect if not logged in
if (!isset($_SESSION['superadmin_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: login_municipal.php");
    exit();
}

// --------------------------------------
// FETCH: Barangays (for filter dropdown)
// --------------------------------------
$barangayList = $pdo->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC")->fetchAll();

// --------------------------------------
// FETCH: Combined Admin + BHW roster, town-wide
// Admin's "status" is derived from their barangay's status —
// admins has no status column of its own (confirmed via phpMyAdmin).
// --------------------------------------
$people = $pdo->query("
    SELECT
        'Admin' AS role_type,
        a.admin_id AS person_id,
        a.full_name,
        a.username,
        a.email,
        NULL AS department,
        LOWER(COALESCE(b.status, 'inactive')) AS status,
        a.created_at,
        a.profile_image,
        b.barangay_id,
        b.barangay_name
    FROM admins a
    LEFT JOIN barangays b ON b.barangay_id = a.barangay_id

    UNION ALL

    SELECT
        'BHW' AS role_type,
        hw.worker_id AS person_id,
        hw.full_name,
        hw.username,
        hw.email,
        hw.department,
        LOWER(COALESCE(hw.status, 'inactive')) AS status,
        hw.created_at,
        hw.profile_image,
        b.barangay_id,
        b.barangay_name
    FROM health_workers hw
    LEFT JOIN barangays b ON b.barangay_id = hw.barangay_id

    ORDER BY barangay_name ASC, role_type ASC, full_name ASC
")->fetchAll();

$totalAdmins = count(array_filter($people, fn($p) => $p['role_type'] === 'Admin'));
$totalWorkers = count(array_filter($people, fn($p) => $p['role_type'] === 'BHW'));

$pageTitle = "User Management";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>User Management | NutriTrack Municipal</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/municipal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_municipal.php"; ?>

    <!-- jsPDF Core -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <!-- jsPDF AutoTable Plugin -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>
        window.MUNICIPAL_ADMIN_NAME = <?= json_encode($_SESSION['full_name'] ?? 'Municipal Admin') ?>;
    </script>
    <!-- User Management PDF Export -->
    <script src="../../assets/js/user_management_municipal_pdf.js"></script>
    <!-- Hidden logos for jsPDF -->
    <img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
    <img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
        style="display:none;">

    <main class="municipal-dashboard">

        <main class="municipal-dashboard">

            <div class="municipal-page-header">
                <div>
                    <!-- <h1 class="municipal-page-title">User Management</h1> -->
                    <p class="municipal-welcome-message">Town-wide view of all Admin and BHW accounts -
                        <?= $totalAdmins ?>
                        admin<?= $totalAdmins !== 1 ? 's' : '' ?>, <?= $totalWorkers ?> health
                        worker<?= $totalWorkers !== 1 ? 's' : '' ?>
                    </p>
                </div>
            </div>

            <section class="section">

                <!-- ── Municipal Export PDF ── -->
                <div class="municipal-table-topbar">
                    <button onclick="generateUserManagementMunicipalPDF()" class="municipal-btn municipal-btn-export">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>

                <!-- ── Filter Bar ── -->
                <div class="municipal-filter-bar">
                    <div class="municipal-filter-select-wrap">
                        <select id="barangayFilter" class="municipal-form-input municipal-filter-select"
                            onchange="filterPeopleTable()">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangayList as $b): ?>
                                <option value="<?= htmlspecialchars(strtolower($b['barangay_name'])) ?>">
                                    <?= htmlspecialchars($b['barangay_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                    </div>

                    <div class="municipal-filter-select-wrap">
                        <select id="roleFilter" class="municipal-form-input municipal-filter-select"
                            onchange="filterPeopleTable()">
                            <option value="admin" selected>Admin</option>
                            <option value="bhw">BHW</option>
                        </select>
                        <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                    </div>

                    <div class="municipal-filter-select-wrap">
                        <select id="statusFilter" class="municipal-form-input municipal-filter-select"
                            onchange="filterPeopleTable()">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                    </div>

                    <div class="municipal-filter-search">
                        <i class="fas fa-magnifying-glass municipal-filter-search-icon"></i>
                        <input type="text" id="peopleSearch" class="municipal-form-input municipal-filter-search-input"
                            placeholder="Search by name, username, email, department..." oninput="filterPeopleTable()">
                    </div>

                    <span class="municipal-filter-count" id="peopleCount" style="display: none;">4 accounts</span>
                </div>

                <?php if (!empty($people)): ?>
                    <div class="municipal-table-wrapper">
                        <table class="municipal-table" id="peopleTable">
                            <thead class="municipal-table-head">
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>Barangay</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody class="municipal-table-body" id="peopleTableBody">
                                <?php foreach ($people as $index => $p): ?>
                                    <tr data-name="<?= strtolower(htmlspecialchars($p['full_name'])) ?>"
                                        data-username="<?= strtolower(htmlspecialchars($p['username'])) ?>"
                                        data-email="<?= strtolower(htmlspecialchars($p['email'] ?? '')) ?>"
                                        data-department="<?= strtolower(htmlspecialchars($p['department'] ?? '')) ?>"
                                        data-role="<?= strtolower($p['role_type']) ?>"
                                        data-barangay="<?= strtolower(htmlspecialchars($p['barangay_name'] ?? '')) ?>"
                                        data-status="<?= htmlspecialchars($p['status']) ?>">
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <div class="municipal-table-barangay-cell">
                                                <?php if (!empty($p['profile_image']) && file_exists("../../" . $p['profile_image'])): ?>
                                                    <img src="../../<?= htmlspecialchars($p['profile_image']) ?>"
                                                        alt="<?= htmlspecialchars($p['full_name']) ?>"
                                                        class="municipal-table-barangay-avatar-initials" style="object-fit:cover;">
                                                <?php else: ?>
                                                    <div class="municipal-table-barangay-avatar-initials">
                                                        <?php
                                                        $parts = explode(' ', trim($p['full_name']));
                                                        $initials = strtoupper(substr($parts[0], 0, 1));
                                                        if (count($parts) > 1)
                                                            $initials .= strtoupper(substr(end($parts), 0, 1));
                                                        echo $initials;
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="municipal-dash-cell-name">
                                                        <?= htmlspecialchars($p['full_name']) ?>
                                                    </div>
                                                    <div class="municipal-dash-cell-sub">
                                                        @<?= htmlspecialchars($p['username']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span
                                                class="municipal-badge <?= $p['role_type'] === 'Admin' ? 'municipal-badge-amber' : 'municipal-badge-blue' ?>">
                                                <?= htmlspecialchars($p['role_type']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($p['barangay_name'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($p['email'] ?? '—') ?></td>
                                        <td>
                                            <span
                                                class="municipal-badge <?= $p['status'] === 'active' ? 'municipal-badge-active' : 'municipal-badge-inactive' ?>">
                                                <?= htmlspecialchars(ucfirst($p['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($p['created_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div id="peopleEmptyState" style="display:none;" class="municipal-dash-empty">
                        <div class="municipal-dash-empty-icon">🔍</div>
                        <div class="municipal-dash-empty-text" id="peopleEmptyMsg">No accounts match your current filters.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="municipal-table-wrapper">
                        <div class="municipal-dash-td-empty">
                            <div class="municipal-dash-empty">
                                <div class="municipal-dash-empty-icon">👥</div>
                                <div class="municipal-dash-empty-text">No accounts found.</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </section>

        </main>

        <?php include "../../includes/footer.php"; ?>

        <script>
            function filterPeopleTable() {
                const barangay = (document.getElementById('barangayFilter')?.value || '').toLowerCase();
                const role = (document.getElementById('roleFilter')?.value || '').toLowerCase();
                const status = (document.getElementById('statusFilter')?.value || '').toLowerCase();
                const query = (document.getElementById('peopleSearch')?.value || '').toLowerCase().trim();

                const rows = document.querySelectorAll('#peopleTableBody tr');
                const emptyEl = document.getElementById('peopleEmptyState');
                const emptyMsg = document.getElementById('peopleEmptyMsg');
                const countEl = document.getElementById('peopleCount');

                let visible = 0;

                rows.forEach(row => {
                    const matchBarangay = !barangay || row.dataset.barangay === barangay;
                    const matchRole = !role || row.dataset.role === role;
                    const matchStatus = !status || row.dataset.status === status;
                    const matchQuery = !query || [
                        row.dataset.name,
                        row.dataset.username,
                        row.dataset.email,
                        row.dataset.department
                    ].some(v => (v || '').includes(query));

                    const show = matchBarangay && matchRole && matchStatus && matchQuery;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });

                if (countEl) {
                    countEl.textContent = visible + ' account' + (visible !== 1 ? 's' : '');
                    countEl.style.display = (barangay || role || status || query) ? '' : 'none';
                }

                if (emptyEl) {
                    emptyEl.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
                    if (emptyMsg && visible === 0) {
                        emptyMsg.textContent = 'No accounts match your current filters. Try adjusting them.';
                    }
                }
            }

            document.addEventListener('DOMContentLoaded', filterPeopleTable);
        </script>

</body>

</html>