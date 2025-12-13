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
    echo json_encode(['success' => false, 'message' => 'ID tugas tidak valid']);
    exit;
}

// Ambil data tugas
$stmt = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->bind_param("i", $taskId);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();

if (!$task) {
    echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan']);
    exit;
}

// Cek apakah user adalah pembuat tugas atau ditugaskan ke tugas ini
if ($task['created_by'] !== $username && strpos($task['assigned_users'], $username) === false) {
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk menghapus tugas ini']);
    exit;
}

// Handle POST request (since DELETE might not be supported by all servers)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete associated files first
    if (!empty($task['attachments'])) {
        $uploadDir = "../uploads/tasks/";
        $attachments = explode(',', $task['attachments']);
        foreach ($attachments as $filename) {
            $filePath = $uploadDir . $filename;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    // Delete task
    $sql = "DELETE FROM tasks WHERE id = $taskId";

    if ($mysqli->query($sql)) {
        echo json_encode(['success' => true, 'message' => 'Tugas berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus tugas: ' . $mysqli->error]);
    }
    exit;
}

// Jika bukan DELETE request, redirect ke tasks.php
header("Location: tasks.php");
exit;
?>
