<?php
// --------------------------------------
// DATABASE & FUNCTIONS
// --------------------------------------
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];
$error = "";
$success = "";

// --------------------------------------
// FETCH CURRENT ADMIN DATA
// --------------------------------------
$stmt = $pdo->prepare("SELECT * FROM admins WHERE admin_id = :admin_id LIMIT 1");
$stmt->execute(['admin_id' => $admin_id]);
$admin = $stmt->fetch();

if (!$admin) {
    session_destroy();
    header("Location: login_admin.php");
    exit();
}

// --------------------------------------
// FORM HANDLING
// --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {

        $action = $_POST['action'] ?? '';

        // ── Quick Avatar Upload ──
        if ($action === 'update_avatar') {
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
                            $stmt = $pdo->prepare("UPDATE admins SET profile_image = :profile_image WHERE admin_id = :admin_id");
                            $stmt->execute(['profile_image' => $img, 'admin_id' => $admin_id]);
                            header("Location: profile_admin.php?updated=1");
                            exit();
                        } else {
                            $error = "Failed to save cropped image.";
                        }
                    }
                }
            } elseif (!empty($_FILES['profile_image']['name'])) {
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
                $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) {
                    $error = "Invalid image format. Allowed: jpg, jpeg, png, webp.";
                } elseif ($_FILES['profile_image']['size'] > 5 * 1024 * 1024) {
                    $error = "Image must be under 5MB.";
                } else {
                    $uploadDir = '../../assets/images/profiles/';
                    $filename = time() . '_' . basename($_FILES['profile_image']['name']);
                    $targetPath = $uploadDir . $filename;
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetPath)) {
                        $profile_image_path = 'assets/images/profiles/' . $filename;
                        $stmt = $pdo->prepare("UPDATE admins SET profile_image = :profile_image WHERE admin_id = :admin_id");
                        $stmt->execute(['profile_image' => $profile_image_path, 'admin_id' => $admin_id]);
                        header("Location: profile_admin.php?updated=1");
                        exit();
                    } else {
                        $error = "Failed to upload image.";
                    }
                }
            }

            // ── Update Profile Info ──
        } elseif ($action === 'update_profile') {
            $full_name = sanitizeInput($_POST['full_name']);
            $email = sanitizeInput($_POST['email']);
            $username = sanitizeInput($_POST['username']);

            if (!validateRequired($full_name)) {
                $error = "Full name is required.";
            } elseif (!validateRequired($username)) {
                $error = "Username is required.";
            } elseif (!empty($email) && !validateEmail($email)) {
                $error = "Invalid email format.";
            } else {
                $checkStmt = $pdo->prepare("SELECT admin_id FROM admins WHERE username = :username AND admin_id != :admin_id LIMIT 1");
                $checkStmt->execute([':username' => $username, ':admin_id' => $admin_id]);
                if ($checkStmt->fetch()) {
                    $error = "Username is already taken. Please choose another.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE admins
                        SET full_name = :full_name,
                            email     = :email,
                            username  = :username
                        WHERE admin_id = :admin_id
                    ");
                    $stmt->execute([
                        'full_name' => $full_name,
                        'email' => $email ?: null,
                        'username' => $username,
                        'admin_id' => $admin_id,
                    ]);
                    $_SESSION['admin_name'] = $full_name;
                    header("Location: profile_admin.php?updated=1");
                    exit();
                }
            }

            // ── Change Password ──
        } elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error = "All password fields are required.";
            } elseif (!password_verify($current_password, $admin['password'])) {
                $error = "Current password is incorrect.";
            } elseif ($new_password !== $confirm_password) {
                $error = "New passwords do not match.";
            } elseif (!isValidPassword($new_password)) {
                $error = "New password must be at least 8 characters and include 1 uppercase, 1 lowercase, and 1 number.";
            } elseif (password_verify($new_password, $admin['password'])) {
                $error = "New password must be different from your current password.";
            } else {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET password = :password WHERE admin_id = :admin_id");
                $stmt->execute(['password' => $hashed, 'admin_id' => $admin_id]);
                header("Location: profile_admin.php?pwd_updated=1");
                exit();
            }
        }
    }
}

// --------------------------------------
// DETECT SUCCESS FLAGS (PRG)
// --------------------------------------
$show_success = isset($_GET['updated']) && $_GET['updated'] === '1';
$show_pwd_success = isset($_GET['pwd_updated']) && $_GET['pwd_updated'] === '1';

// --------------------------------------
// REFRESH ADMIN DATA
// --------------------------------------
$stmt = $pdo->prepare("SELECT * FROM admins WHERE admin_id = :admin_id LIMIT 1");
$stmt->execute(['admin_id' => $admin_id]);
$admin = $stmt->fetch();

