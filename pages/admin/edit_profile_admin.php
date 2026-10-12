<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login_admin.php");
    exit();
}

// --------------------------------------
// FETCH CURRENT ADMIN DATA
// --------------------------------------
$admin_id = $_SESSION['admin_id'];

$stmt = $pdo->prepare("SELECT * FROM admins WHERE admin_id = :id LIMIT 1");
$stmt->execute([':id' => $admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    echo "Error: Admin not found.";
    exit();
}

// --------------------------------------
// FORM HANDLING
// --------------------------------------
$success = "";
$error   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    // Keep existing profile image by default
    $profile_image = $admin['profile_image'] ?? '';

    // --------------------------------------
    // PROFILE IMAGE UPLOAD
    // --------------------------------------
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {

        $uploadDir = __DIR__ . '/../../assets/images/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename   = time() . '_' . basename($_FILES['profile_image']['name']);
        $targetFile = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetFile)) {
            $profile_image = 'assets/images/profiles/' . $filename;
        } else {
            $error = "Failed to upload profile image.";
        }
    }

    if (empty($error)) {

        // --------------------------------------
        // UPDATE DATABASE
        // --------------------------------------
        $stmt = $pdo->prepare("
            UPDATE admins
            SET full_name = :full_name,
                email = :email,
                profile_image = :profile_image
            WHERE admin_id = :id
        ");

        $stmt->execute([
            ':full_name'     => $full_name,
            ':email'         => $email,
            ':profile_image' => $profile_image,
            ':id'            => $admin_id
        ]);

        // Update session
        $_SESSION['full_name']     = $full_name;
        $_SESSION['profile_image'] = $profile_image;

        $success = "Profile updated successfully!";

        // Update local array for display
        $admin['full_name']     = $full_name;
        $admin['email']         = $email;
        $admin['profile_image'] = $profile_image;
    }
}

// --------------------------------------
// HEADER & SIDEBAR
// --------------------------------------
$pageTitle = "Edit Profile";
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_admin.php';
?>

<main class="dashboard">
    <h1 class="page-title">Edit Profile</h1>

    <section class="section">

        <?php if ($error): ?>
            <p class="text-error"><?= htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <?php if ($success): ?>
            <p class="text-success"><?= htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="form-default">

            <div class="form-group">
                <label>Full Name</label>
                <input
                    type="text"
                    name="full_name"
                    class="input-default"
                    value="<?= htmlspecialchars($admin['full_name'] ?? ''); ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>Email</label>
                <input
                    type="email"
                    name="email"
                    class="input-default"
                    value="<?= htmlspecialchars($admin['email'] ?? ''); ?>"
                >
            </div>

            <div class="form-group">
                <label>Profile Image</label>
                <input
                    type="file"
                    name="profile_image"
                    class="input-default"
                    accept="image/*"
                >

                <?php if (!empty($admin['profile_image'])): ?>
                    <img
                        src="../../<?= htmlspecialchars($admin['profile_image']); ?>"
                        alt="Profile"
                        class="profile-preview"
                        style="max-width:100px;margin-top:10px;"
                    >
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-update-resident">
                Update Profile
            </button>

        </form>
    </section>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
