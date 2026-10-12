<?php
ob_start();
/**
 * Email OTP Verification Page - Municipal (Superadmin)
 * Shown after login when superadmin has no TOTP/Google Authenticator set up
 */

session_start();
require_once '../../config/config.php';
require_once '../../includes/email_otp_functions.php';
require_once '../../includes/audit_log.php';

// Block direct access — must come from login page
if (!isset($_SESSION['mfa_user_id']) || !isset($_SESSION['mfa_role'])) {
    $_SESSION['error'] = "Invalid access. Please log in again.";
    header("Location: login_municipal.php");
    exit();
}

// Block if session expired (10 minutes max)
if (time() - $_SESSION['mfa_login_time'] > 600) {
    session_destroy();
    header("Location: login_municipal.php?timeout=1");
    exit();
}

$superadmin_id = $_SESSION['mfa_user_id'];
$role = $_SESSION['mfa_role'];

// Verify this is a superadmin
if ($role !== 'superadmin') {
    session_destroy();
    header("Location: login_municipal.php");
    exit();
}

// Get superadmin info
$stmt = $pdo->prepare("SELECT full_name, email FROM superadmins WHERE superadmin_id = ?");
$stmt->execute([$superadmin_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: login_municipal.php");
    exit();
}

$error   = null;
$success = null;

// Handle resend OTP
if (isset($_GET['resend'])) {
    $otp = generateEmailOTP($pdo, $superadmin_id, $role);
    $sent = sendEmailOTP($user['email'], $user['full_name'], $otp);

    if ($sent) {
        $success = "A new code has been sent to your email.";
        logAudit($pdo, 'superadmin', $superadmin_id, 'EMAIL_OTP_RESENT', 'Municipal requested OTP resend.');
    } else {
        $error = "Failed to resend code. Please try again.";
    }
}

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['resend'])) {
    $enteredOtp = trim($_POST['otp_code'] ?? '');

    if (empty($enteredOtp) || strlen($enteredOtp) !== 6) {
        $error = "Please enter the complete 6-digit code.";
    } else {
        if (verifyEmailOTP($pdo, $superadmin_id, $role, $enteredOtp)) {
            // ✅ OTP valid — grant full session access
            $_SESSION['superadmin_id'] = $superadmin_id;
            $_SESSION['full_name']     = $user['full_name'];
            $_SESSION['role']          = 'superadmin';
            $_SESSION['last_activity'] = time();

            // Clear temp MFA session
            unset($_SESSION['mfa_user_id'], $_SESSION['mfa_role'], $_SESSION['mfa_login_time']);

            // --------------------------------------------------------
            // [MFA COOLDOWN] Mark this device as trusted
            // --------------------------------------------------------
            setMFATrust($pdo, $superadmin_id, 'superadmin');

            logAudit($pdo, 'superadmin', $superadmin_id, 'EMAIL_OTP_VERIFIED', 'Municipal verified email OTP and logged in. Device marked as trusted.');

            header("Location: dashboard_municipal.php");
            exit();
        } else {
            $error = "Invalid or expired code. Please try again.";
            logAudit($pdo, 'superadmin', $superadmin_id, 'EMAIL_OTP_FAILED', 'Municipal entered wrong email OTP.');
        }
    }
}

// Fetch real remaining seconds from DB
$remainingSeconds = 300; // default fallback (5 min)
$stmtTimer = $pdo->prepare("SELECT expires_at FROM email_otp WHERE user_id = :id AND user_role = :role ORDER BY expires_at DESC LIMIT 1");
$stmtTimer->execute(['id' => $superadmin_id, 'role' => $role]);
$timerRow = $stmtTimer->fetch();
if ($timerRow) {
    $remainingSeconds = max(0, strtotime($timerRow['expires_at']) - time());
}

