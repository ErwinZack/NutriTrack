<?php
session_start();
require_once "../../config/config.php";

// Redirect if not logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login_admin.php");
    exit();
}

// --------------------------------------
// FETCH ADMIN INFO
// --------------------------------------
$stmt = $pdo->prepare("SELECT full_name, profile_image FROM admins WHERE admin_id = :id LIMIT 1");
$stmt->execute([':id' => $_SESSION['admin_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!isset($_SESSION['admin_full_name']) && $admin) {
    $_SESSION['admin_full_name'] = $admin['full_name'];
}

$adminName = $admin['full_name'] ?? 'Admin';

$barangay_id = $_SESSION['barangay_id'];
$barangayStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$barangayStmt->execute([':id' => $barangay_id]);
$barangay = $barangayStmt->fetch(PDO::FETCH_ASSOC);
$barangayName = $barangay['barangay_name'] ?? 'Unknown Barangay';

// --------------------------------------
// STAT CARDS
// --------------------------------------

/* ── Card 1: Total Health Workers ── */
$totalHealthWorkers = $pdo->query("SELECT COUNT(*) FROM health_workers WHERE barangay_id = $barangay_id")->fetchColumn();
$activeHealthWorkers = $pdo->query("SELECT COUNT(*) FROM health_workers WHERE status = 'Active' AND barangay_id = $barangay_id")->fetchColumn();
$inactiveWorkers = $totalHealthWorkers - $activeHealthWorkers;

/* ── Card 2: Total Residents ── */
$totalResidents = $pdo->query("SELECT COUNT(*) FROM residents WHERE barangay_id = $barangay_id")->fetchColumn();
$newResidentsThisMonth = $pdo->query("
    SELECT COUNT(*) FROM residents
    WHERE barangay_id = $barangay_id
      AND MONTH(created_at) = MONTH(CURRENT_DATE())
      AND YEAR(created_at)  = YEAR(CURRENT_DATE())
")->fetchColumn();

/* ── Card 3: Total Health Records ── */
$totalRecords = $pdo->query("SELECT COUNT(*) FROM health_records WHERE barangay_id = $barangay_id")->fetchColumn();

/* ── Card 4: New Records This Month + Trend ── */
$newRecordsThisMonth = $pdo->query("
    SELECT COUNT(*) FROM health_records
    WHERE barangay_id = $barangay_id
      AND MONTH(record_date) = MONTH(CURRENT_DATE())
      AND YEAR(record_date)  = YEAR(CURRENT_DATE())
")->fetchColumn();
$recordsLastMonth = $pdo->query("
    SELECT COUNT(*) FROM health_records
    WHERE barangay_id = $barangay_id
      AND MONTH(record_date) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH)
      AND YEAR(record_date)  = YEAR(CURRENT_DATE()  - INTERVAL 1 MONTH)
")->fetchColumn();
$recordsDiff = $newRecordsThisMonth - $recordsLastMonth;
$recordsSign = $recordsDiff >= 0 ? '+' : '';

// --------------------------------------
// CHART 1: Monthly New Residents Trend
// --------------------------------------
$residentTrendLabels = [];
$residentTrendData = [];
for ($i = 5; $i >= 0; $i--) {
    $year = date('Y', strtotime("-$i months"));
    $month = date('m', strtotime("-$i months"));
    $residentTrendLabels[] = date('M Y', strtotime("-$i months"));
    $rq = $pdo->prepare("
    SELECT COUNT(*) FROM residents
    WHERE barangay_id = :brgy AND YEAR(created_at) = :y AND MONTH(created_at) = :m
");
   $rq->execute([':brgy' => $barangay_id, ':y' => $year, ':m' => $month]);
    $residentTrendData[] = (int) $rq->fetchColumn();
}

// --------------------------------------
// CHART 2: Health Worker Status
// --------------------------------------
$hwActiveCount = $activeHealthWorkers;
$hwInactiveCount = $inactiveWorkers;
$hwActivePct = $totalHealthWorkers > 0 ? round(($hwActiveCount / $totalHealthWorkers) * 100, 1) : 0;
$hwInactivePct = $totalHealthWorkers > 0 ? round(($hwInactiveCount / $totalHealthWorkers) * 100, 1) : 0;

// --------------------------------------
// CHART 3: Records per Health Worker
// --------------------------------------
$workerRecordsStmt = $pdo->query("
    SELECT
        hw.worker_id,
        hw.full_name,
        hw.profile_image,
        hw.department,
        hw.status,
        COUNT(hr.record_id) AS total_all,
        SUM(CASE WHEN MONTH(hr.record_date) = MONTH(CURRENT_DATE()) AND YEAR(hr.record_date) = YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) AS total_this_month,
        SUM(CASE WHEN MONTH(hr.record_date) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) AND YEAR(hr.record_date) = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS total_last_month
    FROM health_workers hw
    LEFT JOIN health_records hr ON hw.worker_id = hr.worker_id
    GROUP BY hw.worker_id, hw.full_name, hw.profile_image, hw.department, hw.status
    ORDER BY total_all DESC
    LIMIT 10
");
$workerRecordsRows = $workerRecordsStmt->fetchAll();

// Helper: get initials from full name
function workerInitials(string $name): string
{
    $parts = explode(' ', trim($name));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1)
        $initials .= strtoupper(substr(end($parts), 0, 1));
    return $initials;
}

// --------------------------------------
// CHART 4: Nutrition Status by Month
// --------------------------------------
$stackedLabels = [];
$sUW = [];
$sNM = [];
$sOW = [];
$sOB = [];
for ($i = 5; $i >= 0; $i--) {
    $year = date('Y', strtotime("-$i months"));
    $month = date('m', strtotime("-$i months"));
    $stackedLabels[] = date('M Y', strtotime("-$i months"));
    $sq = $pdo->prepare("
        SELECT
            SUM(CASE WHEN bmi < 18.5                THEN 1 ELSE 0 END) AS uw,
            SUM(CASE WHEN bmi >= 18.5 AND bmi < 25  THEN 1 ELSE 0 END) AS nm,
            SUM(CASE WHEN bmi >= 25   AND bmi < 30  THEN 1 ELSE 0 END) AS ow,
            SUM(CASE WHEN bmi >= 30                 THEN 1 ELSE 0 END) AS ob
        FROM health_records
        WHERE YEAR(record_date) = :y AND MONTH(record_date) = :m
    ");
    $sq->execute([':y' => $year, ':m' => $month]);
    $sr = $sq->fetch();
    $sUW[] = (int) ($sr['uw'] ?? 0);
    $sNM[] = (int) ($sr['nm'] ?? 0);
    $sOW[] = (int) ($sr['ow'] ?? 0);
    $sOB[] = (int) ($sr['ob'] ?? 0);
}

// --------------------------------------
// CHART 5: Age Group Distribution
// --------------------------------------
$ageGroupStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 0  AND 5  THEN 1 ELSE 0 END) AS g0_5,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 6  AND 12 THEN 1 ELSE 0 END) AS g6_12,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 13 AND 17 THEN 1 ELSE 0 END) AS g13_17,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 18 AND 59 THEN 1 ELSE 0 END) AS g18_59,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) >= 60             THEN 1 ELSE 0 END) AS g60plus
    FROM residents
    WHERE barangay_id = $barangay_id
