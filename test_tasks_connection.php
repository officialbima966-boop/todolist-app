<?php
require_once 'inc/koneksi.php';

echo "=== Testing Task Query ===\n\n";

// Check if tasks table exists
$checkTable = $mysqli->query("SHOW TABLES LIKE 'tasks'");
if ($checkTable->num_rows > 0) {
    echo "✓ Tasks table exists\n\n";
    
    // Check tasks
    $result = $mysqli->query("SELECT * FROM tasks LIMIT 1");
    if ($result && $result->num_rows > 0) {
        echo "Found tasks in database:\n";
        $row = $result->fetch_assoc();
        echo "  ID: " . $row['id'] . "\n";
        echo "  Title: " . $row['title'] . "\n";
        echo "  Assigned Users: " . $row['assigned_users'] . "\n";
        echo "  End Date: " . $row['end_date'] . "\n";
        echo "  Status: " . $row['status'] . "\n";
        echo "  Progress: " . $row['progress'] . "%\n";
    } else {
        echo "No tasks found in database.\n";
        echo "Admin needs to create tasks from admin/tasks.php\n";
    }
} else {
    echo "✗ Tasks table does not exist\n";
}

// Check users
echo "\n=== Users in system ===\n";
$usersResult = $mysqli->query("SELECT username, role FROM users LIMIT 10");
if ($usersResult) {
    while ($user = $usersResult->fetch_assoc()) {
        echo "  - " . $user['username'] . " (" . $user['role'] . ")\n";
    }
}
?>
