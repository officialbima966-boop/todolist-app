<?php
require_once "inc/koneksi.php";

if (isset($mysqli)) {
    $conn = $mysqli;
} else {
    die("❌ Database connection not found!");
}

echo "=== CHECKING USERS TABLE ===\n";
$result = $conn->query('SELECT id, username, password, role, status FROM users LIMIT 10');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, Username: {$row['username']}, Role: {$row['role']}, Status: {$row['status']}\n";
        echo "Password hash: " . substr($row['password'], 0, 20) . "...\n\n";
    }
} else {
    echo "Error querying users table: " . $conn->error . "\n";
}

echo "=== CHECKING ADMIN TABLE ===\n";
$result = $conn->query('SELECT username, password FROM admin LIMIT 5');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "Username: {$row['username']}, Password: {$row['password']}\n";
    }
} else {
    echo "Error querying admin table: " . $conn->error . "\n";
}
?>
