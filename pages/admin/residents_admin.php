<?php
// DATABASE & FUNCTIONS
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit_log.php';

// AUTHENTICATION
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login_admin.php");
    exit();
}

// Grab logged-in admin info for audit logging
$admin_id = (int) $_SESSION['admin_id'];
$barangay_id = $_SESSION['barangay_id'];

$adminStmt = $pdo->prepare("SELECT full_name FROM admins WHERE admin_id = :id LIMIT 1");
$adminStmt->execute([':id' => $admin_id]);
$adminRow = $adminStmt->fetch();
$admin_full_name = $adminRow['full_name'] ?? 'Admin';

// Fetch barangay name for PDF report header
$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $barangay_id]);
$barangay_name = $brgyStmt->fetchColumn();

// -------------------------------------------------------
// HANDLE ADD RESIDENT
// -------------------------------------------------------
$add_success = false;
$add_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['first_name'])) {

    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $add_error = "Invalid request. Please try again.";
    } else {
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $birth_date = sanitizeInput($_POST['birth_date']);
        $gender = sanitizeInput($_POST['gender']);
        $address = sanitizeInput($_POST['address']);
        $phone = sanitizeInput($_POST['phone']);

        if (!validateRequired($first_name) || !validateRequired($last_name) || !validateRequired($birth_date) || !validateRequired($gender)) {
            $add_error = "Please fill in all required fields.";
        } elseif (!validateDate($birth_date)) {
            $add_error = "Invalid birth date format.";
        } elseif (!in_array($gender, ['Male', 'Female'])) {
            $add_error = "Invalid gender value.";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO residents (first_name, last_name, birth_date, gender, address, phone, barangay_id)
                VALUES (:fn, :ln, :bd, :g, :a, :p, :brgy)
            ");
            $stmt->execute([
                ':fn' => $first_name,
                ':ln' => $last_name,
                ':bd' => $birth_date,
                ':g' => $gender,
                ':a' => $address,
                ':p' => $phone,
                ':brgy' => $barangay_id,
            ]);

            logAudit($pdo, 'admin', $admin_id, 'ADD_RESIDENT', "Admin '$admin_full_name' added resident: $first_name $last_name.");
            $add_success = true;
        }
    }
}

// -------------------------------------------------------
// HANDLE EDIT RESIDENT
// -------------------------------------------------------
$edit_success = false;
$edit_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_resident_id'])) {

    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $edit_error = "Invalid request. Please try again.";
    } else {
        $resident_id = (int) $_POST['edit_resident_id'];
        $first_name = sanitizeInput($_POST['edit_first_name']);
        $last_name = sanitizeInput($_POST['edit_last_name']);
        $birth_date = sanitizeInput($_POST['edit_birth_date']);
        $gender = sanitizeInput($_POST['edit_gender']);
        $address = sanitizeInput($_POST['edit_address']);
        $phone = sanitizeInput($_POST['edit_phone']);

        if (!validateRequired($first_name) || !validateRequired($last_name) || !validateRequired($birth_date) || !validateRequired($gender)) {
            $edit_error = "Please fill in all required fields.";
        } elseif (!validateDate($birth_date)) {
            $edit_error = "Invalid birth date format.";
        } elseif (!in_array($gender, ['Male', 'Female'])) {
            $edit_error = "Invalid gender value.";
        } else {
            $stmt = $pdo->prepare("
                UPDATE residents
                SET first_name = :fn,
                    last_name  = :ln,
                    birth_date = :bd,
                    gender     = :g,
                    address    = :a,
                    phone      = :p
                WHERE resident_id = :id AND barangay_id = :brgy
            ");
            $stmt->execute([
                ':fn' => $first_name,
                ':ln' => $last_name,
                ':bd' => $birth_date,
                ':g' => $gender,
                ':a' => $address,
                ':p' => $phone,
                ':id' => $resident_id,
                ':brgy' => $barangay_id,
            ]);

            logAudit($pdo, 'admin', $admin_id, 'EDIT_RESIDENT', "Admin '$admin_full_name' edited resident ID: $resident_id ($first_name $last_name).");
            $edit_success = true;
        }
    }
}

