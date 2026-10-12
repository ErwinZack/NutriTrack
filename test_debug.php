<?php
require_once 'config/config.php';
require_once 'blockchain/blockchain_service.php';

// Simulate exactly what health_records.php does
$_SESSION['worker_id'] = 21;

$result = logToBlockchain('health_worker', 21, 'ADD_HEALTH_RECORD', "Worker 'Erwin R. Zacarias' added health record for resident ID: 1 on 2026-03-17");
echo $result ? '✅ SUCCESS — check Ganache for new block' : '❌ FAILED — check Apache error log';