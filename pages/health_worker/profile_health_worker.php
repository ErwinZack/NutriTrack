<?php
session_start();
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/audit_log.php";

// Auth Check
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}

$worker_id = (int) $_SESSION['worker_id'];

// Handle Avatar Upload
$avatar_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avatar_upload'])) {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $avatar_error = "Invalid request. Please try again.";
    } else {
        if (!empty($_POST['cropped_image'])) {
            $data = $_POST['cropped_image'];
            if (preg_match('/^data:image\/(jpeg|png|webp);base64,/', $data, $type)) {
                $data = substr($data, strpos($data, ',') + 1);
                $data = base64_decode($data);
                if ($data !== false) {
                    $uploadDir = '../../assets/images/profiles/';
                    $filename = time() . '_cropped.jpg';
                    $targetPath = $uploadDir . $filename;
                    if (file_put_contents($targetPath, $data)) {
                        $img = 'assets/images/profiles/' . $filename;
                        $s = $pdo->prepare("UPDATE health_workers SET profile_image = :pi WHERE worker_id = :id");
                        $s->execute([':pi' => $img, ':id' => $worker_id]);
                        header("Location: profile_health_worker.php?updated=1");
                        exit();
                    } else {
                        $avatar_error = "Failed to save cropped image.";
                    }
                }
            }
        } elseif (!empty($_FILES['profile_image']['name'])) {
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
            $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $avatar_error = "Invalid image format. Allowed: jpg, jpeg, png, webp.";
            } elseif ($_FILES['profile_image']['size'] > 5 * 1024 * 1024) {
                $avatar_error = "Image must be under 5MB.";
            } else {
                $uploadDir = '../../assets/images/profiles/';
                $filename = time() . '_' . basename($_FILES['profile_image']['name']);
                $targetPath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetPath)) {
                    $img = 'assets/images/profiles/' . $filename;
                    $s = $pdo->prepare("UPDATE health_workers SET profile_image = :pi WHERE worker_id = :id");
                    $s->execute([':pi' => $img, ':id' => $worker_id]);
                    header("Location: profile_health_worker.php?updated=1");
                    exit();
                } else {
                    $avatar_error = "Failed to upload image.";
                }
            }
        }
    }
}

// Handle Edit Profile
$edit_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_profile'])) {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $edit_error = "Invalid request. Please try again.";
    } else {
        $full_name_input = sanitizeInput($_POST['full_name'] ?? '');
        $email_input = sanitizeInput($_POST['email'] ?? '');
        $phone_input = sanitizeInput($_POST['phone'] ?? '');
        $address_input = sanitizeInput($_POST['address'] ?? '');
        $department_input = sanitizeInput($_POST['department'] ?? '');

        if (!validateRequired($full_name_input)) {
            $edit_error = "Full name is required.";
        } else {
            $stmt = $pdo->prepare("
                UPDATE health_workers
                SET full_name = :fn, email = :em, phone = :ph, address = :ad, department = :dp
                WHERE worker_id = :id
            ");
            $stmt->execute([
                ':fn' => $full_name_input,
                ':em' => $email_input,
                ':ph' => $phone_input,
                ':ad' => $address_input,
                ':dp' => $department_input,
                ':id' => $worker_id,
            ]);
            header("Location: profile_health_worker.php?updated=1");
            exit();
        }
    }
}

// Handle Change Password
$pwd_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $pwd_error = "Invalid request. Please try again.";
    } else {
        $stmtPwd = $pdo->prepare("SELECT password, is_temp_password FROM health_workers WHERE worker_id = :id LIMIT 1");
        $stmtPwd->execute([':id' => $worker_id]);
        $pwdRow = $stmtPwd->fetch(PDO::FETCH_ASSOC);
        $currentHash = $pwdRow['password'] ?? '';

        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $pwd_error = "All password fields are required.";
        } elseif (!password_verify($current_password, $currentHash)) {
            $pwd_error = "Current password is incorrect.";
        } elseif ($new_password !== $confirm_password) {
            $pwd_error = "New passwords do not match.";
        } elseif (!isValidPassword($new_password)) {
            $pwd_error = "New password must be at least 8 characters and include 1 uppercase, 1 lowercase, 1 number, and 1 special character.";
        } elseif (password_verify($new_password, $currentHash)) {
            $pwd_error = "New password must be different from your current password.";
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmtUp = $pdo->prepare("UPDATE health_workers SET password = :pw, is_temp_password = 0 WHERE worker_id = :id");
            $stmtUp->execute([':pw' => $hashed, ':id' => $worker_id]);

            logAudit(
                $pdo,
                'health_worker',
                $worker_id,
                'CHANGE_PASSWORD',
                "Health worker ID {$worker_id} changed their password successfully."
            );

            header("Location: profile_health_worker.php?pwd_updated=1");
            exit();
        }
    }
}

