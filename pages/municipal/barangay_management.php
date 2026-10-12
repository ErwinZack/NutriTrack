<?php
session_start();
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/audit_log.php";
require_once "../../includes/mailer.php";

// Redirect if not logged in
if (!isset($_SESSION['superadmin_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: login_municipal.php");
    exit();
}

$superadmin_id = $_SESSION['superadmin_id'];

// --------------------------------------
// [SECURITY] RATE LIMITING — max 10 submissions per 15 minutes per IP
// --------------------------------------
$rate_limit_key = 'add_barangay_' . md5($_SERVER['REMOTE_ADDR']);
$rate_limit_max = 10;
$rate_limit_window = 900;

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'start' => time()];
}
if ((time() - $_SESSION[$rate_limit_key]['start']) > $rate_limit_window) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'start' => time()];
}

// --------------------------------------
// FIXED LIST: All Official Calasiao Barangays
// --------------------------------------
$all_calasiao_barangays = [
    'Ambonao',
    'Ambuetel',
    'Banaoang',
    'Bued',
    'Buenlag',
    'Cabilocaan',
    'Dinalaoan',
    'Doyong',
    'Gabon',
    'Lasip',
    'Longos',
    'Lumbang',
    'Macabito',
    'Malabago',
    'Mancup',
    'Nagsaing',
    'Nalsian',
    'Poblacion East',
    'Poblacion West',
    'Quesban',
    'San Miguel',
    'San Vicente',
    'Songkoy',
    'Talibaew',
];

// --------------------------------------
// ALLOWED EMAIL DOMAINS (same policy as add_user.php)
// --------------------------------------
$allowed_email_domains = [
    'gmail.com',
    'yahoo.com',
    'yahoo.com.ph',
    'outlook.com',
    'hotmail.com',
    'live.com',
    'icloud.com',
    'rocketmail.com',
    'ymail.com',
    'doh.gov.ph',
    'rhu.gov.ph',
    'lgu.gov.ph',
    'dilg.gov.ph',
    'philhealth.gov.ph',
];

$error = "";
$success = "";

// --------------------------------------
// FETCH: Barangays already registered (to exclude from dropdown)
// --------------------------------------
$registeredBarangays = $pdo->query("SELECT barangay_name FROM barangays")->fetchAll(PDO::FETCH_COLUMN);
$availableBarangays = array_values(array_diff($all_calasiao_barangays, $registeredBarangays));

