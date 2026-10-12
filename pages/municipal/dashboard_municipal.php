<?php
session_start();
require_once "../../config/config.php";

// Redirect if not logged in
if (!isset($_SESSION['superadmin_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: login_municipal.php");
    exit();
}

// --------------------------------------
// FETCH SUPERADMIN INFO
// --------------------------------------
$stmt = $pdo->prepare("SELECT full_name, profile_image FROM superadmins WHERE superadmin_id = :id LIMIT 1");
$stmt->execute([':id' => $_SESSION['superadmin_id']]);
$superadmin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!isset($_SESSION['full_name']) && $superadmin) {
    $_SESSION['full_name'] = $superadmin['full_name'];
}

$superadminName = $superadmin['full_name'] ?? 'Municipal Admin';

// --------------------------------------
// STAT CARDS (TOWN-WIDE — all barangays combined)
// --------------------------------------

/* ── Card 1: Total Barangays ── */
$totalBarangays = $pdo->query("SELECT COUNT(*) FROM barangays")->fetchColumn();
$activeBarangays = $pdo->query("SELECT COUNT(*) FROM barangays WHERE status = 'active'")->fetchColumn();
$inactiveBarangays = $totalBarangays - $activeBarangays;

/* ── Card 2: Total Barangay Admins (all barangays) ── */
$totalAdmins = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
$activeAdmins = $pdo->query("SELECT COUNT(*) FROM admins WHERE status = 'Active'")->fetchColumn();

/* ── Card 3: Total Residents (all barangays) ── */
$totalResidents = $pdo->query("SELECT COUNT(*) FROM residents")->fetchColumn();
$newResidentsThisMonth = $pdo->query("
    SELECT COUNT(*) FROM residents
    WHERE MONTH(created_at) = MONTH(CURRENT_DATE())
      AND YEAR(created_at)  = YEAR(CURRENT_DATE())
")->fetchColumn();

/* ── Card 4: New Records This Month + Trend ── */
$newRecordsThisMonth = $pdo->query("
    SELECT COUNT(*) FROM health_records
    WHERE MONTH(record_date) = MONTH(CURRENT_DATE())
      AND YEAR(record_date)  = YEAR(CURRENT_DATE())
")->fetchColumn();
$recordsLastMonth = $pdo->query("
    SELECT COUNT(*) FROM health_records
    WHERE MONTH(record_date) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH)
      AND YEAR(record_date)  = YEAR(CURRENT_DATE()  - INTERVAL 1 MONTH)
")->fetchColumn();
$recordsDiff = $newRecordsThisMonth - $recordsLastMonth;
$recordsSign = $recordsDiff >= 0 ? '+' : '';

// --------------------------------------
// CHART 1: Monthly New Residents Trend (town-wide)
// --------------------------------------
$residentTrendLabels = [];
$residentTrendData = [];
for ($i = 5; $i >= 0; $i--) {
    $year = date('Y', strtotime("-$i months"));
    $month = date('m', strtotime("-$i months"));
    $residentTrendLabels[] = date('M Y', strtotime("-$i months"));
    $rq = $pdo->prepare("
        SELECT COUNT(*) FROM residents
        WHERE YEAR(created_at) = :y AND MONTH(created_at) = :m
    ");
    $rq->execute([':y' => $year, ':m' => $month]);
    $residentTrendData[] = (int) $rq->fetchColumn();
}

// --------------------------------------
// CHART 2: Barangay Status (active vs inactive)
// --------------------------------------
$brgyActiveCount = $activeBarangays;
$brgyInactiveCount = $inactiveBarangays;
$brgyActivePct = $totalBarangays > 0 ? round(($brgyActiveCount / $totalBarangays) * 100, 1) : 0;
$brgyInactivePct = $totalBarangays > 0 ? round(($brgyInactiveCount / $totalBarangays) * 100, 1) : 0;

// --------------------------------------
// TABLE: Records per Barangay (Municipal cross-barangay oversight)
// --------------------------------------
$barangayRecordsStmt = $pdo->query("
    SELECT
        b.barangay_id,
        b.barangay_name,
        b.status,
        COUNT(DISTINCT a.admin_id)   AS total_admins,
        COUNT(DISTINCT hw.worker_id) AS total_workers,
        COUNT(DISTINCT r.resident_id) AS total_residents,
       COUNT(DISTINCT hr.record_id) AS total_records,
COUNT(DISTINCT CASE WHEN MONTH(hr.record_date) = MONTH(CURRENT_DATE()) AND YEAR(hr.record_date) = YEAR(CURRENT_DATE()) THEN hr.record_id END) AS records_this_month
    FROM barangays b
    LEFT JOIN admins a          ON a.barangay_id = b.barangay_id
    LEFT JOIN health_workers hw ON hw.barangay_id = b.barangay_id
    LEFT JOIN residents r       ON r.barangay_id = b.barangay_id
    LEFT JOIN health_records hr ON hr.barangay_id = b.barangay_id
    GROUP BY b.barangay_id, b.barangay_name, b.status
    ORDER BY total_records DESC
");
$barangayRecordsRows = $barangayRecordsStmt->fetchAll();

// Helper: get initials from barangay name
function barangayInitials(string $name): string
{
    $parts = explode(' ', trim($name));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1)
        $initials .= strtoupper(substr(end($parts), 0, 1));
    return $initials;
}

// --------------------------------------
// CHART 4: Nutrition Status by Month (town-wide)
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
// CHART 5: Age Group Distribution (town-wide)
// --------------------------------------
$ageGroupStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 0  AND 5  THEN 1 ELSE 0 END) AS g0_5,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 6  AND 12 THEN 1 ELSE 0 END) AS g6_12,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 13 AND 17 THEN 1 ELSE 0 END) AS g13_17,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 18 AND 59 THEN 1 ELSE 0 END) AS g18_59,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) >= 60             THEN 1 ELSE 0 END) AS g60plus
    FROM residents
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
// CHART 6: Age Group BMI Status Distribution (town-wide)
// --------------------------------------
$ageGroupBmiStmt = $pdo->query("
    SELECT
        CASE
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 0  AND 5  THEN '0–5'
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 6  AND 12 THEN '6–12'
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 13 AND 17 THEN '13–17'
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 18 AND 59 THEN '18–59'
            ELSE '60+'
        END AS age_group,
        SUM(CASE WHEN hr.bmi < 18.5                 THEN 1 ELSE 0 END) AS uw,
        SUM(CASE WHEN hr.bmi >= 18.5 AND hr.bmi < 25 THEN 1 ELSE 0 END) AS nm,
        SUM(CASE WHEN hr.bmi >= 25   AND hr.bmi < 30 THEN 1 ELSE 0 END) AS ow,
        SUM(CASE WHEN hr.bmi >= 30                  THEN 1 ELSE 0 END) AS ob
    FROM residents r
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records GROUP BY resident_id
    ) latest ON r.resident_id = latest.resident_id
    INNER JOIN health_records hr ON hr.record_id = latest.latest_id
    GROUP BY age_group
    ORDER BY MIN(TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()))
