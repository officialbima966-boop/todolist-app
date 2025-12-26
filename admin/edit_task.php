<?php
session_start();
require_once "../inc/koneksi.php";

// Cek session admin
if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$admin = $_SESSION['admin'];

// Ambil data admin
$userQuery = $mysqli->prepare("SELECT * FROM admin WHERE username = ?");
$userQuery->bind_param("s", $admin);
$userQuery->execute();
$result = $userQuery->get_result();

if ($result->num_rows == 0) {
    session_destroy();
    header("Location: ../auth/login.php?error=admin_not_found");
    exit;
}

$userData = $result->fetch_assoc();

// Ambil ID task dari URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: tasks.php");
    exit;
}

$taskId = intval($_GET['id']);

// Ambil data task
$taskQuery = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
$taskQuery->bind_param("i", $taskId);
$taskQuery->execute();
$taskResult = $taskQuery->get_result();

if ($taskResult->num_rows == 0) {
    header("Location: tasks.php?error=task_not_found");
    exit;
}

$taskData = $taskResult->fetch_assoc();

// Admin can edit any task, no authorization check needed
$assignedUsersArray = $taskData['assigned_users'] ? explode(',', $taskData['assigned_users']) : [];

// Ambil semua user untuk assign (termasuk admin)
$usersQuery = $mysqli->query("SELECT * FROM users");

// Tambahkan admin ke daftar users
$adminUser = [
    'id' => 'admin_' . $userData['id'],
    'username' => $userData['username'],
    'role' => 'admin'
];
$users = [];
$users[] = $adminUser;
if ($usersQuery) {
    while ($userRow = $usersQuery->fetch_assoc()) {
        $users[] = $userRow;
    }
}

// Ambil lampiran yang sudah ada
$attachments = [];
if (!empty($taskData['attachments'])) {
    $attachments = json_decode($taskData['attachments'], true);
    if ($attachments === null || !is_array($attachments)) {
        $attachments = [];
    }
}

// Handle AJAX requests for immediate attachment operations and comments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clear any previous output
    if (ob_get_length()) ob_clean();
    
    header('Content-Type: application/json');
    
    // Ensure no other output
    ob_start();
    
    if ($_POST['action'] === 'add_comment') {
        $taskId = intval($_POST['task_id']);
        $comment = trim($_POST['comment']);

        if (empty($comment)) {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            ob_end_flush();
            exit;
        }

        // Verify task ownership
        $taskQuery = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
        if (!$taskQuery) {
            echo json_encode(['success' => false, 'error' => 'Database error']);
            ob_end_flush();
            exit;
        }
        $taskQuery->bind_param("i", $taskId);
        if (!$taskQuery->execute()) {
            echo json_encode(['success' => false, 'error' => 'Database error']);
            ob_end_flush();
            exit;
        }
        $taskResult = $taskQuery->get_result();

        if ($taskResult->num_rows == 0) {
            echo json_encode(['success' => false, 'error' => 'Task not found']);
            ob_end_flush();
            exit;
        }

        // Insert comment
        $stmt = $mysqli->prepare("INSERT INTO task_comments (task_id, user_id, username, comment) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Database error']);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("iiss", $taskId, $userData['id'], $userData['username'], $comment);

        if ($stmt->execute()) {
            // Update comment count
            $updateCount = $mysqli->query("UPDATE tasks SET comments = comments + 1 WHERE id = $taskId");
            if (!$updateCount) {
                echo json_encode(['success' => false, 'error' => 'Failed to update comment count']);
                ob_end_flush();
                exit;
            }

            // Get the inserted comment
            $newCommentQuery = $mysqli->query("SELECT * FROM task_comments WHERE id = LAST_INSERT_ID()");
            if (!$newCommentQuery) {
                echo json_encode(['success' => false, 'error' => 'Failed to retrieve comment']);
                ob_end_flush();
                exit;
            }
            $newComment = $newCommentQuery->fetch_assoc();

            echo json_encode([
                'success' => true,
                'comment' => $newComment
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to add comment']);
        }
        ob_end_flush();
        exit;
    }

    if ($_POST['action'] === 'get_comments') {
        $taskId = intval($_POST['task_id']);

        // Verify task ownership
        $taskQuery = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
        $taskQuery->bind_param("i", $taskId);
        $taskQuery->execute();
        $taskResult = $taskQuery->get_result();

        if ($taskResult->num_rows == 0) {
            echo json_encode(['success' => false, 'error' => 'Task not found']);
            ob_end_flush();
            exit;
        }

        $sql = "SELECT * FROM task_comments WHERE task_id = $taskId ORDER BY created_at DESC";
        $result = $mysqli->query($sql);

        $comments = [];
        while ($row = $result->fetch_assoc()) {
            $comments[] = $row;
        }

        echo json_encode(['success' => true, 'comments' => $comments]);
        ob_end_flush();
        exit;
    }

    if ($_POST['action'] === 'delete_comment') {
        $commentId = intval($_POST['comment_id']);

        // Get comment info first
        $commentQuery = $mysqli->prepare("SELECT task_id FROM task_comments WHERE id = ?");
        $commentQuery->bind_param("i", $commentId);
        $commentQuery->execute();
        $commentResult = $commentQuery->get_result();

        if ($commentResult->num_rows == 0) {
            echo json_encode(['success' => false, 'error' => 'Comment not found']);
            ob_end_flush();
            exit;
        }

        $commentData = $commentResult->fetch_assoc();
        $taskId = $commentData['task_id'];

        // Verify task ownership
        $taskQuery = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
        $taskQuery->bind_param("i", $taskId);
        $taskQuery->execute();
        $taskResult = $taskQuery->get_result();

        if ($taskResult->num_rows == 0) {
            echo json_encode(['success' => false, 'error' => 'Task not found']);
            ob_end_flush();
            exit;
        }

        // Delete comment
        $deleteQuery = $mysqli->prepare("DELETE FROM task_comments WHERE id = ?");
        $deleteQuery->bind_param("i", $commentId);

        if ($deleteQuery->execute()) {
            // Update comment count
            $mysqli->query("UPDATE tasks SET comments = GREATEST(comments - 1, 0) WHERE id = $taskId");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete comment']);
        }
        ob_end_flush();
        exit;
    }

    if ($_POST['action'] === 'delete_attachment') {
        $taskId = intval($_POST['task_id']);
        $fileName = trim($_POST['file_name']);

        // Verify task ownership
        $taskQuery = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
        $taskQuery->bind_param("i", $taskId);
        $taskQuery->execute();
        $taskResult = $taskQuery->get_result();

        if ($taskResult->num_rows == 0) {
            echo json_encode(['success' => false, 'error' => 'Task not found']);
            ob_end_flush();
            exit;
        }

        $taskData = $taskResult->fetch_assoc();

        // Admin can edit any task, no authorization check needed

        // Get current attachments from database
        $currentAttachmentsQuery = $mysqli->prepare("SELECT attachments FROM tasks WHERE id = ?");
        $currentAttachmentsQuery->bind_param("i", $taskId);
        $currentAttachmentsQuery->execute();
        $currentAttachmentsResult = $currentAttachmentsQuery->get_result();
        $currentAttachmentsData = $currentAttachmentsResult->fetch_assoc();
        $attachments = [];
        if (!empty($currentAttachmentsData['attachments'])) {
            $attachments = json_decode($currentAttachmentsData['attachments'], true);
            if ($attachments === null || !is_array($attachments)) {
                $attachments = [];
            }
        }

        // Find and remove the attachment
        $found = false;
        foreach ($attachments as $key => $attachment) {
            $attachmentFileName = $attachment['file_name'] ?? $attachment['name'] ?? '';
            if ($attachmentFileName === $fileName) {
                // Delete physical file
                $filePath = "../" . ($attachment['path'] ?? "uploads/tasks/" . $fileName);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                unset($attachments[$key]);
                $found = true;
                break;
            }
        }

        if ($found) {
            // Re-index array
            $attachments = array_values($attachments);

            // Update database
            $attachmentsJson = json_encode($attachments);
            $updateQuery = $mysqli->prepare("UPDATE tasks SET attachments = ? WHERE id = ?");
            $updateQuery->bind_param("si", $attachmentsJson, $taskId);

            if ($updateQuery->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update database']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Attachment not found']);
        }
        ob_end_flush();
        exit;
    }

    if ($_POST['action'] === 'upload_attachments') {
        $taskId = intval($_POST['task_id']);

        // Verify task ownership
        $taskQuery = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
        $taskQuery->bind_param("i", $taskId);
        $taskQuery->execute();
        $taskResult = $taskQuery->get_result();

        if ($taskResult->num_rows == 0) {
            echo json_encode(['success' => false, 'error' => 'Task not found']);
            ob_end_flush();
            exit;
        }

        $taskData = $taskResult->fetch_assoc();

        // Admin can edit any task, no authorization check needed

        // Get current attachments
        $attachments = [];
        if (!empty($taskData['attachments'])) {
            $attachments = json_decode($taskData['attachments'], true);
            if ($attachments === null || !is_array($attachments)) {
                $attachments = [];
            }
        }

        // Process uploads
        $uploadDir = "../uploads/tasks/";
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                echo json_encode(['success' => false, 'error' => 'Failed to create upload directory']);
                ob_end_flush();
                exit;
            }
        }

        if (!is_writable($uploadDir)) {
            echo json_encode(['success' => false, 'error' => 'Upload directory not writable']);
            ob_end_flush();
            exit;
        }

        $uploadedFiles = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $tmpName = $_FILES['attachments']['tmp_name'][$key];
                    $fileSize = $_FILES['attachments']['size'][$key];
                    $fileType = $_FILES['attachments']['type'][$key];

                    if ($fileSize > 5 * 1024 * 1024) {
                        continue;
                    }

                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp',
                                   'application/pdf', 'application/msword',
                                   'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                   'application/vnd.ms-excel',
                                   'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                   'text/plain'];

                    if (!in_array($fileType, $allowedTypes)) {
                        continue;
                    }

                    $fileExt = pathinfo($name, PATHINFO_EXTENSION);
                    $fileName = uniqid() . '_' . time() . '.' . $fileExt;
                    $uploadPath = $uploadDir . $fileName;

                    if (move_uploaded_file($tmpName, $uploadPath)) {
                        $newAttachment = [
                            'name' => $name,
                            'file_name' => $fileName,
                            'path' => 'uploads/tasks/' . $fileName,
                            'type' => $fileType,
                            'size' => $fileSize,
                            'uploaded_by' => $userData['username'],
                            'uploaded_at' => date('Y-m-d H:i:s')
                        ];
                        $attachments[] = $newAttachment;
                        $uploadedFiles[] = $newAttachment;
                    }
                }
            }
        }

        // Update database
        $attachmentsJson = json_encode($attachments);
        $updateQuery = $mysqli->prepare("UPDATE tasks SET attachments = ? WHERE id = ?");
        $updateQuery->bind_param("si", $attachmentsJson, $taskId);

        if ($updateQuery->execute()) {
            echo json_encode(['success' => true, 'attachments' => $uploadedFiles]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update database']);
        }
        ob_end_flush();
        exit;
    }
    
    // If we reach here, it's an invalid action
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    ob_end_flush();
    exit;
}