// --------------------------------------
// HANDLE: Add New Barangay + First Admin Account
// --------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'add_barangay') {

    // [SECURITY] Rate limit check
    if ($_SESSION[$rate_limit_key]['count'] >= $rate_limit_max) {
        $error = "Too many attempts. Please wait 15 minutes before trying again.";
    }
    // [SECURITY] CSRF check
    elseif (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {

        $barangayName = sanitizeInput($_POST['barangay_name'] ?? '');
        $adminFullName = sanitizeInput($_POST['admin_full_name'] ?? '');
        $adminEmail = sanitizeInput($_POST['admin_email'] ?? '');

        // Length checks
        $length_errors = [];
        if (strlen($barangayName) > 100)
            $length_errors[] = "Barangay name is too long.";
        if (strlen($adminFullName) > 100)
            $length_errors[] = "Admin full name is too long (max 100 characters).";
        if (strlen($adminEmail) > 150)
            $length_errors[] = "Email is too long (max 150 characters).";

        if (!empty($length_errors)) {
            $error = $length_errors[0];
        }
        // Required field validation
        elseif (empty($barangayName) || empty($adminFullName) || empty($adminEmail)) {
            $error = "Please fill in all fields.";
        }
        // [SECURITY] Barangay must be from the fixed official list — prevents injection/typos
        elseif (!in_array($barangayName, $all_calasiao_barangays)) {
            $error = "Invalid barangay selected.";
        }
        // Must not already be registered
        elseif (in_array($barangayName, $registeredBarangays)) {
            $error = "This barangay has already been registered.";
        }
        // Full name basic validation
        elseif (preg_match('/\d/', $adminFullName)) {
            $error = "Admin full name must not contain numbers.";
        }
        // Email format validation
        elseif (!validateEmail($adminEmail)) {
            $error = "Invalid email format. Please enter a valid email address.";
        } else {
            // [SECURITY] Validate allowed email domain
            $email_domain = strtolower(substr(strrchr($adminEmail, '@'), 1));
            if (!in_array($email_domain, $allowed_email_domains)) {
                $error = "Only personal emails (Gmail, Yahoo, Outlook) or Philippine government emails (doh.gov.ph, rhu.gov.ph) are allowed.";
            } else {
                // Check for duplicate admin email
                $checkEmail = $pdo->prepare("SELECT admin_id FROM admins WHERE email = :email LIMIT 1");
                $checkEmail->execute(['email' => $adminEmail]);

                if ($checkEmail->fetch()) {
                    $error = "An admin account with this email already exists.";
                }

                // [DB] Check if email is already used by a health worker
                if (empty($error)) {
                    $checkHW = $pdo->prepare("SELECT COUNT(*) FROM health_workers WHERE email = :email");
                    $checkHW->execute(['email' => $adminEmail]);
                    if ($checkHW->fetchColumn() > 0) {
                        $error = "An account with this email already exists.";
                    }
                }

                // [DB] Check if email is already used by a superadmin
                if (empty($error)) {
                    $checkSA = $pdo->prepare("SELECT COUNT(*) FROM superadmins WHERE email = :email");
                    $checkSA->execute(['email' => $adminEmail]);
                    if ($checkSA->fetchColumn() > 0) {
                        $error = "An account with this email already exists.";
                    }
                }

                if (empty($error)) {
                    // Generate username slug from barangay name (e.g. "San Miguel" -> "sanmiguel")
                    $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $barangayName));
                    $username = $slug;

                    // Ensure username uniqueness (append number if needed)
                    $checkUsername = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = :username");
                    $checkUsername->execute(['username' => $username]);
                    if ($checkUsername->fetchColumn() > 0) {
                        $username = $slug . rand(10, 99);
                    }

                    try {
                        $pdo->beginTransaction();

                        // 1. Create the barangay
                        $stmtBrgy = $pdo->prepare("INSERT INTO barangays (barangay_name, status) VALUES (:name, 'active')");
                        $stmtBrgy->execute(['name' => $barangayName]);
                        $newBarangayId = $pdo->lastInsertId();

                        // 2. Auto-generate temp password (same method as add_user.php)
                        $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 10);
                        $hashed_password = password_hash($temp_password, PASSWORD_BCRYPT);

                        // 3. Create the Admin account for this barangay
                        $stmtAdmin = $pdo->prepare("
                            INSERT INTO admins (full_name, username, password, email, role, barangay_id, is_temp_password)
                            VALUES (:full_name, :username, :password, :email, 'admin', :barangay_id, 1)
                        ");
                        $stmtAdmin->execute([
                            'full_name' => $adminFullName,
                            'username' => $username,
                            'password' => $hashed_password,
                            'email' => $adminEmail,
                            'barangay_id' => $newBarangayId,
                        ]);

                        // Capture the new admin's ID immediately after insert, before commit
                        $newAdminId = $pdo->lastInsertId();

                        $pdo->commit();

                        // Increment rate limit counter on success
                        $_SESSION[$rate_limit_key]['count']++;

                        logAudit(
                            $pdo,
                            'superadmin',
                            (int) $superadmin_id,
                            'BARANGAY_CREATED',
                            "Municipal created barangay '{$barangayName}' with Admin account '{$adminEmail}' (username: {$username}).",
                            $newBarangayId
                        );

                        // [EMAIL] Send temp password credentials to the admin's real email
                        $emailSent = sendTempPasswordEmail($adminEmail, $adminFullName, $temp_password, 'admin');

                        // [NOTIFICATION] Notify the new admin — uses $newAdminId captured above
                        $nStmt = $pdo->prepare("
                            INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message, barangay_id)
                            VALUES (:id, 'admin', :sid, 'superadmin', 'info', :msg, :barangay_id)
                        ");
                        $nStmt->execute([
                            ':id' => $newAdminId,
                            ':sid' => $superadmin_id,
                            ':msg' => "Your barangay admin account has been created by the Municipal office.",
                            ':barangay_id' => $newBarangayId,
                        ]);

                        if ($emailSent) {
                            $success = "Barangay '{$barangayName}' created successfully! Login credentials have been sent to <strong>" . htmlspecialchars($adminEmail) . "</strong>.";
                        } else {
                            $success = "Barangay '{$barangayName}' created successfully! However, the credentials email could not be sent. Please share the temporary password manually: <strong>" . htmlspecialchars($temp_password) . "</strong>";
                        }

                        // Refresh available list after creation
                        $registeredBarangays = $pdo->query("SELECT barangay_name FROM barangays")->fetchAll(PDO::FETCH_COLUMN);
                        $availableBarangays = array_values(array_diff($all_calasiao_barangays, $registeredBarangays));

                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log("Barangay creation error: " . $e->getMessage());
                        $error = "Failed to create barangay. Please try again.";
                    }
                }
            }
        }

        // Increment rate limit counter on every failed attempt
        if (empty($success)) {
            $_SESSION[$rate_limit_key]['count']++;
        }
    }
}

