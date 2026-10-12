<?php
// NutriTrack Functions

// Calculate BMI: weight (kg) / (height (m))^2
function calculateBMI($weight, $height_cm) {
    if ($height_cm <= 0) return 0;
    $height_m = $height_cm / 100;
    return round($weight / ($height_m * $height_m), 2);
}

// Sanitize input (trim, strip tags)
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Format date to Y-m-d
function formatDate($date) {
    return date("Y-m-d", strtotime($date));
}


// [SECURITY] Rate Limiting Functions

// Check if the user is currently locked out from logging in
function isLockedOut($identifier, $pdo) {
    $stmt = $pdo->prepare("SELECT lockout_until FROM login_attempts WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $identifier]);
    $row = $stmt->fetch();

    if ($row && $row['lockout_until']) {
        if (strtotime($row['lockout_until']) > time()) {
            return true; // Still locked out
        }
        // Lockout expired, clear it
        $pdo->prepare("UPDATE login_attempts SET attempts = 0, lockout_until = NULL WHERE email = :email")
            ->execute(['email' => $identifier]);
    }
    return false;
}

// Get remaining lockout time in seconds
function getLockoutTime($identifier, $pdo) {
    $stmt = $pdo->prepare("SELECT lockout_until FROM login_attempts WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $identifier]);
    $row = $stmt->fetch();

    if ($row && $row['lockout_until']) {
        return max(0, strtotime($row['lockout_until']) - time());
    }
    return 0;
}

// Record a failed login attempt, lock out if limit is reached
function recordFailedAttempt($identifier, $pdo) {
    // Insert or update attempts
    $stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $identifier]);
    $row = $stmt->fetch();

    if ($row) {
        $attempts = $row['attempts'] + 1;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            // Lock out the user
            $lockoutUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_TIME);
            $pdo->prepare("UPDATE login_attempts SET attempts = :attempts, lockout_until = :lockout, last_attempt = NOW() WHERE email = :email")
                ->execute(['attempts' => $attempts, 'lockout' => $lockoutUntil, 'email' => $identifier]);
        } else {
            $pdo->prepare("UPDATE login_attempts SET attempts = :attempts, last_attempt = NOW() WHERE email = :email")
                ->execute(['attempts' => $attempts, 'email' => $identifier]);
        }
    } else {
        // First failed attempt
        $pdo->prepare("INSERT INTO login_attempts (email, attempts, last_attempt) VALUES (:email, 1, NOW())")
            ->execute(['email' => $identifier]);
    }
}

// Clear failed attempts after a successful login
function clearFailedAttempts($identifier, $pdo) {
    $pdo->prepare("UPDATE login_attempts SET attempts = 0, lockout_until = NULL WHERE email = :email")
        ->execute(['email' => $identifier]);
}

// Get number of remaining login attempts
function getRemainingAttempts($identifier, $pdo) {
    $stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $identifier]);
    $row = $stmt->fetch();
    $attempts = $row ? $row['attempts'] : 0;
    return MAX_LOGIN_ATTEMPTS - $attempts;
}


// [SECURITY] Password Policy Function

// Validate password meets minimum security requirements
function isValidPassword($password) {
    if (strlen($password) < MIN_PASSWORD_LENGTH) return false;  // Min 8 characters
    if (!preg_match('/[A-Z]/', $password)) return false;        // At least 1 uppercase
    if (!preg_match('/[a-z]/', $password)) return false;        // At least 1 lowercase
    if (!preg_match('/[0-9]/', $password)) return false;        // At least 1 number
    if (!preg_match('/[\W_]/', $password)) return false;        // At least 1 special character
    if (preg_match('/\s/', $password)) return false;            // No spaces
    return true;
}


// [SECURITY] CSRF Token Functions

// Generate a CSRF token and store it in the session
function generateCsrfToken() {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

// Verify the CSRF token submitted from the form
function verifyCsrfToken($token) {
    if (!isset($_SESSION[CSRF_TOKEN_NAME]) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// Output the hidden CSRF input field inside forms
function csrfInput() {
    $token = generateCsrfToken();
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . $token . '">';
}


// [SECURITY] Input Validation Helpers

// Validate that a value is not empty
function validateRequired($value) {
    return !empty(trim($value));
}

// Validate email format
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Validate that a value is a positive number
function validatePositiveNumber($value) {
    return is_numeric($value) && $value > 0;
}

// Validate date format Y-m-d
function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}