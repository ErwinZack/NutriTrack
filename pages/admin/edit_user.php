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
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}

$admin_id = (int) $_SESSION['admin_id'];
// $admin_full_name = $_SESSION['full_name'] ?? 'Unknown';
$admin_full_name = $_SESSION['admin_full_name'] ?? $_SESSION['full_name'] ?? 'Unknown';
$barangay_id = $_SESSION['barangay_id'];

// --------------------------------------
// LOAD USER
// --------------------------------------
if (!isset($_GET['worker_id'])) {
    header("Location: user_management.php");
    exit();
}

$worker_id = intval($_GET['worker_id']);

$stmt = $pdo->prepare("SELECT * FROM health_workers WHERE worker_id = :worker_id AND barangay_id = :brgy LIMIT 1");
$stmt->execute(['worker_id' => $worker_id, 'brgy' => $barangay_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: user_management.php");
    exit();
}

// --------------------------------------
// FORM HANDLING
// --------------------------------------
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $full_name = sanitizeInput($_POST['full_name']);
        $username = sanitizeInput($_POST['username']);
        $email = sanitizeInput($_POST['email']);
        $phone = sanitizeInput($_POST['phone']);
        $address = sanitizeInput($_POST['address']);
        $department = 'Health Worker';
        $date_hired = sanitizeInput($_POST['date_hired']);
        $status = sanitizeInput($_POST['status']);

        if (!validateRequired($full_name) || !validateRequired($username)) {
            $error = "Please fill in all required fields.";
        } elseif (!empty($email) && !validateEmail($email)) {
            $error = "Invalid email format.";
        } elseif (!empty($date_hired) && !validateDate($date_hired)) {
            $error = "Invalid date hired format.";
        } elseif (!in_array($status, ['Active', 'Inactive'])) {
            $error = "Invalid status value.";
        } elseif (strlen($username) < 4) {
            $error = "Username must be at least 4 characters long.";
        } elseif (preg_match('/\s/', $username)) {
            $error = "Username must not contain spaces.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = "Username can only contain letters, numbers, and underscores.";
        }

        if (empty($error)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_workers WHERE username = :username AND worker_id != :worker_id");
            $stmt->execute(['username' => $username, 'worker_id' => $worker_id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username already exists. Choose another one.";
            }

            if (empty($error) && !empty($email)) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_workers WHERE email = :email AND worker_id != :worker_id");
                $stmt->execute(['email' => $email, 'worker_id' => $worker_id]);
                if ($stmt->fetchColumn() > 0) {
                    $error = "Email already exists. Use a different email.";
                }
            }
        }

        if (empty($error)) {
            $stmt = $pdo->prepare("
                UPDATE health_workers
                SET full_name  = :full_name,
                    username   = :username,
                    email      = :email,
                    phone      = :phone,
                    address    = :address,
                    department = :department,
                    date_hired = :date_hired,
                    status     = :status
                WHERE worker_id = :worker_id AND barangay_id = :brgy
            ");
            $params = [
                'full_name' => $full_name,
                'username' => $username,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'address' => $address ?: null,
                'department' => $department ?: null,
                'date_hired' => $date_hired ?: null,
                'status' => $status,
                'worker_id' => $worker_id,
                'brgy' => $barangay_id,
            ];

            $stmt->execute($params);

            logAudit(
                $pdo,
                'admin',
                $admin_id,
                'EDIT_USER',
                "Admin '{$admin_full_name}' edited health worker. Name: {$full_name}" .
                ", Username: {$username}" .
                ", Department: " . ($department ?: 'N/A') .
                ", Status: {$status}" .
                " (Worker ID: {$worker_id})",
                $barangay_id
            );
            $success = "Health worker account updated successfully!";

            $nStmt = $pdo->prepare("INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message) VALUES (:id, 'health_worker', :sid, 'admin', 'info', :msg)");
            $nStmt->execute([':id' => $worker_id, ':sid' => $admin_id, ':msg' => "Your account details have been updated by the administrator."]);

            // [NOTIFICATION] Notify municipal — admin edited a health worker
            $superadmins = $pdo->query("SELECT superadmin_id FROM superadmins");
            foreach ($superadmins->fetchAll() as $sa) {
                $saStmt = $pdo->prepare("
                    INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message, barangay_id)
                    VALUES (:id, 'superadmin', :sid, 'admin', 'info', :msg, :barangay_id)
                ");
                $saStmt->execute([
                    ':id' => $sa['superadmin_id'],
                    ':sid' => $admin_id,
                    ':msg' => "Admin '{$admin_full_name}' updated health worker: {$full_name}. Status: {$status}.",
                    ':barangay_id' => $barangay_id,
                ]);
            }

            $stmt = $pdo->prepare("SELECT * FROM health_workers WHERE worker_id = :worker_id AND barangay_id = :brgy LIMIT 1");
            $stmt->execute(['worker_id' => $worker_id, 'brgy' => $barangay_id]);
            $user = $stmt->fetch();
        }
    }
}

