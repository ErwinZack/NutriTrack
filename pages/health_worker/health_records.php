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
// Grab logged-in worker info for audit logging
$worker_id = (int) $_SESSION['worker_id'];

// Fetch fresh from DB to avoid session being empty
$nameStmt = $pdo->prepare("SELECT full_name FROM health_workers WHERE worker_id = :id LIMIT 1");
$nameStmt->execute([':id' => $worker_id]);
$nameRow = $nameStmt->fetch();
$worker_full_name = $nameRow['full_name'] ?? 'Unknown';
$barangay_id = $_SESSION['barangay_id'];

// Fetch barangay name for PDF report header
$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $barangay_id]);
$barangay_name = $brgyStmt->fetchColumn();

// --------------------------------------
// HANDLE ADD HEALTH RECORD (MODAL SUBMIT)
// --------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_resident_id'])) {

    // [SECURITY] Verify CSRF token
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $add_error = "Invalid request. Please try again.";
    } else {
        // [SECURITY] Sanitize inputs
        $resident_id = (int) $_POST['add_resident_id'];
        $weight = (float) sanitizeInput($_POST['add_weight']);
        $height = (float) sanitizeInput($_POST['add_height']);
        $record_date = sanitizeInput($_POST['add_record_date']);

        // [SECURITY] Server-side validation
        if (empty($resident_id) || empty($weight) || empty($height) || empty($record_date)) {
            $add_error = "Please fill in all required fields.";
        } elseif (!validatePositiveNumber($weight)) {
            $add_error = "Weight must be a positive number.";
        } elseif (!validatePositiveNumber($height)) {
            $add_error = "Height must be a positive number.";
        } elseif (!validateDate($record_date)) {
            $add_error = "Invalid record date format.";
        } else {
            $height_m = $height / 100;
            $bmi = round($weight / ($height_m ** 2), 2);

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
                ':brgy' => $barangay_id
            ]);

            // [SECURITY] Log add health record action
            logAudit($pdo, 'health_worker', $worker_id, 'ADD_HEALTH_RECORD', "Worker '$worker_full_name' added health record for resident ID: $resident_id on $record_date (Weight: {$weight}kg, Height: {$height}cm, BMI: $bmi).");

            $add_success = true;

            // Notify admin — health worker added a health record
            $rStmt = $pdo->prepare("SELECT first_name, last_name FROM residents WHERE resident_id = :id LIMIT 1");
            $rStmt->execute([':id' => $resident_id]);
            $rRow = $rStmt->fetch();
            $residentName = $rRow ? $rRow['first_name'] . ' ' . $rRow['last_name'] : 'Unknown';

            $adminStmt = $pdo->query("SELECT admin_id FROM admins");
            foreach ($adminStmt->fetchAll() as $admin) {
                $nStmt = $pdo->prepare("INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message) VALUES (:id, 'admin', :sid, 'health_worker', 'info', :msg)");
                $nStmt->execute([':id' => $admin['admin_id'], ':sid' => $worker_id, ':msg' => "$worker_full_name added a health record for $residentName."]);
            }
        }
    }
}

// --------------------------------------
// HANDLE EDIT HEALTH RECORD (MODAL SUBMIT)
// --------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['edit_record_id'])) {

    // [SECURITY] Verify CSRF token
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $edit_error = "Invalid request. Please try again.";
    } else {
        // [SECURITY] Sanitize inputs
        $record_id = (int) $_POST['edit_record_id'];
        $resident_id = (int) $_POST['edit_resident_id'];
        $weight = (float) sanitizeInput($_POST['edit_weight']);
        $height = (float) sanitizeInput($_POST['edit_height']);
        $record_date = sanitizeInput($_POST['edit_record_date']);

        // [SECURITY] Server-side validation
        if (empty($record_id) || empty($resident_id) || empty($weight) || empty($height) || empty($record_date)) {
            $edit_error = "Please fill in all required fields.";
        } elseif (!validatePositiveNumber($weight)) {
            $edit_error = "Weight must be a positive number.";
        } elseif (!validatePositiveNumber($height)) {
            $edit_error = "Height must be a positive number.";
        } elseif (!validateDate($record_date)) {
            $edit_error = "Invalid record date format.";
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
                WHERE record_id = :record_id
            ");
            $stmt->execute([
                ':resident_id' => $resident_id,
                ':record_date' => $record_date,
                ':weight' => $weight,
                ':height' => $height,
                ':bmi' => $bmi,
                ':record_id' => $record_id
            ]);

            // [SECURITY] Log edit health record action
            logAudit($pdo, 'health_worker', $worker_id, 'EDIT_HEALTH_RECORD', "Worker '$worker_full_name' edited health record ID: $record_id for resident ID: $resident_id on $record_date (Weight: {$weight}kg, Height: {$height}cm, BMI: $bmi).");

            $edit_success = true;
        }
    }
}

