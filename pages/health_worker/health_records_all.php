<?php
// --------------------------------------
// DATABASE & FUNCTIONS
// --------------------------------------
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}

$worker_id = (int) $_SESSION['worker_id'];
$barangay_id = $_SESSION['barangay_id'];

// Fetch barangay name
$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $barangay_id]);
$barangay_name = $brgyStmt->fetchColumn();

// --------------------------------------
// SELECTED MONTH (from Reports page)
// --------------------------------------
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$filterYear = date('Y', strtotime($selectedMonth . '-01'));
$filterMonth = date('n', strtotime($selectedMonth . '-01'));
$monthLabel = date('F Y', strtotime($selectedMonth . '-01'));

// --------------------------------------
// FETCH ALL RECORDS FOR SELECTED MONTH
// --------------------------------------
$stmt = $pdo->prepare("
    SELECT hr.record_id, hr.record_date, hr.weight, hr.height, hr.bmi,
           r.first_name, r.last_name, r.gender, r.address, r.birth_date,
           hw.full_name AS worker_name
    FROM health_records hr
    INNER JOIN residents r       ON hr.resident_id = r.resident_id
    INNER JOIN health_workers hw ON hr.worker_id   = hw.worker_id
    WHERE hr.barangay_id = :brgy
      AND YEAR(hr.record_date)  = :y
      AND MONTH(hr.record_date) = :m
    ORDER BY hr.record_date DESC
");
$stmt->execute([':brgy' => $barangay_id, ':y' => $filterYear, ':m' => $filterMonth]);
$records = $stmt->fetchAll();

// --------------------------------------
// HELPERS
// --------------------------------------
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
function resInitials(string $first, string $last): string
{
    return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
}
function resBmiValueClass(?float $bmi): string
{
    if ($bmi === null)
        return 'res-bmi-value-none';
    if ($bmi < 18.5)
        return 'res-bmi-value-underweight';
    if ($bmi < 25.0)
        return 'res-bmi-value-normal';
    if ($bmi < 30.0)
        return 'res-bmi-value-overweight';
    return 'res-bmi-value-obese';
}
function resBmiLabel(?float $bmi): string
{
    if ($bmi === null)
        return 'No Data';
    if ($bmi < 18.5)
        return 'Underweight';
    if ($bmi < 25.0)
        return 'Normal';
    if ($bmi < 30.0)
        return 'Overweight';
    return 'Obese';
}
function resBmiBadgeClass(?float $bmi): string
{
    if ($bmi === null)
        return 'res-bmi-badge-none';
    if ($bmi < 18.5)
        return 'res-bmi-badge-underweight';
    if ($bmi < 25.0)
        return 'res-bmi-badge-normal';
    if ($bmi < 30.0)
        return 'res-bmi-badge-overweight';
    return 'res-bmi-badge-obese';
}
function calcAge(string $birthDate): int
{
    return (int) (new DateTime($birthDate))->diff(new DateTime('today'))->y;
}

// --------------------------------------
// HEADER & SIDEBAR
// --------------------------------------
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_health_worker.php';
?>

<main class="dashboard">

    <!-- PAGE HEADER -->
    <div class="reports-page-header">
        <div>
            <h1 class="page-title">Health Records · <?= htmlspecialchars($monthLabel) ?></h1>
            <p class="reports-page-subtitle">
                All records for <?= htmlspecialchars($monthLabel) ?> · Barangay <?= htmlspecialchars($barangay_name) ?>
            </p>
        </div>
        <div class="reports-header-actions">
            <?php $backUrl = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'dashboard_health_worker.php'; ?>
            <a href="<?= htmlspecialchars($backUrl) ?>" class="rpt-btn-secondary">
                ← Back
            </a>
        </div>
    </div>

    <section class="section">

        <!-- FILTER BAR -->
        <div class="rpt-filter-bar">
            <select id="recordStatusFilter" onchange="filterTable()">
                <option value="">All Statuses</option>
                <option value="underweight">Underweight</option>
                <option value="normal">Normal</option>
                <option value="overweight">Overweight</option>
                <option value="obese">Obese</option>
            </select>
            <div class="rpt-search-box">
                <input type="text" id="recordSearch" placeholder="Search by resident, date, weight, height…"
                    oninput="filterTable()">
            </div>
            <span class="rpt-badge-count" id="recordCount"></span>
        </div>

        <?php if (!empty($records)): ?>
            <table class="table-default">
                <thead class="table-head">
                    <tr>
                        <th>Resident</th>
                        <th>Age</th>
                        <th>Date</th>
                        <th>Weight (kg)</th>
                        <th>Height (cm)</th>
                        <th>BMI</th>
                        <th>Status</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody class="table-body" id="recordsTableBody">
                    <?php foreach ($records as $record):
                        $bmiVal = isset($record['bmi']) ? (float) $record['bmi'] : null;
                        $bmiSlugVal = bmiSlug($bmiVal);
                        $initials = resInitials($record['first_name'], $record['last_name']);
                        $avatarClass = $record['gender'] === 'Female' ? 'res-avatar-female' : 'res-avatar-male';
                        $bmiValClass = resBmiValueClass($bmiVal);
                        $bmiBadge = resBmiBadgeClass($bmiVal);
                        $bmiLabel = resBmiLabel($bmiVal);
                        $age = calcAge($record['birth_date']);
                        ?>
                        <tr data-name="<?= strtolower(htmlspecialchars($record['first_name'] . ' ' . $record['last_name'])) ?>"
                            data-date="<?= htmlspecialchars($record['record_date']) ?>" data-bmi="<?= $bmiSlugVal ?>"
                            data-address="<?= strtolower(htmlspecialchars($record['address'] ?? '')) ?>"
                            data-status="<?= htmlspecialchars($bmiLabel) ?>">

                            <!-- RESIDENT -->
                            <td>
                                <div class="res-cell">
                                    <div class="res-avatar <?= $avatarClass ?>">
                                        <?= $initials ?>
                                    </div>
                                    <div>
                                        <div class="res-cell-name">
                                            <?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?>
                                        </div>
                                        <div class="res-cell-addr">
                                            <?= htmlspecialchars($record['address'] ?? '') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- AGE -->
                            <td><?= $age ?> yrs</td>

                            <!-- DATE -->
                            <td><?= htmlspecialchars($record['record_date']) ?></td>

                            <!-- WEIGHT -->
                            <td><?= isset($record['weight']) ? rtrim(rtrim(number_format($record['weight'], 1), '0'), '.') . ' kg' : '-' ?>
                            </td>

                            <!-- HEIGHT -->
                            <td><?= isset($record['height']) ? rtrim(rtrim(number_format($record['height'], 1), '0'), '.') . ' cm' : '-' ?>
                            </td>

                            <!-- BMI -->
                            <td>
                                <span class="<?= $bmiValClass ?>">
                                    <?= isset($record['bmi']) ? rtrim(rtrim(number_format($record['bmi'], 1), '0'), '.') : '-' ?>
                                </span>
                            </td>

                            <!-- STATUS BADGE -->
                            <td>
                                <span class="res-bmi-badge <?= $bmiBadge ?>">
                                    <span class="res-bmi-badge-dot"></span>
                                    <?= $bmiLabel ?>
                                </span>
                            </td>

                            <!-- RECORDED BY -->
                            <td><?= htmlspecialchars($record['worker_name']) ?></td>

                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div id="recordsEmptyState" style="display:none;" class="rpt-empty">
                <div class="rpt-empty-icon">🔍</div>
                <h3>No records found</h3>
                <p id="recordsEmptyMsg">No health records match your current filters.</p>
            </div>

        <?php else: ?>
            <div class="rpt-empty">
                <div class="rpt-empty-icon">📋</div>
                <h3>No Records This Month</h3>
                <p>No health records found for <?= htmlspecialchars($monthLabel) ?>.</p>
            </div>
        <?php endif; ?>

    </section>
</main>

<script>
    function _matches(query, ...sources) {
        if (!query) return true;
        return sources.some(s => (s || '').toLowerCase().includes(query));
    }

    function filterTable() {
        const status = (document.getElementById('recordStatusFilter')?.value || '').toLowerCase();
        const query = (document.getElementById('recordSearch')?.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('#recordsTableBody tr');
        const emptyEl = document.getElementById('recordsEmptyState');
        const emptyMsg = document.getElementById('recordsEmptyMsg');
        const countEl = document.getElementById('recordCount');

        let visible = 0;

        rows.forEach(row => {
            const matchStatus = !status || (row.dataset.bmi || '') === status;
            const matchQuery = _matches(query, row.dataset.name, row.dataset.date,
                row.dataset.address, row.dataset.status, row.textContent);
            const show = matchStatus && matchQuery;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (countEl) {
            countEl.textContent = visible + ' record' + (visible !== 1 ? 's' : '');
            countEl.style.display = (status || query) ? '' : 'none';
        }

        if (emptyEl) {
            if (visible === 0 && rows.length > 0) {
                const parts = [];
                if (query) parts.push('"' + query + '"');
                if (status) parts.push(status + ' BMI');
                if (emptyMsg) emptyMsg.textContent = parts.length
                    ? 'No records match: ' + parts.join(' · ') + '. Try adjusting your filters.'
                    : 'No health records match your current filters.';
                emptyEl.style.display = '';
            } else {
                emptyEl.style.display = 'none';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', filterTable);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>