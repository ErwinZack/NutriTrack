<?php
// pages/municipal/reports_municipal.php
session_start();

// -------------------------------------------------------
// AUTH & DATABASE
// -------------------------------------------------------
require_once "../../includes/auth_municipal.php";
require_once "../../config/config.php";
require_once "../../includes/functions.php";

// -------------------------------------------------------
// FILTERS (shared across tabs)
// -------------------------------------------------------
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selectedStatus = isset($_GET['status']) ? $_GET['status'] : '';
$selectedBrgy = isset($_GET['barangay_id']) ? (int) $_GET['barangay_id'] : 0;
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

$filterYear = date('Y', strtotime($selectedMonth . '-01'));
$filterMonth = date('n', strtotime($selectedMonth . '-01'));

// -------------------------------------------------------
// BARANGAY LIST (for filter dropdowns across tabs)
// -------------------------------------------------------
$allBarangays = $pdo->query("
    SELECT barangay_id, barangay_name
    FROM barangays
    WHERE status = 'active'
    ORDER BY barangay_name ASC
")->fetchAll();
$totalBarangays = count($allBarangays);

// -------------------------------------------------------
// TOWN-WIDE SUMMARY METRICS
// -------------------------------------------------------
$totalResidents = (int) $pdo->query("SELECT COUNT(*) FROM residents")->fetchColumn();
$totalRecords = (int) $pdo->query("SELECT COUNT(*) FROM health_records")->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE YEAR(record_date)=:y AND MONTH(record_date)=:m");
$stmt->execute([':y' => $filterYear, ':m' => $filterMonth]);
$recordsThisMonth = (int) $stmt->fetchColumn();

// -------------------------------------------------------
// LATEST BMI PER RESIDENT — town-wide base dataset
// (reused by Priority Cases, Gender Comparison, Per-Barangay Comparison)
// -------------------------------------------------------
$latestBmiRows = $pdo->query("
    SELECT r.resident_id, r.first_name, r.last_name, r.gender, r.birth_date, r.address, r.phone,
           r.barangay_id, b.barangay_name,
           hr.record_date, hr.weight, hr.height, hr.bmi, hr.ai_status, hr.ai_recommendation
    FROM health_records hr
    INNER JOIN residents r  ON hr.resident_id = r.resident_id
    LEFT JOIN barangays b   ON r.barangay_id = b.barangay_id
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
")->fetchAll();

$countUnderweight = $countNormal = $countOverweight = $countObese = 0;
foreach ($latestBmiRows as $row) {
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


// -------------------------------------------------------
// GENDER-BASED NUTRITION COMPARISON (#5 — town-wide)
// -------------------------------------------------------
$genderStats = [
    'Male' => ['uw' => 0, 'normal' => 0, 'ow' => 0, 'obese' => 0, 'total' => 0, 'bmi_sum' => 0.0],
    'Female' => ['uw' => 0, 'normal' => 0, 'ow' => 0, 'obese' => 0, 'total' => 0, 'bmi_sum' => 0.0],
];
foreach ($latestBmiRows as $gr) {
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
// NEW RESIDENTS REGISTERED (#6 — last 6 months, town-wide)
// -------------------------------------------------------
$newResidentsByMonth = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months", strtotime(date('Y-m-01')));
    $y = date('Y', $ts);
    $m = date('n', $ts);
    $label = date('M Y', $ts);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE YEAR(created_at)=:y AND MONTH(created_at)=:m");
    $stmt->execute([':y' => $y, ':m' => $m]);
    $newResidentsByMonth[] = ['label' => $label, 'count' => (int) $stmt->fetchColumn()];
}
$totalNewThisMonth = $newResidentsByMonth[5]['count'] ?? 0;

// -------------------------------------------------------
// MONTHLY AT-RISK TREND (#7 — last 6 months, town-wide)
// -------------------------------------------------------
$monthlyAtRiskData = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months", strtotime(date('Y-m-01')));
    $y = date('Y', $ts);
    $m = date('n', $ts);
    $label = date('M Y', $ts);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE YEAR(record_date)=:y AND MONTH(record_date)=:m AND (bmi < 18.5 OR bmi >= 25)");
    $stmt->execute([':y' => $y, ':m' => $m]);
    $monthlyAtRiskData[] = ['label' => $label, 'count' => (int) $stmt->fetchColumn()];
}

// -------------------------------------------------------
// PER-BARANGAY COMPARISON (#2 — residents / records / at-risk % / BHW ratio)
// -------------------------------------------------------
$barangayComparison = [];
foreach ($allBarangays as $brgy) {
    $bId = (int) $brgy['barangay_id'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE barangay_id = :bid");
    $stmt->execute([':bid' => $bId]);
    $bResidents = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE barangay_id = :bid");
    $stmt->execute([':bid' => $bId]);
    $bRecords = (int) $stmt->fetchColumn();

    $bAtRisk = 0;
    $bAssessed = 0;
    foreach ($latestBmiRows as $row) {
        if ((int) $row['barangay_id'] !== $bId)
            continue;
        $bAssessed++;
        if ((float) $row['bmi'] < 18.5 || (float) $row['bmi'] >= 25)
            $bAtRisk++;
    }
    $bAtRiskPct = $bAssessed > 0 ? round(($bAtRisk / $bAssessed) * 100, 1) : 0.0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_workers WHERE barangay_id = :bid AND status = 'Active'");
    $stmt->execute([':bid' => $bId]);
    $bActiveBHWs = (int) $stmt->fetchColumn();

    $barangayComparison[] = [
        'barangay_id' => $bId,
        'barangay_name' => $brgy['barangay_name'],
        'residents' => $bResidents,
        'records' => $bRecords,
        'assessed' => $bAssessed,
        'at_risk' => $bAtRisk,
        'at_risk_pct' => $bAtRiskPct,
        'active_bhws' => $bActiveBHWs,
        'ratio' => $bActiveBHWs > 0 ? round($bResidents / $bActiveBHWs, 1) : null,
    ];
}
usort($barangayComparison, fn($a, $b) => $b['at_risk_pct'] <=> $a['at_risk_pct']);

// -------------------------------------------------------
// BHW WORKFORCE COVERAGE (#8 — town-wide summary)
// -------------------------------------------------------
$totalActiveBHWs = (int) $pdo->query("SELECT COUNT(*) FROM health_workers WHERE status = 'Active'")->fetchColumn();
$townWideRatio = $totalActiveBHWs > 0 ? round($totalResidents / $totalActiveBHWs, 1) : null;

// -------------------------------------------------------
// MONTHLY BMI STATUS BREAKDOWN (#1 — town-wide, filtered by month)
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
        FROM health_records GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE YEAR(hr.record_date) = :y AND MONTH(hr.record_date) = :m
");
$stmt->execute([':y' => $filterYear, ':m' => $filterMonth]);
$monthlyStatuses = $stmt->fetch(\PDO::FETCH_ASSOC);

$mUnderweight = (int) ($monthlyStatuses['Underweight'] ?? 0);
$mNormal = (int) ($monthlyStatuses['Normal'] ?? 0);
$mOverweight = (int) ($monthlyStatuses['Overweight'] ?? 0);
$mObese = (int) ($monthlyStatuses['Obese'] ?? 0);
$mTotal = $mUnderweight + $mNormal + $mOverweight + $mObese;

// -------------------------------------------------------
// HELPERS
// -------------------------------------------------------
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
    <title>Municipal Reports &amp; Documentation | NutriTrack</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/reports.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>
        window.MUNICIPAL_ADMIN_NAME = <?= json_encode($_SESSION['full_name'] ?? 'Municipal Admin') ?>;
        window.RPT_SELECTED_MONTH = <?= json_encode(date('F Y', strtotime($selectedMonth . '-01'))) ?>;
        window.RPT_MONTHLY_STATS = <?= json_encode(['uw' => $mUnderweight, 'normal' => $mNormal, 'ow' => $mOverweight, 'obese' => $mObese, 'total' => $mTotal]) ?>;
        window.RPT_BARANGAY_DATA = <?= json_encode($barangayComparison) ?>;
        window.RPT_GENDER_STATS = <?= json_encode($genderStats) ?>;
        window.RPT_NEW_RESIDENTS_DATA = <?= json_encode($newResidentsByMonth) ?>;
        window.RPT_AT_RISK_DATA = <?= json_encode($monthlyAtRiskData) ?>;
    </script>
    <script src="../../assets/js/reports_municipal_pdf.js"></script>
    <script src="../../assets/js/master_report_municipal_pdf.js"></script>
    <img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
    <img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
        style="display:none;">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_municipal.php"; ?>

    <main class="dashboard">

        <!-- PAGE HEADER -->
        <div class="reports-page-header">
            <div>
                <h1 class="page-title">Municipal Reports &amp; Documentation</h1>
                <p class="reports-page-subtitle">Town-wide reports across all barangays &middot; NutriTrack Health
                    System</p>
            </div>
            <div class="reports-header-actions">
                <button class="rpt-btn-secondary" onclick="generateMasterReportPDF()">📥 Export</button>
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
            </div>

            <div class="rpt-tabs-content">

                <!-- ══════════════════════════════════
                 TAB 1: ALL REPORTS — 8 cards
            ═══════════════════════════════════ -->
                <div class="rpt-tab-pane <?= $activeTab === 'all' ? 'active' : '' ?>" id="tab-all">

                    <div class="rpt-filter-bar">
                        <form method="GET" class="form-contents" id="allFilterForm">
                            <input type="hidden" name="tab" value="all">
                            <select name="barangay_id" onchange="this.form.submit()">
                                <option value="0">All Barangays</option>
                                <?php foreach ($allBarangays as $brgy): ?>
                                    <option value="<?= $brgy['barangay_id'] ?>" <?= $selectedBrgy === (int) $brgy['barangay_id'] ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($brgy['barangay_name']) ?>
                                    </option>
                                <?php endforeach; ?>
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

                        <!-- #1 Town-Wide Monthly Nutrition Report -->
                        <div class="rpt-card" data-title="town-wide monthly nutrition report"
                            data-tags="monthly trends statistics barangays">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-blue">📊</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Town-Wide Monthly Nutrition Report</div>
                                    <div class="rpt-card-meta"><?= number_format($totalResidents) ?> residents &middot;
                                        <?= number_format($totalRecords) ?> records
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">BMI distribution aggregated across all <?= $totalBarangays ?>
                                barangays for <?= date('F Y', strtotime($selectedMonth . '-01')) ?>.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">📅 Monthly</span><span class="rpt-tag">🏘️
                                    Town-Wide</span><span class="rpt-tag">📋 Statistics</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                            </div>
                        </div>

                        <!-- #2 Per-Barangay Comparison -->
                        <div class="rpt-card" data-title="per-barangay comparison"
                            data-tags="ranking comparison residents records at-risk percentage">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-green">🏘️</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Per-Barangay Comparison</div>
                                    <div class="rpt-card-meta"><?= $totalBarangays ?> barangays ranked</div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Residents, records, and at-risk percentage side-by-side across
                                every barangay, ranked by risk level.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">📊 Ranking</span><span class="rpt-tag">🔍
                                    Comparison</span><span class="rpt-tag">⚠️ At-Risk %</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                            </div>
                        </div>



                        <!-- #5 Gender-Based Nutrition Comparison -->
                        <div class="rpt-card" data-title="gender-based nutrition comparison"
                            data-tags="gender male female bmi distribution demographics">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-purple">⚥</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Gender-Based Nutrition Comparison</div>
                                    <div class="rpt-card-meta"><?= $genderStats['Male']['total'] ?> male &middot;
                                        <?= $genderStats['Female']['total'] ?> female
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Town-wide BMI split by gender, comparing average BMI and at-risk
                                counts across all residents.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">👥 Demographics</span><span
                                    class="rpt-tag">📊 BMI Split</span><span class="rpt-tag">🔍 Comparison</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                            </div>
                        </div>

                        <!-- #6 New Residents Registered -->
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
                            <div class="rpt-card-desc">Monthly count of newly registered residents town-wide over the
                                last 6 months, showing enrollment growth.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">📅 Monthly</span><span class="rpt-tag">📈
                                    Growth</span><span class="rpt-tag">👥 Enrollment</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                            </div>
                        </div>

                        <!-- #7 Monthly At-Risk Trend -->
                        <div class="rpt-card" data-title="monthly at-risk trend"
                            data-tags="trend line chart 6 months history town-wide">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-amber">📅</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Monthly At-Risk Trend</div>
                                    <div class="rpt-card-meta">Last 6 months &middot; town-wide</div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Month-over-month at-risk resident count trend across the whole
                                municipality to track overall health progress.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">📉 Trend</span><span class="rpt-tag">🏘️
                                    Town-Wide</span><span class="rpt-tag">🔍 Analysis</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                            </div>
                        </div>

                        <!-- #8 BHW Workforce Coverage -->
                        <div class="rpt-card" data-title="bhw workforce coverage"
                            data-tags="staffing ratio health workers per barangay">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-green">🧑‍⚕️</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">BHW Workforce Coverage</div>
                                    <div class="rpt-card-meta"><?= $totalActiveBHWs ?> active BHWs &middot;
                                        <?= $totalBarangays ?> barangays
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Active barangay health workers versus resident count per barangay
                                — staffing ratio for workforce planning.</div>
                            <div class="rpt-card-tags"><span class="rpt-tag">🧑‍⚕️ Staffing</span><span
                                    class="rpt-tag">📊 Ratio</span><span class="rpt-tag">🏘️ Per Barangay</span></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-preview"
                                    onclick="switchTab('statistics', document.querySelectorAll('.rpt-tab-btn')[1])">👁️
                                    Preview</button>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ══════════════════════════════════
     TAB 2: STATISTICS
     #1 Town-Wide Monthly Nutrition, #2 Per-Barangay Comparison,
     #5 Gender Comparison, #6 New Residents, #7 At-Risk Trend, #8 BHW Coverage
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

                        <!-- #1 Town-Wide Monthly Nutrition Report -->
                        <div class="rpt-card" data-title="town-wide monthly nutrition report"
                            data-tags="monthly trends statistics underweight normal overweight obese barangays">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-blue">📊</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Town-Wide Monthly Nutrition Report</div>
                                    <div class="rpt-card-meta"><?= number_format($totalResidents) ?> residents &middot;
                                        <?= number_format($recordsThisMonth) ?> records this month
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-card-desc">Aggregated BMI status across all <?= $totalBarangays ?> barangays
                                for
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
                                <button class="rpt-btn-action rpt-btn-generate"
                                    onclick="generateTownWideNutritionPDF()">📄 Generate</button>
                            </div>
                        </div>

                        <!-- #2 Per-Barangay Comparison -->
                        <div class="rpt-card" data-title="per-barangay comparison"
                            data-tags="ranking comparison residents records at-risk percentage bhw ratio">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-green">🏘️</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Per-Barangay Comparison</div>
                                    <div class="rpt-card-meta"><?= $totalBarangays ?> barangays ranked by at-risk %
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-chart-wrap"><canvas id="barangayCompareChart"></canvas></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-generate"
                                    onclick="generateBarangayComparisonPDF()">📄 Generate</button>
                            </div>
                        </div>

                        <!-- #5 Gender-Based Nutrition Comparison -->
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
                                <button class="rpt-btn-action rpt-btn-generate"
                                    onclick="generateGenderComparisonPDF()">📄 Generate</button>
                            </div>
                        </div>

                        <!-- #6 New Residents Registered -->
                        <div class="rpt-card" data-title="new residents registered"
                            data-tags="new residents registration monthly growth enrollment bar chart 6 months town-wide">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-blue">🆕</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">New Residents Registered</div>
                                    <div class="rpt-card-meta"><?= $totalNewThisMonth ?> this month &middot;
                                        <?= number_format($totalResidents) ?> total
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-chart-wrap"><canvas id="newResidentsChart"></canvas></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-generate" onclick="generateNewResidentsPDF()">📄
                                    Generate</button>
                            </div>
                        </div>

                        <!-- #7 Monthly At-Risk Trend -->
                        <div class="rpt-card" data-title="monthly at-risk trend"
                            data-tags="monthly at-risk trend history 6 months line chart town-wide">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-amber">📅</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">Monthly At-Risk Trend</div>
                                    <div class="rpt-card-meta">Last 6 months &middot; town-wide</div>
                                </div>
                            </div>
                            <div class="rpt-chart-wrap"><canvas id="atRiskTrendChart"></canvas></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-generate" onclick="generateAtRiskTrendPDF()">📄
                                    Generate</button>
                            </div>
                        </div>

                        <!-- #8 BHW Workforce Coverage -->
                        <div class="rpt-card" data-title="bhw workforce coverage"
                            data-tags="staffing ratio health workers per barangay active bhw">
                            <div class="rpt-card-header">
                                <div class="rpt-card-icon rpt-icon-green">🧑‍⚕️</div>
                                <div class="rpt-card-info">
                                    <div class="rpt-card-title">BHW Workforce Coverage</div>
                                    <div class="rpt-card-meta"><?= $totalActiveBHWs ?> active BHWs &middot;
                                        <?= $townWideRatio !== null ? '1 : ' . $townWideRatio : 'No active BHWs' ?>
                                        ratio
                                    </div>
                                </div>
                            </div>
                            <div class="rpt-chart-wrap"><canvas id="bhwCoverageChart"></canvas></div>
                            <div class="rpt-card-actions">
                                <button class="rpt-btn-action rpt-btn-generate" onclick="generateBhwCoveragePDF()">📄
                                    Generate</button>
                            </div>
                        </div>

                    </div>

                    <!-- Per-Barangay Comparison — full detail table -->
                    <section class="rpt-stat-section"
                        data-section="per-barangay comparison residents records at-risk percentage bhw ratio">
                        <div class="rpt-stat-header">
                            <h2 class="rpt-stat-title">Per-Barangay Comparison</h2>
                            <span class="rpt-badge-count"><?= $totalBarangays ?>
                                barangay<?= $totalBarangays !== 1 ? 's' : '' ?></span>
                        </div>
                        <?php if (empty($barangayComparison)): ?>
                            <p class="text-muted">No barangays registered yet.</p>
                        <?php else: ?>
                            <div class="rpt-worker-table-wrap">
                                <table class="rpt-worker-table" id="statBarangayTable">
                                    <thead>
                                        <tr>
                                            <th>Barangay</th>
                                            <th>Residents</th>
                                            <th>Records</th>
                                            <th>Assessed</th>
                                            <th>At-Risk</th>
                                            <th>At-Risk %</th>
                                            <th>Active BHWs</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($barangayComparison as $bc): ?>
                                            <tr>
                                                <td class="rpt-td-bold"><?= htmlspecialchars($bc['barangay_name']) ?></td>
                                                <td class="rpt-td-muted"><?= number_format($bc['residents']) ?></td>
                                                <td class="rpt-td-muted"><?= number_format($bc['records']) ?></td>
                                                <td class="rpt-td-muted"><?= number_format($bc['assessed']) ?></td>
                                                <td class="rpt-td-muted"><?= number_format($bc['at_risk']) ?></td>
                                                <td>
                                                    <span
                                                        class="rpt-bmi-val <?= $bc['at_risk_pct'] >= 50 ? 'rpt-bmi-val--ob' : ($bc['at_risk_pct'] >= 25 ? 'rpt-bmi-val--ow' : 'rpt-bmi-val--nm') ?>">
                                                        <?= $bc['at_risk_pct'] ?>%
                                                    </span>
                                                </td>
                                                <td class="rpt-td-muted"><?= $bc['active_bhws'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- New Residents Registered — full detail table -->
                    <section class="rpt-stat-section" id="statNewResidentsSection"
                        data-section="new residents registered monthly enrollment growth registration last 6 months town-wide">
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


            </div><!-- /.rpt-tabs-content -->
        </div><!-- /.rpt-tabs-container -->

    </main>

    <?php include "../../includes/footer.php"; ?>

    <script>
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
        // STATISTICS CHARTS
        // ─────────────────────────────────────────────────────
        let barangayCompareChartInstance = null;
        let genderBmiChartInstance = null;
        let atRiskTrendInstance = null;
        let newResidentsInstance = null;
        let bhwCoverageChartInstance = null;

        function drawStatCharts() {
            const ctxBrgy = document.getElementById('barangayCompareChart');
            if (ctxBrgy) {
                if (barangayCompareChartInstance) { barangayCompareChartInstance.destroy(); barangayCompareChartInstance = null; }
                barangayCompareChartInstance = new Chart(ctxBrgy, {
                    type: 'bar',
                    data: {
                        labels: [<?= implode(',', array_map(fn($b) => json_encode($b['barangay_name']), $barangayComparison)) ?>],
                        datasets: [{
                            label: 'At-Risk %',
                            data: [<?= implode(',', array_column($barangayComparison, 'at_risk_pct')) ?>],
                            backgroundColor: 'rgba(231,76,60,0.75)',
                            borderRadius: 4
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } } }
                });
            }

            const ctx2 = document.getElementById('genderBmiChart');
            if (ctx2) {
                if (genderBmiChartInstance) { genderBmiChartInstance.destroy(); genderBmiChartInstance = null; }
                genderBmiChartInstance = new Chart(ctx2, {
                    type: 'bar',
                    data: {
                        labels: ['Underweight', 'Normal', 'Overweight', 'Obese'],
                        datasets: [
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
                    data: { labels: [<?= implode(',', array_map(fn($r) => json_encode($r['label']), $monthlyAtRiskData)) ?>], datasets: [{ label: 'At-Risk Residents', data: [<?= implode(',', array_column($monthlyAtRiskData, 'count')) ?>], borderColor: '#e74c3c', backgroundColor: 'rgba(231,76,60,0.1)', borderWidth: 2, pointBackgroundColor: '#e74c3c', pointRadius: 5, fill: true, tension: 0.3 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            }

            const ctx4 = document.getElementById('newResidentsChart');
            if (ctx4) {
                if (newResidentsInstance) { newResidentsInstance.destroy(); newResidentsInstance = null; }
                newResidentsInstance = new Chart(ctx4, {
                    type: 'bar',
                    data: { labels: [<?= implode(',', array_map(fn($r) => json_encode($r['label']), $newResidentsByMonth)) ?>], datasets: [{ label: 'New Residents', data: [<?= implode(',', array_column($newResidentsByMonth, 'count')) ?>], backgroundColor: 'rgba(52,152,219,0.8)', borderRadius: 4 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            }

            const ctx5 = document.getElementById('bhwCoverageChart');
            if (ctx5) {
                if (bhwCoverageChartInstance) { bhwCoverageChartInstance.destroy(); bhwCoverageChartInstance = null; }
                bhwCoverageChartInstance = new Chart(ctx5, {
                    type: 'bar',
                    data: {
                        labels: [<?= implode(',', array_map(fn($b) => json_encode($b['barangay_name']), $barangayComparison)) ?>],
                        datasets: [
                            { label: 'Residents', data: [<?= implode(',', array_column($barangayComparison, 'residents')) ?>], backgroundColor: 'rgba(52,152,219,0.7)', borderRadius: 4, yAxisID: 'y' },
                            { label: 'Active BHWs', data: [<?= implode(',', array_column($barangayComparison, 'active_bhws')) ?>], backgroundColor: 'rgba(22,163,74,0.85)', borderRadius: 4, yAxisID: 'y1' }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } },
                        scales: {
                            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Residents' } },
                            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'BHWs' }, ticks: { stepSize: 1 } }
                        }
                    }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (document.getElementById('tab-statistics')?.classList.contains('active')) drawStatCharts();
        });

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
    </script>

</body>

</html>