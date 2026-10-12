<?php
session_start();
set_time_limit(300); // allow up to 5 minutes for bulk AI generation
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/openrouter.php';
require_once __DIR__ . '/../../includes/functions.php';

// Redirect if not a health worker
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}
$barangay_id = $_SESSION['barangay_id'];
// Fetch barangay name for PDF report header
$brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$brgyStmt->execute([':id' => $barangay_id]);
$barangay_name = $brgyStmt->fetchColumn();
$worker_id = (int) $_SESSION['worker_id'];
$nameStmt = $pdo->prepare("SELECT full_name FROM health_workers WHERE worker_id = :id LIMIT 1");
$nameStmt->execute([':id' => $worker_id]);
$nameRow = $nameStmt->fetch();
$worker_full_name = $nameRow['full_name'] ?? 'Health Worker';

// --------------------------------------
// HELPER FUNCTION: CALL ML MODEL VIA FLASK API
// --------------------------------------
function get_ai_recommendation($bmi) {
    if (!$bmi) {
        return ['status' => '-', 'recommendation' => '-', 'tip' => '-'];
    }

    $flask_url = 'http://127.0.0.1:5000/predict';
    $api_token = defined('FLASK_API_TOKEN') ? FLASK_API_TOKEN : '';

    $ch = curl_init($flask_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['bmi' => (float)$bmi]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $output   = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err || !$output) {
        return ['status' => 'Error', 'recommendation' => 'AI service unavailable', 'tip' => '-'];
    }

    $data = json_decode($output, true);
    if (!$data) {
        return ['status' => 'Error', 'recommendation' => 'Invalid AI output', 'tip' => '-'];
    }

    return [
        'status'         => $data['status']         ?? '-',
        'recommendation' => $data['recommendation'] ?? '-',
        'tip'            => $data['tip']             ?? '-'
    ];
}

