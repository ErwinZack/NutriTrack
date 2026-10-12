<?php
session_start();

// --------------------------------------
// DATABASE & FUNCTIONS
// --------------------------------------
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// --------------------------------------
// AUTHENTICATION
// --------------------------------------
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}

$barangay_id = $_SESSION['barangay_id'];

// --------------------------------------
// GET RECORD
// --------------------------------------
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: health_records.php");
    exit();
}

$record_id = (int) $_GET['id'];

// Fetch record
$stmt = $pdo->prepare("
    SELECT hr.*, r.first_name, r.last_name
    FROM health_records hr
    INNER JOIN residents r ON hr.resident_id = r.resident_id
    WHERE hr.record_id = :id AND hr.barangay_id = :brgy
    LIMIT 1
");
$stmt->execute([':id' => $record_id, ':brgy' => $barangay_id]);
$record = $stmt->fetch();

if (!$record) {
    header("Location: health_records.php");
    exit();
}

// Fetch all residents for dropdown
$stmt = $pdo->prepare("SELECT resident_id, first_name, last_name FROM residents WHERE barangay_id = :brgy ORDER BY first_name ASC");
$stmt->execute([':brgy' => $barangay_id]);
$residents = $stmt->fetchAll();

// --------------------------------------
// FORM HANDLING
// --------------------------------------
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $resident_id = (int) $_POST['resident_id'];
    $weight      = (float) $_POST['weight'];
    $height      = (float) $_POST['height'];
    $record_date = $_POST['record_date'];

    if (empty($resident_id) || empty($weight) || empty($height) || empty($record_date)) {
        $error = "Please fill in all required fields.";
    } else {
        // Current health worker ID
        $worker_id = $_SESSION['worker_id'];

        // [SECURITY] Verify the selected resident belongs to this worker's barangay
        $checkStmt = $pdo->prepare("SELECT resident_id FROM residents WHERE resident_id = :id AND barangay_id = :brgy");
        $checkStmt->execute([':id' => $resident_id, ':brgy' => $barangay_id]);

        if (!$checkStmt->fetch()) {
            $error = "Invalid resident selected.";
        } else {
            $height_m = $height / 100;
            $bmi = round($weight / ($height_m ** 2), 2);

            // Update health record including worker_id, scoped to this barangay
            $stmt = $pdo->prepare("
                UPDATE health_records
                SET resident_id = :resident_id,
                    worker_id = :worker_id,
                    record_date = :record_date,
                    weight = :weight,
                    height = :height,
                    bmi = :bmi
                WHERE record_id = :id AND barangay_id = :brgy
            ");
            $stmt->execute([
                ':resident_id' => $resident_id,
                ':worker_id'   => $worker_id, // <--- added to satisfy foreign key
                ':record_date' => $record_date,
                ':weight'      => $weight,
                ':height'      => $height,
                ':bmi'         => $bmi,
                ':id'          => $record_id,
                ':brgy'        => $barangay_id
            ]);

            $success = "Health record updated successfully!";

            // Refresh record
            $stmt = $pdo->prepare("SELECT * FROM health_records WHERE record_id = :id AND barangay_id = :brgy");
            $stmt->execute([':id' => $record_id, ':brgy' => $barangay_id]);
            $record = $stmt->fetch();
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
    <h1 class="page-title">Edit Health Record</h1>

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
                    <?php foreach ($residents as $r): ?>
                        <option value="<?= $r['resident_id']; ?>" <?= $r['resident_id'] == $record['resident_id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Record Date <span class="text-error">*</span></label>
                <input type="date" name="record_date" class="input-default" value="<?= $record['record_date']; ?>" required>
            </div>

            <div class="form-group">
                <label>Weight (kg) <span class="text-error">*</span></label>
                <input type="number" name="weight" class="input-default" step="0.01" min="0" value="<?= $record['weight']; ?>" required>
            </div>

            <div class="form-group">
                <label>Height (cm) <span class="text-error">*</span></label>
                <input type="number" name="height" class="input-default" step="0.01" min="0" value="<?= $record['height']; ?>" required>
            </div>

            <button type="submit" class="btn-edit-health-record">Update Health Record</button>
        </form>

    </section>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>