// -------------------------------------------------------
// FETCH RESIDENTS + AGE + LATEST HEALTH RECORD
// -------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT r.resident_id, r.first_name, r.last_name, r.gender, r.birth_date, r.address, r.phone,
           TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age,
           hr.weight, hr.height, hr.bmi
    FROM residents r
    LEFT JOIN (
        SELECT hr1.*
        FROM health_records hr1
        INNER JOIN (
            SELECT resident_id, MAX(record_id) AS latest_id
            FROM health_records
            WHERE barangay_id = :brgy
            GROUP BY resident_id
        ) hr2
        ON hr1.resident_id = hr2.resident_id AND hr1.record_id = hr2.latest_id
    ) hr ON r.resident_id = hr.resident_id
    WHERE r.barangay_id = :brgy2
    ORDER BY r.first_name ASC
");
$stmt->execute([':brgy' => $barangay_id, ':brgy2' => $barangay_id]);
$residents = $stmt->fetchAll();

// -------------------------------------------------------
// HELPERS
// -------------------------------------------------------
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
        return 'admin-hr-status-badge';
    if ($bmi < 18.5)
        return 'admin-hr-status-badge admin-hr-status-underweight';
    if ($bmi < 25.0)
        return 'admin-hr-status-badge admin-hr-status-normal';
    if ($bmi < 30.0)
        return 'admin-hr-status-badge admin-hr-status-overweight';
    return 'admin-hr-status-badge admin-hr-status-obese';
}

// HEADER & SIDEBAR
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_admin.php';
?>

<!-- jsPDF Core -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<!-- jsPDF AutoTable Plugin -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<script>
    window.CURRENT_WORKER_NAME = <?= json_encode($admin_full_name) ?>;
    window.CURRENT_BARANGAY_NAME = <?= json_encode($barangay_name ?? 'Barangay Health Center') ?>;
</script>
<script src="../../assets/js/residents_admin_pdf.js"></script>

<img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
<img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
    style="display:none;">

<main class="dashboard">
    <h1 class="page-title">All Residents</h1>

    <section class="section">

        <!-- Top Bar -->
        <div class="admin-table-topbar">
            <button onclick="generateResidentsPDF()" class="admin-btn admin-btn-export">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
        </div>

        <!-- Filter Bar -->
        <div class="admin-filter-bar">
            <select id="residentGenderFilter" onchange="filterResidentsTable()">
                <option value="">All Genders</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
            <select id="residentBmiFilter" onchange="filterResidentsTable()">
                <option value="">All BMI Status</option>
                <option value="underweight">Underweight</option>
                <option value="normal">Normal</option>
                <option value="overweight">Overweight</option>
                <option value="obese">Obese</option>
            </select>
            <div class="admin-filter-search">
                <input type="text" id="residentSearch" placeholder="Search by name, address, phone…"
                    oninput="filterResidentsTable()">
            </div>
            <span class="admin-filter-count" id="residentCount" style="display:none;"></span>
        </div>

        <?php if (!empty($residents)): ?>
            <div class="admin-table-wrapper">
                <table class="admin-table" id="residentsTable">
                    <thead class="admin-table-head">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Age</th>
                            <th>Phone</th>
                            <th>Weight (kg)</th>
                            <th>Height (cm)</th>
                            <th>BMI</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody class="admin-table-body" id="residentsTableBody">
                        <?php foreach ($residents as $index => $resident):
                            $bmiVal = isset($resident['bmi']) ? (float) $resident['bmi'] : null;
                            $bmiSlug = bmiSlug($bmiVal);
                            $initials = resInitials($resident['first_name'], $resident['last_name']);
                            $bmiBadge = resBmiBadgeClass($bmiVal);
                            $bmiLabel = resBmiLabel($bmiVal);
                            ?>
                            <tr id="row-<?= $resident['resident_id'] ?>"
                                data-name="<?= strtolower(htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'])) ?>"
                                data-gender="<?= strtolower(htmlspecialchars($resident['gender'])) ?>"
                                data-address="<?= strtolower(htmlspecialchars($resident['address'] ?? '')) ?>"
                                data-phone="<?= htmlspecialchars($resident['phone'] ?? '') ?>" data-bmi="<?= $bmiSlug ?>">

                                <td><?= $index + 1 ?></td>

                                <!-- NAME + AVATAR + ADDRESS -->
                                <td>
                                    <div class="admin-table-worker-cell">
                                        <div class="admin-table-worker-avatar-initials">
                                            <?= $initials ?>
                                        </div>
                                        <div>
                                            <div class="dash-cell-name">
                                                <?= htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) ?>
                                            </div>
                                            <div class="dash-cell-sub">
                                                <?= htmlspecialchars($resident['address'] ?? '') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- GENDER -->
                                <td><?= htmlspecialchars($resident['gender']) ?></td>

                                <!-- AGE -->
                                <td><?= $resident['age'] ?> yrs</td>

                                <!-- PHONE -->
                                <td><?= htmlspecialchars($resident['phone'] ?? '—') ?></td>

                                <!-- WEIGHT -->
                                <td><?= isset($resident['weight']) ? rtrim(rtrim(number_format($resident['weight'], 1), '0'), '.') . ' kg' : '—' ?>
                                </td>

                                <!-- HEIGHT -->
                                <td><?= isset($resident['height']) ? rtrim(rtrim(number_format($resident['height'], 1), '0'), '.') . ' cm' : '—' ?>
                                </td>

                                <!-- BMI -->
                                <td><?= isset($resident['bmi']) ? rtrim(rtrim(number_format($resident['bmi'], 1), '0'), '.') : '—' ?>
                                </td>

                                <!-- STATUS BADGE -->
                                <td>
                                    <span class="<?= $bmiBadge ?>">
                                        <?= $bmiLabel ?>
                                    </span>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="residentsEmptyState" style="display:none;" class="admin-empty-state">
                <i class="fas fa-users"></i>
                <p id="residentsEmptyMsg">No residents match your current filters.</p>
            </div>

        <?php else: ?>
            <div class="admin-empty-state">
                <i class="fas fa-users"></i>
                <p>No residents found for this barangay.</p>
            </div>
        <?php endif; ?>

    </section>
