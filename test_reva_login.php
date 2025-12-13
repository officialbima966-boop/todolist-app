<?php
require_once "inc/koneksi.php";

if (isset($mysqli)) {
    $conn = $mysqli;
} else {
    die("❌ Database connection not found!");
}

echo "=== TESTING REVA LOGIN ===\n\n";

// Test the specific credentials the user mentioned
$username = 'reva';
$password = 'ponorogo';

echo "Testing login for username: '{$username}' with password: '{$password}'\n";

// Check if user exists
$user_query = $conn->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
$user_query->bind_param("s", $username);
$user_query->execute();
$user_result = $user_query->get_result();

if ($user_result->num_rows === 1) {
    $user_data = $user_result->fetch_assoc();
    echo "  User found - ID: {$user_data['id']}, Status: {$user_data['status']}, Role: {$user_data['role']}\n";

    if ($user_data['status'] !== 'active') {
        echo "  ❌ FAIL: User status is not active\n";
    } else {
        // Test password verification (both hashed and plain text)
        $password_valid = false;
        if (password_verify($password, $user_data['password'])) {
            echo "  ✅ SUCCESS: Password verified as hashed password\n";
            $password_valid = true;
        } elseif ($password === $user_data['password']) {
            echo "  ✅ SUCCESS: Password verified as plain text\n";
            $password_valid = true;
        } else {
            echo "  ❌ FAIL: Password verification failed\n";
            echo "  Password entered: {$password}\n";
            echo "  Password in DB: {$user_data['password']}\n";
        }

        if ($password_valid) {
            echo "  ✅ LOGIN SHOULD WORK: User can login successfully\n";
        }
    }
} else {
    echo "  ❌ FAIL: User not found in database\n";
}

$user_query->close();
echo "\n";
?>