// --------------------------------------
// CALL OPENROUTER AI FOR PERSONALIZED RECOMMENDATION
// --------------------------------------
function get_openrouter_recommendation($status, $bmi, $age, $gender, $name = '', $height = null, $weight = null) {
    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $model   = 'gemini-3.5-flash-lite';
    $url     = defined('GEMINI_API_URL') ? GEMINI_API_URL : 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent';

    if (!$api_key) {
        error_log("NutriTrack OpenRouter: API key not set in config/openrouter.php");
        return null;
    }

if ($status === 'Underweight') {
    $advice_focus = "Always recommend increasing caloric intake with nutrient-dense foods like eggs, rice, and protein-rich foods. Always emphasize eating 3 full meals per day and healthy snacking between meals.";
} elseif ($status === 'Normal') {
    $advice_focus = "Always recommend maintaining their healthy weight through balanced meals with vegetables, fruits, and lean protein. Always emphasize staying consistent with healthy eating habits.";
} elseif ($status === 'Overweight') {
    $advice_focus = "Always recommend reducing fatty and oily foods and controlling rice portions. Always emphasize replacing sugary drinks with water or calamansi juice and doing light daily exercise.";
} elseif ($status === 'Obese') {
    $advice_focus = "Always recommend significant reduction of processed foods, fatty meals, and sugary drinks. Always emphasize the importance of portion control and gradual lifestyle changes through diet and light physical activity.";
} else {
    $advice_focus = "Always recommend general healthy eating habits with balanced meals.";
}

$tips = [
    'Underweight' => [
        "Subukang kumain ng itlog o manok araw-araw para unti-unting madagdagan ang iyong timbang.",
        "Kumain ng saging o mani bilang meryenda sa umaga o hapon para may dagdag na lakas.",
        "Uminom ng gatas bago matulog para makatulong sa paglaki at pagpapalakas ng katawan.",
        "Magdagdag ng isang tasang kanin sa bawat kain para may sapat kang lakas buong araw.",
        "Kumain ng tatlong beses sa isang araw at iwasang laktawan ang pagkain para mapanatili ang tamang timbang."
    ],
    'Normal' => [
        "Kumain ng iba't ibang sariwa at lutong pagkain araw-araw para manatiling malusog.",
        "Uminom ng sapat na tubig sa buong araw para hindi ka madaling mapagod.",
        "Mag-ehersisyo ng 30 minuto ilang beses sa isang linggo para manatiling aktibo.",
        "Magdagdag ng gulay at prutas sa bawat kain para makatulong sa pagpapanatiling malusog ng katawan.",
        "Kumain ng tamang dami ng kanin at ulam para mapanatili ang iyong timbang."
    ],
    'Overweight' => [
        "Subukang tubig o malamig na calamansi na lang ang inumin kaysa matatamis na inumin para makatulong sa pagpapababa ng timbang.",
        "Bawasan nang kaunti ang pagkain ng kanin sa bawat kain para unti-unting bumaba ang timbang.",
        "Maglakad nang 10 minuto pagkatapos kumain para manatiling aktibo.",
        "Subukang bawasan ang pritong pagkain at dagdagan ang gulay o isda sa iyong pagkain.",
        "Dagdagan ang gulay kaysa kanin para mas mabusog at makatulong sa pagkontrol ng timbang."
    ],
    'Obese' => [
        "Palitan ang softdrinks at matatamis na juice ng tubig para mabawasan ang pag-inom ng asukal.",
        "Bawasan ang pagkain ng pritong at maalat na pagkain para makatulong sa pagbaba ng timbang.",
        "Maglakad nang 15 minuto tuwing umaga para mas maging aktibo ang iyong katawan.",
        "Kumain ng tamang dami ng pagkain at iwasang sobra-sobra ang bawat kain.",
        "Palitan ang junk food bilang meryenda ng prutas o gulay para mas maging healthy ang iyong pagkain."
    ]
];

$tip = isset($tips[$status]) ? $tips[$status][array_rand($tips[$status])] : "Uminom ng sapat na tubig sa buong araw para manatiling malusog ang katawan.";

    $height_text = $height ? "{$height} cm" : "not recorded";
    $weight_text = $weight ? "{$weight} kg" : "not recorded";

   // Get first name only
$first_name = explode(' ', trim($name))[0];

$prompt = "You are a professional nutrition advisor at a barangay health center in the Philippines. "
        . "Write a personalized nutrition recommendation for {$first_name}. "
        . "Profile: BMI: {$bmi}, Nutritional Status: {$status}. "
        . "RULES: "
        . "1. ALWAYS follow this EXACT 3-sentence structure: "
        . "Sentence 1: '{$first_name}, ang iyong BMI na {$bmi} ay nasa {$status} na antas.' "
        . "Sentence 2: Specific food advice — what to eat or avoid based on {$status}. Include at least 2-3 specific Filipino foods. "
        . "Sentence 3: Exercise/lifestyle recommendation — include specific activities like paglalakad, pag-unat, home exercises, light jogging with duration. Always use numbers for time (e.g. 15-20 minuto, 30 minuto) never spell them out in words. "
        . "2. ADVICE FOCUS per status: {$advice_focus} "
        . "3. Daily tip: Use this exact tip — {$tip} "
        . "4. Write in plain Tagalog only — no English words, no asterisks, no markdown. "
        . "5. STRICTLY PROHIBITED: Never mention medicine, drugs, supplements, or medical diagnosis. "
        . "6. SCOPE: Food, meal, portion, and lifestyle/exercise advice only. "
        . "7. STRICT LIMIT: Recommendation = EXACTLY 3 sentences. Daily tip = EXACTLY 1 sentence. "
        . "8. Use a warm, professional, and encouraging tone. "
        . "Respond ONLY in this exact JSON format with no extra text: "
        . "{\"recommendation\": \"...\", \"tip\": \"...\"}";

    $payload = json_encode([
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 1500,
            'temperature' => 0.7
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $output    = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err || $http_code !== 200) {
        error_log("NutriTrack OpenRouter FAILED - HTTP $http_code - Error: $curl_err - Response: $output");
        return null;
    }

    $data = json_decode($output, true);
    if (!$data || empty($data['candidates'][0]['content']['parts'][0]['text'])) {
        error_log("NutriTrack Gemini EMPTY RESPONSE - Raw output: $output");
        return null;
    }
    $content = $data['candidates'][0]['content']['parts'][0]['text'];
    $content = preg_replace('/```json|```/', '', $content);
    $content = trim($content);

    $parsed = json_decode($content, true);
    if (!$parsed || empty($parsed['recommendation'])) {
        error_log("NutriTrack OpenRouter PARSE FAILED - Raw content: $content");
        return null;
    }

return [
    'recommendation' => $parsed['recommendation'] ?? '-',
    'tip'            => $tip
];

}

// --------------------------------------
// DETERMINE BMI STATUS FROM BMI VALUE
// --------------------------------------
function get_bmi_status($bmi) {
    if ($bmi < 18.5) return 'Underweight';
    if ($bmi < 25.0) return 'Normal';
    if ($bmi < 30.0) return 'Overweight';
    return 'Obese';
}

// --------------------------------------
// CALCULATE AGE
// --------------------------------------
function calculate_age($birth_date) {
    if (!$birth_date) return '-';
    $birth = new DateTime($birth_date);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

// --------------------------------------
// HANDLE SINGLE RESIDENT CACHE CLEAR (AJAX)
// --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refresh_resident') {
    $resident_id = (int)($_POST['resident_id'] ?? 0);
    if ($resident_id) {
        $checkStmt = $pdo->prepare("SELECT resident_id FROM residents WHERE resident_id = ? AND barangay_id = ?");
        $checkStmt->execute([$resident_id, $barangay_id]);
        if ($checkStmt->fetch()) {
            $stmt = $pdo->prepare("
                UPDATE health_records
                SET ai_status = NULL, ai_recommendation = NULL, ai_tip = NULL, ai_generated_at = NULL
                WHERE resident_id = ?
                  AND barangay_id = ?
                  AND record_id = (SELECT MAX(record_id) FROM health_records h2 WHERE h2.resident_id = ?)
            ");
            $stmt->execute([$resident_id, $barangay_id, $resident_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Resident not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid resident']);
    }
    exit();
}

// --------------------------------------
// FIX 2: LAZY-LOAD AI GENERATION PER RESIDENT (AJAX)
// --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_resident_ai') {
    $resident_id = (int)($_POST['resident_id'] ?? 0);

    $checkStmt = $pdo->prepare("
        SELECT r.resident_id, r.first_name, r.last_name, r.gender, r.birth_date,
               hr.weight, hr.height, hr.bmi
        FROM residents r
        LEFT JOIN health_records hr ON r.resident_id = hr.resident_id
            AND hr.record_id = (SELECT MAX(record_id) FROM health_records h2 WHERE h2.resident_id = r.resident_id)
        WHERE r.resident_id = ? AND r.barangay_id = ?
    ");
    $checkStmt->execute([$resident_id, $barangay_id]);
    $res = $checkStmt->fetch();

    if (!$res || !$res['bmi']) {
        echo json_encode(['success' => false, 'message' => 'No data']);
        exit();
    }

    $bmi    = $res['bmi'];
    $status = get_bmi_status((float)$bmi);
    $age    = calculate_age($res['birth_date']);
    $gender = ucfirst($res['gender'] ?? 'unknown');
    $name   = $res['first_name'] . ' ' . $res['last_name'];

    $ai = get_openrouter_recommendation($status, round($bmi, 2), $age, $gender, $name, $res['height'], $res['weight']);
    if (!$ai) {
        // FIX 1 applied here too: Flask fallback does NOT overwrite $status
        $flask          = get_ai_recommendation($bmi);
        $recommendation = $flask['recommendation'];
        $tip            = $flask['tip'];
    } else {
        $recommendation = $ai['recommendation'];
        $tip            = $ai['tip'];
    }

    $pdo->prepare("
        UPDATE health_records
        SET ai_status = ?, ai_recommendation = ?, ai_tip = ?, ai_generated_at = NOW()
        WHERE resident_id = ?
          AND record_id = (SELECT MAX(record_id) FROM health_records h2 WHERE h2.resident_id = ?)
    ")->execute([$status, $recommendation, $tip, $resident_id, $resident_id]);

    echo json_encode([
        'success'        => true,
        'status'         => $status,
        'recommendation' => $recommendation,
        'tip'            => $tip
    ]);
    exit();
}

// --------------------------------------
// HANDLE REGENERATE ALL (AJAX)
// --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'regenerate_all') {
    $stmt = $pdo->prepare("UPDATE health_records SET ai_status = NULL, ai_recommendation = NULL, ai_tip = NULL, ai_generated_at = NULL WHERE barangay_id = ?");
    $stmt->execute([$barangay_id]);
    echo json_encode(['success' => true]);
    exit();
}

// --------------------------------------
// FETCH RESIDENTS + LATEST HEALTH RECORD
// --------------------------------------
$stmt = $pdo->prepare("
    SELECT
        r.resident_id,
        r.first_name,
        r.last_name,
        r.gender,
        r.birth_date,
        hr.weight,
        hr.height,
        hr.bmi,
        hr.record_date,
        hr.ai_status,
        hr.ai_recommendation,
        hr.ai_tip,
        hr.ai_generated_at
    FROM residents r
    LEFT JOIN health_records hr
        ON r.resident_id = hr.resident_id
        AND hr.record_id = (
            SELECT MAX(record_id)
            FROM health_records
            WHERE resident_id = r.resident_id
        )
    WHERE r.barangay_id = :brgy
    ORDER BY r.first_name, r.last_name
");
$stmt->execute([':brgy' => $barangay_id]);
$residents = $stmt->fetchAll();

// Initialize statistics
$total_residents   = count($residents);
$normal_count      = 0;
$underweight_count = 0;
$overweight_count  = 0;
$obese_count       = 0;
$at_risk_count     = 0;
$no_record_count   = 0;

$processed = [];

foreach ($residents as $resident) {
    $weight = $resident['weight'] ?? null;
    $height = $resident['height'] ?? null;
    $bmi    = $resident['bmi']    ?? null;

    if ($bmi === null && $weight && $height && $height > 0) {
        $bmi = $weight / (($height / 100) ** 2);
    }

    $age        = calculate_age($resident['birth_date'] ?? null);
    $gender     = ucfirst($resident['gender'] ?? 'unknown');
    $name       = $resident['first_name'] . ' ' . $resident['last_name'];
    $last_check = $resident['record_date']
                    ? date('M d, Y', strtotime($resident['record_date']))
                    : '-';

    // AI freshness
    $ai_generated_at = $resident['ai_generated_at'] ?? null;
    $freshness_label = '';
    $freshness_class = '';
    if ($ai_generated_at) {
        $generated = new DateTime($ai_generated_at);
        $now       = new DateTime();
        $diff_days = (int)$now->diff($generated)->days;
        if ($diff_days === 0) {
            $freshness_label = 'Fresh';
            $freshness_class = 'ai-fresh';
        } elseif ($diff_days <= 7) {
            $freshness_label = $diff_days . 'd ago';
            $freshness_class = 'ai-old';
        } else {
            $freshness_label = 'Outdated';
            $freshness_class = 'ai-very-old';
        }
    }

    $is_pending = false;

    if ($bmi) {
        $cache_valid = !empty($resident['ai_status'])
                    && !empty($resident['ai_recommendation'])
                    && !empty($resident['ai_generated_at']);

        if ($cache_valid) {
            // Use cached values — no API call needed
            $status         = get_bmi_status((float)$bmi);
            $recommendation = $resident['ai_recommendation'];
            $tip            = $resident['ai_tip'];
        } else {
            // FIX 2: Don't generate AI on page load — mark as pending
            // The JS queue will call generate_resident_ai per row after render
            $status         = get_bmi_status((float)$bmi);
            $recommendation = '';
            $tip            = '';
            $is_pending     = true;
        }
    } else {
        $status         = 'No Record';
        $recommendation = 'No health record available';
        $tip            = '-';
    }

    // ── Underweight now counts as at_risk ──
    if ($status === 'Normal')          $normal_count++;
    elseif ($status === 'Underweight') { $underweight_count++; $at_risk_count++; }
    elseif ($status === 'Overweight')  { $overweight_count++;  $at_risk_count++; }
    elseif ($status === 'Obese')       { $obese_count++;       $at_risk_count++; }
    else                               $no_record_count++;

    $dotClass = match(true) {
        $status === 'Underweight'                       => 'yellow',
        $status === 'Normal'                            => 'green',
        $status === 'Overweight' || $status === 'Obese' => 'red',
        default                                         => 'gray'
    };

    $badgeClass = match($status) {
        'Normal'      => 'badge-normal',
        'Underweight' => 'badge-underweight',
        'Overweight'  => 'badge-overweight',
        'Obese'       => 'badge-obese',
        default       => 'badge-norecord'
    };

    $processed[] = [
        'resident'        => $resident,
        'bmi'             => $bmi,
        'age'             => $age,
        'last_check'      => $last_check,
        'status'          => $status,
        'recommendation'  => $recommendation,
        'tip'             => $tip,
        'dotClass'        => $dotClass,
        'badgeClass'      => $badgeClass,
        'freshness_label' => $freshness_label,
        'freshness_class' => $freshness_class,
        'ai_generated_at' => $ai_generated_at,
        'is_high_risk'    => in_array($status, ['Underweight', 'Overweight', 'Obese']),
        'is_pending'      => $is_pending,
    ];
}

$at_risk_pct = $total_residents > 0 ? round(($at_risk_count / $total_residents) * 100) : 0;

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_health_worker.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
<script>
    window.CURRENT_WORKER_NAME = <?= json_encode($worker_full_name) ?>;
    window.CURRENT_BARANGAY_NAME = <?= json_encode($barangay_name ?? 'Barangay Health Center') ?>;
</script>
<script src="../../assets/js/ai_nutrition_pdf.js"></script>
<img id="logoSystem" src="../../assets/images/pdf-nutritrack-logo.png" style="display:none;">
<img id="logoDOH"    src="../../assets/images/Seal_of_the_Department_of_Health_of_the_Philippines.png" style="display:none;">

<style>
/* BMI Legend inside chart card */
.ai-bmi-divider     { border-top: 1px solid #eef0f3; margin-top: 1.5rem; padding-top: 1.25rem; }
.ai-bmi-legend-title {
    font-size: 0.72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: #adb5bd; margin-bottom: 0.85rem;
}
.ai-bmi-legend-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
}
.ai-bmi-card {
    padding: 10px 14px; border-radius: 8px;
    border: 1.5px solid; display: flex;
    align-items: center; gap: 8px; transition: transform 0.15s;
}
.ai-bmi-card:hover          { transform: translateY(-2px); }
.ai-bmi-card.normal         { border-color: #16a34a; background: #dcfce7; }
.ai-bmi-card.underweight    { border-color: #0891b2; background: #cffafe; }
.ai-bmi-card.overweight     { border-color: #d97706; background: #fef3c7; }
.ai-bmi-card.obese          { border-color: #dc2626; background: #fee2e2; }
.ai-bmi-dot  { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.ai-bmi-name { font-size: 0.8rem; font-weight: 700; color: #2c3e50; }
.ai-bmi-range{ font-size: 0.7rem; color: #6c757d; font-family: monospace; }

/* ── TABLE SECTION ── */
.ai-table-section {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    border: 1px solid #eef0f3;
    margin-bottom: 1.75rem;
}
.ai-table-header {
    display: flex; align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
    flex-wrap: wrap; gap: 0.75rem;
}
.ai-header-actions { display: flex; align-items: center; gap: 0.6rem; }

.ai-btn-regen {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: #f0a500; color: #fff;
    border: none; border-radius: 8px; font-size: 0.84rem;
    font-weight: 600; cursor: pointer; font-family: inherit;
    transition: background 0.2s, transform 0.15s;
}
.ai-btn-regen:hover  { background: #d4920a; transform: translateY(-1px); }

.ai-btn-print {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: #2c3e50; color: #fff;
    border: none; border-radius: 8px; font-size: 0.84rem;
    font-weight: 600; cursor: pointer; font-family: inherit;
    transition: background 0.2s, transform 0.15s;
}
.ai-btn-print:hover  { background: #1a252f; transform: translateY(-1px); }

.ai-filter-bar {
    display: flex; align-items: center;
    gap: 0.65rem; margin-bottom: 1rem; flex-wrap: wrap;
}
.ai-filter-select {
    padding: 7px 30px 7px 11px;
    border: 1px solid #ced4da; border-radius: 6px;
    font-size: 0.84rem; font-family: inherit;
    font-weight: 500; color: #2c3e50;
    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%236c757d'/%3E%3C/svg%3E") no-repeat right 9px center;
    appearance: none; cursor: pointer; outline: none;
    transition: border-color 0.2s;
}
.ai-filter-select:focus { border-color: #2563eb; }

.ai-search-wrap { position: relative; flex: 1; min-width: 200px; }
.ai-search-icon {
    position: absolute; left: 10px; top: 50%;
    transform: translateY(-50%); color: #adb5bd; font-size: 0.8rem;
}
.ai-search-input {
    width: 100%; padding: 7px 12px 7px 30px;
    border: 1px solid #ced4da; border-radius: 6px;
    font-size: 0.84rem; font-family: inherit;
    outline: none; color: #2c3e50; transition: border-color 0.2s;
}
.ai-search-input:focus { border-color: #2563eb; }
.ai-record-count {
    font-size: 0.78rem; color: #6c757d;
    background: #f8f9fa; padding: 4px 10px;
    border-radius: 20px; font-weight: 600; white-space: nowrap;
}

.ai-name-cell   { font-weight: 700; color: #2c3e50; font-size: 0.88rem; }
.ai-resident-id { font-size: 0.7rem; color: #adb5bd; font-family: monospace; margin-top: 2px; }

.ai-status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 0.74rem; font-weight: 700; white-space: nowrap;
}
.ai-status-badge.badge-normal      { background: #dcfce7; color: #16a34a; }
.ai-status-badge.badge-underweight { background: #cffafe; color: #0891b2; }
.ai-status-badge.badge-overweight  { background: #fef3c7; color: #d97706; }
.ai-status-badge.badge-obese       { background: #fee2e2; color: #dc2626; }
.ai-status-badge.badge-norecord    { background: #f3f4f6; color: #9ca3af; }
.ai-badge-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

.ai-high-risk {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.68rem; font-weight: 700; color: #dc2626;
    background: #fee2e2; border: 1px solid #fca5a5;
    padding: 2px 7px; border-radius: 10px; margin-top: 4px;
}

.ai-bmi-normal      { color: #16a34a; font-weight: 700; font-family: monospace; }
.ai-bmi-underweight { color: #0891b2; font-weight: 700; font-family: monospace; }
.ai-bmi-overweight  { color: #d97706; font-weight: 700; font-family: monospace; }
.ai-bmi-obese       { color: #dc2626; font-weight: 700; font-family: monospace; }

.ai-rec-text { font-size: 0.83rem; color: #2c3e50; line-height: 1.55; max-width: 280px; }
.ai-rec-collapsed {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.ai-rec-expanded { -webkit-line-clamp: unset; overflow: visible; }
.ai-read-more {
    background: none; border: none; padding: 2px 0;
    font-size: 0.76rem; font-weight: 600; color: #2563eb;
    cursor: pointer; font-family: inherit; display: inline-block; margin-top: 3px;
}
.ai-read-more:hover { text-decoration: underline; }

/* Pending skeleton shimmer */
.ai-pending-placeholder {
    display: flex; align-items: center; gap: 8px;
    color: #adb5bd; font-size: 0.8rem; font-style: italic;
}
@keyframes ai-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
.ai-pending-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #adb5bd; animation: ai-pulse 1.2s ease-in-out infinite;
}

.ai-tip-text { font-size: 0.8rem; color: #6c757d; line-height: 1.5; max-width: 180px; font-style: italic; }

.ai-freshness      { display: flex; flex-direction: column; gap: 4px; }
.ai-fresh, .ai-old, .ai-very-old {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 10px;
}
.ai-fresh    { background: #dcfce7; color: #16a34a; }
.ai-old      { background: #fef3c7; color: #d97706; }
.ai-very-old { background: #fee2e2; color: #dc3545; }
.ai-freshness-date { font-size: 0.72rem; color: #adb5bd; }

.ai-refresh-btn {
    width: 30px; height: 30px; border-radius: 6px;
    border: 1px solid #dee2e6; background: #fff;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: #6c757d; font-size: 0.78rem;
    transition: all 0.2s;
}
.ai-refresh-btn:hover    { border-color: #2563eb; color: #2563eb; background: #eff6ff; transform: rotate(90deg); }
.ai-refresh-btn.outdated { border-color: #fca5a5; color: #dc3545; }

@keyframes ai-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.ai-refresh-btn.spinning { animation: ai-spin 0.6s linear; }

.ai-empty { text-align: center; padding: 3rem 1rem; color: #6c757d; }
.ai-empty-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
.ai-empty h3   { font-size: 1rem; font-weight: 700; color: #2c3e50; margin-bottom: 0.25rem; }
.ai-empty p    { font-size: 0.85rem; }

.ai-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999; opacity: 0; pointer-events: none; transition: opacity 0.2s;
}
.ai-modal-overlay.show { opacity: 1; pointer-events: all; }
.ai-modal {
    background: #fff; border-radius: 16px; padding: 2rem;
    width: 400px; max-width: 90vw;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    transform: translateY(20px); transition: transform 0.2s;
}
.ai-modal-overlay.show .ai-modal { transform: translateY(0); }
.ai-modal-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: #fff8e6; display: flex; align-items: center;
    justify-content: center; font-size: 1.4rem; margin-bottom: 1rem;
}
.ai-modal-title   { font-size: 1rem; font-weight: 700; color: #2c3e50; margin-bottom: 0.5rem; }
.ai-modal-desc    { font-size: 0.84rem; color: #6c757d; line-height: 1.6; margin-bottom: 1.25rem; }
.ai-modal-actions { display: flex; gap: 0.6rem; justify-content: flex-end; }
.ai-modal-cancel {
    padding: 8px 16px; border-radius: 8px; border: 1px solid #dee2e6;
    background: #fff; font-size: 0.84rem; font-weight: 600;
    color: #6c757d; cursor: pointer; font-family: inherit; transition: all 0.15s;
}
.ai-modal-cancel:hover  { background: #f8f9fa; }
.ai-modal-confirm {
    padding: 8px 16px; border-radius: 8px; border: none;
    background: #f0a500; color: #fff; font-size: 0.84rem;
    font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.15s;
}
.ai-modal-confirm:hover { background: #d4920a; }

@media (max-width: 768px) {
    .ai-stats-grid      { grid-template-columns: 1fr 1fr; }
    .ai-bmi-legend-grid { grid-template-columns: 1fr 1fr; }
    .ai-chart-body      { flex-direction: column; }
    .ai-chart-container { width: 160px; height: 160px; }
    .ai-filter-bar      { flex-direction: column; align-items: stretch; }
    .ai-table-header    { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 480px) {
    .ai-stats-grid      { grid-template-columns: 1fr; }
    .ai-bmi-legend-grid { grid-template-columns: 1fr; }
}
</style>

<main class="dashboard">
    <h1 class="page-title">AI Nutrition Advisor</h1>

    <!-- ── AI CONTEXT SUMMARY ── -->
    <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;">
        <div style="background:#fff;border:1px solid #eef0f3;border-radius:10px;padding:0.75rem 1.25rem;display:flex;align-items:center;gap:0.6rem;box-shadow:0 1px 4px rgba(0,0,0,0.06);">
            <i class="fas fa-users" style="color:#667eea;"></i>
            <span style="font-size:0.84rem;color:#6c757d;">Total:</span>
            <strong style="font-size:0.9rem;color:#2c3e50;"><?= $total_residents ?></strong>
        </div>
        <div style="background:#fff;border:1px solid #eef0f3;border-radius:10px;padding:0.75rem 1.25rem;display:flex;align-items:center;gap:0.6rem;box-shadow:0 1px 4px rgba(0,0,0,0.06);">
            <i class="fas fa-exclamation-circle" style="color:#dc3545;"></i>
            <span style="font-size:0.84rem;color:#6c757d;">At Risk:</span>
            <strong style="font-size:0.9rem;color:#dc3545;"><?= $at_risk_count ?></strong>
        </div>
        <div style="background:#fff;border:1px solid #eef0f3;border-radius:10px;padding:0.75rem 1.25rem;display:flex;align-items:center;gap:0.6rem;box-shadow:0 1px 4px rgba(0,0,0,0.06);">
            <i class="fas fa-check-circle" style="color:#28a745;"></i>
            <span style="font-size:0.84rem;color:#6c757d;">Normal:</span>
            <strong style="font-size:0.9rem;color:#28a745;"><?= $normal_count ?></strong>
        </div>
        <div style="background:#fff;border:1px solid #eef0f3;border-radius:10px;padding:0.75rem 1.25rem;display:flex;align-items:center;gap:0.6rem;box-shadow:0 1px 4px rgba(0,0,0,0.06);">
            <i class="fas fa-user-slash" style="color:#6c757d;"></i>
            <span style="font-size:0.84rem;color:#6c757d;">No Record:</span>
            <strong style="font-size:0.9rem;color:#6c757d;"><?= $no_record_count ?></strong>
        </div>
    </div>

    <!-- ── RESIDENT NUTRITION REPORT ── -->
    <div class="ai-table-section">

        <div class="ai-table-header">
            <h2 class="section-title" style="margin:0;">Resident Nutrition Report</h2>
            <div class="ai-header-actions">
                <button class="ai-btn-regen" onclick="document.getElementById('aiRegenModal').classList.add('show')">
                    <i class="fas fa-sync-alt"></i> Regenerate All
                </button>
                <button class="ai-btn-print" onclick="generateAINutritionPDF()">
                    <i class="fas fa-file-pdf"></i> Print Report
                </button>
            </div>
        </div>

        <div class="ai-filter-bar">
            <select class="ai-filter-select" id="aiGenderFilter" onchange="aiFilterTable()">
                <option value="">All Genders</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
            <select class="ai-filter-select" id="aiStatusFilter" onchange="aiFilterTable()">
                <option value="">All BMI Status</option>
                <option value="Normal">Normal</option>
                <option value="Underweight">Underweight</option>
                <option value="Overweight">Overweight</option>
                <option value="Obese">Obese</option>
                <option value="No Record">No Record</option>
            </select>
            <div class="ai-search-wrap">
                <i class="fas fa-search ai-search-icon"></i>
                <input
                    class="ai-search-input"
                    type="text"
                    id="aiSearchInput"
                    placeholder="Search by name..."
                    oninput="aiFilterTable()"
                >
            </div>
            <span class="ai-record-count" id="aiRecordCount" style="display:none;"></span>
        </div>

        <?php if (!empty($processed)): ?>
        <div style="overflow-x:auto;">
            <table class="table-default" id="aiResidentsTable">
                <thead class="table-head">
                    <tr>
                        <th>Resident</th>
                        <th>Age</th>
                        <th>Gender</th>
                        <th>BMI</th>
                        <th>Status</th>
                        <th>AI Recommendation</th>
                        <th>Daily Tip</th>
                        <th>AI Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody class="table-body" id="aiTableBody">
                <?php foreach ($processed as $i => $row):
                    $rec_id = $row['resident']['resident_id'];
                    $bmiVal = $row['bmi'] ? number_format($row['bmi'], 1) : '-';
                    $bmiColorClass = '';
                    if ($row['bmi']) {
                        if      ($row['bmi'] < 18.5) $bmiColorClass = 'ai-bmi-underweight';
                        elseif  ($row['bmi'] < 25.0) $bmiColorClass = 'ai-bmi-normal';
                        elseif  ($row['bmi'] < 30.0) $bmiColorClass = 'ai-bmi-overweight';
                        else                         $bmiColorClass = 'ai-bmi-obese';
                    }
                ?>
                    <tr
                        id="ai-row-<?= $rec_id ?>"
                        data-name="<?= strtolower(htmlspecialchars($row['resident']['first_name'].' '.$row['resident']['last_name'])) ?>"
                        data-gender="<?= htmlspecialchars(ucfirst($row['resident']['gender'] ?? '')) ?>"
                        data-status="<?= htmlspecialchars($row['status']) ?>"
                        data-pending="<?= $row['is_pending'] ? '1' : '0' ?>"
                    >
                        <td>
                            <div class="ai-name-cell">
                                <?= htmlspecialchars($row['resident']['first_name'].' '.$row['resident']['last_name']) ?>
                            </div>
                            <div class="ai-resident-id">
                                #RES-<?= str_pad($rec_id, 3, '0', STR_PAD_LEFT) ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($row['age']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($row['resident']['gender'] ?? '-')) ?></td>
                        <td>
                            <span class="<?= $bmiColorClass ?>"><?= $bmiVal ?></span>
                        </td>
                        <td>
                            <span class="ai-status-badge <?= $row['badgeClass'] ?>">
                                <span class="ai-badge-dot"></span>
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        </td>

                        <!-- Recommendation cell — shows placeholder while pending -->
                        <td id="ai-rec-cell-<?= $rec_id ?>">
                            <?php if ($row['is_pending']): ?>
                            <div class="ai-pending-placeholder">
                                <span class="ai-pending-dot"></span> Generating…
                            </div>
                            <?php else: ?>
                            <div class="ai-rec-text ai-rec-collapsed" id="rec-<?= $i ?>">
                                <?= htmlspecialchars($row['recommendation']) ?>
                            </div>
                            <?php if (mb_strlen($row['recommendation']) > 120): ?>
                            <button class="ai-read-more" onclick="aiToggleRec('rec-<?= $i ?>', this)">
                                Read more ▾
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <!-- Tip cell — shows placeholder while pending -->
                        <td id="ai-tip-cell-<?= $rec_id ?>">
                            <?php if ($row['is_pending']): ?>
                            <div class="ai-pending-placeholder" style="font-size:0.78rem;">
                                <span class="ai-pending-dot"></span> Generating…
                            </div>
                            <?php else: ?>
                            <div class="ai-tip-text"><?= htmlspecialchars($row['tip']) ?></div>
                            <?php endif; ?>
                        </td>

                        <td id="ai-status-cell-<?= $rec_id ?>">
                            <?php if ($row['is_pending']): ?>
                            <span style="color:#adb5bd;font-size:0.78rem;">Pending</span>
                            <?php elseif ($row['freshness_label']): ?>
                            <div class="ai-freshness">
                                <span class="<?= $row['freshness_class'] ?>">
                                    <i class="fas fa-circle" style="font-size:0.45rem;"></i>
                                    <?= htmlspecialchars($row['freshness_label']) ?>
                                </span>
                                <span class="ai-freshness-date">
                                    <?= $row['ai_generated_at'] ? date('M d, Y', strtotime($row['ai_generated_at'])) : '-' ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <span style="color:#adb5bd;font-size:0.78rem;">Not generated</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <button
                                class="ai-refresh-btn <?= $row['freshness_class'] === 'ai-very-old' ? 'outdated' : '' ?>"
                                title="<?= $row['freshness_class'] === 'ai-very-old' ? 'Outdated — refresh now!' : 'Refresh AI recommendation' ?>"
                                onclick="aiRefreshResident(<?= $rec_id ?>, this)"
                            >
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="aiEmptyState" style="display:none;" class="ai-empty">
            <div class="ai-empty-icon">🔍</div>
            <h3>No records found</h3>
            <p id="aiEmptyMsg">No residents match your current filters.</p>
        </div>

        <?php else: ?>
        <div class="ai-empty">
            <div class="ai-empty-icon">📋</div>
            <h3>No resident data found</h3>
            <p>Add residents and health records to get started.</p>
        </div>
        <?php endif; ?>

    </div>

</main>

<!-- ── REGENERATE ALL MODAL ── -->
<div class="ai-modal-overlay" id="aiRegenModal">
    <div class="ai-modal">
        <div class="ai-modal-icon">🔄</div>
        <div class="ai-modal-title">Regenerate All Recommendations?</div>
        <div class="ai-modal-desc">
            This will clear the cached AI recommendations for all
            <strong><?= $total_residents ?> residents</strong>
            and generate fresh personalized advice from Google Gemma.
            This will use approximately <?= $total_residents ?> API calls.
        </div>
        <div class="ai-modal-actions">
            <button class="ai-modal-cancel" onclick="document.getElementById('aiRegenModal').classList.remove('show')">
                Cancel
            </button>
            <button class="ai-modal-confirm" onclick="aiRegenerateAll()">
                <i class="fas fa-sync-alt"></i> Yes, Regenerate All
            </button>
        </div>
    </div>
</div>

<script>
// ── FILTER ──────────────────────────────────────────────────────────────────
function aiFilterTable() {
    const gender  = (document.getElementById('aiGenderFilter')?.value || '');
    const status  = (document.getElementById('aiStatusFilter')?.value || '');
    const query   = (document.getElementById('aiSearchInput')?.value  || '').toLowerCase().trim();
    const countEl = document.getElementById('aiRecordCount');
    const emptyEl = document.getElementById('aiEmptyState');
    const emptyMsg= document.getElementById('aiEmptyMsg');

    const rows = document.querySelectorAll('#aiTableBody tr');
    let visible = 0;

    rows.forEach(row => {
        const rowGender = (row.dataset.gender || '');
        const rowStatus = (row.dataset.status || '');
        const rowName   = (row.dataset.name   || '');

        const matchGender = !gender || rowGender === gender;
        const matchStatus = !status || rowStatus === status;
        const matchQuery  = !query  || rowName.includes(query);

        const show = matchGender && matchStatus && matchQuery;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    if (countEl) {
        const isFiltering = gender || status || query;
        countEl.textContent   = visible + ' resident' + (visible !== 1 ? 's' : '');
        countEl.style.display = isFiltering ? '' : 'none';
    }

    if (emptyEl) {
        if (visible === 0 && rows.length > 0) {
            if (emptyMsg) emptyMsg.textContent = 'No residents match your current filters.';
            emptyEl.style.display = '';
        } else {
            emptyEl.style.display = 'none';
        }
    }
}

// ── READ MORE TOGGLE ─────────────────────────────────────────────────────────
function aiToggleRec(id, btn) {
    const el       = document.getElementById(id);
    const expanded = el.classList.toggle('ai-rec-expanded');
    el.classList.toggle('ai-rec-collapsed', !expanded);
    btn.textContent = expanded ? 'Read less ▴' : 'Read more ▾';
}

// ── REFRESH SINGLE RESIDENT (clears cache then re-queues) ────────────────────
function aiRefreshResident(residentId, btn) {
    btn.classList.add('spinning');
    btn.disabled = true;

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=refresh_resident&resident_id=' + residentId
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Mark row as pending and re-generate without full reload
            const row = document.getElementById('ai-row-' + residentId);
            if (row) row.dataset.pending = '1';
            showPendingState(residentId);
            generateForResident(residentId);
        }
    })
    .catch(() => alert('Failed to refresh. Please try again.'))
    .finally(() => {
        btn.classList.remove('spinning');
        btn.disabled = false;
    });
}

// ── REGENERATE ALL ───────────────────────────────────────────────────────────
function aiRegenerateAll() {
    document.getElementById('aiRegenModal').classList.remove('show');

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=regenerate_all'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) window.location.reload();
    })
    .catch(() => alert('Failed to regenerate. Please try again.'));
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function showPendingState(residentId) {
    const recCell    = document.getElementById('ai-rec-cell-'    + residentId);
    const tipCell    = document.getElementById('ai-tip-cell-'    + residentId);
    const statusCell = document.getElementById('ai-status-cell-' + residentId);
    const placeholder = `<div class="ai-pending-placeholder"><span class="ai-pending-dot"></span> Generating…</div>`;
    if (recCell)    recCell.innerHTML    = placeholder;
    if (tipCell)    tipCell.innerHTML    = `<div class="ai-pending-placeholder" style="font-size:0.78rem;"><span class="ai-pending-dot"></span> Generating…</div>`;
    if (statusCell) statusCell.innerHTML = `<span style="color:#adb5bd;font-size:0.78rem;">Pending</span>`;
}

function applyGeneratedResult(residentId, data, rowIndex) {
    const recCell    = document.getElementById('ai-rec-cell-'    + residentId);
    const tipCell    = document.getElementById('ai-tip-cell-'    + residentId);
    const statusCell = document.getElementById('ai-status-cell-' + residentId);
    const row        = document.getElementById('ai-row-'         + residentId);

    if (recCell) {
        const needsToggle = data.recommendation.length > 120;
        recCell.innerHTML =
            `<div class="ai-rec-text ai-rec-collapsed" id="rec-lazy-${residentId}">${escHtml(data.recommendation)}</div>`
            + (needsToggle ? `<button class="ai-read-more" onclick="aiToggleRec('rec-lazy-${residentId}', this)">Read more ▾</button>` : '');
    }
    if (tipCell) {
        tipCell.innerHTML = `<div class="ai-tip-text">${escHtml(data.tip)}</div>`;
    }
    if (statusCell) {
        const today = new Date().toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        statusCell.innerHTML =
            `<div class="ai-freshness">
                <span class="ai-fresh"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Fresh</span>
                <span class="ai-freshness-date">${today}</span>
            </div>`;
    }
    if (row) row.dataset.pending = '0';
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── FIX 2: LAZY QUEUE — runs after page renders ──────────────────────────────
async function generateForResident(residentId) {
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=generate_resident_ai&resident_id=' + residentId
        });
        const data = await res.json();
        if (data.success) {
            applyGeneratedResult(residentId, data);
        } else {
            const statusCell = document.getElementById('ai-status-cell-' + residentId);
            if (statusCell) statusCell.innerHTML = `<span style="color:#adb5bd;font-size:0.78rem;">Failed</span>`;
        }
    } catch (e) {
        console.warn('AI generation failed for resident', residentId, e);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const pendingRows = [...document.querySelectorAll('#aiTableBody tr[data-pending="1"]')];
    if (!pendingRows.length) return;

    const queue = pendingRows.map(row => row.id.replace('ai-row-', ''));

    // Process one at a time to avoid hammering the API
    (async function processQueue() {
        for (const residentId of queue) {
            await generateForResident(residentId);
        }
    })();
});

// Close modal on backdrop click
document.getElementById('aiRegenModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>