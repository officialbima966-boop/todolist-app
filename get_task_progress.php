<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['user'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$taskId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($taskId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid task ID']);
    exit;
}

// Ambil data task
$taskQuery = $mysqli->query("SELECT subtasks FROM tasks WHERE id = $taskId");
$task = $taskQuery->fetch_assoc();

// Parse subtasks untuk menghitung progress
$totalAssignments = 0;
$completedAssignments = 0;

if (!empty($task['subtasks'])) {
    $subtasks = json_decode($task['subtasks'], true);
    if (is_array($subtasks)) {
        foreach ($subtasks as $subtask) {
            if (isset($subtask['assigned'])) {
                $totalAssignments += count($subtask['assigned']);
            }
            if (isset($subtask['completedBy'])) {
                $completedAssignments += count($subtask['completedBy']);
            }
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'totalAssignments' => $totalAssignments,
    'completedAssignments' => $completedAssignments,
    'progress' => $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100) : 0
]);