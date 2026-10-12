<?php
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/audit_log.php";
require_once "../../includes/email_otp_functions.php";

$error = "";
$success = "";

// Redirect if already logged in
if (isset($_SESSION['superadmin_id']) && $_SESSION['role'] === 'superadmin') {
    header("Location: dashboard_municipal.php");
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
            $stmt = $pdo->prepare("SELECT * FROM superadmins WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $superadmin = $stmt->fetch();

            // [SECURITY] bcrypt verification
            if ($superadmin && password_verify($password, $superadmin['password'])) {
                // [SECURITY] Regenerate session ID to prevent session fixation
                session_regenerate_id(true);

                // [SECURITY] Clear failed attempts on successful login
                clearFailedAttempts($email, $pdo);

                // --------------------------------------------------------
                // [MFA COOLDOWN] Check if this device is already trusted
                // --------------------------------------------------------
                if (checkMFATrust($pdo, $superadmin['superadmin_id'], 'superadmin')) {
                    $_SESSION['superadmin_id'] = $superadmin['superadmin_id'];
                    $_SESSION['role'] = 'superadmin';
                    $_SESSION['username'] = $superadmin['username'];
                    $_SESSION['full_name'] = $superadmin['full_name'];

                    logAudit(
                        $pdo,
                        'superadmin',
                        (int) $superadmin['superadmin_id'],
                        'LOGIN_TRUSTED_DEVICE',
                        "Municipal '{$superadmin['full_name']}' logged in via trusted device. MFA skipped."
                    );

                    header("Location: dashboard_municipal.php");
                    exit();
                }

                // --------------------------------------------------------
                // Device not trusted — proceed with OTP as normal
                // --------------------------------------------------------
                $_SESSION['mfa_user_id'] = $superadmin['superadmin_id'];
                $_SESSION['mfa_role'] = 'superadmin';
                $_SESSION['mfa_login_time'] = time();

                $otp = generateEmailOTP($pdo, $superadmin['superadmin_id'], 'superadmin');
                $sent = sendEmailOTP($superadmin['email'], $superadmin['full_name'], $otp);

                if ($sent) {
                    logAudit(
                        $pdo,
                        'superadmin',
                        (int) $superadmin['superadmin_id'],
                        'LOGIN_EMAIL_OTP_SENT',
                        "Municipal '{$superadmin['full_name']}' passed password check. OTP sent to {$superadmin['email']}."
                    );

                    header("Location: verify_email_otp_municipal.php");
                    exit();

                } else {
                    unset($_SESSION['mfa_user_id'], $_SESSION['mfa_role'], $_SESSION['mfa_login_time']);
                    $error = "Failed to send verification email. Please try again.";
                }

            } else {
                recordFailedAttempt($email, $pdo);

                logAudit(
                    $pdo,
                    'superadmin',
                    (int) 0,
                    'LOGIN_FAILED',
                    "Failed municipal login attempt. Email used: $email"
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
    <title>Municipal Login | NutriTrack</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/login_municipal.css">
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
            <p class="tagline">Town-Wide Oversight for Healthier<br>Barangays and Communities</p>
        </div>

        <div class="login-right">
            <div class="login-box">
                <img src="../../assets/images/nutritrack-logo.png" class="login-form-logo" alt="NutriTrack Logo">

                <h2>Municipal Login</h2>

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
                    <a href="register_municipal.php">Don't have an account?</a>
                    <a href="forgot_password_municipal.php">Forgot your Password?</a>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['registered']) && empty($error)): ?>
                    <div class="success">Account created successfully! You can now log in.</div>
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