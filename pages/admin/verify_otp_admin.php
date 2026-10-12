<?php
// pages/admin/verify_otp_admin.php
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/mailer.php";

if (isset($_SESSION['admin_id']) && $_SESSION['role'] === 'admin') {
    header("Location: dashboard_admin.php");
    exit();
}

// If no reset session, go back to forgot password
if (empty($_SESSION['reset_email']) || $_SESSION['reset_role'] !== 'admin') {
    header("Location: forgot_password_admin.php");
    exit();
}

$error   = null;
$success = null;

// Handle resend OTP via GET redirect (matches MFA pattern)
// NOTE: if resend is set, we never fall through to the POST block
if (isset($_GET['resend'])) {
    $email = $_SESSION['reset_email'];

    // Fetch admin info
    $stmtUser = $pdo->prepare("SELECT admin_id, full_name FROM admins WHERE email = :email LIMIT 1");
    $stmtUser->execute(['email' => $email]);
    $adminUser = $stmtUser->fetch();

    if ($adminUser) {
        // Generate new OTP and store in DB
        $newOtp = rand(100000, 999999);
        $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute(['email' => $email]);
        $stmtIns = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, NOW() + INTERVAL 10 MINUTE)");
        $stmtIns->execute(['email' => $email, 'token' => $newOtp]);

        // Send via mailer
        try {
            $mail = createMailer();
            $mail->addAddress($email, $adminUser['full_name']);
            $mail->isHTML(true);
            $mail->Subject = 'NutriTrack — Your New Admin Password Reset OTP';
            $mail->Body    = "
                <div style='font-family: Poppins, Arial, sans-serif; max-width: 600px; margin: auto;'>
                    <h2 style='color: #1565c0;'>NutriTrack Admin Password Reset</h2>
                    <p>Hi <strong>" . htmlspecialchars($adminUser['full_name']) . "</strong>,</p>
                    <p>Your new OTP code to reset your admin password is:</p>
                    <div style='text-align: center; margin: 30px 0;'>
                        <span style='font-size: 42px; font-weight: bold; letter-spacing: 12px; color: #1565c0;'>$newOtp</span>
                    </div>
                    <p style='color: #777; font-size: 13px;'>This code expires in <strong>10 minutes</strong>. Do not share this with anyone.</p>
                    <hr style='border: none; border-top: 1px solid #eee;'>
                    <p style='color: #aaa; font-size: 12px;'>NutriTrack — Community Nutrition & Health Monitoring</p>
                </div>
            ";
            $mail->AltBody = "Hi {$adminUser['full_name']},\n\nYour new OTP code is: $newOtp\n\nThis expires in 10 minutes.";
            $mail->send();
            $success = "A new code has been sent to your email.";
        } catch (Exception $e) {
            error_log("Resend OTP mailer error: " . $e->getMessage());
            $error = "Failed to resend code. Please try again.";
        }
    } else {
        $error = "Account not found.";
    }
}

// Fetch real remaining seconds from DB
$remainingSeconds = 600; // default fallback
if (!empty($_SESSION['reset_email'])) {
    $stmtTimer = $pdo->prepare("SELECT expires_at FROM password_resets WHERE email = :email ORDER BY expires_at DESC LIMIT 1");
    $stmtTimer->execute(['email' => $_SESSION['reset_email']]);
    $timerRow = $stmtTimer->fetch();
    if ($timerRow) {
        $remainingSeconds = max(0, strtotime($timerRow['expires_at']) - time());
    }
}

// Mask email for display (e.g. wi***@gmail.com)
$email       = $_SESSION['reset_email'];
$emailParts  = explode('@', $email);
$maskedEmail = substr($emailParts[0], 0, 2) . '***@' . $emailParts[1];

