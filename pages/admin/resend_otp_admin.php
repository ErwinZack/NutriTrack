<?php
// pages/admin/resend_otp_admin.php
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/mailer.php";

header('Content-Type: application/json');

// Validate session
if (empty($_SESSION['reset_email']) || $_SESSION['reset_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Invalid session.']);
    exit();
}

$email = $_SESSION['reset_email'];

// Check if admin exists
$stmt = $pdo->prepare("SELECT admin_id, full_name FROM admins WHERE email = :email LIMIT 1");
$stmt->execute(['email' => $email]);
$admin = $stmt->fetch();

if (!$admin) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit();
}

// Generate new OTP
$otp = rand(100000, 999999);

// Delete old OTP and insert new one — use MySQL NOW() to avoid timezone mismatch
$pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute(['email' => $email]);
$stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, NOW() + INTERVAL 10 MINUTE)");
$stmt->execute(['email' => $email, 'token' => $otp]);

// Send new OTP via email
try {
    $mail = createMailer();
    $mail->addAddress($email, $admin['full_name']);
    $mail->isHTML(true);
    $mail->Subject = 'NutriTrack — Your New Admin Password Reset OTP';
    $mail->Body    = "
        <div style='font-family: Poppins, Arial, sans-serif; max-width: 600px; margin: auto;'>
            <h2 style='color: #1565c0;'>NutriTrack Admin Password Reset</h2>
            <p>Hi <strong>" . htmlspecialchars($admin['full_name']) . "</strong>,</p>
            <p>Your new OTP code to reset your admin password is:</p>
            <div style='text-align: center; margin: 30px 0;'>
                <span style='font-size: 42px; font-weight: bold; letter-spacing: 12px; color: #1565c0;'>$otp</span>
            </div>
            <p style='color: #777; font-size: 13px;'>This code expires in <strong>10 minutes</strong>. Do not share this with anyone.</p>
            <hr style='border: none; border-top: 1px solid #eee;'>
            <p style='color: #aaa; font-size: 12px;'>NutriTrack — Community Nutrition & Health Monitoring</p>
        </div>
    ";
    $mail->AltBody = "Hi {$admin['full_name']},\n\nYour new OTP code is: $otp\n\nThis expires in 10 minutes.";
    $mail->send();

    echo json_encode(['success' => true, 'message' => 'OTP resent successfully!']);
} catch (Exception $e) {
    error_log("Resend OTP mailer error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP. Please try again.']);
}