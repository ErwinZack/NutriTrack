<?php
// --------------------------------------
// DATABASE & FUNCTIONS
// --------------------------------------
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit_log.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}

// Grab logged-in worker info for audit logging
$worker_id = (int) $_SESSION['worker_id'];
$worker_full_name = $_SESSION['full_name'] ?? 'Unknown';
$barangay_id = $_SESSION['barangay_id'];

// Fetch barangay name for PDF report header
$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $barangay_id]);
$barangay_name = $brgyStmt->fetchColumn();

// --------------------------------------
// VALIDATE RESIDENT ID FROM URL
// --------------------------------------
$resident_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($resident_id <= 0) {
    header("Location: health_records.php");
    exit();
}

// --------------------------------------
// FETCH RESIDENT INFO
// --------------------------------------
$stmtResident = $pdo->prepare("
    SELECT resident_id, first_name, last_name, gender, birth_date, address, phone
    FROM residents
    WHERE resident_id = :resident_id AND barangay_id = :brgy
");
$stmtResident->execute([':resident_id' => $resident_id, ':brgy' => $barangay_id]);
$resident = $stmtResident->fetch();

if (!$resident) {
    header("Location: health_records.php");
    exit();
}

$residentFullName = htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']);

// --------------------------------------
// HANDLE ADD HEALTH RECORD (MODAL SUBMIT)
// --------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_resident_id'])) {

    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $add_error = "Invalid request. Please try again.";
    } else {
        $post_resident_id = (int) $_POST['add_resident_id'];
        $weight = (float) sanitizeInput($_POST['add_weight']);
        $height = (float) sanitizeInput($_POST['add_height']);
        $record_date = sanitizeInput($_POST['add_record_date']);

        if (empty($post_resident_id) || empty($weight) || empty($height) || empty($record_date)) {
            $add_error = "Please fill in all required fields.";
        } elseif (!validatePositiveNumber($weight)) {
            $add_error = "Weight must be a positive number.";
        } elseif (!validatePositiveNumber($height)) {
            $add_error = "Height must be a positive number.";
        } elseif (!validateDate($record_date)) {
            $add_error = "Invalid record date format.";
        } else {
            // [SECURITY] Verify resident belongs to this worker's barangay
            $checkStmt = $pdo->prepare("SELECT resident_id FROM residents WHERE resident_id = :id AND barangay_id = :brgy");
            $checkStmt->execute([':id' => $post_resident_id, ':brgy' => $barangay_id]);
            if (!$checkStmt->fetch()) {
                $add_error = "Invalid resident selected.";
            } else {
                $height_m = $height / 100;
                $bmi = round($weight / ($height_m ** 2), 2);

                $stmt = $pdo->prepare("
                    INSERT INTO health_records (resident_id, worker_id, record_date, weight, height, bmi, barangay_id)
                    VALUES (:resident_id, :worker_id, :record_date, :weight, :height, :bmi, :brgy)
                ");
                $stmt->execute([
                    ':resident_id' => $post_resident_id,
                    ':worker_id' => $worker_id,
                    ':record_date' => $record_date,
                    ':weight' => $weight,
                    ':height' => $height,
                    ':bmi' => $bmi,
                    ':brgy' => $barangay_id
                ]);

                logAudit($pdo, 'health_worker', $worker_id, 'ADD_HEALTH_RECORD', "Worker '$worker_full_name' added health record for resident ID: $post_resident_id on $record_date (Weight: {$weight}kg, Height: {$height}cm, BMI: $bmi).");

                $add_success = true;
            }
        }
    }
}

// --------------------------------------
// HANDLE EDIT HEALTH RECORD (MODAL SUBMIT)
// --------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['edit_record_id'])) {

    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $edit_error = "Invalid request. Please try again.";
    } else {
        $record_id_edit = (int) $_POST['edit_record_id'];
        $edit_resident_id = (int) $_POST['edit_resident_id'];
        $weight = (float) sanitizeInput($_POST['edit_weight']);
        $height = (float) sanitizeInput($_POST['edit_height']);
        $record_date = sanitizeInput($_POST['edit_record_date']);

        if (empty($record_id_edit) || empty($edit_resident_id) || empty($weight) || empty($height) || empty($record_date)) {
            $edit_error = "Please fill in all required fields.";
        } elseif (!validatePositiveNumber($weight)) {
            $edit_error = "Weight must be a positive number.";
        } elseif (!validatePositiveNumber($height)) {
            $edit_error = "Height must be a positive number.";
        } elseif (!validateDate($record_date)) {
            $edit_error = "Invalid record date format.";
        } else {
            // [SECURITY] Verify the selected resident belongs to this worker's barangay
            $checkStmt = $pdo->prepare("SELECT resident_id FROM residents WHERE resident_id = :id AND barangay_id = :brgy");
            $checkStmt->execute([':id' => $edit_resident_id, ':brgy' => $barangay_id]);
            if (!$checkStmt->fetch()) {
                $edit_error = "Invalid resident selected.";
            } else {
                $height_m = $height / 100;
                $bmi = round($weight / ($height_m ** 2), 2);

                $stmt = $pdo->prepare("
                    UPDATE health_records
                    SET resident_id = :resident_id,
                        record_date = :record_date,
                        weight      = :weight,
                        height      = :height,
                        bmi         = :bmi
                    WHERE record_id = :record_id AND barangay_id = :brgy
                ");
                $stmt->execute([
                    ':resident_id' => $edit_resident_id,
                    ':record_date' => $record_date,
                    ':weight' => $weight,
                    ':height' => $height,
                    ':bmi' => $bmi,
                    ':record_id' => $record_id_edit,
                    ':brgy' => $barangay_id
                ]);

                logAudit($pdo, 'health_worker', $worker_id, 'EDIT_HEALTH_RECORD', "Worker '$worker_full_name' edited health record ID: $record_id_edit for resident ID: $edit_resident_id on $record_date (Weight: {$weight}kg, Height: {$height}cm, BMI: $bmi).");

                $edit_success = true;
            }
        }
    }
}

