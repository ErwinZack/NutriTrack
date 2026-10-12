<?php
require_once "../../includes/auth_health_worker.php";
require_once "../../config/config.php";
require_once "../../includes/functions.php";

$barangay_id = $_SESSION['barangay_id'];

$stmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :brgy");
$stmt->execute([':brgy' => $barangay_id]);
$barangay_name = $stmt->fetchColumn() ?: 'Unknown Barangay';

/* ── Date Range ── */
$allowedRanges = ['7', '30', '90', 'all'];
$range = isset($_GET['range']) && in_array($_GET['range'], $allowedRanges) ? $_GET['range'] : '30';

/* ── Stat Cards ── */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE barangay_id = :brgy");
$stmt->execute([':brgy' => $barangay_id]);
$totalResidents = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE barangay_id = :brgy");
$stmt->execute([':brgy' => $barangay_id]);
$totalRecords = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM health_records
    WHERE barangay_id = :brgy
      AND MONTH(record_date) = MONTH(CURRENT_DATE())
      AND YEAR(record_date)  = YEAR(CURRENT_DATE())
");
$stmt->execute([':brgy' => $barangay_id]);
$newRecordsThisMonth = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT hr.resident_id)
    FROM health_records hr
   INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id
           AND hr.record_id    = latest.latest_id
    WHERE hr.barangay_id = :brgy2
      AND (hr.bmi < 18.5 OR hr.bmi > 25)
");
$stmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id]);
$atRiskResidents = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT hr.resident_id)
    FROM health_records hr
   INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id
           AND hr.record_id    = latest.latest_id
    WHERE hr.barangay_id = :brgy2
      AND hr.bmi >= 18.5 AND hr.bmi <= 25
");
$stmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id]);
$normalCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM residents
    WHERE barangay_id = :brgy
      AND MONTH(created_at) = MONTH(CURRENT_DATE())
      AND YEAR(created_at)  = YEAR(CURRENT_DATE())
");
$stmt->execute([':brgy' => $barangay_id]);
$newResidentsThisMonth = $stmt->fetchColumn();

$atRiskPct = $totalResidents > 0 ? round(($atRiskResidents / $totalResidents) * 100, 1) : 0;
$normalPct = $totalResidents > 0 ? round(($normalCount / $totalResidents) * 100, 1) : 0;

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM health_records
    WHERE barangay_id = :brgy
      AND MONTH(record_date) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH)
      AND YEAR(record_date)  = YEAR(CURRENT_DATE()  - INTERVAL 1 MONTH)
");
$stmt->execute([':brgy' => $barangay_id]);
$recordsLastMonth = $stmt->fetchColumn();
$recordsDiff = $newRecordsThisMonth - $recordsLastMonth;
$recordsFooterClass = $recordsDiff >= 0 ? 'positive' : 'negative';
$recordsSign = $recordsDiff >= 0 ? '+' : '';

/* ── Chart 1: BMI Category Overview ── */
$bmiCategoryStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN bmi < 18.5                  THEN 1 ELSE 0 END) AS underweight,
        SUM(CASE WHEN bmi >= 18.5 AND bmi < 25    THEN 1 ELSE 0 END) AS normal,
        SUM(CASE WHEN bmi >= 25   AND bmi < 30    THEN 1 ELSE 0 END) AS overweight,
        SUM(CASE WHEN bmi >= 30                   THEN 1 ELSE 0 END) AS obese
    FROM health_records hr
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id
           AND hr.record_id    = latest.latest_id
    WHERE hr.barangay_id = :brgy2
");
$bmiCategoryStmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id]);
$bmiCategoryRow = $bmiCategoryStmt->fetch();
$bmiCatUnderweight = (int) ($bmiCategoryRow['underweight'] ?? 0);
$bmiCatNormal = (int) ($bmiCategoryRow['normal'] ?? 0);
$bmiCatOverweight = (int) ($bmiCategoryRow['overweight'] ?? 0);
$bmiCatObese = (int) ($bmiCategoryRow['obese'] ?? 0);
$bmiCatTotal = $bmiCatUnderweight + $bmiCatNormal + $bmiCatOverweight + $bmiCatObese;

/* ── Helpers ── */
function bmiPct(int $val, int $total): string
{
    return $total > 0 ? round(($val / $total) * 100, 1) . '%' : '0%';
}

function dashBmiPillClass(float $bmi): string
{
    if ($bmi < 18.5)
        return 'dash-pill-underweight';
    if ($bmi < 25.0)
        return 'dash-pill-normal';
    if ($bmi < 30.0)
        return 'dash-pill-overweight';
    return 'dash-pill-obese';
}

