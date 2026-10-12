<?php
// pages/municipal/program_planning.php
session_start();
require_once "../../config/config.php";
require_once "../../includes/functions.php";
require_once "../../includes/audit_log.php";
require_once "../../config/openrouter.php";

// Redirect if not logged in
if (!isset($_SESSION['superadmin_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: login_municipal.php");
    exit();
}

$superadmin_id = $_SESSION['superadmin_id'];

// --------------------------------------
// [SECURITY] RATE LIMITING
// --------------------------------------
$rate_limit_key = 'add_program_' . md5($_SERVER['REMOTE_ADDR']);
$rate_limit_max = 15;
$rate_limit_window = 900;

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'start' => time()];
}
if ((time() - $_SESSION[$rate_limit_key]['start']) > $rate_limit_window) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'start' => time()];
}

$error = "";
$success = $_SESSION['success_msg'] ?? '';
unset($_SESSION['success_msg']);

const PROGRAM_CATEGORIES = ['Feeding Program', 'Deworming', 'Nutrition Education', 'Supplementation', 'Other'];
const PRIORITY_LEVELS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

// --------------------------------------
// FETCH: All barangays (for target selection)
// --------------------------------------
$barangayList = $pdo->query("SELECT barangay_id, barangay_name FROM barangays WHERE status = 'active' ORDER BY barangay_name ASC")->fetchAll();

