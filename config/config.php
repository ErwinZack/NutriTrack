<?php
// config/config.php

// [FIX] Set correct Philippine timezone
date_default_timezone_set('Asia/Manila');

// --------------------------------------
// [SECURITY] Load .env file
// Reads DB credentials from .env instead of hardcoding them here
// --------------------------------------
$envFile = __DIR__ . '/../.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comment lines starting with #
        if (str_starts_with(trim($line), '#')) continue;
        // Split each line into KEY=VALUE and store in $_ENV
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// --------------------------------------
// Database credentials — loaded from .env
// --------------------------------------
$host    = $_ENV['DB_HOST']    ?? 'localhost';
$db      = $_ENV['DB_NAME']    ?? 'nutri_track_db';
$user    = $_ENV['DB_USER']    ?? 'root';
$pass    = $_ENV['DB_PASS']    ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

// Connection string PDO uses to find your database
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// PDO behavior: throw errors, return named arrays, use real prepared statements
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Connect to the database, stop script if connection fails
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}


// [SECURITY] Only apply session settings if session has not started yet
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);    // Set to 1 on live HTTPS server
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', 1800);
    session_start();
}

// [SECURITY] Auto-logout after 30 minutes of inactivity
define('SESSION_TIMEOUT', 1800);

// [SECURITY] Lock account for 15 minutes after 10 failed login attempts
define('MAX_LOGIN_ATTEMPTS', 10);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

// [SECURITY] Minimum password length requirement
define('MIN_PASSWORD_LENGTH', 8);

// [SECURITY] CSRF token name used in all forms
define('CSRF_TOKEN_NAME', 'csrf_token');

// [SECURITY] Flask ML API token
// Must match NUTRITRACK_API_TOKEN value in your .env file
// Used by ai_nutrition_advisor.php to authenticate requests to Flask
define('FLASK_API_TOKEN', $_ENV['NUTRITRACK_API_TOKEN'] ?? '');

// ── Pinata IPFS Configuration ─────────────────────────────────
// Used by blockchain/blockchain_service.php to upload audit logs to IPFS
// Flow: Audit Data → Upload to Pinata → Get CID → Store hash on-chain
// Gas reduction: 300k-900k → ~30k-45k per transaction
define('PINATA_API_KEY',  'a72e2894e9e8a8527132');
define('PINATA_API_SECRET', 'a3a4c95dd1d1c71f10ee7bbee6f2f73b38f1ff341fc45d0656f815baa1a7b3b3');
define('PINATA_JWT',      'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySW5mb3JtYXRpb24iOnsiaWQiOiI4MDQxMWFjYi1kZTBkLTRiMWItOTFhYy04MzIyZGRiM2JjYTAiLCJlbWFpbCI6Im51dHJpdHJhY2tzeXN0ZW1AZ21haWwuY29tIiwiZW1haWxfdmVyaWZpZWQiOnRydWUsInBpbl9wb2xpY3kiOnsicmVnaW9ucyI6W3siZGVzaXJlZFJlcGxpY2F0aW9uQ291bnQiOjEsImlkIjoiRlJBMSJ9LHsiZGVzaXJlZFJlcGxpY2F0aW9uQ291bnQiOjEsImlkIjoiTllDMSJ9XSwidmVyc2lvbiI6MX0sIm1mYV9lbmFibGVkIjpmYWxzZSwic3RhdHVzIjoiQUNUSVZFIn0sImF1dGhlbnRpY2F0aW9uVHlwZSI6InNjb3BlZEtleSIsInNjb3BlZEtleUtleSI6ImE3MmUyODk0ZTllOGE4NTI3MTMyIiwic2NvcGVkS2V5U2VjcmV0IjoiYTNhNGM5NWRkMWQxYzcxZjEwZWU3YmJlZTZmMmY3M2IzOGYxZmYzNDFmYzQ1ZDA2NTZmODE1YmFhMWE3YjNiMyIsImV4cCI6MTgwNTI2MzI0NH0.x9TDXyytKOsCHSQDWFmdARmKEXNUS7orh_FjGcAaJ60');
define('PINATA_ENDPOINT', 'https://api.pinata.cloud/pinning/pinJSONToIPFS');
define('PINATA_GATEWAY',  'https://gateway.pinata.cloud/ipfs/');