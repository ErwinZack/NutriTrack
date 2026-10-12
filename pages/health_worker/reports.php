<?php
// -------------------------------------------------------
// AUTH & DATABASE
// -------------------------------------------------------
require_once "../../includes/auth_health_worker.php";
require_once "../../config/config.php";
require_once "../../includes/functions.php";

$barangay_id = $_SESSION['barangay_id'];

// Fetch barangay name for PDF report header
$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $barangay_id]);
$barangay_name = $brgyStmt->fetchColumn();

// -------------------------------------------------------
// HANDLE ADD HEALTH RECORD (MODAL POST)
// -------------------------------------------------------
$add_success = false;
$add_error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['modal_add_record'])) {
    $resident_id = (int) ($_POST['resident_id'] ?? 0);
    $weight = (float) ($_POST['weight'] ?? 0);
    $height = (float) ($_POST['height'] ?? 0);
    $record_date = $_POST['record_date'] ?? '';

    if (empty($resident_id) || empty($weight) || empty($height) || empty($record_date)) {
        $add_error = "Please fill in all required fields.";
    } else {
        try {
            // [SECURITY] Verify resident belongs to this worker's barangay
            $checkStmt = $pdo->prepare("SELECT resident_id FROM residents WHERE resident_id = :id AND barangay_id = :brgy");
            $checkStmt->execute([':id' => $resident_id, ':brgy' => $barangay_id]);
            if (!$checkStmt->fetch()) {
                $add_error = "Invalid resident selected.";
            } else {
                $height_m = $height / 100;
                $bmi = round($weight / ($height_m ** 2), 2);
                $worker_id = $_SESSION['worker_id'];
                $stmt = $pdo->prepare("
                    INSERT INTO health_records (resident_id, worker_id, record_date, weight, height, bmi, barangay_id)
                    VALUES (:resident_id, :worker_id, :record_date, :weight, :height, :bmi, :brgy)
                ");
                $stmt->execute([
                    ':resident_id' => $resident_id,
                    ':worker_id' => $worker_id,
                    ':record_date' => $record_date,
                    ':weight' => $weight,
                    ':height' => $height,
                    ':bmi' => $bmi,
                    ':brgy' => $barangay_id,
                ]);
                $add_success = true;
            }
        } catch (Exception $e) {
            $add_error = "Database error. Please try again.";
        }
    }
}

// -------------------------------------------------------
// FILTERS
// -------------------------------------------------------
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selectedStatus = isset($_GET['status']) ? $_GET['status'] : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

$filterYear = date('Y', strtotime($selectedMonth . '-01'));
$filterMonth = date('n', strtotime($selectedMonth . '-01'));

// -------------------------------------------------------
// SUMMARY METRICS
// -------------------------------------------------------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE barangay_id = :brgy");
$stmt->execute([':brgy' => $barangay_id]);
$totalResidents = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE barangay_id = :brgy");
$stmt->execute([':brgy' => $barangay_id]);
$totalRecords = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM health_workers WHERE status = 'Active' AND barangay_id = :brgy");
$stmt->execute([':brgy' => $barangay_id]);
$totalWorkers = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT resident_id, first_name, last_name FROM residents WHERE barangay_id = :brgy ORDER BY first_name ASC");
$stmt->execute([':brgy' => $barangay_id]);
$allResidents = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT hr.bmi FROM health_records hr
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy1
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
");
$stmt->execute([':brgy1' => $barangay_id, ':brgy2' => $barangay_id]);
$bmiDistRows = $stmt->fetchAll();

$countUnderweight = $countNormal = $countOverweight = $countObese = 0;
foreach ($bmiDistRows as $row) {
    $b = (float) $row['bmi'];
    if ($b < 18.5)
        $countUnderweight++;
    elseif ($b < 25.0)
        $countNormal++;
    elseif ($b < 30.0)
        $countOverweight++;
    else
        $countObese++;
}
$atRisk = $countUnderweight + $countOverweight + $countObese;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE barangay_id = :brgy AND YEAR(record_date)=:y AND MONTH(record_date)=:m");
$stmt->execute([':brgy' => $barangay_id, ':y' => $filterYear, ':m' => $filterMonth]);
$recordsThisMonth = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT resident_id) FROM health_records WHERE barangay_id = :brgy AND YEAR(record_date)=:y AND MONTH(record_date)=:m");
$stmt->execute([':brgy' => $barangay_id, ':y' => $filterYear, ':m' => $filterMonth]);
$residentsThisMonth = (int) $stmt->fetchColumn();

// Current logged-in health worker (for PDF "Prepared by") — self lookup, no barangay filter needed
$stmt = $pdo->prepare("SELECT full_name FROM health_workers WHERE worker_id = :id");
$stmt->execute([':id' => $_SESSION['worker_id']]);
$currentWorkerName = $stmt->fetchColumn() ?: 'Health Worker';

// -------------------------------------------------------
// PRIORITY / AT-RISK RESIDENTS
// -------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT r.first_name, r.last_name, r.gender, r.birth_date, r.address, r.phone,
           hr.record_date, hr.weight, hr.height, hr.bmi, hr.ai_status, hr.ai_recommendation
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy1
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
      AND (hr.bmi < 18.5 OR hr.bmi >= 25)
    ORDER BY CASE WHEN hr.bmi < 18.5 THEN (18.5 - hr.bmi) ELSE (hr.bmi - 25.0) END DESC
");
$stmt->execute([':brgy1' => $barangay_id, ':brgy2' => $barangay_id]);
$atRiskResidents = $stmt->fetchAll();

// -------------------------------------------------------
// STATISTICS TAB
// -------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN bmi < 18.5 THEN 1 ELSE 0 END) AS Underweight,
        SUM(CASE WHEN bmi >= 18.5 AND bmi < 25 THEN 1 ELSE 0 END) AS Normal,
        SUM(CASE WHEN bmi >= 25 AND bmi < 30 THEN 1 ELSE 0 END) AS Overweight,
        SUM(CASE WHEN bmi >= 30 THEN 1 ELSE 0 END) AS Obese
    FROM health_records hr
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy1
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
      AND YEAR(hr.record_date) = :y AND MONTH(hr.record_date) = :m
");
$stmt->execute([':brgy1' => $barangay_id, ':brgy2' => $barangay_id, ':y' => $filterYear, ':m' => $filterMonth]);
$monthlyStatuses = $stmt->fetch(\PDO::FETCH_ASSOC);

$mUnderweight = (int) ($monthlyStatuses['Underweight'] ?? 0);
$mNormal = (int) ($monthlyStatuses['Normal'] ?? 0);
$mOverweight = (int) ($monthlyStatuses['Overweight'] ?? 0);
$mObese = (int) ($monthlyStatuses['Obese'] ?? 0);
$mTotal = $mUnderweight + $mNormal + $mOverweight + $mObese;

// -------------------------------------------------------
// HEALTH RECORDS TABLE
// -------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT hr.record_date, r.first_name, r.last_name, r.birth_date, r.gender,
           hr.weight, hr.height, hr.bmi, hr.ai_status, hw.full_name AS worker_name
    FROM health_records hr
    INNER JOIN residents r       ON hr.resident_id = r.resident_id
    INNER JOIN health_workers hw ON hr.worker_id   = hw.worker_id
    WHERE hr.barangay_id = :brgy AND YEAR(hr.record_date)=:y AND MONTH(hr.record_date)=:m
    ORDER BY hr.record_date DESC
");
$stmt->execute([':brgy' => $barangay_id, ':y' => $filterYear, ':m' => $filterMonth]);
$healthRecordsTable = $stmt->fetchAll();
$healthRecordsPreview = array_slice($healthRecordsTable, 0, 5);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE barangay_id = :brgy");
$stmt->execute([':brgy' => $barangay_id]);
$totalRecordsSystem = (int) $stmt->fetchColumn();

// -------------------------------------------------------
// BMI BY AGE GROUP
// -------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT r.birth_date, hr.bmi FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy1
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
");
$stmt->execute([':brgy1' => $barangay_id, ':brgy2' => $barangay_id]);
$ageGroupRawRows = $stmt->fetchAll();

$ageGroupBuckets = [];
foreach ($ageGroupRawRows as $ar) {
    $age = (int) (new DateTime($ar['birth_date']))->diff(new DateTime('today'))->y;
    $bmi = (float) $ar['bmi'];
    if ($age <= 12)
        $bucket = '0-12 yrs';
    elseif ($age <= 19)
        $bucket = '13-19 yrs';
    elseif ($age <= 24)
        $bucket = '20-24 yrs';
    elseif ($age <= 34)
        $bucket = '25-34 yrs';
    elseif ($age <= 49)
        $bucket = '35-49 yrs';
    elseif ($age <= 59)
        $bucket = '50-59 yrs';
    else
        $bucket = '60+ yrs';
    if (!isset($ageGroupBuckets[$bucket]))
        $ageGroupBuckets[$bucket] = ['count' => 0, 'bmi_sum' => 0.0, 'uw' => 0, 'normal' => 0, 'ow' => 0, 'obese' => 0];
    $ageGroupBuckets[$bucket]['count']++;
    $ageGroupBuckets[$bucket]['bmi_sum'] += $bmi;
    if ($bmi < 18.5)
        $ageGroupBuckets[$bucket]['uw']++;
    elseif ($bmi < 25.0)
        $ageGroupBuckets[$bucket]['normal']++;
    elseif ($bmi < 30.0)
        $ageGroupBuckets[$bucket]['ow']++;
    else
        $ageGroupBuckets[$bucket]['obese']++;
}
$ageGroupData = [];
foreach (['0-12 yrs', '13-19 yrs', '20-24 yrs', '25-34 yrs', '35-49 yrs', '50-59 yrs', '60+ yrs'] as $key) {
    if (isset($ageGroupBuckets[$key]) && $ageGroupBuckets[$key]['count'] > 0) {
        $b = $ageGroupBuckets[$key];
        $ageGroupData[$key] = [
            'count' => $b['count'],
            'avg_bmi' => round($b['bmi_sum'] / $b['count'], 1),
            'uw' => $b['uw'],
            'normal' => $b['normal'],
            'ow' => $b['ow'],
            'obese' => $b['obese'],
        ];
    }
}
$totalAgeGroups = count($ageGroupData);