");
$ageGroupBmiRows = $ageGroupBmiStmt->fetchAll();

// Pre-initialize all age groups with 0 so they always show
$ageBmiMap = [
    '0–5' => ['uw' => 0, 'nm' => 0, 'ow' => 0, 'ob' => 0],
    '6–12' => ['uw' => 0, 'nm' => 0, 'ow' => 0, 'ob' => 0],
    '13–17' => ['uw' => 0, 'nm' => 0, 'ow' => 0, 'ob' => 0],
    '18–59' => ['uw' => 0, 'nm' => 0, 'ow' => 0, 'ob' => 0],
    '60+' => ['uw' => 0, 'nm' => 0, 'ow' => 0, 'ob' => 0],
];
foreach ($ageGroupBmiRows as $row) {
    if (isset($ageBmiMap[$row['age_group']])) {
        $ageBmiMap[$row['age_group']] = [
            'uw' => (int) $row['uw'],
            'nm' => (int) $row['nm'],
            'ow' => (int) $row['ow'],
            'ob' => (int) $row['ob'],
        ];
    }
}
$ageBmiLabels = array_keys($ageBmiMap);
$ageBmiUW = array_column($ageBmiMap, 'uw');
$ageBmiNM = array_column($ageBmiMap, 'nm');
$ageBmiOW = array_column($ageBmiMap, 'ow');
$ageBmiOB = array_column($ageBmiMap, 'ob');