// --------------------------------------
// FETCH THIS RESIDENT'S HEALTH RECORDS
// --------------------------------------
$stmtRecords = $pdo->prepare("
    SELECT hr.record_id, hr.resident_id, hr.record_date, hr.weight, hr.height, hr.bmi,
           r.first_name, r.last_name
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    WHERE hr.resident_id = :resident_id AND hr.barangay_id = :brgy
    ORDER BY hr.record_date DESC, hr.record_id DESC
");
$stmtRecords->execute([':resident_id' => $resident_id, ':brgy' => $barangay_id]);
$records = $stmtRecords->fetchAll();

// --------------------------------------
// FETCH ALL RESIDENTS FOR MODAL DROPDOWN
// --------------------------------------
$stmtAllResidents = $pdo->prepare("SELECT resident_id, first_name, last_name FROM residents WHERE barangay_id = :brgy ORDER BY first_name ASC");
$stmtAllResidents->execute([':brgy' => $barangay_id]);
$residents = $stmtAllResidents->fetchAll();

// --------------------------------------
// HELPER — BMI status slug
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

// --------------------------------------
// HEADER & SIDEBAR
// --------------------------------------
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_health_worker.php';
?>

<!-- Link the modal enhancement CSS -->
<link rel="stylesheet" href="residents_modal.css">

<!-- jsPDF Core -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<!-- jsPDF AutoTable Plugin -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<!-- Custom Health Records PDF Script -->
<script>
    window.CURRENT_WORKER_NAME = <?= json_encode($worker_full_name) ?>;
    window.CURRENT_BARANGAY_NAME = <?= json_encode($barangay_name ?? 'Barangay Health Center') ?>;
</script>
<script src="../../assets/js/view_resident_records_pdf.js"></script>

<!-- Hidden images for jsPDF -->
<img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
<img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
    style="display:none;">

<style>
    .rs-wrap {
        position: relative;
    }

    .rs-wrap .rs-input {
        cursor: pointer;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 14px;
        padding-right: 32px;
    }

    .rs-wrap .rs-input.rs-open {
        cursor: text;
    }

    .rs-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 3px);
        left: 0;
        right: 0;
        z-index: 9999;
        background: #fff;
        border: 1px solid #ccc;
        border-radius: 6px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.10);
        overflow: hidden;
        max-height: 260px;
        overflow-y: auto;
    }

    .rs-dropdown.rs-open {
        display: block;
    }

    .rs-option {
        padding: 9px 13px;
        font-size: 14px;
        color: #333;
        cursor: pointer;
        border-bottom: 1px solid #f5f5f5;
        transition: background 0.1s;
        font-family: inherit;
    }

    .rs-option:last-child {
        border-bottom: none;
    }

    .rs-option:hover,
    .rs-option.rs-highlighted {
        background: #e8f0fc;
        color: #1a6fdb;
    }

    .rs-option mark {
        background: none;
        color: #1a6fdb;
        font-weight: 700;
    }

    .rs-hint {
        padding: 9px 13px;
        font-size: 13px;
        color: #999;
        font-family: inherit;
        cursor: default;
    }

    .rs-dropdown::-webkit-scrollbar {
        width: 5px;
    }

    .rs-dropdown::-webkit-scrollbar-thumb {
        background: #ddd;
        border-radius: 10px;
    }

    /* Resident profile card */
    .resident-profile-card {
        display: flex;
        align-items: center;
        gap: 18px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 18px 22px;
        margin-bottom: 22px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }

    .resident-profile-avatar {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        background: #e8f0fc;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
    }

    .resident-profile-info {
        flex: 1;
    }

    .resident-profile-name {
        font-size: 17px;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 3px;
    }

    .resident-profile-meta {
        font-size: 13px;
        color: #6b7280;
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
    }

    .resident-profile-meta span {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .resident-back-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        color: #4b5563;
        text-decoration: none;
        padding: 6px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: #fff;
        transition: background 0.15s, color 0.15s;
        white-space: nowrap;
    }

    .resident-back-btn:hover {
        background: #f3f4f6;
        color: #111827;
        text-decoration: none;
    }

    /* Stats strip */
    .resident-stats-strip {
        display: flex;
        gap: 14px;
        margin-bottom: 22px;
        flex-wrap: wrap;
    }

    .resident-stat-card {
        flex: 1;
        min-width: 130px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 14px 18px;
        text-align: center;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    }

    .resident-stat-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #9ca3af;
        margin-bottom: 5px;
        font-weight: 600;
    }

    .resident-stat-value {
        font-size: 20px;
        font-weight: 700;
        color: #1a1a2e;
        line-height: 1.2;
    }

    .resident-stat-sub {
        font-size: 11px;
        color: #9ca3af;
        margin-top: 2px;
    }