// PRG Success Flags
$show_success = isset($_GET['updated']) && $_GET['updated'] === '1';
$show_pwd_success = isset($_GET['pwd_updated']) && $_GET['pwd_updated'] === '1';

// Fetch Health Worker
$stmt = $pdo->prepare("
    SELECT full_name, email, profile_image, phone, address, department, role, date_hired, status, is_temp_password
    FROM health_workers
    WHERE worker_id = :id
    LIMIT 1
");
$stmt->execute([':id' => $worker_id]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$worker) {
    die("Health worker not found.");
}

// Fetch Residents Assessed Count
$stmtCount = $pdo->prepare("
    SELECT COUNT(DISTINCT resident_id) AS total
    FROM health_records
    WHERE worker_id = :id
");
$stmtCount->execute([':id' => $worker_id]);
$residentsAssessed = $stmtCount->fetchColumn() ?: 0;

// Safe Values
$full_name = $worker['full_name'] ?? '';
$email = $worker['email'] ?? '';
$phone = $worker['phone'] ?? '';
$address = $worker['address'] ?? '';
$department = $worker['department'] ?? '';

// Fetch barangay name
$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $_SESSION['barangay_id']]);
$barangay_name = $brgyStmt->fetchColumn() ?: '—';

$role = $worker['role'] ?? '';
$date_hired = $worker['date_hired'] ?? '';
$status = $worker['status'] ?? 'Active';
$is_temp_password = (int) ($worker['is_temp_password'] ?? 0);

$profileImage = !empty($worker['profile_image'])
    ? '../../' . $worker['profile_image']
    : '../../assets/images/profile_icon.png';

$pageTitle = "My Profile";

include "../../includes/header.php";
include "../../includes/sidebar_health_worker.php";
?>