// -------------------------------------------------------
// GENDER-BASED NUTRITION COMPARISON
// -------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT r.gender, hr.bmi
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy1
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
");
$stmt->execute([':brgy1' => $barangay_id, ':brgy2' => $barangay_id]);
$genderBmiRows = $stmt->fetchAll();

$genderStats = [
    'Male' => ['uw' => 0, 'normal' => 0, 'ow' => 0, 'obese' => 0, 'total' => 0, 'bmi_sum' => 0.0],
    'Female' => ['uw' => 0, 'normal' => 0, 'ow' => 0, 'obese' => 0, 'total' => 0, 'bmi_sum' => 0.0],
];
foreach ($genderBmiRows as $gr) {
    $g = $gr['gender'];
    $b = (float) $gr['bmi'];
    if (!isset($genderStats[$g]))
        continue;
    $genderStats[$g]['total']++;
    $genderStats[$g]['bmi_sum'] += $b;
    if ($b < 18.5)
        $genderStats[$g]['uw']++;
    elseif ($b < 25.0)
        $genderStats[$g]['normal']++;
    elseif ($b < 30.0)
        $genderStats[$g]['ow']++;
    else
        $genderStats[$g]['obese']++;
}
foreach ($genderStats as $g => &$gs) {
    $gs['avg_bmi'] = $gs['total'] > 0 ? round($gs['bmi_sum'] / $gs['total'], 1) : 0;
    $gs['at_risk'] = $gs['uw'] + $gs['ow'] + $gs['obese'];
}
unset($gs);

// -------------------------------------------------------
// MONTHLY AT-RISK COMPARISON (last 6 months)
// -------------------------------------------------------
$monthlyAtRiskData = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months", strtotime(date('Y-m-01')));
    $y = date('Y', $ts);
    $m = date('n', $ts);
    $label = date('M Y', $ts);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE barangay_id = :brgy AND YEAR(record_date)=:y AND MONTH(record_date)=:m AND (bmi < 18.5 OR bmi >= 25)");
    $stmt->execute([':brgy' => $barangay_id, ':y' => $y, ':m' => $m]);
    $monthlyAtRiskData[] = ['label' => $label, 'count' => (int) $stmt->fetchColumn()];
}

// -------------------------------------------------------
// NEW RESIDENTS REGISTERED (last 6 months)
// -------------------------------------------------------
$newResidentsByMonth = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months", strtotime(date('Y-m-01')));
    $y = date('Y', $ts);
    $m = date('n', $ts);
    $label = date('M Y', $ts);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE barangay_id = :brgy AND YEAR(created_at)=:y AND MONTH(created_at)=:m");
    $stmt->execute([':brgy' => $barangay_id, ':y' => $y, ':m' => $m]);
    $newResidentsByMonth[] = ['label' => $label, 'count' => (int) $stmt->fetchColumn()];
}
$totalNewThisMonth = $newResidentsByMonth[5]['count'] ?? 0;
$totalNewLastMonth = $newResidentsByMonth[4]['count'] ?? 0;

// -------------------------------------------------------
// UNASSESSED RESIDENTS
// -------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT r.resident_id, r.first_name, r.last_name, r.gender, r.birth_date, r.phone, r.address,
           MAX(hr.record_date) AS last_record_date,
           COALESCE(COUNT(hr.record_id), 0) AS total_records,
           latest_hr.bmi AS last_bmi,
           latest_hr.ai_status AS last_ai_status
    FROM residents r
    LEFT JOIN health_records hr ON r.resident_id = hr.resident_id
    LEFT JOIN health_records latest_hr ON latest_hr.resident_id = r.resident_id
        AND latest_hr.record_date = (SELECT MAX(record_date) FROM health_records WHERE resident_id = r.resident_id)
    WHERE r.barangay_id = :brgy
    GROUP BY r.resident_id, r.first_name, r.last_name, r.gender, r.birth_date,
             r.phone, r.address, latest_hr.bmi, latest_hr.ai_status
    HAVING MAX(hr.record_date) IS NULL OR MAX(hr.record_date) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY last_record_date ASC
");
$stmt->execute([':brgy' => $barangay_id]);
$unassessedResidents = $stmt->fetchAll();
$totalUnassessed = count($unassessedResidents);