function old($field, $dbValue = '')
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return htmlspecialchars($_POST[$field] ?? $dbValue);
    }
    return htmlspecialchars($dbValue);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit User | NutriTrack Admin</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>

<body>

    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <?php include __DIR__ . '/../../includes/sidebar_admin.php'; ?>

    <main class="dashboard">
        <h1 class="page-title">Edit User</h1>

        <section class="section">
            <div class="admin-card">

                <!-- ── Card Header ── -->
                <div class="admin-card-header">
                    <div class="admin-card-icon">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Edit Health Worker Account</h2>
                        <p class="admin-card-subtitle">
                            Editing account for <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                        </p>
                    </div>
                </div>

                <!-- ── Alerts ── -->
                <?php if (!empty($success)): ?>
                    <div class="admin-alert admin-alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                    <div class="admin-alert admin-alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- ── Form ── -->
                <form method="POST" action="" class="admin-form">
                    <?= csrfInput() ?>

                    <!-- ── Section 1: Personal Information ── -->
                    <div class="admin-form-section">Personal Information</div>
                    <div class="admin-form-grid">

                        <div class="admin-form-group">
                            <label class="admin-form-label">
                                Full Name <span class="admin-form-required">*</span>
                            </label>
                            <input type="text" name="full_name" class="admin-form-input"
                                placeholder="e.g. Juan dela Cruz" value="<?= old('full_name', $user['full_name']) ?>"
                                required>
                        </div>

                        <div class="admin-form-group">
                            <label class="admin-form-label">Phone</label>
                            <input type="text" name="phone" class="admin-form-input" placeholder="e.g. 09XX XXX XXXX"
                                value="<?= old('phone', $user['phone'] ?? '') ?>">
                        </div>

                        <div class="admin-form-group admin-form-group-full">
                            <label class="admin-form-label">Address</label>
                            <input type="text" name="address" class="admin-form-input"
                                placeholder="e.g. Barangay, Municipality, Province"
                                value="<?= old('address', $user['address'] ?? '') ?>">
                        </div>

                    </div>

                    <!-- ── Section 2: Account Details ── -->
                    <div class="admin-form-section">Account Details</div>
                    <div class="admin-form-grid">

                        <div class="admin-form-group">
                            <label class="admin-form-label">
                                Username <span class="admin-form-required">*</span>
                            </label>
                            <input type="text" name="username" class="admin-form-input" placeholder="e.g. jdelacruz"
                                value="<?= old('username', $user['username']) ?>" required minlength="4"
                                pattern="[a-zA-Z0-9_]+" title="Letters, numbers, and underscores only. No spaces.">
                            <p class="admin-form-hint">
                                Min. 4 characters &bull; Letters, numbers, underscores only &bull; No spaces
                            </p>
                        </div>

                        <div class="admin-form-group">
                            <label class="admin-form-label">Email</label>
                            <input type="email" name="email" class="admin-form-input" placeholder="e.g. juan@email.com"
                                value="<?= old('email', $user['email'] ?? '') ?>">
                        </div>


                    </div>

                    <!-- ── Section 3: Employment Details ── -->
                    <div class="admin-form-section">Employment Details</div>
                    <div class="admin-form-grid">


                        <div class="admin-form-group">
                            <label class="admin-form-label">Date Hired</label>
                            <input type="date" name="date_hired" class="admin-form-input"
                                value="<?= old('date_hired', $user['date_hired'] ?? '') ?>">
                        </div>

                        <div class="admin-form-group">
                            <label class="admin-form-label">Status</label>
                            <select name="status" class="admin-form-input">
                                <option value="Active" <?= old('status', $user['status']) === 'Active' ? 'selected' : '' ?>>
                                    Active
                                </option>
                                <option value="Inactive" <?= old('status', $user['status']) === 'Inactive' ? 'selected' : '' ?>>
                                    Inactive
                                </option>
                            </select>
                        </div>

                    </div>

                    <!-- ── Form Actions ── -->
                    <div class="admin-form-actions">
                        <span class="admin-form-required-note">* Required fields</span>
                        <div class="admin-form-actions-buttons">
                            <a href="user_management.php" class="admin-btn admin-btn-cancel">
                                Cancel
                            </a>
                            <button type="submit" class="admin-btn admin-btn-submit">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </div>

                </form>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>

        <script>
        document.querySelector('input[name="username"]').addEventListener('keydown', e => {
            if (e.key === ' ') e.preventDefault();
        });

        // Auto fade out success alert
        const successAlert = document.querySelector('.admin-alert-success');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.transition = 'opacity 0.6s ease';
                successAlert.style.opacity = '0';
                setTimeout(() => successAlert.style.display = 'none', 600);
            }, 1500);
        }
    </script>

</body>

</html>