<?php
// pages/municipal/reset_password_municipal.php
require_once "../../config/config.php";
require_once "../../includes/functions.php";

if (isset($_SESSION['superadmin_id']) && $_SESSION['role'] === 'superadmin') {
    header("Location: dashboard_municipal.php");
    exit();
}

// [SECURITY] Must have verified OTP first
if (empty($_SESSION['reset_email']) || empty($_SESSION['otp_verified']) || $_SESSION['reset_role'] !== 'superadmin') {
    header("Location: forgot_password_municipal.php");
    exit();
}

$error = "";
$success = "";
$email = $_SESSION['reset_email'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
        $error = "Password must be at least " . MIN_PASSWORD_LENGTH . " characters.";
    } elseif (!preg_match('/[A-Z]/', $newPassword)) {
        $error = "Password must contain at least 1 uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $newPassword)) {
        $error = "Password must contain at least 1 lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $newPassword)) {
        $error = "Password must contain at least 1 number.";
    } elseif (!preg_match('/[\W_]/', $newPassword)) {
        $error = "Password must contain at least 1 special character.";
    } elseif (preg_match('/\s/', $newPassword)) {
        $error = "Password must not contain spaces.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match. Please try again.";
    } else {
        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);

        // Update password in superadmins table
        $stmt = $pdo->prepare("UPDATE superadmins SET password = :password WHERE email = :email");
        $stmt->execute(['password' => $hashed, 'email' => $email]);

        // Delete used OTP from database
        $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute(['email' => $email]);

        // Clear reset session variables
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_role']);
        unset($_SESSION['otp_verified']);

        $success = "Your Municipal password has been reset successfully! Redirecting to login...";
        header("Refresh:3; url=login_municipal.php");
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reset Password | NutriTrack Municipal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/login_municipal.css">
    <link rel="stylesheet" href="../../assets/css/password_recovery.css">
    <style>
        :root {
            --recovery-color: #1e3c72;
            --recovery-color-dark: #152a54;
        }

        /* Fix: override negative margin from recovery CSS for this layout */
        .recovery-alert-success.active {
            margin-top: 0 !important;
            margin-bottom: 16px !important;
            height: auto !important;
            overflow: visible !important;
            animation: recoverySlideInFade 0.4s ease-out forwards !important;
            align-items: flex-start !important;
            justify-content: flex-start !important;
            text-align: left !important;
        }

        .recovery-alert-success.active svg {
            margin-top: 3px !important;
            flex-shrink: 0 !important;
        }
    </style>
</head>

<body>
    <div class="login-page">
        <svg class="vector-bg" viewBox="0 0 1440 900" preserveAspectRatio="none">
            <path d="M0,0 C400,0 650,5 900,320 C1100,620 1100,850 1440,900 L1440,0 Z" fill="#ffffff" />
        </svg>

        <div class="login-left">
            <div class="brand-header">
                <img src="../../assets/images/nutritrack-logo.png" class="brand-logo" alt="NutriTrack Logo">
                <div class="brand-text">
                    <h1>NutriTrack</h1>
                    <p class="subtitle">Community Nutrition & Health Monitoring</p>
                </div>
            </div>
            <img src="../../assets/images/main-illustration.png" class="brand-sticker" alt="Health Illustration">
            <p class="tagline">Town-Wide Oversight for Healthier<br>Barangays and Communities</p>
        </div>

        <div class="login-right">
            <div class="login-box forgot-box">
                <img src="../../assets/images/nutritrack-logo.png" class="login-form-logo" alt="NutriTrack Logo">

                <h2>Reset Municipal Password</h2>

                <!-- Success Alert -->
                <div class="recovery-alert-success <?php echo !empty($success) ? 'active' : ''; ?>">
                    <?php if (!empty($success)): ?>
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        <?= htmlspecialchars($success) ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($success)): ?>
                    <hr class="recovery-divider">
                    <a href="login_municipal.php" class="back-link">← Back to Municipal Login</a>

                <?php else: ?>

                    <!-- Error Alert -->
                    <div class="recovery-alert-error <?php echo !empty($error) ? 'active' : ''; ?>">
                        <?php if (!empty($error)): ?>
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <?= htmlspecialchars($error) ?>
                        <?php endif; ?>
                    </div>

                    <form method="POST" action="">
                        <label>New Password</label>
                        <input type="password" name="new_password" placeholder="Enter new password" required
                            minlength="<?= MIN_PASSWORD_LENGTH ?>">
                       <p class="password-hint">Min. <?= MIN_PASSWORD_LENGTH ?> chars • uppercase • lowercase • number • special char • no spaces</p>

                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Confirm new password" required>

                        <button type="submit">Reset Password</button>
                    </form>

                    <hr class="recovery-divider">
                    <a href="login_municipal.php" class="back-link">← Back to Municipal Login</a>

                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>