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
require_once __DIR__ . '/../../includes/auth_admin.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}

$barangay_id = $_SESSION['barangay_id'];

// --------------------------------------
// KPI STATS
// --------------------------------------
$totalRecords = (int) $pdo->query("SELECT COUNT(*) FROM health_records WHERE barangay_id = $barangay_id")->fetchColumn();

$totalResidentsAssessed = (int) $pdo->query("SELECT COUNT(DISTINCT resident_id) FROM health_records WHERE barangay_id = $barangay_id")->fetchColumn();

$atRiskCount = (int) $pdo->query("
    SELECT COUNT(DISTINCT hr.resident_id)
    FROM health_records hr
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id
           AND hr.record_id    = latest.latest_id
   WHERE (hr.bmi < 18.5 OR hr.bmi >= 25) AND hr.barangay_id = $barangay_id
")->fetchColumn();

$recordsThisMonth = (int) $pdo->query("
    SELECT COUNT(*) FROM health_records
    WHERE barangay_id = $barangay_id
      AND MONTH(record_date) = MONTH(CURRENT_DATE())
      AND YEAR(record_date)  = YEAR(CURRENT_DATE())
")->fetchColumn();

$recordsLastMonth = (int) $pdo->query("
    SELECT COUNT(*) FROM health_records
    WHERE barangay_id = $barangay_id
      AND MONTH(record_date) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH)
      AND YEAR(record_date)  = YEAR(CURRENT_DATE()  - INTERVAL 1 MONTH)
")->fetchColumn();

$recordsDiff = $recordsThisMonth - $recordsLastMonth;
$recordsSign = $recordsDiff >= 0 ? '+' : '';

// --------------------------------------
// FETCH ALL HEALTH WORKERS FOR FILTER (Barangay-scoped)
// --------------------------------------
$workerStmt = $pdo->prepare("
    SELECT worker_id, full_name FROM health_workers WHERE barangay_id = ? ORDER BY full_name ASC
");
$workerStmt->execute([$barangay_id]);
$allWorkers = $workerStmt->fetchAll();

// --------------------------------------
// FETCH ALL HEALTH RECORDS (Barangay-scoped)
// --------------------------------------
$records = $pdo->query("
    SELECT
        hr.record_id,
        hr.record_date,
        hr.weight,
        hr.height,
        hr.bmi,
        hr.ai_status,
        r.resident_id,
        r.first_name,
        r.last_name,
        r.gender,
        r.birth_date,
        r.address,
        hw.worker_id,
        hw.full_name  AS worker_name,
        hw.profile_image AS worker_image,
        hw.department AS worker_dept
    FROM health_records hr
    INNER JOIN residents r      ON hr.resident_id = r.resident_id
    INNER JOIN health_workers hw ON hr.worker_id   = hw.worker_id
    WHERE hr.barangay_id = $barangay_id
    ORDER BY hr.record_date DESC, hr.record_id DESC
")->fetchAll();

// --------------------------------------
// HELPERS
// --------------------------------------
function calcAge(string $birthDate): int
{
    return (int) (new DateTime($birthDate))->diff(new DateTime('today'))->y;
}

function bmiSlug(?float $bmi): string
{
    if ($bmi === null)
        return 'none';
    if ($bmi < 18.5)
        return 'underweight';
    if ($bmi < 25.0)
        return 'normal';
    if ($bmi < 30.0)
        return 'overweight';
    return 'obese';
}

function workerInitials(string $name): string
{
    $parts = explode(' ', trim($name));
    $i = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1)
        $i .= strtoupper(substr(end($parts), 0, 1));
    return $i;
}

function resInitials(string $first, string $last): string
{
    return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Health Records Overview | NutriTrack Admin</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
</head>

<body>

    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <?php include __DIR__ . '/../../includes/sidebar_admin.php'; ?>

    <!-- jsPDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script src="../../assets/js/health_records_admin_pdf.js"></script>
    <img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
    <img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
        style="display:none;">

    <main class="dashboard">

        <div class="admin-page-header">
            <div>
                <!-- <h1 class="admin-page-title">Health Records Overview</h1> -->
                <p class="admin-welcome-message">All health records across all workers · system-wide view</p>
            </div>
        </div>

        <!-- ── KPI STRIP ── -->
        <div class="admin-stats-grid">

            <div class="admin-stat-card">
                <div class="admin-stat-card-header">
                    <div class="admin-stat-content">
                        <h3 class="admin-stat-title">Total Records</h3>
                        <p class="admin-stat-value"><?= number_format($totalRecords) ?></p>
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

            <div class="admin-stat-card">
                <div class="admin-stat-card-header">
                    <div class="admin-stat-content">
                        <h3 class="admin-stat-title">Residents Assessed</h3>
                        <p class="admin-stat-value"><?= number_format($totalResidentsAssessed) ?></p>
                    </div>
                    <div class="admin-stat-icon admin-stat-icon-residents">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="admin-stat-divider"></div>
                <div class="admin-stat-footer">
                    <i class="fas fa-circle-check" style="color:#12b76a;font-size:12px;"></i>
                    Unique residents <span class="admin-stat-footer-muted">· with records</span>
                </div>
            </div>

            <div class="admin-stat-card">
                <div class="admin-stat-card-header">
                    <div class="admin-stat-content">
                        <h3 class="admin-stat-title">At-Risk Residents</h3>
                        <p class="admin-stat-value"><?= number_format($atRiskCount) ?></p>
                    </div>
                    <div class="admin-stat-icon admin-stat-icon-workers">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                </div>
                <div class="admin-stat-divider"></div>
                <div class="admin-stat-footer">
                    <i class="fas fa-circle-exclamation" style="color:#f04438;font-size:12px;"></i>
                    Require attention <span class="admin-stat-footer-muted">· latest record</span>
                </div>
            </div>

            <div class="admin-stat-card">
                <div class="admin-stat-card-header">
                    <div class="admin-stat-content">
                        <h3 class="admin-stat-title">Records This Month</h3>
                        <p class="admin-stat-value"><?= number_format($recordsThisMonth) ?></p>
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
                    <?= $recordsSign . $recordsDiff ?> from last month
                    <span class="admin-stat-footer-muted">· <?= date('F Y') ?></span>
                </div>
            </div>

        </div>

        <section class="section">

            <!-- ── TOP BAR ── -->
            <div class="admin-table-topbar">
                <button onclick="generateHealthRecordsAdminPDF()" class="admin-btn admin-btn-export">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>

            <!-- ── FILTER BAR ── -->
            <div class="admin-filter-bar">

                <select id="hrWorkerFilter" onchange="filterHRTable()">
                    <option value="">All Workers</option>
                    <?php foreach ($allWorkers as $w): ?>
                        <option value="<?= $w['worker_id'] ?>">
                            <?= htmlspecialchars($w['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="hrBmiFilter" onchange="filterHRTable()">
                    <option value="">All BMI Status</option>
                    <option value="underweight">Underweight</option>
                    <option value="normal">Normal</option>
                    <option value="overweight">Overweight</option>
                    <option value="obese">Obese</option>
                </select>

                <select id="hrGenderFilter" onchange="filterHRTable()">
                    <option value="">All Genders</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>

                <input type="month" id="hrMonthFilter" onchange="filterHRTable()" title="Filter by month"
                    class="admin-filter-date">

                <div class="admin-filter-search">
                    <input type="text" id="hrSearch" placeholder="Search by resident name, address..."
                        oninput="filterHRTable()" autocomplete="off">
                </div>

                <span class="admin-filter-count" id="hrCount" style="display:none;"></span>

            </div>

            <!-- ── TABLE ── -->
            <?php if (!empty($records)): ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table" id="hrTable">
                        <thead class="admin-table-head">
                            <tr>
                                <th>#</th>
                                <th>Resident</th>
                                <th>Gender / Age</th>
                                <th>Date</th>
                                <th>Weight</th>
                                <th>Height</th>
                                <th>BMI</th>
                                <th>Status</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody class="admin-table-body" id="hrTableBody">
                            <?php foreach ($records as $i => $rec):
                                $bmiVal = isset($rec['bmi']) ? (float) $rec['bmi'] : null;
                                $slug = bmiSlug($bmiVal);
                                $age = calcAge($rec['birth_date']);
                                $initials = resInitials($rec['first_name'], $rec['last_name']);
                                $avatarClass = $rec['gender'] === 'Female' ? 'female' : 'male';
                                $aiStatus = $rec['ai_status'] ?? '';
                                $bmiStatus = match (true) {
                                    $bmiVal === null => '',
                                    $bmiVal < 18.5 => 'Underweight',
                                    $bmiVal < 25.0 => 'Normal',
                                    $bmiVal < 30.0 => 'Overweight',
                                    default => 'Obese'
                                };
                               $displayStatus = $bmiStatus;
                                $aiDotClass = match ($aiStatus) {
                                    'Normal' => 'green',
                                    'Underweight' => 'cyan',
                                    'Overweight' => 'amber',
                                    'Obese' => 'red',
                                    default => ''
                                };
                                $workerInitials = workerInitials($rec['worker_name']);
                                ?>
                                <tr data-worker="<?= htmlspecialchars($rec['worker_id']) ?>" data-bmi="<?= $slug ?>"
                                    data-gender="<?= htmlspecialchars($rec['gender']) ?>"
                                    data-month="<?= substr($rec['record_date'], 0, 7) ?>"
                                    data-name="<?= strtolower(htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name'])) ?>"
                                    data-address="<?= strtolower(htmlspecialchars($rec['address'] ?? '')) ?>">
                                    <td class="admin-vw-td-num"><?= $i + 1 ?></td>

                                    <!-- Resident -->
                                    <td>
                                        <div class="admin-table-worker-cell">
                                            <div class="admin-table-worker-avatar-initials
                                <?= $avatarClass === 'female' ? ' female' : '' ?>">
                                                <?= $initials ?>
                                            </div>
                                            <div>
                                                <div class="dash-cell-name">
                                                    <?= htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']) ?>
                                                </div>
                                                <div class="dash-cell-sub">
                                                    <?= htmlspecialchars($rec['address'] ?? '—') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Gender / Age -->
                                    <td><?= htmlspecialchars($rec['gender']) ?>, <?= $age ?> yrs</td>

                                    <!-- Date -->
                                    <td><?= date('M d, Y', strtotime($rec['record_date'])) ?></td>

                                    <!-- Weight -->
                                    <td><?= (float) $rec['weight'] ?> kg</td>

                                    <!-- Height -->
                                    <td><?= (float) $rec['height'] ?> cm</td>

                                    <!-- BMI -->
                                    <td>
                                        <span class="admin-vw-bmi-badge admin-vw-bmi-<?= $slug ?>">
                                            <?= $bmiVal !== null ? number_format($bmiVal, 1) : '—' ?>
                                        </span>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <?php if ($displayStatus): ?>
                                            <span class="admin-hr-status-badge admin-hr-status-<?= strtolower($displayStatus) ?>">
                                                <?= htmlspecialchars($displayStatus) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#adb5bd;font-size:0.78rem;">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Recorded By -->
                                    <td>
                                        <div class="admin-table-worker-cell">
                                            <?php if (!empty($rec['worker_image']) && file_exists('../../' . $rec['worker_image'])): ?>
                                                <img src="../../<?= htmlspecialchars($rec['worker_image']) ?>"
                                                    alt="<?= htmlspecialchars($rec['worker_name']) ?>"
                                                    class="admin-table-worker-avatar">
                                            <?php else: ?>
                                                <div class="admin-table-worker-avatar-initials">
                                                    <?= $workerInitials ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="dash-cell-name">
                                                    <?= htmlspecialchars($rec['worker_name']) ?>
                                                </div>
                                                <?php if (!empty($rec['worker_dept'])): ?>
                                                    <div class="dash-cell-sub">
                                                        <?= htmlspecialchars($rec['worker_dept']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state (JS-controlled) -->
                <div id="hrEmptyState" style="display:none;" class="admin-empty-state">
                    <i class="fas fa-file-medical"></i>
                    <p id="hrEmptyMsg">No health records match your current filters.</p>
                </div>

            <?php else: ?>
                <div class="admin-empty-state">
                    <i class="fas fa-file-medical"></i>
                    <p>No health records found in the system.</p>
                </div>
            <?php endif; ?>

        </section>
    </main>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>

    <script>
        // ============================================================
        //  HEALTH RECORDS ADMIN — Filter + Search
        // ============================================================
        function filterHRTable() {
            const worker = (document.getElementById('hrWorkerFilter')?.value || '');
            const bmi = (document.getElementById('hrBmiFilter')?.value || '').toLowerCase();
            const gender = (document.getElementById('hrGenderFilter')?.value || '');
            const month = (document.getElementById('hrMonthFilter')?.value || '');
            const query = (document.getElementById('hrSearch')?.value || '').toLowerCase().trim();

            const rows = document.querySelectorAll('#hrTableBody tr');
            const emptyEl = document.getElementById('hrEmptyState');
            const emptyMsg = document.getElementById('hrEmptyMsg');
            const countEl = document.getElementById('hrCount');

            let visible = 0;
            let counter = 1;

            rows.forEach(row => {
                const matchWorker = !worker || row.dataset.worker === worker;
                const matchBmi = !bmi || row.dataset.bmi === bmi;
                const matchGender = !gender || row.dataset.gender === gender;
                const matchMonth = !month || row.dataset.month === month;
                const matchQuery = !query ||
                    row.dataset.name.includes(query) ||
                    row.dataset.address.includes(query) ||
                    row.textContent.toLowerCase().includes(query);

                const show = matchWorker && matchBmi && matchGender && matchMonth && matchQuery;
                row.style.display = show ? '' : 'none';

                if (show) {
                    const numCell = row.querySelector('.admin-vw-td-num');
                    if (numCell) numCell.textContent = counter;
                    counter++;
                    visible++;
                }
            });

            if (countEl) {
                countEl.textContent = visible + ' record' + (visible !== 1 ? 's' : '');
                countEl.style.display = (worker || bmi || gender || month || query) ? '' : 'none';
            }

            if (emptyEl) {
                if (visible === 0 && rows.length > 0) {
                    const parts = [];
                    if (query) parts.push('"' + query + '"');
                    if (bmi) parts.push(bmi + ' BMI');
                    if (gender) parts.push(gender);
                    if (month) parts.push(month);
                    if (emptyMsg) emptyMsg.textContent = parts.length
                        ? 'No records match: ' + parts.join(' · ') + '. Try adjusting your filters.'
                        : 'No health records match your current filters.';
                    emptyEl.style.display = '';
                } else {
                    emptyEl.style.display = 'none';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', filterHRTable);
    </script>

</body>

</html>