// --------------------------------------
// CHART 7: Total Records Over 12 Months (town-wide)
// --------------------------------------
$recordsTrendLabels = [];
$recordsTrendData = [];
for ($i = 11; $i >= 0; $i--) {
    $year = date('Y', strtotime("-$i months"));
    $month = date('m', strtotime("-$i months"));
    $recordsTrendLabels[] = date('M Y', strtotime("-$i months"));
    $rq = $pdo->prepare("
        SELECT COUNT(*) FROM health_records
        WHERE YEAR(record_date) = :y AND MONTH(record_date) = :m
    ");
    $rq->execute([':y' => $year, ':m' => $month]);
    $recordsTrendData[] = (int) $rq->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Municipal Dashboard | NutriTrack</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/municipal.css">
    <link rel="stylesheet" href="../../assets/css/dashboard-header.css">
    <link rel="stylesheet" href="../../assets/css/dash-table.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_municipal.php"; ?>

    <main class="municipal-dashboard">

        <!-- Page Header -->
        <div class="municipal-page-header">
            <div>
                <h1 class="municipal-page-title">Municipal Dashboard</h1>
                <p class="municipal-welcome-message">
                    Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Municipal Admin'); ?> 👋
                </p>
            </div>
        </div>

        <!-- Welcome Banner -->
        <div class="municipal-welcome-banner">
            <div class="municipal-welcome-banner-text">
                <h2>Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Municipal Admin'); ?> 👋</h2>
                <p>Here's a town-wide overview of NutriTrack across all barangays today.</p>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="municipal-stats-grid">

            <a href="barangay_management.php" class="municipal-stat-card-link">
                <div class="municipal-stat-card">
                    <div class="municipal-stat-card-header">
                        <div class="municipal-stat-content">
                            <h3 class="municipal-stat-title">Total Barangays</h3>
                            <p class="municipal-stat-value"><?= number_format($totalBarangays); ?></p>
                        </div>
                        <div class="municipal-stat-icon municipal-stat-icon-barangays">
                            <i class="fas fa-map-location-dot"></i>
                        </div>
                    </div>
                    <div class="municipal-stat-divider"></div>
                    <div class="municipal-stat-footer">
                        <i class="fas fa-circle-check" style="color:#12b76a;font-size:12px;"></i>
                        <?= number_format($activeBarangays) ?> active <span class="municipal-stat-footer-muted">·
                            <?= number_format($inactiveBarangays) ?> inactive</span>
                    </div>
                </div>
            </a>

            <a href="user_management_municipal.php" class="municipal-stat-card-link">
                <div class="municipal-stat-card">
                    <div class="municipal-stat-card-header">
                        <div class="municipal-stat-content">
                            <h3 class="municipal-stat-title">Total Barangay Admins</h3>
                            <p class="municipal-stat-value"><?= number_format($totalAdmins); ?></p>
                        </div>
                        <div class="municipal-stat-icon municipal-stat-icon-workers">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                    <div class="municipal-stat-divider"></div>
                    <div class="municipal-stat-footer">
                        <i class="fas fa-circle-check" style="color:#12b76a;font-size:12px;"></i>
                        <?= number_format($activeAdmins) ?> active <span class="municipal-stat-footer-muted">·
                            town-wide</span>
                    </div>
                </div>
            </a>

            <a href="residents_municipal.php" class="municipal-stat-card-link">
                <div class="municipal-stat-card">
                    <div class="municipal-stat-card-header">
                        <div class="municipal-stat-content">
                            <h3 class="municipal-stat-title">Total Residents</h3>
                            <p class="municipal-stat-value"><?= number_format($totalResidents); ?></p>
                        </div>
                        <div class="municipal-stat-icon municipal-stat-icon-residents">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="municipal-stat-divider"></div>
                    <div class="municipal-stat-footer">
                        <i class="fas fa-circle-check" style="color:#12b76a;font-size:12px;"></i>
                        +<?= number_format($newResidentsThisMonth) ?> this month <span
                            class="municipal-stat-footer-muted">· all barangays</span>
                    </div>
                </div>
            </a>

            <a href="reports_municipal.php" class="municipal-stat-card-link">
                <div class="municipal-stat-card">
                    <div class="municipal-stat-card-header">
                        <div class="municipal-stat-content">
                            <h3 class="municipal-stat-title">New Records This Month</h3>
                            <p class="municipal-stat-value"><?= number_format($newRecordsThisMonth); ?></p>
                        </div>
                        <div class="municipal-stat-icon municipal-stat-icon-new">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                    </div>
                    <div class="municipal-stat-divider"></div>
                    <div class="municipal-stat-footer">
                        <?php if ($recordsDiff >= 0): ?>
                            <i class="fas fa-arrow-trend-up" style="color:#12b76a;font-size:12px;"></i>
                        <?php else: ?>
                            <i class="fas fa-arrow-trend-down" style="color:#f04438;font-size:12px;"></i>
                        <?php endif; ?>
                        <?= $recordsSign . $recordsDiff ?> from last month <span class="municipal-stat-footer-muted">·
                            records</span>
                    </div>
                </div>
            </a>

        </div>

        <!-- Row 1: Monthly New Residents Trend + Barangay Status -->
        <div class="municipal-dashboard-row-overview">

            <div class="municipal-chart-card">
                <div class="municipal-section-header">
                    <div>
                        <h3 class="municipal-section-title">Monthly New Residents Trend</h3>
                        <p class="municipal-section-subtitle">Newly registered residents per month, all barangays · last
                            6 months</p>
                    </div>
                    <span class="municipal-analytics-badge">Last 6 Months</span>
                </div>
                <div class="municipal-chart-box municipal-chart-box-lg">
                    <canvas id="residentTrendChart"></canvas>
                </div>
            </div>

            <div class="municipal-chart-card">
                <div class="municipal-section-header">
                    <div>
                        <h3 class="municipal-section-title">Barangay Status</h3>
                        <p class="municipal-section-subtitle">Active vs inactive barangays in the system</p>
                    </div>
                    <span class="municipal-analytics-badge">Oversight</span>
                </div>
                <div class="municipal-status-pill-grid">
                    <div class="municipal-status-pill sp-nm">
                        <div>
                            <div class="sp-label">Active</div>
                            <div class="sp-sub"><?= $brgyActivePct ?>%</div>
                        </div>
                        <div class="sp-count"><?= $brgyActiveCount ?></div>
                    </div>
                    <div class="municipal-status-pill sp-ob">
                        <div>
                            <div class="sp-label">Inactive</div>
                            <div class="sp-sub"><?= $brgyInactivePct ?>%</div>
                        </div>
                        <div class="sp-count"><?= $brgyInactiveCount ?></div>
                    </div>
                </div>
                <div class="municipal-chart-box">
                    <canvas id="barangayStatusChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Row 2: Records per Barangay + Nutrition Status by Month -->
        <div class="municipal-dashboard-row-records">

            <div class="municipal-chart-card">
                <div class="municipal-section-header">
                    <div>
                        <h3 class="municipal-section-title">Records per Barangay</h3>
                        <p class="municipal-section-subtitle">Cross-barangay activity overview · all time</p>
                    </div>
                    <span class="municipal-analytics-badge green">Cross-Barangay</span>
                </div>
                <div class="municipal-dash-inner-table-wrap">
                    <table class="municipal-dash-table">
                        <thead>
                            <tr>
                                <th>Barangay</th>
                                <th>Status</th>
                                <th>Workers</th>
                                <th>Residents</th>
                                <th>This Month</th>
                                <th>All Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($barangayRecordsRows)): ?>
                                <?php foreach ($barangayRecordsRows as $b):
                                    $initials = barangayInitials($b['barangay_name']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="municipal-table-barangay-cell">
                                                <div class="municipal-table-barangay-avatar-initials">
                                                    <?= $initials ?>
                                                </div>
                                                <div>
                                                    <div class="municipal-dash-cell-name">
                                                        <?= htmlspecialchars($b['barangay_name']) ?>
                                                    </div>
                                                    <div class="municipal-dash-cell-sub"><?= (int) $b['total_admins'] ?>
                                                        admin(s)
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span
                                                class="municipal-badge <?= $b['status'] === 'active' ? 'municipal-badge-active' : 'municipal-badge-inactive' ?>">
                                                <?= htmlspecialchars(ucfirst($b['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="municipal-dash-td-num"><?= (int) $b['total_workers'] ?></td>
                                        <td class="municipal-dash-td-num"><?= (int) $b['total_residents'] ?></td>
                                        <td class="municipal-dash-td-num"><?= (int) $b['records_this_month'] ?></td>
                                        <td class="municipal-dash-td-num"><?= (int) $b['total_records'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="municipal-dash-td-empty">
                                        <div class="municipal-dash-empty">
                                            <div class="municipal-dash-empty-icon">🏘️</div>
                                            <div class="municipal-dash-empty-text">No barangays found.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="municipal-chart-card">
                <div class="municipal-section-header">
                    <div>
                        <h3 class="municipal-section-title">Nutrition Status by Month</h3>
                        <p class="municipal-section-subtitle">Monthly BMI status breakdown, all barangays · last 6
                            months</p>
                    </div>
                    <span class="municipal-analytics-badge amber">Last 6 Months</span>
                </div>
                <div class="municipal-chart-box">
                    <canvas id="statusStackedChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Row 3: Age Group Distribution + Gender Distribution -->
        <div class="municipal-dashboard-row-bmi">

            <div class="municipal-chart-card">
                <div class="municipal-section-header">
                    <div>
                        <h3 class="municipal-section-title">Age Group Distribution</h3>
                        <p class="municipal-section-subtitle">All registered residents by age group, town-wide</p>
                    </div>
                    <span class="municipal-analytics-badge green">Demographics</span>
                </div>
                <div class="municipal-chart-box">
                    <canvas id="ageGroupChart"></canvas>
                </div>
            </div>

            <div class="municipal-chart-card">
                <div class="municipal-section-header">
                    <div>
                        <h3 class="municipal-section-title">Age Group BMI Status</h3>
                        <p class="municipal-section-subtitle">BMI distribution per age group, town-wide</p>
                    </div>
                    <span class="municipal-analytics-badge green">Demographics</span>
                </div>
                <div class="municipal-chart-box">
                    <canvas id="ageGroupBmiChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Row 4: Total Records Over 12 Months (full width) -->
        <div class="municipal-dashboard-row-weekly">
            <div class="municipal-chart-card">
                <div class="municipal-section-header">
                    <div>
                        <h3 class="municipal-section-title">Total Records Over 12 Months</h3>
                        <p class="municipal-section-subtitle">System-wide health records submitted per month · last 12
                            months</p>
                    </div>
                    <span class="municipal-analytics-badge">Last 12 Months</span>
                </div>
                <div class="municipal-chart-box">
                    <canvas id="recordsTrendChart"></canvas>
                </div>
            </div>
        </div>

    </main>

    <?php include "../../includes/footer.php"; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        Chart.defaults.color = '#8899b3';
        Chart.defaults.borderColor = '#eef1f7';
        Chart.defaults.font.family = "'Plus Jakarta Sans', 'Segoe UI', system-ui, sans-serif";
        Chart.defaults.font.size = 11.5;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.legend.labels.padding = 14;

        // Chart 1: Monthly New Residents Trend
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

        // Chart 2: Barangay Status
        new Chart(document.getElementById('barangayStatusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Inactive'],
                datasets: [{
                    data: [<?= $brgyActiveCount ?>, <?= $brgyInactiveCount ?>],
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
                                const total = <?= $totalBarangays ?>;
                                const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return ` ${ctx.label}: ${ctx.raw} barangay${ctx.raw !== 1 ? 's' : ''} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Chart 4: Nutrition Status by Month
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

        // Chart 5: Age Group Distribution
        new Chart(document.getElementById('ageGroupChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($ageLabels) ?>,
                datasets: [{
                    label: 'Residents',
                    data: <?= json_encode($ageData) ?>,
                    backgroundColor: ['rgba(134,239,172,0.8)', 'rgba(74,222,128,0.8)', 'rgba(59,130,246,0.8)', 'rgba(139,92,246,0.8)', 'rgba(107,114,128,0.8)'],
                    borderColor: ['#86efac', '#4ade80', '#3b82f6', '#8b5cf6', '#6b7280'],
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

        // Chart 6: Age Group BMI Status Distribution (horizontal stacked bar)
        new Chart(document.getElementById('ageGroupBmiChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($ageBmiLabels) ?>,
                datasets: [
                    { label: 'Underweight', data: <?= json_encode($ageBmiUW) ?>, backgroundColor: 'rgba(217,119,6,0.85)', borderRadius: 6, borderSkipped: false, stack: 'bmi' },
                    { label: 'Normal', data: <?= json_encode($ageBmiNM) ?>, backgroundColor: 'rgba(5,150,105,0.85)', borderRadius: 6, borderSkipped: false, stack: 'bmi' },
                    { label: 'Overweight', data: <?= json_encode($ageBmiOW) ?>, backgroundColor: 'rgba(234,88,12,0.85)', borderRadius: 6, borderSkipped: false, stack: 'bmi' },
                    { label: 'Obese', data: <?= json_encode($ageBmiOB) ?>, backgroundColor: 'rgba(220,38,38,0.85)', borderRadius: 6, borderSkipped: false, stack: 'bmi' }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: { backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8 }
                },
                scales: {
                    x: { stacked: true, beginAtZero: true, grid: { color: '#f0f2f8' }, ticks: { precision: 0, color: '#94a3b8' } },
                    y: { stacked: true, grid: { display: false }, ticks: { color: '#64748b' } }
                }
            }
        });

        // Chart 7: Total Records Over 12 Months
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