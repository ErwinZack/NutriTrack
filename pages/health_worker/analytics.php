<?php
session_start();

/* ── Dependencies ── */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

/* ── Auth ── */
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}
$barangay_id = $_SESSION['barangay_id'];

$stmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :brgy");
$stmt->execute([':brgy' => $barangay_id]);
$barangay_name = $stmt->fetchColumn() ?: 'Unknown Barangay';

/* ── Date Range ── */
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

/* ── Summary Stats ── */
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
      AND YEAR(record_date) = YEAR(CURRENT_DATE())
");

$stmt->execute([':brgy' => $barangay_id]);
$newRecordsThisMonth = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT hr.resident_id, hr.bmi
    FROM health_records hr
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy
          AND record_date BETWEEN :from1 AND :to1
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
      AND hr.record_date BETWEEN :from2 AND :to2
");
$stmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id, ':from1' => $dateFrom, ':to1' => $dateTo, ':from2' => $dateFrom, ':to2' => $dateTo]);
$latestBmiPerResident = $stmt->fetchAll();

$countUnderweight = 0;
$countNormal = 0;
$countOverweight = 0;
$countObese = 0;
foreach ($latestBmiPerResident as $row) {
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
$atRiskCount = $countUnderweight + $countOverweight + $countObese;

$stmt = $pdo->prepare("
    SELECT ROUND(AVG(bmi), 1) FROM health_records
    WHERE barangay_id = :brgy
      AND MONTH(record_date) = MONTH(CURRENT_DATE())
      AND YEAR(record_date) = YEAR(CURRENT_DATE())
");
$stmt->execute([':brgy' => $barangay_id]);
$avgBmi = $stmt->fetchColumn() ?? 0;

/* ── Chart 1: New Residents Registered (last 12 months, all months shown) ── */
$newResidentStmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS rmonth,
           COUNT(*) AS reg_count
    FROM residents
    WHERE barangay_id = :brgy
      AND DATE(created_at) BETWEEN :from AND :to
    GROUP BY rmonth
    ORDER BY rmonth ASC
");
$newResidentStmt->execute([':brgy' => $barangay_id, ':from' => $dateFrom, ':to' => $dateTo]);
$newResidentData = $newResidentStmt->fetchAll();

// Index by month key
$dbMonthMap = [];
foreach ($newResidentData as $row) {
    $dbMonthMap[$row['rmonth']] = (int) $row['reg_count'];
}

// Generate all 12 months, fill missing with 0
$newResidentLabels = [];
$newResidentCounts = [];
for ($i = 11; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-$i months"));
    $monthLabel = date('M Y', strtotime("-$i months"));
    $newResidentLabels[] = $monthLabel;
    $newResidentCounts[] = $dbMonthMap[$monthKey] ?? 0;
}

/* ── Chart 3: Status Change Tracker ── */
$changeStmt = $pdo->prepare("
    SELECT
        r.resident_id,
        r.first_name,
        r.last_name,
        r.gender,
        TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age,
        curr.bmi AS current_bmi,
        curr.record_date AS current_record_date,
        prev.bmi AS prev_bmi
    FROM residents r
        INNER JOIN (
        SELECT resident_id, bmi, record_date
        FROM health_records hr1
        WHERE barangay_id = :brgy1
          AND record_date BETWEEN :from AND :to
          AND record_id = (
            SELECT MAX(record_id)
            FROM health_records hr2
            WHERE hr2.resident_id = hr1.resident_id
              AND hr2.barangay_id = :brgy2
              AND hr2.record_date BETWEEN :from2 AND :to2
        )
    ) curr ON curr.resident_id = r.resident_id
    INNER JOIN (
        SELECT hr3.resident_id, hr3.bmi, hr3.record_date
        FROM health_records hr3
        WHERE hr3.barangay_id = :brgy3
          AND hr3.record_id = (
            SELECT MAX(hr4.record_id)
            FROM health_records hr4
            WHERE hr4.resident_id = hr3.resident_id
              AND hr4.barangay_id = :brgy4
              AND hr4.record_id < (
                  SELECT MAX(hr5.record_id)
                  FROM health_records hr5
                  WHERE hr5.resident_id = hr3.resident_id
                    AND hr5.barangay_id = :brgy5
              )
        )
    ) prev ON prev.resident_id = r.resident_id
    WHERE r.barangay_id = :brgy6
    ORDER BY curr.record_date DESC
    LIMIT 10
");
$changeStmt->execute([
    ':brgy1' => $barangay_id, ':brgy2' => $barangay_id, ':brgy3' => $barangay_id,
    ':brgy4' => $barangay_id, ':brgy5' => $barangay_id, ':brgy6' => $barangay_id,
    ':from'  => $dateFrom,    ':to'    => $dateTo,
    ':from2' => $dateFrom,    ':to2'   => $dateTo
]);
$changedResidents = $changeStmt->fetchAll();

function getBmiStatus($bmi)
{
    if ($bmi < 18.5)
        return 'Underweight';
    if ($bmi < 25.0)
        return 'Normal';
    if ($bmi < 30.0)
        return 'Overweight';
    return 'Obese';
}

function statusBadgeClass($status)
{
    switch ($status) {
        case 'Underweight':
            return 'badge-uw';
        case 'Normal':
            return 'badge-nm';
        case 'Overweight':
            return 'badge-ow';
        case 'Obese':
            return 'badge-ob';
        default:
            return '';
    }
}

function bmiValClass($status)
{
    switch ($status) {
        case 'Normal':
            return 'bmi-val-normal';
        case 'Obese':
            return 'bmi-val-obese';
        case 'Overweight':
            return 'bmi-val-overweight';
        default:
            return 'bmi-val-underweight';
    }
}

/* ── Chart 4: Age Group BMI Breakdown ── */
$ageGroupStmt = $pdo->prepare("
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
        FROM health_records
        WHERE barangay_id = :brgy1
          AND record_date BETWEEN :from1 AND :to1
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
      AND hr.record_date BETWEEN :from2 AND :to2
    GROUP BY age_group
    ORDER BY MIN(TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()))
");
$ageGroupStmt->execute([':brgy1' => $barangay_id, ':brgy2' => $barangay_id, ':from1' => $dateFrom, ':to1' => $dateTo, ':from2' => $dateFrom, ':to2' => $dateTo]);
$ageGroups = $ageGroupStmt->fetchAll();


/* ── Chart: Daily Records ── */
$dailyDates  = [];
$dailyCounts = [];
$startDate = new DateTime($dateFrom);
$endDate   = new DateTime($dateTo);
$interval  = new DateInterval('P1D');
$dateRange = new DatePeriod($startDate, $interval, $endDate->modify('+1 day'));

foreach ($dateRange as $d) {
    $dStr = $d->format('Y-m-d');
    $dailyDates[] = $d->format('M d');
    $s = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE barangay_id = :brgy AND DATE(record_date) = :d");
    $s->execute([':brgy' => $barangay_id, ':d' => $dStr]);
    $dailyCounts[] = (int) $s->fetchColumn();
}

/* ── Includes ── */
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_health_worker.php';
?>

<main class="dashboard analytics-page">

    <!-- Page Header -->
    <div class="analytics-header">
        <div>
            <h1 class="page-title">Analytics</h1>
            <p class="analytics-subtitle">Community Health Analytics · Barangay <?= htmlspecialchars($barangay_name) ?>
            </p>
        </div>
        <div class="range-tabs">
            <a href="?range=7" class="range-tab <?= $range === '7' ? 'active' : '' ?>">7 Days</a>
            <a href="?range=30" class="range-tab <?= $range === '30' ? 'active' : '' ?>">30 Days</a>
            <a href="?range=90" class="range-tab <?= $range === '90' ? 'active' : '' ?>">90 Days</a>
            <a href="?range=all" class="range-tab <?= $range === 'all' ? 'active' : '' ?>">All Time</a>
        </div>
    </div>


    <!-- Row 1: Population BMI Trend + Nutrition Status -->
    <div class="analytics-row-trend">

        <!-- New Residents Registered -->
        <div class="chart-card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">New Residents Registered</h3>
                    <p class="section-subtitle">Number of residents registered per month</p>
                </div>
                <span class="analytics-badge">Last 12 Months</span>
            </div>
            <div class="chart-box">
                <canvas id="newResidentsChart"></canvas>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="chart-card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Nutrition Status</h3>
                    <p class="section-subtitle">Current distribution by latest BMI</p>
                </div>
                <span class="analytics-badge green">Live Snapshot</span>
            </div>
            <div class="status-panel-body">
                <div class="status-pill-grid">
                    <div class="status-pill sp-uw">
                        <div>
                            <div class="sp-label">Underweight</div>
                            <div class="sp-sub">BMI &lt; 18.5</div>
                        </div>
                        <div class="sp-count"><?= $countUnderweight ?></div>
                    </div>
                    <div class="status-pill sp-nm">
                        <div>
                            <div class="sp-label">Normal</div>
                            <div class="sp-sub">18.5 – 24.9</div>
                        </div>
                        <div class="sp-count"><?= $countNormal ?></div>
                    </div>
                    <div class="status-pill sp-ow">
                        <div>
                            <div class="sp-label">Overweight</div>
                            <div class="sp-sub">25.0 – 29.9</div>
                        </div>
                        <div class="sp-count"><?= $countOverweight ?></div>
                    </div>
                    <div class="status-pill sp-ob">
                        <div>
                            <div class="sp-label">Obese</div>
                            <div class="sp-sub">BMI ≥ 30</div>
                        </div>
                        <div class="sp-count"><?= $countObese ?></div>
                    </div>
                </div>
                <div class="donut-area">
                    <canvas id="statusDonutChart"></canvas>
                </div>
            </div>
        </div>

    </div>

    <!-- Row 2: Status Change Tracker + BMI by Age Group -->
    <div class="analytics-row-tracker">

        <!-- Status Change Tracker -->
        <div class="chart-card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Status Change Tracker</h3>
                    <p class="section-subtitle">Residents whose BMI category changed between last two records</p>
                </div>
                <span class="analytics-badge red">Alerts</span>
            </div>
            <div class="change-table-wrap">
                <table class="change-table">
                    <thead>
                        <tr>
                            <th>Resident</th>
                            <th>Previous</th>
                            <th>Current</th>
                            <th>BMI</th>
                            <th>Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($changedResidents)): ?>
                            <?php foreach ($changedResidents as $cr):
                                $prevStatus = getBmiStatus((float) $cr['prev_bmi']);
                                $currStatus = getBmiStatus((float) $cr['current_bmi']);
                                $bmiDiff = round($cr['current_bmi'] - $cr['prev_bmi'], 2);

                                $order = ['Normal' => 0, 'Underweight' => 1, 'Overweight' => 1, 'Obese' => 2];
                                $prevRisk = $order[$prevStatus] ?? 0;
                                $currRisk = $order[$currStatus] ?? 0;

                                if ($currRisk < $prevRisk) {
                                    $arrowClass = 'arrow-better';
                                    $arrow = '↑ Better';
                                } elseif ($currRisk > $prevRisk) {
                                    $arrowClass = 'arrow-worse';
                                    $arrow = '↓ Worse';
                                } else {
                                    $arrowClass = 'arrow-same';
                                    $arrow = '→ Same';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($cr['first_name'] . ' ' . $cr['last_name']) ?></strong>
                                        <div class="resident-meta"><?= htmlspecialchars($cr['gender']) ?> ·
                                            <?= (int) $cr['age'] ?> yrs
                                        </div>
                                    </td>
                                    <td><span class="badge <?= statusBadgeClass($prevStatus) ?>"><?= $prevStatus ?></span></td>
                                    <td><span class="badge <?= statusBadgeClass($currStatus) ?>"><?= $currStatus ?></span></td>
                                    <td><span
                                            class="bmi-val <?= bmiValClass($currStatus) ?>"><?= number_format((float) $cr['current_bmi'], 2) ?></span>
                                    </td>
                                    <td><span class="change-arrow <?= $arrowClass ?>"><?= $arrow ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="no-change-row">
                                <td colspan="5">No status changes detected yet. Add more health records to see comparisons.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- BMI by Age Group -->
        <div class="chart-card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">BMI by Age Group</h3>
                    <p class="section-subtitle">Average BMI per demographic bracket</p>
                </div>
                <span class="analytics-badge amber">Demographics</span>
            </div>
            <div class="demog-body">
                <?php if (!empty($ageGroups)): ?>
                    <div class="age-bars-list">
                        <?php
                        $ageColors = ['#2563eb', '#059669', '#d97706', '#ea580c', '#dc2626', '#7c3aed'];
                        $ci = 0;
                        foreach ($ageGroups as $ag):
                            $bmiVal = (float) $ag['avg_bmi'];
                            $fillPct = min(100, round(($bmiVal / 40) * 100));
                            $color = $ageColors[$ci % count($ageColors)];
                            $ci++;
                            ?>
                            <div class="age-bar-item">
                                <div class="age-bar-top">
                                    <span class="age-bar-label">
                                        <?= htmlspecialchars($ag['age_group']) ?> yrs
                                        <span class="age-bar-count">(<?= (int) $ag['resident_count'] ?>
                                            resident<?= $ag['resident_count'] > 1 ? 's' : '' ?>)</span>
                                    </span>
                                    <span class="age-bar-meta">Avg BMI <?= $bmiVal ?></span>
                                </div>
                                <div class="age-bar-track">
                                    <div class="age-bar-fill" data-width="<?= $fillPct ?>" data-color="<?= $color ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-data-text">No data available yet.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Row 3: Daily Health Records (full width) -->
    <div class="analytics-row-daily">
        <div class="chart-card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Daily Health Records - 
    <?= $range === 'all' ? 'All Time' : 'Last ' . $range . ' Days' ?>
</h3>
                    <p class="section-subtitle">Number of health assessments recorded per day</p>
                </div>
                <span class="analytics-badge"><?= $range === 'all' ? 'All Time' : 'Last ' . $range . ' Days' ?></span>
            </div>
            <div class="chart-box">
                <canvas id="dailyRecordsChart"></canvas>
            </div>
        </div>
    </div>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
    document.querySelectorAll('.age-bar-fill').forEach(el => {
        el.style.width = el.dataset.width + '%';
        el.style.background = el.dataset.color;
    });

    Chart.defaults.color = '#8899b3';
    Chart.defaults.borderColor = '#eef1f7';
    Chart.defaults.font.family = "'Plus Jakarta Sans', 'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size = 11.5;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.padding = 14;

    const newResidentLabels = <?= json_encode($newResidentLabels) ?>;
    const newResidentCounts = <?= json_encode($newResidentCounts) ?>;
    const newResidentCtx = document.getElementById('newResidentsChart').getContext('2d');

    const newResidentGradient = newResidentCtx.createLinearGradient(0, 0, 0, 220);
    newResidentGradient.addColorStop(0, 'rgba(5,150,105,0.75)');
    newResidentGradient.addColorStop(1, 'rgba(5,150,105,0.15)');

    new Chart(newResidentCtx, {
        type: 'bar',
        data: {
            labels: newResidentLabels.length ? newResidentLabels : ['No Data'],
            datasets: [{
                label: 'New Residents',
                data: newResidentCounts.length ? newResidentCounts : [0],
                backgroundColor: newResidentGradient,
                borderRadius: 5,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a1f36',
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: ctx => ` New Residents: ${ctx.parsed.y}`
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 0, minRotation: 0, font: { size: 10 } } },
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f2f8' } }
            }
        }
    });

    new Chart(document.getElementById('statusDonutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Underweight', 'Normal', 'Overweight', 'Obese'],
            datasets: [{
                data: [<?= $countUnderweight ?>, <?= $countNormal ?>, <?= $countOverweight ?>, <?= $countObese ?>],
                backgroundColor: ['#0891b2', '#16a34a', '#d97706', '#dc2626'],
                borderColor: '#ffffff',
                borderWidth: 3,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '64%',
            plugins: {
                legend: { position: 'right', labels: { font: { size: 11 }, padding: 10 } },
                tooltip: {
                    callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw} resident${ctx.raw !== 1 ? 's' : ''}` }
                }
            }
        }
    });

    const dailyLabels = <?= json_encode($dailyDates) ?>;
    const dailyCounts = <?= json_encode($dailyCounts) ?>;
    const dailyCtx = document.getElementById('dailyRecordsChart').getContext('2d');

    const dailyGradient = dailyCtx.createLinearGradient(0, 0, 0, 180);
    dailyGradient.addColorStop(0, 'rgba(37,99,235,0.7)');
    dailyGradient.addColorStop(1, 'rgba(37,99,235,0.2)');

    new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Health Records',
                data: dailyCounts,
                backgroundColor: dailyGradient,
                borderRadius: 5,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: '#1a1f36', padding: 10, cornerRadius: 8 }
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 10, maxRotation: 0 } },
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f2f8' } }
            }
        }
    });
</script>