</style>

<main class="dashboard">
    <h1 class="page-title">Health Records — <?= $residentFullName ?></h1>

    <!-- Resident Profile Card -->
    <div class="resident-profile-card">
        <div class="resident-profile-avatar">👤</div>
        <div class="resident-profile-info">
            <div class="resident-profile-name"><?= $residentFullName ?></div>
            <div class="resident-profile-meta">
                <?php if (!empty($resident['gender'])): ?>
                    <span><i class="fas fa-venus-mars"></i> <?= htmlspecialchars($resident['gender']) ?></span>
                <?php endif; ?>
                <?php if (!empty($resident['birth_date'])): ?>
                    <span><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($resident['birth_date']) ?></span>
                <?php endif; ?>
                <?php if (!empty($resident['address'])): ?>
                    <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($resident['address']) ?></span>
                <?php endif; ?>
                <?php if (!empty($resident['phone'])): ?>
                    <span><i class="fas fa-phone"></i> <?= htmlspecialchars($resident['phone']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <a href="health_records.php" class="resident-back-btn">
            <i class="fas fa-arrow-left"></i> Back to All Records
        </a>
    </div>

    <?php
    // Compute stats from records
    $totalRecords = count($records);
    $latestBmi = null;
    $highestBmi = null;
    $lowestBmi = null;

    if ($totalRecords > 0) {
        $latestBmi = (float) $records[0]['bmi'];
        $bmis = array_filter(array_column($records, 'bmi'), fn($v) => $v !== null);
        if ($bmis) {
            $highestBmi = max($bmis);
            $lowestBmi = min($bmis);
        }
    }
    ?>

    <!-- Stats Strip -->
    <div class="resident-stats-strip">
        <div class="resident-stat-card">
            <div class="resident-stat-label">Total Records</div>
            <div class="resident-stat-value"><?= $totalRecords ?></div>
            <div class="resident-stat-sub">all time</div>
        </div>
        <div class="resident-stat-card">
            <div class="resident-stat-label">Latest BMI</div>
            <div class="resident-stat-value"><?= $latestBmi !== null ? number_format($latestBmi, 1) : '—' ?></div>
            <div class="resident-stat-sub">
                <?= $latestBmi !== null ? ($records[0]['record_date'] ?? '') : 'no records' ?>
            </div>
        </div>
        <div class="resident-stat-card">
            <div class="resident-stat-label">Highest BMI</div>
            <div class="resident-stat-value"><?= $highestBmi !== null ? number_format($highestBmi, 1) : '—' ?></div>
            <div class="resident-stat-sub">recorded high</div>
        </div>
        <div class="resident-stat-card">
            <div class="resident-stat-label">Lowest BMI</div>
            <div class="resident-stat-value"><?= $lowestBmi !== null ? number_format($lowestBmi, 1) : '—' ?></div>
            <div class="resident-stat-sub">recorded low</div>
        </div>
    </div>

    <section class="section">
        <div class="section-header">
            <div class="header-actions-left"></div>
            <div class="header-actions-right">
                <button onclick="generateProfessionalPDF()" class="btn-print">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
                <button class="btn-add-health-record" onclick="openHealthRecordModal()">+ Add Health Record</button>
            </div>
        </div>

        <div class="rpt-filter-bar">
            <select id="recordStatusFilter" onchange="filterHealthRecordsTable()">
                <option value="">All Statuses</option>
                <option value="underweight">Underweight</option>
                <option value="normal">Normal</option>
                <option value="overweight">Overweight</option>
                <option value="obese">Obese</option>
            </select>
            <input type="month" id="recordMonthFilter" onchange="filterHealthRecordsTable()" title="Filter by month">
            <div class="rpt-search-box">
                <input type="text" id="healthRecordSearch" placeholder="Search by date, weight, height…"
                    oninput="filterHealthRecordsTable()">
            </div>
            <span class="rpt-badge-count" id="healthRecordCount">
                <?= $totalRecords ?> record<?= $totalRecords !== 1 ? 's' : '' ?>
            </span>
        </div>

        <?php if (!empty($records)): ?>
            <table class="table-default">
                <thead class="table-head">
                    <tr>
                        <!-- <th>Resident</th> -->
                        <th>Date</th>
                        <th>Weight (kg)</th>
                        <th>Height (cm)</th>
                        <th>BMI</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody class="table-body" id="healthRecordsTableBody">
                    <?php foreach ($records as $record):
                        $bmi = $record['bmi'] ?? null;

                        if ($bmi === null) {
                            $dotClass = 'gray';
                            $status = 'No Record';
                            $bmiSlug = 'none';
                        } elseif ($bmi < 18.5) {
                            $dotClass = 'yellow';
                            $status = 'Underweight';
                            $bmiSlug = 'underweight';
                        } elseif ($bmi < 25) {
                            $dotClass = 'green';
                            $status = 'Normal';
                            $bmiSlug = 'normal';
                        } elseif ($bmi < 30) {
                            $dotClass = 'orange';
                            $status = 'Overweight';
                            $bmiSlug = 'overweight';
                        } else {
                            $dotClass = 'red';
                            $status = 'Obese';
                            $bmiSlug = 'obese';
                        }

                        $recordYearMonth = substr($record['record_date'], 0, 7);
                        ?>
                        <tr id="row-<?= $record['record_id']; ?>"
                            data-name="<?= strtolower(htmlspecialchars($record['first_name'] . ' ' . $record['last_name'])) ?>"
                            data-date="<?= htmlspecialchars($record['record_date']) ?>"
                            data-month="<?= htmlspecialchars($recordYearMonth) ?>" data-bmi="<?= $bmiSlug ?>"
                            data-status="<?= htmlspecialchars($status) ?>">
                            <!-- <td><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td> -->
                            <td><?= $record['record_date']; ?></td>
                            <td><?= isset($record['weight']) ? rtrim(rtrim(number_format($record['weight'], 1), '0'), '.') . ' kg' : '-'; ?>
                            </td>
                            <td><?= isset($record['height']) ? rtrim(rtrim(number_format($record['height'], 1), '0'), '.') . ' cm' : '-'; ?>
                            </td>
                            <td><?= isset($record['bmi']) ? rtrim(rtrim(number_format($record['bmi'], 1), '0'), '.') : '-'; ?>
                            </td>
                            <td>
                                <span class="res-bmi-badge res-bmi-badge-<?= $bmiSlug; ?>">
                                    <span class="res-bmi-badge-dot"></span>
                                    <?= htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td>
                                <a href="#" class="btn-edit" title="Edit" onclick="openEditHealthModal(
                                       <?= $record['record_id']; ?>,
                                       <?= $record['resident_id']; ?>,
                                       '<?= $record['record_date']; ?>',
                                       <?= $record['weight']; ?>,
                                       <?= $record['height']; ?>
                                   ); return false;">
                                    <i class="fa fa-edit"></i>
                                </a>
                                <a href="#" class="btn-delete" title="Delete"
                                    onclick="openDeleteConfirmModal(<?= $record['record_id']; ?>, '<?= htmlspecialchars(addslashes($record['first_name'] . ' ' . $record['last_name'])); ?>'); return false;">
                                    <i class="fa fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div id="healthRecordsEmptyState" style="display:none;" class="rpt-empty">
                <div class="rpt-empty-icon">🔍</div>
                <h3>No records found</h3>
                <p id="healthRecordsEmptyMsg">No health records match your current filters.</p>
            </div>

        <?php else: ?>
            <div class="rpt-empty">
                <h3>No health records yet</h3>
                <p>This resident has no recorded health data. Click <strong>+ Add Health Record</strong> to get started.</p>
            </div>
        <?php endif; ?>
    </section>