// --------------------------------------
// FETCH HEALTH RECORDS WITH RESIDENT INFO
// --------------------------------------
$stmt = $pdo->prepare("
    SELECT hr.record_id, hr.resident_id, hr.record_date, hr.weight, hr.height, hr.bmi,
           r.first_name, r.last_name, r.gender, r.address
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    INNER JOIN (
        SELECT MAX(record_id) AS latest_id
        FROM health_records
        WHERE barangay_id = :brgy
        GROUP BY resident_id
    ) latest ON hr.record_id = latest.latest_id
    WHERE hr.barangay_id = :brgy2
    ORDER BY hr.record_date DESC
");
$stmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id]);
$records = $stmt->fetchAll();

// --------------------------------------
// FETCH RESIDENTS FOR MODAL DROPDOWN
// --------------------------------------
$stmt = $pdo->prepare("SELECT resident_id, first_name, last_name FROM residents WHERE barangay_id = :brgy ORDER BY first_name ASC");
$stmt->execute([':brgy' => $barangay_id]);
$residents = $stmt->fetchAll();

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
<script src="../../assets/js/health_records_pdf.js"></script>

<!-- Hidden images for jsPDF -->
<img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
<img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
    style="display:none;">



<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>


<main class="dashboard">
    <h1 class="page-title">Health Records</h1>

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
                <input type="text" id="healthRecordSearch" placeholder="Search by resident, date, weight, height…"
                    oninput="filterHealthRecordsTable()">
            </div>
            <span class="rpt-badge-count" id="healthRecordCount"></span>
        </div>

        <?php if (!empty($records)): ?>
            <table class="table-default">
                <thead class="table-head">
                    <tr>
                        <th>Resident</th>
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
                        $bmiVal = isset($record['bmi']) ? (float) $record['bmi'] : null;
                        $bmiSlug = bmiSlug($bmiVal);
                        $initials = resInitials($record['first_name'], $record['last_name']);
                        $avatarClass = $record['gender'] === 'Female' ? 'res-avatar-female' : 'res-avatar-male';
                        $bmiValClass = resBmiValueClass($bmiVal);
                        $bmiBadge = resBmiBadgeClass($bmiVal);
                        $bmiLabel = resBmiLabel($bmiVal);
                        $recordYearMonth = substr($record['record_date'], 0, 7);
                        ?>
                        <tr id="row-<?= $record['record_id']; ?>"
                            data-name="<?= strtolower(htmlspecialchars($record['first_name'] . ' ' . $record['last_name'])) ?>"
                            data-date="<?= htmlspecialchars($record['record_date']) ?>"
                            data-month="<?= htmlspecialchars($recordYearMonth) ?>" data-bmi="<?= $bmiSlug ?>"
                            data-address="<?= strtolower(htmlspecialchars($record['address'] ?? '')) ?>"
                            data-status="<?= htmlspecialchars($bmiLabel) ?>">

                            <!-- NAME + AVATAR + ADDRESS -->
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

                            <!-- DATE -->
                            <td><?= htmlspecialchars($record['record_date']); ?></td>

                            <!-- WEIGHT -->
                            <td><?= isset($record['weight']) ? rtrim(rtrim(number_format($record['weight'], 1), '0'), '.') . ' kg' : '-'; ?>
                            </td>

                            <!-- HEIGHT -->
                            <td><?= isset($record['height']) ? rtrim(rtrim(number_format($record['height'], 1), '0'), '.') . ' cm' : '-'; ?>
                            </td>

                            <!-- BMI — color coded number -->
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

                            <!-- ACTIONS -->
                            <td>
                                <a href="view_resident_records.php?id=<?= $record['resident_id']; ?>" class="btn-view"
                                    title="View All Records">
                                    <i class="fa fa-eye"></i>
                                </a>
                                <!-- <a href="#" class="btn-edit" title="Edit"
                                   onclick="openEditHealthModal(
                                       <?= $record['record_id']; ?>,
                                       <?= $record['resident_id']; ?>,
                                       '<?= $record['record_date']; ?>',
                                       <?= $record['weight']; ?>,
                                       <?= $record['height']; ?>
                                   ); return false;">
                                    <i class="fa fa-edit"></i>
                                </a> -->
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
            <p class="text-muted">No health records found.</p>
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
                row.dataset.address,
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

    function openHealthRecordModal() {
        resetAddModal();
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