</main>


<!-- ===================== ADD RESIDENT MODAL ===================== -->
<div id="residentModal" class="modal-overlay">
    <div class="modal-box">

        <div class="modal-header" id="addModalHeader">
            <div class="modal-header-left">
                <div class="modal-header-icon">🏥</div>
                <div class="modal-header-text">
                    <h2>Add New Resident</h2>
                    <p>Fill in the resident's information below</p>
                </div>
            </div>
            <span class="modal-close" onclick="closeResidentModal()">&times;</span>
        </div>
        <div class="modal-progress-bar" id="addModalProgressBar">
            <div class="modal-progress-fill"></div>
        </div>

        <div class="modal-form-content" id="addFormContent">
            <form method="POST" class="form-default modal-form" id="addResidentForm">
                <?= csrfInput() ?>

                <div class="modal-section-label">Personal Information</div>
                <div class="modal-form-grid">
                    <div class="form-group">
                        <label>First Name <span class="text-error">*</span></label>
                        <input type="text" name="first_name" class="input-default" placeholder="e.g. Juan" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="text-error">*</span></label>
                        <input type="text" name="last_name" class="input-default" placeholder="e.g. dela Cruz" required>
                    </div>
                    <div class="form-group">
                        <label>Birth Date <span class="text-error">*</span></label>
                        <input type="date" name="birth_date" class="input-default" required>
                    </div>
                    <div class="form-group">
                        <label>Gender <span class="text-error">*</span></label>
                        <select name="gender" class="input-default" required>
                            <option value="">Select gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="modal-section-label">Contact Details</div>
                <div class="modal-form-grid modal-form-grid--single">
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" class="input-default"
                            placeholder="Barangay, Municipality, Province">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" class="input-default" placeholder="+63 9XX XXX XXXX">
                    </div>
                </div>

                <div class="modal-actions">
                    <span class="modal-required-note">* Required fields</span>
                    <div class="modal-actions-buttons">
                        <button type="button" class="admin-btn admin-btn-cancel"
                            onclick="closeResidentModal()">Cancel</button>
                        <button type="submit" class="admin-btn admin-btn-submit">Save Resident &rarr;</button>
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
            <div class="s5-state-title success">Resident Added!</div>
            <div class="s5-state-message">The resident has been successfully saved to the system.</div>
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


