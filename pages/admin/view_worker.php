<?php
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
$barangay_id = $_SESSION['barangay_id'];

// --------------------------------------
// LOAD WORKER
// --------------------------------------
if (!isset($_GET['worker_id'])) {
    header("Location: user_management.php");
    exit();
}

$worker_id = (int) $_GET['worker_id'];

$stmt = $pdo->prepare("SELECT * FROM health_workers WHERE worker_id = :id AND barangay_id = :brgy LIMIT 1");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id]);
$worker = $stmt->fetch();

if (!$worker) {
    header("Location: user_management.php");
    exit();
}

// --------------------------------------
// STAT: Total Records (all time)
// --------------------------------------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE worker_id = :id AND barangay_id = :brgy");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id]);
$totalRecords = (int) $stmt->fetchColumn();

// --------------------------------------
// STAT: Distinct Residents Assessed
// --------------------------------------
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT resident_id) FROM health_records WHERE worker_id = :id AND barangay_id = :brgy");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id]);
$totalResidents = (int) $stmt->fetchColumn();

// --------------------------------------
// STAT: Records This Month
// --------------------------------------
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM health_records
    WHERE worker_id = :id AND barangay_id = :brgy
      AND MONTH(record_date) = MONTH(CURRENT_DATE())
      AND YEAR(record_date)  = YEAR(CURRENT_DATE())
");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id]);
$recordsThisMonth = (int) $stmt->fetchColumn();

// --------------------------------------
// STAT: BMI Breakdown (latest record per resident)
// --------------------------------------
$stmt = $pdo->prepare("
    SELECT hr.bmi
    FROM health_records hr
    INNER JOIN (
        SELECT resident_id, MAX(record_date) AS latest_date
        FROM health_records
        WHERE worker_id = :id AND barangay_id = :brgy
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id
           AND hr.record_date  = latest.latest_date
    WHERE hr.worker_id = :id2 AND hr.barangay_id = :brgy2
");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id, ':id2' => $worker_id, ':brgy2' => $barangay_id]);
$bmiRows = $stmt->fetchAll();

$bmiCounts = ['underweight' => 0, 'normal' => 0, 'overweight' => 0, 'obese' => 0];
foreach ($bmiRows as $row) {
    $bmi = (float) $row['bmi'];
    if ($bmi < 18.5)
        $bmiCounts['underweight']++;
    elseif ($bmi < 25.0)
        $bmiCounts['normal']++;
    elseif ($bmi < 30.0)
        $bmiCounts['overweight']++;
    else
        $bmiCounts['obese']++;
}

$atRisk = $bmiCounts['underweight'] + $bmiCounts['overweight'] + $bmiCounts['obese'];

// --------------------------------------
// STAT: Last Activity
// --------------------------------------
$stmt = $pdo->prepare("SELECT MAX(record_date) FROM health_records WHERE worker_id = :id AND barangay_id = :brgy");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id]);
$lastActivity = $stmt->fetchColumn();

