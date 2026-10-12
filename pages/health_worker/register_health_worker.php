<?php
session_start();
require_once "../../config/config.php"; // Your PDO DB connection

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['full_name']);
    $email = strtolower(trim($_POST['email']));
    $password = trim($_POST['password']);

    if (empty($full_name) || empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT * FROM health_workers WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            $error = "Email already registered.";
        } else {
            // Create a dummy username (letters only, max 20 chars)
            $dummy_username = substr(preg_replace('/[^a-z0-9]/i', '', $full_name), 0, 20);

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert new health worker
            $stmt = $pdo->prepare("INSERT INTO health_workers (username, full_name, email, password, role) VALUES (:username, :full_name, :email, :password, :role)");
            $stmt->execute([
                ':username' => $dummy_username,
                ':full_name' => $full_name,
                ':email' => $email,
                ':password' => $hashed_password,
                ':role' => 'health_worker'
            ]);

            $success = "Account created! You can now <a href='login_health_worker.php'>log in</a>.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register Health Worker | NutriTrack</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>

<div class="register-container">
    <h2>Register Health Worker</h2>

    <?php if (!empty($error)): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <p class="success"><?= $success ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <label>Full Name</label>
        <input type="text" name="full_name" required>

        <label>Email</label>
        <input type="email" name="email" required>

        <label>Password</label>
        <input type="password" name="password" required>

        <button type="submit">Register</button>
    </form>
</div>

</body>
</html>
