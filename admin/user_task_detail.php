<?php
session_start();
require_once "../inc/koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

$username = $_SESSION['user'];
$taskId = $_GET['id'];

// Get task data
$taskQuery = $mysqli->query("SELECT * FROM tasks WHERE id = $taskId");
$task = $taskQuery->fetch_assoc();

// Check if user has access to this task
$hasAccess = false;
if (strpos($task['assigned_users'], $username) !== false) {
    $hasAccess = true;
} else {
    // Check subtasks
    $subtaskQuery = $mysqli->query("SELECT id FROM task_subtasks WHERE task_id = $taskId AND assigned_to = '$username'");
    if ($subtaskQuery->num_rows > 0) {
        $hasAccess = true;
    }
}

if (!$hasAccess) {
    die("Anda tidak memiliki akses ke task ini!");
}

// Handle actions (sama seperti tasks.php tapi hanya untuk subtask yang assigned ke user ini)
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Task - BM Garage</title>
    <!-- Style sama seperti tasks.php -->
</head>
<body>
    <!-- Implementasi detail task untuk user -->
    <!-- Hanya tampilkan subtask yang assigned ke user ini -->
    <!-- User hanya bisa toggle subtask yang assigned ke mereka -->
</body>
</html>