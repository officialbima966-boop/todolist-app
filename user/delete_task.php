<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

$username = $_SESSION['user'];
$taskId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($taskId <= 0) {
    header("Location: tasks.php?error=invalid_id");
    exit;
}

// Ambil data tugas
$stmt = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->bind_param("i", $taskId);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();

if (!$task) {
    header("Location: tasks.php?error=task_not_found");
    exit;
}

// Cek apakah user adalah pembuat tugas atau ditugaskan ke tugas ini
if ($task['created_by'] !== $username && strpos($task['assigned_users'], $username) === false) {
    header("Location: tasks.php?error=unauthorized");
    exit;
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete associated files first
    if (!empty($task['attachments'])) {
        $uploadDir = "../uploads/tasks/";
        $attachments = json_decode($task['attachments'], true);
        
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (isset($attachment['path'])) {
                    $filePath = $uploadDir . basename($attachment['path']);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            }
        }
    }
    
    // Delete comments first (if any)
    $mysqli->query("DELETE FROM task_comments WHERE task_id = $taskId");

    // Delete task
    $sql = "DELETE FROM tasks WHERE id = $taskId";

    if ($mysqli->query($sql)) {
        // Redirect to tasks.php with success message
        header("Location: tasks.php?deleted=success");
        exit;
    } else {
        // Redirect to tasks.php with error message
        header("Location: tasks.php?deleted=error");
        exit;
    }
}

// Jika bukan POST request, redirect ke tasks.php
header("Location: tasks.php");
exit;
?>