// --------------------------------------
// FETCH ALL HEALTH RECORDS BY THIS WORKER
// --------------------------------------
$stmt = $pdo->prepare("
    SELECT hr.record_id, hr.record_date, hr.weight, hr.height, hr.bmi, hr.status,
           r.first_name, r.last_name, r.gender
    FROM health_records hr
    JOIN residents r ON r.resident_id = hr.resident_id
    WHERE hr.worker_id = :id AND hr.barangay_id = :brgy
    ORDER BY hr.record_date DESC, hr.record_id DESC
");
$stmt->execute([':id' => $worker_id, ':brgy' => $barangay_id]);
$records = $stmt->fetchAll();

// --------------------------------------
// PROFILE IMAGE
// --------------------------------------
$profileImage = !empty($worker['profile_image'])
    ? '../../' . $worker['profile_image']
    : null;

// --------------------------------------
// BMI STATUS BADGE HELPER
// --------------------------------------
function bmiStatusBadge(?float $bmi): string
{
    if ($bmi === null)
        return '<span class="admin-vw-bmi-badge admin-vw-bmi-none">—</span>';
    if ($bmi < 18.5)
        return '<span class="admin-vw-bmi-badge admin-vw-bmi-underweight">Underweight</span>';
    if ($bmi < 25.0)
        return '<span class="admin-vw-bmi-badge admin-vw-bmi-normal">Normal</span>';
    if ($bmi < 30.0)
        return '<span class="admin-vw-bmi-badge admin-vw-bmi-overweight">Overweight</span>';
    return '<span class="admin-vw-bmi-badge admin-vw-bmi-obese">Obese</span>';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>View Worker | NutriTrack Admin</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>

<body>

    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <?php include __DIR__ . '/../../includes/sidebar_admin.php'; ?>

    <main class="dashboard">
        <h1 class="page-title">Worker Activity</h1>

        <section class="section">

            <!-- ── Back button ── -->
            <div class="admin-vw-back-bar">
                <a href="user_management.php" class="admin-vw-back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Manage Users
                </a>
            </div>

            <!-- ── Worker Profile Header ── -->
            <div class="admin-vw-profile-header">

                <!-- Avatar -->
                <div class="admin-vw-profile-avatar">
                    <?php if ($profileImage): ?>
                        <img src="<?= htmlspecialchars($profileImage) ?>"
                            alt="<?= htmlspecialchars($worker['full_name']) ?>">
                    <?php else: ?>
                        <div class="admin-vw-profile-avatar-initials">
                            <?= strtoupper(mb_substr($worker['full_name'], 0, 2)) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Identity -->
                <div class="admin-vw-profile-identity">
                    <h2 class="admin-vw-profile-name">
                        <?= htmlspecialchars($worker['full_name']) ?>
                    </h2>
                    <div class="admin-vw-profile-meta-row">
                        <?php if (!empty($worker['department'])): ?>
                            <span class="admin-vw-profile-meta-item">
                                <i class="fas fa-building"></i>
                                <?= htmlspecialchars($worker['department']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($worker['email'])): ?>
                            <span class="admin-vw-profile-meta-item">
                                <i class="fas fa-envelope"></i>
                                <?= htmlspecialchars($worker['email']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($worker['phone'])): ?>
                            <span class="admin-vw-profile-meta-item">
                                <i class="fas fa-phone"></i>
                                <?= htmlspecialchars($worker['phone']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($worker['date_hired'])): ?>
                            <span class="admin-vw-profile-meta-item">
                                <i class="fas fa-calendar-check"></i>
                                Hired <?= date('M d, Y', strtotime($worker['date_hired'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Status badge + Edit button -->
                <div class="admin-vw-profile-status-wrap">
                    <span
                        class="admin-vw-profile-status-badge admin-vw-profile-status-<?= strtolower($worker['status']) ?>">
                        <span class="admin-vw-profile-status-dot"></span>
                        <?= htmlspecialchars($worker['status']) ?>
                    </span>
                    <a href="edit_user.php?worker_id=<?= $worker_id ?>" class="admin-vw-edit-btn">
                        <i class="fas fa-edit"></i> Edit Worker
                    </a>
                </div>

            </div>

            <!-- ── Stat Cards ── -->
            <div class="admin-vw-stats-grid">

                <div class="admin-vw-stat-card">
                    <div class="admin-vw-stat-icon admin-vw-stat-icon-records">
                        <i class="fas fa-file-medical"></i>
                    </div>
                    <div class="admin-vw-stat-content">
                        <p class="admin-vw-stat-label">Total Records</p>
                        <p class="admin-vw-stat-value"><?= $totalRecords ?></p>
                        <p class="admin-vw-stat-sub">All time</p>
                    </div>
                </div>

                <div class="admin-vw-stat-card">
                    <div class="admin-vw-stat-icon admin-vw-stat-icon-residents">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="admin-vw-stat-content">
                        <p class="admin-vw-stat-label">Residents Assessed</p>
                        <p class="admin-vw-stat-value"><?= $totalResidents ?></p>
                        <p class="admin-vw-stat-sub">Unique residents</p>
                    </div>
                </div>

                <div class="admin-vw-stat-card">
                    <div class="admin-vw-stat-icon admin-vw-stat-icon-month">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <div class="admin-vw-stat-content">
                        <p class="admin-vw-stat-label">Records This Month</p>
                        <p class="admin-vw-stat-value"><?= $recordsThisMonth ?></p>
                        <p class="admin-vw-stat-sub"><?= date('F Y') ?></p>
                    </div>
                </div>

                <div class="admin-vw-stat-card">
                    <div class="admin-vw-stat-icon admin-vw-stat-icon-atrisk">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                    <div class="admin-vw-stat-content">
                        <p class="admin-vw-stat-label">At-Risk Residents</p>
                        <p class="admin-vw-stat-value"><?= $atRisk ?></p>
                        <p class="admin-vw-stat-sub">Require attention</p>
                    </div>
                </div>

            </div>

            <!-- ── BMI Breakdown + Last Activity ── -->
            <div class="admin-vw-bmi-row">

                <div class="admin-vw-bmi-breakdown">
                    <h3 class="admin-vw-section-title">
                        <i class="fas fa-chart-pie"></i> BMI Breakdown
                        <span class="admin-vw-section-sub">(latest record per resident)</span>
                    </h3>
                    <div class="admin-vw-bmi-chips">
                        <div class="admin-vw-bmi-chip admin-vw-bmi-chip-underweight">
                            <span class="admin-vw-bmi-chip-count"><?= $bmiCounts['underweight'] ?></span>
                            <span class="admin-vw-bmi-chip-label">Underweight</span>
                        </div>
                        <div class="admin-vw-bmi-chip admin-vw-bmi-chip-normal">
                            <span class="admin-vw-bmi-chip-count"><?= $bmiCounts['normal'] ?></span>
                            <span class="admin-vw-bmi-chip-label">Normal</span>
                        </div>
                        <div class="admin-vw-bmi-chip admin-vw-bmi-chip-overweight">
                            <span class="admin-vw-bmi-chip-count"><?= $bmiCounts['overweight'] ?></span>
                            <span class="admin-vw-bmi-chip-label">Overweight</span>
                        </div>
                        <div class="admin-vw-bmi-chip admin-vw-bmi-chip-obese">
                            <span class="admin-vw-bmi-chip-count"><?= $bmiCounts['obese'] ?></span>
                            <span class="admin-vw-bmi-chip-label">Obese</span>
                        </div>
                    </div>
                </div>

                <div class="admin-vw-last-activity">
                    <h3 class="admin-vw-section-title">
                        <i class="fas fa-clock"></i> Last Activity
                    </h3>
                    <p class="admin-vw-last-activity-value">
                        <?= $lastActivity ? date('F d, Y', strtotime($lastActivity)) : '—' ?>
                    </p>
                    <p class="admin-vw-last-activity-sub">
                        <?= $lastActivity ? 'Last health record submitted' : 'No records submitted yet' ?>
                    </p>
                </div>

            </div>

            <!-- ── Health Records Table ── -->
            <div class="admin-vw-records-section">
                <div class="admin-vw-records-header">
                    <h3 class="admin-vw-section-title">
                        <i class="fas fa-list-ul"></i> Submitted Health Records
                        <span class="admin-vw-section-sub">(read only)</span>
                    </h3>
                    <div class="admin-filter-bar">
                        <select id="vwBmiFilter" onchange="filterVwTable()">
                            <option value="">All BMI Status</option>
                            <option value="underweight">Underweight</option>
                            <option value="normal">Normal</option>
                            <option value="overweight">Overweight</option>
                            <option value="obese">Obese</option>
                        </select>
                        <div class="admin-filter-search">
                            <input type="text" id="vwSearch" placeholder="Search resident name..."
                                oninput="filterVwTable()" autocomplete="off">
                        </div>
                        <span class="admin-filter-count" id="vwCount" style="display:none;"></span>
                    </div>
                </div>

                <?php if (empty($records)): ?>
                    <div class="admin-empty-state">
                        <i class="fas fa-file-medical"></i>
                        <p>This worker has not submitted any health records yet.</p>
                    </div>
                <?php else: ?>

                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead class="admin-table-head">
                                <tr>
                                    <th>#</th>
                                    <th>Resident</th>
                                    <th>Gender</th>
                                    <th>Date</th>
                                    <th>Weight (kg)</th>
                                    <th>Height (cm)</th>
                                    <th>BMI</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="admin-table-body" id="vwTableBody">
                                <?php foreach ($records as $i => $rec):
                                    $bmiVal = isset($rec['bmi']) ? (float) $rec['bmi'] : null;
                                    if ($bmiVal === null)
                                        $bmiSlug = 'none';
                                    elseif ($bmiVal < 18.5)
                                        $bmiSlug = 'underweight';
                                    elseif ($bmiVal < 25.0)
                                        $bmiSlug = 'normal';
                                    elseif ($bmiVal < 30.0)
                                        $bmiSlug = 'overweight';
                                    else
                                        $bmiSlug = 'obese';
                                    ?>
                                    <tr data-name="<?= strtolower(htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name'])) ?>"
                                        data-bmi="<?= $bmiSlug ?>">
                                        <td class="admin-vw-td-num"><?= $i + 1 ?></td>
                                        <td><?= htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']) ?></td>
                                        <td><?= htmlspecialchars($rec['gender']) ?></td>
                                        <td><?= date('M d, Y', strtotime($rec['record_date'])) ?></td>
                                        <td><?= (float) $rec['weight'] ?> kg</td>
                                        <td><?= (float) $rec['height'] ?> cm</td>
                                        <td><?= $bmiVal !== null ? number_format($bmiVal, 1) : '—' ?></td>
                                        <td><?= bmiStatusBadge($bmiVal) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Empty state shown by JS ── -->
                    <div id="vwEmptyState" style="display:none;" class="admin-empty-state">
                        <i class="fas fa-file-medical"></i>
                        <p id="vwEmptyMsg">No records match your filters.</p>
                    </div>

                <?php endif; ?>
            </div>

        </section>
    </main>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>

    <script>
        function filterVwTable() {
            const bmi = (document.getElementById('vwBmiFilter')?.value || '').toLowerCase();
            const query = (document.getElementById('vwSearch')?.value || '').toLowerCase().trim();

            const rows = document.querySelectorAll('#vwTableBody tr');
            const emptyEl = document.getElementById('vwEmptyState');
            const emptyMsg = document.getElementById('vwEmptyMsg');
            const countEl = document.getElementById('vwCount');

            let visible = 0;
            let counter = 1;

            rows.forEach(row => {
                const matchBmi = !bmi || row.dataset.bmi === bmi;
                const matchQuery = !query || row.dataset.name.includes(query);

                const show = matchBmi && matchQuery;
                row.style.display = show ? '' : 'none';

                if (show) {
                    const numCell = row.querySelector('.admin-vw-td-num');
                    if (numCell) numCell.textContent = counter;
                    counter++;
                    visible++;
                }
            });

            if (countEl) {
                countEl.textContent = visible + ' ' + (visible === 1 ? 'record' : 'records');
                countEl.style.display = (bmi || query) ? '' : 'none';
            }

            if (emptyEl) {
                if (visible === 0 && rows.length > 0) {
                    const parts = [];
                    if (query) parts.push('"' + query + '"');
                    if (bmi) parts.push(bmi + ' BMI');
                    if (emptyMsg) emptyMsg.textContent = parts.length
                        ? 'No records match: ' + parts.join(' · ') + '. Try adjusting your filters.'
                        : 'No records match your filters.';
                    emptyEl.style.display = '';
                } else {
                    emptyEl.style.display = 'none';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', filterVwTable);
    </script>

</body>

</html>