<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['superadmin_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: login_municipal.php");
    exit();
}

// FETCH ALL RESIDENTS TOWN-WIDE + LATEST HEALTH RECORD + BARANGAY NAME
$stmt = $pdo->query("
    SELECT r.resident_id, r.first_name, r.last_name, r.gender, r.birth_date, r.address, r.phone,
           b.barangay_name,
           TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age,
           hr.weight, hr.height, hr.bmi
    FROM residents r
    LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
    LEFT JOIN (
        SELECT hr1.*
        FROM health_records hr1
        INNER JOIN (
            SELECT resident_id, MAX(record_id) AS latest_id
            FROM health_records
            GROUP BY resident_id
        ) hr2 ON hr1.resident_id = hr2.resident_id AND hr1.record_id = hr2.latest_id
    ) hr ON r.resident_id = hr.resident_id
    ORDER BY b.barangay_name ASC, r.first_name ASC
");
$residents = $stmt->fetchAll();

// FETCH ALL BARANGAYS FOR FILTER
$barangayList = $pdo->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC")->fetchAll();

$totalResidents = count($residents);

// HELPERS
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
        return 'municipal-bmi-badge';
    if ($bmi < 18.5)
        return 'municipal-bmi-badge municipal-bmi-underweight';
    if ($bmi < 25.0)
        return 'municipal-bmi-badge municipal-bmi-normal';
    if ($bmi < 30.0)
        return 'municipal-bmi-badge municipal-bmi-overweight';
    return 'municipal-bmi-badge municipal-bmi-obese';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Residents | NutriTrack Municipal</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/municipal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_municipal.php"; ?>

    <!-- jsPDF Core -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <!-- jsPDF AutoTable Plugin -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>
        window.MUNICIPAL_ADMIN_NAME = <?= json_encode($_SESSION['full_name'] ?? 'Municipal Admin') ?>;
    </script>
    <!-- Residents PDF Export -->
    <script src="../../assets/js/residents_municipal_pdf.js"></script>
    <!-- Hidden logos for jsPDF -->
    <img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
    <img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
        style="display:none;">

    <main class="municipal-dashboard">

        <div class="municipal-page-header">
            <div>
                <h1 class="municipal-page-title">All Residents</h1>
                <p class="municipal-welcome-message">Town-wide view of all registered residents across all barangays —
                    <?= $totalResidents ?> resident<?= $totalResidents !== 1 ? 's' : '' ?></p>
            </div>
        </div>

        <section class="section">

            <!-- ── Municipal Export PDF ── -->
            <div class="municipal-table-topbar">
                <button onclick="generateResidentsMunicipalPDF()" class="municipal-btn municipal-btn-export">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>


            <!-- Filter Bar -->
            <div class="municipal-filter-bar">

                <div class="municipal-filter-select-wrap">
                    <select id="residentBarangayFilter" class="municipal-form-input municipal-filter-select"
                        onchange="filterResidentsTable()">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangayList as $b): ?>
                            <option value="<?= strtolower(htmlspecialchars($b['barangay_name'])) ?>">
                                <?= htmlspecialchars($b['barangay_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                </div>

                <div class="municipal-filter-select-wrap">
                    <select id="residentGenderFilter" class="municipal-form-input municipal-filter-select"
                        onchange="filterResidentsTable()">
                        <option value="">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                    <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                </div>

                <div class="municipal-filter-select-wrap">
                    <select id="residentBmiFilter" class="municipal-form-input municipal-filter-select"
                        onchange="filterResidentsTable()">
                        <option value="">All BMI Status</option>
                        <option value="underweight">Underweight</option>
                        <option value="normal">Normal</option>
                        <option value="overweight">Overweight</option>
                        <option value="obese">Obese</option>
                    </select>
                    <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                </div>

                <div class="municipal-filter-search">
                    <i class="fas fa-magnifying-glass municipal-filter-search-icon"></i>
                    <input type="text" id="residentSearch" class="municipal-form-input municipal-filter-search-input"
                        placeholder="Search by name, address, phone…" oninput="filterResidentsTable()">
                </div>

                <span class="municipal-filter-count" id="residentCount" style="display:none;"></span>

            </div>

            <?php if (!empty($residents)): ?>
                <div class="municipal-table-wrapper">
                    <table class="municipal-table" id="residentsTable">
                        <thead class="municipal-table-head">
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Barangay</th>
                                <th>Gender</th>
                                <th>Age</th>
                                <th>Phone</th>
                                <th>Weight (kg)</th>
                                <th>Height (cm)</th>
                                <th>BMI</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody class="municipal-table-body" id="residentsTableBody">
                            <?php foreach ($residents as $index => $resident):
                                $bmiVal = isset($resident['bmi']) ? (float) $resident['bmi'] : null;
                                $bmiSlug = bmiSlug($bmiVal);
                                $initials = resInitials($resident['first_name'], $resident['last_name']);
                                $bmiBadge = resBmiBadgeClass($bmiVal);
                                $bmiLabel = resBmiLabel($bmiVal);
                                ?>
                                <tr data-name="<?= strtolower(htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'])) ?>"
                                    data-barangay="<?= strtolower(htmlspecialchars($resident['barangay_name'] ?? '')) ?>"
                                    data-gender="<?= strtolower(htmlspecialchars($resident['gender'])) ?>"
                                    data-address="<?= strtolower(htmlspecialchars($resident['address'] ?? '')) ?>"
                                    data-phone="<?= htmlspecialchars($resident['phone'] ?? '') ?>" data-bmi="<?= $bmiSlug ?>">

                                    <td><?= $index + 1 ?></td>

                                    <!-- NAME + AVATAR -->
                                    <td>
                                        <div class="municipal-table-barangay-cell">
                                            <div class="municipal-table-barangay-avatar-initials">
                                                <?= $initials ?>
                                            </div>
                                            <div>
                                                <div class="municipal-dash-cell-name">
                                                    <?= htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) ?>
                                                </div>
                                                <div class="municipal-dash-cell-sub">
                                                    <?= htmlspecialchars($resident['address'] ?? '') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td><?= htmlspecialchars($resident['barangay_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($resident['gender']) ?></td>
                                    <td><?= $resident['age'] ?> yrs</td>
                                    <td><?= htmlspecialchars($resident['phone'] ?? '—') ?></td>
                                    <td><?= isset($resident['weight']) ? rtrim(rtrim(number_format($resident['weight'], 1), '0'), '.') . ' kg' : '—' ?>
                                    </td>
                                    <td><?= isset($resident['height']) ? rtrim(rtrim(number_format($resident['height'], 1), '0'), '.') . ' cm' : '—' ?>
                                    </td>
                                    <td><?= isset($resident['bmi']) ? rtrim(rtrim(number_format($resident['bmi'], 1), '0'), '.') : '—' ?>
                                    </td>
                                    <td><span class="<?= $bmiBadge ?>"><?= $bmiLabel ?></span></td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="residentsEmptyState" style="display:none;" class="municipal-dash-empty">
                    <div class="municipal-dash-empty-icon">🔍</div>
                    <div class="municipal-dash-empty-text" id="residentsEmptyMsg">No residents match your current filters.
                    </div>
                </div>

            <?php else: ?>
                <div class="municipal-dash-empty">
                    <div class="municipal-dash-empty-icon">👥</div>
                    <div class="municipal-dash-empty-text">No residents found across all barangays.</div>
                </div>
            <?php endif; ?>

        </section>

    </main>

    <?php include "../../includes/footer.php"; ?>

    <script>
        function filterResidentsTable() {
            const barangay = (document.getElementById('residentBarangayFilter')?.value || '').toLowerCase();
            const gender = (document.getElementById('residentGenderFilter')?.value || '').toLowerCase();
            const bmi = (document.getElementById('residentBmiFilter')?.value || '').toLowerCase();
            const query = (document.getElementById('residentSearch')?.value || '').toLowerCase().trim();

            const rows = document.querySelectorAll('#residentsTableBody tr');
            const emptyEl = document.getElementById('residentsEmptyState');
            const emptyMsg = document.getElementById('residentsEmptyMsg');
            const countEl = document.getElementById('residentCount');

            let visible = 0;

            rows.forEach(row => {
                const matchBarangay = !barangay || row.dataset.barangay === barangay;
                const matchGender = !gender || row.dataset.gender === gender;
                const matchBmi = !bmi || row.dataset.bmi === bmi;
                const matchQuery = !query || [
                    row.dataset.name,
                    row.dataset.address,
                    row.dataset.phone,
                    row.dataset.barangay
                ].some(v => (v || '').includes(query));

                const show = matchBarangay && matchGender && matchBmi && matchQuery;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if (countEl) {
                countEl.textContent = visible + ' resident' + (visible !== 1 ? 's' : '');
                countEl.style.display = (barangay || gender || bmi || query) ? '' : 'none';
            }

            if (emptyEl) {
                emptyEl.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
                if (emptyMsg && visible === 0) {
                    const parts = [];
                    if (query) parts.push('"' + query + '"');
                    if (barangay) parts.push(barangay);
                    if (gender) parts.push(gender);
                    if (bmi) parts.push(bmi + ' BMI');
                    emptyMsg.textContent = parts.length
                        ? 'No residents match: ' + parts.join(' · ') + '. Try adjusting your filters.'
                        : 'No residents match your current filters.';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', filterResidentsTable);
    </script>

</body>

</html>