<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'inc/koneksi.php';

// Test user
$username = 'aditya';
$searchPattern = "%".$username."%";

// Test completed query
echo "=== Testing FIXED Completed Tasks Query ===\n";
$completeQuery = $mysqli->prepare("
    SELECT COUNT(DISTINCT t.id) as total 
    FROM tasks t 
    LEFT JOIN task_subtasks ts ON t.id = ts.task_id 
    WHERE (t.assigned_users LIKE ? OR ts.assigned_to = ?) 
    AND (t.status = 'completed' OR t.progress = 100)
");
$completeQuery->bind_param("ss", $searchPattern, $username);
$completeQuery->execute();
$result = $completeQuery->get_result();
$complete = $result->fetch_assoc();
echo "Completed tasks: " . $complete['total'] . "\n";

// Test total query
echo "\n=== Testing All Tasks Query ===\n";
$totalQuery = $mysqli->prepare("
    SELECT COUNT(DISTINCT t.id) as total 
    FROM tasks t 
    LEFT JOIN task_subtasks ts ON t.id = ts.task_id 
    WHERE t.assigned_users LIKE ? OR ts.assigned_to = ?
");
$totalQuery->bind_param("ss", $searchPattern, $username);
$totalQuery->execute();
$result = $totalQuery->get_result();
$total = $result->fetch_assoc();
echo "Total assigned tasks: " . $total['total'] . "\n";

// Test task list query
echo "\n=== Testing Tasks List Query ===\n";
$tasksQuery = $mysqli->prepare("
    SELECT DISTINCT t.*, 
           DATEDIFF(t.end_date, NOW()) as days_left 
    FROM tasks t 
    LEFT JOIN task_subtasks ts ON t.id = ts.task_id 
    WHERE t.assigned_users LIKE ? OR ts.assigned_to = ?
    ORDER BY t.end_date ASC
");
$tasksQuery->bind_param("ss", $searchPattern, $username);
$tasksQuery->execute();
$result = $tasksQuery->get_result();
$tasks = $result->fetch_all(MYSQLI_ASSOC);
echo "Found " . count($tasks) . " tasks\n";

foreach ($tasks as $task) {
    echo "- ID: {$task['id']}, Title: {$task['title']}, Status: {$task['status']}, Progress: {$task['progress']}, End Date: {$task['end_date']}, Days Left: {$task['days_left']}\n";
}

echo "\n=== Field Check ===\n";
if (!empty($tasks)) {
    echo "Sample task fields:\n";
    print_r(array_keys($tasks[0]));
}
?>
