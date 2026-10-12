<?php
// --------------------------------------
// DATABASE & FUNCTIONS
// --------------------------------------
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/mailer.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];
$admin_full_name = $_SESSION['admin_full_name'] ?? $_SESSION['full_name'] ?? 'Unknown';

// --------------------------------------
// [SECURITY] RATE LIMITING — max 10 submissions per 15 minutes per IP
// --------------------------------------
$rate_limit_key = 'add_user_' . md5($_SERVER['REMOTE_ADDR']);
$rate_limit_max = 10;
$rate_limit_window = 900;

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'start' => time()];
}

if ((time() - $_SESSION[$rate_limit_key]['start']) > $rate_limit_window) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'start' => time()];
}



// --------------------------------------
// ALLOWED EMAIL DOMAINS
// --------------------------------------
$allowed_email_domains = [
    // Personal
    'gmail.com',
    'yahoo.com',
    'yahoo.com.ph',
    'outlook.com',
    'hotmail.com',
    'live.com',
    'icloud.com',
    'rocketmail.com',
    'ymail.com',
    // Philippine Government
    'doh.gov.ph',
    'rhu.gov.ph',
    'lgu.gov.ph',
    'dilg.gov.ph',
    'philhealth.gov.ph',
];

// --------------------------------------
// FORM HANDLING
// --------------------------------------
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // [SECURITY] Check rate limit before processing
    if ($_SESSION[$rate_limit_key]['count'] >= $rate_limit_max) {
        $error = "Too many attempts. Please wait 15 minutes before trying again.";
    }
    // [SECURITY] Verify CSRF token
    elseif (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {

        // [SECURITY] Sanitize all inputs
        $full_name = sanitizeInput($_POST['full_name']);
        $username = sanitizeInput($_POST['username']);
        $email = sanitizeInput($_POST['email']);
        $phone = sanitizeInput($_POST['phone']);
        $address = sanitizeInput($_POST['address']);
        $department = 'Health Worker';
        $date_hired = sanitizeInput($_POST['date_hired']);
        $status = sanitizeInput($_POST['status']);

        // [SECURITY] Max length checks
        $length_errors = [];
        if (strlen($full_name) > 100)
            $length_errors[] = "Full name is too long (max 100 characters).";
        if (strlen($username) > 50)
            $length_errors[] = "Username is too long (max 50 characters).";
        if (strlen($email) > 150)
            $length_errors[] = "Email is too long (max 150 characters).";
        if (strlen($phone) > 11)
            $length_errors[] = "Phone number is too long (max 11 digits).";
        if (strlen($address) > 255)
            $length_errors[] = "Address is too long (max 255 characters).";
        if (strlen($department) > 100)
            $length_errors[] = "Department name is too long (max 100 characters).";

        if (!empty($length_errors)) {
            $error = $length_errors[0];
        }
        // [SECURITY] Required field validation
        elseif (!validateRequired($full_name) || !validateRequired($username)) {
            $error = "Please fill in all required fields.";
        }
        // [SECURITY] Email is now required — temp password is sent via email
        elseif (!validateRequired($email)) {
            $error = "Email is required. The health worker's login credentials will be sent to this email.";
        } elseif (!validateEmail($email)) {
            $error = "Invalid email format. Please enter a valid email address.";
        } else {
            // [SECURITY] Validate allowed email domain
            $email_domain = strtolower(substr(strrchr($email, '@'), 1));
            if (!in_array($email_domain, $allowed_email_domains)) {
                $error = "Only personal emails (Gmail, Yahoo, Outlook) or Philippine government emails (doh.gov.ph, rhu.gov.ph) are allowed.";
            }
        }

        if (empty($error)) {
            // [SECURITY] Department must be from the allowed list — prevents injection
            if (!empty($date_hired) && !validateDate($date_hired)) {
                $error = "Invalid date hired format.";
            } elseif (!in_array($status, ['Active', 'Inactive'])) {
                $error = "Invalid status value.";
            } elseif (strlen($username) < 4) {
                $error = "Username must be at least 4 characters long.";
            } elseif (preg_match('/\s/', $username)) {
                $error = "Username must not contain spaces.";
            } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                $error = "Username can only contain letters, numbers, and underscores.";
            } else {
                // [DB] Check for duplicate username
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_workers WHERE username = :username");
                $stmt->execute(['username' => $username]);
                if ($stmt->fetchColumn() > 0) {
                    $error = "Username already exists. Choose another one.";
                } else {
                    // [DB] Check for duplicate email in health_workers
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_workers WHERE email = :email");
                    $stmt->execute(['email' => $email]);
                    if ($stmt->fetchColumn() > 0) {
                        $error = "Email already exists. Use a different email.";
                    }

                    // [DB] Check if email is already used by an admin
                    if (empty($error)) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE email = :email");
                        $stmt->execute(['email' => $email]);
                        if ($stmt->fetchColumn() > 0) {
                            $error = "Email already exists. Use a different email.";
                        }
                    }

                    // [DB] Check if email is already used by a superadmin
                    if (empty($error)) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM superadmins WHERE email = :email");
                        $stmt->execute(['email' => $email]);
                        if ($stmt->fetchColumn() > 0) {
                            $error = "Email already exists. Use a different email.";
                        }
                    }
                }

                if (empty($error)) {
                    // Increment rate limit counter on successful attempt
                    $_SESSION[$rate_limit_key]['count']++;

                    // [TEMP PASSWORD] Auto-generate random 10-character alphanumeric password
                    $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 10);
                    $hashed_password = password_hash($temp_password, PASSWORD_BCRYPT);

                    // [DB] Insert new health worker with is_temp_password = 1
                    $barangay_id = $_SESSION['barangay_id'];
                    $stmt = $pdo->prepare("
    INSERT INTO health_workers 
        (full_name, username, password, email, phone, address, department, date_hired, status, role, is_temp_password, barangay_id)
    VALUES 
        (:full_name, :username, :password, :email, :phone, :address, :department, :date_hired, :status, 'health_worker', 1, :barangay_id)
");
                    $stmt->execute([
                        'full_name' => $full_name,
                        'username' => $username,
                        'password' => $hashed_password,
                        'email' => $email,
                        'phone' => $phone ?: null,
                        'address' => $address ?: null,
                        'department' => $department,
                        'date_hired' => $date_hired ?: null,
                        'status' => $status,
                        'barangay_id' => $barangay_id,
                    ]);

                    // [AUDIT] Log account creation
                    logAudit(
                        $pdo,
                        'admin',
                        (int) $admin_id,
                        'ADD_USER',
                        'Admin \'' . $admin_full_name . '\' created health worker: ' . $full_name .
                        ', Username: ' . $username .
                        ', Department: ' . $department .
                        ', Status: ' . $status
                    );

                    // [EMAIL] Send temporary credentials to the new health worker
                    $emailSent = sendTempPasswordEmail($email, $full_name, $temp_password, 'health_worker');

                    // [NOTIFICATION] Notify health worker — admin created their account
                    $newWorker = $pdo->prepare("SELECT worker_id FROM health_workers WHERE username = :username LIMIT 1");
                    $newWorker->execute([':username' => $username]);
                    $worker = $newWorker->fetch();
                    if ($worker) {
                        $nStmt = $pdo->prepare("
                            INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message)
                            VALUES (:id, 'health_worker', :sid, 'admin', 'info', :msg)
                        ");
                        $nStmt->execute([
                            ':id' => $worker['worker_id'],
                            ':sid' => $admin_id,
                            ':msg' => "Your account has been created by the administrator."
                        ]);
                    }

                    // [NOTIFICATION] Notify municipal — admin added a new health worker
                    $bStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :bid");
                    $bStmt->execute([':bid' => $barangay_id]);
                    $barangayName = $bStmt->fetchColumn() ?: 'Unknown Barangay';

                    $superadmins = $pdo->query("SELECT superadmin_id FROM superadmins");
                    foreach ($superadmins->fetchAll() as $sa) {
                        $saStmt = $pdo->prepare("
        INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message, barangay_id)
        VALUES (:id, 'superadmin', :sid, 'admin', 'info', :msg, :barangay_id)
    ");
                        $saStmt->execute([
                            ':id' => $sa['superadmin_id'],
                            ':sid' => $admin_id,
                            ':msg' => "Brgy. {$barangayName} added a new health worker: {$full_name}.",
                            ':barangay_id' => $barangay_id,
                        ]);
                    }
                    // [SUCCESS] Message — show if email was sent or not
                    if ($emailSent) {
                        $success = "Health worker account created successfully! Login credentials have been sent to <strong>" . htmlspecialchars($email) . "</strong>.";
                    } else {
                        $success = "Health worker account created successfully! However, the credentials email could not be sent. Please share the temporary password manually.";
                    }
                }
            }
        }

        // Increment rate limit counter on every failed POST attempt
        if (empty($success)) {
            $_SESSION[$rate_limit_key]['count']++;
        }
    }
}