</main>


<!-- ==============================
   ADD HEALTH RECORD MODAL
================================= -->
<div id="healthRecordModal" class="modal-overlay">
    <div class="modal-box">

        <div class="modal-header" id="addModalHeader">
            <div class="modal-header-left">
                <div class="modal-header-icon">🩺</div>
                <div class="modal-header-text">
                    <h2>Add Health Record</h2>
                    <p>Fill in the resident's latest health information</p>
                </div>
            </div>
            <span class="modal-close" onclick="closeHealthRecordModal()">&times;</span>
        </div>
        <div class="modal-progress-bar" id="addModalProgressBar">
            <div class="modal-progress-fill"></div>
        </div>

        <div class="modal-form-content" id="addFormContent">
            <form method="POST" class="form-default modal-form" id="addHealthRecordForm">

                <?= csrfInput() ?>

                <div class="modal-section-label">Resident Info</div>
                <div class="modal-form-grid modal-form-grid--single">
                    <div class="form-group">
                        <label>Select Resident <span class="text-error">*</span></label>
                        <input type="hidden" name="add_resident_id" id="add_resident_id_val">
                        <div class="rs-wrap" id="rs-add">
                            <input type="text" class="input-default rs-input" id="rs-add-input"
                                placeholder="Select resident" autocomplete="off" readonly>
                            <div class="rs-dropdown" id="rs-add-dropdown"></div>
                        </div>
                    </div>
                </div>

                <div class="modal-section-label">Health Details</div>
                <div class="modal-form-grid modal-form-grid--single">
                    <div class="form-group">
                        <label>Record Date <span class="text-error">*</span></label>
                        <input type="date" name="add_record_date" class="input-default" required>
                    </div>
                </div>

                <div class="modal-form-grid">
                    <div class="form-group">
                        <label>Weight (kg) <span class="text-error">*</span></label>
                        <input type="number" step="0.1" name="add_weight" id="add_weight" class="input-default"
                            required>
                    </div>
                    <div class="form-group">
                        <label>Height (cm) <span class="text-error">*</span></label>
                        <input type="number" step="0.1" name="add_height" id="add_height" class="input-default"
                            required>
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
                        <button type="button" class="btn-cancel" onclick="closeHealthRecordModal()">Cancel</button>
                        <button type="submit" class="btn-submit-resident">Save Record &rarr;</button>
                    </div>
                </div>

            </form>
        </div>

        <div class="modal-state" id="addSuccessState">
            <div class="s5-ring-wrap">
                <div class="s5-ring success"></div>
                <div class="s5-ring-2 success"></div>
                <div class="s5-state-icon success">
                    <svg viewBox="0 0 24 24">
                        <polyline class="s5-check-path" points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
            </div>
            <div class="s5-state-title success">Health Record Added!</div>
            <div class="s5-state-message">The health record has been successfully saved to the system.</div>
        </div>

        <div class="modal-state" id="addErrorState">
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
            <button class="s5-state-btn error-btn" onclick="resetAddModal()">Try Again</button>
        </div>

    </div>