<main class="profile-dashboard">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <section class="section">

        <!-- Top Row: Avatar + Name + Stats -->
        <div class="profile-top-row">

            <!-- ── Clickable avatar ── -->
            <form method="POST" enctype="multipart/form-data" id="avatarForm">
                <?= csrfInput() ?>
                <input type="hidden" name="avatar_upload" value="1">
                <input type="hidden" name="cropped_image" id="croppedImageData">
                <input type="file" name="profile_image" id="avatarInput" accept="image/*" style="display:none;"
                    onchange="openCropModal(this)">
                <div class="profile-avatar-wrap" onclick="document.getElementById('avatarInput').click()"
                    title="Click to change profile photo">
                    <img src="<?= htmlspecialchars($profileImage); ?>" class="profile-avatar" alt="Profile Image">
                    <div class="profile-avatar-overlay">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
            </form>

            <!-- Crop Modal -->
            <div id="cropModal" class="crop-modal-overlay">
                <div class="crop-modal-box">
                    <h3 class="crop-modal-title">Crop Profile Photo</h3>
                    <div class="crop-image-wrap">
                        <img id="cropImage" style="max-width:100%;">
                    </div>
                    <div class="crop-modal-actions">
                        <button type="button" class="btn-crop-cancel" onclick="closeCropModal()">Cancel</button>
                        <button type="button" class="btn-crop-apply" onclick="applyCrop()">Apply &rarr;</button>
                    </div>
                </div>
            </div>

            <div class="profile-identity">
                <h2 class="profile-name"><?= htmlspecialchars($full_name ?: 'Health Worker'); ?></h2>
                <div class="profile-role-badge">
                    <i class="fa-solid fa-shield-halved"></i>
                    <?= htmlspecialchars($role ?: 'Health Worker'); ?>
                </div>
            </div>

            <div class="profile-stats-row">
                <div class="profile-stat-item">
                    <span class="profile-stat-value"><?= htmlspecialchars($barangay_name); ?></span>
                    <span class="profile-stat-label">Barangay</span>
                </div>
                <div class="profile-stat-divider"></div>
                <div class="profile-stat-item">
                    <span class="profile-stat-value"><?= (int) $residentsAssessed; ?></span>
                    <span class="profile-stat-label">Residents Assessed</span>
                </div>
                <div class="profile-stat-divider"></div>
                <div class="profile-stat-item">
                    <span
                        class="profile-stat-value <?= strtolower($status) === 'active' ? 'profile-stat-active' : ''; ?>">
                        <?= htmlspecialchars($status); ?>
                    </span>
                    <span class="profile-stat-label">Status</span>
                </div>
            </div>

        </div>

        <!-- Personal Information -->
        <div class="profile-info-section">

            <div class="profile-info-header">
                <i class="fa-regular fa-circle-user"></i>
                <span>Personal Information</span>
            </div>

            <div class="profile-info-row">
                <span class="profile-info-key">Full Name</span>
                <span class="profile-info-val"><?= htmlspecialchars($full_name ?: 'Not Provided'); ?></span>
            </div>
            <div class="profile-info-row">
                <span class="profile-info-key">Email</span>
                <span class="profile-info-val"><?= htmlspecialchars($email ?: 'Not Provided'); ?></span>
            </div>
            <div class="profile-info-row">
                <span class="profile-info-key">Phone</span>
                <span class="profile-info-val"><?= htmlspecialchars($phone ?: 'Not Provided'); ?></span>
            </div>
            <div class="profile-info-row">
                <span class="profile-info-key">Address</span>
                <span class="profile-info-val"><?= htmlspecialchars($address ?: 'Not Provided'); ?></span>
            </div>
            <!-- <div class="profile-info-row">
                <span class="profile-info-key">Department</span>
                <span class="profile-info-val"><?= htmlspecialchars($department ?: 'Not Assigned'); ?></span>
            </div>
            <div class="profile-info-row">
                <span class="profile-info-key">Role</span>
                <span class="profile-info-val"><?= htmlspecialchars($role ?: 'Health Worker'); ?></span>
            </div> -->
            <div class="profile-info-row">
                <span class="profile-info-key">Date Hired</span>
                <span class="profile-info-val"><?= htmlspecialchars($date_hired ?: 'Not Set'); ?></span>
            </div>

        </div>

        <!-- Action Buttons -->
        <div class="profile-info-actions">
            <a href="#" class="btn-edit-profile" onclick="openEditProfileModal(); return false;">
                <i class="fa-solid fa-pen-to-square"></i> Edit Profile
            </a>
            <a href="#" class="btn-change-password" onclick="openChangePasswordModal(); return false;">
                <i class="fa-solid fa-key"></i> Change Password
            </a>
        </div>

    </section>
</main>


