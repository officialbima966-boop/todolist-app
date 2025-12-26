<?php
session_start();
require_once "../inc/koneksi.php";

// Cek session admin
if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$admin = $_SESSION['admin'];
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

// Handle POST request (since DELETE might not be supported by all servers)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete associated files first
    if (!empty($task['attachments'])) {
        $attachments = json_decode($task['attachments'], true);
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                $filePath = "../" . ($attachment['path'] ?? "uploads/tasks/" . ($attachment['file_name'] ?? $attachment['name'] ?? ''));
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
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

// Jika bukan POST request, redirect ke tasks.php
header("Location: tasks.php");
exit;
?>