// Proses update jika form disubmit (non-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // Jika ada parameter ajax, ini adalah AJAX request untuk save
    if (isset($_POST['ajax'])) {
        // Clear any previous output
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        ob_start();
    }
    
    $title = $_POST['title'];
    $description = $_POST['description'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $status = $_POST['status'];
    $progress = $_POST['progress'];

    // Validasi tanggal: end_date tidak boleh sebelum start_date
    if (strtotime($end_date) < strtotime($start_date)) {
        $error = "Tanggal selesai tidak boleh sebelum tanggal mulai";
    }

    // Ambil assigned users
    $assignedUsersStr = $_POST['assigned_users'] ?? '';
    $assignedUsers = $assignedUsersStr ? explode(',', $assignedUsersStr) : [];

    // Ambil subtasks dari POST
    $subtasks = [];
    if (isset($_POST['subtasks']) && !empty($_POST['subtasks'])) {
        $subtasks = json_decode($_POST['subtasks'], true);
    }

    // Reload current attachments from DB to include any AJAX uploads
    $currentAttachmentsQuery = $mysqli->prepare("SELECT attachments FROM tasks WHERE id = ?");
    $currentAttachmentsQuery->bind_param("i", $taskId);
    $currentAttachmentsQuery->execute();
    $currentAttachmentsResult = $currentAttachmentsQuery->get_result();
    $currentAttachmentsData = $currentAttachmentsResult->fetch_assoc();
    $attachments = [];
    if (!empty($currentAttachmentsData['attachments'])) {
        $attachments = json_decode($currentAttachmentsData['attachments'], true);
        if ($attachments === null || !is_array($attachments)) {
            $attachments = [];
        }
    }

    // Proses upload file lampiran (untuk non-AJAX)
    $uploadedAttachments = $attachments;

    // Jika ada file yang diupload via form biasa
    if (!empty($_FILES['attachments']['name'][0])) {
        $uploadDir = "../uploads/tasks/";

        // Buat folder jika belum ada
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                $error = "Gagal membuat folder uploads. Periksa izin direktori.";
            }
        }

        // Periksa apakah folder dapat ditulis
        if (!isset($error) && !is_writable($uploadDir)) {
            $error = "Folder uploads tidak dapat ditulis. Periksa izin direktori.";
        }

        if (!isset($error)) {
            // Loop melalui semua file yang diupload
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $tmpName = $_FILES['attachments']['tmp_name'][$key];
                    $fileSize = $_FILES['attachments']['size'][$key];
                    $fileType = $_FILES['attachments']['type'][$key];

                    // Validasi ukuran file (maksimal 5MB)
                    if ($fileSize > 5 * 1024 * 1024) {
                        $error = "File $name terlalu besar. Maksimal 5MB";
                        continue;
                    }

                    // Validasi tipe file
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp',
                                   'application/pdf', 'application/msword',
                                   'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                   'application/vnd.ms-excel',
                                   'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                   'text/plain'];

                    if (!in_array($fileType, $allowedTypes)) {
                        $error = "Tipe file $name tidak diizinkan";
                        continue;
                    }

                    // Generate nama file unik
                    $fileExt = pathinfo($name, PATHINFO_EXTENSION);
                    $fileName = uniqid() . '_' . time() . '.' . $fileExt;
                    $uploadPath = $uploadDir . $fileName;

                    // Pindahkan file ke folder uploads
                    if (move_uploaded_file($tmpName, $uploadPath)) {
                        $uploadedAttachments[] = [
                            'name' => $name,
                            'file_name' => $fileName,
                            'path' => 'uploads/tasks/' . $fileName,
                            'type' => $fileType,
                            'size' => $fileSize,
                            'uploaded_by' => $userData['username'],
                            'uploaded_at' => date('Y-m-d H:i:s')
                        ];
                    } else {
                        $error = "Gagal mengupload file $name. Error: " . $_FILES['attachments']['error'][$key] . ". Periksa izin folder uploads.";
                    }
                } else {
                    $error = "Error upload untuk file $name: " . $_FILES['attachments']['error'][$key];
                }
            }
        }
    }

    // Proses penghapusan lampiran
    if (isset($_POST['delete_attachments']) && !empty($_POST['delete_attachments'])) {
        $deletedFiles = explode(',', $_POST['delete_attachments']);
        foreach ($deletedFiles as $deletedFile) {
            $deletedFile = trim($deletedFile);
            if (!empty($deletedFile)) {
                // Hapus dari array lampiran
                foreach ($uploadedAttachments as $key => $attachment) {
                    if (isset($attachment['file_name']) && $attachment['file_name'] === $deletedFile) {
                        // Hapus file fisik dari server
                        $filePath = "../uploads/tasks/" . $deletedFile;
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                        unset($uploadedAttachments[$key]);
                    }
                }
            }
        }
        // Re-index array
        $uploadedAttachments = array_values($uploadedAttachments);
    }

    // Jika ada error, tampilkan
    if (isset($error)) {
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => false, 'error' => $error]);
            ob_end_flush();
            exit;
        }
        // Untuk non-AJAX, error akan ditampilkan di bawah
    } else {
        // Update query
        $updateQuery = $mysqli->prepare("
            UPDATE tasks
            SET title = ?, note = ?, start_date = ?, end_date = ?,
                status = ?, progress = ?, assigned_users = ?, subtasks = ?, attachments = ?
            WHERE id = ?
        ");

        $subtasksJson = json_encode($subtasks);
        $attachmentsJson = json_encode($uploadedAttachments);
        $progressInt = intval($progress);

        $updateQuery->bind_param(
            "sssssisssi",
            $title,
            $description,
            $start_date,
            $end_date,
            $status,
            $progressInt,
            $assignedUsersStr,
            $subtasksJson,
            $attachmentsJson,
            $taskId
        );

        if ($updateQuery->execute()) {
            // Sync subtasks to task_subtasks table
            $mysqli->query("DELETE FROM task_subtasks WHERE task_id = $taskId");

            if (!empty($subtasks)) {
                foreach ($subtasks as $subtask) {
                    $subtaskTitle = $mysqli->real_escape_string($subtask['text']);
                    $assignedTo = isset($subtask['assigned']) ? $mysqli->real_escape_string($subtask['assigned']) : '';
                    $isCompleted = isset($subtask['completed']) && $subtask['completed'] ? 1 : 0;

                    $insertSubtaskQuery = $mysqli->prepare("INSERT INTO task_subtasks (task_id, title, assigned_to, is_completed, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $insertSubtaskQuery->bind_param("issi", $taskId, $subtaskTitle, $assignedTo, $isCompleted);
                    $insertSubtaskQuery->execute();
                }
            }

            if (isset($_POST['ajax'])) {
                echo json_encode(['success' => true, 'redirect' => "task_detail.php?id=$taskId"]);
                ob_end_flush();
                exit;
            } else {
                header("Location: task_detail.php?id=$taskId");
                exit;
            }
        } else {
            if (isset($_POST['ajax'])) {
                echo json_encode(['success' => false, 'error' => "Gagal memperbarui tugas: " . $mysqli->error]);
                ob_end_flush();
                exit;
            } else {
                $error = "Gagal memperbarui tugas: " . $mysqli->error;
            }
        }
    }
    
    // If we reach here in AJAX mode, something went wrong
    if (isset($_POST['ajax'])) {
        echo json_encode(['success' => false, 'error' => 'Unknown error occurred']);
        ob_end_flush();
        exit;
    }
}

// Ambil subtasks jika ada
$subtasks = [];
if (!empty($taskData['subtasks'])) {
    $subtasks = json_decode($taskData['subtasks'], true);
    if ($subtasks === null) {
        $subtasks = [];
    }
}

// Helper functions untuk PHP
function getFileIcon($fileType) {
    if (strpos($fileType, 'image/') === 0) return 'fas fa-image';
    if ($fileType === 'application/pdf') return 'fas fa-file-pdf';
    if (strpos($fileType, 'word') !== false) return 'fas fa-file-word';
    if (strpos($fileType, 'excel') !== false || strpos($fileType, 'spreadsheet') !== false) return 'fas fa-file-excel';
    if ($fileType === 'text/plain') return 'fas fa-file-alt';
    return 'fas fa-file';
}

function getFileIconClass($fileType) {
    if (strpos($fileType, 'image/') === 0) return 'image';
    if ($fileType === 'application/pdf') return 'pdf';
    if (strpos($fileType, 'word') !== false) return 'word';
    if (strpos($fileType, 'excel') !== false || strpos($fileType, 'spreadsheet') !== false) return 'excel';
    return 'other';
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function isImageFile($fileType) {
    return strpos($fileType, 'image/') === 0;
}

function getMimeTypeFromExtension($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime_types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
    ];
    
    return $mime_types[$extension] ?? 'application/octet-stream';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Edit Task - <?= htmlspecialchars($taskData['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f8f9fa;
            color: #333;
            min-height: 100vh;
        }

        .container {
            max-width: 540px;
            margin: 0 auto;
            padding: 0;
            background: white;
            min-height: 100vh;
            box-shadow: 0 0 30px rgba(0,0,0,0.08);
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);
            padding: 18px 20px;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
        }

        .task-menu-container {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
        }

        .task-menu-btn {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 8px 10px;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .task-menu-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }

        .task-menu-dropdown {
            position: absolute;
            right: 0;
            top: 48px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            min-width: 180px;
            z-index: 100;
            display: none;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .task-menu-dropdown.active {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .task-menu-item {
            padding: 14px 18px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }

        .task-menu-item:hover {
            background: linear-gradient(90deg, #EEF2FF 0%, #E0E7FF 100%);
        }

        .task-menu-item i {
            width: 20px;
            font-size: 15px;
        }

        .task-menu-item.delete {
            color: #ef4444;
        }

        .task-menu-item.delete:hover {
            background: linear-gradient(90deg, #fef2f2 0%, #fee2e2 100%);
        }

        .back-btn {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            font-size: 22px;
            color: white;
            cursor: pointer;
            padding: 8px 10px;
            border-radius: 10px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-3px);
        }

        .header-title {
            font-size: 20px;
            font-weight: 700;
            color: white;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Content */
        .content {
            padding: 24px 20px;
            padding-bottom: 100px;
            background: white;
        }

        .form-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* Form Groups */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: white;
            color: #1f2937;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4F46E5;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
            font-family: 'Poppins', sans-serif;
        }

        /* Date Input */
        .date-input-wrapper {
            position: relative;
        }

        .date-input-wrapper i {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #4F46E5;
            pointer-events: none;
            font-size: 18px;
        }

        .date-input-wrapper input[type="date"] {
            width: 100%;
            padding-right: 50px;
        }

        .date-input-wrapper input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: 0;
            width: 100%;
            height: 100%;
            position: absolute;
            left: 0;
            cursor: pointer;
        }

        /* Status Selector */
        .status-selector {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 10px 20px;
            border-radius: 24px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid;
            background: white;
        }

        .status-badge.todo {
            border-color: #cbd5e1;
            color: #64748b;
        }

        .status-badge.todo:hover {
            border-color: #94a3b8;
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.2);
            transform: translateY(-2px);
        }

        .status-badge.progress {
            border-color: #fbbf24;
            color: #d97706;
        }

        .status-badge.progress:hover {
            border-color: #f59e0b;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
            transform: translateY(-2px);
        }

        .status-badge.completed {
            border-color: #4ade80;
            color: #16a34a;
        }

        .status-badge.completed:hover {
            border-color: #22c55e;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
            transform: translateY(-2px);
        }

        .status-badge.selected {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .status-badge.todo.selected {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            border-color: #64748b;
        }

        .status-badge.progress.selected {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-color: #d97706;
        }

        .status-badge.completed.selected {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-color: #16a34a;
        }

        /* Progress Section */
        .progress-section {
            background: #F9FAFB;
            border-radius: 16px;
            padding: 20px;
            border: 2px solid #E5E7EB;
        }

        .progress-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
        }

        .progress-title i {
            color: #4F46E5;
            font-size: 18px;
        }

        .progress-bar-container {
            margin-bottom: 16px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .progress-label-text {
            font-size: 14px;
            color: #6b7280;
            font-weight: 600;
        }

        .progress-percentage {
            font-size: 16px;
            font-weight: 800;
            color: #4F46E5;
        }

        .progress-bar {
            height: 10px;
            background: #E5E7EB;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4F46E5 0%, #6366F1 100%);
            border-radius: 10px;
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .progress-input input[type="range"] {
            width: 100%;
            height: 6px;
            -webkit-appearance: none;
            background: transparent;
            border-radius: 3px;
            outline: none;
            margin-top: 8px;
        }

        .progress-input input[type="range"]::-webkit-slider-runnable-track {
            height: 6px;
            background: #E5E7EB;
            border-radius: 3px;
        }

        .progress-input input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 22px;
            height: 22px;
            background: #4F46E5;
            border-radius: 50%;
            cursor: pointer;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.4);
            margin-top: -8px;
        }

        .progress-input input[type="range"]::-webkit-slider-thumb:hover {
            transform: scale(1.15);
        }

        /* Attachments */
        .attachments-section {
            background: #F9FAFB;
            border-radius: 16px;
            padding: 20px;
            border: 2px solid #E5E7EB;
        }

        .attachments-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
        }

        .attachments-title i {
            color: #4F46E5;
            font-size: 18px;
        }

        .attachments-list {
            margin-bottom: 16px;
        }

        .file-item {
            display: flex;
            align-items: center;
            padding: 14px;
            background: white;
            border-radius: 12px;
            margin-bottom: 10px;
            border: 2px solid #E5E7EB;
            transition: all 0.3s;
        }

        .file-item:hover {
            border-color: #4F46E5;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
            transform: translateY(-2px);
        }

        .file-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 14px;
            font-size: 20px;
        }

        .file-icon.image { background: #DBEAFE; color: #1D4ED8; }
        .file-icon.pdf { background: #FEE2E2; color: #DC2626; }
        .file-icon.word { background: #DBEAFE; color: #2563EB; }
        .file-icon.excel { background: #DCFCE7; color: #16A34A; }
        .file-icon.other { background: #F3F4F6; color: #6B7280; }

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-size {
            font-size: 12px;
            color: #6b7280;
        }

        .file-actions {
            display: flex;
            gap: 6px;
        }

        .file-action-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: none;
            background: #F3F4F6;
            color: #6B7280;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
        }

        .file-action-btn:hover {
            background: #E5E7EB;
        }

        .file-action-btn.delete:hover {
            background: #FEE2E2;
            color: #DC2626;
        }

        .file-action-btn.preview:hover {
            background: #DBEAFE;
            color: #1D4ED8;
        }

        /* File Upload */
        .file-upload-wrapper {
            border: 2px dashed #CBD5E1;
            border-radius: 14px;
            padding: 32px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }

        .file-upload-wrapper:hover {
            border-color: #4F46E5;
            background: #F9FAFB;
        }

        .file-upload-wrapper.drag-over {
            border-color: #4F46E5;
            background: #EEF2FF;
        }

        .file-upload-icon {
            font-size: 42px;
            color: #4F46E5;
            margin-bottom: 12px;
        }

        .file-upload-text {
            font-size: 15px;
            color: #374151;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .file-upload-hint {
            font-size: 13px;
            color: #6B7280;
        }

        /* Assigned Users Section */
        .assigned-section {
            background: #F9FAFB;
            border-radius: 16px;
            padding: 20px;
            border: 2px solid #E5E7EB;
        }

        .assigned-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
        }

        .assigned-title i {
            color: #4F46E5;
            font-size: 18px;
        }

        .assigned-users-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .user-item {
            display: flex;
            align-items: center;
            padding: 12px 14px;
            background: white;
            border-radius: 12px;
            border: 2px solid #E5E7EB;
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-item:hover {
            border-color: #4F46E5;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.1);
            transform: translateX(4px);
        }

        .user-item.selected {
            background: linear-gradient(135deg, #EEF2FF 0%, #E0E7FF 100%);
            border-color: #4F46E5;
        }

        .user-checkbox {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            accent-color: #4F46E5;
            cursor: pointer;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            margin-right: 12px;
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 2px;
        }

        .user-role {
            font-size: 12px;
            color: #6B7280;
        }

        /* Subtasks - Updated to match image */
        .subtasks-section {
            background: #F9FAFB;
            border-radius: 16px;
            padding: 20px;
            border: 2px solid #E5E7EB;
        }

        .subtasks-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
        }

        .subtasks-title i {
            color: #4F46E5;
            font-size: 18px;
        }

        .subtask-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 16px;
            background: white;
            border-radius: 12px;
            margin-bottom: 10px;
            border: 2px solid #E5E7EB;
            transition: all 0.3s;
        }

        .subtask-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .subtask-top {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .subtask-checkbox {
            width: 22px;
            height: 22px;
            border: 2px solid #D1D5DB;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.3s;
            background: white;
        }

        .subtask-checkbox:hover {
            border-color: #4F46E5;
        }

        .subtask-checkbox.checked {
            background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);
            border-color: #4F46E5;
        }

        .subtask-checkbox.checked i {
            color: white;
            font-size: 12px;
        }

        .subtask-text {
            flex: 1;
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
            cursor: text;
        }

        .subtask-text.completed {
            text-decoration: line-through;
            color: #9CA3AF;
        }

        .subtask-edit-input {
            width: 100%;
            border: 2px solid #4F46E5;
            border-radius: 6px;
            padding: 6px 8px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
        }

        .subtask-edit-input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .subtask-actions {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }

        .subtask-edit-btn,
        .subtask-delete-btn {
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.2s;
            font-size: 14px;
        }

        .subtask-edit-btn:hover {
            background: #e5e7eb;
            color: #4F46E5;
        }

        .subtask-delete-btn:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        .subtask-assigned {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            padding-left: 34px;
        }

        .subtask-assigned-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #E0E7FF 0%, #DDD6FE 100%);
            border: 1px solid #C4B5FD;
            border-radius: 16px;
            font-size: 13px;
            color: #5B21B6;
            font-weight: 500;
        }

        .subtask-assigned-tag .remove-user {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 16px;
            height: 16px;
            background: rgba(91, 33, 182, 0.15);
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 10px;
            margin-left: 2px;
        }

        .subtask-assigned-tag .remove-user:hover {
            background: rgba(91, 33, 182, 0.3);
        }

        .subtask-add-user-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            padding: 0;
            background: white;
            border: 1px dashed #C4B5FD;
            border-radius: 50%;
            font-size: 14px;
            color: #5B21B6;
            cursor: pointer;
            transition: all 0.2s;
        }

        .subtask-add-user-btn:hover {
            background: #F5F3FF;
            border-color: #A78BFA;
        }

        .add-subtask-form {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .add-subtask-form input {
            flex: 1;
            padding: 12px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .add-subtask-form input:focus {
            outline: none;
            border-color: #4F46E5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .add-subtask-form button {
            padding: 12px 16px;
            background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.2s;
        }

        .add-subtask-form button:hover {
            transform: translateY(-2px);
        }

        .comments-section {
            background: #F9FAFB;
            border-radius: 16px;
            padding: 20px;
            border: 2px solid #E5E7EB;
            margin-top: 24px;
        }

        .comments-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
        }

        .comments-title i {
            color: #4F46E5;
            font-size: 18px;
        }

        .comments-list {
            margin-bottom: 16px;
            max-height: 200px;
            overflow-y: auto;
        }

        .comment-item {
            display: flex;
            gap: 12px;
            padding: 12px;
            background: white;
            border-radius: 12px;
            margin-bottom: 10px;
            border: 2px solid #E5E7EB;
        }

        .comment-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #4F46E5;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
        }

        .comment-content {
            flex: 1;
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .comment-name {
            font-size: 12px;
            font-weight: 600;
            color: #1f2937;
        }

        .comment-time {
            font-size: 10px;
            color: #6b7280;
        }

        .comment-text {
            font-size: 14px;
            color: #1f2937;
        }

        .add-comment-form {
            display: flex;
            gap: 10px;
        }

        .add-comment-form textarea {
            flex: 1;
            padding: 10px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            resize: vertical;
            min-height: 60px;
        }

        .add-comment-form button {
            padding: 10px 12px;
            background: #4F46E5;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        /* Action Buttons */
        .action-buttons {
            position: fixed;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            max-width: 540px;
            width: 100%;
            background: white;
            padding: 16px 20px;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.08);
            display: flex;
            gap: 12px;
            z-index: 90;
        }

        .btn {
            flex: 1;
            padding: 14px 24px;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-cancel {
            background: white;
            color: #374151;
            border: 2px solid #E5E7EB;
        }

        .btn-cancel:hover {
            background: #F9FAFB;
            border-color: #CBD5E1;
        }

        .btn-save {
            background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .btn-save:hover {
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.4);
            transform: translateY(-2px);
        }

        .btn-save:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Preview Modal */
        .preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .preview-content {
            max-width: 90%;
            max-height: 90%;
            position: relative;
        }

        .preview-image {
            max-width: 100%;
            max-height: 85vh;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }

        .close-preview {
            position: absolute;
            top: -40px;
            right: 0;
            background: white;
            border: none;
            color: #1f2937;
            font-size: 24px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .close-preview:hover {
            background: #F3F4F6;
            transform: rotate(90deg);
        }

        /* Error Message */
        .error-message {
            background: #FEF2F2;
            border: 2px solid #FECACA;
            color: #DC2626;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .error-message i {
            font-size: 18px;
        }

        /* Save Message Toast */
        .save-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
            padding: 14px 24px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
            z-index: 1000;
            animation: slideIn 0.3s ease;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @keyframes slideIn {
            from { 
                transform: translateX(400px); 
                opacity: 0; 
            }
            to { 
                transform: translateX(0); 
                opacity: 1; 
            }
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        /* Loading state */
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .fa-spinner {
            animation: spin 1s linear infinite;
        }

        /* Responsive */
        @media (max-width: 540px) {
            .container {
                max-width: 100%;
            }

            .action-buttons {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <button class="back-btn" onclick="window.history.back()" aria-label="Kembali">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">Edit Tugas</div>
                <div class="task-menu-container">
                    <button class="task-menu-btn" onclick="toggleTaskMenu()" aria-expanded="false" aria-label="Menu tugas">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="task-menu-dropdown" role="menu">
                        <div class="task-menu-item" onclick="viewTaskDetail()" role="menuitem">
                            <i class="fas fa-eye"></i>
                            Lihat Detail Tugas
                        </div>
                        <div class="task-menu-item delete" onclick="deleteTask()" role="menuitem">
                            <i class="fas fa-trash"></i>
                            Hapus Tugas
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Error Message -->
            <?php if (isset($error)): ?>
                <div class="error-message show">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form id="editTaskForm" action="" method="POST" class="form-container" enctype="multipart/form-data">
                <!-- Basic Info -->
                <div class="form-group">
                    <label for="title">Judul Tugas</label>
                    <input type="text" id="title" name="title" value="<?= htmlspecialchars($taskData['title']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">Deskripsi</label>
                    <textarea id="description" name="description"><?= htmlspecialchars($taskData['note'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Tanggal Mulai</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="start_date" name="start_date" value="<?= $taskData['start_date'] ?>" required>
                            <i class="far fa-calendar-alt"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="end_date">Tanggal Selesai</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="end_date" name="end_date" value="<?= $taskData['end_date'] ?>" required>
                            <i class="far fa-calendar-alt"></i>
                        </div>
                    </div>
                </div>

                <!-- Status Selector -->
                <div class="form-group">
                    <label>Status Tugas</label>
                    <div class="status-selector">
                        <span class="status-badge todo <?= $taskData['status'] === 'todo' ? 'selected' : '' ?>" onclick="selectStatus('todo')">
                            Belum Dimulai
                        </span>
                        <span class="status-badge progress <?= $taskData['status'] === 'progress' ? 'selected' : '' ?>" onclick="selectStatus('progress')">
                            Sedang Berjalan
                        </span>
                        <span class="status-badge completed <?= $taskData['status'] === 'completed' ? 'selected' : '' ?>" onclick="selectStatus('completed')">
                            Selesai
                        </span>
                    </div>
                    <input type="hidden" id="status" name="status" value="<?= $taskData['status'] ?>">
                </div>

                <!-- Progress Section -->
                <div class="progress-section">
                    <div class="progress-title">
                        <i class="fas fa-chart-line"></i>
                        Progress
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-label">
                            <span class="progress-label-text">Progress</span>
                            <span class="progress-percentage" id="progressValue"><?= $taskData['progress'] ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" id="progressFill" style="width: <?= $taskData['progress'] ?>%"></div>
                        </div>
                    </div>
                    <div class="progress-input">
                        <input type="range" id="progress" name="progress" min="0" max="100" value="<?= $taskData['progress'] ?>" oninput="updateProgress(this.value)">
                    </div>
                    <input type="hidden" id="progressHidden" name="progress" value="<?= $taskData['progress'] ?>">
                </div>

                <!-- Attachments Section -->
                <div class="attachments-section">
                    <div class="attachments-title">
                        <i class="fas fa-paperclip"></i>
                        Lampiran Tugas
                    </div>
                    
                    <!-- Existing Attachments -->
                    <div class="attachments-list" id="existingAttachments">
                        <?php foreach ($attachments as $index => $attachment): 
                            // Handle missing array keys
                            $fileName = $attachment['file_name'] ?? ($attachment['name'] ?? 'unnamed');
                            $fileType = $attachment['type'] ?? getMimeTypeFromExtension($fileName);
                            $fileSize = $attachment['size'] ?? 0;
                            $filePath = $attachment['path'] ?? ('uploads/tasks/' . $fileName);
                            $displayName = $attachment['name'] ?? $fileName;
                        ?>
                            <div class="file-item" data-file-name="<?= htmlspecialchars($fileName) ?>">
                                <div class="file-icon <?= getFileIconClass($fileType) ?>">
                                    <i class="<?= getFileIcon($fileType) ?>"></i>
                                </div>
                                <div class="file-info">
                                    <div class="file-name"><?= htmlspecialchars($displayName) ?></div>
                                    <div class="file-size"><?= formatFileSize($fileSize) ?></div>
                                </div>
                                <div class="file-actions">
                                    <?php if (isImageFile($fileType)): ?>
                                        <button type="button" class="file-action-btn preview" onclick="previewFile('<?= htmlspecialchars($filePath) ?>', '<?= htmlspecialchars($displayName) ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a href="../<?= htmlspecialchars($filePath) ?>" class="file-action-btn" download>
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button type="button" class="file-action-btn delete" onclick="deleteAttachment('<?= htmlspecialchars($fileName) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- File Upload Area -->
                    <div class="file-upload-wrapper" id="fileUploadArea" onclick="document.getElementById('fileInput').click()"
                         ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
                        <div class="file-upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="file-upload-text">
                            Klik atau tarik file ke sini untuk mengupload
                        </div>
                        <div class="file-upload-hint">
                            Maksimal 5MB per file. Gambar, PDF, Word, Excel, Text
                        </div>
                    </div>
                    <input type="file" id="fileInput" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" onchange="handleFileSelect(event)" style="display: none;">
                </div>

                <!-- Assign ke Anggota Tim -->
                <div class="assigned-section">
                    <div class="assigned-title">
                        <i class="fas fa-users"></i>
                        Assign ke Anggota Tim
                    </div>
                    <div class="add-user-form" style="margin-bottom: 16px;">
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="newUserName" placeholder="Tambah anggota tim..." style="flex: 1; padding: 10px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                            <button type="button" onclick="addCustomUser()" style="padding: 10px 12px; background: #4F46E5; color: white; border: none; border-radius: 8px; cursor: pointer;">
                                <i class="fas fa-plus"></i> Tambah
                            </button>
                        </div>
                    </div>
                    <div class="assigned-users-list">
                        <?php
                        foreach ($users as $userRow):
                            $initial = strtoupper(substr($userRow['username'], 0, 1));
                            $isSelected = in_array($userRow['username'], $assignedUsersArray);
                        ?>
                            <div class="user-item <?= $isSelected ? 'selected' : '' ?>" onclick="toggleUserSelection(this, '<?= htmlspecialchars($userRow['username']) ?>')">
                                <input type="checkbox" class="user-checkbox" <?= $isSelected ? 'checked' : '' ?> onchange="event.stopPropagation();">
                                <div class="user-avatar"><?= $initial ?></div>
                                <div class="user-info">
                                    <div class="user-name"><?= htmlspecialchars($userRow['username']) ?></div>
                                    <div class="user-role"><?= htmlspecialchars($userRow['role'] ?? 'Member') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php
                        // Add custom users not in database
                        foreach ($assignedUsersArray as $assignedUser):
                            $found = false;
                            foreach ($users as $user) {
                                if ($user['username'] === $assignedUser) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found):
                                $initial = strtoupper(substr($assignedUser, 0, 1));
                        ?>
                            <div class="user-item selected" onclick="toggleUserSelection(this, '<?= htmlspecialchars($assignedUser) ?>')">
                                <input type="checkbox" class="user-checkbox" checked onchange="event.stopPropagation();">
                                <div class="user-avatar"><?= $initial ?></div>
                                <div class="user-info">
                                    <div class="user-name"><?= htmlspecialchars($assignedUser) ?></div>
                                    <div class="user-role">Custom</div>
                                </div>
                            </div>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </div>
                    <input type="hidden" id="assignedUsers" name="assigned_users" value="<?= implode(',', $assignedUsersArray) ?>">
                    <input type="hidden" id="deleteAttachments" name="delete_attachments" value="">
                </div>

                <!-- Subtasks -->
                <div class="subtasks-section">
                    <div class="subtasks-title">
                        <i class="fas fa-tasks"></i>
                        Subtasks / Pekerjaan
                    </div>
                    <div id="subtasksContainer">
                        <?php foreach ($subtasks as $index => $subtask): ?>
                            <div class="subtask-item">
                                <div class="subtask-top">
                                    <div class="subtask-checkbox <?= $subtask['completed'] ? 'checked' : '' ?>" onclick="toggleSubtask(<?= $index ?>)">
                                        <?php if ($subtask['completed']): ?>
                                            <i class="fas fa-check"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="subtask-text <?= $subtask['completed'] ? 'completed' : '' ?>" ondblclick="editSubtaskInline(this, <?= $index ?>)">
                                        <?= htmlspecialchars($subtask['text']) ?>
                                    </div>
                                    <div class="subtask-actions">
                                        <button type="button" class="subtask-edit-btn" onclick="editSubtaskInline(this.parentElement.previousElementSibling, <?= $index ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="subtask-delete-btn" onclick="deleteSubtask(<?= $index ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="subtask-assigned">
                                    <?php
                                    $assignedUsers = [];
                                    if (isset($subtask['assigned'])) {
                                        if (is_array($subtask['assigned'])) {
                                            $assignedUsers = $subtask['assigned'];
                                        } else if (!empty($subtask['assigned'])) {
                                            $assignedUsers = explode(',', $subtask['assigned']);
                                        }
                                    }
                                    
                                    foreach ($assignedUsers as $assignedUser):
                                        $assignedUser = trim($assignedUser);
                                        if (!empty($assignedUser)):
                                    ?>
                                        <span class="subtask-assigned-tag">
                                            <?= htmlspecialchars($assignedUser) ?>
                                            <span class="remove-user" onclick="removeUserFromSubtask(<?= $index ?>, '<?= htmlspecialchars($assignedUser) ?>')">×</span>
                                        </span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                    <button type="button" class="subtask-add-user-btn" onclick="addUserToSubtask(<?= $index ?>)">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="add-subtask-form">
                        <input type="text" id="newSubtask" placeholder="Tambah subtask...">
                        <button type="button" onclick="addSubtask()">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>

                <!-- Comments Section -->
                <div class="comments-section">
                    <div class="comments-title">
                        <i class="fas fa-comments"></i>
                        Komentar & Diskusi
                    </div>
                    <div class="comments-list" id="commentsList">
                        <!-- Comments will be loaded here -->
                    </div>
                    <div class="add-comment-form">
                        <textarea id="newComment" placeholder="Tulis komentar Anda..."></textarea>
                        <button type="button" onclick="addComment()">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="action-buttons">
                    <div style="text-align: center; margin-bottom: 12px; font-size: 12px; color: #6b7280;">
                        <i class="fas fa-info-circle"></i> Perubahan belum tersimpan. Klik "Simpan Perubahan" untuk menyimpan.
                    </div>
                    <button type="button" class="btn btn-cancel" onclick="window.history.back()">Batal</button>
                    <button type="submit" class="btn btn-save">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Preview Modal -->
    <div class="preview-modal" id="previewModal">
        <div class="preview-content">
            <button class="close-preview" onclick="closePreview()">
                <i class="fas fa-times"></i>
            </button>
            <img id="previewImage" src="" alt="Preview" class="preview-image">
        </div>
    </div>

    <script>
        // Auto-save functionality
        let autoSaveTimeout;

        function scheduleAutoSave() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => saveTask(false), 2000);
        }

        // Add auto-save triggers for all input fields
        document.addEventListener('DOMContentLoaded', function() {
            // Text inputs and textareas
            const inputs = document.querySelectorAll('input[type="text"], input[type="date"], textarea');
            inputs.forEach(input => {
                input.addEventListener('input', scheduleAutoSave);
            });

            // Progress slider
            const progressSlider = document.getElementById('progress');
            if (progressSlider) {
                progressSlider.addEventListener('input', scheduleAutoSave);
            }

            // User selection changes
            const userItems = document.querySelectorAll('.user-item');
            userItems.forEach(item => {
                item.addEventListener('click', scheduleAutoSave);
            });

            // Subtask changes
            document.addEventListener('subtaskChanged', scheduleAutoSave);
            
            // Load comments on page load
            loadComments();
            
            // Initialize subtasks
            updateSubtasks();
        });

        async function saveTask(redirect = false) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('title', document.getElementById('title').value);
            formData.append('description', document.getElementById('description').value);
            formData.append('start_date', document.getElementById('start_date').value);
            formData.append('end_date', document.getElementById('end_date').value);
            formData.append('status', document.getElementById('status').value);
            formData.append('progress', document.getElementById('progress').value);
            formData.append('assigned_users', document.getElementById('assignedUsers').value);
            formData.append('subtasks', document.querySelector('input[name="subtasks"]').value);
            formData.append('delete_attachments', document.getElementById('deleteAttachments').value);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    if (redirect) {
                        window.location.href = result.redirect;
                    } else {
                        showSaveMessage();
                    }
                } else {
                    console.error('Save error:', result.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function showSaveMessage() {
            const existing = document.querySelector('.auto-save-message');
            if (existing) existing.remove();

            const message = document.createElement('div');
            message.className = 'auto-save-message';
            message.textContent = '✓ Tersimpan otomatis';
            message.style.position = 'fixed';
            message.style.top = '20px';
            message.style.right = '20px';
            message.style.background = '#10b981';
            message.style.color = 'white';
            message.style.padding = '10px 20px';
            message.style.borderRadius = '8px';
            message.style.zIndex = '1000';
            message.style.fontSize = '14px';
            message.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
            document.body.appendChild(message);
            setTimeout(() => {
                if (message.parentNode) message.remove();
            }, 2000);
        }

        // Status selection
        function selectStatus(status) {
            document.getElementById('status').value = status;

            // Update UI
            document.querySelectorAll('.status-badge').forEach(badge => {
                badge.classList.remove('selected');
            });

            document.querySelectorAll('.status-badge.' + status).forEach(badge => {
                badge.classList.add('selected');
            });

            scheduleAutoSave();
        }

        // Progress update
        function updateProgress(value) {
            document.getElementById('progressValue').textContent = value + '%';
            document.getElementById('progressFill').style.width = value + '%';
            document.getElementById('progressHidden').value = value;
            scheduleAutoSave();
        }

        // User selection
        let selectedUsers = <?= json_encode($assignedUsersArray) ?>;

        function toggleUserSelection(element, userName) {
            const checkbox = element.querySelector('.user-checkbox');
            const index = selectedUsers.indexOf(userName);

            if (index > -1) {
                selectedUsers.splice(index, 1);
                element.classList.remove('selected');
                checkbox.checked = false;
            } else {
                selectedUsers.push(userName);
                element.classList.add('selected');
                checkbox.checked = true;
            }

            document.getElementById('assignedUsers').value = selectedUsers.join(',');
            scheduleAutoSave();
        }

        function addCustomUser() {
            const input = document.getElementById('newUserName');
            const userName = input.value.trim();

            if (!userName) return;

            // Check if user already exists
            if (selectedUsers.includes(userName)) {
                alert('User sudah ditambahkan');
                return;
            }

            // Add to selected users
            selectedUsers.push(userName);
            document.getElementById('assignedUsers').value = selectedUsers.join(',');

            // Add to UI
            const assignedUsersList = document.querySelector('.assigned-users-list');
            const initial = userName.charAt(0).toUpperCase();

            const userItem = document.createElement('div');
            userItem.className = 'user-item selected';
            userItem.onclick = () => toggleUserSelection(userItem, userName);
            userItem.innerHTML = `
                <input type="checkbox" class="user-checkbox" checked onchange="event.stopPropagation();">
                <div class="user-avatar">${initial}</div>
                <div class="user-info">
                    <div class="user-name">${escapeHtml(userName)}</div>
                    <div class="user-role">Custom</div>
                </div>
            `;

            assignedUsersList.appendChild(userItem);

            // Clear input
            input.value = '';

            // Trigger auto-save
            scheduleAutoSave();
        }

        // Subtasks
        let subtasks = <?= json_encode($subtasks) ?>;

        function addSubtask() {
            const input = document.getElementById('newSubtask');
            const text = input.value.trim();

            if (!text) return;

            subtasks.push({
                text: text,
                completed: false,
                assigned: ''
            });

            updateSubtasks();
            input.value = '';

            // Save immediately when subtask is added
            scheduleAutoSave();
        }

        function toggleSubtask(index) {
            if (index > -1 && subtasks[index]) {
                subtasks[index].completed = !subtasks[index].completed;
                updateSubtasks();
                
                // Dispatch event for auto-save
                document.dispatchEvent(new Event('subtaskChanged'));
            }
        }

        function editSubtaskInline(element, index) {
            const currentText = subtasks[index].text;
            const input = document.createElement('input');
            input.type = 'text';
            input.value = currentText;
            input.className = 'subtask-edit-input';
            input.onblur = function() {
                const newText = this.value.trim();
                if (newText && newText !== currentText) {
                    subtasks[index].text = newText;
                    updateSubtasks();
                    document.dispatchEvent(new Event('subtaskChanged'));
                } else {
                    updateSubtasks();
                }
            };
            input.onkeydown = function(e) {
                if (e.key === 'Enter') {
                    this.blur();
                } else if (e.key === 'Escape') {
                    updateSubtasks();
                }
            };
            
            element.parentNode.replaceChild(input, element);
            input.focus();
            input.select();
        }

        function addUserToSubtask(index) {
            const userName = prompt('Masukkan nama user untuk ditambahkan:');
            if (userName && userName.trim()) {
                const current = subtasks[index].assigned || '';
                const users = current ? current.split(',').map(u => u.trim()).filter(u => u) : [];
                
                if (!users.includes(userName.trim())) {
                    users.push(userName.trim());
                    subtasks[index].assigned = users.join(',');
                    updateSubtasks();
                    document.dispatchEvent(new Event('subtaskChanged'));
                }
            }
        }

        function removeUserFromSubtask(index, userName) {
            const current = subtasks[index].assigned || '';
            const users = current.split(',').map(u => u.trim()).filter(u => u);
            const filteredUsers = users.filter(u => u !== userName);
            
            subtasks[index].assigned = filteredUsers.join(',');
            updateSubtasks();
            document.dispatchEvent(new Event('subtaskChanged'));
        }

        function deleteSubtask(index) {
            if (confirm('Apakah Anda yakin ingin menghapus subtask ini?')) {
                subtasks.splice(index, 1);
                updateSubtasks();
                document.dispatchEvent(new Event('subtaskChanged'));
            }
        }

        function updateSubtasks() {
            const container = document.getElementById('subtasksContainer');
            container.innerHTML = '';

            subtasks.forEach((subtask, index) => {
                const div = document.createElement('div');
                div.className = 'subtask-item';
                
                // Top section (checkbox + text + actions)
                const topDiv = document.createElement('div');
                topDiv.className = 'subtask-top';
                
                // Create checkbox
                const checkbox = document.createElement('div');
                checkbox.className = 'subtask-checkbox' + (subtask.completed ? ' checked' : '');
                checkbox.onclick = () => toggleSubtask(index);
                if (subtask.completed) {
                    checkbox.innerHTML = '<i class="fas fa-check"></i>';
                }
                
                // Create text
                const text = document.createElement('div');
                text.className = 'subtask-text' + (subtask.completed ? ' completed' : '');
                text.textContent = subtask.text;
                text.ondblclick = () => editSubtaskInline(text, index);
                
                // Create actions
                const actions = document.createElement('div');
                actions.className = 'subtask-actions';
                
                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'subtask-edit-btn';
                editBtn.innerHTML = '<i class="fas fa-edit"></i>';
                editBtn.onclick = () => editSubtaskInline(text, index);
                
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'subtask-delete-btn';
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
                deleteBtn.onclick = () => deleteSubtask(index);
                
                actions.appendChild(editBtn);
                actions.appendChild(deleteBtn);
                
                topDiv.appendChild(checkbox);
                topDiv.appendChild(text);
                topDiv.appendChild(actions);
                
                // Assigned users section
                const assignedDiv = document.createElement('div');
                assignedDiv.className = 'subtask-assigned';
                
                // Show assigned users as tags
                if (subtask.assigned && subtask.assigned.trim()) {
                    const users = subtask.assigned.split(',').map(u => u.trim()).filter(u => u);
                    users.forEach((user, userIndex) => {
                        const tag = document.createElement('span');
                        tag.className = 'subtask-assigned-tag';
                        
                        const userName = document.createElement('span');
                        userName.textContent = user;
                        
                        const removeBtn = document.createElement('span');
                        removeBtn.className = 'remove-user';
                        removeBtn.innerHTML = '×';
                        removeBtn.onclick = (e) => {
                            e.stopPropagation();
                            removeUserFromSubtask(index, user);
                        };
                        
                        tag.appendChild(userName);
                        tag.appendChild(removeBtn);
                        assignedDiv.appendChild(tag);
                    });
                }
                
                // Always show add button
                const addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.className = 'subtask-add-user-btn';
                addBtn.innerHTML = '<i class="fas fa-plus"></i>';
                addBtn.onclick = (e) => {
                    e.stopPropagation();
                    addUserToSubtask(index);
                };
                assignedDiv.appendChild(addBtn);
                
                // Assemble
                div.appendChild(topDiv);
                div.appendChild(assignedDiv);
                container.appendChild(div);
            });

            // Update hidden field for form submission
            const subtasksInput = document.createElement('input');
            subtasksInput.type = 'hidden';
            subtasksInput.name = 'subtasks';
            subtasksInput.value = JSON.stringify(subtasks);

            // Remove existing if any
            const existing = document.querySelector('input[name="subtasks"]');
            if (existing) existing.remove();

            document.querySelector('form').appendChild(subtasksInput);
        }

        // File upload functionality
        function handleDragOver(e) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('fileUploadArea').classList.add('drag-over');
        }

        function handleDragLeave(e) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('fileUploadArea').classList.remove('drag-over');
        }

        function handleDrop(e) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('fileUploadArea').classList.remove('drag-over');
            
            const files = e.dataTransfer.files;
            document.getElementById('fileInput').files = files;
            handleFileSelect({ target: document.getElementById('fileInput') });
        }

        async function handleFileSelect(event) {
            const files = event.target.files;
            if (files.length === 0) return;

            const formData = new FormData();
            formData.append('action', 'upload_attachments');
            formData.append('task_id', <?= $taskId ?>);
            for (let i = 0; i < files.length; i++) {
                formData.append('attachments[]', files[i]);
            }

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success && result.attachments) {
                    result.attachments.forEach(attachment => {
                        addAttachmentToUI(attachment);
                    });
                    
                    // Clear file input
                    event.target.value = '';
                    
                    // Schedule auto-save
                    scheduleAutoSave();
                } else {
                    alert('Error: ' + (result.error || 'Upload gagal'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat upload file');
            }
        }

        function addAttachmentToUI(attachment) {
            const container = document.getElementById('existingAttachments');
            
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            fileItem.dataset.fileName = attachment.file_name;
            
            const fileType = attachment.type;
            const fileIconClass = getFileIconClass(fileType);
            const fileIcon = getFileIcon(fileType);
            const fileSize = formatFileSize(attachment.size);
            const isImage = isImageFile(fileType);
            
            fileItem.innerHTML = `
                <div class="file-icon ${fileIconClass}">
                    <i class="${fileIcon}"></i>
                </div>
                <div class="file-info">
                    <div class="file-name">${escapeHtml(attachment.name)}</div>
                    <div class="file-size">${fileSize}</div>
                </div>
                <div class="file-actions">
                    ${isImage ? `
                        <button type="button" class="file-action-btn preview" onclick="previewFile('${escapeHtml(attachment.path)}', '${escapeHtml(attachment.name)}')">
                            <i class="fas fa-eye"></i>
                        </button>
                    ` : ''}
                    <a href="../${escapeHtml(attachment.path)}" class="file-action-btn" download>
                        <i class="fas fa-download"></i>
                    </a>
                    <button type="button" class="file-action-btn delete" onclick="deleteAttachment('${escapeHtml(attachment.file_name)}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            
            container.appendChild(fileItem);
        }

        async function deleteAttachment(fileName) {
            if (!confirm('Apakah Anda yakin ingin menghapus lampiran ini?')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'delete_attachment');
                formData.append('task_id', <?= $taskId ?>);
                formData.append('file_name', fileName);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Hapus dari tampilan
                    const fileItem = document.querySelector(`.file-item[data-file-name="${fileName}"]`);
                    if (fileItem) {
                        fileItem.remove();
                    }
                    // Add to delete list for form submission
                    const deleteInput = document.getElementById('deleteAttachments');
                    let currentDeletes = deleteInput.value ? deleteInput.value.split(',') : [];
                    currentDeletes.push(fileName);
                    deleteInput.value = currentDeletes.join(',');
                    
                    // Schedule auto-save
                    scheduleAutoSave();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat menghapus lampiran');
            }
        }

        function previewFile(filePath, fileName) {
            const previewModal = document.getElementById('previewModal');
            const previewImage = document.getElementById('previewImage');
            
            previewImage.src = '../' + filePath;
            previewImage.alt = fileName;
            previewModal.style.display = 'flex';
        }

        function closePreview() {
            document.getElementById('previewModal').style.display = 'none';
        }

        // Helper functions untuk file icons
        function getFileIcon(fileType) {
            if (fileType.startsWith('image/')) return 'fas fa-image';
            if (fileType === 'application/pdf') return 'fas fa-file-pdf';
            if (fileType.includes('word')) return 'fas fa-file-word';
            if (fileType.includes('excel') || fileType.includes('spreadsheet')) return 'fas fa-file-excel';
            if (fileType === 'text/plain') return 'fas fa-file-alt';
            return 'fas fa-file';
        }

        function getFileIconClass(fileType) {
            if (fileType.startsWith('image/')) return 'image';
            if (fileType === 'application/pdf') return 'pdf';
            if (fileType.includes('word')) return 'word';
            if (fileType.includes('excel') || fileType.includes('spreadsheet')) return 'excel';
            return 'other';
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function isImageFile(fileType) {
            return fileType.startsWith('image/');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal when clicking outside
        document.getElementById('previewModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closePreview();
            }
        });

        // Form validation and AJAX submission
        document.querySelector('form').addEventListener('submit', async function(e) {
            e.preventDefault();

            const startDate = new Date(document.getElementById('start_date').value);
            const endDate = new Date(document.getElementById('end_date').value);

            if (endDate < startDate) {
                alert('Tanggal selesai tidak boleh sebelum tanggal mulai');
                return false;
            }

            // Show loading state
            const submitBtn = document.querySelector('.btn-save');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Menyimpan...';
            submitBtn.disabled = true;

            try {
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('title', document.getElementById('title').value);
                formData.append('description', document.getElementById('description').value);
                formData.append('start_date', document.getElementById('start_date').value);
                formData.append('end_date', document.getElementById('end_date').value);
                formData.append('status', document.getElementById('status').value);
                formData.append('progress', document.getElementById('progress').value);
                formData.append('assigned_users', document.getElementById('assignedUsers').value);
                formData.append('subtasks', document.querySelector('input[name="subtasks"]').value);
                formData.append('delete_attachments', document.getElementById('deleteAttachments').value);

                // Add files
                const fileInput = document.getElementById('fileInput');
                for (let i = 0; i < fileInput.files.length; i++) {
                    formData.append('attachments[]', fileInput.files[i]);
                }

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Redirect to task detail page
                    window.location.href = result.redirect;
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat menyimpan perubahan');
            } finally {
                // Reset button state
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }

            return false;
        });
        
        // Comments functionality
        async function addComment() {
            const textarea = document.getElementById('newComment');
            const text = textarea.value.trim();

            if (!text) {
                alert('Silakan tulis komentar terlebih dahulu');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'add_comment');
                formData.append('task_id', <?= $taskId ?>);
                formData.append('comment', text);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Add the new comment to the UI
                    addCommentToUI(result.comment);
                    textarea.value = '';

                    // Auto scroll to bottom
                    const commentsList = document.getElementById('commentsList');
                    commentsList.scrollTop = commentsList.scrollHeight;
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat menambah komentar');
            }
        }

        function addCommentToUI(comment) {
            const commentsList = document.getElementById('commentsList');
            const commentDate = new Date(comment.created_at);
            const timeStr = formatCommentTime(commentDate);

            const commentHTML = `
                <div class="comment-item" data-comment-id="${comment.id}">
                    <div class="comment-avatar">${comment.username.charAt(0).toUpperCase()}</div>
                    <div class="comment-content">
                        <div class="comment-header">
                            <div class="comment-name">${escapeHtml(comment.username)}</div>
                            <div class="comment-time">${timeStr}</div>
                        </div>
                        <div class="comment-text">${escapeHtml(comment.comment)}</div>
                    </div>
                    <button type="button" class="comment-delete-btn" onclick="deleteComment(${comment.id})" title="Hapus komentar" style="background: none; border: none; color: #9ca3af; cursor: pointer; padding: 4px; border-radius: 4px;">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;

            commentsList.insertAdjacentHTML('beforeend', commentHTML);
        }

        async function deleteComment(commentId) {
            if (!confirm('Apakah Anda yakin ingin menghapus komentar ini?')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'delete_comment');
                formData.append('comment_id', commentId);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Remove comment from UI
                    const commentItem = document.querySelector(`[data-comment-id="${commentId}"]`);
                    if (commentItem) {
                        commentItem.remove();
                    }
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat menghapus komentar');
            }
        }

        function formatCommentTime(date) {
            const now = new Date();
            const diff = now - date;
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(diff / 3600000);
            const days = Math.floor(diff / 86400000);

            if (minutes < 1) return 'Baru saja';
            if (minutes < 60) return `${minutes} menit yang lalu`;
            if (hours < 24) return `${hours} jam yang lalu`;
            if (days < 7) return `${days} hari yang lalu`;

            return date.toLocaleDateString('id-ID', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Load comments on page load
        async function loadComments() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_comments');
                formData.append('task_id', <?= $taskId ?>);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    const commentsList = document.getElementById('commentsList');
                    commentsList.innerHTML = '';

                    result.comments.forEach(comment => {
                        addCommentToUI(comment);
                    });
                } else {
                    console.error('Error loading comments:', result.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Allow Enter to send comment (Ctrl+Enter for new line)
        document.getElementById('newComment').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.ctrlKey && !e.shiftKey) {
                e.preventDefault();
                addComment();
            }
        });

        // Task menu functions
        function toggleTaskMenu() {
            const menu = document.querySelector('.task-menu-dropdown');
            menu.classList.toggle('active');
        }

        function viewTaskDetail() {
            window.location.href = `task_detail.php?id=<?= $taskId ?>`;
        }

        function deleteTask() {
            if (confirm('Apakah Anda yakin ingin menghapus tugas ini?')) {
                window.location.href = `delete_task.php?id=<?= $taskId ?>`;
            }
        }

        // Close task menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.querySelector('.task-menu-dropdown');
            const button = document.querySelector('.task-menu-btn');
            
            if (menu.classList.contains('active') && 
                !menu.contains(event.target) && 
                !button.contains(event.target)) {
                menu.classList.remove('active');
            }
        });
    </script>
</body>
</html>