function old($field, $default = '')
{
    return htmlspecialchars($_POST[$field] ?? $default);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Add User | NutriTrack Admin</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>

<body>

    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <?php include __DIR__ . '/../../includes/sidebar_admin.php'; ?>

    <main class="dashboard">
        <h1 class="page-title">Add User</h1>

        <section class="section">
            <div class="admin-card">

                <div class="admin-card-header">
                    <div class="admin-card-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">New Health Worker Account</h2>
                        <p class="admin-card-subtitle">Fill in the details below to create a new health worker account
                        </p>
                    </div>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="admin-alert admin-alert-success" id="successAlert">
                        <i class="fas fa-check-circle"></i>
                        <?= $success; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="admin-alert admin-alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="admin-form" id="add-user-form" novalidate>

                    <?= csrfInput() ?>

                    <!-- ─── Section 1: Personal Information ─── -->
                    <div class="admin-form-section">Personal Information</div>
                    <div class="admin-form-grid">

                        <!-- Full Name -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">
                                Full Name <span class="admin-form-required">*</span>
                            </label>
                            <input type="text" id="full_name" name="full_name" class="admin-form-input"
                                placeholder="e.g. Juan dela Cruz" value="<?= old('full_name'); ?>" maxlength="100"
                                required>
                            <span class="admin-field-error" id="full_name-error">
                                <i class="admin-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                        </div>

                        <!-- Phone -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">Phone</label>
                            <input type="text" id="phone" name="phone" class="admin-form-input"
                                placeholder="e.g. 09XX XXX XXXX" value="<?= old('phone'); ?>" maxlength="11">
                            <span class="admin-field-error" id="phone-error">
                                <i class="admin-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                            <p class="admin-form-hint">Must be exactly 11 digits starting with 09</p>
                        </div>

                        <!-- Address -->
                        <div class="admin-form-group admin-form-group-full">
                            <label class="admin-form-label">Address</label>
                            <input type="text" id="address" name="address" class="admin-form-input"
                                placeholder="e.g. Barangay, Municipality, Province" value="<?= old('address'); ?>"
                                maxlength="255">
                            <span class="admin-field-error" id="address-error">
                                <i class="admin-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                        </div>

                    </div>

                    <!-- ─── Section 2: Account Details ─── -->
                    <div class="admin-form-section">Account Details</div>
                    <div class="admin-form-grid">

                        <!-- Username -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">
                                Username <span class="admin-form-required">*</span>
                            </label>
                            <input type="text" id="username" name="username" class="admin-form-input"
                                placeholder="e.g. jdelacruz" value="<?= old('username'); ?>" maxlength="50" required
                                minlength="4" pattern="[a-zA-Z0-9_]+"
                                title="Letters, numbers, and underscores only. No spaces.">
                            <span class="admin-field-error" id="username-error">
                                <i class="admin-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                            <p class="admin-form-hint">Min. 4 characters &bull; Letters, numbers, underscores only
                                &bull; No spaces</p>
                        </div>

                        <!-- Email — NOW REQUIRED -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">
                                Email <span class="admin-form-required">*</span>
                            </label>
                            <input type="email" id="email" name="email" class="admin-form-input"
                                placeholder="e.g. juan@gmail.com" value="<?= old('email'); ?>" maxlength="150" required>
                            <span class="admin-field-error" id="email-error">
                                <i class="admin-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                            <p class="admin-form-hint">
                                <i class="fas fa-info-circle"></i>
                                Accepted: Gmail, Yahoo, Outlook, iCloud, DOH, RHU, LGU, DILG, PhilHealth emails
                            </p>
                        </div>

                    </div>

                    <!-- ─── Section 3: Employment Details ─── -->
                    <div class="admin-form-section">Employment Details</div>
                    <div class="admin-form-grid">



                        <!-- Date Hired -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">Date Hired</label>
                            <input type="date" id="date_hired" name="date_hired" class="admin-form-input"
                                value="<?= old('date_hired'); ?>">
                            <span class="admin-field-error" id="date_hired-error">
                                <i class="admin-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                        </div>

                        <!-- Status -->
                        <div class="admin-form-group">
                            <label class="admin-form-label">Status</label>
                            <select name="status" class="admin-form-input">
                                <option value="Active" <?= (old('status', 'Active') === 'Active') ? 'selected' : ''; ?>>
                                    Active</option>
                                <option value="Inactive" <?= (old('status', 'Active') === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                    </div>

                    <!-- Actions -->
                    <div class="admin-form-actions">
                        <span class="admin-form-required-note">* Required fields</span>
                        <div class="admin-form-actions-buttons">
                            <a href="user_management.php" class="admin-btn admin-btn-cancel">Cancel</a>
                            <button type="submit" class="admin-btn admin-btn-submit" id="submit-btn">
                                <i class="fas fa-user-plus"></i> Add Health Worker
                            </button>
                        </div>
                    </div>

                </form>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>

    <script>
        const touched = new Set();
        const OPTIONAL = new Set(['phone', 'address', 'date_hired']);



        function showError(fieldId, msg) {
            const inp = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '-error');
            if (inp) inp.classList.add('input-invalid');
            if (err) {
                err.querySelector('.admin-err-text').textContent = msg;
                err.classList.add('show');
            }
        }

        function clearError(fieldId) {
            const inp = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '-error');
            if (inp) inp.classList.remove('input-invalid');
            if (err) {
                err.querySelector('.admin-err-text').textContent = '';
                err.classList.remove('show');
            }
        }

        const Validate = {

            full_name(v) {
                if (!v.trim()) return 'Full name is required.';
                if (/\d/.test(v)) return 'Full name must not contain numbers.';
                if (/[^a-zA-ZÀ-ÿ\s.'\-]/.test(v)) return 'Full name contains invalid characters.';
                if (v.trim().length < 2) return 'Full name is too short.';
                if (v.length > 100) return 'Full name is too long (max 100 characters).';
                return null;
            },

            phone(v) {
                if (!v.trim()) return null;
                if (/[^0-9]/.test(v)) return 'Phone number must contain digits only.';
                if (!v.startsWith('09')) return 'Phone number must start with 09.';
                if (v.length !== 11) return 'Phone number must be exactly 11 digits.';
                return null;
            },

            address(v) {
                if (!v.trim()) return null;
                if (v.trim().length < 5) return 'Address seems too short.';
                if (v.length > 255) return 'Address is too long (max 255 characters).';
                return null;
            },

            username(v) {
                if (!v.trim()) return 'Username is required.';
                if (/\s/.test(v)) return 'Username must not contain spaces.';
                if (v.length < 4) return 'Username must be at least 4 characters.';
                if (v.length > 50) return 'Username is too long (max 50 characters).';
                if (!/^[a-zA-Z0-9_]+$/.test(v)) return 'Only letters, numbers, and underscores allowed.';
                return null;
            },

            email(v) {
                if (!v.trim()) return 'Email is required.';
                if (v.length > 150) return 'Email is too long (max 150 characters).';
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email address.';

                const allowedDomains = [
                    // Personal
                    'gmail.com', 'yahoo.com', 'yahoo.com.ph',
                    'outlook.com', 'hotmail.com', 'live.com',
                    'icloud.com', 'rocketmail.com', 'ymail.com',
                    // Philippine Government
                    'doh.gov.ph', 'rhu.gov.ph', 'lgu.gov.ph',
                    'dilg.gov.ph', 'philhealth.gov.ph',
                ];
                const domain = v.split('@')[1]?.toLowerCase();
                if (!allowedDomains.includes(domain)) {
                    return 'Only personal emails (Gmail, Yahoo, Outlook) or PH government emails (doh.gov.ph, rhu.gov.ph) are allowed.';
                }

                return null;
            },

            date_hired(v) {
                if (!v) return null;
                const d = new Date(v);
                if (isNaN(d.getTime())) return 'Enter a valid date.';
                if (d < new Date('1970-01-01')) return 'Date hired seems too far in the past.';
                const tenYearsAhead = new Date();
                tenYearsAhead.setFullYear(tenYearsAhead.getFullYear() + 10);
                if (d > tenYearsAhead) return 'Date hired seems too far in the future.';
                return null;
            }
        };

        function attachField(fieldId, key) {
            const el = document.getElementById(fieldId);
            if (!el) return;
            const isOptional = OPTIONAL.has(fieldId);

            el.addEventListener('focus', function () { touched.add(fieldId); });

            el.addEventListener('blur', function () {
                if (!touched.has(fieldId)) return;
                if (isOptional && !this.value.trim()) { clearError(fieldId); return; }
                const msg = Validate[key](this.value);
                msg ? showError(fieldId, msg) : clearError(fieldId);
            });

            el.addEventListener('input', function () {
                if (!touched.has(fieldId)) return;
                if (!this.classList.contains('input-invalid')) return;
                if (isOptional && !this.value.trim()) { clearError(fieldId); return; }
                const msg = Validate[key](this.value);
                msg ? showError(fieldId, msg) : clearError(fieldId);
            });
        }

        attachField('full_name', 'full_name');
        attachField('phone', 'phone');
        attachField('address', 'address');
        attachField('username', 'username');
        attachField('email', 'email');
        attachField('date_hired', 'date_hired');

        document.getElementById('phone').addEventListener('keypress', function (e) {
            if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                e.preventDefault();
            }
        });

        document.getElementById('username').addEventListener('keydown', e => {
            if (e.key === ' ') e.preventDefault();
        });

        document.getElementById('add-user-form').addEventListener('submit', function (e) {
            let hasErr = false;

            const fields = [
                { id: 'full_name', key: 'full_name', req: true },
                { id: 'phone', key: 'phone', req: false },
                { id: 'address', key: 'address', req: false },
                { id: 'username', key: 'username', req: true },
                { id: 'email', key: 'email', req: true },
                { id: 'date_hired', key: 'date_hired', req: false },
            ];

            fields.forEach(({ id, key, req }) => {
                const el = document.getElementById(id);
                if (!el) return;
                if (!req && !el.value.trim()) { clearError(id); return; }
                if (req && !el.value.trim()) {
                    showError(id, Validate[key](''));
                    hasErr = true;
                    return;
                }
                const msg = Validate[key](el.value);
                if (msg) { showError(id, msg); hasErr = true; }
                else clearError(id);
            });

            if (hasErr) {
                e.preventDefault();
                const first = document.querySelector('.input-invalid');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    </script>
<script>
    const alertEl = document.getElementById('successAlert');
    if (alertEl) {
        setTimeout(() => {
            alertEl.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
            alertEl.style.opacity = '0';
            alertEl.style.transform = 'translateY(-8px)';
            setTimeout(() => alertEl.remove(), 800);
        }, 4000);
    }
</script>

</body>

</html>