<!-- ===================== EDIT RESIDENT MODAL ===================== -->
<div id="editResidentModal" class="modal-overlay">
    <div class="modal-box">

        <div class="modal-header" id="editModalHeader">
            <div class="modal-header-left">
                <div class="modal-header-icon">✏️</div>
                <div class="modal-header-text">
                    <h2>Edit Resident</h2>
                    <p>Update the resident's information below</p>
                </div>
            </div>
            <span class="modal-close" onclick="closeEditResidentModal()">&times;</span>
        </div>
        <div class="modal-progress-bar" id="editModalProgressBar">
            <div class="modal-progress-fill"></div>
        </div>

        <div class="modal-form-content" id="editFormContent">
            <form method="POST" class="form-default modal-form" id="editResidentForm">
                <input type="hidden" name="edit_resident_id" id="edit_resident_id">
                <?= csrfInput() ?>

                <div class="modal-section-label">Personal Information</div>
                <div class="modal-form-grid">
                    <div class="form-group">
                        <label>First Name <span class="text-error">*</span></label>
                        <input type="text" name="edit_first_name" id="edit_first_name" class="input-default"
                            placeholder="e.g. Juan" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="text-error">*</span></label>
                        <input type="text" name="edit_last_name" id="edit_last_name" class="input-default"
                            placeholder="e.g. dela Cruz" required>
                    </div>
                    <div class="form-group">
                        <label>Birth Date <span class="text-error">*</span></label>
                        <input type="date" name="edit_birth_date" id="edit_birth_date" class="input-default" required>
                    </div>
                    <div class="form-group">
                        <label>Gender <span class="text-error">*</span></label>
                        <select name="edit_gender" id="edit_gender" class="input-default" required>
                            <option value="">Select gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="modal-section-label">Contact Details</div>
                <div class="modal-form-grid modal-form-grid--single">
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="edit_address" id="edit_address" class="input-default"
                            placeholder="Barangay, Municipality, Province">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="edit_phone" id="edit_phone" class="input-default"
                            placeholder="+63 9XX XXX XXXX">
                    </div>
                </div>

                <div class="modal-actions">
                    <span class="modal-required-note">* Required fields</span>
                    <div class="modal-actions-buttons">
                        <button type="button" class="admin-btn admin-btn-cancel"
                            onclick="closeEditResidentModal()">Cancel</button>
                        <button type="submit" class="admin-btn admin-btn-submit">Update Resident &rarr;</button>
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
            <div class="s5-state-title success">Resident Updated!</div>
            <div class="s5-state-message">The resident's information has been successfully updated.</div>
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


<!-- ===================== DELETE CONFIRMATION MODAL ===================== -->
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
                You are about to delete this resident. This action cannot be undone.
            </div>
            <div class="modal-state-actions">
                <button class="modal-state-btn cancel-btn" onclick="closeDeleteConfirmModal()">Cancel</button>
                <button class="modal-state-btn delete-btn" id="confirmDeleteBtn" onclick="executeDelete()">Yes,
                    Delete</button>
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
            <div class="s5-state-message">The resident has been successfully removed.</div>
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
            <div class="s5-state-message">Could not delete the resident. Please try again.</div>
            <button class="s5-state-btn error-btn" onclick="closeDeleteConfirmModal()">Close</button>
        </div>

    </div>
</div>


