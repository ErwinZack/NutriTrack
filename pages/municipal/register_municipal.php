<?php
session_start();
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/audit_log.php";

$error = "";
$success = "";

// Redirect if already logged in
if (isset($_SESSION['superadmin_id']) && $_SESSION['role'] === 'superadmin') {
    header("Location: dashboard_municipal.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Max length checks
    if (strlen($full_name) > 100) {
        $error = "Full name is too long (max 100 characters).";
    } elseif (strlen($username) > 50) {
        $error = "Username is too long (max 50 characters).";
    } elseif (strlen($email) > 150) {
        $error = "Email is too long (max 150 characters).";
    } elseif (empty($full_name) || empty($username) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (preg_match('/\d/', $full_name)) {
        $error = "Full name must not contain numbers.";
    } elseif (preg_match('/[^a-zA-ZÀ-ÿ\s.\'\-]/', $full_name)) {
        $error = "Full name contains invalid characters.";
    } elseif (strlen($full_name) < 2) {
        $error = "Full name is too short (min 2 characters).";
    } elseif (!validateEmail($email)) {
        $error = "Invalid email format.";
    } elseif (strlen($username) < 4) {
        $error = "Username must be at least 4 characters long.";
    } elseif (preg_match('/\s/', $username)) {
        $error = "Username must not contain spaces.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = "Username can only contain letters, numbers, and underscores.";
    } elseif (preg_match('/\s/', $password)) {
        $error = "Password must not contain spaces.";
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password)) {
        $error = "Password must be at least 8 characters and include 1 uppercase, 1 lowercase, 1 number, and 1 special character.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM superadmins WHERE username = :username");
        $stmt->execute(['username' => $username]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Username already taken.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM superadmins WHERE email = :email");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $error = "An account with this email already exists.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare("
                    INSERT INTO superadmins (full_name, username, password, email)
                    VALUES (:full_name, :username, :password, :email)
                ");
                $stmt->execute([
                    'full_name' => $full_name,
                    'username' => $username,
                    'password' => $hashed_password,
                    'email' => $email,
                ]);

                $newSuperadminId = $pdo->lastInsertId();

                logAudit(
                    $pdo,
                    'superadmin',
                    (int) $newSuperadminId,
                    'SUPERADMIN_REGISTERED',
                    "New municipal superadmin account registered: '{$full_name}' (username: {$username})."
                );

                header("Location: login_municipal.php?registered=1");
                exit();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Municipal Registration | NutriTrack</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/login_municipal.css">
    <link rel="stylesheet" href="../../assets/css/register_municipal.css">
    <style>
        .field-error {
            display: block;
            height: 0;
            font-size: 11px;
            color: #e53935;
            margin: 0;
            overflow: hidden;
            visibility: hidden;
        }

        .field-error.show {
            height: auto;
            margin-top: -10px;
            margin-bottom: 6px;
            visibility: visible;
        }

        .input-invalid {
            border-color: #e53935 !important;
            border-width: 1.5px !important;
        }

        .input-valid {
            border-color: #28a745 !important;
            border-width: 1.5px !important;
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
            <div class="login-box">
                <img src="../../assets/images/nutritrack-logo.png" class="login-form-logo" alt="NutriTrack Logo">

                <h2>Municipal Registration</h2>

                <div class="step-dots">
                    <span id="dot1" class="active"></span>
                    <span id="dot2"></span>
                </div>

                <form method="POST" action="" id="registerForm" novalidate>

                    <div id="step1" class="form-step active">

                        <label>Full Name</label>
                        <input type="text" id="full_name" name="full_name" placeholder="e.g. Juan Dela Cruz" required
                            maxlength="100" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                        <span class="field-error" id="full_name-error"></span>

                        <label>Username</label>
                        <input type="text" id="username" name="username" placeholder="e.g. jdelacruz" required
                            minlength="4" maxlength="50" pattern="[a-zA-Z0-9_]+"
                            title="Letters, numbers, and underscores only. No spaces."
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        <span class="field-error" id="username-error"></span>

                        <label>Email</label>
                        <div style="position:relative;width:100%;margin-bottom:4px;">
                            <span
                                style="position:absolute;left:13px;top:50%;transform:translateY(-50%);pointer-events:none;line-height:0;z-index:1;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#999"
                                    viewBox="0 0 24 24">
                                    <path
                                        d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" />
                                </svg>
                            </span>
                            <input type="email" id="email" name="email" placeholder="Enter your email" required
                                maxlength="150"
                                style="margin-bottom:0;width:100%;box-sizing:border-box;background-image:none;"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <span class="field-error" id="email-error"></span>

                        <button type="button" id="nextBtn" style="margin-top:16px;">Next</button>
                    </div>

                    <div id="step2" class="form-step">

                        <label>Password</label>
                        <input type="password" id="password" name="password" placeholder="Create a password" required>
                        <span class="field-error" id="password-error"></span>
                        <p style="font-size:12px;color:#888;margin-top:4px;margin-bottom:16px;">
                            Min. 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 special character
                        </p>

                        <label>Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                            placeholder="Re-enter your password" required>
                        <span class="field-error" id="confirm_password-error"></span>

                        <div class="step-buttons" style="margin-top:16px;">
                            <button type="button" id="backBtn" class="btn-back">Back</button>
                            <button type="submit">Register</button>
                        </div>
                    </div>

                </form>

                <div class="form-footer">
                    <a href="login_municipal.php">Already have an account? Login</a>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        document.addEventListener("DOMContentLoaded", () => {

            // ─── Step Navigation ───
            const step1 = document.getElementById("step1");
            const step2 = document.getElementById("step2");
            const dot1 = document.getElementById("dot1");
            const dot2 = document.getElementById("dot2");
            const nextBtn = document.getElementById("nextBtn");
            const backBtn = document.getElementById("backBtn");

            // ─── Validation Rules ───
            const Validate = {
                full_name(v) {
                    if (!v.trim()) return 'Full name is required.';
                    if (v.trim().length < 2) return 'Full name is too short (min 2 characters).';
                    if (v.length > 100) return 'Full name is too long (max 100 characters).';
                    if (/\d/.test(v)) return 'Full name must not contain numbers.';
                    if (/[^a-zA-ZÀ-ÿ\s.'\-]/.test(v)) return 'Full name contains invalid characters.';
                    return null;
                },
                username(v) {
                    if (!v.trim()) return 'Username is required.';
                    if (/\s/.test(v)) return 'Username must not contain spaces.';
                    if (v.length < 4) return 'Username must be at least 4 characters.';
                    if (v.length > 50) return 'Username is too long (max 50 characters).';
                    if (!/^[a-zA-Z0-9_]+$/.test(v)) return 'Only letters, numbers, and underscores allowed.';
                    return null;
                },
                email(v) {
                    if (!v.trim()) return 'Email is required.';
                    if (v.length > 150) return 'Email is too long (max 150 characters).';
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email address.';
                    return null;
                },
                password(v) {
                    if (!v) return 'Password is required.';
                    if (/\s/.test(v)) return 'Password must not contain spaces.';
                    if (v.length < 8) return 'Password must be at least 8 characters.';
                    if (!/(?=.*[a-z])/.test(v)) return 'Password must include at least 1 lowercase letter.';
                    if (!/(?=.*[A-Z])/.test(v)) return 'Password must include at least 1 uppercase letter.';
                    if (!/(?=.*\d)/.test(v)) return 'Password must include at least 1 number.';
                    if (!/(?=.*[\W_])/.test(v)) return 'Password must include at least 1 special character.';
                    return null;
                },
                confirm_password(v) {
                    const pw = document.getElementById('password').value;
                    if (!v) return 'Please confirm your password.';
                    if (v !== pw) return 'Passwords do not match.';
                    return null;
                }
            };

            // ─── Show / Clear Error ───
            function showError(fieldId, msg) {
                const inp = document.getElementById(fieldId);
                const err = document.getElementById(fieldId + '-error');
                if (inp) { inp.classList.add('input-invalid'); inp.classList.remove('input-valid'); }
                if (err) { err.textContent = msg; err.classList.add('show'); }
            }

            function clearError(fieldId) {
                const inp = document.getElementById(fieldId);
                const err = document.getElementById(fieldId + '-error');
                if (inp) {
                    inp.classList.remove('input-invalid');
                    if (inp.value.trim()) {
                        inp.classList.add('input-valid');
                    } else {
                        inp.classList.remove('input-valid');
                    }
                }
                if (err) { err.textContent = ''; err.classList.remove('show'); }
            }

            // ─── Attach Realtime Validation ───
            const touched = new Set();

            function attachField(fieldId) {
                const el = document.getElementById(fieldId);
                if (!el || !Validate[fieldId]) return;

                el.addEventListener('blur', () => {
                    if (!touched.has(fieldId)) return;
                    const msg = Validate[fieldId](el.value);
                    msg ? showError(fieldId, msg) : clearError(fieldId);
                });

                el.addEventListener('input', () => {
                    touched.add(fieldId);
                    const msg = Validate[fieldId](el.value);
                    msg ? showError(fieldId, msg) : clearError(fieldId);
                });
            }

            ['full_name', 'username', 'email', 'password', 'confirm_password'].forEach(attachField);

            // ─── Prevent spaces in username ───
            document.getElementById('username').addEventListener('keydown', e => {
                if (e.key === ' ') e.preventDefault();
            });

            // ─── Next Button — validate Step 1 before proceeding ───
            nextBtn.addEventListener("click", (e) => {
                e.preventDefault();
                const fields = ['full_name', 'username', 'email'];
                let hasErr = false;

                // Clear step 2 state completely when moving forward
                touched.delete('password');
                touched.delete('confirm_password');
                clearError('password');
                clearError('confirm_password');
                document.getElementById('password').value = '';
                document.getElementById('confirm_password').value = '';

                fields.forEach(id => {
                    touched.add(id);
                    const el = document.getElementById(id);
                    const msg = Validate[id](el.value);
                    if (msg) { showError(id, msg); hasErr = true; }
                    else clearError(id);
                });

                if (hasErr) return;

                step1.classList.remove("active");
                step2.classList.add("active");
                dot1.classList.remove("active");
                dot2.classList.add("active");
            });

            // ─── Back Button ───
            backBtn.addEventListener("click", () => {
                touched.delete('password');
                touched.delete('confirm_password');
                clearError('password');
                clearError('confirm_password');
                step2.classList.remove("active");
                step1.classList.add("active");
                dot2.classList.remove("active");
                dot1.classList.add("active");
            });

            // ─── Form Submit — validate Step 2 ───
            document.getElementById('registerForm').addEventListener('submit', function (e) {
                const fields = ['password', 'confirm_password'];
                let hasErr = false;

                fields.forEach(id => {
                    touched.add(id);
                    const el = document.getElementById(id);
                    const msg = Validate[id](el.value);
                    if (msg) { showError(id, msg); hasErr = true; }
                    else clearError(id);
                });

                if (hasErr) e.preventDefault();
            });

            // ─── Password toggle (click right edge) ───
            [document.getElementById('password'), document.getElementById('confirm_password')].forEach(input => {
                let visible = false;
                input.addEventListener("click", e => {
                    const r = input.getBoundingClientRect();
                    const clickX = e.clientX - r.left;
                    if (clickX > r.width - 42 && input.value) {
                        visible = !visible;
                        input.type = visible ? "text" : "password";
                    }
                });
            });

        });
    </script>

</body>

</html>