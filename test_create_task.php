<?php
require_once 'inc/koneksi.php';

echo "=== Creating Test Task ===\n\n";

$title = "Test Task - Harus Muncul di User Dashboard";
$startDate = date('Y-m-d');
$endDate = date('Y-m-d', strtotime('+7 days'));
$note = "Ini adalah task test yang seharusnya muncul di user dashboard";
$assignedUsers = "aditya,boim";
$createdBy = "bimaaa";
$status = 'progress';
$progress = 0;

$sql = "INSERT INTO tasks (title, start_date, end_date, note, assigned_users, created_by, status, progress) 
        VALUES ('$title', '$startDate', '$endDate', '$note', '$assignedUsers', '$createdBy', '$status', $progress)";

if ($mysqli->query($sql)) {
    $taskId = $mysqli->insert_id;
    echo "✓ Task created successfully!\n";
    echo "  Task ID: $taskId\n";
    echo "  Title: $title\n";
    echo "  Assigned to: $assignedUsers\n";
    echo "\n✓ Test user biasa (aditya/boim) di: http://localhost/coba/user/user_dashboard.php\n";
} else {
    echo "✗ Insert failed: " . $mysqli->error . "\n";
}

// Test query
$testUser = "aditya";
$pattern = "%".$testUser."%";

$testQuery = $mysqli->prepare("
    SELECT DISTINCT t.* FROM tasks t 
    LEFT JOIN task_subtasks ts ON t.id = ts.task_id 
    WHERE t.assigned_users LIKE ? OR ts.assigned_to = ?
");
$testQuery->bind_param("ss", $pattern, $testUser);
$testQuery->execute();
$testResult = $testQuery->get_result();

echo "\n✓ Found " . $testResult->num_rows . " tasks for user '$testUser'\n";
?>
