<?php
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
$worker_id        = (int) $_SESSION['worker_id'];
$worker_full_name = $_SESSION['full_name'] ?? 'Unknown';
$barangay_id      = $_SESSION['barangay_id'];

// --------------------------------------
// FORM HANDLING
// --------------------------------------
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // [SECURITY] Verify CSRF token
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        // [SECURITY] Sanitize all inputs
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name  = sanitizeInput($_POST['last_name']);
        $birth_date = sanitizeInput($_POST['birth_date']);
        $gender     = sanitizeInput($_POST['gender']);
        $address    = sanitizeInput($_POST['address']);
        $phone      = sanitizeInput($_POST['phone']);

        // [SECURITY] Server-side validation
        if (!validateRequired($first_name) || !validateRequired($last_name) || !validateRequired($birth_date) || !validateRequired($gender)) {
            $error = "Please fill in all required fields.";
        } elseif (!validateDate($birth_date)) {
            $error = "Invalid birth date format.";
        } elseif (!in_array($gender, ['Male', 'Female'])) {
            $error = "Invalid gender value.";
        } else {
            // Insert resident
                      $stmt = $pdo->prepare("
                INSERT INTO residents (first_name, last_name, birth_date, gender, address, phone, barangay_id) 
                VALUES (:first_name, :last_name, :birth_date, :gender, :address, :phone, :barangay_id)
            ");
            $stmt->execute([
                ':first_name'  => $first_name,
                ':last_name'   => $last_name,
                ':birth_date'  => $birth_date,
                ':gender'      => $gender,
                ':address'     => $address,
                ':phone'       => $phone,
                ':barangay_id' => $barangay_id
            ]);

            // [AUDIT] Log add resident action
            logAudit(
                $pdo,
                'health_worker',
                $worker_id,
                'ADD_RESIDENT',
                "Worker '{$worker_full_name}' added resident: {$first_name} {$last_name} (DOB: {$birth_date}, Gender: {$gender})."
            );

            $success = "Resident added successfully!";
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
    <h1 class="page-title">Add Resident</h1>

    <section class="section">

        <?php if ($error): ?>
            <p class="text-error"><?= htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="text-success"><?= htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form method="POST" action="" class="form-default">

            <?= csrfInput() ?>

            <div class="form-group">
                <label>First Name <span class="text-error">*</span></label>
                <input type="text" name="first_name" class="input-default" required>
            </div>

            <div class="form-group">
                <label>Last Name <span class="text-error">*</span></label>
                <input type="text" name="last_name" class="input-default" required>
            </div>

            <div class="form-group">
                <label>Birth Date <span class="text-error">*</span></label>
                <input type="date" name="birth_date" class="input-default" required>
            </div>

            <div class="form-group">
                <label>Gender <span class="text-error">*</span></label>
                <select name="gender" class="input-default" required>
                    <option value="">Select gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>

            <div class="form-group">
                <label>Address</label>
                <input type="text" name="address" class="input-default">
            </div>

            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" class="input-default">
            </div>

            <button type="submit" class="btn-submit-resident">Add Resident</button>
        </form>

    </section>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>