<!-- Edit Profile Modal -->
<div id="editProfileModal" class="modal-overlay">
    <div class="modal-box">

        <div class="modal-header" id="editProfileModalHeader">
            <div class="modal-header-left">
                <div class="modal-header-icon">✏️</div>
                <div class="modal-header-text">
                    <h2>Edit Profile</h2>
                    <p>Update your personal information below</p>
                </div>
            </div>
            <span class="modal-close" onclick="closeEditProfileModal()">&times;</span>
        </div>

        <div class="modal-progress-bar" id="editProfileProgressBar">
            <div class="modal-progress-fill"></div>
        </div>

        <div class="modal-form-content" id="editProfileFormContent">
            <form method="POST" class="form-default modal-form" id="editProfileForm">

                <?= csrfInput() ?>
                <input type="hidden" name="edit_profile" value="1">

                <div class="modal-section-label">Personal Information</div>
                <div class="modal-form-grid">
                    <div class="form-group">
                        <label>Full Name <span class="text-error">*</span></label>
                        <input type="text" name="full_name" class="input-default"
                            value="<?= htmlspecialchars($full_name); ?>" placeholder="e.g. Juan dela Cruz" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="input-default" value="<?= htmlspecialchars($email); ?>"
                            placeholder="e.g. juan@example.com">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" class="input-default" value="<?= htmlspecialchars($phone); ?>"
                            placeholder="+63 9XX XXX XXXX">
                    </div>
                    <!-- <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department" class="input-default"
                            value="<?= htmlspecialchars($department); ?>" placeholder="e.g. Nutrition Department">
                    </div> -->
                </div>

                <div class="modal-section-label">Contact Information</div>
                <div class="modal-form-grid modal-form-grid--single">
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" class="input-default"
                            value="<?= htmlspecialchars($address); ?>" placeholder="Barangay, Municipality, Province">
                    </div>
                </div>

                <div class="modal-actions">
                    <span class="modal-required-note">* Required fields</span>
                    <div class="modal-actions-buttons">
                        <button type="button" class="btn-cancel" onclick="closeEditProfileModal()">Cancel</button>
                        <button type="submit" class="btn-submit-resident">Save Changes &rarr;</button>
                    </div>
                </div>

            </form>
        </div>

        <!-- Success State -->
        <div class="modal-state" id="editProfileSuccessState">
            <div class="s5-ring-wrap">
                <div class="s5-ring success"></div>
                <div class="s5-ring-2 success"></div>
                <div class="s5-state-icon success">
                    <svg viewBox="0 0 24 24">
                        <polyline class="s5-check-path" points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
            </div>
            <div class="s5-state-title success">Profile Updated!</div>
            <div class="s5-state-message">Your profile has been successfully saved.</div>
        </div>

        <!-- Error State -->
        <div class="modal-state" id="editProfileErrorState">
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
            <button class="s5-state-btn error-btn" onclick="resetEditProfileModal()">Try Again</button>
        </div>

    </div>
</div>