// --------------------------------------
// PRIORITY DATA: Reuse at-risk logic from analytics for hint display
// --------------------------------------
$latestBmiPerResident = $pdo->query("
    SELECT hr.resident_id, hr.bmi, r.barangay_id
    FROM health_records hr
    INNER JOIN (
        SELECT resident_id, MAX(record_id) AS latest_id
        FROM health_records
        GROUP BY resident_id
    ) latest ON hr.resident_id = latest.resident_id AND hr.record_id = latest.latest_id
    INNER JOIN residents r ON r.resident_id = hr.resident_id
")->fetchAll();

$brgyRiskStats = [];
foreach ($barangayList as $b) {
    $brgyRiskStats[$b['barangay_id']] = ['name' => $b['barangay_name'], 'at_risk' => 0, 'total' => 0];
}
foreach ($latestBmiPerResident as $row) {
    $bid = $row['barangay_id'];
    if (!isset($brgyRiskStats[$bid]))
        continue;
    $b = (float) $row['bmi'];
    $brgyRiskStats[$bid]['total']++;
    if ($b < 18.5 || $b >= 25.0)
        $brgyRiskStats[$bid]['at_risk']++;
}
foreach ($brgyRiskStats as $bid => &$s) {
    $s['at_risk_pct'] = $s['total'] > 0 ? round($s['at_risk'] / $s['total'] * 100, 1) : 0;
}
unset($s);

// Top 3 priority barangays for the hint banner
$priorityHint = array_filter($brgyRiskStats, fn($s) => $s['total'] > 0);
uasort($priorityHint, fn($a, $b) => $b['at_risk_pct'] <=> $a['at_risk_pct']);
$priorityHint = array_slice($priorityHint, 0, 3, true);

// AI: Generate program description via Gemini
function get_ai_program_description($programName, $selectedBarangayIds, $allRiskStats)
{
    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $model = 'gemini-3.5-flash-lite';
    $url = defined('GEMINI_API_URL') ? GEMINI_API_URL : 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent';

    if (!$api_key) {
        error_log("NutriTrack Groq: API key not set in config/openrouter.php");
        return null;
    }

    $summaryLines = [];
    foreach ($allRiskStats as $bid => $s) {
        if ($selectedBarangayIds !== null && !in_array((int) $bid, $selectedBarangayIds))
            continue;
        if ($s['total'] === 0)
            continue;
        $summaryLines[] = "{$s['name']}: {$s['at_risk_pct']}% at risk out of {$s['total']} monitored residents";
    }
    $summaryText = !empty($summaryLines) ? implode('; ', $summaryLines) : "No nutrition data available yet for the selected barangay(s).";

   $prompt = "You are a municipal health program officer in the Philippines writing a program directive for a barangay admin. "
    . "Program name: " . $programName . ". "
    . "Nutrition data: " . $summaryText . ". "
    . "Write the description following this EXACT template: "
    . "---TEMPLATE START--- "
    . "Conduct a [program name] in your barangay to address [health issue based on nutrition data] among at-risk residents. "
    . "(blank line) "
    . "Services to provide: "
    . "- [First relevant service based on program name] "
    . "- [Second relevant service based on program name] "
    . "- Open to all qualified residents "
    . "(blank line) "
    . "Ensure all eligible residents are reached and properly recorded in the NutriTrack system. "
    . "---TEMPLATE END--- "
    . "RULES: "
    . "1. Plain text only — no emojis, no markdown, no asterisks. "
    . "2. Use a dash (-) for each item under Services to provide. "
    . "3. Do NOT include dates, barangay names, or health center names. "
    . "4. Do not mention AI or automation. "
    . "5. Only list services directly implied by the program name. "
    . "6. Write in English. "
    . "Respond ONLY in this exact JSON format with no extra text: "
    . "{\"description\": \"...\"}";

    $payload = json_encode([
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 8192,
            'temperature' => 0.4
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $output = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err || $http_code !== 200) {
        error_log("NutriTrack Program AI FAILED - HTTP $http_code - Error: $curl_err - Response: $output");
        return null;
    }

    $data = json_decode($output, true);
    $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (!$content) {
        error_log("NutriTrack Program AI EMPTY RESPONSE - Raw output: $output");
        return null;
    }

    $content = trim(preg_replace('/```json|```/', '', $content));

    $parsed = json_decode($content, true);
    if (!$parsed || empty($parsed['description'])) {
        error_log("NutriTrack Program AI PARSE FAILED - Raw content: $content");
        return null;
    }

    return $parsed['description'];
}

// --------------------------------------
// SHARED VALIDATION for Add / Edit
// --------------------------------------
function validateProgramInput($barangayList): array
{
    $programName = sanitizeInput($_POST['program_name'] ?? '');
    if (empty($programName)) {
        $programName = sanitizeInput($_POST['program_name_select'] ?? '');
    }
    $description = sanitizeInput($_POST['description'] ?? '');
    $targetScope = $_POST['target_scope'] ?? 'specific';
    $targetBarangays = $_POST['target_barangays'] ?? [];
    $startDate = sanitizeInput($_POST['start_date'] ?? '');
    $endDate = sanitizeInput($_POST['end_date'] ?? '');
    $targetBeneficiaries = (int) ($_POST['target_beneficiaries'] ?? 0);
    $priorityLevel = $_POST['priority_level'] ?? 'medium';
    $programNameLower = strtolower($programName);
    $category = str_contains($programNameLower, 'feed') ? 'Feeding Program'
        : (str_contains($programNameLower, 'deworm') ? 'Deworming'
            : (str_contains($programNameLower, 'education') || str_contains($programNameLower, 'counseling') ? 'Nutrition Education'
                : (str_contains($programNameLower, 'supplement') || str_contains($programNameLower, 'vitamin') || str_contains($programNameLower, 'iron') ? 'Supplementation'
                    : 'Other')));

    $err = null;

    if (empty($description)) {
        $err = "Description is required.";
    } elseif (strlen($programName) > 150) {
        $err = "Program name is too long (max 150 characters).";
    } elseif (strlen($description) > 2000) {
        $err = "Description is too long (max 2000 characters).";
    } elseif (empty($programName) || empty($startDate) || empty($endDate)) {
        $err = "Please fill in all required fields.";
    } elseif (!in_array($targetScope, ['all', 'specific'])) {
        $err = "Invalid target scope.";
    } elseif ($targetScope === 'specific' && empty($targetBarangays)) {
        $err = "Please select at least one barangay, or choose 'All Barangays'.";
    } elseif (!validateDate($startDate) || !validateDate($endDate)) {
        $err = "Invalid date format.";
    } elseif (strtotime($endDate) < strtotime($startDate)) {
        $err = "End date cannot be before the start date.";
    } elseif ($targetBeneficiaries < 0) {
        $err = "Target beneficiaries cannot be negative.";
    } elseif (!array_key_exists($priorityLevel, PRIORITY_LEVELS)) {
        $err = "Invalid priority level.";
        // } elseif ($category !== '' && !in_array($category, PROGRAM_CATEGORIES)) {
        //     $err = "Invalid program category."; // category auto-derived, no validation needed
    }

    // Resolve the actual barangay id list this program applies to
    $validBarangayIds = array_column($barangayList, 'barangay_id');
    if ($targetScope === 'all') {
        $resolvedBarangayIds = $validBarangayIds;
    } else {
        $resolvedBarangayIds = array_values(array_filter(
            array_map('intval', $targetBarangays),
            fn($id) => in_array($id, $validBarangayIds)
        ));
    }

    return [
        'error' => $err,
        'program_name' => $programName,
        'description' => $description,
        'target_scope' => $targetScope,
        'resolved_barangay_ids' => $resolvedBarangayIds,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'target_beneficiaries' => $targetBeneficiaries,
        'priority_level' => $priorityLevel,
        'category' => $category ?: null,
    ];
}

// --------------------------------------
// HANDLE: Add New Program
// --------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'add_program') {

    if ($_SESSION[$rate_limit_key]['count'] >= $rate_limit_max) {
        $error = "Too many attempts. Please wait 15 minutes before trying again.";
    } elseif (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $data = validateProgramInput($barangayList);

        if ($data['error']) {
            $error = $data['error'];
            $_SESSION[$rate_limit_key]['count']++;
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO programs (program_name, description, target_scope, target_beneficiaries, priority_level, category, start_date, end_date, status, created_by)
                    VALUES (:name, :desc, :scope, :target, :priority, :category, :start, :end, 'planned', :creator)
                ");
                $stmt->execute([
                    'name' => $data['program_name'],
                    'desc' => $data['description'] ?: null,
                    'scope' => $data['target_scope'],
                    'target' => $data['target_beneficiaries'],
                    'priority' => $data['priority_level'],
                    'category' => $data['category'],
                    'start' => $data['start_date'],
                    'end' => $data['end_date'],
                    'creator' => $superadmin_id,
                ]);
                $newProgramId = $pdo->lastInsertId();

                $barangayIds = $data['resolved_barangay_ids'];
                $count = count($barangayIds);
                $perBarangayTarget = $count > 0 ? (int) ceil($data['target_beneficiaries'] / $count) : 0;

                if ($count > 0) {
                    $pbStmt = $pdo->prepare("
                        INSERT INTO program_barangays (program_id, barangay_id, assigned_target, actual_reached)
                        VALUES (:pid, :bid, :target, 0)
                    ");
                    foreach ($barangayIds as $bid) {
                        $pbStmt->execute(['pid' => $newProgramId, 'bid' => $bid, 'target' => $perBarangayTarget]);
                    }
                }

                $pdo->commit();
                $_SESSION[$rate_limit_key]['count']++;

                $scopeLabel = $data['target_scope'] === 'all' ? 'all barangays' : $count . ' barangay(s)';
                logAudit(
                    $pdo,
                    'superadmin',
                    (int) $superadmin_id,
                    'PROGRAM_CREATED',
                    "Municipal created program '{$data['program_name']}' targeting {$scopeLabel} ({$data['start_date']} to {$data['end_date']})."
                );

                $_SESSION['success_msg'] = "Program '{$data['program_name']}' created successfully.";
                header("Location: program_planning.php");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Program creation error: " . $e->getMessage());
                $error = "Failed to create program. Please try again.";
                $_SESSION[$rate_limit_key]['count']++;
            }
        }
    }
}


// --------------------------------------
// AJAX: Generate AI Description
// --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_ai_description') {
    header('Content-Type: application/json');

    $programName = sanitizeInput($_POST['program_name'] ?? '');
    $targetScope = $_POST['target_scope'] ?? 'specific';
    $targetBarangayIds = array_map('intval', $_POST['target_barangays'] ?? []);

    if (empty($programName)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a program name first.']);
        exit();
    }
    if ($targetScope === 'specific' && empty($targetBarangayIds)) {
        echo json_encode(['success' => false, 'message' => 'Please select at least one barangay first.']);
        exit();
    }

    $filterIds = ($targetScope === 'all') ? null : $targetBarangayIds;
    $description = get_ai_program_description($programName, $filterIds, $brgyRiskStats);

    if ($description) {
        echo json_encode(['success' => true, 'description' => $description]);
    } else {
        echo json_encode(['success' => false, 'message' => 'AI service is currently unavailable. Please write the description manually.']);
    }
    exit();
}

