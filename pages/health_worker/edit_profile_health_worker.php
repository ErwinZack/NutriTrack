<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}

// --------------------------------------
// FETCH CURRENT HEALTH WORKER DATA
// --------------------------------------
$worker_id = $_SESSION['worker_id'];
$stmt = $pdo->prepare("SELECT * FROM health_workers WHERE worker_id = :id LIMIT 1");
$stmt->execute([':id' => $worker_id]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC); // fetch associative array

if (!$worker) {
    echo "Error: Health worker not found.";
    exit();
}

// --------------------------------------
// FORM HANDLING
// --------------------------------------
$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Keep existing profile image by default
    $profile_image = $worker['profile_image'] ?? '';

    // Profile image upload
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../assets/images/profiles/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename = time() . '_' . basename($_FILES['profile_image']['name']);
        $targetFile = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetFile)) {
            $profile_image = 'assets/images/profiles/' . $filename;
        } else {
            $error = "Failed to upload profile image.";
        }
    }

    if (empty($error)) {
        // Update database
        $stmt = $pdo->prepare("
            UPDATE health_workers
            SET full_name = :full_name,
                email = :email,
                profile_image = :profile_image
            WHERE worker_id = :id
        ");
        $stmt->execute([
            ':full_name' => $full_name,
            ':email' => $email,
            ':profile_image' => $profile_image,
            ':id' => $worker_id
        ]);

        // Update session data
        $_SESSION['full_name'] = $full_name;
        $_SESSION['profile_image'] = $profile_image;

        $success = "Profile updated successfully!";

        // Update $worker array for form display
        $worker['full_name'] = $full_name;
        $worker['email'] = $email;
        $worker['profile_image'] = $profile_image;
    }
}

// --------------------------------------
// HEADER & SIDEBAR
// --------------------------------------
$pageTitle = "Edit Profile";
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_health_worker.php';
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
                <input type="text" name="full_name" class="input-default" value="<?= htmlspecialchars($worker['full_name'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="input-default" value="<?= htmlspecialchars($worker['email'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label>Profile Image</label>
                <input type="file" name="profile_image" class="input-default" accept="image/*">
                <?php if (!empty($worker['profile_image'])): ?>
                    <img src="../../<?= htmlspecialchars($worker['profile_image']); ?>" alt="Profile" class="profile-preview" style="max-width:100px;margin-top:10px;">
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-update-resident">Update Profile</button>
        </form>
    </section>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
