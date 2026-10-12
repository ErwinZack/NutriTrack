<?php
require_once __DIR__ . '/../config/config.php';

$encryptKey  = $_ENV['BACKUP_ENCRYPT_KEY'] ?? '';
$encFile     = __DIR__ . '/backups/backup_2026_03_17_160000.sql.enc';
$fileContent = file_get_contents($encFile);

[$ivBase64, $encrypted] = explode('::', $fileContent, 2);
$iv  = base64_decode($ivBase64);

$sql = openssl_decrypt(
    $encrypted,
    'AES-256-CBC',
    $encryptKey,
    0,
    $iv
);

$sqlFile = __DIR__ . '/backups/restored.sql';
file_put_contents($sqlFile, $sql);

echo '✅ Decrypted successfully! File saved as restored.sql';