$full_name = $admin['full_name'] ?? '';
$username = $admin['username'] ?? '';
$email = $admin['email'] ?? '';
$role = $admin['role'] ?? 'Admin';
$created_at = $admin['created_at'] ?? '';

$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $admin['barangay_id'] ?? 0]);
$admin_barangay = $brgyStmt->fetchColumn() ?: 'N/A';

$profileImage = !empty($admin['profile_image'])
    ? '../../' . $admin['profile_image']
    : null;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Profile | NutriTrack Admin</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
</head>

<body>

    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <?php include __DIR__ . '/../../includes/sidebar_admin.php'; ?>

    <main class="dashboard">

        <h1 class="page-title">My Profile</h1>

        <section class="section">

            <!-- ── TOP ROW: Avatar + Name + Stats ── -->
            <div class="admin-profile-card-header">

                <!-- ── Clickable avatar ── -->
                <form method="POST" enctype="multipart/form-data" id="avatarForm">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="update_avatar">
                    <input type="hidden" name="cropped_image" id="croppedImageData">
                    <input type="file" name="profile_image" id="avatarInput" accept="image/*" style="display:none;"
                        onchange="openCropModal(this)">

                    <div class="admin-profile-card-icon" onclick="document.getElementById('avatarInput').click()"
                        title="Click to change profile photo">
                        <?php if ($profileImage): ?>
                            <img src="<?= htmlspecialchars($profileImage) ?>" alt="Profile Photo">
                        <?php else: ?>
                            <i class="fas fa-user-shield"></i>
                        <?php endif; ?>
                        <div class="admin-profile-card-icon-overlay">
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

                <!-- ── Name + meta ── -->
                <div class="admin-profile-card-header-text">
                    <h2 class="admin-profile-card-name"><?= htmlspecialchars($full_name) ?></h2>
                    <p class="admin-profile-card-meta">
                        <i class="fas fa-shield-alt" style="font-size:11px;"></i>
                        Administrator &bull; @<?= htmlspecialchars($username) ?>
                    </p>
                </div>

                <!-- ── Stats row ── -->
                <div class="admin-profile-stats-row">
                    <div class="admin-profile-stat-item">
                        <span class="admin-profile-stat-value"><?= htmlspecialchars($admin_barangay) ?></span>
                        <span class="admin-profile-stat-label">Barangay</span>
                    </div>
                    <div class="admin-profile-stat-divider"></div>
                    <div class="admin-profile-stat-item">
                        <span class="admin-profile-stat-value"><?= ucfirst(htmlspecialchars($role)) ?></span>
                        <span class="admin-profile-stat-label">Role</span>
                    </div>
                    <div class="admin-profile-stat-divider"></div>
                    <div class="admin-profile-stat-item">
                        <span class="admin-profile-stat-value">
                            <?= $created_at ? date('M d, Y', strtotime($created_at)) : '—' ?>
                        </span>
                        <span class="admin-profile-stat-label">Member Since</span>
                    </div>
                </div>

            </div>

            <!-- ── PERSONAL INFORMATION ── -->
            <div class="admin-profile-info-section">

                <div class="admin-profile-info-header">
                    <i class="fa-regular fa-circle-user"></i>
                    <span>Personal Information</span>
                </div>

                <div class="admin-profile-info-row">
                    <span class="admin-profile-info-key">Full Name</span>
                    <span class="admin-profile-info-val"><?= htmlspecialchars($full_name ?: 'Not Provided') ?></span>
                </div>
                <div class="admin-profile-info-row">
                    <span class="admin-profile-info-key">Email</span>
                    <span class="admin-profile-info-val"><?= htmlspecialchars($email ?: 'Not Provided') ?></span>
                </div>
                <div class="admin-profile-info-row">
                    <span class="admin-profile-info-key">Username</span>
                    <span class="admin-profile-info-val">@<?= htmlspecialchars($username ?: 'Not Set') ?></span>
                </div>
                <!-- <div class="admin-profile-info-row">
                    <span class="admin-profile-info-key">Role</span>
                    <span class="admin-profile-info-val"><?= ucfirst(htmlspecialchars($role)) ?></span>
                </div> -->
                <div class="admin-profile-info-row">
                    <span class="admin-profile-info-key">Member Since</span>
                    <span class="admin-profile-info-val">
                        <?= $created_at ? date('F d, Y', strtotime($created_at)) : 'Not Set' ?>
                    </span>
                </div>
                <div class="admin-profile-info-row">
                    <span class="admin-profile-info-key">Status</span>
                    <span class="admin-profile-info-val">
                        <span class="admin-profile-status-dot"></span> Active
                    </span>
                </div>

            </div>

            <!-- ── Action Buttons ── -->
            <div class="admin-profile-info-actions">
                <a href="#" class="admin-btn-edit-profile" onclick="openEditProfileModal(); return false;">
                    <i class="fas fa-pen-to-square"></i> Edit Profile
                </a>
                <a href="#" class="admin-btn-change-password" onclick="openChangePasswordModal(); return false;">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </div>

        </section>
    </main>


    <!-- ══════════════════════════════════════
     EDIT PROFILE MODAL