<!-- Change Password Modal -->
<div id="changePasswordModal" class="modal-overlay">
    <div class="modal-box">

        <div class="modal-header" id="changePasswordModalHeader">
            <div class="modal-header-left">
                <div class="modal-header-icon">🔑</div>
                <div class="modal-header-text">
                    <h2>Change Password</h2>
                    <p>Enter your current and new password below</p>
                </div>
            </div>
            <span class="modal-close" onclick="closeChangePasswordModal()">&times;</span>
        </div>

        <div class="modal-progress-bar" id="changePasswordProgressBar">
            <div class="modal-progress-fill"></div>
        </div>

        <div class="modal-form-content" id="changePasswordFormContent">
            <form method="POST" class="form-default modal-form" id="changePasswordForm">

                <?= csrfInput() ?>
                <input type="hidden" name="change_password" value="1">

                <?php if ($is_temp_password): ?>
                    <!-- <div class="admin-alert admin-alert-warning">
                        <i class="fas fa-info-circle"></i>
                        You are using a <strong>temporary password</strong>. Please update it now.
                    </div> -->
                <?php endif; ?>

                <div class="modal-section-label">Password Details</div>
                <div class="modal-form-grid modal-form-grid--single">

                    <!-- Current Password -->
                    <div class="form-group">
                        <label>Current Password <span class="text-error">*</span></label>
                        <div class="hw-pwd-wrapper">
                            <input type="password" id="hw-current-password" name="current_password"
                                class="input-default hw-pwd-input" placeholder="Enter your current password"
                                maxlength="255" required>
                            <button type="button" class="hw-pwd-toggle" id="hw-toggle-current"
                                aria-label="Show or hide password" tabindex="-1">
                                <i class="fas fa-eye hw-pwd-toggle-icon" id="hw-toggle-current-icon"></i>
                            </button>
                        </div>
                        <span class="hw-field-error" id="hw-current-password-error">
                            <i class="hw-warn-icon fas fa-exclamation-triangle"></i>
                            <span class="hw-err-text"></span>
                        </span>
                    </div>

                    <!-- New Password -->
                    <div class="form-group">
                        <label>New Password <span class="text-error">*</span></label>
                        <div class="hw-pwd-wrapper">
                            <input type="password" id="hw-new-password" name="new_password"
                                class="input-default hw-pwd-input" placeholder="Enter new password" maxlength="255"
                                required>
                            <button type="button" class="hw-pwd-toggle" id="hw-toggle-new"
                                aria-label="Show or hide password" tabindex="-1">
                                <i class="fas fa-eye hw-pwd-toggle-icon" id="hw-toggle-new-icon"></i>
                            </button>
                        </div>
                        <div class="hw-pwd-strength" id="hw-pwd-strength">
                            <div class="hw-pwd-strength-bar">
                                <div class="hw-pwd-strength-fill" id="hw-pwd-strength-fill"></div>
                            </div>
                            <div class="hw-pwd-strength-label" id="hw-pwd-strength-lbl"></div>
                        </div>
                        <span class="hw-field-error" id="hw-new-password-error">
                            <i class="hw-warn-icon fas fa-exclamation-triangle"></i>
                            <span class="hw-err-text"></span>
                        </span>
                        <p class="hw-form-hint">
                            Min. 8 characters &bull; 1 uppercase &bull; 1 lowercase &bull; 1 number &bull; 1 special
                            character &bull; No spaces
                        </p>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="form-group">
                        <label>Confirm New Password <span class="text-error">*</span></label>
                        <div class="hw-pwd-wrapper">
                            <input type="password" id="hw-confirm-password" name="confirm_password"
                                class="input-default hw-pwd-input" placeholder="Re-enter new password" maxlength="255"
                                required>
                            <button type="button" class="hw-pwd-toggle" id="hw-toggle-confirm"
                                aria-label="Show or hide password" tabindex="-1">
                                <i class="fas fa-eye hw-pwd-toggle-icon" id="hw-toggle-confirm-icon"></i>
                            </button>
                        </div>
                        <span class="hw-field-error" id="hw-confirm-password-error">
                            <i class="hw-warn-icon fas fa-exclamation-triangle"></i>
                            <span class="hw-err-text"></span>
                        </span>
                    </div>

                </div>

                <div class="modal-actions">
                    <span class="modal-required-note">* Required fields</span>
                    <div class="modal-actions-buttons">
                        <button type="button" class="btn-cancel" onclick="closeChangePasswordModal()">Cancel</button>
                        <button type="submit" class="btn-submit-resident">Change Password &rarr;</button>
                    </div>
                </div>

            </form>
        </div>

        <!-- Success State -->
        <div class="modal-state" id="changePasswordSuccessState">
            <div class="s5-ring-wrap">
                <div class="s5-ring success"></div>
                <div class="s5-ring-2 success"></div>
                <div class="s5-state-icon success">
                    <svg viewBox="0 0 24 24">
                        <polyline class="s5-check-path" points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
            </div>
            <div class="s5-state-title success">Password Changed!</div>
            <div class="s5-state-message">Your password has been successfully updated.</div>
        </div>

        <!-- Error State -->
        <div class="modal-state" id="changePasswordErrorState">
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
            <div class="s5-state-message"><?= htmlspecialchars($pwd_error) ?></div>
            <button class="s5-state-btn error-btn" onclick="resetChangePasswordModal()">Try Again</button>
        </div>

    </div>
</div>