</div>


<!-- ==============================
   EDIT HEALTH RECORD MODAL
================================= -->
<div id="editHealthModal" class="modal-overlay">
    <div class="modal-box">

        <div class="modal-header" id="editModalHeader">
            <div class="modal-header-left">
                <div class="modal-header-icon">✏️</div>
                <div class="modal-header-text">
                    <h2>Edit Health Record</h2>
                    <p>Update the resident's health information</p>
                </div>
            </div>
            <span class="modal-close" onclick="closeEditHealthModal()">&times;</span>
        </div>
        <div class="modal-progress-bar" id="editModalProgressBar">
            <div class="modal-progress-fill"></div>
        </div>

        <div class="modal-form-content" id="editFormContent">
            <form method="POST" class="form-default modal-form" id="editHealthRecordForm">
                <input type="hidden" name="edit_record_id" id="edit_record_id">

                <?= csrfInput() ?>

                <div class="modal-section-label">Resident Info</div>
                <div class="modal-form-grid modal-form-grid--single">
                    <div class="form-group">
                        <label>Select Resident <span class="text-error">*</span></label>
                        <input type="hidden" name="edit_resident_id" id="edit_resident_id">
                        <div class="rs-wrap" id="rs-edit">
                            <input type="text" class="input-default rs-input" id="rs-edit-input"
                                placeholder="Select resident" autocomplete="off" readonly>
                            <div class="rs-dropdown" id="rs-edit-dropdown"></div>
                        </div>
                    </div>
                </div>

                <div class="modal-section-label">Health Details</div>
                <div class="modal-form-grid modal-form-grid--single">
                    <div class="form-group">
                        <label>Record Date <span class="text-error">*</span></label>
                        <input type="date" name="edit_record_date" id="edit_record_date" class="input-default" required>
                    </div>
                </div>

                <div class="modal-form-grid">
                    <div class="form-group">
                        <label>Weight (kg) <span class="text-error">*</span></label>
                        <input type="number" step="0.1" name="edit_weight" id="edit_weight" class="input-default"
                            required>
                    </div>
                    <div class="form-group">
                        <label>Height (cm) <span class="text-error">*</span></label>
                        <input type="number" step="0.1" name="edit_height" id="edit_height" class="input-default"
                            required>
                    </div>
                </div>

                <div class="modal-form-grid modal-form-grid--single">
                    <div class="form-group">
                        <label>BMI (Auto-calculated)</label>
                        <input type="text" name="edit_bmi" id="edit_bmi" class="input-default" readonly>
                    </div>
                </div>

                <div class="modal-actions">
                    <span class="modal-required-note">* Required fields</span>
                    <div class="modal-actions-buttons">
                        <button type="button" class="btn-cancel" onclick="closeEditHealthModal()">Cancel</button>
                        <button type="submit" class="btn-submit-resident">Update Record &rarr;</button>
                    </div>
                </div>

            </form>
        </div>

        <div class="modal-state" id="editSuccessState">
            <div class="s5-ring-wrap">
                <div class="s5-ring success"></div>
                <div class="s5-ring-2 success"></div>
                <div class="s5-state-icon success">
                    <svg viewBox="0 0 24 24">
                        <polyline class="s5-check-path" points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
            </div>
            <div class="s5-state-title success">Health Record Updated!</div>
            <div class="s5-state-message">The health record has been successfully updated.</div>
        </div>

        <div class="modal-state" id="editErrorState">
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
            <button class="s5-state-btn error-btn" onclick="resetEditModal()">Try Again</button>
        </div>

    </div>
