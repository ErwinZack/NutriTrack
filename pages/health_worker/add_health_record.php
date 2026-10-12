<?php
session_start();

// --------------------------------------
// DATABASE & FUNCTIONS
// --------------------------------------
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit_log.php'; // [AUDIT] Added for audit logging

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}

// [AUDIT] Grab worker session data once at the top
$worker_id = (int) $_SESSION['worker_id'];
$worker_full_name = $_SESSION['full_name'] ?? 'Unknown';
$barangay_id = $_SESSION['barangay_id'];

// --------------------------------------
// FORM HANDLING
// --------------------------------------
$error = "";
$success = "";

// Fetch all residents for select dropdown (scoped to this worker's barangay)
$stmt = $pdo->prepare("SELECT resident_id, first_name, last_name FROM residents WHERE barangay_id = :brgy ORDER BY first_name ASC");
$stmt->execute([':brgy' => $barangay_id]);
$residents = $stmt->fetchAll();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $resident_id = (int) $_POST['resident_id'];
    $weight = (float) $_POST['weight'];
    $height = (float) $_POST['height'];
    $record_date = $_POST['record_date'];

    if (empty($resident_id) || empty($weight) || empty($height) || empty($record_date)) {
        $error = "Please fill in all required fields.";
    } else {
        // [SECURITY] Verify resident belongs to this worker's barangay before inserting
        $checkStmt = $pdo->prepare("SELECT resident_id FROM residents WHERE resident_id = :id AND barangay_id = :brgy");
        $checkStmt->execute([':id' => $resident_id, ':brgy' => $barangay_id]);

        if (!$checkStmt->fetch()) {
            $error = "Invalid resident selected.";
        } else {
            // Calculate BMI
            $height_m = $height / 100; // cm to meters
            $bmi = round($weight / ($height_m ** 2), 2);

            // Insert health record including worker_id and barangay_id
            $stmt = $pdo->prepare("
                INSERT INTO health_records (resident_id, worker_id, record_date, weight, height, bmi, barangay_id)
                VALUES (:resident_id, :worker_id, :record_date, :weight, :height, :bmi, :brgy)
            ");
            $stmt->execute([
                ':resident_id' => $resident_id,
                ':worker_id' => $worker_id,
                ':record_date' => $record_date,
                ':weight' => $weight,
                ':height' => $height,
                ':bmi' => $bmi,
                ':brgy' => $barangay_id
            ]);

                        // [AUDIT] Log add health record action
            logAudit(
                $pdo,
                'health_worker',
                $worker_id,
                'ADD_HEALTH_RECORD',
                "Worker '{$worker_full_name}' added health record for resident ID: {$resident_id} on {$record_date} (Weight: {$weight}kg, Height: {$height}cm, BMI: {$bmi}).",
                $barangay_id
            );

            // [NOTIFICATION] Notify municipal if resident is at-risk based on BMI
            $bmi_status = '';
            if ($bmi < 18.5)      $bmi_status = 'Underweight';
            elseif ($bmi < 25.0)  $bmi_status = 'Normal';
            elseif ($bmi < 30.0)  $bmi_status = 'Overweight';
            else                   $bmi_status = 'Obese';

            if (in_array($bmi_status, ['Underweight', 'Overweight', 'Obese'])) {
                // Get barangay name
                $brgyStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id");
                $brgyStmt->execute([':id' => $barangay_id]);
                $barangay_name = $brgyStmt->fetchColumn() ?: 'Unknown Barangay';

                // Get resident name
                $resStmt = $pdo->prepare("SELECT first_name, last_name FROM residents WHERE resident_id = :id");
                $resStmt->execute([':id' => $resident_id]);
                $resident = $resStmt->fetch();
                $resident_name = $resident ? $resident['first_name'] . ' ' . $resident['last_name'] : 'Unknown Resident';

                $superadmins = $pdo->query("SELECT superadmin_id FROM superadmins");
                foreach ($superadmins->fetchAll() as $sa) {
                    $saStmt = $pdo->prepare("
                        INSERT INTO notifications (recipient_id, recipient_role, sender_id, sender_role, type, message, barangay_id)
                        VALUES (:id, 'superadmin', :sid, 'health_worker', 'warning', :msg, :barangay_id)
                    ");
                    $saStmt->execute([
                        ':id'          => $sa['superadmin_id'],
                        ':sid'         => $worker_id,
                        ':msg'         => "At-risk resident detected in {$barangay_name}: {$resident_name} is {$bmi_status} (BMI: {$bmi}).",
                        ':barangay_id' => $barangay_id,
                    ]);
                }
            }

            $success = "Health record added successfully!";
        }
    }
}

// --------------------------------------
// HEADER & SIDEBAR
// --------------------------------------
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_health_worker.php';
?>

<main class="dashboard">
    <h1 class="page-title">Add Health Record</h1>

    <section class="section">

        <?php if ($error): ?>
            <p class="text-error"><?= htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="text-success"><?= htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form method="POST" class="form-default">

            <div class="form-group">
                <label>Resident <span class="text-error">*</span></label>
                <select name="resident_id" class="input-default" required>
                    <option value="">Select resident</option>
                    <?php foreach ($residents as $r): ?>
                        <option value="<?= $r['resident_id']; ?>">
                            <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Record Date <span class="text-error">*</span></label>
                <input type="date" name="record_date" class="input-default" required>
            </div>

            <div class="form-group">
                <label>Weight (kg) <span class="text-error">*</span></label>
                <input type="number" name="weight" class="input-default" step="0.01" min="0" required>
            </div>

            <div class="form-group">
                <label>Height (cm) <span class="text-error">*</span></label>
                <input type="number" name="height" class="input-default" step="0.01" min="0" required>
            </div>

            <button type="submit" class="btn-submit-health-record">Add Health Record</button>
        </form>

    </section>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>