function dashBmiLabel(float $bmi): string
{
    if ($bmi < 18.5)
        return 'Underweight';
    if ($bmi < 25.0)
        return 'Normal';
    if ($bmi < 30.0)
        return 'Overweight';
    return 'Obese';
}

/* ── Chart 2: Gender Distribution ── */
$genderStmt = $pdo->prepare("SELECT gender, COUNT(*) AS cnt FROM residents WHERE barangay_id = :brgy GROUP BY gender");
$genderStmt->execute([':brgy' => $barangay_id]);
$genderRows = $genderStmt->fetchAll();
$genderMale = 0;
$genderFemale = 0;
foreach ($genderRows as $gr) {
    if ($gr['gender'] === 'Male')
        $genderMale = (int) $gr['cnt'];
    if ($gr['gender'] === 'Female')
        $genderFemale = (int) $gr['cnt'];
}
$genderTotal = $genderMale + $genderFemale;
$genderMalePct = $genderTotal > 0 ? round(($genderMale / $genderTotal) * 100, 1) : 0;
$genderFemPct = $genderTotal > 0 ? round(($genderFemale / $genderTotal) * 100, 1) : 0;

/* ── Chart 3: Assessments per Day of Week ── */
$dowStmt = $pdo->prepare("
    SELECT DAYOFWEEK(record_date) AS dow, COUNT(*) AS total
    FROM health_records
    WHERE barangay_id = :brgy
    GROUP BY dow
    ORDER BY dow ASC
");
$dowStmt->execute([':brgy' => $barangay_id]);
$dowRows = $dowStmt->fetchAll();

$dowMap = [2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat', 1 => 'Sun'];
$dowCounts = array_fill_keys(array_keys($dowMap), 0);
foreach ($dowRows as $row) {
    $dowCounts[(int) $row['dow']] = (int) $row['total'];
}
$dowLabels = array_values($dowMap);
$dowData = array_values($dowCounts);
$dowAvg = count($dowData) > 0 ? round(array_sum($dowData) / count($dowData), 1) : 0;
$dowMax = max($dowData) ?: 1;

$dowColors = [];
$dowBorderColors = [];
foreach ($dowData as $val) {
    $dowColors[] = $val === $dowMax ? 'rgba(37,99,235,0.85)' : 'rgba(37,99,235,0.35)';
    $dowBorderColors[] = $val === $dowMax ? '#2563eb' : 'rgba(37,99,235,0.5)';
}

/* ── Chart 4: Nutrition Status by Month ── */
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
            SUM(CASE WHEN bmi < 18.5 THEN 1 ELSE 0 END) AS uw,
            SUM(CASE WHEN bmi >= 18.5 AND bmi < 25 THEN 1 ELSE 0 END) AS nm,
            SUM(CASE WHEN bmi >= 25 AND bmi < 30 THEN 1 ELSE 0 END) AS ow,
            SUM(CASE WHEN bmi >= 30 THEN 1 ELSE 0 END) AS ob
        FROM health_records
        WHERE barangay_id = :brgy AND YEAR(record_date) = :y AND MONTH(record_date) = :m
    ");
    $sq->execute([':brgy' => $barangay_id, ':y' => $year, ':m' => $month]);
    $sr = $sq->fetch();
    $sUW[] = (int) ($sr['uw'] ?? 0);
    $sNM[] = (int) ($sr['nm'] ?? 0);
    $sOW[] = (int) ($sr['ow'] ?? 0);
    $sOB[] = (int) ($sr['ob'] ?? 0);
}

/* ── Chart 5: Top 10 Highest BMI ── */
$highBmiStmt = $pdo->prepare("
    SELECT CONCAT(r.first_name,' ',r.last_name) AS name, hr.bmi
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records WHERE barangay_id = :brgy GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2 AND hr.bmi >= 25
    ORDER BY hr.bmi DESC LIMIT 10
");
$highBmiStmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id]);
$highBmiRows = $highBmiStmt->fetchAll();
$highBmiNames = array_column($highBmiRows, 'name');
$highBmiValues = array_map(fn($r) => (float) $r['bmi'], $highBmiRows);