</div>


<!-- ==============================
   DELETE CONFIRMATION MODAL
================================= -->
<div id="deleteConfirmModal" class="modal-overlay">
    <div class="modal-box">

        <div class="modal-state active" id="deleteConfirmState">
            <div class="modal-state-icon warning">
                <svg viewBox="0 0 24 24">
                    <line class="x-path" x1="12" y1="8" x2="12" y2="12"></line>
                    <line class="x-path" x1="12" y1="16" x2="12.01" y2="16"></line>
                    <path class="x-path"
                        d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z">
                    </path>
                </svg>
            </div>
            <div class="modal-state-title warning">Are you sure?</div>
            <div class="modal-state-message" id="deleteConfirmMessage">
                You are about to delete this health record. This action cannot be undone.
            </div>
            <div class="modal-state-actions">
                <button class="modal-state-btn cancel-btn" onclick="closeDeleteConfirmModal()">Cancel</button>
                <button class="modal-state-btn delete-btn" id="confirmDeleteBtn" onclick="executeDelete()">
                    Yes, Delete
                </button>
            </div>
        </div>

        <div class="modal-state" id="deleteSuccessState">
            <div class="s5-ring-wrap">
                <div class="s5-ring success"></div>
                <div class="s5-ring-2 success"></div>
                <div class="s5-state-icon success">
                    <svg viewBox="0 0 24 24">
                        <polyline class="s5-check-path" points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
            </div>
            <div class="s5-state-title success">Deleted!</div>
            <div class="s5-state-message">The health record has been successfully removed.</div>
        </div>

        <div class="modal-state" id="deleteErrorState">
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
            <div class="s5-state-message">Could not delete the health record. Please try again.</div>
            <button class="s5-state-btn error-btn" onclick="closeDeleteConfirmModal()">Close</button>
        </div>

    </div>
</div>


