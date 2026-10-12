<?php
session_start();
require_once "../../config/config.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit();
}

$error   = "";
$success = "";

// Fetch current settings
$stmt     = $pdo->query("SELECT * FROM system_settings LIMIT 1");
$settings = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $system_name        = trim($_POST['system_name']);
    $contact_email      = trim($_POST['contact_email']);
    // --------------------------------------------------------
    // [MFA COOLDOWN] Read and validate submitted cooldown hours
    // Allowed values: 2, 4, 8, 12, 24 — default to 8 if invalid
    // --------------------------------------------------------
    $allowed_cooldowns  = [2, 4, 8, 12, 24];
    $mfa_cooldown_hours = in_array((int)$_POST['mfa_cooldown_hours'], $allowed_cooldowns)
                            ? (int)$_POST['mfa_cooldown_hours']
                            : 8;

    if (empty($system_name)) {
        $error = "System Name is required.";
    } else {
        $stmt = $pdo->prepare("
            UPDATE system_settings 
            SET system_name        = :system_name,
                contact_email      = :contact_email,
                mfa_cooldown_hours = :mfa_cooldown_hours
        ");
        $updated = $stmt->execute([
            'system_name'        => $system_name,
            'contact_email'      => $contact_email,
            'mfa_cooldown_hours' => $mfa_cooldown_hours,
        ]);

        $success = $updated ? "Settings updated successfully." : "Failed to update settings.";

        // Refresh settings after save
        $stmt     = $pdo->query("SELECT * FROM system_settings LIMIT 1");
        $settings = $stmt->fetch();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Settings | NutriTrack</title>
</head>
<body>
<?php include "../../includes/sidebar_admin.php"; ?>

<main class="dashboard">
    <h1>System Settings</h1>

    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">

        <label>System Name</label>
        <input type="text" name="system_name"
               value="<?= htmlspecialchars($settings['system_name']) ?>" required>

        <label>Contact Email</label>
        <input type="email" name="contact_email"
               value="<?= htmlspecialchars($settings['contact_email']) ?>">

        <!-- -------------------------------------------------------- -->
        <!-- [MFA COOLDOWN] Admin-configurable trusted device window   -->
        <!-- Controls how long a device is trusted before OTP is       -->
        <!-- required again. Default: 8 hours (one full work shift).   -->
        <!-- -------------------------------------------------------- -->
        <label>MFA Trusted Device Duration
            <small style="color:#64748b; font-weight:400; display:block; margin-top:2px;">
                How long a browser/device is trusted after passing OTP verification.
                Users will not be prompted for OTP again until this window expires or they log out.
            </small>
        </label>
        <select name="mfa_cooldown_hours">
            <option value="2"  <?= (int)$settings['mfa_cooldown_hours'] === 2  ? 'selected' : '' ?>>2 hours</option>
            <option value="4"  <?= (int)$settings['mfa_cooldown_hours'] === 4  ? 'selected' : '' ?>>4 hours</option>
            <option value="8"  <?= (int)$settings['mfa_cooldown_hours'] === 8  ? 'selected' : '' ?>>8 hours (recommended)</option>
            <option value="12" <?= (int)$settings['mfa_cooldown_hours'] === 12 ? 'selected' : '' ?>>12 hours</option>
            <option value="24" <?= (int)$settings['mfa_cooldown_hours'] === 24 ? 'selected' : '' ?>>24 hours</option>
        </select>

        <button type="submit">Save Settings</button>

    </form>
</main>
</body>
</html>