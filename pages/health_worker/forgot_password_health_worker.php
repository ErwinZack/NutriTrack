<?php
// pages/health_worker/forgot_password_health_worker.php
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/mailer.php";

if (isset($_SESSION['worker_id']) && $_SESSION['role'] === 'health_worker') {
    header("Location: dashboard_health_worker.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $pdo->prepare("SELECT worker_id, full_name FROM health_workers WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $worker = $stmt->fetch();

        if ($worker) {
            // Generate 6-digit OTP
            $otp = rand(100000, 999999);

            // Delete any existing OTP for this email
            $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute(['email' => $email]);

            // Store OTP in database — use MySQL NOW() to avoid PHP/MySQL timezone mismatch
            $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, NOW() + INTERVAL 10 MINUTE)");
            $stmt->execute(['email' => $email, 'token' => $otp]);

            // Store email in session for next pages
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_role'] = 'health_worker';

            // Send OTP via PHPMailer
            try {
                $mail = createMailer();
                $mail->addAddress($email, $worker['full_name']);
                $mail->isHTML(true);
                $mail->Subject = 'NutriTrack — Your Password Reset OTP';
                $mail->Body = "
                    <div style='font-family: Poppins, Arial, sans-serif; max-width: 600px; margin: auto;'>
                        <h2 style='color: #2e7d32;'>NutriTrack Password Reset</h2>
                        <p>Hi <strong>" . htmlspecialchars($worker['full_name']) . "</strong>,</p>
                        <p>Your OTP code to reset your password is:</p>
                        <div style='text-align: center; margin: 30px 0;'>
                            <span style='font-size: 42px; font-weight: bold; letter-spacing: 12px; color: #2e7d32;'>$otp</span>
                        </div>
                        <p style='color: #777; font-size: 13px;'>This code expires in <strong>10 minutes</strong>. Do not share this with anyone.</p>
                        <hr style='border: none; border-top: 1px solid #eee;'>
                        <p style='color: #aaa; font-size: 12px;'>NutriTrack — Community Nutrition & Health Monitoring</p>
                    </div>
                ";
                $mail->AltBody = "Hi {$worker['full_name']},\n\nYour OTP code is: $otp\n\nThis expires in 10 minutes.";
                $mail->send();

                header("Location: verify_otp_health_worker.php");
                exit();

            } catch (Exception $e) {
                error_log("Mailer error: " . $e->getMessage());
                $error = "Failed to send OTP. Please try again.";
            }

        } else {
            $error = "No account found with that email address. Please check and try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Forgot Password | NutriTrack</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/login_health_worker.css">
    <link rel="stylesheet" href="../../assets/css/password_recovery.css">
    <style>
        :root {
            --recovery-color: #2f6fd6;
            --recovery-color-dark: #245bb3;
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
            <p class="tagline">Data-Driven Monitoring for Healthier<br>Barangays and Communities</p>
        </div>

        <div class="login-right">
            <div class="login-box forgot-box">
                <img src="../../assets/images/nutritrack-logo.png" class="login-form-logo" alt="NutriTrack Logo">

                <h2>Forgot Password</h2>
                <p class="desc">Enter your registered email and we'll send you a 6-digit OTP code.</p>

                <form method="POST" action="">
                    <label>Email Address</label>
                    <div class="forgot-email-wrap">
                        <span class="forgot-email-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#999"
                                viewBox="0 0 24 24">
                                <path
                                    d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" />
                            </svg>
                        </span>
                        <input type="email" name="email" placeholder="Enter your email" required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <button type="submit">Send OTP</button>
                </form>

                <?php if (!empty($error)): ?>
                    <div class="error">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
                            style="width:16px;height:16px;vertical-align:middle;margin-right:6px;flex-shrink:0;">
                            <path d="M12 2L1 21h22L12 2zm0 3.5L20.5 19h-17L12 5.5zM11 10v4h2v-4h-2zm0 6v2h2v-2h-2z" />
                        </svg>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <hr class="recovery-divider">
                <a href="login_health_worker.php" class="back-link">← Back to Login</a>
            </div>
        </div>
    </div>
</body>

</html>