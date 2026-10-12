<?php
// ============================================================
//  NutriTrack — Blockchain Service (IPFS + Pinata Edition)
//  Flow: Audit Data → Upload to Pinata IPFS → Get CID Hash
//        → Store bytes32 hash on-chain → ~30k-45k gas
//  Compatible with web3p/web3.php v0.3.2
// ============================================================


require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use Web3\Web3;
use Web3\Contract;

// ── Configuration ────────────────────────────────────────────
define('BLOCKCHAIN_RPC',       'http://127.0.0.1:7545');
define('CONTRACT_ADDRESS',     '0xaa36217c221cC829B5F4602Fd38f370bD0D26CfF');
define('DEFAULT_FROM_ADDRESS', '0xddd588D92473e35C56505ECdEC4057caEdED3f2a');


// ── New ABI — matches new NutriTrackAudit.sol ─────────────────
$CONTRACT_ABI = '[
  {
    "anonymous": false,
    "inputs": [
      {"indexed": true,  "internalType": "uint256", "name": "id",        "type": "uint256"},
      {"indexed": true,  "internalType": "bytes32", "name": "ipfsHash",  "type": "bytes32"},
      {"indexed": false, "internalType": "uint256", "name": "timestamp", "type": "uint256"}
    ],
    "name": "AuditLogged",
    "type": "event"
  },
  {
    "inputs": [],
    "name": "entryCount",
    "outputs": [{"internalType": "uint256", "name": "", "type": "uint256"}],
    "stateMutability": "view",
    "type": "function"
  },
  {
    "inputs": [],
    "name": "getCount",
    "outputs": [{"internalType": "uint256", "name": "", "type": "uint256"}],
    "stateMutability": "view",
    "type": "function"
  },
  {
    "inputs": [{"internalType": "bytes32", "name": "ipfsHash", "type": "bytes32"}],
    "name": "logAudit",
    "outputs": [],
    "stateMutability": "nonpayable",
    "type": "function"
  }
]';

// ── Helper: Create Contract Instance ─────────────────────────
function makeContract(): Contract
{
    global $CONTRACT_ABI;
    $web3     = new Web3(BLOCKCHAIN_RPC);
    $contract = new Contract($web3->provider, $CONTRACT_ABI);
    $contract->at(CONTRACT_ADDRESS);
    return $contract;
}

// ── Step 1: Upload Audit Data to Pinata IPFS ─────────────────
function uploadToPinata(string $role, int $userId, string $action, string $description): ?string
{
    $payload = json_encode([
        'pinataContent' => [
            'role'        => $role,
            'userId'      => $userId,
            'action'      => $action,
            'description' => $description,
            'timestamp'   => time(),
            'system'      => 'NutriTrack',
        ],
        'pinataMetadata' => [
            'name' => 'audit_' . $action . '_' . time(),
        ]
    ]);

    $ch = curl_init(PINATA_ENDPOINT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . PINATA_JWT,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err || $http_code !== 200) {
        error_log("[IPFS] Pinata upload failed. HTTP: $http_code | Error: $curl_err");
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['IpfsHash'])) {
        error_log("[IPFS] Pinata response missing IpfsHash: $response");
        return null;
    }

    error_log("[IPFS] Successfully uploaded to Pinata. CID: " . $data['IpfsHash']);
    return $data['IpfsHash'];
}

// ── Step 2: Convert CID string to bytes32 ────────────────────
function cidToBytes32(string $cid): string
{
    // 0x-prefixed hex string — required by web3p v0.3.2 for bytes32
    return '0x' . hash('sha256', $cid);
}

// ── Core Function: Log to Blockchain via IPFS ────────────────
function logToBlockchain(string $role, int $userId, string $action, string $description): bool
{
    try {
        // Step 1: Upload full audit data to Pinata IPFS
        $cid = uploadToPinata($role, $userId, $action, $description);

        if (!$cid) {
            error_log("[Blockchain] IPFS upload failed — skipping blockchain write.");
            return false;
        }

        // Step 2: Convert CID to bytes32
        $ipfsHashBytes = cidToBytes32($cid);

        // Step 3: Send only the bytes32 hash to blockchain (~30k-45k gas)
        $contract = makeContract();
        $error    = null;

        $contract->send(
            'logAudit',
            $ipfsHashBytes,
            [
                'from' => DEFAULT_FROM_ADDRESS,
                'gas'  => '0xC350', // 50,000 gas — sufficient for event-only contract
            ],
            function ($err, $result) use (&$error) {
                if ($err !== null) {
                    $error = $err->getMessage();
                }
            }
        );

        if ($error) {
            error_log("[Blockchain] logAudit error: $error");
            return false;
        }

        error_log("[Blockchain] Successfully logged. CID: $cid");
        return true;

    } catch (Exception $e) {
        error_log("[Blockchain] Exception: " . $e->getMessage());
        return false;
    }
}

// ── Core Function: Get Total Entry Count ─────────────────────
function getBlockchainCount(): int
{
    try {
        $contract = makeContract();
        $count    = 0;

        $contract->call('getCount', function ($err, $result) use (&$count) {
            if ($err === null && isset($result[0])) {
                $count = (int) $result[0]->toString();
            }
        });

        return $count;

    } catch (Exception $e) {
        error_log("[Blockchain] getCount error: " . $e->getMessage());
        return 0;
    }
}

// ── Helper: Retrieve Audit Data from IPFS by CID ─────────────
function getFromIPFS(string $cid): ?array
{
    $url = PINATA_GATEWAY . $cid;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . PINATA_JWT,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        error_log("[IPFS] Failed to fetch CID: $cid");
        return null;
    }

    return json_decode($response, true);
}