/* ── Chart 6: Top 10 Lowest BMI ── */
$lowBmiStmt = $pdo->prepare("
    SELECT CONCAT(r.first_name,' ',r.last_name) AS name, hr.bmi
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records WHERE barangay_id = :brgy GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2 AND hr.bmi < 18.5
    ORDER BY hr.bmi ASC LIMIT 10
");
$lowBmiStmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id]);
$lowBmiRows = $lowBmiStmt->fetchAll();
$lowBmiNames = array_column($lowBmiRows, 'name');
$lowBmiValues = array_map(fn($r) => (float) $r['bmi'], $lowBmiRows);

/* ── Recent Health Records (Top 5) ── */
$recentRecordsStmt = $pdo->prepare("
    SELECT
        hr.record_id,
        hr.bmi,
        hr.weight,
        hr.height,
        hr.record_date,
        r.first_name,
        r.last_name,
        r.gender,
        r.address,
        TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
    ORDER BY hr.record_date DESC, hr.record_id DESC
    LIMIT 5
");
$recentRecordsStmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id]);
$recentRecords = $recentRecordsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Health Worker Dashboard | NutriTrack</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/dashboard-header.css">
    <link rel="stylesheet" href="../../assets/css/dash-table.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/stat-card-link.css">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_health_worker.php"; ?>

    <main class="dashboard">

        <!-- Page Header -->
        <div class="dashboard-page-header">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p class="dashboard-subtitle">Community nutritional health overview ·
                    <?= htmlspecialchars($barangay_name) ?></p>
            </div>
        </div>

        <!-- Welcome Banner -->
        <div class="dashboard-welcome">
            <div class="dashboard-welcome-text">
                <h2>Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Health Worker') ?> 👋</h2>
                <p>Here's what's happening in your community today.</p>
            </div>
            <div class="dashboard-welcome-date">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                    <line x1="16" y1="2" x2="16" y2="6" />
                    <line x1="8" y1="2" x2="8" y2="6" />
                    <line x1="3" y1="10" x2="21" y2="10" />
                </svg>
                <?= date('l, F j, Y') ?>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="dashboard-stats">

            <a href="residents.php" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-content">
                            <h3 class="stat-title">Total Residents</h3>
                            <p class="stat-value"><?= number_format($totalResidents) ?></p>
                        </div>
                        <div class="stat-icon"><img src="../../assets/images/users-icon.png" alt="Users"></div>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-footer <?= $newResidentsThisMonth > 0 ? 'positive' : 'negative' ?>">
                        <?php if ($newResidentsThisMonth > 0): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" />
                                <polyline points="17 6 23 6 23 12" />
                            </svg>
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="23 18 13.5 8.5 8.5 13.5 1 6" />
                                <polyline points="17 18 23 18 23 12" />
                            </svg>
                        <?php endif; ?>
                        <?= $newResidentsThisMonth > 0 ? '+' : '' ?><?= number_format($newResidentsThisMonth) ?> this
                        month
                        <span class="footer-muted">· registered</span>
                    </div>
                </div>
            </a>

            <a href="reports.php?tab=priority" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-content">
                            <h3 class="stat-title">At Risk</h3>
                            <p class="stat-value"><?= number_format($atRiskResidents) ?></p>
                        </div>
                        <div class="stat-icon"><img src="../../assets/images/warning-icon.png" alt="Warning"></div>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-footer warning">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <?= $atRiskPct ?>% of residents <span class="footer-muted">· needs care</span>
                    </div>
                </div>
            </a>

            <a href="health_records.php?filter=normal" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-content">
                            <h3 class="stat-title">Normal BMI</h3>
                            <p class="stat-value"><?= number_format($normalCount) ?></p>
                        </div>
                        <div class="stat-icon"><img src="../../assets/images/check-icon.png" alt="Check"></div>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-footer positive">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                            <polyline points="22 4 12 14.01 9 11.01" />
                        </svg>
                        <?= $normalPct ?>% of total <span class="footer-muted">· healthy</span>
                    </div>
                </div>
            </a>

            <a href="health_records_all.php?month=<?= date('Y-m') ?>" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-content">
                            <h3 class="stat-title">Records This Month</h3>
                            <p class="stat-value"><?= number_format($newRecordsThisMonth) ?></p>
                        </div>
                        <div class="stat-icon"><img src="../../assets/images/records-icon.png" alt="Records"></div>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-footer <?= $recordsFooterClass ?>">
                        <?php if ($recordsDiff >= 0): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" />
                                <polyline points="17 6 23 6 23 12" />
                            </svg>
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="23 18 13.5 8.5 8.5 13.5 1 6" />
                                <polyline points="17 18 23 18 23 12" />
                            </svg>
                        <?php endif; ?>
                        <?= $recordsSign . $recordsDiff ?> from last month <span class="footer-muted">· records</span>
                    </div>
                </div>
            </a>

        </div>

        <!-- Row 1: BMI Category Overview + Gender Distribution -->
        <div class="dashboard-row-overview">

            <!-- Chart 1: BMI Category Overview -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">BMI Category Overview</h3>
                        <p class="section-subtitle">Overall distribution of residents across all 4 BMI categories ·
                            based on latest record per resident</p>
                    </div>
                    <span class="analytics-badge">All Residents</span>
                </div>
                <div class="bmi-pill-grid">
                    <div class="bmi-pill bmi-uw">
                        <div>
                            <div class="bmi-pill-label">Underweight</div>
                            <div class="bmi-pill-pct"><?= bmiPct($bmiCatUnderweight, $bmiCatTotal) ?></div>
                        </div>
                        <div class="bmi-pill-count"><?= $bmiCatUnderweight ?></div>
                    </div>
                    <div class="bmi-pill bmi-nm">
                        <div>
                            <div class="bmi-pill-label">Normal</div>
                            <div class="bmi-pill-pct"><?= bmiPct($bmiCatNormal, $bmiCatTotal) ?></div>
                        </div>
                        <div class="bmi-pill-count"><?= $bmiCatNormal ?></div>
                    </div>
                    <div class="bmi-pill bmi-ow">
                        <div>
                            <div class="bmi-pill-label">Overweight</div>
                            <div class="bmi-pill-pct"><?= bmiPct($bmiCatOverweight, $bmiCatTotal) ?></div>
                        </div>
                        <div class="bmi-pill-count"><?= $bmiCatOverweight ?></div>
                    </div>
                    <div class="bmi-pill bmi-ob">
                        <div>
                            <div class="bmi-pill-label">Obese</div>
                            <div class="bmi-pill-pct"><?= bmiPct($bmiCatObese, $bmiCatTotal) ?></div>
                        </div>
                        <div class="bmi-pill-count"><?= $bmiCatObese ?></div>
                    </div>
                </div>
                <div class="chart-box chart-box-lg">
                    <canvas id="bmiCategoryChart"></canvas>
                </div>
            </div>

            <!-- Chart 2: Gender Distribution -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Gender Distribution</h3>
                        <p class="section-subtitle">Breakdown of registered residents by gender</p>
                    </div>
                    <span class="analytics-badge green">Demographics</span>
                </div>
                <div class="status-pill-grid">
                    <div class="status-pill sp-male">
                        <div>
                            <div class="sp-label">Male</div>
                            <div class="sp-sub"><?= $genderMalePct ?>%</div>
                        </div>
                        <div class="sp-count"><?= $genderMale ?></div>
                    </div>
                    <div class="status-pill sp-female">
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

        <!-- Row 2: Recent Health Records + Nutrition Status by Month -->
        <div class="dashboard-row-records">

            <!-- Recent Health Records (Top 5) -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Recent Health Records</h3>
                        <p class="section-subtitle">Last health assessments submitted</p>
                    </div>
                    <a href="health_records.php" class="analytics-badge analytics-badge-link">View All</a>
                </div>
                <div class="dash-inner-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>Resident</th>
                                <th>Gender / Age</th>
                                <th>BMI</th>
                                <th>Status</th>
                                <!-- <th>Weight / Height</th> -->
                                <th>Record Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentRecords)): ?>
                                <?php foreach ($recentRecords as $rec):
                                    $bmi = (float) $rec['bmi'];
                                    $initials = strtoupper(substr($rec['first_name'], 0, 1) . substr($rec['last_name'], 0, 1));
                                    $gender = $rec['gender'] === 'Female' ? 'female' : 'male';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="dash-cell">
                                                <div class="dash-cell-name">
                                                    <?= htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']) ?>
                                                </div>
                                                <div class="dash-cell-sub"><?= htmlspecialchars($rec['address'] ?? '—') ?></div>
                                            </div>
                                        </td>
                                        <td class="dash-td-muted"><?= htmlspecialchars($rec['gender']) ?>,
                                            <?= (int) $rec['age'] ?> yrs
                                        </td>
                                        <td class="dash-td-bmi"><?= number_format($bmi, 1) ?></td>
                                        <td><span
                                                class="dash-pill <?= dashBmiPillClass($bmi) ?>"><?= dashBmiLabel($bmi) ?></span>
                                        </td>
                                        <!-- <td class="dash-td-muted"><?= number_format((float) $rec['weight'], 1) ?> kg / <?= number_format((float) $rec['height'], 1) ?> cm</td> -->
                                        <td class="dash-td-muted-sm"><?= date('M j, Y', strtotime($rec['record_date'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="dash-td-empty">
                                        <div class="dash-empty">
                                            <div class="dash-empty-icon">📋</div>
                                            <div class="dash-empty-text">No health records found.</div>
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

        <!-- Row 3: Top 10 Highest BMI + Top 10 Lowest BMI -->
        <div class="dashboard-row-bmi">

            <!-- Chart 5: Top 10 Highest BMI -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Top 10 Highest BMI Residents</h3>
                        <p class="section-subtitle">Residents most at risk of obesity — requires urgent attention</p>
                    </div>
                    <span class="analytics-badge red">Urgent</span>
                </div>
                <div class="chart-box">
                    <canvas id="highBmiChart"></canvas>
                </div>
            </div>

            <!-- Chart 6: Top 10 Lowest BMI -->
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Top 10 Lowest BMI Residents</h3>
                        <p class="section-subtitle">Residents most at risk of malnutrition or underweight conditions</p>
                    </div>
                    <span class="analytics-badge red">Urgent</span>
                </div>
                <div class="chart-box">
                    <canvas id="lowBmiChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Row 4: Assessments per Day of Week (full width) -->
        <div class="dashboard-row-weekly">
            <div class="chart-card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Assessments per Day of Week</h3>
                        <p class="section-subtitle">
                            Total health records submitted per weekday · busiest day highlighted
                            <strong class="subtitle-avg-highlight">Avg: <?= $dowAvg ?>/day</strong>
                        </p>
                    </div>
                    <span class="analytics-badge">All Time</span>
                </div>
                <div class="chart-box">
                    <canvas id="dowChart"></canvas>
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

        // ── Chart 1: BMI Category Overview
        const bmiCatCtx = document.getElementById('bmiCategoryChart').getContext('2d');
        const bmiCatData = [<?= $bmiCatUnderweight ?>, <?= $bmiCatNormal ?>, <?= $bmiCatOverweight ?>, <?= $bmiCatObese ?>];
        const bmiCatTotal = <?= $bmiCatTotal ?>;

        const BORDER_COLORS = ['#0891b2', '#16a34a', '#d97706', '#dc2626'];
        const RAW_COLORS = [[8, 145, 178], [22, 163, 74], [217, 119, 6], [220, 38, 38]];

        const bmiGradients = RAW_COLORS.map(([r, g, b]) => {
            const grad = bmiCatCtx.createLinearGradient(0, 0, 0, 280);
            grad.addColorStop(0, `rgba(${r},${g},${b},0.95)`);
            grad.addColorStop(1, `rgba(${r},${g},${b},0.45)`);
            return grad;
        });

        new Chart(bmiCatCtx, {
            type: 'bar',
            data: {
                labels: ['Underweight\n(BMI < 18.5)', 'Normal\n(18.5 – 24.9)', 'Overweight\n(25 – 29.9)', 'Obese\n(BMI ≥ 30)'],
                datasets: [{
                    label: 'Residents',
                    data: bmiCatData,
                    backgroundColor: bmiGradients,
                    borderColor: BORDER_COLORS,
                    borderWidth: 2,
                    borderRadius: 10,
                    borderSkipped: false,
                    barPercentage: 0.55,
                    categoryPercentage: 0.7,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1a1f36',
                        titleFont: { weight: '700' },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: ctx => {
                                const pct = bmiCatTotal > 0 ? ((ctx.raw / bmiCatTotal) * 100).toFixed(1) : 0;
                                return ` ${ctx.raw} residents (${pct}%)`;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b' } },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f0f2f8' },
                        ticks: { precision: 0, color: '#94a3b8' },
                        title: { display: true, text: 'Number of Residents', color: '#94a3b8', font: { size: 11 } }
                    }
                }
            }
        });

        // ── Chart 2: Gender Distribution
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
                responsive: true,
                maintainAspectRatio: false,
                cutout: '64%',
                plugins: {
                    legend: { position: 'right', labels: { font: { size: 12 }, padding: 16 } },
                    tooltip: {
                        backgroundColor: '#1a1f36',
                        padding: 12,
                        cornerRadius: 8,
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

        // ── Chart 3: Assessments per Day of Week
        const dowLabels = <?= json_encode($dowLabels) ?>;
        const dowData = <?= json_encode($dowData) ?>;
        const dowColors = <?= json_encode($dowColors) ?>;
        const dowBorderColors = <?= json_encode($dowBorderColors) ?>;
        const dowAvg = <?= $dowAvg ?>;
        const dowMax = Math.max(...dowData);

        new Chart(document.getElementById('dowChart'), {
            type: 'bar',
            data: {
                labels: dowLabels,
                datasets: [
                    {
                        label: 'Assessments',
                        data: dowData,
                        backgroundColor: dowColors,
                        borderColor: dowBorderColors,
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false,
                        order: 2
                    },
                    {
                        label: `Weekly Avg (${dowAvg})`,
                        data: dowLabels.map(() => dowAvg),
                        type: 'line',
                        borderColor: 'rgba(220,38,38,0.45)',
                        borderDash: [6, 4],
                        borderWidth: 1.5,
                        pointRadius: 0,
                        fill: false,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        backgroundColor: '#1a1f36',
                        titleFont: { weight: '700' },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: ctx => {
                                if (ctx.datasetIndex === 0) {
                                    const isBusiest = ctx.raw === dowMax;
                                    return ` ${ctx.label}: ${ctx.raw} assessment${ctx.raw !== 1 ? 's' : ''}${isBusiest ? ' 🔥 Busiest' : ''}`;
                                }
                                return ` Weekly avg: ${dowAvg} assessments`;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0 },
                        grid: { color: '#f0f2f8' },
                        title: { display: true, text: 'Total Assessments', color: '#8899b3', font: { size: 11 } }
                    }
                }
            }
        });

        // ── Chart 4: Nutrition Status by Month
        new Chart(document.getElementById('statusStackedChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($stackedLabels) ?>,
                datasets: [
                    { label: 'Underweight', data: <?= json_encode($sUW) ?>, backgroundColor: 'rgba(8,145,178,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status' },
                    { label: 'Normal', data: <?= json_encode($sNM) ?>, backgroundColor: 'rgba(22,163,74,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status' },
                    { label: 'Overweight', data: <?= json_encode($sOW) ?>, backgroundColor: 'rgba(217,119,6,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status' },
                    { label: 'Obese', data: <?= json_encode($sOB) ?>, backgroundColor: 'rgba(220,38,38,0.8)', borderRadius: 4, borderSkipped: false, stack: 'status' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: { backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8 }
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, grid: { color: '#f0f2f8' } }
                }
            }
        });

        // ── Chart 5: Top 10 Highest BMI
        new Chart(document.getElementById('highBmiChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($highBmiNames) ?>,
                datasets: [{
                    label: 'BMI',
                    data: <?= json_encode($highBmiValues) ?>,
                    backgroundColor: <?= json_encode($highBmiValues) ?>.map(v => v >= 30 ? 'rgba(220,38,38,0.8)' : 'rgba(217,119,6,0.75)'),
                    borderColor: <?= json_encode($highBmiValues) ?>.map(v => v >= 30 ? '#dc2626' : '#d97706'),
                    borderWidth: 2,
                    borderRadius: 5,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1a1f36',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: { label: ctx => ` BMI: ${ctx.parsed.x.toFixed(2)}` }
                    }
                },
                scales: {
                    x: { min: 20, grid: { color: '#f0f2f8' }, ticks: { stepSize: 5 }, title: { display: true, text: 'BMI Value', color: '#8899b3' } },
                    y: { grid: { display: false } }
                }
            }
        });

        // ── Chart 6: Top 10 Lowest BMI
        new Chart(document.getElementById('lowBmiChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($lowBmiNames) ?>,
                datasets: [{
                    label: 'BMI',
                    data: <?= json_encode($lowBmiValues) ?>,
                    backgroundColor: <?= json_encode($lowBmiValues) ?>.map(v => v < 16 ? 'rgba(220,38,38,0.8)' : 'rgba(8,145,178,0.75)'),
                    borderColor: <?= json_encode($lowBmiValues) ?>.map(v => v < 16 ? '#dc2626' : '#0891b2'),
                    borderWidth: 2,
                    borderRadius: 5,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1a1f36',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: { label: ctx => ` BMI: ${ctx.parsed.x.toFixed(2)}` }
                    }
                },
                scales: {
                    x: { max: 25, grid: { color: '#f0f2f8' }, ticks: { stepSize: 2 }, title: { display: true, text: 'BMI Value', color: '#8899b3' } },
                    y: { grid: { display: false } }
                }
            }
        });
    </script>

</body>

</html>