// -------------------------------------------------------
// HELPERS
// -------------------------------------------------------
function getInitials(string $name): string
{
    $parts = explode(' ', trim($name));
    $init = '';
    foreach ($parts as $p) {
        $init .= strtoupper(substr($p, 0, 1));
    }
    return substr($init, 0, 2);
}
function calcAge(string $birthDate): int
{
    return (int) (new DateTime($birthDate))->diff(new DateTime('today'))->y;
}
function bmiLabel(float $bmi): array
{
    if ($bmi < 18.5)
        return ['Underweight', 'underweight'];
    elseif ($bmi < 25.0)
        return ['Normal', 'normal'];
    elseif ($bmi < 30.0)
        return ['Overweight', 'overweight'];
    else
        return ['Obese', 'obese'];
}
function bmiValClass(float $bmi): string
{
    if ($bmi < 18.5)
        return 'rpt-bmi-val--uw';
    elseif ($bmi < 25.0)
        return 'rpt-bmi-val--nm';
    elseif ($bmi < 30.0)
        return 'rpt-bmi-val--ow';
    else
        return 'rpt-bmi-val--ob';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reports &amp; Documentation | NutriTrack</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/reports.css">
    <link rel="stylesheet" href="residents_modal.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>
        window.CURRENT_WORKER_NAME = <?= json_encode($currentWorkerName) ?>;
        window.CURRENT_BARANGAY_NAME = <?= json_encode($barangay_name ?? 'Barangay Health Center') ?>;
    </script>
    <script src="../../assets/js/reports_pdf.js"></script>
    <script src="../../assets/js/master_report_pdf.js"></script>
    <img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
    <img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
        style="display:none;">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_health_worker.php"; ?>



    <main class="dashboard">

        <!-- PAGE HEADER -->
        <div class="reports-page-header">
            <div>
                <h1 class="page-title">Reports &amp; Documentation</h1>
                <p class="reports-page-subtitle">Browse and generate reports by category &middot; NutriTrack Health
                    System</p>
            </div>
            <div class="reports-header-actions">
                <button class="rpt-btn-secondary" onclick="generateMasterReportPDF()">📥 Export</button>
                <!-- <button class="rpt-btn-primary" onclick="openRecordModal()">+ New Record</button> -->
            </div>
        </div>

        <div class="rpt-tabs-container">

            <!-- TAB BUTTONS -->
            <div class="rpt-tabs-header">
                <button class="rpt-tab-btn <?= $activeTab === 'all' ? 'active' : '' ?>"
                    onclick="switchTab('all', this)">
                    All Reports <span class="rpt-tab-badge">6</span>
                </button>
                <button class="rpt-tab-btn <?= $activeTab === 'statistics' ? 'active' : '' ?>"
                    onclick="switchTab('statistics', this)">
                    Statistics <span class="rpt-tab-badge">6</span>
                </button>
                <button class="rpt-tab-btn <?= $activeTab === 'priority' ? 'active' : '' ?>"
                    onclick="switchTab('priority', this)">
                    Priority Cases <span class="rpt-tab-badge"><?= count($atRiskResidents) ?></span>
                </button>
                <button class="rpt-tab-btn <?= $activeTab === 'unassessed' ? 'active' : '' ?>"
                    onclick="switchTab('unassessed', this)">
                    Unassessed
                    <?php if ($totalUnassessed > 0): ?>
                        <span class="rpt-tab-badge rpt-tab-badge--alert"><?= $totalUnassessed ?></span>
                    <?php else: ?>
                        <span class="rpt-tab-badge"><?= $totalUnassessed ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <div class="rpt-tabs-content">

                <!-- ══════════════════════════════════
                 TAB 1: ALL REPORTS — 6 cards
            ═══════════════════════════════════ -->
                <div class="rpt-tab-pane <?= $activeTab === 'all' ? 'active' : '' ?>" id="tab-all">

                    <div class="rpt-filter-bar">
                        <form method="GET" class="form-contents" id="allFilterForm">
                            <input type="hidden" name="tab" value="all">
                            <select name="status" onchange="this.form.submit()">
                                <option value="">All Statuses</option>
                                <option value="Underweight" <?= $selectedStatus === 'Underweight' ? ' selected' : '' ?>>
                                    Underweight</option>
                                <option value="Normal" <?= $selectedStatus === 'Normal' ? ' selected' : '' ?>>Normal
                                </option>
                                <option value="Overweight" <?= $selectedStatus === 'Overweight' ? ' selected' : '' ?>>
                                    Overweight</option>
                                <option value="Obese" <?= $selectedStatus === 'Obese' ? ' selected' : '' ?>>Obese</option>
                            </select>
                            <input type="month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>"
                                onchange="this.form.submit()">
                        </form>
                        <div class="rpt-search-box">
                            <input type="text" id="allReportsSearch" placeholder="Search reports…"
                                value="<?= htmlspecialchars($searchQuery) ?>" oninput="filterAllReports()">
                        </div>
                    </div>

                    <div class="rpt-grid" id="allReportsGrid">

                        <div class="rpt-card" data-title="monthly nutrition report"
                            data-tags="monthly trends statistics">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-blue">📊</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Monthly Nutrition Report</div>
                                    <div class="rpt-card-meta"><?= number_format($totalResidents) ?> residents &middot;
                                        <?= number_format($totalRecords) ?> records
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Comprehensive monthly summary of nutrition status, trends, and
                                statistics across all residents for
                                <?= date('F Y', strtotime($selectedMonth . '-01')) ?>.
                            </div>
                            <div class="rpt-card-tags"><span class="rpt-tag">📅 Monthly</span><span class="rpt-tag">📈
                                    Trends</span><span class="rpt-tag">📋 Statistics</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                                <!-- <button class="rpt-btn-action rpt-btn-generate" onclick="window.print()">📄 Generate</button> -->
                            </div>
                        </div>

                        <div class="rpt-card" data-title="malnutrition trends" data-tags="historical patterns analysis">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-orange">📈</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Malnutrition Trends</div>
                                    <div class="rpt-card-meta">90-day historical</div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Historical analysis of malnutrition rates and patterns over time
                                with comparative data and visual charts.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">⏱️ Historical</span><span
                                    class="rpt-tag">📉 Patterns</span><span class="rpt-tag">🔍 Analysis</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                                <!-- <button class="rpt-btn-action rpt-btn-generate" onclick="window.print()">📄 Generate</button> -->
                            </div>
                        </div>

                        <div class="rpt-card" data-title="at-risk population list"
                            data-tags="priority action items contact info">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-red">⚠️</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">At-Risk Population List</div>
                                    <div class="rpt-card-meta"><?= count($atRiskResidents) ?> priority cases</div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Detailed list of residents requiring immediate attention with
                                contact info, BMI readings, and AI-generated recommendations.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">🚨 Priority</span><span class="rpt-tag">📋
                                    Action Items</span><span class="rpt-tag">📞 Contact Info</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('priority', document.querySelectorAll('.rpt-tab-btn')[2])">👁️
                                    Preview</button>
                                <!-- <button class="rpt-btn-action rpt-btn-generate" onclick="window.print()">📄 Generate</button> -->
                            </div>
                        </div>

                        <div class="rpt-card" data-title="gender-based nutrition comparison"
                            data-tags="gender male female bmi distribution demographics">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-purple">⚥</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Gender-Based Nutrition Comparison</div>
                                    <div class="rpt-card-meta"><?= $genderStats['Male']['total'] ?> male &middot;
                                        <?= $genderStats['Female']['total'] ?> female residents
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Compares BMI distribution, average BMI, and at-risk counts
                                between male and female residents based on latest health records.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">👥 Demographics</span><span
                                    class="rpt-tag">📊 BMI Split</span><span class="rpt-tag">🔍 Comparison</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                                <!-- <button class="rpt-btn-action rpt-btn-generate" onclick="window.print()">📄 Generate</button> -->
                            </div>
                        </div>

                        <div class="rpt-card" data-title="unassessed residents"
                            data-tags="unassessed no record pending overdue inactive">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-red">📞</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Unassessed Residents</div>
                                    <div class="rpt-card-meta"><?= $totalUnassessed ?>
                                        resident<?= $totalUnassessed !== 1 ? 's' : '' ?> overdue</div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Lists residents with no health record in the last 30 days or
                                never assessed, enabling health workers to prioritize follow-ups.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">🚨 Overdue</span><span class="rpt-tag">📋
                                    Follow-up</span><span class="rpt-tag">📞 Contact</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('unassessed', document.querySelectorAll('.rpt-tab-btn')[3])">👁️
                                    Preview</button>
                                <!-- <button class="rpt-btn-action rpt-btn-generate" onclick="window.print()">📄 Generate</button> -->
                            </div>
                        </div>

                        <div class="rpt-card" data-title="new residents registered"
                            data-tags="new residents registration monthly growth enrollment">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-blue">🆕</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">New Residents Registered</div>
                                    <div class="rpt-card-meta"><?= $totalNewThisMonth ?> new this month &middot;
                                        <?= number_format($totalResidents) ?> total
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Monthly count and overview of newly registered residents over the
                                last 6 months, showing enrollment growth trends over time.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">📅 Monthly</span><span class="rpt-tag">📈
                                    Growth</span><span class="rpt-tag">👥 Enrollment</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                                <!-- <button class="rpt-btn-action rpt-btn-generate" onclick="window.print()">📄 Generate</button> -->
                            </div>
                        </div>

                    </div>
                </div>


                <!-- ══════════════════════════════════
                 TAB 2: STATISTICS
            ═══════════════════════════════════ -->
                <div class="rpt-tab-pane <?= $activeTab === 'statistics' ? 'active' : '' ?>" id="tab-statistics">

                    <div class="rpt-filter-bar">
                        <form method="GET" class="form-contents">
                            <input type="hidden" name="tab" value="statistics">
                            <input type="month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>"
                                onchange="this.form.submit()">
                        </form>
                        <div class="rpt-search-box">
                            <input type="text" id="statSearch" placeholder="Search statistics…"
                                value="<?= htmlspecialchars($searchQuery) ?>" oninput="filterStatistics()">
                        </div>
                    </div>

                    <div class="rpt-grid" id="statCardsGrid">

                        <div class="rpt-card" data-title="monthly nutrition report"
                            data-tags="monthly trends statistics underweight normal overweight obese">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-blue">📊</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Monthly Nutrition Report</div>
                                    <div class="rpt-card-meta"><?= number_format($totalResidents) ?> residents &middot;
                                        <?= number_format($recordsThisMonth) ?> records this month
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Comprehensive summary of nutrition status and trends for
                                <?= date('F Y', strtotime($selectedMonth . '-01')) ?>.
                            </div>
                            <div class="rpt-stat-bars-wrap">
                                <?php
                                $statuses = [['Underweight', $mUnderweight, 'fill-cyan'], ['Normal', $mNormal, 'fill-green'], ['Overweight', $mOverweight, 'fill-amber'], ['Obese', $mObese, 'fill-red']];
                                foreach ($statuses as [$label, $count, $fillClass]):
                                    $pct = $mTotal > 0 ? round(($count / $mTotal) * 100) : 0;
                                    ?>
                                    <div class="rpt-stat-bar-row">
                                        <div class="rpt-stat-bar-meta"><span><?= $label ?></span><span><?= $count ?>
                                                (<?= $pct ?>%)</span></div>
                                        <div class="rpt-progress-bar">
                                            <div class="rpt-progress-fill <?= $fillClass ?>" style="width:<?= $pct ?>%;">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="rpt-card-actions">
                                <!-- <button class="rpt-btn-action rpt-btn-preview" onclick="window.print()">👁️ Preview</button> -->
                                <button class="rpt-btn-action rpt-btn-generate"
                                    onclick="generateMonthlyNutritionPDF()">📄 Generate</button>
                            </div>
                        </div>

                        <div class="rpt-card" data-title="bmi distribution all-time"
                            data-tags="bmi doughnut chart distribution underweight normal overweight obese all residents">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-purple">🧮</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">BMI Distribution (All-time)</div>
                                    <div class="rpt-card-meta"><?= number_format($totalResidents) ?> residents evaluated
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-chart-wrap"><canvas id="statBmiChart"></canvas></div>
                            <div class="rpt-card-actions">
                                <!-- <button class="rpt-btn-action rpt-btn-preview" onclick="window.print()">👁️ Preview</button> -->
                                <button class="rpt-btn-action rpt-btn-generate"
                                    onclick="generateBmiDistributionPDF()">📄 Generate</button>
                            </div>
                        </div>

                        <div class="rpt-card" data-title="age group analysis"
                            data-tags="age group demographics nutrition status distribution 0-12 13-17 18-24 25-39 40-59 60">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-amber">👥</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Age Group Analysis</div>
                                    <div class="rpt-card-meta">Nutrition status by demographics</div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Nutrition status breakdown by age groups with gender distribution
                                and risk assessment across all registered residents.</div>
                            <?php
                            $ageRows = $pdo->query("SELECT r.birth_date, r.gender, hr.bmi FROM health_records hr INNER JOIN residents r ON hr.resident_id = r.resident_id INNER JOIN (SELECT resident_id, MAX(record_id) AS latest_id FROM health_records GROUP BY resident_id) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id")->fetchAll();
                            $ageGroups = ['0-12' => 0, '13-17' => 0, '18-24' => 0, '25-39' => 0, '40-59' => 0, '60+' => 0];
                            foreach ($ageRows as $ar) {
                                $age = calcAge($ar['birth_date']);
                                if ($age <= 12)
                                    $ageGroups['0-12']++;
                                elseif ($age <= 17)
                                    $ageGroups['13-17']++;
                                elseif ($age <= 24)
                                    $ageGroups['18-24']++;
                                elseif ($age <= 39)
                                    $ageGroups['25-39']++;
                                elseif ($age <= 59)
                                    $ageGroups['40-59']++;
                                else
                                    $ageGroups['60+']++;
                            }
                            $maxAge = max(1, max($ageGroups));
                            ?>
                            <div class="rpt-age-bars-wrap">
                                <?php foreach ($ageGroups as $range => $cnt):
                                    $pct = round(($cnt / $maxAge) * 100); ?>
                                    <div class="rpt-age-bar-row">
                                        <span class="rpt-age-bar-label"><?= $range ?></span>
                                        <div class="rpt-progress-bar">
                                            <div class="rpt-progress-fill fill-blue" style="width:<?= $pct ?>%;"></div>
                                        </div>
                                        <span class="rpt-age-bar-count"><?= $cnt ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="rpt-card-actions">
                                <!-- <button class="rpt-btn-action rpt-btn-preview" onclick="window.print()">👁️ Preview</button> -->
                                <button class="rpt-btn-action rpt-btn-generate" onclick="generateAgeGroupPDF()">📄
                                    Generate</button>
                            </div>
                        </div>

                        <div class="rpt-card" data-title="gender-based nutrition comparison"
                            data-tags="gender male female bmi distribution demographics at-risk comparison chart">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-purple">⚥</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Gender-Based Nutrition Comparison</div>
                                    <div class="rpt-card-meta"><?= $genderStats['Male']['total'] ?> male &middot;
                                        <?= $genderStats['Female']['total'] ?> female
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-chart-wrap"><canvas id="genderBmiChart"></canvas></div>
                            <div class="rpt-gender-summary">
                                <?php foreach (['Male', 'Female'] as $g):
                                    $gs = $genderStats[$g];
                                    $icon = $g === 'Male' ? '♂' : '♀'; ?>
                                    <div class="rpt-gender-col">
                                        <div class="rpt-gender-label"><?= $icon ?>     <?= $g ?></div>
                                        <div class="rpt-gender-avg">Avg BMI: <strong><?= $gs['avg_bmi'] ?></strong></div>
                                        <div class="rpt-gender-atrisk">At-Risk: <strong><?= $gs['at_risk'] ?></strong> /
                                            <?= $gs['total'] ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="rpt-card-actions">
                                <!-- <button class="rpt-btn-action rpt-btn-preview" onclick="window.print()">👁️ Preview</button> -->
                                <button class="rpt-btn-action rpt-btn-generate"
                                    onclick="generateGenderComparisonPDF()">📄 Generate</button>
                            </div>
                        </div>

                        <div class="rpt-card" data-title="monthly at-risk comparison"
                            data-tags="monthly at-risk trend history 6 months line chart">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-amber">📅</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Monthly At-Risk Comparison</div>
                                    <div class="rpt-card-meta">Last 6 months</div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Month-over-month at-risk resident count trend to track overall
                                health progress.</div>
                            <div class="rpt-chart-wrap"><canvas id="atRiskTrendChart"></canvas></div>
                            <div class="rpt-card-actions">
                                <!-- <button class="rpt-btn-action rpt-btn-preview" onclick="window.print()">👁️ Preview</button> -->
                                <button class="rpt-btn-action rpt-btn-generate" onclick="generateAtRiskTrendPDF()">📄
                                    Generate</button>
                            </div>
                        </div>

                        <div class="rpt-card" data-title="new residents registered"
                            data-tags="new residents registration monthly growth enrollment bar chart 6 months">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-blue">🆕</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">New Residents Registered</div>
                                    <div class="rpt-card-meta"><?= $totalNewThisMonth ?> this month &middot;
                                        <?= number_format($totalResidents) ?> total
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Monthly enrollment count showing resident registration growth
                                over the past 6 months.</div>
                            <div class="rpt-chart-wrap"><canvas id="newResidentsChart"></canvas></div>
                            <div class="rpt-card-actions">
                                <!-- <button class="rpt-btn-action rpt-btn-preview" onclick="window.print()">👁️ Preview</button> -->
                                <button class="rpt-btn-action rpt-btn-generate" onclick="generateNewResidentsPDF()">📄
                                    Generate</button>
                            </div>
                        </div>

                    </div>

                    <!-- BMI Status Distribution -->
                    <!-- <section class="rpt-stat-section"
                        data-section="bmi status distribution underweight normal overweight obese live snapshot">
                        <div class="rpt-stat-header">
                            <h2 class="rpt-stat-title">BMI Status Distribution</h2>
                            <span class="rpt-badge-live">Live Snapshot</span>
                        </div>
                        <div class="rpt-bmi-dist-grid">
                            <div class="rpt-dist-card rpt-dist-underweight">
                                <div class="rpt-dist-label">Underweight</div>
                                <div class="rpt-dist-range">BMI &lt; 18.5</div>
                                <div class="rpt-dist-count"><?= $countUnderweight ?></div>
                            </div>
                            <div class="rpt-dist-card rpt-dist-normal">
                                <div class="rpt-dist-label">Normal</div>
                                <div class="rpt-dist-range">18.5 – 24.9</div>
                                <div class="rpt-dist-count"><?= $countNormal ?></div>
                            </div>
                            <div class="rpt-dist-card rpt-dist-overweight">
                                <div class="rpt-dist-label">Overweight</div>
                                <div class="rpt-dist-range">25.0 – 29.9</div>
                                <div class="rpt-dist-count"><?= $countOverweight ?></div>
                            </div>
                            <div class="rpt-dist-card rpt-dist-obese">
                                <div class="rpt-dist-label">Obese</div>
                                <div class="rpt-dist-range">BMI &ge; 30</div>
                                <div class="rpt-dist-count"><?= $countObese ?></div>
                            </div>
                        </div>
                    </section> -->

                    <!-- Gender Comparison Detail -->
                    <!-- <section class="rpt-stat-section" id="statGenderSection"
                        data-section="gender nutrition breakdown male female avg bmi at-risk underweight normal overweight obese">
                        <div class="rpt-stat-header">
                            <h2 class="rpt-stat-title">Gender Nutrition Breakdown</h2>
                            <span class="rpt-badge-live">All-time</span>
                        </div>
                        <div class="rpt-worker-table-wrap">
                            <table class="rpt-worker-table">
                                <thead>
                                    <tr>
                                        <th>Gender</th>
                                        <th>Total Residents</th>
                                        <th>Avg BMI</th>
                                        <th>Underweight</th>
                                        <th>Normal</th>
                                        <th>Overweight</th>
                                        <th>Obese</th>
                                        <th>At-Risk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (['Male', 'Female'] as $g):
                                        $gs = $genderStats[$g]; ?>
                                        <tr>
                                            <td class="rpt-td-bold"><?= $g ?></td>
                                            <td class="rpt-td-muted"><?= $gs['total'] ?></td>
                                            <td><span
                                                    class="rpt-bmi-val <?= $gs['avg_bmi'] > 0 ? bmiValClass($gs['avg_bmi']) : '' ?>"><?= $gs['avg_bmi'] ?></span>
                                            </td>
                                            <td>
                                                <div class="metric"><span
                                                        class="metric-dot cyan"></span><span><?= $gs['uw'] ?></span></div>
                                            </td>
                                            <td>
                                                <div class="metric"><span
                                                        class="metric-dot green"></span><span><?= $gs['normal'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="metric"><span
                                                        class="metric-dot amber"></span><span><?= $gs['ow'] ?></span></div>
                                            </td>
                                            <td>
                                                <div class="metric"><span
                                                        class="metric-dot red"></span><span><?= $gs['obese'] ?></span></div>
                                            </td>
                                            <td class="rpt-td-bold"><?= $gs['at_risk'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section> -->

                    <!-- Health Records Table -->
                    <section class="rpt-stat-section"
                        data-section="health records monthly residents weight height bmi ai status recorded by">
                        <div class="rpt-stat-header">
                            <div class="rpt-records-title-wrap">
                                <h2 class="rpt-stat-title">Health Records &middot;
                                    <?= date('F Y', strtotime($selectedMonth . '-01')) ?>
                                </h2>
                                <?php if (!empty($healthRecordsTable)): ?>
                                    <p class="rpt-stat-sub">Showing <?= min(5, count($healthRecordsTable)) ?> of
                                        <?= count($healthRecordsTable) ?> records this month
                                        &middot; <?= $totalRecordsSystem ?> total in system</p>
                                <?php endif; ?>
                            </div>
                            <div class="rpt-stat-header-actions">
                                <button onclick="generateHealthRecordsPDF()" class="btn-print"><i
                                        class="fas fa-print"></i> Print Report</button>
                                <a href="health_records_all.php?month=<?= urlencode($selectedMonth) ?>" class="rpt-btn-view">View All</a>
                            </div>
                        </div>
                        <?php if (empty($healthRecordsTable)): ?>
                            <p class="text-muted">No health records found for
                                <?= date('F Y', strtotime($selectedMonth . '-01')) ?>.
                            </p>
                        <?php else: ?>
                            <div class="rpt-worker-table-wrap">
                                <table class="rpt-worker-table" id="statRecordsTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Resident</th>
                                            <th>Age</th>
                                            <th>Gender</th>
                                            <th>Weight</th>
                                            <th>Height</th>
                                            <th>BMI</th>
                                            <th>AI Status</th>
                                            <th>Recorded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($healthRecordsPreview as $rec):
                                            $recAge = calcAge($rec['birth_date']);
                                            $recBmi = (float) $rec['bmi'];
                                            $bmValClass = bmiValClass($recBmi);
                                            $aiStatus = $rec['ai_status'] ?? '';
                                            $aiDotClass = match ($aiStatus) { 'Normal' => 'green', 'Underweight' => 'cyan', 'Overweight' => 'amber', 'Obese' => 'red', default => ''};
                                            ?>
                                            <tr>
                                                <td class="rpt-td-nowrap"><?= htmlspecialchars($rec['record_date']) ?></td>
                                                <td class="rpt-td-bold">
                                                    <?= htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']) ?>
                                                </td>
                                                <td class="rpt-td-muted"><?= $recAge ?> yrs</td>
                                                <td class="rpt-td-muted"><?= htmlspecialchars($rec['gender']) ?></td>
                                                <td class="rpt-td-muted"><?= number_format($rec['weight'], 0) ?> kg</td>
                                                <td class="rpt-td-muted"><?= number_format($rec['height'], 0) ?> cm</td>
                                                <td><span
                                                        class="rpt-bmi-val <?= $bmValClass ?>"><?= number_format($recBmi, 1) ?></span>
                                                </td>
                                                <?php
                                                $computedStatus = '';
                                                $computedDot = '';
                                                if ($recBmi > 0) {
                                                    if ($recBmi < 18.5) {
                                                        $computedStatus = 'Underweight';
                                                        $computedDot = 'cyan';
                                                    } elseif ($recBmi < 25.0) {
                                                        $computedStatus = 'Normal';
                                                        $computedDot = 'green';
                                                    } elseif ($recBmi < 30.0) {
                                                        $computedStatus = 'Overweight';
                                                        $computedDot = 'amber';
                                                    } else {
                                                        $computedStatus = 'Obese';
                                                        $computedDot = 'red';
                                                    }
                                                }
                                                ?>
                                                <td>
                                                    <?php if ($computedStatus): ?>
                                                        <span
                                                            class="res-bmi-badge res-bmi-badge-<?= strtolower($computedStatus) ?>">
                                                            <span class="res-bmi-badge-dot"></span>
                                                            <?= $computedStatus ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="rpt-td-none">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="rpt-td-muted"><?= htmlspecialchars($rec['worker_name']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- BMI by Age Group -->
                    <!-- <section class="rpt-stat-section"
                        data-section="bmi age group 0-12 13-19 20-24 25-34 35-49 50-59 60 avg bmi underweight normal overweight obese">
                        <div class="rpt-stat-header">
                            <h2 class="rpt-stat-title">BMI by Age Group</h2>
                            <div class="rpt-stat-header-actions">
                                <button onclick="generateAgeGroupPDF()" class="btn-print"><i class="fas fa-print"></i>
                                    Print Report</button>
                                <span class="rpt-badge-count"><?= $totalAgeGroups ?>
                                    group<?= $totalAgeGroups !== 1 ? 's' : '' ?></span>
                            </div>
                        </div>
                        <?php if (empty($ageGroupData)): ?>
                            <p class="text-muted">No resident records available to group by age.</p>
                        <?php else: ?>
                            <div class="rpt-worker-table-wrap">
                                <table class="rpt-worker-table" id="statAgeTable">
                                    <thead>
                                        <tr>
                                            <th>Age Group</th>
                                            <th>Residents</th>
                                            <th>Avg BMI</th>
                                            <th>Underweight</th>
                                            <th>Normal</th>
                                            <th>Overweight</th>
                                            <th>Obese</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ageGroupData as $range => $data): ?>
                                            <tr>
                                                <td class="rpt-td-age-name"><?= htmlspecialchars($range) ?></td>
                                                <td class="rpt-td-muted"><?= $data['count'] ?></td>
                                                <td><span
                                                        class="rpt-bmi-val <?= bmiValClass($data['avg_bmi']) ?>"><?= $data['avg_bmi'] ?></span>
                                                </td>
                                                <td>
                                                    <div class="metric"><span
                                                            class="metric-dot cyan"></span><span><?= $data['uw'] ?></span></div>
                                                </td>
                                                <td>
                                                    <div class="metric"><span
                                                            class="metric-dot green"></span><span><?= $data['normal'] ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="metric"><span
                                                            class="metric-dot amber"></span><span><?= $data['ow'] ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="metric"><span
                                                            class="metric-dot red"></span><span><?= $data['obese'] ?></span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section> -->

                    <!-- New Residents Registered -->
                    <section class="rpt-stat-section" id="statNewResidentsSection"
                        data-section="new residents registered monthly enrollment growth registration last 6 months">
                        <div class="rpt-stat-header">
                            <h2 class="rpt-stat-title">New Residents Registered (Last 6 Months)</h2>
                            <span class="rpt-badge-count"><?= $totalNewThisMonth ?> this month</span>
                        </div>
                        <div class="rpt-worker-table-wrap">
                            <table class="rpt-worker-table" id="statNewResidentsTable">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>New Registrations</th>
                                        <th>Visual</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $maxNew = max(1, max(array_column($newResidentsByMonth, 'count')));
                                    foreach ($newResidentsByMonth as $nr):
                                        $pct = round(($nr['count'] / $maxNew) * 100); ?>
                                        <tr>
                                            <td class="rpt-td-bold"><?= htmlspecialchars($nr['label']) ?></td>
                                            <td class="rpt-td-muted"><?= $nr['count'] ?></td>
                                            <td>
                                                <div class="rpt-progress-bar rpt-progress-bar--wide">
                                                    <div class="rpt-progress-fill fill-blue" style="width:<?= $pct ?>%">
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                </div><!-- /#tab-statistics -->


                <!-- ══════════════════════════════════
                 TAB 3: PRIORITY CASES
                 — with Read More on AI Recommendation
            ═══════════════════════════════════ -->
                <div class="rpt-tab-pane <?= $activeTab === 'priority' ? 'active' : '' ?>" id="tab-priority">

                    <div class="rpt-filter-bar">
                        <select id="priorityStatusFilter" onchange="filterPriorityTable()">
                            <option value="">All At-Risk</option>
                            <option value="Underweight">Underweight</option>
                            <option value="Overweight">Overweight</option>
                            <option value="Obese">Obese</option>
                        </select>
                        <select id="priorityGenderFilter" onchange="filterPriorityTable()">
                            <option value="">All Genders</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                        <div class="rpt-search-box">
                            <input type="text" id="prioritySearch" placeholder="Search residents…"
                                oninput="filterPriorityTable()">
                        </div>
                    </div>

                    <?php if (empty($atRiskResidents)): ?>
                        <div class="rpt-empty">
                            <div class="rpt-empty-icon">✅</div>
                            <h3>No At-Risk Residents</h3>
                            <p>All residents currently have a normal BMI. Great work!</p>
                        </div>
                    <?php else: ?>
                        <div class="rpt-worker-table-wrap">
                            <table class="rpt-worker-table" id="priorityTable">
                                <thead>
                                    <tr>
                                        <th>Resident</th>
                                        <th>Gender / Age</th>
                                        <th>BMI</th>
                                        <th>Status</th>
                                        <th>Weight / Height</th>
                                        <th>Record Date</th>
                                        <th>AI Recommendation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $loop_index = 0;
                                    foreach ($atRiskResidents as $res):
                                        $loop_index++;
                                        [$statusLabel, $statusClass] = bmiLabel((float) $res['bmi']);
                                        $age = calcAge($res['birth_date']);
                                        ?>
                                        <tr data-status="<?= htmlspecialchars($statusLabel) ?>"
                                            data-gender="<?= htmlspecialchars($res['gender']) ?>"
                                            data-name="<?= strtolower(htmlspecialchars($res['first_name'] . ' ' . $res['last_name'])) ?>">
                                            <td>
                                                <div class="rpt-worker-cell">
                                                    <div
                                                        class="rpt-worker-avatar-placeholder <?= $res['gender'] === 'Female' ? 'female' : 'male' ?>">
                                                        <?= strtoupper(substr($res['first_name'], 0, 1) . substr($res['last_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <div class="rpt-worker-name">
                                                            <?= htmlspecialchars($res['first_name'] . ' ' . $res['last_name']) ?>
                                                        </div>
                                                        <div class="rpt-worker-dept">
                                                            <?= htmlspecialchars($res['address'] ?? '—') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="rpt-td-muted"><?= htmlspecialchars($res['gender']) ?>, <?= $age ?> yrs
                                            </td>
                                            <td><span
                                                    class="rpt-bmi-val rpt-td-bold-xl <?= bmiValClass((float) $res['bmi']) ?>"><?= number_format($res['bmi'], 1) ?></span>
                                            </td>
                                            <td><span class="rpt-bmi-pill <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                            <td class="rpt-td-muted"><?= number_format($res['weight'], 1) ?> kg /
                                                <?= number_format($res['height'], 1) ?> cm
                                            </td>
                                            <td class="rpt-td-muted-sm"><?= date('M j, Y', strtotime($res['record_date'])) ?>
                                            </td>
                                            <td class="rpt-td-recommend">
                                                <?php if (!empty($res['ai_recommendation'])): ?>
                                                    <div class="ai-rec-text ai-rec-collapsed" id="rec-<?= $loop_index ?>">
                                                        <?= htmlspecialchars($res['ai_recommendation']) ?>
                                                    </div>
                                                    <button class="ai-read-more"
                                                        onclick="aiToggleRec('rec-<?= $loop_index ?>', this)">
                                                        Read more ▾
                                                    </button>
                                                <?php else: ?>
                                                    <em>No recommendation yet</em>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                </div><!-- /#tab-priority -->


                <!-- ══════════════════════════════════
                 TAB 4: UNASSESSED RESIDENTS
            ═══════════════════════════════════ -->
                <div class="rpt-tab-pane <?= $activeTab === 'unassessed' ? 'active' : '' ?>" id="tab-unassessed">

                    <div class="rpt-filter-bar">
                        <select id="unassessedGenderFilter" onchange="filterUnassessedTable()">
                            <option value="">All Genders</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                        <select id="unassessedTypeFilter" onchange="filterUnassessedTable()">
                            <option value="">All Types</option>
                            <option value="never">Never Assessed</option>
                            <option value="overdue">Overdue (30+ days)</option>
                        </select>
                        <div class="rpt-search-box">
                            <input type="text" id="unassessedSearch" placeholder="Search residents…"
                                oninput="filterUnassessedTable()">
                        </div>
                    </div>

                    <?php if (empty($unassessedResidents)): ?>
                        <div class="rpt-empty">
                            <div class="rpt-empty-icon">✅</div>
                            <h3>All Residents Assessed</h3>
                            <p>Every resident has a health record within the last 30 days. Great work!</p>
                        </div>
                    <?php else: ?>
                        <?php
                        $cntNeverAssessed = 0;
                        $cntOverdue = 0;
                        foreach ($unassessedResidents as $ur) {
                            if ($ur['last_record_date'] === null)
                                $cntNeverAssessed++;
                            else
                                $cntOverdue++;
                        }
                        ?>
                        <div class="rpt-activity-kpis">
                            <div class="rpt-kpi-card"><span class="rpt-kpi-label">Total Unassessed</span><span
                                    class="rpt-kpi-value rpt-kpi-value--worsened"><?= $totalUnassessed ?></span><span
                                    class="rpt-kpi-sub">Require follow-up</span></div>
                            <div class="rpt-kpi-card"><span class="rpt-kpi-label">Never Assessed</span><span
                                    class="rpt-kpi-value"><?= $cntNeverAssessed ?></span><span class="rpt-kpi-sub">No record
                                    at all</span></div>
                            <div class="rpt-kpi-card"><span class="rpt-kpi-label">Overdue (30+ days)</span><span
                                    class="rpt-kpi-value"><?= $cntOverdue ?></span><span class="rpt-kpi-sub">Last record
                                    &gt; 30 days ago</span></div>
                            <div class="rpt-kpi-card"><span class="rpt-kpi-label">Total Residents</span><span
                                    class="rpt-kpi-value"><?= number_format($totalResidents) ?></span><span
                                    class="rpt-kpi-sub">Registered in system</span></div>
                        </div>

                        <div class="rpt-worker-table-wrap">
                            <table class="rpt-worker-table" id="unassessedTable">
                                <thead>
                                    <tr>
                                        <th>Resident</th>
                                        <th>Gender / Age</th>
                                        <th>Phone</th>
                                        <th>Last Record</th>
                                        <th>Days Since</th>
                                        <th>Type</th>
                                        <th>Last BMI</th>
                                        <th>Last AI Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unassessedResidents as $ur):
                                        $age = calcAge($ur['birth_date']);
                                        $isNever = $ur['last_record_date'] === null;
                                        $daysSince = $isNever ? null : (int) (new DateTime($ur['last_record_date']))->diff(new DateTime('today'))->days;
                                        $lastBmi = $ur['last_bmi'] ? (float) $ur['last_bmi'] : null;
                                        $lastAiStatus = $ur['last_ai_status'] ?? '';
                                        $typeLabel = $isNever ? 'Never Assessed' : 'Overdue';
                                        $typeClass = $isNever ? 'rpt-bmi-pill obese' : 'rpt-bmi-pill overweight';
                                        $typeAttr = $isNever ? 'never' : 'overdue';
                                        $aiDot = match ($lastAiStatus) { 'Normal' => 'green', 'Underweight' => 'cyan', 'Overweight' => 'amber', 'Obese' => 'red', default => ''};
                                        ?>
                                        <tr data-gender="<?= htmlspecialchars($ur['gender']) ?>" data-type="<?= $typeAttr ?>"
                                            data-name="<?= strtolower(htmlspecialchars($ur['first_name'] . ' ' . $ur['last_name'])) ?>">
                                            <td>
                                                <div class="rpt-worker-cell">
                                                    <div
                                                        class="rpt-worker-avatar-placeholder <?= $ur['gender'] === 'Female' ? 'female' : 'male' ?>">
                                                        <?= strtoupper(substr($ur['first_name'], 0, 1) . substr($ur['last_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <div class="rpt-worker-name">
                                                            <?= htmlspecialchars($ur['first_name'] . ' ' . $ur['last_name']) ?>
                                                        </div>
                                                        <div class="rpt-worker-dept">
                                                            <?= htmlspecialchars($ur['address'] ?? '—') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="rpt-td-muted"><?= htmlspecialchars($ur['gender']) ?>, <?= $age ?> yrs
                                            </td>
                                            <td class="rpt-td-muted"><?= htmlspecialchars($ur['phone'] ?? '—') ?></td>
                                            <td class="rpt-td-muted-sm">
                                                <?= $isNever ? '<em>No records</em>' : date('M j, Y', strtotime($ur['last_record_date'])) ?>
                                            </td>
                                            <td class="rpt-td-bold"><?= $isNever ? '—' : $daysSince . ' days' ?></td>
                                            <td><span class="<?= $typeClass ?>"><?= $typeLabel ?></span></td>
                                            <td><?php if ($lastBmi): ?><span
                                                        class="rpt-bmi-val <?= bmiValClass($lastBmi) ?>"><?= number_format($lastBmi, 1) ?></span><?php else: ?><span
                                                        class="rpt-td-none">—</span><?php endif; ?></td>
                                            <td><?php if ($lastAiStatus): ?>
                                                    <div class="metric"><span
                                                            class="metric-dot <?= $aiDot ?>"></span><span><?= htmlspecialchars($lastAiStatus) ?></span>
                                                    </div><?php else: ?><span class="rpt-td-none">—</span><?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                </div><!-- /#tab-unassessed -->

            </div><!-- /.rpt-tabs-content -->
        </div><!-- /.rpt-tabs-container -->

    </main>


    <!-- ══════════════════════════════════
     ADD HEALTH RECORD MODAL
     — with searchable resident dropdown
═══════════════════════════════════ -->
    <div id="recordModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header" id="recordModalHeader">
                <div class="modal-header-left">
                    <div class="modal-header-icon">🩺</div>
                    <div class="modal-header-text">
                        <h2>Add Health Record</h2>
                        <p>Fill in the resident's latest health information</p>
                    </div>
                </div>
                <span class="modal-close" onclick="closeRecordModal()">&times;</span>
            </div>
            <div class="modal-progress-bar" id="recordModalProgressBar">
                <div class="modal-progress-fill"></div>
            </div>

            <div class="modal-form-content" id="recordFormContent">
                <form method="POST" class="form-default modal-form" id="addRecordForm">
                    <input type="hidden" name="modal_add_record" value="1">

                    <div class="modal-section-label">Resident Info</div>
                    <div class="modal-form-grid modal-form-grid--single">
                        <div class="form-group">
                            <label>Select Resident <span class="text-error">*</span></label>
                            <!-- Hidden field carries the actual resident_id on submit -->
                            <input type="hidden" name="resident_id" id="modal_resident_id_val">
                            <!-- Searchable dropdown (same pattern as health records page) -->
                            <div class="rs-wrap" id="rs-modal">
                                <input type="text" class="input-default rs-input" id="rs-modal-input"
                                    placeholder="Select resident" autocomplete="off" readonly>
                                <div class="rs-dropdown" id="rs-modal-dropdown"></div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-section-label">Health Details</div>
                    <div class="modal-form-grid modal-form-grid--single">
                        <div class="form-group">
                            <label>Record Date <span class="text-error">*</span></label>
                            <input type="date" name="record_date" class="input-default" value="<?= date('Y-m-d') ?>"
                                required>
                        </div>
                    </div>
                    <div class="modal-form-grid">
                        <div class="form-group">
                            <label>Weight (kg) <span class="text-error">*</span></label>
                            <input type="number" name="weight" id="rec_weight" class="input-default" step="0.01" min="0"
                                placeholder="e.g. 55.5" required oninput="calcReportsBmi()">
                        </div>
                        <div class="form-group">
                            <label>Height (cm) <span class="text-error">*</span></label>
                            <input type="number" name="height" id="rec_height" class="input-default" step="0.01" min="0"
                                placeholder="e.g. 165.0" required oninput="calcReportsBmi()">
                        </div>
                    </div>
                    <div class="modal-form-grid modal-form-grid--single">
                        <div class="form-group">
                            <label>BMI (Auto-calculated)</label>
                            <input type="text" name="add_bmi" id="add_bmi" class="input-default" readonly>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <span class="modal-required-note">* Required fields</span>
                        <div class="modal-actions-buttons">
                            <button type="button" class="btn-cancel" onclick="closeRecordModal()">Cancel</button>
                            <button type="submit" class="btn-submit-resident">Save Record &rarr;</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-state" id="recordSuccessState">
                <div class="s5-ring-wrap">
                    <div class="s5-ring success"></div>
                    <div class="s5-ring-2 success"></div>
                    <div class="s5-state-icon success">
                        <svg viewBox="0 0 24 24">
                            <polyline class="s5-check-path" points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                </div>
                <div class="s5-state-title success">Record Added!</div>
                <div class="s5-state-message">The health record has been successfully saved to the system.</div>
            </div>

            <div class="modal-state" id="recordErrorState">
                <div class="s5-ring-wrap">
                    <div class="s5-ring error"></div>
                    <div class="s5-state-icon error">
                        <svg viewBox="0 0 24 24">
                            <line class="s5-x-path" x1="18" y1="6" x2="6" y2="18"></line>
                            <line class="s5-x-path" x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </div>
                </div>
                <div class="s5-state-title error">Sorry :(</div>
                <div class="s5-state-message">Something went wrong. Please try again.</div>
                <button class="s5-state-btn error-btn" onclick="resetRecordModal()">Try Again</button>
            </div>
        </div>
    </div>


    <?php include "../../includes/footer.php"; ?>

    <script>
        // ─────────────────────────────────────────────────────
        // READ MORE TOGGLE
        // ─────────────────────────────────────────────────────
        function aiToggleRec(id, btn) {
            const el = document.getElementById(id);
            const expanded = el.classList.toggle('ai-rec-expanded');
            el.classList.toggle('ai-rec-collapsed', !expanded);
            btn.textContent = expanded ? 'Read less ▴' : 'Read more ▾';
        }

        // ─────────────────────────────────────────────────────
        // BMI AUTO-CALCULATE
        // ─────────────────────────────────────────────────────
        function calcReportsBmi() {
            const weight = parseFloat(document.getElementById('rec_weight')?.value);
            const height = parseFloat(document.getElementById('rec_height')?.value);
            const bmiField = document.getElementById('add_bmi');
            if (bmiField && weight > 0 && height > 0) {
                const heightM = height / 100;
                bmiField.value = (weight / (heightM * heightM)).toFixed(2);
            } else if (bmiField) {
                bmiField.value = '';
            }
        }

        // ─────────────────────────────────────────────────────
        // TAB SWITCHER
        // ─────────────────────────────────────────────────────
        function switchTab(tabId, btnEl) {
            document.querySelectorAll('.rpt-tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.rpt-tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            btnEl.classList.add('active');
            if (tabId === 'statistics') drawStatCharts();
        }

        // ─────────────────────────────────────────────────────
        // SHARED HELPER
        // ─────────────────────────────────────────────────────
        function _matches(query, ...sources) {
            if (!query) return true;
            return sources.some(s => (s || '').toLowerCase().includes(query));
        }

        // ─────────────────────────────────────────────────────
        // SEARCH — ALL REPORTS TAB
        // ─────────────────────────────────────────────────────
        function filterAllReports() {
            const query = (document.getElementById('allReportsSearch')?.value || '').toLowerCase().trim();
            const grid = document.getElementById('allReportsGrid');
            let visible = 0;
            grid.querySelectorAll('.rpt-card').forEach(card => {
                const match = _matches(query, card.dataset.title, card.dataset.tags,
                    card.querySelector('.rpt-card-desc')?.textContent,
                    card.querySelector('.rpt-card-title')?.textContent,
                    card.querySelector('.rpt-card-meta')?.textContent,
                    card.querySelector('.rpt-card-tags')?.textContent);
                card.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            let empty = grid.querySelector('.rpt-search-empty');
            if (visible === 0 && query) {
                if (!empty) {
                    empty = document.createElement('div');
                    empty.className = 'rpt-empty rpt-search-empty';
                    empty.innerHTML = `<div class="rpt-empty-icon">🔍</div><h3>No reports found</h3><p>No reports match "<strong>${query}</strong>". Try a different keyword.</p>`;
                    grid.appendChild(empty);
                }
            } else if (empty) { empty.remove(); }
            const badge = document.querySelector('.rpt-tab-btn[onclick*="switchTab(\'all\'"] .rpt-tab-badge');
            if (badge) badge.textContent = visible || 6;
        }

        // ─────────────────────────────────────────────────────
        // SEARCH — STATISTICS TAB
        // ─────────────────────────────────────────────────────
        function filterStatistics() {
            const query = (document.getElementById('statSearch')?.value || '').toLowerCase().trim();
            const statGrid = document.getElementById('statCardsGrid');
            let cardMatches = 0;
            if (statGrid) {
                statGrid.querySelectorAll('.rpt-card').forEach(card => {
                    const match = _matches(query, card.dataset.title, card.dataset.tags,
                        card.querySelector('.rpt-card-title')?.textContent,
                        card.querySelector('.rpt-card-meta')?.textContent,
                        card.querySelector('.rpt-card-desc')?.textContent,
                        card.querySelector('.rpt-gender-summary')?.textContent);
                    card.style.display = match ? '' : 'none';
                    if (match) cardMatches++;
                });
                statGrid.style.display = cardMatches === 0 && query ? 'none' : '';
            }
            document.querySelectorAll('#tab-statistics .rpt-stat-section').forEach(section => {
                const sectionKeywords = (section.dataset.section || '').toLowerCase();
                const sectionTitle = (section.querySelector('.rpt-stat-title')?.textContent || '').toLowerCase();
                let rowMatches = 0;
                section.querySelectorAll('tbody tr').forEach(row => {
                    const match = _matches(query, row.textContent);
                    row.style.display = match ? '' : 'none';
                    if (match) rowMatches++;
                });
                const hasTable = section.querySelector('tbody') !== null;
                const titleMatch = _matches(query, sectionKeywords, sectionTitle);
                const show = !query || titleMatch || (hasTable && rowMatches > 0);
                section.style.display = show ? '' : 'none';
                if (!query) section.querySelectorAll('tbody tr').forEach(r => r.style.display = '');
            });
            const badge = document.querySelector('.rpt-tab-btn[onclick*="switchTab(\'statistics\'"] .rpt-tab-badge');
            if (badge) badge.textContent = query ? cardMatches : 6;
        }

        // ─────────────────────────────────────────────────────
        // PRIORITY TABLE FILTER
        // ─────────────────────────────────────────────────────
        function filterPriorityTable() {
            const status = (document.getElementById('priorityStatusFilter')?.value || '').toLowerCase();
            const gender = (document.getElementById('priorityGenderFilter')?.value || '').toLowerCase();
            const query = (document.getElementById('prioritySearch')?.value || '').toLowerCase().trim();
            let visible = 0;
            document.querySelectorAll('#priorityTable tbody tr').forEach(row => {
                const matchS = !status || (row.dataset.status || '').toLowerCase() === status;
                const matchG = !gender || (row.dataset.gender || '').toLowerCase() === gender;
                const matchQ = _matches(query, row.dataset.name,
                    row.querySelector('.rpt-worker-name')?.textContent,
                    row.querySelector('.rpt-worker-dept')?.textContent,
                    row.querySelector('.rpt-td-muted')?.textContent,
                    row.querySelector('.rpt-td-recommend')?.textContent,
                    row.querySelector('.rpt-bmi-pill')?.textContent);
                const show = matchS && matchG && matchQ;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const badge = document.querySelector('.rpt-tab-btn[onclick*="switchTab(\'priority\'"] .rpt-tab-badge');
            if (badge) badge.textContent = visible || document.querySelectorAll('#priorityTable tbody tr').length;
        }

        // ─────────────────────────────────────────────────────
        // UNASSESSED TABLE FILTER
        // ─────────────────────────────────────────────────────
        function filterUnassessedTable() {
            const gender = (document.getElementById('unassessedGenderFilter')?.value || '').toLowerCase();
            const type = (document.getElementById('unassessedTypeFilter')?.value || '').toLowerCase();
            const query = (document.getElementById('unassessedSearch')?.value || '').toLowerCase().trim();
            let visible = 0;
            document.querySelectorAll('#unassessedTable tbody tr').forEach(row => {
                const matchG = !gender || (row.dataset.gender || '').toLowerCase() === gender;
                const matchT = !type || (row.dataset.type || '').toLowerCase() === type;
                const matchQ = _matches(query, row.dataset.name,
                    row.querySelector('.rpt-worker-name')?.textContent,
                    row.querySelector('.rpt-td-muted')?.textContent,
                    row.querySelector('.rpt-bmi-pill')?.textContent);
                const show = matchG && matchT && matchQ;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const badge = document.querySelector('.rpt-tab-btn[onclick*="switchTab(\'unassessed\'"] .rpt-tab-badge');
            if (badge) badge.textContent = visible || document.querySelectorAll('#unassessedTable tbody tr').length;
        }

        // ─────────────────────────────────────────────────────
        // STATISTICS CHARTS
        // ─────────────────────────────────────────────────────
        let statBmiChartInstance = null;
        let genderBmiChartInstance = null;
        let atRiskTrendInstance = null;
        let newResidentsInstance = null;

        function drawStatCharts() {
            const ctx1 = document.getElementById('statBmiChart');
            if (ctx1) {
                if (statBmiChartInstance) { statBmiChartInstance.destroy(); statBmiChartInstance = null; }
                statBmiChartInstance = new Chart(ctx1, {
                    type: 'doughnut',
                    data: { labels: ['Underweight', 'Normal', 'Overweight', 'Obese'], datasets: [{ data: [<?= $countUnderweight ?>, <?= $countNormal ?>, <?= $countOverweight ?>, <?= $countObese ?>], backgroundColor: ['#0891b2', '#16a34a', '#d97706', '#dc2626'], borderWidth: 0, hoverOffset: 6 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 12 }, padding: 14 } } }, cutout: '62%' }
                });
            }
            const ctx2 = document.getElementById('genderBmiChart');
            if (ctx2) {
                if (genderBmiChartInstance) { genderBmiChartInstance.destroy(); genderBmiChartInstance = null; }
                genderBmiChartInstance = new Chart(ctx2, {
                    type: 'bar',
                    data: {
                        labels: ['Underweight', 'Normal', 'Overweight', 'Obese'], datasets: [
                            { label: '♂ Male', data: [<?= $genderStats['Male']['uw'] ?>, <?= $genderStats['Male']['normal'] ?>, <?= $genderStats['Male']['ow'] ?>, <?= $genderStats['Male']['obese'] ?>], backgroundColor: 'rgba(52,152,219,0.8)', borderRadius: 4 },
                            { label: '♀ Female', data: [<?= $genderStats['Female']['uw'] ?>, <?= $genderStats['Female']['normal'] ?>, <?= $genderStats['Female']['ow'] ?>, <?= $genderStats['Female']['obese'] ?>], backgroundColor: 'rgba(231,76,112,0.8)', borderRadius: 4 }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            }
            const ctx3 = document.getElementById('atRiskTrendChart');
            if (ctx3) {
                if (atRiskTrendInstance) { atRiskTrendInstance.destroy(); atRiskTrendInstance = null; }
                atRiskTrendInstance = new Chart(ctx3, {
                    type: 'line',
                    data: { labels: [<?= implode(',', array_map(fn($r) => '"' . $r['label'] . '"', $monthlyAtRiskData)) ?>], datasets: [{ label: 'At-Risk Residents', data: [<?= implode(',', array_column($monthlyAtRiskData, 'count')) ?>], borderColor: '#e74c3c', backgroundColor: 'rgba(231,76,60,0.1)', borderWidth: 2, pointBackgroundColor: '#e74c3c', pointRadius: 5, fill: true, tension: 0.3 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            }
            const ctx4 = document.getElementById('newResidentsChart');
            if (ctx4) {
                if (newResidentsInstance) { newResidentsInstance.destroy(); newResidentsInstance = null; }
                newResidentsInstance = new Chart(ctx4, {
                    type: 'bar',
                    data: { labels: [<?= implode(',', array_map(fn($r) => '"' . $r['label'] . '"', $newResidentsByMonth)) ?>], datasets: [{ label: 'New Residents', data: [<?= implode(',', array_column($newResidentsByMonth, 'count')) ?>], backgroundColor: 'rgba(52,152,219,0.8)', borderRadius: 4 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (document.getElementById('tab-statistics')?.classList.contains('active')) drawStatCharts();
        });

        // ─────────────────────────────────────────────────────
        // MODAL UTILITIES
        // ─────────────────────────────────────────────────────
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        function autoClose(cb) { setTimeout(() => { cb(); }, 2100); }

        function showState(formContentId, stateId, headerId, progressBarId) {
            const content = document.getElementById(formContentId);
            const state = document.getElementById(stateId);
            const header = headerId ? document.getElementById(headerId) : null;
            const bar = progressBarId ? document.getElementById(progressBarId) : null;
            if (header) header.style.display = 'none';
            if (bar) bar.style.display = 'none';
            if (content) { content.classList.add('fade-out'); setTimeout(() => { content.style.display = 'none'; state.classList.add('active'); }, 220); }
            else { state.classList.add('active'); }
        }

        function resetState(formContentId, stateId, headerId, progressBarId) {
            const content = document.getElementById(formContentId);
            const state = document.getElementById(stateId);
            const header = headerId ? document.getElementById(headerId) : null;
            const bar = progressBarId ? document.getElementById(progressBarId) : null;
            state.classList.remove('active');
            if (content) { content.style.display = ''; content.classList.remove('fade-out'); }
            if (header) header.style.display = '';
            if (bar) bar.style.display = '';
        }

        function openRecordModal() { resetRecordModal(); openModal('recordModal'); }
        function closeRecordModal() { closeModal('recordModal'); setTimeout(resetRecordModal, 300); }
        function resetRecordModal() {
            resetState('recordFormContent', 'recordSuccessState', 'recordModalHeader', 'recordModalProgressBar');
            resetState('recordFormContent', 'recordErrorState', 'recordModalHeader', 'recordModalProgressBar');
            document.getElementById('addRecordForm').reset();
            if (rsModal) rsModal.clear();
        }

        <?php if ($add_success): ?>
            window.addEventListener('DOMContentLoaded', () => {
                openModal('recordModal');
                showState('recordFormContent', 'recordSuccessState', 'recordModalHeader', 'recordModalProgressBar');
                autoClose(closeRecordModal);
            });
        <?php elseif (!empty($add_error)): ?>
            window.addEventListener('DOMContentLoaded', () => {
                openModal('recordModal');
                showState('recordFormContent', 'recordErrorState', 'recordModalHeader', 'recordModalProgressBar');
            });
        <?php endif; ?>

        // ─────────────────────────────────────────────────────
        // SEARCHABLE RESIDENT DROPDOWN
        // (exact same logic as health records page)
        // ─────────────────────────────────────────────────────
        const RS_RESIDENTS = [
            <?php foreach ($allResidents as $r): ?>
                { id: <?= (int) $r['resident_id'] ?>, name: <?= json_encode($r['first_name'] . ' ' . $r['last_name']) ?> },
            <?php endforeach; ?>
        ];

        const MAX_DEFAULT = 10;

        function makeResidentSelect(wrapId, inputId, dropdownId, hiddenId) {
            const wrap = document.getElementById(wrapId);
            const input = document.getElementById(inputId);
            const dropdown = document.getElementById(dropdownId);
            const hidden = document.getElementById(hiddenId);
            if (!wrap || !input || !dropdown || !hidden) return null;

            let isOpen = false;
            let highlighted = -1;

            function render(query) {
                dropdown.innerHTML = '';
                highlighted = -1;
                const q = (query || '').toLowerCase().trim();
                const matched = q
                    ? RS_RESIDENTS.filter(r => r.name.toLowerCase().includes(q))
                    : RS_RESIDENTS.slice(0, MAX_DEFAULT);

                const hint = document.createElement('div');
                hint.className = 'rs-hint';
                if (!matched.length) {
                    hint.textContent = q ? 'No residents match "' + query + '"' : 'No residents found.';
                    dropdown.appendChild(hint);
                    return;
                }
                hint.textContent = q
                    ? matched.length + ' result' + (matched.length !== 1 ? 's' : '') + ' for "' + query + '"'
                    : 'Showing ' + matched.length + ' of ' + RS_RESIDENTS.length + ' — type to search';
                dropdown.appendChild(hint);

                matched.forEach((r, i) => {
                    const div = document.createElement('div');
                    div.className = 'rs-option';
                    div.dataset.id = r.id;
                    div.dataset.name = r.name;
                    div.dataset.index = i;
                    if (q) {
                        const idx = r.name.toLowerCase().indexOf(q);
                        div.innerHTML = r.name.slice(0, idx)
                            + '<mark>' + r.name.slice(idx, idx + q.length) + '</mark>'
                            + r.name.slice(idx + q.length);
                    } else {
                        div.textContent = r.name;
                    }
                    div.addEventListener('mousedown', (e) => { e.preventDefault(); selectResident(r.id, r.name); });
                    dropdown.appendChild(div);
                });
            }

            function selectResident(id, name) { hidden.value = id; input.value = name; closeDropdown(); }

            function openDropdown() {
                if (isOpen) return;
                isOpen = true;
                input.classList.add('rs-open');
                input.readOnly = false;
                dropdown.classList.add('rs-open');
                render(input.value === input.dataset.selected ? '' : input.value);
            }

            function closeDropdown() {
                if (!isOpen) return;
                isOpen = false;
                input.classList.remove('rs-open');
                input.readOnly = true;
                dropdown.classList.remove('rs-open');
                highlighted = -1;
                if (!hidden.value) input.value = '';
            }

            function moveHighlight(dir) {
                const opts = dropdown.querySelectorAll('.rs-option');
                if (!opts.length) return;
                opts[highlighted]?.classList.remove('rs-highlighted');
                highlighted = Math.max(0, Math.min(opts.length - 1, highlighted + dir));
                opts[highlighted].classList.add('rs-highlighted');
                opts[highlighted].scrollIntoView({ block: 'nearest' });
            }

            input.addEventListener('click', () => { if (!isOpen) openDropdown(); });
            input.addEventListener('input', () => { if (!isOpen) openDropdown(); render(input.value); });
            input.addEventListener('keydown', (e) => {
                if (!isOpen && (e.key === 'ArrowDown' || e.key === 'Enter')) { openDropdown(); return; }
                if (e.key === 'ArrowDown') { e.preventDefault(); moveHighlight(1); return; }
                if (e.key === 'ArrowUp') { e.preventDefault(); moveHighlight(-1); return; }
                if (e.key === 'Escape') { closeDropdown(); return; }
                if (e.key === 'Tab') { closeDropdown(); return; }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const opts = dropdown.querySelectorAll('.rs-option');
                    if (highlighted >= 0 && opts[highlighted]) {
                        selectResident(opts[highlighted].dataset.id, opts[highlighted].dataset.name);
                    }
                }
            });
            document.addEventListener('mousedown', (e) => { if (!wrap.contains(e.target)) closeDropdown(); });

            return {
                setValue(id, name) { hidden.value = id; input.value = name; input.dataset.selected = name; },
                clear() { hidden.value = ''; input.value = ''; input.dataset.selected = ''; closeDropdown(); }
            };
        }

        let rsModal = null;
        document.addEventListener('DOMContentLoaded', () => {
            rsModal = makeResidentSelect('rs-modal', 'rs-modal-input', 'rs-modal-dropdown', 'modal_resident_id_val');
        });
    </script>

</body>

</html>