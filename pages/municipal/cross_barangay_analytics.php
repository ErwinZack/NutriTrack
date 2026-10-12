<?php
session_start();

/* ── Dependencies ── */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

/* ── Auth ── */
if (!isset($_SESSION['superadmin_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: login_municipal.php");
    exit();
}

/* ── Date Range (for reporting activity section) ── */
$range = $_GET['range'] ?? '30';
switch ($range) {
    case '7':
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        break;
    case '90':
        $dateFrom = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'all':
        $dateFrom = '2000-01-01';
        break;
    default:
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $range = '30';
}
$dateTo = date('Y-m-d');

/* ══════════════════════════════════════════
   SECTION 1: CURRENT SITUATION (Stat Cards)
   ══════════════════════════════════════════ */

$latestBmiPerResident = $pdo->query("
    SELECT hr.resident_id, hr.bmi, r.barangay_id, r.gender, r.birth_date
    FROM health_records hr
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    INNER JOIN residents r ON r.resident_id = hr.resident_id
")->fetchAll();

/* ══════════════════════════════════════════
   SECTION 2: WHICH BARANGAYS NEED ATTENTION?
   ══════════════════════════════════════════ */

$barangayList = $pdo->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC")->fetchAll();

// Init per-barangay counters
$brgyStats = [];
foreach ($barangayList as $b) {
    $brgyStats[$b['barangay_id']] = [
        'name' => $b['barangay_name'],
        'uw' => 0,
        'nm' => 0,
        'ow' => 0,
        'ob' => 0,
        'total' => 0
    ];
}

// Fill from latest BMI data
foreach ($latestBmiPerResident as $row) {
    $bid = $row['barangay_id'];
    if (!isset($brgyStats[$bid]))
        continue; // resident with no/unknown barangay assigned
    $b = (float) $row['bmi'];
    $brgyStats[$bid]['total']++;
    if ($b < 18.5)
        $brgyStats[$bid]['uw']++;
    elseif ($b < 25.0)
        $brgyStats[$bid]['nm']++;
    elseif ($b < 30.0)
        $brgyStats[$bid]['ow']++;
    else
        $brgyStats[$bid]['ob']++;
}

// Compute at-risk rate per barangay
foreach ($brgyStats as $bid => &$s) {
    $atRisk = $s['uw'] + $s['ow'] + $s['ob'];
    $s['at_risk_count'] = $atRisk;
    $s['at_risk_pct'] = $s['total'] > 0 ? round($atRisk / $s['total'] * 100, 1) : 0;
}
unset($s);

// Total Residents per Barangay (ALL registered residents, not just those with BMI records)
$residentsPerBarangayStmt = $pdo->query("
    SELECT b.barangay_id, b.barangay_name, COUNT(r.resident_id) AS resident_count
    FROM barangays b
    LEFT JOIN residents r ON r.barangay_id = b.barangay_id
    GROUP BY b.barangay_id, b.barangay_name
    ORDER BY b.barangay_name ASC
");
$residentsPerBarangay = $residentsPerBarangayStmt->fetchAll();

// Priority Barangays — sorted by at-risk % descending, only barangays with data
$priorityBarangays = array_filter($brgyStats, fn($s) => $s['total'] > 0);
uasort($priorityBarangays, fn($a, $b) => $b['at_risk_pct'] <=> $a['at_risk_pct']);
$priorityBarangays = array_slice($priorityBarangays, 0, 5, true); // top 5

/* ══════════════════════════════════════════
   SECTION 3: WHO NEEDS TARGETED ATTENTION?
   ══════════════════════════════════════════ */

// BMI by Age Group (town-wide)
$ageGroupStmt = $pdo->query("
    SELECT
        CASE
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 0  AND 12 THEN '0–12'
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 13 AND 19 THEN '13–19'
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 20 AND 24 THEN '20–24'
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 25 AND 34 THEN '25–34'
            WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 35 AND 49 THEN '35–49'
            ELSE '50+'
        END AS age_group,
        ROUND(AVG(hr.bmi), 1) AS avg_bmi,
        COUNT(DISTINCT hr.resident_id) AS resident_count
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    GROUP BY age_group
    ORDER BY MIN(TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()))
");
$ageGroups = $ageGroupStmt->fetchAll();

// Gender Distribution (town-wide, all registered residents)
$genderStmt = $pdo->query("SELECT gender, COUNT(*) AS cnt FROM residents GROUP BY gender");
$genderRows = $genderStmt->fetchAll();
$genderMale = $genderFemale = 0;
foreach ($genderRows as $gr) {
    if ($gr['gender'] === 'Male')
        $genderMale = (int) $gr['cnt'];
    if ($gr['gender'] === 'Female')
        $genderFemale = (int) $gr['cnt'];
}
$genderTotal = $genderMale + $genderFemale;
$genderMalePct = $genderTotal > 0 ? round($genderMale / $genderTotal * 100, 1) : 0;
$genderFemPct = $genderTotal > 0 ? round($genderFemale / $genderTotal * 100, 1) : 0;

/* ══════════════════════════════════════════
   SECTION 4: IS NUTRITION IMPROVING OR WORSENING?
   ══════════════════════════════════════════ */

$trendLabels = [];
$trendAtRiskPct = [];
$trendNormalPct = [];
for ($i = 5; $i >= 0; $i--) {
    $y = date('Y', strtotime("-$i months"));
    $m = date('m', strtotime("-$i months"));
    $trendLabels[] = date('M Y', strtotime("-$i months"));

    $stmt = $pdo->prepare("SELECT bmi FROM health_records WHERE YEAR(record_date) = :y AND MONTH(record_date) = :m");
    $stmt->execute([':y' => $y, ':m' => $m]);
    $rows = $stmt->fetchAll();

    $total = count($rows);
    $atRisk = 0;
    $normal = 0;
    foreach ($rows as $r) {
        $b = (float) $r['bmi'];
        if ($b >= 18.5 && $b < 25.0)
            $normal++;
        else
            $atRisk++;
    }
    $trendAtRiskPct[] = $total > 0 ? round($atRisk / $total * 100, 1) : 0;
    $trendNormalPct[] = $total > 0 ? round($normal / $total * 100, 1) : 0;
}

/* ══════════════════════════════════════════
   SECTION 5: ARE BARANGAYS ACTIVELY REPORTING?
   ══════════════════════════════════════════ */

$reportingStmt = $pdo->prepare("
    SELECT b.barangay_id, b.barangay_name, COUNT(hr.record_id) AS record_count
    FROM barangays b
    LEFT JOIN health_records hr ON hr.barangay_id = b.barangay_id
        AND hr.record_date BETWEEN :dateFrom AND :dateTo
    GROUP BY b.barangay_id, b.barangay_name
    ORDER BY record_count DESC
");
$reportingStmt->execute([':dateFrom' => $dateFrom, ':dateTo' => $dateTo]);
$reportingActivity = $reportingStmt->fetchAll();

// Underreporting: flagged if 0 records in the selected period
$underreportingBarangays = array_filter($reportingActivity, fn($r) => (int) $r['record_count'] === 0);

$pageTitle = "Cross-Barangay Analytics";

/* ── Includes ── */
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_municipal.php';
?>

<main class="municipal-dashboard municipal-analytics-page">

    <!-- Page Header -->
    <div class="municipal-page-header">
        <div>
            <p class="municipal-welcome-message">Town-wide nutritional health overview · Calasiao</p>
        </div>
        <div class="municipal-range-tabs">
            <a href="?range=7" class="municipal-range-tab <?= $range === '7' ? 'active' : '' ?>">7 Days</a>
            <a href="?range=30" class="municipal-range-tab <?= $range === '30' ? 'active' : '' ?>">30 Days</a>
            <a href="?range=90" class="municipal-range-tab <?= $range === '90' ? 'active' : '' ?>">90 Days</a>
            <a href="?range=all" class="municipal-range-tab <?= $range === 'all' ? 'active' : '' ?>">All Time</a>
        </div>
    </div>

   

    <!-- Total Residents per Barangay (full width) -->
    <div class="municipal-dashboard-row-weekly">
        <div class="municipal-chart-card">
            <div class="municipal-section-header">
                <div>
                    <h3 class="municipal-section-title">Total Residents per Barangay</h3>
                    <p class="municipal-section-subtitle">Number of registered residents being monitored in each
                        barangay</p>
                </div>
                <span class="municipal-analytics-badge">Population</span>
            </div>
            <div class="municipal-chart-box">
                <canvas id="residentsPerBarangayChart"></canvas>
            </div>
        </div>
    </div>

    <div class="municipal-dashboard-row-overview">

        <div class="municipal-chart-card">
            <div class="municipal-section-header">
                <div>
                    <h3 class="municipal-section-title">At-Risk Rate by Barangay</h3>
                    <p class="municipal-section-subtitle">% of residents currently at nutritional risk</p>
                </div>
                <span class="municipal-analytics-badge amber">Comparison</span>
            </div>
            <div class="municipal-chart-box">
                <canvas id="atRiskRateChart"></canvas>
            </div>
        </div>

        <div class="municipal-chart-card">
            <div class="municipal-section-header">
                <div>
                    <h3 class="municipal-section-title">Nutrition Status by Barangay</h3>
                    <p class="municipal-section-subtitle">Underweight / Normal / Overweight / Obese distribution</p>
                </div>
                <span class="municipal-analytics-badge green">Breakdown</span>
            </div>
            <div class="municipal-chart-box">
                <canvas id="statusByBarangayChart"></canvas>
            </div>
        </div>

    </div>

    <!-- Priority Barangays Table (full width) -->
    <div class="municipal-dashboard-row-weekly">
        <div class="municipal-chart-card">
            <div class="municipal-section-header">
                <div>
                    <h3 class="municipal-section-title">Priority Barangays</h3>
                    <p class="municipal-section-subtitle">Barangays with the highest at-risk rates — may need
                        intervention or resources</p>
                </div>
                <span class="municipal-analytics-badge red">Alert</span>
            </div>
            <div class="municipal-dash-inner-table-wrap">
                <table class="municipal-dash-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Barangay</th>
                            <th>Underweight</th>
                            <th>Normal</th>
                            <th>Overweight</th>
                            <th>Obese</th>
                            <th>At-Risk Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($priorityBarangays)): ?>
                            <?php $rank = 1;
                            foreach ($priorityBarangays as $bid => $s): ?>
                                <tr>
                                    <td class="municipal-dash-td-num"><?= $rank++ ?></td>
                                    <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                                    <td class="municipal-dash-td-num"><?= $s['uw'] ?></td>
                                    <td class="municipal-dash-td-num"><?= $s['nm'] ?></td>
                                    <td class="municipal-dash-td-num"><?= $s['ow'] ?></td>
                                    <td class="municipal-dash-td-num"><?= $s['ob'] ?></td>
                                    <td>
                                        <span
                                            class="municipal-badge <?= $s['at_risk_pct'] >= 50 ? 'municipal-badge-inactive' : ($s['at_risk_pct'] >= 25 ? 'municipal-badge-amber' : 'municipal-badge-active') ?>">
                                            <?= $s['at_risk_pct'] ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="municipal-dash-td-empty">
                                    <div class="municipal-dash-empty">
                                        <div class="municipal-dash-empty-icon">🏘️</div>
                                        <div class="municipal-dash-empty-text">No nutrition data available yet.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══ SECTION 3: Who Needs Targeted Attention? ═══ -->
    <h2 class="municipal-analytics-section-title">Demographic Breakdown</h2>
    <div class="municipal-dashboard-row-overview">

        <div class="municipal-chart-card">
            <div class="municipal-section-header">
                <div>
                    <h3 class="municipal-section-title">BMI by Age Group</h3>
                    <p class="municipal-section-subtitle">Average BMI per demographic bracket, town-wide</p>
                </div>
                <span class="municipal-analytics-badge amber">Demographics</span>
            </div>
            <div class="municipal-demog-body">
                <?php if (!empty($ageGroups)): ?>
                    <div class="municipal-age-bars-list">
                        <?php
                        $ageColors = ['#1e3c72', '#059669', '#d97706', '#ea580c', '#dc2626', '#7c3aed'];
                        $ci = 0;
                        foreach ($ageGroups as $ag):
                            $bmiVal = (float) $ag['avg_bmi'];
                            $fillPct = min(100, round(($bmiVal / 40) * 100));
                            $color = $ageColors[$ci % count($ageColors)];
                            $ci++;
                            ?>
                            <div class="municipal-age-bar-item">
                                <div class="municipal-age-bar-top">
                                    <span class="municipal-age-bar-label">
                                        <?= htmlspecialchars($ag['age_group']) ?> yrs
                                        <span class="municipal-age-bar-count">(<?= (int) $ag['resident_count'] ?>
                                            resident<?= $ag['resident_count'] > 1 ? 's' : '' ?>)</span>
                                    </span>
                                    <span class="municipal-age-bar-meta">Avg BMI <?= $bmiVal ?></span>
                                </div>
                                <div class="municipal-age-bar-track">
                                    <div class="municipal-age-bar-fill" data-width="<?= $fillPct ?>" data-color="<?= $color ?>">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="municipal-no-data-text">No data available yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="municipal-chart-card">
            <div class="municipal-section-header">
                <div>
                    <h3 class="municipal-section-title">Gender Distribution</h3>
                    <p class="municipal-section-subtitle">Male/female breakdown, town-wide — supports program planning
                    </p>
                </div>
                <span class="municipal-analytics-badge green">Demographics</span>
            </div>
            <div class="municipal-status-pill-grid">
                <div class="municipal-status-pill sp-nm">
                    <div>
                        <div class="sp-label">Male</div>
                        <div class="sp-sub"><?= $genderMalePct ?>%</div>
                    </div>
                    <div class="sp-count"><?= $genderMale ?></div>
                </div>
                <div class="municipal-status-pill sp-uw">
                    <div>
                        <div class="sp-label">Female</div>
                        <div class="sp-sub"><?= $genderFemPct ?>%</div>
                    </div>
                    <div class="sp-count"><?= $genderFemale ?></div>
                </div>
            </div>
            <div class="municipal-chart-box">
                <canvas id="genderChart"></canvas>
            </div>
        </div>

    </div>

    <!-- ═══ SECTION 4: Is Nutrition Improving or Worsening? ═══ -->
    <h2 class="municipal-analytics-section-title">Nutrition Trend</h2>
    <div class="municipal-dashboard-row-weekly">
        <div class="municipal-chart-card">
            <div class="municipal-section-header">
                <div>
                    <h3 class="municipal-section-title">Nutrition Status Trend</h3>
                    <p class="municipal-section-subtitle">Town-wide % at-risk vs. % normal over the last 6 months</p>
                </div>
                <span class="municipal-analytics-badge">Last 6 Months</span>
            </div>
            <div class="municipal-chart-box">
                <canvas id="nutritionTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ═══ SECTION 5: Are Barangays Actively Reporting? ═══ -->
    <h2 class="municipal-analytics-section-title">Reporting Compliance</h2>
    <div class="municipal-dashboard-row-records">

        <div class="municipal-chart-card">
            <div class="municipal-section-header">
                <div>
                    <h3 class="municipal-section-title">Barangay Reporting Activity</h3>
                    <p class="municipal-section-subtitle">Health-record submissions per barangay · selected period</p>
                </div>
                <span class="municipal-analytics-badge green">Ranking</span>
            </div>
            <div class="municipal-dash-inner-table-wrap">
                <table class="municipal-dash-table">
                    <thead>
                        <tr>
                            <th>Barangay</th>
                            <th>Records Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($reportingActivity)): ?>
                            <?php foreach ($reportingActivity as $r): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['barangay_name']) ?></strong></td>
                                    <td class="municipal-dash-td-num"><?= (int) $r['record_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="2" class="municipal-dash-td-empty">
                                    <div class="municipal-dash-empty">
                                        <div class="municipal-dash-empty-icon">📋</div>
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
                    <h3 class="municipal-section-title">Underreporting Alerts</h3>
                    <p class="municipal-section-subtitle">Barangays with zero submissions in the selected period</p>
                </div>
                <span class="municipal-analytics-badge red">Alert</span>
            </div>
            <?php if (!empty($underreportingBarangays)): ?>
                <div class="municipal-underreporting-list">
                    <?php foreach ($underreportingBarangays as $ur): ?>
                        <div class="municipal-underreporting-item">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span><strong><?= htmlspecialchars($ur['barangay_name']) ?></strong> has 0 health records in this
                                period</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="municipal-dash-empty" style="padding: 40px 20px;">
                    <div class="municipal-dash-empty-icon">✅</div>
                    <div class="municipal-dash-empty-text">All barangays are actively reporting.</div>
                </div>
            <?php endif; ?>
        </div>

    </div>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    Chart.defaults.color = '#8899b3';
    Chart.defaults.borderColor = '#eef1f7';
    Chart.defaults.font.family = "'Plus Jakarta Sans', 'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size = 11.5;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.padding = 14;

    // Age bar animation
    document.querySelectorAll('.municipal-age-bar-fill').forEach(el => {
        el.style.width = el.dataset.width + '%';
        el.style.background = el.dataset.color;
    });

    // Chart 1: Total Residents per Barangay
    const rpbCtx = document.getElementById('residentsPerBarangayChart').getContext('2d');
    const rpbGrad = rpbCtx.createLinearGradient(0, 0, 0, 260);
    rpbGrad.addColorStop(0, 'rgba(30,60,114,0.85)');
    rpbGrad.addColorStop(1, 'rgba(30,60,114,0.25)');
    new Chart(rpbCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($residentsPerBarangay, 'barangay_name')) ?>,
            datasets: [{
                label: 'Residents',
                data: <?= json_encode(array_map('intval', array_column($residentsPerBarangay, 'resident_count'))) ?>,
                backgroundColor: rpbGrad,
                borderRadius: 8,
                borderSkipped: false,
                barPercentage: 0.35,
                categoryPercentage: 0.5
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8,
                    callbacks: { label: ctx => ` ${ctx.raw} residents` }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#64748b', autoSkip: false, maxRotation: 0, minRotation: 0, font: { size: 11 } } },
                y: { beginAtZero: true, grid: { color: '#f0f2f8' }, ticks: { precision: 0, color: '#94a3b8' } }
            }
        }
    });

    // Chart 2: At-Risk Rate by Barangay
    const brgyLabels = <?= json_encode(array_values(array_column($brgyStats, 'name'))) ?>;
    const brgyAtRiskPct = <?= json_encode(array_values(array_column($brgyStats, 'at_risk_pct'))) ?>;
    new Chart(document.getElementById('atRiskRateChart'), {
        type: 'bar',
        data: {
            labels: brgyLabels,
            datasets: [{
                label: 'At-Risk Rate (%)',
                data: brgyAtRiskPct,
                backgroundColor: brgyAtRiskPct.map(v => v >= 50 ? 'rgba(220,38,38,0.8)' : v >= 25 ? 'rgba(217,119,6,0.8)' : 'rgba(5,150,105,0.8)'),
                borderColor: brgyAtRiskPct.map(v => v >= 50 ? '#dc2626' : v >= 25 ? '#d97706' : '#059669'),
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
                barPercentage: 0.35,
                categoryPercentage: 0.5
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8,
                    callbacks: { label: ctx => ` ${ctx.raw}% at risk` }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#64748b', autoSkip: false, maxRotation: 0, minRotation: 0, font: { size: 11 } } },
                y: { beginAtZero: true, max: 100, grid: { color: '#f0f2f8' }, ticks: { color: '#94a3b8', callback: v => v + '%' } }
            }
        }
    });

    // Chart 3: Nutrition Status by Barangay (stacked)
    new Chart(document.getElementById('statusByBarangayChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_values(array_column($brgyStats, 'name'))) ?>,
            datasets: [
                { label: 'Underweight', data: <?= json_encode(array_values(array_column($brgyStats, 'uw'))) ?>, backgroundColor: 'rgba(217,119,6,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status', barPercentage: 0.35, categoryPercentage: 0.5 },
                { label: 'Normal', data: <?= json_encode(array_values(array_column($brgyStats, 'nm'))) ?>, backgroundColor: 'rgba(5,150,105,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status', barPercentage: 0.35, categoryPercentage: 0.5 },
                { label: 'Overweight', data: <?= json_encode(array_values(array_column($brgyStats, 'ow'))) ?>, backgroundColor: 'rgba(234,88,12,0.8)', borderRadius: 0, borderSkipped: false, stack: 'status', barPercentage: 0.35, categoryPercentage: 0.5 },
                { label: 'Obese', data: <?= json_encode(array_values(array_column($brgyStats, 'ob'))) ?>, backgroundColor: 'rgba(220,38,38,0.8)', borderRadius: 4, borderSkipped: false, stack: 'status', barPercentage: 0.35, categoryPercentage: 0.5 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'top' }, tooltip: { backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8 } },
            scales: {
                x: { stacked: true, grid: { display: false }, ticks: { color: '#64748b', autoSkip: false, maxRotation: 0, minRotation: 0, font: { size: 11 } } },
                y: { stacked: true, beginAtZero: true, grid: { color: '#f0f2f8' }, ticks: { color: '#94a3b8', precision: 0 } }
            }
        }
    });

    // Chart 4: Gender Distribution
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

    // Chart 5: Nutrition Trend (last 6 months)
    const trendCtx = document.getElementById('nutritionTrendChart').getContext('2d');
    const atRiskGrad = trendCtx.createLinearGradient(0, 0, 0, 280);
    atRiskGrad.addColorStop(0, 'rgba(239,68,68,0.18)');
    atRiskGrad.addColorStop(1, 'rgba(239,68,68,0.01)');
    const normalGrad = trendCtx.createLinearGradient(0, 0, 0, 280);
    normalGrad.addColorStop(0, 'rgba(5,150,105,0.18)');
    normalGrad.addColorStop(1, 'rgba(5,150,105,0.01)');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendLabels) ?>,
            datasets: [
                {
                    label: '% At-Risk',
                    data: <?= json_encode($trendAtRiskPct) ?>,
                    borderColor: '#ef4444',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#ef4444',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    backgroundColor: atRiskGrad,
                    tension: 0.4
                },
                {
                    label: '% Normal',
                    data: <?= json_encode($trendNormalPct) ?>,
                    borderColor: '#059669',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#059669',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    backgroundColor: normalGrad,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    backgroundColor: '#1a1f36', padding: 12, cornerRadius: 8,
                    callbacks: { label: ctx => ` ${ctx.raw}%` }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#64748b' } },
                y: { beginAtZero: true, max: 100, grid: { color: '#f0f2f8' }, ticks: { color: '#94a3b8', callback: v => v + '%' } }
            }
        }
    });
</script>