<script>

    // Cropper
    let cropper = null;

    function openCropModal(input) {
        if (!input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = function (e) {
            const cropImage = document.getElementById('cropImage');
            cropImage.src = e.target.result;
            const modal = document.getElementById('cropModal');
            modal.style.display = 'flex';
            if (cropper) { cropper.destroy(); cropper = null; }
            cropper = new Cropper(cropImage, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 0.8,
            });
        };
        reader.readAsDataURL(input.files[0]);
    }

    function closeCropModal() {
        document.getElementById('cropModal').style.display = 'none';
        if (cropper) { cropper.destroy(); cropper = null; }
        document.getElementById('avatarInput').value = '';
    }

    function applyCrop() {
        if (!cropper) return;
        const canvas = cropper.getCroppedCanvas({ width: 300, height: 300 });
        document.getElementById('croppedImageData').value = canvas.toDataURL('image/jpeg', 0.9);
        closeCropModal();
        document.getElementById('avatarForm').submit();
    }

    // Modal Helpers
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    function autoClose(cb) { setTimeout(() => cb(), 2100); }

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

    // Edit Profile Modal
    function openEditProfileModal() {
        resetEditProfileModal();
        openModal('editProfileModal');
    }
    function closeEditProfileModal() {
        closeModal('editProfileModal');
        setTimeout(resetEditProfileModal, 300);
    }
    function resetEditProfileModal() {
        resetState('editProfileFormContent', 'editProfileSuccessState', 'editProfileModalHeader', 'editProfileProgressBar');
        resetState('editProfileFormContent', 'editProfileErrorState', 'editProfileModalHeader', 'editProfileProgressBar');
    }

    // Change Password Modal
    function openChangePasswordModal() {
        resetChangePasswordModal();
        openModal('changePasswordModal');
    }
    function closeChangePasswordModal() {
        closeModal('changePasswordModal');
        setTimeout(resetChangePasswordModal, 300);
    }
    function resetChangePasswordModal() {
        resetState('changePasswordFormContent', 'changePasswordSuccessState', 'changePasswordModalHeader', 'changePasswordProgressBar');
        resetState('changePasswordFormContent', 'changePasswordErrorState', 'changePasswordModalHeader', 'changePasswordProgressBar');
    }

    // ESC + Overlay Close
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeEditProfileModal();
            closeChangePasswordModal();
        }
    });
    document.getElementById('editProfileModal').addEventListener('click', function (e) {
        if (e.target === this) closeEditProfileModal();
    });
    document.getElementById('changePasswordModal').addEventListener('click', function (e) {
        if (e.target === this) closeChangePasswordModal();
    });

    // PRG Success Triggers
    <?php if ($show_success): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('editProfileModal');
            showState('editProfileFormContent', 'editProfileSuccessState', 'editProfileModalHeader', 'editProfileProgressBar');
            history.replaceState(null, '', 'profile_health_worker.php');
            autoClose(closeEditProfileModal);
        });
    <?php elseif ($show_pwd_success): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('changePasswordModal');
            showState('changePasswordFormContent', 'changePasswordSuccessState', 'changePasswordModalHeader', 'changePasswordProgressBar');
            history.replaceState(null, '', 'profile_health_worker.php');
            autoClose(closeChangePasswordModal);
        });
    <?php elseif (!empty($edit_error)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('editProfileModal');
            showState('editProfileFormContent', 'editProfileErrorState', 'editProfileModalHeader', 'editProfileProgressBar');
        });
    <?php elseif (!empty($pwd_error)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('changePasswordModal');
            showState('changePasswordFormContent', 'changePasswordErrorState', 'changePasswordModalHeader', 'changePasswordProgressBar');
        });
    <?php endif; ?>

    // Show/Hide Password Toggles
    function hwPwdToggle(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (!input || !icon) return;
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    document.getElementById('hw-toggle-current').addEventListener('click', () => hwPwdToggle('hw-current-password', 'hw-toggle-current-icon'));
    document.getElementById('hw-toggle-new').addEventListener('click', () => hwPwdToggle('hw-new-password', 'hw-toggle-new-icon'));
    document.getElementById('hw-toggle-confirm').addEventListener('click', () => hwPwdToggle('hw-confirm-password', 'hw-toggle-confirm-icon'));

    // Password Strength Bar
    const hwNewPwd = document.getElementById('hw-new-password');
    const hwStrEl = document.getElementById('hw-pwd-strength');
    const hwStrLbl = document.getElementById('hw-pwd-strength-lbl');

    function hwStrengthScore(pwd) {
        let s = 0;
        if (pwd.length >= 8) s++;
        if (/[A-Z]/.test(pwd)) s++;
        if (/[a-z]/.test(pwd)) s++;
        if (/\d/.test(pwd)) s++;
        if (/[\W_]/.test(pwd)) s++;
        return s;
    }

    function hwUpdateStrength(pwd) {
        if (!pwd) { hwStrEl.style.display = 'none'; return; }
        hwStrEl.style.display = 'block';
        hwStrEl.className = 'hw-pwd-strength';
        const s = hwStrengthScore(pwd);
        if (s <= 1) { hwStrEl.classList.add('weak'); hwStrLbl.textContent = 'Strength: Weak'; }
        else if (s === 2) { hwStrEl.classList.add('fair'); hwStrLbl.textContent = 'Strength: Fair'; }
        else if (s <= 4) { hwStrEl.classList.add('good'); hwStrLbl.textContent = 'Strength: Good'; }
        else { hwStrEl.classList.add('strong'); hwStrLbl.textContent = 'Strength: Strong'; }
    }

    hwNewPwd.addEventListener('input', function () { hwUpdateStrength(this.value); });

    // Field Error Helpers
    function hwShowError(fieldId, msg) {
        const inp = document.getElementById(fieldId);
        const err = document.getElementById(fieldId + '-error');
        if (inp) inp.classList.add('input-invalid');
        if (err) {
            err.querySelector('.hw-err-text').textContent = msg;
            err.classList.add('show');
        }
    }

    function hwClearError(fieldId) {
        const inp = document.getElementById(fieldId);
        const err = document.getElementById(fieldId + '-error');
        if (inp) inp.classList.remove('input-invalid');
        if (err) {
            err.querySelector('.hw-err-text').textContent = '';
            err.classList.remove('show');
        }
    }

    // Block Spaces in Password Fields
    ['hw-current-password', 'hw-new-password', 'hw-confirm-password'].forEach(id => {
        document.getElementById(id).addEventListener('keydown', e => {
            if (e.key === ' ') e.preventDefault();
        });
    });

    // Blur Validation
    document.getElementById('hw-current-password').addEventListener('blur', function () {
        if (!this.value.trim()) { hwShowError('hw-current-password', 'Current password is required.'); return; }
        hwClearError('hw-current-password');
    });

    document.getElementById('hw-new-password').addEventListener('blur', function () {
        if (!this.value) { hwShowError('hw-new-password', 'New password is required.'); return; }
        if (/\s/.test(this.value)) { hwShowError('hw-new-password', 'Password must not contain spaces.'); return; }
        if (this.value.length < 8) { hwShowError('hw-new-password', 'Must be at least 8 characters.'); return; }
        if (!/[A-Z]/.test(this.value)) { hwShowError('hw-new-password', 'Include at least 1 uppercase letter.'); return; }
        if (!/[a-z]/.test(this.value)) { hwShowError('hw-new-password', 'Include at least 1 lowercase letter.'); return; }
        if (!/\d/.test(this.value)) { hwShowError('hw-new-password', 'Include at least 1 number.'); return; }
        if (!/[\W_]/.test(this.value)) { hwShowError('hw-new-password', 'Include at least 1 special character.'); return; }
        hwClearError('hw-new-password');
    });

    document.getElementById('hw-confirm-password').addEventListener('blur', function () {
        const newPwd = document.getElementById('hw-new-password').value;
        if (!this.value) { hwShowError('hw-confirm-password', 'Please confirm your new password.'); return; }
        if (this.value !== newPwd) { hwShowError('hw-confirm-password', 'Passwords do not match.'); return; }
        hwClearError('hw-confirm-password');
    });

    // Submit Validation
    document.getElementById('changePasswordForm').addEventListener('submit', function (e) {
        let hasErr = false;
        const cur = document.getElementById('hw-current-password');
        const nwd = document.getElementById('hw-new-password');
        const cfm = document.getElementById('hw-confirm-password');

        if (!cur.value.trim()) {
            hwShowError('hw-current-password', 'Current password is required.');
            hasErr = true;
        } else {
            hwClearError('hw-current-password');
        }

        if (!nwd.value) {
            hwShowError('hw-new-password', 'New password is required.');
            hasErr = true;
        } else if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/.test(nwd.value)) {
            hwShowError('hw-new-password', 'Password does not meet the requirements.');
            hasErr = true;
        } else {
            hwClearError('hw-new-password');
        }

        if (!cfm.value) {
            hwShowError('hw-confirm-password', 'Please confirm your new password.');
            hasErr = true;
        } else if (cfm.value !== nwd.value) {
            hwShowError('hw-confirm-password', 'Passwords do not match.');
            hasErr = true;
        } else {
            hwClearError('hw-confirm-password');
        }

        if (hasErr) {
            e.preventDefault();
            const first = document.querySelector('.input-invalid');
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
</script>

<?php include "../../includes/footer.php"; ?>