// --------------------------------------
// HANDLE: Edit Existing Program
// --------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'edit_program') {

    $editProgramId = (int) ($_POST['program_id'] ?? 0);

    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = "Invalid request. Please try again.";
    } elseif ($editProgramId <= 0) {
        $error = "Invalid program.";
    } else {
        $data = validateProgramInput($barangayList);

        if ($data['error']) {
            $error = $data['error'];
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE programs
                    SET program_name = :name, description = :desc, target_scope = :scope,
                        target_beneficiaries = :target, priority_level = :priority, category = :category,
                        start_date = :start, end_date = :end
                    WHERE program_id = :id
                ");
                $stmt->execute([
                    'name' => $data['program_name'],
                    'desc' => $data['description'] ?: null,
                    'scope' => $data['target_scope'],
                    'target' => $data['target_beneficiaries'],
                    'priority' => $data['priority_level'],
                    'category' => $data['category'],
                    'start' => $data['start_date'],
                    'end' => $data['end_date'],
                    'id' => $editProgramId,
                ]);

                // Preserve actual_reached for barangays that remain assigned
                $existingStmt = $pdo->prepare("SELECT barangay_id, actual_reached FROM program_barangays WHERE program_id = :id");
                $existingStmt->execute(['id' => $editProgramId]);
                $existingReached = [];
                foreach ($existingStmt->fetchAll() as $row) {
                    $existingReached[(int) $row['barangay_id']] = (int) $row['actual_reached'];
                }

                $delStmt = $pdo->prepare("DELETE FROM program_barangays WHERE program_id = :id");
                $delStmt->execute(['id' => $editProgramId]);

                $barangayIds = $data['resolved_barangay_ids'];
                $count = count($barangayIds);
                $perBarangayTarget = $count > 0 ? (int) ceil($data['target_beneficiaries'] / $count) : 0;

                if ($count > 0) {
                    $pbStmt = $pdo->prepare("
                        INSERT INTO program_barangays (program_id, barangay_id, assigned_target, actual_reached)
                        VALUES (:pid, :bid, :target, :reached)
                    ");
                    foreach ($barangayIds as $bid) {
                        $pbStmt->execute([
                            'pid' => $editProgramId,
                            'bid' => $bid,
                            'target' => $perBarangayTarget,
                            'reached' => $existingReached[$bid] ?? 0,
                        ]);
                    }
                }

                $pdo->commit();

                logAudit(
                    $pdo,
                    'superadmin',
                    (int) $superadmin_id,
                    'PROGRAM_UPDATED',
                    "Municipal updated program '{$data['program_name']}' (ID {$editProgramId})."
                );

                $_SESSION['success_msg'] = "Program '{$data['program_name']}' updated successfully.";
                header("Location: program_planning.php");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Program edit error: " . $e->getMessage());
                $error = "Failed to update program. Please try again.";
            }
        }
    }
}





