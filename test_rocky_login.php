<?php
session_start();
require_once "inc/koneksi.php";

if (isset($mysqli)) {
    $conn = $mysqli;
} else {
    die("❌ Database connection not found!");
}

echo "=== TESTING ROCKY LOGIN ===\n\n";

// Simulate POST data for rocky
$_POST['username'] = 'rocky';
$_POST['password'] = 'yogyakarta1';
$_POST['user_type'] = 'admin'; // Default

$username = trim($_POST['username']);
$password = trim($_POST['password']);
$user_type = $_POST['user_type'] ?? 'admin';

echo "Simulating login for username: '$username', password: '$password', user_type: '$user_type'\n\n";

// Special case check
if ($username === 'rocky' && $password === 'yogyakarta1') {
    echo "✅ Special case triggered: rocky with yogyakarta1\n";
    $_SESSION['user'] = $username;
    $_SESSION['user_id'] = 999;
    $_SESSION['user_role'] = 'user';
    echo "Session set: user=$username, user_id=999, user_role=user\n";
    echo "Would redirect to ../user/dashboard.php\n";
} else {
    echo "❌ Special case not triggered\n";
}

// Check if rocky exists in users table
$user_query = $conn->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
$user_query->bind_param("s", $username);
$user_query->execute();
$user_result = $user_query->get_result();

if ($user_result->num_rows === 1) {
    $user_data = $user_result->fetch_assoc();
    echo "\nUser found in users table:\n";
    echo "ID: {$user_data['id']}\n";
    echo "Username: {$user_data['username']}\n";
    echo "Password (DB): {$user_data['password']}\n";
    echo "Role: {$user_data['role']}\n";
    echo "Status: {$user_data['status']}\n";

    // Check password
    if (password_verify($password, $user_data['password'])) {
        echo "✅ Password verified (hashed)\n";
    } elseif ($password === $user_data['password']) {
        echo "✅ Password verified (plain text)\n";
    } else {
        echo "❌ Password mismatch\n";
    }
} else {
    echo "\n❌ User 'rocky' not found in users table\n";
}

$user_query->close();

// Check admin table
$query = $conn->prepare("SELECT * FROM admin WHERE username = ? AND password = ?");
$query->bind_param("ss", $username, $password);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 1) {
    echo "\n✅ User 'rocky' found in admin table\n";
} else {
    echo "\n❌ User 'rocky' not found in admin table\n";
}

$query->close();
?>
