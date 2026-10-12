<?php
// includes/mailer.php
// --------------------------------------
// Reusable PHPMailer configuration
// Used by health worker, admin, and municipal (superadmin) onboarding
// --------------------------------------

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';

/**
 * Creates and returns a configured PHPMailer instance.
 * Credentials are loaded from .env via config.php.
 *
 * @return PHPMailer
 * @throws Exception
 */
function createMailer(): PHPMailer {
    $mail = new PHPMailer(true);

    // [SMTP] Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USERNAME'] ?? '';
    $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // [SENDER] From address
    $mail->setFrom(
        $_ENV['MAIL_USERNAME'] ?? '',
        $_ENV['MAIL_FROM_NAME'] ?? 'NutriTrack'
    );

    return $mail;
}

/**
 * Sends a welcome email to a newly created account
 * containing their login credentials (email + temp password).
 *
 * @param string $recipientEmail  Recipient's email address
 * @param string $recipientName   Recipient's full name
 * @param string $tempPassword    Plain-text temp password (before hashing)
 * @param string $role            'health_worker' | 'admin' | 'superadmin' — determines login URL + footer text
 * @return bool  true = sent successfully, false = failed
 */
function sendTempPasswordEmail(string $recipientEmail, string $recipientName, string $tempPassword, string $role = 'health_worker'): bool {
    try {
        $mail = createMailer();

        // [RECIPIENT]
        $mail->addAddress($recipientEmail, $recipientName);

        // [LOGIN URL] — role-aware, dynamically built from current server
        $login_paths = [
            'health_worker' => '/NutriTrack/pages/health_worker/login_health_worker.php',
            'admin'         => '/NutriTrack/pages/admin/login_admin.php',
            'superadmin'    => '/NutriTrack/pages/municipal/login_municipal.php',
        ];
        $login_path = $login_paths[$role] ?? $login_paths['health_worker'];

        $login_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                   . '://' . $_SERVER['HTTP_HOST']
                   . $login_path;

        // [FOOTER] — role-aware signature line
        $footer_labels = [
            'health_worker' => 'NutriTrack - Health Worker Portal',
            'admin'         => 'NutriTrack - Barangay Admin Portal',
            'superadmin'    => 'NutriTrack - Municipal Nutrition Office, Calasiao',
        ];
        $footer_label = $footer_labels[$role] ?? $footer_labels['health_worker'];

        // [SUBJECT]
        $mail->Subject = 'Your NutriTrack Account Credentials';

        // [BODY] — Plain text
        $mail->isHTML(false);
        $mail->Body =
"Hi {$recipientName},

Your NutriTrack account has been set up by the administrator.
You can now access the system using the credentials below:

-------------------------------------------------
  Email   : {$recipientEmail}
  Password: {$tempPassword}
-------------------------------------------------

Click the link below to log in:
{$login_url}

For your security:
  - Do not share your password with anyone
  - You can change your password anytime from your profile
  - If you did not expect this email, contact your administrator

This is an automated message from {$footer_label}.
Please do not reply to this email.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('sendTempPasswordEmail failed: ' . $e->getMessage());
        return false;
    }
}