══════════════════════════════════════ -->
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
                <form method="POST" class="modal-form" id="editProfileForm">

                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="modal-section-label">Personal Information</div>
                    <div class="modal-form-grid">
                        <div class="form-group">
                            <label>Full Name <span class="text-error">*</span></label>
                            <input type="text" name="full_name" class="input-default"
                                value="<?= htmlspecialchars($full_name) ?>" placeholder="e.g. Juan Dela Cruz" required>
                        </div>
                        <div class="form-group">
                            <label>Username <span class="text-error">*</span></label>
                            <input type="text" name="username" class="input-default"
                                value="<?= htmlspecialchars($username) ?>" placeholder="e.g. admin123" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="input-default"
                                value="<?= htmlspecialchars($email) ?>" placeholder="e.g. admin@email.com">
                        </div>
                    </div>

                    <div class="modal-actions">
                        <span class="modal-required-note">* Required fields</span>
                        <div class="modal-actions-buttons">
                            <button type="button" class="btn-cancel" onclick="closeEditProfileModal()">Cancel</button>
                            <button type="submit" class="btn-submit-resident">
                                Save Changes &rarr;
                            </button>
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
                <div class="s5-state-message"><?= htmlspecialchars($error) ?></div>
                <button class="s5-state-btn error-btn" onclick="resetEditProfileModal()">Try Again</button>
            </div>

        </div>
    </div>


    <!-- ══════════════════════════════════════
     CHANGE PASSWORD MODAL
