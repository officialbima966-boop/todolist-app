<?php
require_once "inc/koneksi.php";

if (isset($mysqli)) {
    $conn = $mysqli;
} else {
    die("❌ Database connection not found!");
}

echo "=== TESTING LOGIN FUNCTIONALITY ===\n\n";

// Test data from database
$test_users = [
    ['username' => 'boim', 'password' => 'password123', 'expected' => true],
    ['username' => 'reva', 'password' => 'password123', 'expected' => true],
    ['username' => 'bima', 'password' => 'password123', 'expected' => true],
    ['username' => 'aditya', 'password' => 'password123', 'expected' => true],
    ['username' => 'nonexistent', 'password' => 'password123', 'expected' => false],
];

foreach ($test_users as $test) {
    echo "Testing login for username: '{$test['username']}' with password: '{$test['password']}'\n";

    // Check if user exists
    $user_query = $conn->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
    $user_query->bind_param("s", $test['username']);
    $user_query->execute();
    $user_result = $user_query->get_result();

    if ($user_result->num_rows === 1) {
        $user_data = $user_result->fetch_assoc();
        echo "  User found - ID: {$user_data['id']}, Status: {$user_data['status']}, Role: {$user_data['role']}\n";

        if ($user_data['status'] !== 'active') {
            echo "  ❌ FAIL: User status is not active\n";
        } else {
            // Test password verification
            if (password_verify($test['password'], $user_data['password'])) {
                echo "  ✅ SUCCESS: Password verified correctly\n";
            } else {
                echo "  ❌ FAIL: Password verification failed\n";
                echo "  Expected hash for '{$test['password']}': " . password_hash($test['password'], PASSWORD_DEFAULT) . "\n";
                echo "  Actual hash in DB: {$user_data['password']}\n";
            }
        }
    } else {
        echo "  ❌ FAIL: User not found in database\n";
    }

    $user_query->close();
    echo "\n";
}

echo "=== SUGGESTED TEST USERS ===\n";
echo "If the above tests fail, try these users that exist in your database:\n";
echo "- Username: boim, Password: password123\n";
echo "- Username: reva, Password: password123\n";
echo "- Username: bima, Password: password123\n";
echo "- Username: aditya, Password: password123\n";
echo "\nNote: These are default passwords that may have been set during user creation.\n";
?>
