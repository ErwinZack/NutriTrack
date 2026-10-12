<?php
/**
 * Email OTP Functions for NutriTrack
 * Handles sending and verifying login OTP via Gmail
 * Used by: verify_email_otp_health_worker.php
 *          verify_email_otp_admin.php
 *          verify_email_otp_municipal.php
 */

// [FIX] Buffer output so setcookie() inside setMFATrust() never fails
// due to "headers already sent" — safe even if ob_start() was already
// called elsewhere (PHP handles nested output buffers cleanly)
if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/mailer.php';

/**
 * Generate a 6-digit OTP and save it to the database
 */
function generateEmailOTP($pdo, $userId, $role) {
    // Invalidate any existing unused OTPs for this user
    $stmt = $pdo->prepare("
        UPDATE email_otp 
        SET used = 1 
        WHERE user_id = ? AND user_role = ? AND used = 0
    ");
    $stmt->execute([$userId, $role]);

    // Generate new 6-digit OTP
    $otp       = strval(random_int(100000, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes from now

    // Save to database
    $stmt = $pdo->prepare("
        INSERT INTO email_otp (user_id, user_role, otp_code, expires_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $role, $otp, $expiresAt]);

    return $otp;
}

/**
 * Verify the OTP entered by the user
 * Uses PHP time comparison to avoid MySQL timezone mismatch
 */
function verifyEmailOTP($pdo, $userId, $role, $enteredOtp) {
    $stmt = $pdo->prepare("
        SELECT * FROM email_otp
        WHERE user_id   = ?
          AND user_role = ?
          AND otp_code  = ?
          AND used      = 0
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $role, $enteredOtp]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // [FIX] Use PHP time() to check expiry — avoids MySQL timezone mismatch
        $expiresAt = strtotime($row['expires_at']);
        if (time() > $expiresAt) {
            return false; // OTP expired
        }

        // Mark OTP as used
        $stmt = $pdo->prepare("UPDATE email_otp SET used = 1 WHERE id = ?");
        $stmt->execute([$row['id']]);
        return true;
    }

    return false;
}

/**
 * Send OTP email using PHPMailer
 */
function sendEmailOTP($toEmail, $toName, $otp) {
    try {
        $mail = createMailer();

        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'NutriTrack — Your Login Verification Code';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;'>
                <div style='background: #2563eb; padding: 24px; border-radius: 10px 10px 0 0; text-align: center;'>
                    <h2 style='color: white; margin: 0;'>🛡️ NutriTrack Login Verification</h2>
                </div>
                <div style='background: #f8fafc; padding: 32px; border-radius: 0 0 10px 10px; border: 1px solid #e2e8f0;'>
                    <p style='color: #374151; font-size: 15px;'>Hi <strong>{$toName}</strong>,</p>
                    <p style='color: #374151; font-size: 15px;'>Use the code below to complete your login to NutriTrack:</p>
                    <div style='background: white; border: 2px dashed #2563eb; border-radius: 10px; padding: 24px; text-align: center; margin: 24px 0;'>
                        <p style='font-size: 42px; font-weight: bold; letter-spacing: 10px; color: #1e40af; margin: 0;'>{$otp}</p>
                    </div>
                    <p style='color: #64748b; font-size: 13px;'>⏱ This code expires in <strong>5 minutes</strong>.</p>
                    <p style='color: #64748b; font-size: 13px;'>🔒 If you did not attempt to log in, please ignore this email and secure your account immediately.</p>
                    <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                    <p style='color: #94a3b8; font-size: 12px; text-align: center;'>NutriTrack — Community Nutrition & Health Monitoring</p>
                </div>
            </div>
        ";
        $mail->AltBody = "Your NutriTrack login code is: {$otp}\n\nThis code expires in 5 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================================
// MFA COOLDOWN / TRUSTED DEVICE FUNCTIONS
// ============================================================

/**
 * Get the MFA cooldown duration (in hours) from system_settings.
 * Falls back to 8 hours if not set.
 */
function getMFACooldownHours($pdo) {
    try {
        $stmt = $pdo->query("SELECT mfa_cooldown_hours FROM system_settings LIMIT 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && isset($row['mfa_cooldown_hours'])) ? (int)$row['mfa_cooldown_hours'] : 8;
    } catch (Exception $e) {
        return 8; // Safe default
    }
}

/**
 * Helper: resolve the correct table + ID column for a given role.
 * Supports admin, superadmin (Municipal), and health_worker (default).
 */
function resolveMFATable($role) {
    if ($role === 'admin') {
        return ['admins', 'admin_id'];
    }
    if ($role === 'superadmin') {
        return ['superadmins', 'superadmin_id'];
    }
    return ['health_workers', 'worker_id'];
}

/**
 * Check if the current browser/device is MFA-trusted for this user.
 */
function checkMFATrust($pdo, $userId, $role) {
    // [TEMP] OTP disabled for testing — always trust the device.
    // TODO: remove this line to re-enable OTP login.
    return true;

    $cookieName = 'mfa_trust_' . $role;

    // No cookie present — not trusted
    if (empty($_COOKIE[$cookieName])) {
        return false;
    }

    $cookieToken = $_COOKIE[$cookieName];
    [$table, $idColumn] = resolveMFATable($role);

    $stmt = $pdo->prepare("
        SELECT mfa_trusted_token, mfa_trusted_until 
        FROM `{$table}`
        WHERE `{$idColumn}` = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // No token in DB — not trusted
    if (!$row || empty($row['mfa_trusted_token']) || empty($row['mfa_trusted_until'])) {
        return false;
    }

    // Token mismatch — not trusted
    if (!hash_equals($row['mfa_trusted_token'], $cookieToken)) {
        return false;
    }

    // Check expiry using PHP time
    if (time() > strtotime($row['mfa_trusted_until'])) {
        return false;
    }

    return true;
}

/**
 * Set MFA trust for the current browser/device after successful OTP verification.
 */
function setMFATrust($pdo, $userId, $role) {
    $cooldownHours = getMFACooldownHours($pdo);
    $token         = bin2hex(random_bytes(32)); // 64-char secure token
    $expiresAt     = date('Y-m-d H:i:s', time() + ($cooldownHours * 3600));
    $cookieName    = 'mfa_trust_' . $role;
    [$table, $idColumn] = resolveMFATable($role);

    // Save token + expiry to DB
    $stmt = $pdo->prepare("
        UPDATE `{$table}`
        SET mfa_trusted_token = ?, mfa_trusted_until = ?
        WHERE `{$idColumn}` = ?
    ");
    $stmt->execute([$token, $expiresAt, $userId]);

    // Set secure HttpOnly cookie in browser
    setcookie(
        $cookieName,
        $token,
        [
            'expires'  => time() + ($cooldownHours * 3600),
            'path'     => '/',
            'secure'   => false,   // Set to true if using HTTPS in production
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );
}

/**
 * Clear MFA trust for the current user on logout.
 */
function clearMFATrust($pdo, $userId, $role) {
    $cookieName = 'mfa_trust_' . $role;
    [$table, $idColumn] = resolveMFATable($role);

    // Nullify token in DB
    $stmt = $pdo->prepare("
        UPDATE `{$table}`
        SET mfa_trusted_token = NULL, mfa_trusted_until = NULL
        WHERE `{$idColumn}` = ?
    ");
    $stmt->execute([$userId]);

    // Expire the cookie immediately
    setcookie(
        $cookieName,
        '',
        [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );
}