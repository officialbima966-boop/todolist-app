<?php
// Simulate the exact login process for user 'reva' with password 'ponorogo'
session_start();
require_once "inc/koneksi.php";

if (isset($mysqli)) {
    $conn = $mysqli;
} else {
    die("❌ Koneksi database tidak ditemukan!");
}

echo "=== DEBUGGING REVA LOGIN PROCESS ===\n\n";

// Simulate POST data
$_POST['username'] = 'reva';
$_POST['password'] = 'ponorogo';
$_POST['user_type'] = 'user'; // User Biasa
$_SERVER['REQUEST_METHOD'] = 'POST';

$error = "";

// Copy the exact login logic from login.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $user_type = $_POST['user_type'] ?? 'admin';

    echo "Input received:\n";
    echo "  Username: {$username}\n";
    echo "  Password: {$password}\n";
    echo "  User Type: {$user_type}\n\n";

    if ($user_type === 'admin') {
        echo "Processing as ADMIN login...\n";
        $query = $conn->prepare("SELECT * FROM admin WHERE username = ? AND password = ?");
        if (!$query) {
            die("❌ SQL Error: " . $conn->error);
        }

        $query->bind_param("ss", $username, $password);
        $query->execute();
        $result = $query->get_result();

        if ($result->num_rows === 1) {
            echo "✅ Admin login successful\n";
        } else {
            echo "❌ Admin login failed - wrong credentials\n";
        }
        $query->close();
    } else {
        echo "Processing as USER login...\n";
        $user_query = $conn->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
        if (!$user_query) {
            die("❌ SQL Error: " . $conn->error);
        }

        $user_query->bind_param("s", $username);
        $user_query->execute();
        $user_result = $user_query->get_result();

        echo "Query result count: " . $user_result->num_rows . "\n";

        if ($user_result->num_rows === 1) {
            $user_data = $user_result->fetch_assoc();

            echo "User data found:\n";
            echo "  ID: {$user_data['id']}\n";
            echo "  Username: {$user_data['username']}\n";
            echo "  Status: {$user_data['status']}\n";
            echo "  Role: {$user_data['role']}\n";
            echo "  Password in DB: {$user_data['password']}\n\n";

            if ($user_data['status'] !== 'active') {
                echo "❌ FAIL: User status is not active\n";
            } else {
                echo "Testing password verification:\n";
                echo "  Input password: {$password}\n";
                echo "  DB password: {$user_data['password']}\n";

                $password_valid = false;
                if (password_verify($password, $user_data['password'])) {
                    echo "  ✅ Password verified as HASHED\n";
                    $password_valid = true;
                } elseif ($password === $user_data['password']) {
                    echo "  ✅ Password verified as PLAIN TEXT\n";
                    $password_valid = true;
                } else {
                    echo "  ❌ Password verification FAILED\n";
                }

                if ($password_valid) {
                    echo "\n✅ LOGIN SUCCESSFUL - User should be redirected\n";
                    echo "Redirect destination: " . ($user_data['role'] === 'admin' ? '../admin/dashboard.php' : '../user/dashboard.php') . "\n";
                } else {
                    echo "\n❌ LOGIN FAILED - Password wrong\n";
                }
            }
        } else {
            echo "❌ User not found in database\n";
        }

        $user_query->close();
    }
}
?>