");
$ageRow = $ageGroupStmt->fetch();
$ageLabels = ['0–5', '6–12', '13–17', '18–59', '60+'];
$ageData = [
    (int) ($ageRow['g0_5'] ?? 0),
    (int) ($ageRow['g6_12'] ?? 0),
    (int) ($ageRow['g13_17'] ?? 0),
    (int) ($ageRow['g18_59'] ?? 0),
    (int) ($ageRow['g60plus'] ?? 0),
];
$ageTotal = array_sum($ageData);

// --------------------------------------
// CHART 6: Gender Distribution
// --------------------------------------
$genderStmt = $pdo->query("SELECT gender, COUNT(*) AS cnt FROM residents WHERE barangay_id = $barangay_id GROUP BY gender");
$genderRows = $genderStmt->fetchAll();
$genderMale = $genderFemale = 0;
foreach ($genderRows as $gr) {
    if ($gr['gender'] === 'Male')
        $genderMale = (int) $gr['cnt'];
    if ($gr['gender'] === 'Female')
        $genderFemale = (int) $gr['cnt'];
}
$genderTotal = $genderMale + $genderFemale;
$genderMalePct = $genderTotal > 0 ? round(($genderMale / $genderTotal) * 100, 1) : 0;
$genderFemPct = $genderTotal > 0 ? round(($genderFemale / $genderTotal) * 100, 1) : 0;