══════════════════════════════════════ -->
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
                <form method="POST" class="modal-form" id="changePasswordForm">

                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="modal-section-label">Password Details</div>
                    <div class="modal-form-grid modal-form-grid--single">

                        <!-- Current Password -->
                        <div class="form-group">
                            <label>Current Password <span class="text-error">*</span></label>
                            <div class="admin-pwd-wrapper">
                                <input type="password" id="admin-current-password" name="current_password"
                                    class="input-default admin-pwd-input" placeholder="Enter your current password"
                                    maxlength="255" required>
                                <button type="button" class="admin-pwd-toggle" id="admin-toggle-current" tabindex="-1">
                                    <i class="fas fa-eye admin-pwd-toggle-icon" id="admin-toggle-current-icon"></i>
                                </button>
                            </div>
                            <span class="admin-pwd-field-error" id="admin-current-password-error">
                                <i class="admin-pwd-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                        </div>

                        <!-- New Password -->
                        <div class="form-group">
                            <label>New Password <span class="text-error">*</span></label>
                            <div class="admin-pwd-wrapper">
                                <input type="password" id="admin-new-password" name="new_password"
                                    class="input-default admin-pwd-input" placeholder="Enter new password"
                                    maxlength="255" required>
                                <button type="button" class="admin-pwd-toggle" id="admin-toggle-new" tabindex="-1">
                                    <i class="fas fa-eye admin-pwd-toggle-icon" id="admin-toggle-new-icon"></i>
                                </button>
                            </div>
                            <div class="admin-pwd-strength" id="admin-pwd-strength">
                                <div class="admin-pwd-strength-bar">
                                    <div class="admin-pwd-strength-fill" id="admin-pwd-strength-fill"></div>
                                </div>
                                <div class="admin-pwd-strength-label" id="admin-pwd-strength-lbl"></div>
                            </div>
                            <span class="admin-pwd-field-error" id="admin-new-password-error">
                                <i class="admin-pwd-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                            <p class="admin-pwd-hint">
                                Min. 8 characters &bull; 1 uppercase &bull; 1 lowercase &bull; 1 number
                            </p>
                        </div>

                        <!-- Confirm New Password -->
                        <div class="form-group">
                            <label>Confirm New Password <span class="text-error">*</span></label>
                            <div class="admin-pwd-wrapper">
                                <input type="password" id="admin-confirm-password" name="confirm_password"
                                    class="input-default admin-pwd-input" placeholder="Re-enter new password"
                                    maxlength="255" required>
                                <button type="button" class="admin-pwd-toggle" id="admin-toggle-confirm" tabindex="-1">
                                    <i class="fas fa-eye admin-pwd-toggle-icon" id="admin-toggle-confirm-icon"></i>
                                </button>
                            </div>
                            <span class="admin-pwd-field-error" id="admin-confirm-password-error">
                                <i class="admin-pwd-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="admin-err-text"></span>
                            </span>
                        </div>

                    </div>

                    <div class="modal-actions">
                        <span class="modal-required-note">* Required fields</span>
                        <div class="modal-actions-buttons">
                            <button type="button" class="btn-cancel"
                                onclick="closeChangePasswordModal()">Cancel</button>
                            <button type="submit" class="btn-submit-resident">
                                Change Password &rarr;
                            </button>
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
                <div class="s5-state-message"><?= htmlspecialchars($error) ?></div>
                <button class="s5-state-btn error-btn" onclick="resetChangePasswordModal()">Try Again</button>
            </div>

        </div>
    </div>


    <?php include __DIR__ . '/../../includes/footer.php'; ?>

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

        // ── Generic modal helpers ──
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

        // ── Edit Profile Modal ──
        function openEditProfileModal() { resetEditProfileModal(); openModal('editProfileModal'); }
        function closeEditProfileModal() { closeModal('editProfileModal'); setTimeout(resetEditProfileModal, 300); }
        function resetEditProfileModal() {
            resetState('editProfileFormContent', 'editProfileSuccessState', 'editProfileModalHeader', 'editProfileProgressBar');
            resetState('editProfileFormContent', 'editProfileErrorState', 'editProfileModalHeader', 'editProfileProgressBar');
        }

        // ── Change Password Modal ──
        function openChangePasswordModal() { resetChangePasswordModal(); openModal('changePasswordModal'); }
        function closeChangePasswordModal() { closeModal('changePasswordModal'); setTimeout(resetChangePasswordModal, 300); }
        function resetChangePasswordModal() {
            resetState('changePasswordFormContent', 'changePasswordSuccessState', 'changePasswordModalHeader', 'changePasswordProgressBar');
            resetState('changePasswordFormContent', 'changePasswordErrorState', 'changePasswordModalHeader', 'changePasswordProgressBar');
        }

        // ── Admin Password Toggle ──
        function adminPwdToggle(inputId, iconId) {
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
        document.getElementById('admin-toggle-current').addEventListener('click', () => adminPwdToggle('admin-current-password', 'admin-toggle-current-icon'));
        document.getElementById('admin-toggle-new').addEventListener('click', () => adminPwdToggle('admin-new-password', 'admin-toggle-new-icon'));
        document.getElementById('admin-toggle-confirm').addEventListener('click', () => adminPwdToggle('admin-confirm-password', 'admin-toggle-confirm-icon'));

        // ── Admin Password Strength ──
        document.getElementById('admin-new-password').addEventListener('input', function () {
            const pwd = this.value;
            const strEl = document.getElementById('admin-pwd-strength');
            const lbl = document.getElementById('admin-pwd-strength-lbl');
            if (!pwd) { strEl.style.display = 'none'; return; }
            strEl.style.display = 'block';
            strEl.className = 'admin-pwd-strength';
            let s = 0;
            if (pwd.length >= 8) s++;
            if (/[A-Z]/.test(pwd)) s++;
            if (/[a-z]/.test(pwd)) s++;
            if (/\d/.test(pwd)) s++;
            if (/[\W_]/.test(pwd)) s++;
            if (s <= 1) { strEl.classList.add('weak'); lbl.textContent = 'Strength: Weak'; }
            else if (s === 2) { strEl.classList.add('fair'); lbl.textContent = 'Strength: Fair'; }
            else if (s <= 4) { strEl.classList.add('good'); lbl.textContent = 'Strength: Good'; }
            else { strEl.classList.add('strong'); lbl.textContent = 'Strength: Strong'; }
        });

        // ── Admin Field Error Helpers ──
        function adminShowError(fieldId, msg) {
            const inp = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '-error');
            if (inp) inp.classList.add('admin-input-invalid');
            if (err) { err.querySelector('.admin-err-text').textContent = msg; err.classList.add('show'); }
        }
        function adminClearError(fieldId) {
            const inp = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '-error');
            if (inp) inp.classList.remove('admin-input-invalid');
            if (err) { err.querySelector('.admin-err-text').textContent = ''; err.classList.remove('show'); }
        }

        // ── Block spaces ──
        ['admin-current-password', 'admin-new-password', 'admin-confirm-password'].forEach(id => {
            document.getElementById(id).addEventListener('keydown', e => { if (e.key === ' ') e.preventDefault(); });
        });

        // ── Blur validation ──
        document.getElementById('admin-current-password').addEventListener('blur', function () {
            if (!this.value.trim()) { adminShowError('admin-current-password', 'Current password is required.'); return; }
            adminClearError('admin-current-password');
        });
        document.getElementById('admin-new-password').addEventListener('blur', function () {
            if (!this.value) { adminShowError('admin-new-password', 'New password is required.'); return; }
            if (/\s/.test(this.value)) { adminShowError('admin-new-password', 'Password must not contain spaces.'); return; }
            if (this.value.length < 8) { adminShowError('admin-new-password', 'Must be at least 8 characters.'); return; }
            if (!/[A-Z]/.test(this.value)) { adminShowError('admin-new-password', 'Include at least 1 uppercase letter.'); return; }
            if (!/[a-z]/.test(this.value)) { adminShowError('admin-new-password', 'Include at least 1 lowercase letter.'); return; }
            if (!/\d/.test(this.value)) { adminShowError('admin-new-password', 'Include at least 1 number.'); return; }
            adminClearError('admin-new-password');
        });
        document.getElementById('admin-confirm-password').addEventListener('blur', function () {
            const newPwd = document.getElementById('admin-new-password').value;
            if (!this.value) { adminShowError('admin-confirm-password', 'Please confirm your new password.'); return; }
            if (this.value !== newPwd) { adminShowError('admin-confirm-password', 'Passwords do not match.'); return; }
            adminClearError('admin-confirm-password');
        });

        // ── Submit validation ──
        document.getElementById('changePasswordForm').addEventListener('submit', function (e) {
            let hasErr = false;
            const cur = document.getElementById('admin-current-password');
            const nwd = document.getElementById('admin-new-password');
            const cfm = document.getElementById('admin-confirm-password');

            if (!cur.value.trim()) { adminShowError('admin-current-password', 'Current password is required.'); hasErr = true; }
            else adminClearError('admin-current-password');

            if (!nwd.value) { adminShowError('admin-new-password', 'New password is required.'); hasErr = true; }
            else if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/.test(nwd.value)) { adminShowError('admin-new-password', 'Password does not meet the requirements.'); hasErr = true; }
            else adminClearError('admin-new-password');

            if (!cfm.value) { adminShowError('admin-confirm-password', 'Please confirm your new password.'); hasErr = true; }
            else if (cfm.value !== nwd.value) { adminShowError('admin-confirm-password', 'Passwords do not match.'); hasErr = true; }
            else adminClearError('admin-confirm-password');

            if (hasErr) {
                e.preventDefault();
                const first = document.querySelector('.admin-input-invalid');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // ── Close on ESC ──
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeEditProfileModal();
                closeChangePasswordModal();
            }
        });

        // ── Close on overlay click ──
        document.getElementById('editProfileModal').addEventListener('click', function (e) {
            if (e.target === this) closeEditProfileModal();
        });
        document.getElementById('changePasswordModal').addEventListener('click', function (e) {
            if (e.target === this) closeChangePasswordModal();
        });

        // ── PRG success triggers ──
        <?php if ($show_success): ?>
            window.addEventListener('DOMContentLoaded', () => {
                openModal('editProfileModal');
                showState('editProfileFormContent', 'editProfileSuccessState', 'editProfileModalHeader', 'editProfileProgressBar');
                history.replaceState(null, '', 'profile_admin.php');
                autoClose(closeEditProfileModal);
            });
        <?php elseif ($show_pwd_success): ?>
            window.addEventListener('DOMContentLoaded', () => {
                openModal('changePasswordModal');
                showState('changePasswordFormContent', 'changePasswordSuccessState', 'changePasswordModalHeader', 'changePasswordProgressBar');
                history.replaceState(null, '', 'profile_admin.php');
                autoClose(closeChangePasswordModal);
            });
        <?php elseif (!empty($error)): ?>
            window.addEventListener('DOMContentLoaded', () => {
                openModal('<?= $action === "update_profile" ? "editProfileModal" : "changePasswordModal" ?>');
                showState(
                    '<?= $action === "update_profile" ? "editProfileFormContent" : "changePasswordFormContent" ?>',
                    '<?= $action === "update_profile" ? "editProfileErrorState" : "changePasswordErrorState" ?>',
                    '<?= $action === "update_profile" ? "editProfileModalHeader" : "changePasswordModalHeader" ?>',
                    '<?= $action === "update_profile" ? "editProfileProgressBar" : "changePasswordProgressBar" ?>'
                );
            });
        <?php endif; ?>
    </script>

</body>

</html>