// Mask email for display (e.g. mu***@calasiao.gov.ph)
$emailParts = explode('@', $user['email']);
$maskedEmail = substr($emailParts[0], 0, 2) . '***@' . $emailParts[1];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Municipal Email Verification — NutriTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/mfa.css">
    <style>
        :root {
            --mfa-color: #1e3c72;
            --mfa-color-dark: #152a54;
        }
    </style>
</head>

<body class="mfa-verify-body">

    <!-- Wavy background — matches login_municipal.php style -->
    <svg class="mfa-vector-bg" viewBox="0 0 1440 900" preserveAspectRatio="none">
        <path d="M0,0 
               C400,0 650,5 900,320
               C1100,620 1100,850 1440,900
               L1440,0 Z" fill="#ffffff" />
    </svg>

    <div class="container">
        <div class="mfa-wrapper-verify">
            <div class="mfa-card">

                <div class="mfa-verify-logo">
                    <i class="bi bi-envelope-check-fill text-white mfa-verify-icon"></i>
                </div>

                <h3 class="text-center mb-2">Check Your Email</h3>
                <p class="text-center text-muted mb-1">We sent a 6-digit login code to</p>
                <p class="text-center mb-4">
                    <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>
                </p>

                <div class="mfa-alert-error <?php echo isset($error) ? 'active' : ''; ?>">
                    <?php if (isset($error)): ?>
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        <?php echo htmlspecialchars($error); ?>
                    <?php endif; ?>
                </div>

                <div class="mfa-alert-success <?php echo isset($success) ? 'active' : ''; ?>">
                    <?php if (isset($success)): ?>
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        <?php echo htmlspecialchars($success); ?>
                    <?php endif; ?>
                </div>

                <form method="POST" action="verify_email_otp_municipal.php" onsubmit="return validateOTP()">

                    <div class="mb-4">
                        <label class="mfa-code-label">Verification Code</label>
                        <div class="mfa-code-inputs">
                            <input type="text" class="mfa-code-input" maxlength="1" pattern="[0-9]" required autocomplete="off">
                            <input type="text" class="mfa-code-input" maxlength="1" pattern="[0-9]" required autocomplete="off">
                            <input type="text" class="mfa-code-input" maxlength="1" pattern="[0-9]" required autocomplete="off">
                            <input type="text" class="mfa-code-input" maxlength="1" pattern="[0-9]" required autocomplete="off">
                            <input type="text" class="mfa-code-input" maxlength="1" pattern="[0-9]" required autocomplete="off">
                            <input type="text" class="mfa-code-input" maxlength="1" pattern="[0-9]" required autocomplete="off">
                        </div>
                        <input type="hidden" name="otp_code" id="otp_code_combined">
                        <small class="mfa-code-hint">
                            ⏱ Code expires in <span id="countdown" style="color:#dc2626; font-weight:600;"></span>
                        </small>
                    </div>

                    <button type="submit" class="mfa-btn-primary w-100 mb-3">
                        Verify & Continue
                    </button>

                </form>

                <div class="text-center">
                    <a href="?resend=1" class="mfa-btn-link mfa-resend-link">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Resend Code
                    </a>
                </div>

                <hr class="mfa-divider">

                <div class="mfa-back-link">
                    <a href="login_municipal.php" class="mfa-btn-secondary">
                        ← Back to Login
                    </a>
                </div>

                <div class="mfa-alert-info">
                    <i class="bi bi-shield-check"></i>
                    <strong>Didn't receive the code?</strong><br>
                    Check your spam folder or click Resend Code above.
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function validateOTP() {
            const inputs = document.querySelectorAll('.mfa-code-input');
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
            const inputs = document.querySelectorAll('.mfa-code-input');

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

            let seconds = <?= intval($remainingSeconds) ?>;
            const countdown = document.getElementById('countdown');

            function updateDisplay() {
                const m = Math.floor(seconds / 60).toString().padStart(2, '0');
                const s = (seconds % 60).toString().padStart(2, '0');
                countdown.textContent = `${m}:${s}`;
            }

            updateDisplay();

            const timer = setInterval(() => {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(timer);
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