// --------------------------------------
// CHART 7: Total Records Over 12 Months
// --------------------------------------
$recordsTrendLabels = [];
$recordsTrendData = [];
for ($i = 11; $i >= 0; $i--) {
    $year = date('Y', strtotime("-$i months"));
    $month = date('m', strtotime("-$i months"));
    $recordsTrendLabels[] = date('M Y', strtotime("-$i months"));
    $rq = $pdo->prepare("
    SELECT COUNT(*) FROM health_records
    WHERE barangay_id = :brgy AND YEAR(record_date) = :y AND MONTH(record_date) = :m
");
    $rq->execute([':brgy' => $barangay_id, ':y' => $year, ':m' => $month]);
    $recordsTrendData[] = (int) $rq->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard | NutriTrack</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/dashboard-header.css">
    <link rel="stylesheet" href="../../assets/css/dash-table.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_admin.php"; ?>

    <main class="dashboard">

        <!-- Page Header -->
        <div class="admin-page-header">
            <div>
                <h1 class="admin-page-title">Dashboard</h1>
                <p class="admin-welcome-message">
                    Welcome back, <?= htmlspecialchars($_SESSION['admin_full_name'] ?? 'Admin'); ?> 👋 ·
                    <strong><?= htmlspecialchars($barangayName); ?></strong>
                </p>
            </div>
        </div>

        <!-- Welcome Banner -->
        <div class="admin-welcome-banner">
            <div class="admin-welcome-banner-text">
                <h2>Barangay: <?= htmlspecialchars($barangayName); ?> 🏘️</h2>
                <p>Welcome back, <?= htmlspecialchars($_SESSION['admin_full_name'] ?? 'Admin'); ?>. Here's your barangay
                    overview today.</p>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="admin-stats-grid">

            <a href="user_management.php" class="admin-stat-card-link">
                <div class="admin-stat-card">
                    <div class="admin-stat-card-header">
                        <div class="admin-stat-content">
                            <h3 class="admin-stat-title">Total Health Workers</h3>
                            <p class="admin-stat-value"><?= number_format($totalHealthWorkers); ?></p>
                        </div>
                        <div class="admin-stat-icon admin-stat-icon-workers">
                            <i class="fas fa-user-nurse"></i>
                        </div>
                    </div>
                    <div class="admin-stat-divider"></div>
                    <div class="admin-stat-footer">
                        <i class="fas fa-circle-check" style="color:#12b76a;font-size:12px;"></i>
                        <?= number_format($activeHealthWorkers) ?> active <span class="admin-stat-footer-muted">·
                            <?= number_format($inactiveWorkers) ?> inactive</span>
                    </div>
                </div>
            </a>

            <a href="residents_admin.php" class="admin-stat-card-link">
                <div class="admin-stat-card">
                    <div class="admin-stat-card-header">
                        <div class="admin-stat-content">
                            <h3 class="admin-stat-title">Total Residents</h3>
                            <p class="admin-stat-value"><?= number_format($totalResidents); ?></p>
                        </div>
                        <div class="admin-stat-icon admin-stat-icon-residents">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="admin-stat-divider"></div>
                    <div class="admin-stat-footer">
                        <i class="fas fa-circle-check" style="color:#12b76a;font-size:12px;"></i>
                        +<?= number_format($newResidentsThisMonth) ?> this month <span class="admin-stat-footer-muted">·
                            registered</span>
                    </div>
                </div>
            </a>

            <a href="health_records_admin.php" class="admin-stat-card-link">
                <div class="admin-stat-card">
                    <div class="admin-stat-card-header">
                        <div class="admin-stat-content">
                            <h3 class="admin-stat-title">Total Health Records</h3>
                            <p class="admin-stat-value"><?= number_format($totalRecords); ?></p>
                        </div>
                        <div class="admin-stat-icon admin-stat-icon-records">
                            <i class="fas fa-file-medical"></i>
                        </div>
                    </div>
                    <div class="admin-stat-divider"></div>
                    <div class="admin-stat-footer">
                        <i class="fas fa-database" style="color:#0cb4b4;font-size:12px;"></i>
                        All time <span class="admin-stat-footer-muted">· records on file</span>
                    </div>
                </div>
            </a>

            <a href="health_records_admin.php" class="admin-stat-card-link">
                <div class="admin-stat-card">
                    <div class="admin-stat-card-header">
                        <div class="admin-stat-content">
                            <h3 class="admin-stat-title">New Records This Month</h3>
                            <p class="admin-stat-value"><?= number_format($newRecordsThisMonth); ?></p>
                        </div>
                        <div class="admin-stat-icon admin-stat-icon-new">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                    </div>
                    <div class="admin-stat-divider"></div>
                    <div class="admin-stat-footer">
                        <?php if ($recordsDiff >= 0): ?>
                            <i class="fas fa-arrow-trend-up" style="color:#12b76a;font-size:12px;"></i>
                        <?php else: ?>
                            <i class="fas fa-arrow-trend-down" style="color:#f04438;font-size:12px;"></i>
                        <?php endif; ?>
                        <?= $recordsSign . $recordsDiff ?> from last month <span class="admin-stat-footer-muted">·
                            records</span>
                    </div>
                </div>
            </a>

        </div>

        <!-- Row 1: Monthly New Residents Trend + Health Worker Status -->
        <div class="dashboard-row-overview">

            <!-- Chart 1: Monthly New Residents Trend -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Monthly New Residents Trend</h3>
                        <p class="section-subtitle">Number of newly registered residents per month · last 6 months</p>
                    </div>
                    <span class="analytics-badge">Last 6 Months</span>
                </div>
                <div class="chart-box chart-box-lg">
                    <canvas id="residentTrendChart"></canvas>
                </div>
            </div>

            <!-- Chart 2: Health Worker Status -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Health Worker Status</h3>
                        <p class="section-subtitle">Active vs inactive health workers in the system</p>
                    </div>
                    <span class="analytics-badge">Workforce</span>
                </div>
                <div class="status-pill-grid">
                    <div class="status-pill sp-nm">
                        <div>
                            <div class="sp-label">Active</div>
                            <div class="sp-sub"><?= $hwActivePct ?>%</div>
                        </div>
                        <div class="sp-count"><?= $hwActiveCount ?></div>
                    </div>
                    <div class="status-pill sp-ob">
                        <div>
                            <div class="sp-label">Inactive</div>
                            <div class="sp-sub"><?= $hwInactivePct ?>%</div>
                        </div>
                        <div class="sp-count"><?= $hwInactiveCount ?></div>
                    </div>
                </div>
                <div class="chart-box">
                    <canvas id="workerStatusChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Row 2: Records per Health Worker + Nutrition Status by Month -->
        <div class="dashboard-row-records">

            <!-- Table: Records per Health Worker -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Records per Health Worker</h3>
                        <p class="section-subtitle">Total health records submitted by each worker · all time</p>
                    </div>
                    <span class="analytics-badge green">Performance</span>
                </div>
                <div class="dash-inner-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>Health Worker</th>
                                <th>Status</th>
                                <th>This Month</th>
                                <th>Last Month</th>
                                <th>All Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($workerRecordsRows)): ?>
                                <?php foreach ($workerRecordsRows as $w):
                                    $thisMonth = (int) $w['total_this_month'];
                                    $lastMonth = (int) $w['total_last_month'];
                                    $allTime = (int) $w['total_all'];
                                    $diff = $thisMonth - $lastMonth;
                                    $trendIcon = $diff > 0 ? '▲' : ($diff < 0 ? '▼' : '—');
                                    $trendColor = $diff > 0 ? '#12b76a' : ($diff < 0 ? '#f04438' : '#94a3b8');
                                    $initials = workerInitials($w['full_name']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="admin-table-worker-cell">
                                                <?php if (!empty($w['profile_image']) && file_exists("../../" . $w['profile_image'])): ?>
                                                    <img src="../../<?= htmlspecialchars($w['profile_image']) ?>"
                                                        alt="<?= htmlspecialchars($w['full_name']) ?>"
                                                        class="admin-table-worker-avatar">
                                                <?php else: ?>
                                                    <div class="admin-table-worker-avatar-initials">
                                                        <?= $initials ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="dash-cell-name"><?= htmlspecialchars($w['full_name']) ?></div>
                                                    <div class="dash-cell-sub"><?= htmlspecialchars($w['department'] ?? '—') ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span
                                                class="admin-badge <?= $w['status'] === 'Active' ? 'admin-badge-active' : 'admin-badge-inactive' ?>">
                                                <?= htmlspecialchars($w['status']) ?>
                                            </span>
                                        </td>
                                        <td class="dash-td-bmi">
                                            <?= $thisMonth ?>
                                            <span
                                                style="font-size:10px;margin-left:3px;color:<?= $trendColor ?>"><?= $trendIcon ?></span>
                                        </td>
                                        <td class="dash-td-muted"><?= $lastMonth ?></td>
                                        <td class="dash-td-bmi"><?= $allTime ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="dash-td-empty">
                                        <div class="dash-empty">
                                            <div class="dash-empty-icon">👩‍⚕️</div>
                                            <div class="dash-empty-text">No health workers found.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Chart 4: Nutrition Status by Month -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Nutrition Status by Month</h3>
                        <p class="section-subtitle">Monthly BMI status breakdown · last 6 months</p>
                    </div>
                    <span class="analytics-badge amber">Last 6 Months</span>
                </div>
                <div class="chart-box">
                    <canvas id="statusStackedChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Row 3: Age Group Distribution + Gender Distribution -->
        <div class="dashboard-row-bmi">

            <!-- Chart 5: Age Group Distribution -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Age Group Distribution</h3>
                        <p class="section-subtitle">Breakdown of all registered residents by age group</p>
                    </div>
                    <span class="analytics-badge green">Demographics</span>
                </div>
                <div class="chart-box">
                    <canvas id="ageGroupChart"></canvas>
                </div>
            </div>

            <!-- Chart 6: Gender Distribution -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Gender Distribution</h3>
                        <p class="section-subtitle">Breakdown of registered residents by gender</p>
                    </div>
                    <span class="analytics-badge green">Demographics</span>
                </div>
                <div class="status-pill-grid">
                    <div class="status-pill sp-nm">
                        <div>
                            <div class="sp-label">Male</div>
                            <div class="sp-sub"><?= $genderMalePct ?>%</div>
                        </div>
                        <div class="sp-count"><?= $genderMale ?></div>
                    </div>
                    <div class="status-pill sp-uw">
                        <div>
                            <div class="sp-label">Female</div>
                            <div class="sp-sub"><?= $genderFemPct ?>%</div>
                        </div>
                        <div class="sp-count"><?= $genderFemale ?></div>
                    </div>
                </div>
                <div class="chart-box">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Row 4: Total Records Over 12 Months (full width) -->
        <div class="dashboard-row-weekly">
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Total Records Over 12 Months</h3>
                        <p class="section-subtitle">System-wide health records submitted per month · last 12 months</p>
                    </div>
                    <span class="analytics-badge">Last 12 Months</span>
                </div>
                <div class="chart-box">
                    <canvas id="recordsTrendChart"></canvas>
                </div>
            </div>
        </div>

    </main>

    <?php include "../../includes/footer.php"; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        // ── Global Defaults
        Chart.defaults.color = '#8899b3';
        Chart.defaults.borderColor = '#eef1f7';
        Chart.defaults.font.family = "'Plus Jakarta Sans', 'Segoe UI', system-ui, sans-serif";
        Chart.defaults.font.size = 11.5;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.legend.labels.padding = 14;

        // ── Chart 1: Monthly New Residents Trend
        const trendCtx = document.getElementById('residentTrendChart').getContext('2d');
        const trendGrad = trendCtx.createLinearGradient(0, 0, 0, 280);
        trendGrad.addColorStop(0, 'rgba(37,99,235,0.25)');
        trendGrad.addColorStop(1, 'rgba(37,99,235,0.01)');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($residentTrendLabels) ?>,
                datasets: [{
                    label: 'New Residents',
                    data: <?= json_encode($residentTrendData) ?>,
                    borderColor: '#2563eb',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#2563eb',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    backgroundColor: trendGrad,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1a1f36', titleFont: { weight: '700' }, padding: 12, cornerRadius: 8,
                        callbacks: { label: ctx => ` ${ctx.raw} new resident${ctx.raw !== 1 ? 's' : ''}` }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b' } },
                    y: { beginAtZero: true, grid: { color: '#f0f2f8' }, ticks: { precision: 0, color: '#94a3b8' }, title: { display: true, text: 'New Residents', color: '#94a3b8', font: { size: 11 } } }
                }
            }
        });

        // ── Chart 2: Health Worker Status
        new Chart(document.getElementById('workerStatusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Inactive'],
                datasets: [{
                    data: [<?= $hwActiveCount ?>, <?= $hwInactiveCount ?>],
                    backgroundColor: ['rgba(5,150,105,0.82)', 'rgba(239,68,68,0.82)'],
                    borderColor: ['#059669', '#ef4444'],
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '64%',
                plugins: {
                    legend: { position: 'right', labels: { font: { size: 12 }, padding: 16 } },
                    tooltip: {
                        backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8,
                        callbacks: {
                            label: ctx => {
                                const total = <?= $totalHealthWorkers ?>;
                                const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return ` ${ctx.label}: ${ctx.raw} worker${ctx.raw !== 1 ? 's' : ''} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // ── Chart 3: Records per Health Worker is now a table (no JS needed)

        // ── Chart 4: Nutrition Status by Month
        new Chart(document.getElementById('statusStackedChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($stackedLabels) ?>,
                datasets: [
                    { label: 'Underweight', data: <?= json_encode($sUW) ?>, backgroundColor: 'rgba(217,119,6,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status' },
                    { label: 'Normal', data: <?= json_encode($sNM) ?>, backgroundColor: 'rgba(5,150,105,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status' },
                    { label: 'Overweight', data: <?= json_encode($sOW) ?>, backgroundColor: 'rgba(234,88,12,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status' },
                    { label: 'Obese', data: <?= json_encode($sOB) ?>, backgroundColor: 'rgba(220,38,38,0.8)', borderRadius: 4, borderSkipped: false, stack: 'status' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top' }, tooltip: { backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8 } },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, grid: { color: '#f0f2f8' } }
                }
            }
        });

        // ── Chart 5: Age Group Distribution
        new Chart(document.getElementById('ageGroupChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($ageLabels) ?>,
                datasets: [{
                    label: 'Residents',
                    data: <?= json_encode($ageData) ?>,
                    backgroundColor: ['rgba(99,102,241,0.8)', 'rgba(37,99,235,0.8)', 'rgba(5,150,105,0.8)', 'rgba(234,88,12,0.8)', 'rgba(220,38,38,0.8)'],
                    borderColor: ['#6366f1', '#2563eb', '#059669', '#ea580c', '#dc2626'],
                    borderWidth: 2, borderRadius: 8, borderSkipped: false,
                    barPercentage: 0.55, categoryPercentage: 0.7,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8,
                        callbacks: {
                            label: ctx => {
                                const total = <?= $ageTotal ?>;
                                const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return ` ${ctx.raw} residents (${pct}%)`;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b' } },
                    y: { beginAtZero: true, grid: { color: '#f0f2f8' }, ticks: { precision: 0, color: '#94a3b8' }, title: { display: true, text: 'Number of Residents', color: '#94a3b8', font: { size: 11 } } }
                }
            }
        });

        // ── Chart 6: Gender Distribution
        new Chart(document.getElementById('genderChart'), {
            type: 'doughnut',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [<?= $genderMale ?>, <?= $genderFemale ?>],
                    backgroundColor: ['rgba(37,99,235,0.82)', 'rgba(236,72,153,0.82)'],
                    borderColor: ['#2563eb', '#ec4899'],
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '64%',
                plugins: {
                    legend: { position: 'right', labels: { font: { size: 12 }, padding: 16 } },
                    tooltip: {
                        backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8,
                        callbacks: {
                            label: ctx => {
                                const total = <?= $genderTotal ?>;
                                const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return ` ${ctx.label}: ${ctx.raw} residents (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // ── Chart 7: Total Records Over 12 Months
        const recordsCtx = document.getElementById('recordsTrendChart').getContext('2d');
        const recordsGrad = recordsCtx.createLinearGradient(0, 0, 0, 300);
        recordsGrad.addColorStop(0, 'rgba(5,150,105,0.2)');
        recordsGrad.addColorStop(1, 'rgba(5,150,105,0.01)');
        new Chart(recordsCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($recordsTrendLabels) ?>,
                datasets: [{
                    label: 'Health Records',
                    data: <?= json_encode($recordsTrendData) ?>,
                    borderColor: '#059669',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#059669',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    backgroundColor: recordsGrad,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1a1f36', titleFont: { weight: '700' }, padding: 12, cornerRadius: 8,
                        callbacks: { label: ctx => ` ${ctx.raw} record${ctx.raw !== 1 ? 's' : ''} submitted` }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b' } },
                    y: { beginAtZero: true, grid: { color: '#f0f2f8' }, ticks: { precision: 0, color: '#94a3b8' }, title: { display: true, text: 'Records Submitted', color: '#94a3b8', font: { size: 11 } } }
                }
            }
        });
    </script>

</body>

</html>