// --------------------------------------
// HANDLE: Toggle Barangay Status (Active/Inactive)
// --------------------------------------
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $barangayId = (int) $_GET['id'];

    $stmt = $pdo->prepare("SELECT barangay_name, status FROM barangays WHERE barangay_id = :id LIMIT 1");
    $stmt->execute(['id' => $barangayId]);
    $brgy = $stmt->fetch();

    if ($brgy) {
        $newStatus = $brgy['status'] === 'active' ? 'inactive' : 'active';
        $upd = $pdo->prepare("UPDATE barangays SET status = :status WHERE barangay_id = :id");
        $upd->execute(['status' => $newStatus, 'id' => $barangayId]);

        // Sync admin account status
        $updAdmin = $pdo->prepare("UPDATE admins SET status = :status WHERE barangay_id = :id");
        $updAdmin->execute(['status' => $newStatus, 'id' => $barangayId]);

        logAudit(
            $pdo,
            'superadmin',
            (int) $superadmin_id,
            'BARANGAY_STATUS_TOGGLED',
            "Municipal set barangay '{$brgy['barangay_name']}' to {$newStatus}.",
            $barangayId
        );

        header("Location: barangay_management.php");
        exit();
    }
}

// --------------------------------------
// FETCH: All Barangays with Admin + Worker + Resident counts
// --------------------------------------
$barangays = $pdo->query("
    SELECT
        b.barangay_id,
        b.barangay_name,
        b.status,
        b.created_at,
        a.admin_id,
        a.full_name AS admin_name,
        a.email AS admin_email,
        COUNT(DISTINCT hw.worker_id) AS total_workers,
        COUNT(DISTINCT r.resident_id) AS total_residents
    FROM barangays b
    LEFT JOIN admins a          ON a.barangay_id = b.barangay_id
    LEFT JOIN health_workers hw ON hw.barangay_id = b.barangay_id
    LEFT JOIN residents r       ON r.barangay_id = b.barangay_id
    GROUP BY b.barangay_id, b.barangay_name, b.status, b.created_at, a.admin_id, a.full_name, a.email
    ORDER BY b.barangay_name ASC
")->fetchAll();

function old($field, $default = '')
{
    return htmlspecialchars($_POST[$field] ?? $default);
}

$pageTitle = "Barangay Management";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Barangay Management | NutriTrack Municipal</title>
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
    <!-- Barangay Management PDF Export -->
    <script src="../../assets/js/barangay_management_pdf.js"></script>
    <!-- Hidden logos for jsPDF -->
    <img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
    <img id="logoDOH" src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png"
        style="display:none;">

    <main class="municipal-dashboard">

        <div class="municipal-page-header">
            <div>
                <!-- <h1 class="municipal-page-title">Barangay Management</h1> -->
                <p class="municipal-welcome-message">Add barangays and manage their Admin accounts</p>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="municipal-alert municipal-alert-success">
                <i class="fas fa-circle-check"></i> <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="municipal-alert municipal-alert-error">
                <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Add Barangay Card -->
        <div class="municipal-card">
            <div class="municipal-card-header">
                <div class="municipal-card-icon"><i class="fas fa-map-location-dot"></i></div>
                <div class="municipal-card-header-text">
                    <div class="municipal-card-title">Add New Barangay</div>
                    <div class="municipal-card-subtitle">Creates the barangay and its first Admin account together</div>
                </div>
            </div>

            <form method="POST" action="" class="municipal-form" id="add-barangay-form" novalidate>
                <input type="hidden" name="action" value="add_barangay">
                <?= csrfInput() ?>

                <div class="municipal-form-grid">

                    <!-- Barangay Name — Custom Dropdown -->
                    <div class="municipal-form-group">
                        <label class="municipal-form-label">
                            Barangay Name <span class="municipal-form-required">*</span>
                        </label>

                        <div class="municipal-dropdown" id="barangay-dropdown">
                            <button type="button" class="municipal-dropdown-toggle" id="barangay-dropdown-toggle"
                                <?= empty($availableBarangays) ? 'disabled' : '' ?>>
                                <span
                                    class="municipal-dropdown-toggle-text<?= old('barangay_name') ? ' has-value' : '' ?>"
                                    id="barangay-dropdown-text">
                                    <?= old('barangay_name') ?: '-- Select Barangay --' ?>
                                </span>
                                <i class="fas fa-chevron-down municipal-dropdown-arrow"></i>
                            </button>

                            <div class="municipal-dropdown-menu" id="barangay-dropdown-menu">
                                <?php foreach ($availableBarangays as $brgy): ?>
                                    <div class="municipal-dropdown-item<?= old('barangay_name') === $brgy ? ' selected' : '' ?>"
                                        data-value="<?= htmlspecialchars($brgy) ?>">
                                        <?= htmlspecialchars($brgy) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Hidden input actually submitted with the form -->
                            <input type="hidden" id="barangay_name" name="barangay_name"
                                value="<?= old('barangay_name') ?>">
                        </div>

                        <span class="municipal-field-error" id="barangay_name-error">
                            <i class="municipal-warn-icon fas fa-exclamation-triangle"></i>
                            <span class="municipal-err-text"></span>
                        </span>
                        <?php if (empty($availableBarangays)): ?>
                            <p class="municipal-form-hint">All Calasiao barangays have already been registered.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Admin Full Name -->
                    <div class="municipal-form-group">
                        <label class="municipal-form-label">
                            Admin Full Name <span class="municipal-form-required">*</span>
                        </label>
                        <input type="text" id="admin_full_name" name="admin_full_name" class="municipal-form-input"
                            placeholder="e.g. Juan Dela Cruz" value="<?= old('admin_full_name') ?>" maxlength="100"
                            required>
                        <span class="municipal-field-error" id="admin_full_name-error">
                            <i class="municipal-warn-icon fas fa-exclamation-triangle"></i>
                            <span class="municipal-err-text"></span>
                        </span>
                    </div>

                    <!-- Admin Email -->
                    <div class="municipal-form-group municipal-form-group-full">
                        <label class="municipal-form-label">
                            Admin Email <span class="municipal-form-required">*</span>
                        </label>
                        <input type="email" id="admin_email" name="admin_email" class="municipal-form-input"
                            placeholder="e.g. juan.delacruz@gmail.com" value="<?= old('admin_email') ?>" maxlength="150"
                            required>
                        <span class="municipal-field-error" id="admin_email-error">
                            <i class="municipal-warn-icon fas fa-exclamation-triangle"></i>
                            <span class="municipal-err-text"></span>
                        </span>
                        <p class="municipal-form-hint">
                            <i class="fas fa-info-circle"></i>
                            Login credentials (temp password) will be sent to this email. Accepted: Gmail, Yahoo,
                            Outlook, iCloud, DOH, RHU, LGU, DILG, PhilHealth emails.
                        </p>
                    </div>

                </div>

                <div class="municipal-form-actions">
                    <span class="municipal-form-required-note">* Required fields</span>
                    <button type="submit" class="municipal-btn municipal-btn-submit" <?= empty($availableBarangays) ? 'disabled' : '' ?>>
                        <i class="fas fa-plus"></i> Create Barangay + Admin
                    </button>
                </div>
            </form>
        </div>

        <section class="section">

            <!-- ── Municipal Export PDF ── -->
            <div class="municipal-table-topbar">
                <button onclick="generateBarangayManagementPDF()" class="municipal-btn municipal-btn-export">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>

            <!-- Barangay List -->
            <div class="municipal-filter-bar">
                <div class="municipal-filter-select-wrap">
                    <select id="barangayStatusFilter" class="municipal-form-input municipal-filter-select"
                        onchange="filterBarangayTable()">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                </div>

                <div class="municipal-filter-search">
                    <i class="fas fa-magnifying-glass municipal-filter-search-icon"></i>
                    <input type="text" id="barangaySearch" class="municipal-form-input municipal-filter-search-input"
                        placeholder="Search by barangay, admin, email..." oninput="filterBarangayTable()">
                </div>

                <span class="municipal-filter-count" id="barangayCount">
                    <?= count($barangays) ?> barangay<?= count($barangays) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <div class="municipal-table-wrapper">
                <table class="municipal-table" id="barangayTable">
                    <thead class="municipal-table-head">
                        <tr>
                            <th>Barangay</th>
                            <th>Admin</th>
                            <th>Login Email</th>
                            <th>Workers</th>
                            <th>Residents</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody class="municipal-table-body" id="barangayTableBody">
                        <?php if (!empty($barangays)): ?>
                            <?php foreach ($barangays as $b): ?>
                                <tr data-barangay="<?= strtolower(htmlspecialchars($b['barangay_name'])) ?>"
                                    data-admin="<?= strtolower(htmlspecialchars($b['admin_name'] ?? '')) ?>"
                                    data-email="<?= strtolower(htmlspecialchars($b['admin_email'] ?? '')) ?>"
                                    data-status="<?= htmlspecialchars($b['status']) ?>">
                                    <td><strong><?= htmlspecialchars($b['barangay_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($b['admin_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($b['admin_email'] ?? '—') ?></td>
                                    <td><?= (int) $b['total_workers'] ?></td>
                                    <td><?= (int) $b['total_residents'] ?></td>
                                    <td>
                                        <span
                                            class="municipal-badge <?= $b['status'] === 'active' ? 'municipal-badge-active' : 'municipal-badge-inactive' ?>">
                                            <?= htmlspecialchars(ucfirst($b['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($b['created_at'])) ?></td>
                                    <td>
                                        <?php if ($b['status'] === 'active'): ?>
                                            <a href="?toggle_status=1&id=<?= $b['barangay_id'] ?>"
                                                class="municipal-btn municipal-btn-activate" title="Deactivate Barangay"
                                                onclick="return confirm('Deactivate Barangay <?= htmlspecialchars(addslashes($b['barangay_name'])) ?>?');">
                                                <i class="fas fa-toggle-on"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="?toggle_status=1&id=<?= $b['barangay_id'] ?>"
                                                class="municipal-btn municipal-btn-deactivate" title="Activate Barangay"
                                                onclick="return confirm('Activate Barangay <?= htmlspecialchars(addslashes($b['barangay_name'])) ?>?');">
                                                <i class="fas fa-toggle-off"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="municipal-dash-td-empty">
                                    <div class="municipal-dash-empty">
                                        <div class="municipal-dash-empty-icon">🏘️</div>
                                        <div class="municipal-dash-empty-text">No barangays added yet. Use the form above to
                                            create one.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>

    </main>

    <?php include "../../includes/footer.php"; ?>

    <script>
        const touched = new Set();

        const Validate = {
            barangay_name(v) {
                if (!v) return 'Please select a barangay.';
                return null;
            },
            admin_full_name(v) {
                if (!v.trim()) return 'Admin full name is required.';
                if (/\d/.test(v)) return 'Full name must not contain numbers.';
                if (v.trim().length < 2) return 'Full name is too short.';
                if (v.length > 100) return 'Full name is too long (max 100 characters).';
                return null;
            },
            admin_email(v) {
                if (!v.trim()) return 'Admin email is required.';
                if (v.length > 150) return 'Email is too long (max 150 characters).';
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email address.';

                const allowedDomains = [
                    'gmail.com', 'yahoo.com', 'yahoo.com.ph',
                    'outlook.com', 'hotmail.com', 'live.com',
                    'icloud.com', 'rocketmail.com', 'ymail.com',
                    'doh.gov.ph', 'rhu.gov.ph', 'lgu.gov.ph',
                    'dilg.gov.ph', 'philhealth.gov.ph',
                ];
                const domain = v.split('@')[1]?.toLowerCase();
                if (!allowedDomains.includes(domain)) {
                    return 'Only personal emails (Gmail, Yahoo, Outlook) or PH government emails (doh.gov.ph, rhu.gov.ph) are allowed.';
                }
                return null;
            }
        };

        // ── Custom Barangay Dropdown ──
        const brgyDropdown = document.getElementById('barangay-dropdown');
        const brgyToggle = document.getElementById('barangay-dropdown-toggle');
        const brgyMenu = document.getElementById('barangay-dropdown-menu');
        const brgyHiddenInput = document.getElementById('barangay_name');
        const brgyToggleText = document.getElementById('barangay-dropdown-text');
        const brgyItems = brgyMenu ? brgyMenu.querySelectorAll('.municipal-dropdown-item') : [];

        if (brgyToggle && !brgyToggle.disabled) {
            brgyToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                brgyDropdown.classList.toggle('open');
            });

            brgyItems.forEach(item => {
                item.addEventListener('click', function () {
                    const value = this.dataset.value;

                    brgyHiddenInput.value = value;
                    brgyToggleText.textContent = value;
                    brgyToggleText.classList.add('has-value');

                    brgyItems.forEach(i => i.classList.remove('selected'));
                    this.classList.add('selected');

                    brgyDropdown.classList.remove('open');
                    touched.add('barangay_name');
                    clearError('barangay_name');
                });
            });

            document.addEventListener('click', function (e) {
                if (!brgyDropdown.contains(e.target)) {
                    brgyDropdown.classList.remove('open');
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') brgyDropdown.classList.remove('open');
            });
        }

        function showError(fieldId, msg) {
            const inp = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '-error');

            if (fieldId === 'barangay_name') {
                if (brgyDropdown) brgyDropdown.classList.add('select-invalid');
            } else if (inp) {
                inp.classList.add('input-invalid');
            }

            if (err) {
                err.querySelector('.municipal-err-text').textContent = msg;
                err.classList.add('show');
            }
        }

        function clearError(fieldId) {
            const inp = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '-error');

            if (fieldId === 'barangay_name') {
                if (brgyDropdown) brgyDropdown.classList.remove('select-invalid');
            } else if (inp) {
                inp.classList.remove('input-invalid');
            }

            if (err) {
                err.querySelector('.municipal-err-text').textContent = '';
                err.classList.remove('show');
            }
        }

        function attachField(fieldId) {
            const el = document.getElementById(fieldId);
            if (!el) return;

            el.addEventListener('focus', () => touched.add(fieldId));
            el.addEventListener('blur', function () {
                if (!touched.has(fieldId)) return;
                const msg = Validate[fieldId](this.value);
                msg ? showError(fieldId, msg) : clearError(fieldId);
            });
            el.addEventListener('input', function () {
                if (!touched.has(fieldId)) return;
                if (!this.classList.contains('input-invalid')) return;
                const msg = Validate[fieldId](this.value);
                msg ? showError(fieldId, msg) : clearError(fieldId);
            });
        }

        attachField('admin_full_name');
        attachField('admin_email');

        document.getElementById('add-barangay-form').addEventListener('submit', function (e) {
            let hasErr = false;
            ['barangay_name', 'admin_full_name', 'admin_email'].forEach(id => {
                const el = document.getElementById(id);
                const msg = Validate[id](el.value);
                if (msg) { showError(id, msg); hasErr = true; }
                else clearError(id);
            });

            if (hasErr) {
                e.preventDefault();
                const first = document.querySelector('.input-invalid, .select-invalid');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        function filterBarangayTable() {
            const status = (document.getElementById('barangayStatusFilter')?.value || '').toLowerCase();
            const query = (document.getElementById('barangaySearch')?.value || '').toLowerCase().trim();

            const rows = document.querySelectorAll('#barangayTableBody tr');
            const countEl = document.getElementById('barangayCount');

            let visible = 0;

            rows.forEach(row => {
                const matchStatus = !status || row.dataset.status === status;
                const matchQuery = !query || [
                    row.dataset.barangay,
                    row.dataset.admin,
                    row.dataset.email
                ].some(v => (v || '').includes(query));

                const show = matchStatus && matchQuery;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if (countEl) {
                countEl.textContent = visible + ' barangay' + (visible !== 1 ? 's' : '');
                countEl.style.display = '';
            }
        }

        document.addEventListener('DOMContentLoaded', filterBarangayTable);

    </script>

</body>

</html>