<script>
    // --------------------------------------
    // FILTER & SEARCH
    // --------------------------------------
    function _matches(query, ...sources) {
        if (!query) return true;
        return sources.some(s => (s || '').toLowerCase().includes(query));
    }

    function filterHealthRecordsTable() {
        const status = (document.getElementById('recordStatusFilter')?.value || '').toLowerCase();
        const month = (document.getElementById('recordMonthFilter')?.value || '');
        const query = (document.getElementById('healthRecordSearch')?.value || '').toLowerCase().trim();

        const rows = document.querySelectorAll('#healthRecordsTableBody tr');
        const emptyEl = document.getElementById('healthRecordsEmptyState');
        const emptyMsg = document.getElementById('healthRecordsEmptyMsg');
        const countEl = document.getElementById('healthRecordCount');

        let visible = 0;

        rows.forEach(row => {
            const matchStatus = !status || (row.dataset.bmi || '') === status;
            const matchMonth = !month || (row.dataset.month || '') === month;
            const matchQuery = _matches(
                query,
                row.dataset.name,
                row.dataset.date,
                row.dataset.status,
                row.textContent
            );

            const show = matchStatus && matchMonth && matchQuery;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (countEl) {
            countEl.textContent = visible + ' record' + (visible !== 1 ? 's' : '');
            countEl.style.display = (status || month || query) ? '' : 'none';
        }

        if (emptyEl) {
            if (visible === 0 && rows.length > 0) {
                const parts = [];
                if (query) parts.push('"' + query + '"');
                if (status) parts.push(status + ' BMI');
                if (month) parts.push('month: ' + month);
                if (emptyMsg) emptyMsg.textContent = parts.length
                    ? 'No records match: ' + parts.join(' · ') + '. Try adjusting your filters.'
                    : 'No health records match your current filters.';
                emptyEl.style.display = '';
            } else {
                emptyEl.style.display = 'none';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', filterHealthRecordsTable);

    // --------------------------------------
    // MODAL HELPERS
    // --------------------------------------
    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    function autoClose(closeCallback) {
        setTimeout(() => { closeCallback(); }, 2100);
    }

    function showState(formContentId, stateId, headerId, progressBarId) {
        const content = document.getElementById(formContentId);
        const state = document.getElementById(stateId);
        const header = headerId ? document.getElementById(headerId) : null;
        const progressBar = progressBarId ? document.getElementById(progressBarId) : null;

        if (header) header.style.display = 'none';
        if (progressBar) progressBar.style.display = 'none';

        if (content) {
            content.classList.add('fade-out');
            setTimeout(() => {
                content.style.display = 'none';
                state.classList.add('active');
            }, 220);
        } else {
            state.classList.add('active');
        }
    }

    function resetState(formContentId, stateId, headerId, progressBarId) {
        const content = document.getElementById(formContentId);
        const state = document.getElementById(stateId);
        const header = headerId ? document.getElementById(headerId) : null;
        const progressBar = progressBarId ? document.getElementById(progressBarId) : null;

        state.classList.remove('active');
        if (content) {
            content.style.display = '';
            content.classList.remove('fade-out');
        }
        if (header) header.style.display = '';
        if (progressBar) progressBar.style.display = '';
    }

    // --------------------------------------
    // ADD MODAL
    // --------------------------------------
    function openHealthRecordModal() {
        resetAddModal();
        // Pre-select current resident in dropdown
        if (rsAdd) {
            rsAdd.setValue(<?= $resident_id ?>, <?= json_encode($resident['first_name'] . ' ' . $resident['last_name']) ?>);
        }
        openModal('healthRecordModal');
    }

    function closeHealthRecordModal() {
        closeModal('healthRecordModal');
        setTimeout(resetAddModal, 300);
    }

    function resetAddModal() {
        resetState('addFormContent', 'addSuccessState', 'addModalHeader', 'addModalProgressBar');
        resetState('addFormContent', 'addErrorState', 'addModalHeader', 'addModalProgressBar');
        document.getElementById('addHealthRecordForm').reset();
        if (rsAdd) rsAdd.clear();
    }

    <?php if (!empty($add_success)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('healthRecordModal');
            showState('addFormContent', 'addSuccessState', 'addModalHeader', 'addModalProgressBar');
            autoClose(closeHealthRecordModal);
        });
    <?php elseif (!empty($add_error)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('healthRecordModal');
            showState('addFormContent', 'addErrorState', 'addModalHeader', 'addModalProgressBar');
        });
    <?php endif; ?>

    // --------------------------------------
    // EDIT MODAL
    // --------------------------------------
    function openEditHealthModal(record_id, resident_id, record_date, weight, height) {
        resetEditModal();
        document.getElementById('edit_record_id').value = record_id;
        document.getElementById('edit_record_date').value = record_date;
        document.getElementById('edit_weight').value = weight;
        document.getElementById('edit_height').value = height;

        if (rsEdit) {
            const found = RS_RESIDENTS ? RS_RESIDENTS.find(r => r.id == resident_id) : null;
            rsEdit.setValue(resident_id, found ? found.name : '');
        } else {
            document.getElementById('edit_resident_id').value = resident_id;
        }

        if (weight && height) {
            document.getElementById('edit_bmi').value = (weight / ((height / 100) ** 2)).toFixed(1);
        } else {
            document.getElementById('edit_bmi').value = '';
        }

        openModal('editHealthModal');
    }

    function closeEditHealthModal() {
        closeModal('editHealthModal');
        setTimeout(resetEditModal, 300);
    }

    function resetEditModal() {
        resetState('editFormContent', 'editSuccessState', 'editModalHeader', 'editModalProgressBar');
        resetState('editFormContent', 'editErrorState', 'editModalHeader', 'editModalProgressBar');
        document.getElementById('editHealthRecordForm').reset();
        if (rsEdit) rsEdit.clear();
    }

    <?php if (!empty($edit_success)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('editHealthModal');
            showState('editFormContent', 'editSuccessState', 'editModalHeader', 'editModalProgressBar');
            autoClose(closeEditHealthModal);
        });
    <?php elseif (!empty($edit_error)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('editHealthModal');
            showState('editFormContent', 'editErrorState', 'editModalHeader', 'editModalProgressBar');
        });
    <?php endif; ?>

    // --------------------------------------
    // DELETE MODAL
    // --------------------------------------
    let pendingDeleteId = null;

    function openDeleteConfirmModal(id, name) {
        pendingDeleteId = id;

        document.getElementById('deleteConfirmState').classList.add('active');
        document.getElementById('deleteSuccessState').classList.remove('active');
        document.getElementById('deleteErrorState').classList.remove('active');

        document.getElementById('deleteConfirmMessage').textContent =
            'You are about to delete the health record for "' + name + '". This action cannot be undone.';

        openModal('deleteConfirmModal');
    }

    function closeDeleteConfirmModal() {
        closeModal('deleteConfirmModal');
        pendingDeleteId = null;
    }

    function executeDelete() {
        if (!pendingDeleteId) return;

        const confirmBtn = document.getElementById('confirmDeleteBtn');
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Deleting...';

        fetch('delete_health_record.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: pendingDeleteId })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('deleteConfirmState').classList.remove('active');
                    document.getElementById('deleteSuccessState').classList.add('active');

                    const row = document.getElementById('row-' + pendingDeleteId);
                    if (row) {
                        row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(20px)';
                        setTimeout(() => {
                            row.remove();
                            filterHealthRecordsTable();
                        }, 400);
                    }

                    autoClose(closeDeleteConfirmModal);
                } else {
                    document.getElementById('deleteConfirmState').classList.remove('active');
                    document.getElementById('deleteErrorState').classList.add('active');
                }
            })
            .catch(() => {
                document.getElementById('deleteConfirmState').classList.remove('active');
                document.getElementById('deleteErrorState').classList.add('active');
            })
            .finally(() => {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Yes, Delete';
            });
    }

    // --------------------------------------
    // BMI AUTO-CALCULATE
    // --------------------------------------
    const addWeightInput = document.getElementById('add_weight');
    const addHeightInput = document.getElementById('add_height');
    const addBmiInput = document.getElementById('add_bmi');

    function calculateAddBMI() {
        const weight = parseFloat(addWeightInput.value);
        const height = parseFloat(addHeightInput.value);
        addBmiInput.value = weight && height ? (weight / ((height / 100) ** 2)).toFixed(1) : '';
    }

    if (addWeightInput) addWeightInput.addEventListener('input', calculateAddBMI);
    if (addHeightInput) addHeightInput.addEventListener('input', calculateAddBMI);

    const editWeightInput = document.getElementById('edit_weight');
    const editHeightInput = document.getElementById('edit_height');
    const editBmiInput = document.getElementById('edit_bmi');

    function calculateEditBMI() {
        const weight = parseFloat(editWeightInput.value);
        const height = parseFloat(editHeightInput.value);
        editBmiInput.value = weight && height ? (weight / ((height / 100) ** 2)).toFixed(1) : '';
    }

    if (editWeightInput) editWeightInput.addEventListener('input', calculateEditBMI);
    if (editHeightInput) editHeightInput.addEventListener('input', calculateEditBMI);

    // --------------------------------------
    // RESIDENT SEARCHABLE SELECT
    // --------------------------------------
    const RS_RESIDENTS = [
        <?php foreach ($residents as $r): ?>
                { id: <?= (int) $r['resident_id'] ?>, name: <?= json_encode($r['first_name'] . ' ' . $r['last_name']) ?> },
        <?php endforeach; ?>
    ];

    const MAX_DEFAULT = 10;

    function makeResidentSelect(wrapId, inputId, dropdownId, hiddenId) {
        const wrap = document.getElementById(wrapId);
        const input = document.getElementById(inputId);
        const dropdown = document.getElementById(dropdownId);
        const hidden = document.getElementById(hiddenId);

        if (!wrap || !input || !dropdown || !hidden) return;

        let isOpen = false;
        let highlighted = -1;

        function render(query) {
            dropdown.innerHTML = '';
            highlighted = -1;

            const q = (query || '').toLowerCase().trim();
            const matched = q
                ? RS_RESIDENTS.filter(r => r.name.toLowerCase().includes(q))
                : RS_RESIDENTS.slice(0, MAX_DEFAULT);

            if (!matched.length) {
                const hint = document.createElement('div');
                hint.className = 'rs-hint';
                hint.textContent = q ? 'No residents match "' + query + '"' : 'No residents found.';
                dropdown.appendChild(hint);
                return;
            }

            if (!q) {
                const hint = document.createElement('div');
                hint.className = 'rs-hint';
                hint.textContent = 'Showing ' + matched.length + ' of ' + RS_RESIDENTS.length + ' — type to search';
                dropdown.appendChild(hint);
            }

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

                div.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    selectResident(r.id, r.name);
                });

                dropdown.appendChild(div);
            });
        }

        function selectResident(id, name) {
            hidden.value = id;
            input.value = name;
            closeDropdown();
        }

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

        input.addEventListener('click', () => {
            if (!isOpen) openDropdown();
        });

        input.addEventListener('input', () => {
            if (!isOpen) openDropdown();
            render(input.value);
        });

        input.addEventListener('keydown', (e) => {
            if (!isOpen && (e.key === 'ArrowDown' || e.key === 'Enter')) {
                openDropdown();
                return;
            }
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

        document.addEventListener('mousedown', (e) => {
            if (!wrap.contains(e.target)) closeDropdown();
        });

        return {
            setValue(id, name) {
                hidden.value = id;
                input.value = name;
                input.dataset.selected = name;
            },
            clear() {
                hidden.value = '';
                input.value = '';
                input.dataset.selected = '';
                closeDropdown();
            }
        };
    }

    let rsAdd = null;
    let rsEdit = null;

    document.addEventListener('DOMContentLoaded', () => {
        rsAdd = makeResidentSelect('rs-add', 'rs-add-input', 'rs-add-dropdown', 'add_resident_id_val');
        rsEdit = makeResidentSelect('rs-edit', 'rs-edit-input', 'rs-edit-dropdown', 'edit_resident_id');
    });
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>