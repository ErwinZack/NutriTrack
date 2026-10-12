<?php
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/audit_log.php";
require_once "../../includes/email_otp_functions.php";

$error = "";
$success = "";

// Redirect if already logged in
if (isset($_SESSION['admin_id']) && $_SESSION['role'] === 'admin') {
    header("Location: dashboard_admin.php");
    exit();
}

// Process login
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {

        // [SECURITY] Check if user is locked out
        if (isLockedOut($email, $pdo)) {
            $minutes = ceil(getLockoutTime($email, $pdo) / 60);
            $error = "Too many failed attempts. Please try again in $minutes minutes.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $admin = $stmt->fetch();

            // [SECURITY] bcrypt verification
            if ($admin && password_verify($password, $admin['password'])) {
                // [SECURITY] Regenerate session ID to prevent session fixation
                session_regenerate_id(true);

                // [SECURITY] Clear failed attempts on successful login
                clearFailedAttempts($email, $pdo);

                // ── Block inactive admins ──
                if ($admin['status'] === 'inactive') {
                    $error = "Your account has been deactivated. Please contact the Municipal office.";
                } else {

                    // --------------------------------------------------------
                    // [MFA COOLDOWN] Check if this device is already trusted
                    // If trusted within cooldown window → skip OTP entirely
                    // --------------------------------------------------------
                    if (checkMFATrust($pdo, $admin['admin_id'], 'admin')) {
                        // Device is trusted — log in directly without OTP
                        $_SESSION['admin_id'] = $admin['admin_id'];
                        $_SESSION['role'] = 'admin';
                        $_SESSION['username'] = $admin['username'];
                        $_SESSION['fullname'] = $admin['full_name'];
                        $_SESSION['full_name'] = $admin['full_name'];
                        $_SESSION['barangay_id'] = $admin['barangay_id'];

                        logAudit(
                            $pdo,
                            'admin',
                            (int) $admin['admin_id'],
                            'LOGIN_TRUSTED_DEVICE',
                            "Admin '{$admin['full_name']}' logged in via trusted device. MFA skipped.",
                            (int) $admin['barangay_id']
                        );

                        header("Location: dashboard_admin.php");
                        exit();
                    }

                    // --------------------------------------------------------
                    // Device not trusted — proceed with OTP as normal
                    // --------------------------------------------------------

                    // Set temp session — gate until OTP is verified
                    $_SESSION['mfa_user_id'] = $admin['admin_id'];
                    $_SESSION['mfa_role'] = 'admin';
                    $_SESSION['mfa_login_time'] = time();
                    $_SESSION['barangay_id'] = $admin['barangay_id'];

                    // Generate and send Email OTP
                    $otp = generateEmailOTP($pdo, $admin['admin_id'], 'admin');
                    $sent = sendEmailOTP($admin['email'], $admin['full_name'], $otp);

                    if ($sent) {
                        logAudit(
                            $pdo,
                            'admin',
                            (int) $admin['admin_id'],
                            'LOGIN_EMAIL_OTP_SENT',
                            "Admin '{$admin['full_name']}' passed password check. OTP sent to {$admin['email']}.",
                            (int) $admin['barangay_id']
                        );

                        header("Location: verify_email_otp_admin.php");
                        exit();

                    } else {
                        // Email failed — clear temp session
                        unset($_SESSION['mfa_user_id'], $_SESSION['mfa_role'], $_SESSION['mfa_login_time']);
                        $error = "Failed to send verification email. Please try again.";
                    }

                } // end status check

            } else {
                // [SECURITY] Record failed attempt
                recordFailedAttempt($email, $pdo);

                $admin_barangay_id = $admin ? (int) $admin['barangay_id'] : null;
                logAudit(
                    $pdo,
                    'admin',
                    (int) 0,
                    'LOGIN_FAILED',
                    "Failed admin login attempt. Email used: $email",
                    $admin_barangay_id
                );

                if (isLockedOut($email, $pdo)) {
                    $minutes = ceil(getLockoutTime($email, $pdo) / 60);
                    $error = "Too many failed attempts. Please try again in $minutes minutes.";
                } else {
                    $remaining = getRemainingAttempts($email, $pdo);
                    $error = "Invalid email or password. Only $remaining attempts left!";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Login | NutriTrack</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/login_admin.css">
</head>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        const pwd = document.querySelector("input[name='password']");
        let visible = false;

        const updateEyeVisibility = () => {
            pwd.style.backgroundImage = pwd.value
                ? pwd.style.backgroundImage
                : pwd.style.backgroundImage.replace(/url\([^)]*\),/, "");
        };

        pwd.addEventListener("input", updateEyeVisibility);

        pwd.addEventListener("click", e => {
            const r = pwd.getBoundingClientRect();
            const clickX = e.clientX - r.left;

            if (clickX > r.width - 42 && pwd.value) {
                visible = !visible;
                pwd.type = visible ? "text" : "password";

                pwd.style.backgroundImage = visible
                    ? pwd.style.backgroundImage.replace(
                        /viewBox='0 0 24 24'[^)]*\)/,
                        "viewBox='0 0 24 24'%3E%3Cpath d='M2 5l17 17M12 7a5 5 0 0 1 5 5c0 .7-.1 1.3-.4 1.9M9.9 9.9A2 2 0 0 0 12 14a2 2 0 0 0 2-2'/%3E%3C/svg%3E)"
                    )
                    : pwd.style.backgroundImage;
            }
        });
    });
</script>

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
            <p class="tagline">Data-Driven Monitoring for Healthier<br>Barangays and Communities</p>
        </div>

        <div class="login-right">
            <div class="login-box">
                <img src="../../assets/images/nutritrack-logo.png" class="login-form-logo" alt="NutriTrack Logo">

                <h2>Admin Login</h2>

                <form method="POST" action="">
                    <label>Email</label>
                    <div style="position:relative;width:100%;margin-bottom:16px;">
                        <span
                            style="position:absolute;left:13px;top:50%;transform:translateY(-50%);pointer-events:none;line-height:0;z-index:1;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#999"
                                viewBox="0 0 24 24">
                                <path
                                    d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" />
                            </svg>
                        </span>
                        <input type="email" name="email" placeholder="Enter your email" required
                            style="margin-bottom:0;width:100%;box-sizing:border-box;background-image:none;">
                    </div>

                    <label>Password</label>
                    <input type="password" name="password" placeholder="Enter your password" required>

                    <button type="submit">Login</button>
                </form>

                <div class="form-footer">
                    <label><input type="checkbox" name="remember"> Remember me</label>
                    <a href="forgot_password_admin.php">Forgot your Password?</a>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['timeout'])): ?>
                    <div class="error">Your session has expired. Please log in again.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</body>

</html>