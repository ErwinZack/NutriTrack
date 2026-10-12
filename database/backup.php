<?php
// database/backup.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_admin.php';

header('Content-Type: application/json');

// --------------------------------------
// [SECURITY] Only admins can trigger a backup
// auth_admin.php already handles redirect if not admin
// --------------------------------------

// --------------------------------------
// [SECURITY] Load encryption key from .env
// Never hardcode this value in source code
// --------------------------------------
$encryptKey = $_ENV['BACKUP_ENCRYPT_KEY'] ?? '';

if (!$encryptKey) {
    echo json_encode([
        'success' => false,
        'message' => 'Backup encryption key not configured. Set BACKUP_ENCRYPT_KEY in .env'
    ]);
    exit();
}

// --------------------------------------
// Create backups folder if it doesn't exist
// --------------------------------------
$backupDir = __DIR__ . '/backups/';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// --------------------------------------
// Generate timestamped filename
// --------------------------------------
$filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
$encFile  = $backupDir . $filename . '.enc';

// --------------------------------------
// Dump the database using mysqldump
// Credentials loaded from .env via config.php
// --------------------------------------
$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbUser = $_ENV['DB_USER'] ?? '';
$dbPass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? '';

$mysqldump = "C:\\xampp\\mysql\\bin\\mysqldump.exe";
$command   = "\"{$mysqldump}\" -h {$dbHost} -u {$dbUser} -p{$dbPass} {$dbName} 2>&1";
$dump      = shell_exec($command);

if (!$dump || str_contains($dump, 'ERROR') || str_contains($dump, 'error: ')) {
    error_log("NutriTrack backup failed: " . $dump);
    echo json_encode([
        'success' => false,
        'message' => 'Database dump failed. Check server logs.'
    ]);
    exit();
}

// --------------------------------------
// [SECURITY] Encrypt dump with AES-256-CBC
// A random IV is generated for every backup
// IV is stored alongside the encrypted data
// --------------------------------------
$iv        = openssl_random_pseudo_bytes(16);
$encrypted = openssl_encrypt($dump, 'AES-256-CBC', $encryptKey, 0, $iv);

if (!$encrypted) {
    error_log("NutriTrack backup encryption failed.");
    echo json_encode([
        'success' => false,
        'message' => 'Encryption failed. Check server logs.'
    ]);
    exit();
}

// Store as: base64(IV) :: encryptedData
$fileContent = base64_encode($iv) . '::' . $encrypted;
file_put_contents($encFile, $fileContent);

// --------------------------------------
// [AUDIT] Log the backup action
// --------------------------------------
require_once __DIR__ . '/../includes/audit_log.php';
$admin_id = (int) $_SESSION['admin_id'];
logAudit(
    $pdo,
    'admin',
    $admin_id,
    'DATABASE_BACKUP',
    "Admin ID {$admin_id} created encrypted database backup: " . basename($encFile)
);

echo json_encode([
    'success' => true,
    'message' => 'Backup created successfully.',
    'file'    => basename($encFile),
    'created' => date('Y-m-d H:i:s')
]);