if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_GET['resend'])) {
    $enteredOtp = trim($_POST['otp_code'] ?? '');
    $email      = $_SESSION['reset_email'];

    if (empty($enteredOtp) || strlen($enteredOtp) !== 6) {
        $error = "Please enter the complete 6-digit OTP code.";
    } else {
        // Check OTP in database
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = :email AND token = :token AND expires_at > NOW() LIMIT 1");
        $stmt->execute(['email' => $email, 'token' => $enteredOtp]);
        $row = $stmt->fetch();

        if ($row) {
            // OTP is valid — mark as verified in session
            $_SESSION['otp_verified'] = true;
            header("Location: reset_password_admin.php");
            exit();
        } else {
            // Check if OTP exists but expired
            $stmt2 = $pdo->prepare("SELECT * FROM password_resets WHERE email = :email AND token = :token LIMIT 1");
            $stmt2->execute(['email' => $email, 'token' => $enteredOtp]);
            $expired = $stmt2->fetch();

            if ($expired) {
                $error = "Your OTP has expired. Please request a new one.";
            } else {
                $error = "Invalid OTP code. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | NutriTrack Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/password_recovery.css">
    <style>
        :root {
            --recovery-color:      #1e3c72;
            --recovery-color-dark: #152a54;
        }
    </style>
</head>
<body class="recovery-body">

    <!-- Wavy background — matches login_admin.php style -->
    <svg class="recovery-vector-bg" viewBox="0 0 1440 900" preserveAspectRatio="none">
        <path
            d="M0,0
               C400,0 650,5 900,320
               C1100,620 1100,850 1440,900
               L1440,0 Z"
            fill="#ffffff"
        />
    </svg>

    <div class="container">
        <div class="recovery-wrapper">
            <div class="recovery-card">

                <!-- Logo circle -->
                <div class="recovery-logo">
                    <i class="bi bi-envelope-check-fill text-white recovery-icon"></i>
                </div>

                <h3 class="text-center mb-2">Password Reset</h3>
                <p class="text-center text-muted mb-1">Enter the verification code sent to</p>
                <p class="text-center mb-4">
                    <strong><?= htmlspecialchars($maskedEmail) ?></strong>
                </p>

                <!-- Alerts -->
                <div class="recovery-alert-error <?php echo !empty($error) ? 'active' : ''; ?>">
                    <?php if (!empty($error)): ?>
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        <?= htmlspecialchars($error) ?>
                    <?php endif; ?>
                </div>

                <div class="recovery-alert-success <?php echo !empty($success) ? 'active' : ''; ?>">
                    <?php if (!empty($success)): ?>
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        <?= htmlspecialchars($success) ?>
                    <?php endif; ?>
                </div>

                <!-- OTP Form -->
                <form method="POST" action="verify_otp_admin.php" onsubmit="return validateOTP()">
                    <div class="mb-4">
                        <label class="recovery-code-label">Verification Code</label>
                        <div class="recovery-code-inputs">
                            <input type="text" class="recovery-code-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                            <input type="text" class="recovery-code-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                            <input type="text" class="recovery-code-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                            <input type="text" class="recovery-code-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                            <input type="text" class="recovery-code-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                            <input type="text" class="recovery-code-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                        </div>
                        <input type="hidden" name="otp_code" id="otp_code_combined">
                        <small class="recovery-code-hint">
                            ⏱ Code expires in <span id="countdown" style="color:#dc2626; font-weight:600;"></span>
                        </small>
                    </div>

                    <button type="submit" class="recovery-btn-primary w-100 mb-3">
                        Continue Reset
                    </button>
                </form>

                <!-- Resend OTP — GET redirect, matches MFA pattern -->
                <div class="text-center">
                    <a href="?resend=1" class="recovery-btn-link recovery-resend-link">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Resend OTP
                    </a>
                </div>

                <hr class="recovery-divider">

                <div class="recovery-back-link">
                    <a href="login_admin.php" class="recovery-btn-secondary">
                        ← Back to Admin Login
                    </a>
                </div>

                <div class="recovery-alert-info">
                    <i class="bi bi-shield-check"></i>
                    <strong>Didn't receive the code?</strong><br>
                    Check your spam folder or click Resend OTP above.
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ── OTP Combine & Validate ───────────────────────────────
        function validateOTP() {
            const inputs = document.querySelectorAll('.recovery-code-input');
            let code = '';
            inputs.forEach(input => { code += input.value; });

            if (code.length !== 6) {
                alert('Please enter all 6 digits.');
                return false;
            }
            document.getElementById('otp_code_combined').value = code;
            return true;
        }

        document.addEventListener('DOMContentLoaded', function () {
            const inputs = document.querySelectorAll('.recovery-code-input');

            // ── Box navigation ───────────────────────────────────
            inputs.forEach((input, index) => {
                input.addEventListener('input', function () {
                    this.value = this.value.replace(/[^0-9]/g, '');
                    if (this.value.length === 1 && index < inputs.length - 1) {
                        inputs[index + 1].focus();
                    }
                });
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !this.value && index > 0) {
                        inputs[index - 1].focus();
                    }
                });
                input.addEventListener('paste', function (e) {
                    e.preventDefault();
                    const pasted = e.clipboardData.getData('text').replace(/[^0-9]/g, '');
                    if (pasted.length === 6) {
                        inputs.forEach((inp, i) => { inp.value = pasted[i] || ''; });
                        inputs[5].focus();
                    }
                });
            });

            inputs[0].focus();

            // ── Countdown timer (from DB-fetched remaining seconds) ──
            let seconds = <?= intval($remainingSeconds) ?>;
            const countdown = document.getElementById('countdown');

            function updateDisplay() {
                const m = Math.floor(seconds / 60).toString().padStart(2, '0');
                const s = (seconds % 60).toString().padStart(2, '0');
                countdown.textContent = `${m}:${s}`;
            }

            updateDisplay();

            const timerInterval = setInterval(() => {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(timerInterval);
                    countdown.textContent = 'Expired';
                    countdown.style.color = '#9ca3af';
                } else {
                    updateDisplay();
                }
            }, 1000);
        });
    </script>
</body>
</html>