// --------------------------------------
// FETCH: All Programs with target barangay names + progress totals
// --------------------------------------
$programs = $pdo->query("
    SELECT
        p.program_id, p.program_name, p.description, p.target_scope,
        p.priority_level, p.category, p.start_date, p.end_date, p.created_at,
        GROUP_CONCAT(b.barangay_name SEPARATOR ', ') AS target_names
    FROM programs p
    LEFT JOIN program_barangays pb ON pb.program_id = p.program_id
    LEFT JOIN barangays b ON b.barangay_id = pb.barangay_id
    GROUP BY p.program_id, p.program_name, p.description, p.target_scope,
             p.priority_level, p.category, p.start_date, p.end_date, p.created_at
    ORDER BY p.created_at DESC
")->fetchAll();

// --------------------------------------
// HANDLE: Delete Program
// --------------------------------------
if (isset($_GET['delete_program']) && isset($_GET['id'])) {
    $deleteId = (int) $_GET['id'];
    $stmt = $pdo->prepare("SELECT program_name FROM programs WHERE program_id = :id LIMIT 1");
    $stmt->execute(['id' => $deleteId]);
    $prog = $stmt->fetch();

    if ($prog) {
        $pdo->prepare("DELETE FROM program_barangays WHERE program_id = :id")->execute(['id' => $deleteId]);
        $pdo->prepare("DELETE FROM programs WHERE program_id = :id")->execute(['id' => $deleteId]);

        logAudit(
            $pdo,
            'superadmin',
            (int) $superadmin_id,
            'PROGRAM_DELETED',
            "Municipal deleted program '{$prog['program_name']}' (ID {$deleteId})."
        );

        header("Location: program_planning.php");
        exit();
    }
}


function old($field, $default = '')
{
    return htmlspecialchars($_POST[$field] ?? $default);
}

function priorityBadgeClass($priority)
{
    switch ($priority) {
        case 'high':
            return 'municipal-badge-priority-high';
        case 'medium':
            return 'municipal-badge-priority-medium';
        case 'low':
            return 'municipal-badge-priority-low';
        default:
            return '';
    }
}

// Reopen the create modal automatically if that specific submission failed
$reopenModal = !empty($error) && ($_POST['action'] ?? '') === 'add_program';

$pageTitle = "Program Planning";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Program Planning | NutriTrack Municipal</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/municipal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_municipal.php"; ?>

    <main class="municipal-dashboard">

        <div class="municipal-page-header">
            <div>
                <!-- <h1 class="municipal-page-title">Program Planning</h1> -->
                <p class="municipal-welcome-message">Plan and manage municipal nutrition programs</p>
            </div>
            <div class="municipal-header-actions">
                <button type="button" class="municipal-btn municipal-btn-submit" id="open-create-program-modal">
                    <i class="fas fa-plus"></i> Create Program
                </button>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="municipal-alert municipal-alert-success">
                <i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="municipal-alert municipal-alert-error">
                <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Priority Hint Banner -->
        <?php if (!empty($priorityHint)): ?>
            <div class="municipal-priority-hint">
                <div class="municipal-priority-hint-icon"><i class="fas fa-lightbulb"></i></div>
                <div class="municipal-priority-hint-text">
                    <strong>Data-driven suggestion:</strong>
                    <?php $hints = [];
                    foreach ($priorityHint as $s):
                        $hints[] = htmlspecialchars($s['name']) . " ({$s['at_risk_pct']}% at risk)";
                    endforeach; ?>
                    Barangays with the highest at-risk rates right now: <?= implode(', ', $hints) ?>. Consider prioritizing
                    these when creating a new program.
                </div>
            </div>
        <?php endif; ?>

        <!-- Program Posts Feed -->
        <section class="section">


            <div class="municipal-table-topbar">
                <span class="municipal-filter-count" id="programCount"><?= count($programs) ?> program
                    post<?= count($programs) !== 1 ? 's' : '' ?></span>
            </div>

            <!-- Filter Bar -->
            <div class="municipal-filter-bar">
                <div class="municipal-filter-select-wrap">
                    <select id="programBarangayFilter" class="municipal-form-input municipal-filter-select"
                        onchange="filterProgramFeed()">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangayList as $b): ?>
                            <option value="<?= htmlspecialchars(strtolower($b['barangay_name'])) ?>">
                                <?= htmlspecialchars($b['barangay_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                </div>

                <div class="municipal-filter-select-wrap">
                    <select id="programPriorityFilter" class="municipal-form-input municipal-filter-select"
                        onchange="filterProgramFeed()">
                        <option value="">All Priorities</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                    <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                </div>

                <div class="municipal-filter-select-wrap">
                    <select id="programCategoryFilter" class="municipal-form-input municipal-filter-select"
                        onchange="filterProgramFeed()">
                        <option value="">All Categories</option>
                        <?php foreach (PROGRAM_CATEGORIES as $cat): ?>
                            <option value="<?= htmlspecialchars(strtolower($cat)) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down municipal-filter-select-icon"></i>
                </div>

                <div class="municipal-filter-search">
                    <i class="fas fa-magnifying-glass municipal-filter-search-icon"></i>
                    <input type="text" id="programSearch" class="municipal-form-input municipal-filter-search-input"
                        placeholder="Search by program name or description..." oninput="filterProgramFeed()">
                </div>
            </div>

            <div class="municipal-program-feed" id="programFeed">
                <?php if (!empty($programs)): ?>
                    <?php foreach ($programs as $p): ?>
                        <div class="municipal-program-post" data-name="<?= htmlspecialchars(strtolower($p['program_name'])) ?>"
                            data-desc="<?= htmlspecialchars(strtolower($p['description'] ?? '')) ?>"
                            data-priority="<?= htmlspecialchars($p['priority_level']) ?>"
                            data-category="<?= htmlspecialchars(strtolower($p['category'] ?? '')) ?>"
                            data-barangays="<?= htmlspecialchars(strtolower($p['target_names'] ?? '')) ?>"
                            data-scope="<?= htmlspecialchars($p['target_scope']) ?>">
                            <div class="municipal-program-post-header">
                                <div class="municipal-program-post-meta">
                                    <span class="municipal-badge <?= priorityBadgeClass($p['priority_level']) ?>">
                                        <?= htmlspecialchars(PRIORITY_LEVELS[$p['priority_level']] ?? ucfirst($p['priority_level'])) ?>
                                    </span>
                                    <?php if (!empty($p['category'])): ?>
                                        <span
                                            class="municipal-badge municipal-badge-neutral"><?= htmlspecialchars($p['category']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="municipal-program-post-actions">
                                    <button type="button" class="municipal-icon-btn edit-program-btn" data-program='<?= htmlspecialchars(json_encode([
                                        'program_id' => $p['program_id'],
                                        'program_name' => $p['program_name'],
                                        'description' => $p['description'],
                                        'target_scope' => $p['target_scope'],
                                        'target_barangay_ids' => array_map(
                                            fn($b) => (int) $b['barangay_id'],
                                            $pdo->query("SELECT barangay_id FROM program_barangays WHERE program_id = {$p['program_id']}")->fetchAll()
                                        ),
                                        'priority_level' => $p['priority_level'],
                                        'start_date' => $p['start_date'],
                                        'end_date' => $p['end_date'],
                                    ]), ENT_QUOTES) ?>' title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <a href="?delete_program=1&id=<?= $p['program_id'] ?>" class="municipal-icon-btn btn-danger"
                                        onclick="return confirm('Delete this program post permanently?');" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>

                            <h3 class="municipal-program-post-title"><?= htmlspecialchars($p['program_name']) ?></h3>

                            <?php if (!empty($p['description'])): ?>
                                <p class="municipal-program-post-desc"><?= nl2br(htmlspecialchars($p['description'])) ?></p>
                            <?php endif; ?>

                            <div class="municipal-program-post-footer">
                                <div class="municipal-program-post-tags">
                                    <i class="fas fa-location-dot" style="color:#4338ca;font-size:12px;"></i>
                                    <?= $p['target_scope'] === 'all' ? '<strong>All Barangays</strong>' : htmlspecialchars($p['target_names'] ?: '—') ?>
                                </div>
                                <div class="municipal-program-post-date">
                                    <i class="fas fa-calendar" style="font-size:12px;"></i>
                                    <?= date('M j, Y', strtotime($p['start_date'])) ?> –
                                    <?= date('M j, Y', strtotime($p['end_date'])) ?>
                                    <span class="municipal-stat-footer-muted" style="margin-left:8px;">
                                        · Posted <?= date('M j, Y', strtotime($p['created_at'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="municipal-dash-empty" style="padding:40px;">
                        <div class="municipal-dash-empty-icon">📋</div>
                        <div class="municipal-dash-empty-text">No program posts yet. Click "Create Program" to post one.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Filter empty state -->
            <div id="programEmptyState" style="display:none;" class="municipal-dash-empty program-empty-filter">
                <div class="municipal-dash-empty-icon">🔍</div>
                <div class="municipal-dash-empty-text">No programs match your current filters.</div>
            </div>

        </section>
    </main>

    <!-- Create / Edit Program Modal -->
    <div class="municipal-modal-overlay" id="create-program-overlay" <?= $reopenModal ? '' : 'style="display:none;"' ?>>
        <div class="municipal-modal">
            <div class="municipal-modal-header">
                <div class="municipal-modal-header-text">
                    <div class="municipal-modal-title" id="program-modal-title">Create New Program</div>
                    <div class="municipal-modal-subtitle">Plan a nutrition program and assign target barangays</div>
                </div>
                <button type="button" class="municipal-modal-close" id="close-create-program-modal" aria-label="Close">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="" class="municipal-form" id="add-program-form" novalidate>
                <input type="hidden" name="action" id="program-form-action" value="add_program">
                <input type="hidden" name="program_id" id="program-form-id" value="">
                <?= csrfInput() ?>

                <div class="municipal-modal-body">
                    <div class="municipal-form-grid">

                        <div class="municipal-form-group municipal-form-group-full">
                            <label class="municipal-form-label">
                                Program Name <span class="municipal-form-required">*</span>
                            </label>
                            <select id="program_name_select" class="municipal-form-input"
                                onchange="handleProgramNameSelect(this.value)">
                                <option value="">-- Select a Program --</option>
                                <option value="Supplementary Feeding Program">Supplementary Feeding Program</option>
                                <option value="Micronutrient Supplementation Program">Micronutrient Supplementation
                                    Program</option>
                                <option value="Deworming Program">Deworming Program</option>
                                <option value="Nutrition Education and Counseling">Nutrition Education and Counseling
                                </option>
                                <option value="Malnutrition Prevention Drive">Malnutrition Prevention Drive</option>
                                <option value="Growth Monitoring Program">Growth Monitoring Program</option>
                                <option value="Vitamin A Supplementation">Vitamin A Supplementation</option>
                                <option value="Iron Supplementation Program">Iron Supplementation Program</option>
                                <option value="Community-Based Nutrition Program">Community-Based Nutrition Program
                                </option>
                                <option value="Other">Other (specify)</option>
                            </select>
                            <input type="text" id="program_name" name="program_name" class="municipal-form-input"
                                placeholder="Enter custom program name" value="<?= old('program_name') ?>"
                                maxlength="150" style="display:none; margin-top:8px;">
                            <span class="municipal-field-error" id="program_name-error">
                                <i class="municipal-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="municipal-err-text"></span>
                            </span>
                        </div>

                        <div class="municipal-form-group municipal-form-group-full">
                            <div class="municipal-description-header">
                                <label class="municipal-form-label">Description <span
                                        class="municipal-form-required">*</span></label>
                                <button type="button" class="municipal-btn-ai-generate" id="ai-generate-btn">
                                    <i class="fas fa-wand-magic-sparkles"></i> Generate with AI
                                </button>
                            </div>
                            <textarea id="description" name="description"
                                class="municipal-form-input municipal-form-textarea"
                                placeholder="Briefly describe the program's goals and activities" maxlength="2000"
                                rows="3"><?= old('description') ?></textarea>
                            <span class="municipal-field-error" id="description-error">
                                <i class="municipal-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="municipal-err-text"></span>
                            </span>
                            <p class="municipal-form-hint" id="ai-generate-status"></p>
                        </div>

                        <div class="municipal-form-group">
                            <label class="municipal-form-label">Priority Level</label>
                            <select id="priority_level" name="priority_level" class="municipal-form-input">
                                <?php foreach (PRIORITY_LEVELS as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= old('priority_level', 'medium') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="municipal-form-group municipal-form-group-full">
                            <label class="municipal-form-label">
                                Target Barangays <span class="municipal-form-required">*</span>
                            </label>
                            <div class="municipal-scope-toggle">
                                <label class="municipal-radio-option">
                                    <input type="radio" name="target_scope" value="all" <?= (old('target_scope', 'specific') === 'all') ? 'checked' : '' ?>>
                                    <span>All Barangays</span>
                                </label>
                                <label class="municipal-radio-option">
                                    <input type="radio" name="target_scope" value="specific" <?= (old('target_scope', 'specific') === 'specific') ? 'checked' : '' ?>>
                                    <span>Specific Barangays</span>
                                </label>
                            </div>

                            <div class="municipal-tag-picker" id="barangay-tag-picker">
                                <div class="municipal-tag-chips" id="barangay-chips-container">
                                    <input type="text" id="barangay-search-input" class="municipal-tag-input"
                                        placeholder="Type to search barangays..." autocomplete="off">
                                </div>
                                <div class="municipal-tag-dropdown" id="barangay-tag-dropdown"></div>
                            </div>
                            <span class="municipal-field-error" id="target_barangays-error">
                                <i class="municipal-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="municipal-err-text"></span>
                            </span>
                        </div>


                        <div class="municipal-form-group">
                            <label class="municipal-form-label">
                                Start Date <span class="municipal-form-required">*</span>
                            </label>
                            <input type="date" id="start_date" name="start_date" class="municipal-form-input"
                                value="<?= old('start_date') ?>" required>
                            <span class="municipal-field-error" id="start_date-error">
                                <i class="municipal-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="municipal-err-text"></span>
                            </span>
                        </div>

                        <div class="municipal-form-group">
                            <label class="municipal-form-label">
                                End Date <span class="municipal-form-required">*</span>
                            </label>
                            <input type="date" id="end_date" name="end_date" class="municipal-form-input"
                                value="<?= old('end_date') ?>" required>
                            <span class="municipal-field-error" id="end_date-error">
                                <i class="municipal-warn-icon fas fa-exclamation-triangle"></i>
                                <span class="municipal-err-text"></span>
                            </span>
                        </div>

                    </div>
                </div>

                <div class="municipal-form-actions">
                    <span class="municipal-form-required-note">* Required fields</span>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="municipal-btn municipal-btn-deactivate"
                            id="cancel-create-program-modal">Cancel</button>
                        <button type="submit" class="municipal-btn municipal-btn-submit" id="program-form-submit-btn">
                            <i class="fas fa-plus"></i> Create Program
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>


    <?php include "../../includes/footer.php"; ?>

    <script>
        // Barangay list from PHP, used by the tag picker below
        const ALL_BARANGAYS = <?= json_encode(array_map(fn($b) => ['id' => (int) $b['barangay_id'], 'name' => $b['barangay_name']], $barangayList)) ?>;
        const OLD_SELECTED_BARANGAYS = <?= json_encode(array_map('intval', $_POST['target_barangays'] ?? [])) ?>;

        // ── Create/Edit modal open/close ──
        const overlay = document.getElementById('create-program-overlay');
        const openBtn = document.getElementById('open-create-program-modal');
        const closeBtn = document.getElementById('close-create-program-modal');
        const cancelBtn = document.getElementById('cancel-create-program-modal');
        const modalTitle = document.getElementById('program-modal-title');
        const formAction = document.getElementById('program-form-action');
        const formProgramId = document.getElementById('program-form-id');
        const submitBtn = document.getElementById('program-form-submit-btn');
        const programForm = document.getElementById('add-program-form');

        function openModal() { overlay.style.display = 'flex'; }
        function closeModal() { overlay.style.display = 'none'; }

        function resetToCreateMode() {
            modalTitle.textContent = 'Create New Program';
            formAction.value = 'add_program';
            formProgramId.value = '';
            submitBtn.innerHTML = '<i class="fas fa-plus"></i> Create Program';
            programForm.reset();
            document.getElementById('program_name_select').value = '';
            document.getElementById('program_name').style.display = 'none';
            document.getElementById('program_name').value = '';
            selectedIds.clear();
            renderChips();
            updateScopeVisibility();
        }

        openBtn.addEventListener('click', function () {
            resetToCreateMode();
            openModal();
        });
        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.style.display === 'flex') closeModal();
        });

        // ── Edit Program: populate modal from row data ──
        document.querySelectorAll('.edit-program-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const p = JSON.parse(this.dataset.program);

                modalTitle.textContent = 'Edit Program';
                formAction.value = 'edit_program';
                formProgramId.value = p.program_id;
                submitBtn.innerHTML = '<i class="fas fa-check"></i> Save Changes';

                const selectEl = document.getElementById('program_name_select');
                const customInput = document.getElementById('program_name');
                const predefinedOptions = Array.from(selectEl.options).map(o => o.value);
                if (predefinedOptions.includes(p.program_name)) {
                    selectEl.value = p.program_name;
                    customInput.style.display = 'none';
                    customInput.value = p.program_name;
                } else {
                    selectEl.value = 'Other';
                    customInput.style.display = 'block';
                    customInput.value = p.program_name || '';
                }
                document.getElementById('description').value = p.description || '';
                // document.getElementById('category').value = p.category || '';
                document.getElementById('priority_level').value = p.priority_level || 'medium';
                document.getElementById('start_date').value = p.start_date || '';
                document.getElementById('end_date').value = p.end_date || '';

                document.querySelector(`input[name="target_scope"][value="${p.target_scope}"]`).checked = true;

                selectedIds.clear();
                (p.target_barangay_ids || []).forEach(id => selectedIds.add(id));
                renderChips();
                updateScopeVisibility();

                openModal();
            });
        });

        // ── Barangay tag picker ──
        const tagPicker = document.getElementById('barangay-tag-picker');
        const chipsContainer = document.getElementById('barangay-chips-container');
        const searchInput = document.getElementById('barangay-search-input');
        const dropdown = document.getElementById('barangay-tag-dropdown');
        const selectedIds = new Set();

        function renderChips() {
            chipsContainer.querySelectorAll('.municipal-tag-chip').forEach(el => el.remove());
            chipsContainer.querySelectorAll('input[name="target_barangays[]"]').forEach(el => el.remove());

            selectedIds.forEach(id => {
                const brgy = ALL_BARANGAYS.find(b => b.id === id);
                if (!brgy) return;

                const chip = document.createElement('span');
                chip.className = 'municipal-tag-chip';
                chip.innerHTML = `${brgy.name} <button type="button" class="municipal-tag-chip-remove" data-id="${id}" aria-label="Remove">&times;</button>`;
                chipsContainer.insertBefore(chip, searchInput);

                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'target_barangays[]';
                hidden.value = id;
                chipsContainer.appendChild(hidden);
            });
        }

        function renderDropdown() {
            const query = searchInput.value.trim().toLowerCase();
            const options = ALL_BARANGAYS.filter(b => !selectedIds.has(b.id) && b.name.toLowerCase().includes(query));

            dropdown.innerHTML = '';
            if (options.length === 0) {
                dropdown.innerHTML = '<div class="municipal-tag-dropdown-empty">No matching barangays</div>';
            } else {
                options.forEach(b => {
                    const item = document.createElement('div');
                    item.className = 'municipal-tag-dropdown-item';
                    item.textContent = b.name;
                    item.dataset.id = b.id;
                    dropdown.appendChild(item);
                });
            }
            dropdown.style.display = 'block';
        }

        function closeDropdown() { dropdown.style.display = 'none'; }

        searchInput.addEventListener('focus', renderDropdown);
        searchInput.addEventListener('input', renderDropdown);

        dropdown.addEventListener('click', function (e) {
            const item = e.target.closest('.municipal-tag-dropdown-item');
            if (!item) return;
            selectedIds.add(parseInt(item.dataset.id, 10));
            searchInput.value = '';
            renderChips();
            renderDropdown();
            searchInput.focus();
        });

        chipsContainer.addEventListener('click', function (e) {
            const removeBtn = e.target.closest('.municipal-tag-chip-remove');
            if (removeBtn) {
                selectedIds.delete(parseInt(removeBtn.dataset.id, 10));
                renderChips();
                return;
            }
            if (e.target === chipsContainer) searchInput.focus();
        });

        document.addEventListener('click', function (e) {
            if (!tagPicker.contains(e.target)) closeDropdown();
        });

        const scopeRadios = document.querySelectorAll('input[name="target_scope"]');
        function updateScopeVisibility() {
            const selected = document.querySelector('input[name="target_scope"]:checked');
            tagPicker.style.display = (selected && selected.value === 'specific') ? 'block' : 'none';
        }
        scopeRadios.forEach(r => r.addEventListener('change', updateScopeVisibility));

        OLD_SELECTED_BARANGAYS.forEach(id => selectedIds.add(id));
        renderChips();
        updateScopeVisibility();

        // ── Field validation ──

        // ── AI Generate Description ──
        const aiGenerateBtn = document.getElementById('ai-generate-btn');
        const aiStatusEl = document.getElementById('ai-generate-status');
        const descriptionEl = document.getElementById('description');

        aiGenerateBtn.addEventListener('click', function () {
            const selectEl = document.getElementById('program_name_select');
            const programName = (selectEl.value && selectEl.value !== 'Other')
                ? selectEl.value
                : document.getElementById('program_name').value.trim();
            const scope = document.querySelector('input[name="target_scope"]:checked').value;
            const targetBarangays = Array.from(selectedIds);

            if (!programName) {
                aiStatusEl.textContent = 'Please enter a program name first.';
                aiStatusEl.style.color = '#f04438';
                return;
            }
            if (scope === 'specific' && targetBarangays.length === 0) {
                aiStatusEl.textContent = 'Please select at least one barangay first.';
                aiStatusEl.style.color = '#f04438';
                return;
            }

            aiGenerateBtn.disabled = true;
            aiGenerateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            aiStatusEl.textContent = '';

            const formData = new URLSearchParams();
            formData.append('action', 'generate_ai_description');
            formData.append('program_name', programName);
            formData.append('program_name_select', document.getElementById('program_name_select').value);
            formData.append('target_scope', scope);
            targetBarangays.forEach(id => formData.append('target_barangays[]', id));

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        descriptionEl.value = data.description;
                        aiStatusEl.textContent = 'Description generated based on current barangay nutrition data.';
                        aiStatusEl.style.color = '#12b76a';
                    } else {
                        aiStatusEl.textContent = data.message || 'Failed to generate description.';
                        aiStatusEl.style.color = '#f04438';
                    }
                })
                .catch(() => {
                    aiStatusEl.textContent = 'Failed to generate description. Please try again.';
                    aiStatusEl.style.color = '#f04438';
                })
                .finally(() => {
                    aiGenerateBtn.disabled = false;
                    aiGenerateBtn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate with AI';
                });
        });


        const touched = new Set();
        const Validate = {
            description(v) {
                if (!v || !v.trim()) return 'Description is required.';
                return null;
            },
            program_name(v) {
                const select = document.getElementById('program_name_select');
                const val = (select && select.value !== '') ? select.value : v;
                if (!val || !val.trim()) return 'Program name is required.';
                if (val.length > 150) return 'Program name is too long (max 150 characters).';
                return null;
            },
            start_date(v) {
                if (!v) return 'Start date is required.';
                return null;
            },
            end_date(v) {
                if (!v) return 'End date is required.';
                const start = document.getElementById('start_date').value;
                if (start && v < start) return 'End date cannot be before the start date.';
                return null;
            }
        };

        function handleProgramNameSelect(value) {
            const customInput = document.getElementById('program_name');
            if (value === 'Other') {
                customInput.style.display = 'block';
                customInput.value = '';
                customInput.focus();
            } else {
                customInput.style.display = 'none';
                customInput.value = value;
            }
        }

        function showError(fieldId, msg) {
            const inp = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '-error');
            if (inp) inp.classList.add('input-invalid');
            if (err) { err.querySelector('.municipal-err-text').textContent = msg; err.classList.add('show'); }
        }
        function clearError(fieldId) {
            const inp = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '-error');
            if (inp) inp.classList.remove('input-invalid');
            if (err) { err.querySelector('.municipal-err-text').textContent = ''; err.classList.remove('show'); }
        }
        function attachField(fieldId) {
            const el = document.getElementById(fieldId);
            if (!el) return;
            el.addEventListener('focus', () => touched.add(fieldId));
            el.addEventListener('blur', function () {
                if (!touched.has(fieldId)) return;
                const msg = Validate[fieldId](this.value);
                msg ? showError(fieldId, msg) : clearError(fieldId);
            });
            el.addEventListener('input', function () {
                if (!touched.has(fieldId)) return;
                if (!this.classList.contains('input-invalid')) return;
                const msg = Validate[fieldId](this.value);
                msg ? showError(fieldId, msg) : clearError(fieldId);
            });
        }
        attachField('program_name');
        attachField('description');
        attachField('start_date');
        attachField('end_date');

        // ── Auto fade-out alerts after 2 seconds ──
        document.querySelectorAll('.municipal-alert').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.6s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 600);
            }, 2000);
        });

        programForm.addEventListener('submit', function (e) {
            let hasErr = false;
            ['program_name', 'description', 'start_date', 'end_date'].forEach(id => {
                const el = document.getElementById(id);
                const msg = Validate[id](el ? el.value : '');
                if (msg) { showError(id, msg); hasErr = true; }
                else clearError(id);
            });

            const scope = document.querySelector('input[name="target_scope"]:checked').value;
            if (scope === 'specific' && selectedIds.size === 0) {
                showError('target_barangays', 'Please select at least one barangay, or choose "All Barangays".');
                hasErr = true;
            } else {
                clearError('target_barangays');
            }

            if (hasErr) {
                e.preventDefault();
                const first = document.querySelector('.input-invalid');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });


        // ── Program Feed Filter ──
        function filterProgramFeed() {
            const barangayVal = document.getElementById('programBarangayFilter').value.toLowerCase();
            const priorityVal = document.getElementById('programPriorityFilter').value.toLowerCase();
            const categoryVal = document.getElementById('programCategoryFilter').value.toLowerCase();
            const searchVal = document.getElementById('programSearch').value.toLowerCase().trim();

            const posts = document.querySelectorAll('#programFeed .municipal-program-post');
            let visible = 0;

            posts.forEach(post => {
                const name = post.dataset.name || '';
                const desc = post.dataset.desc || '';
                const priority = post.dataset.priority || '';
                const category = post.dataset.category || '';
                const barangays = post.dataset.barangays || '';
                const scope = post.dataset.scope || '';

                const matchBarangay = !barangayVal || scope === 'all' || barangays.includes(barangayVal);
                const matchPriority = !priorityVal || priority === priorityVal;
                const matchCategory = !categoryVal || category === categoryVal;
                const matchSearch = !searchVal || name.includes(searchVal) || desc.includes(searchVal);

                const show = matchBarangay && matchPriority && matchCategory && matchSearch;
                post.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            const countEl = document.getElementById('programCount');
            if (countEl) countEl.textContent = visible + ' program post' + (visible !== 1 ? 's' : '');

            const emptyState = document.getElementById('programEmptyState');
            if (emptyState) emptyState.style.display = (visible === 0 && posts.length > 0) ? 'block' : 'none';
        }

    </script>

</body>

</html>