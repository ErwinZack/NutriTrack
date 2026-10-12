<?php
session_start();
require_once "../../config/config.php"; // DB connection

$error = "";
$success = "";

// Process form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['full_name']);
    $username  = trim($_POST['username']);
    $email     = trim($_POST['email']);
    $password  = trim($_POST['password']);

    // Basic validation
    if (empty($full_name) || empty($username) || empty($password)) {
        $error = "Please fill in all required fields.";
    } else {
        // Check if username already exists
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);

        if ($stmt->fetch()) {
            $error = "Username already taken.";
        } else {
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert into database
            $stmt = $pdo->prepare("INSERT INTO admins (full_name, username, password, email, role) 
                                   VALUES (:full_name, :username, :password, :email, 'admin')");
            $stmt->execute([
                'full_name' => $full_name,
                'username'  => $username,
                'password'  => $hashed_password,
                'email'     => $email
            ]);

            $success = "Admin account created successfully!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register Admin Staff | NutriTrack</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <div class="form-container">
        <h2>Register Admin Staff</h2>

        <?php if (!empty($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <label>Full Name</label>
            <input type="text" name="full_name" required>

            <label>Username</label>
            <input type="text" name="username" required>

            <label>Email (optional)</label>
            <input type="email" name="email">

            <label>Password</label>z
            <input type="password" name="password" required>

            <button type="submit">Register</button>
        </form>
    </div>
</body>
</html>