<script>
    // ── Filter & Search ──────────────────────────────────────────
    function filterResidentsTable() {
        const gender = (document.getElementById('residentGenderFilter')?.value || '').toLowerCase();
        const bmi = (document.getElementById('residentBmiFilter')?.value || '').toLowerCase();
        const query = (document.getElementById('residentSearch')?.value || '').toLowerCase().trim();

        const rows = document.querySelectorAll('#residentsTableBody tr');
        const emptyEl = document.getElementById('residentsEmptyState');
        const emptyMsg = document.getElementById('residentsEmptyMsg');
        const countEl = document.getElementById('residentCount');

        let visible = 0;

        rows.forEach(row => {
            const matchGender = !gender || row.dataset.gender === gender;
            const matchBmi = !bmi || row.dataset.bmi === bmi;
            const matchQuery = !query || [
                row.dataset.name,
                row.dataset.address,
                row.dataset.phone
            ].some(v => (v || '').includes(query));

            const show = matchGender && matchBmi && matchQuery;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (countEl) {
            countEl.textContent = visible + ' resident' + (visible !== 1 ? 's' : '');
            countEl.style.display = (gender || bmi || query) ? '' : 'none';
        }

        if (emptyEl) {
            emptyEl.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
            if (emptyMsg && visible === 0) {
                const parts = [];
                if (query) parts.push('"' + query + '"');
                if (gender) parts.push(gender);
                if (bmi) parts.push(bmi + ' BMI');
                emptyMsg.textContent = parts.length
                    ? 'No residents match: ' + parts.join(' · ') + '. Try adjusting your filters.'
                    : 'No residents match your current filters.';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', filterResidentsTable);

    // ── Modal Helpers ────────────────────────────────────────────
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function autoClose(cb) { setTimeout(() => { cb(); }, 2100); }

    function showState(formContentId, stateId, headerId, progressBarId) {
        const content = document.getElementById(formContentId);
        const state = document.getElementById(stateId);
        const header = headerId ? document.getElementById(headerId) : null;
        const progressBar = progressBarId ? document.getElementById(progressBarId) : null;

        if (header) header.style.display = 'none';
        if (progressBar) progressBar.style.display = 'none';

        if (content) {
            content.classList.add('fade-out');
            setTimeout(() => { content.style.display = 'none'; state.classList.add('active'); }, 220);
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
        if (content) { content.style.display = ''; content.classList.remove('fade-out'); }
        if (header) header.style.display = '';
        if (progressBar) progressBar.style.display = '';
    }

    // ── Add Modal ────────────────────────────────────────────────
    function openResidentModal() { resetAddModal(); openModal('residentModal'); }
    function closeResidentModal() { closeModal('residentModal'); setTimeout(resetAddModal, 300); }
    function resetAddModal() {
        resetState('addFormContent', 'addSuccessState', 'addModalHeader', 'addModalProgressBar');
        resetState('addFormContent', 'addErrorState', 'addModalHeader', 'addModalProgressBar');
    }

    <?php if ($add_success): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('residentModal');
            showState('addFormContent', 'addSuccessState', 'addModalHeader', 'addModalProgressBar');
            autoClose(closeResidentModal);
        });
    <?php elseif (!empty($add_error)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('residentModal');
            showState('addFormContent', 'addErrorState', 'addModalHeader', 'addModalProgressBar');
        });
    <?php endif; ?>

    // ── Edit Modal ───────────────────────────────────────────────
    function openEditResidentModal(id, first_name, last_name, birth_date, gender, address, phone) {
        resetEditModal();
        document.getElementById('edit_resident_id').value = id;
        document.getElementById('edit_first_name').value = first_name;
        document.getElementById('edit_last_name').value = last_name;
        document.getElementById('edit_birth_date').value = birth_date;
        document.getElementById('edit_gender').value = gender;
        document.getElementById('edit_address').value = address;
        document.getElementById('edit_phone').value = phone;
        openModal('editResidentModal');
    }

    function closeEditResidentModal() { closeModal('editResidentModal'); setTimeout(resetEditModal, 300); }
    function resetEditModal() {
        resetState('editFormContent', 'editSuccessState', 'editModalHeader', 'editModalProgressBar');
        resetState('editFormContent', 'editErrorState', 'editModalHeader', 'editModalProgressBar');
    }

    <?php if ($edit_success): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('editResidentModal');
            showState('editFormContent', 'editSuccessState', 'editModalHeader', 'editModalProgressBar');
            autoClose(closeEditResidentModal);
        });
    <?php elseif (!empty($edit_error)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('editResidentModal');
            showState('editFormContent', 'editErrorState', 'editModalHeader', 'editModalProgressBar');
        });
    <?php endif; ?>

    // ── Delete Modal ─────────────────────────────────────────────
    let pendingDeleteId = null;

    function openDeleteConfirmModal(id, name) {
        pendingDeleteId = id;
        document.getElementById('deleteConfirmState').classList.add('active');
        document.getElementById('deleteSuccessState').classList.remove('active');
        document.getElementById('deleteErrorState').classList.remove('active');
        document.getElementById('deleteConfirmMessage').textContent =
            'You are about to delete "' + name + '". This action cannot be undone.';
        openModal('deleteConfirmModal');
    }

    function closeDeleteConfirmModal() { closeModal('deleteConfirmModal'); pendingDeleteId = null; }

    function executeDelete() {
        if (!pendingDeleteId) return;

        const confirmBtn = document.getElementById('confirmDeleteBtn');
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Deleting...';

        fetch('delete_resident.php', {
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
                        setTimeout(() => { row.remove(); filterResidentsTable(); }, 400);
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
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>