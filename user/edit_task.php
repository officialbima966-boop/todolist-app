
<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

$username = $_SESSION['user'];

// Ambil data user
$userQuery = $mysqli->prepare("SELECT * FROM users WHERE username = ?");
$userQuery->bind_param("s", $username);
$userQuery->execute();
$result = $userQuery->get_result();

if ($result->num_rows == 0) {
    session_destroy();
    header("Location: ../auth/login.php?error=user_not_found");
    exit;
}

$userData = $result->fetch_assoc();

// Redirect jika admin
if ($userData['role'] == 'admin') {
    header("Location: ../admin/dashboard.php");
    exit;
}

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

// Cek apakah user adalah pembuat task atau ditugaskan ke task ini
if ($taskData['created_by'] !== $username && !str_contains($taskData['assigned_users'], $username)) {
    header("Location: tasks.php?error=unauthorized");
    exit;
}

// Ambil semua user untuk assign
$usersQuery = $mysqli->query("SELECT * FROM users WHERE username != '$username'");

// Ambil lampiran yang sudah ada
$attachments = [];
if (!empty($taskData['attachments'])) {
    $attachments = json_decode($taskData['attachments'], true);
    if ($attachments === null || !is_array($attachments)) {
        $attachments = [];
    }
}

// Proses update jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $status = $_POST['status'];
    $progress = $_POST['progress'];
    
    // Ambil assigned users
    $assignedUsers = [];
    if (isset($_POST['assigned_users']) && is_array($_POST['assigned_users'])) {
        $assignedUsers = $_POST['assigned_users'];
    }
    $assignedUsersStr = implode(',', $assignedUsers);
    
    // Ambil subtasks dari POST
    $subtasks = [];
    if (isset($_POST['subtasks']) && !empty($_POST['subtasks'])) {
        $subtasks = json_decode($_POST['subtasks'], true);
    }
    
    // Proses upload file lampiran
    $uploadedAttachments = $attachments; // Simpan yang sudah ada

    // Jika ada file yang diupload
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
                            'uploaded_by' => $username,
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

    // Jika ada error, jangan update
    if (isset($error)) {
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
        // Untuk non-AJAX, error akan ditampilkan di bawah
    } else {
        // Update query - tambahkan kolom attachments
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
            "sssssissii",
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

        if (isset($_POST['ajax'])) {
            if ($updateQuery->execute()) {
                echo json_encode(['success' => true, 'redirect' => "task_detail.php?id=$taskId"]);
                exit;
            } else {
                echo json_encode(['success' => false, 'error' => "Gagal memperbarui tugas: " . $mysqli->error]);
                exit;
            }
        } else {
            if ($updateQuery->execute()) {
                header("Location: task_detail.php?id=$taskId");
                exit;
            } else {
                $error = "Gagal memperbarui tugas: " . $mysqli->error;
            }
        }
    }
}

// Ambil subtasks jika ada (dari JSON atau format lainnya)
$subtasks = [];
if (!empty($taskData['subtasks'])) {
    $subtasks = json_decode($taskData['subtasks'], true);
    if ($subtasks === null) {
        $subtasks = [];
    }
}

// Parse assigned users
$assignedUsersArray = $taskData['assigned_users'] ? explode(',', $taskData['assigned_users']) : [];

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

// Fungsi untuk mendapatkan tipe file dari ekstensi
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
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
        }

        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 0;
        }

        /* Header */
        .header {
            background: #3550dc;
            color: white;
            padding: 20px 15px 25px 15px;
            position: relative;
            border-radius: 0 0 20px 20px;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .header-title {
            font-size: 18px;
            font-weight: 600;
            flex: 1;
        }

        /* Content */
        .content {
            padding: 20px 15px;
        }

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }

        .form-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            font-size: 14px;
            color: #374151;
            background: white;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3550dc;
            box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: flex;
            gap: 12px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .date-input-wrapper {
            position: relative;
        }

        .date-input-wrapper i {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }

        .date-input-wrapper input {
            padding-right: 40px;
        }

        /* File Upload */
        .file-upload-wrapper {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f9fafb;
        }

        .file-upload-wrapper:hover {
            border-color: #3550dc;
            background: #f0f4ff;
        }

        .file-upload-wrapper.dragover {
            border-color: #3550dc;
            background: #e8ecf4;
        }

        .file-upload-icon {
            font-size: 40px;
            color: #3550dc;
            margin-bottom: 10px;
        }

        .file-upload-text {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .file-upload-hint {
            font-size: 12px;
            color: #9ca3af;
        }

        #fileInput {
            display: none;
        }

        .file-list {
            margin-top: 15px;
        }

        .file-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background: #f3f4f6;
            border-radius: 8px;
            margin-bottom: 8px;
            transition: all 0.3s;
        }

        .file-item:hover {
            background: #e5e7eb;
        }

        .file-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: white;
            font-size: 16px;
        }

        .file-icon.image { background: linear-gradient(135deg, #f59e0b, #f97316); }
        .file-icon.pdf { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .file-icon.word { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .file-icon.excel { background: linear-gradient(135deg, #10b981, #059669); }
        .file-icon.other { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 2px;
        }

        .file-size {
            font-size: 12px;
            color: #6b7280;
        }

        .file-actions {
            display: flex;
            gap: 8px;
        }

        .file-action-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            background: white;
            color: #6b7280;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .file-action-btn:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .file-action-btn.delete:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        /* Attachments Section */
        .attachments-section {
            margin-top: 20px;
        }

        .attachments-title {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .attachments-title i {
            color: #3550dc;
        }

        .attachments-list {
            margin-bottom: 15px;
        }

        /* Preview Modal */
        .preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .preview-content {
            background: white;
            border-radius: 15px;
            max-width: 90%;
            max-height: 90%;
            overflow: hidden;
            position: relative;
        }

        .preview-content img {
            max-width: 100%;
            max-height: 80vh;
            display: block;
        }

        .preview-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.3s;
        }

        .preview-close:hover {
            background: rgba(0, 0, 0, 0.7);
        }

        /* Assign Users */
        .assign-users-section {
            margin-top: 20px;
        }

        .assign-users-title {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .assign-users-title i {
            color: #3550dc;
        }

        .assign-users-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 15px;
        }

        .assign-user-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f3f4f6;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .assign-user-item.selected {
            background: #eff6ff;
            border-color: #3550dc;
        }

        .assign-user-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
            font-weight: 600;
        }

        .user1 { background: linear-gradient(135deg, #f59e0b, #f97316); }
        .user2 { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .user3 { background: linear-gradient(135deg, #10b981, #059669); }
        .user4 { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

        .assign-user-item span {
            font-size: 12px;
            color: #374151;
        }

        /* Subtasks */
        .subtasks-section {
            margin-top: 20px;
        }

        .subtasks-title {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subtasks-title i {
            color: #3550dc;
        }

        .subtask-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f3f4f6;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .subtask-checkbox {
            width: 16px;
            height: 16px;
            border: 2px solid #d1d5db;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: #10b981;
        }

        .subtask-text {
            flex: 1;
            font-size: 14px;
            color: #374151;
        }

        .add-subtask-form {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }

        .add-subtask-form input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
        }

        .add-subtask-form button {
            background: #3550dc;
            color: white;
            border: none;
            border-radius: 8px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .add-subtask-form button:hover {
            background: #2b44c9;
        }

        /* Progress Section */
        .progress-section {
            margin-top: 20px;
        }

        .progress-title {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-title i {
            color: #3550dc;
        }

        .progress-bar-container {
            margin-bottom: 12px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .progress-label-text {
            color: #6b7280;
        }

        .progress-percentage {
            font-weight: 600;
            color: #1f2937;
        }

        .progress-bar {
            height: 8px;
            background: #e8ecf4;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #3550dc;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        .progress-input {
            margin-top: 10px;
        }

        .progress-input input[type="range"] {
            width: 100%;
            height: 6px;
            -webkit-appearance: none;
            background: #e8ecf4;
            border-radius: 10px;
            outline: none;
        }

        .progress-input input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #3550dc;
            cursor: pointer;
            border: 3px solid white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        .btn {
            flex: 1;
            padding: 14px 20px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #6b7280;
        }

        .btn-cancel:hover {
            background: #e5e7eb;
        }

        .btn-save {
            background: #3550dc;
            color: white;
        }

        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(53, 80, 220, 0.25);
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .status-badge.todo {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.progress {
            background: #e8ecf4;
            color: #3550dc;
        }

        .status-badge.completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.selected {
            border-color: currentColor;
            transform: scale(1.05);
        }

        .status-selector {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Error Message */
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .container {
                padding: 0;
            }

            .content {
                padding: 15px;
            }

            .form-container {
                padding: 15px;
            }

            .form-row {
                flex-direction: column;
                gap: 16px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <button class="back-btn" onclick="window.history.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">Edit Tugas</div>
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

            <form action="" method="POST" class="form-container" enctype="multipart/form-data">
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
                    <input type="file" id="fileInput" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" onchange="handleFileSelect(event)">
                    
                    <!-- New Files List -->
                    <div class="file-list" id="newFilesList"></div>
                </div>

                <!-- Assign Users -->
                <div class="assign-users-section">
                    <div class="assign-users-title">
                        <i class="fas fa-users"></i>
                        Assign ke Anggota Tim
                    </div>
                    <div class="assign-users-list" id="assignUsersList">
                        <?php
                        $userIndex = 1;
                        $usersQuery->data_seek(0);
                        while ($userRow = $usersQuery->fetch_assoc()):
                            $initial = strtoupper(substr($userRow['username'], 0, 1));
                            $userClass = 'user' . ($userIndex % 4 + 1);
                            $isSelected = in_array($userRow['username'], $assignedUsersArray);
                        ?>
                            <div class="assign-user-item <?= $isSelected ? 'selected' : '' ?>" 
                                 data-user-id="<?= $userRow['id'] ?>" 
                                 data-user-name="<?= $userRow['username'] ?>"
                                 onclick="toggleUserSelection(this)">
                                <div class="assign-user-avatar <?= $userClass ?>"><?= $initial ?></div>
                                <span><?= htmlspecialchars($userRow['username']) ?></span>
                            </div>
                        <?php 
                            $userIndex++;
                        endwhile; 
                        ?>
                    </div>
                    <input type="hidden" id="assignedUsers" name="assigned_users[]" value="<?= implode(',', $assignedUsersArray) ?>">
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
                                <div class="subtask-checkbox <?= $subtask['completed'] ? 'checked' : '' ?>" onclick="toggleSubtask(this)">
                                    <?php if ($subtask['completed']): ?>
                                        <i class="fas fa-check"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="subtask-text"><?= htmlspecialchars($subtask['text']) ?></div>
                                <?php if (!empty($subtask['assigned'])): ?>
                                    <small style="color: #6b7280; font-size: 11px;">
                                        (<?= htmlspecialchars($subtask['assigned']) ?>)
                                    </small>
                                <?php endif; ?>
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

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="window.history.back()">Batal</button>
                    <button type="submit" class="btn btn-save">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Preview Modal -->
    <div class="preview-modal" id="previewModal">
        <div class="preview-content">
            <button class="preview-close" onclick="closePreview()">
                <i class="fas fa-times"></i>
            </button>
            <img id="previewImage" src="" alt="Preview">
        </div>
    </div>

    <script>
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
        }

        // Progress update
        function updateProgress(value) {
            document.getElementById('progressValue').textContent = value + '%';
            document.getElementById('progressFill').style.width = value + '%';
            document.getElementById('progressHidden').value = value;
        }

        // User selection
        let selectedUsers = <?= json_encode($assignedUsersArray) ?>;

        function toggleUserSelection(element) {
            const userName = element.getAttribute('data-user-name');
            const index = selectedUsers.indexOf(userName);
            
            if (index > -1) {
                selectedUsers.splice(index, 1);
                element.classList.remove('selected');
            } else {
                selectedUsers.push(userName);
                element.classList.add('selected');
            }
            
            document.getElementById('assignedUsers').value = selectedUsers.join(',');
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
        }

        function toggleSubtask(element) {
            const subtaskText = element.nextElementSibling.textContent;
            const index = subtasks.findIndex(s => s.text === subtaskText);
            
            if (index > -1) {
                subtasks[index].completed = !subtasks[index].completed;
                element.classList.toggle('checked');
                
                if (subtasks[index].completed) {
                    element.innerHTML = '<i class="fas fa-check"></i>';
                } else {
                    element.innerHTML = '';
                }
            }
        }

        function updateSubtasks() {
            const container = document.getElementById('subtasksContainer');
            container.innerHTML = '';
            
            subtasks.forEach(subtask => {
                const div = document.createElement('div');
                div.className = 'subtask-item';
                div.innerHTML = `
                    <div class="subtask-checkbox ${subtask.completed ? 'checked' : ''}" onclick="toggleSubtask(this)">
                        ${subtask.completed ? '<i class="fas fa-check"></i>' : ''}
                    </div>
                    <div class="subtask-text">${escapeHtml(subtask.text)}</div>
                    ${subtask.assigned ? `<small style="color: #6b7280; font-size: 11px;">(${escapeHtml(subtask.assigned)})</small>` : ''}
                `;
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

        // Initialize subtasks hidden field
        updateSubtasks();

        // File Upload Functions
        let filesToUpload = [];
        let filesToDelete = [];

        function handleFileSelect(event) {
            const files = event.target.files;
            handleFiles(files);
        }

        function handleDragOver(event) {
            event.preventDefault();
            event.stopPropagation();
            document.getElementById('fileUploadArea').classList.add('dragover');
        }

        function handleDragLeave(event) {
            event.preventDefault();
            event.stopPropagation();
            document.getElementById('fileUploadArea').classList.remove('dragover');
        }

        function handleDrop(event) {
            event.preventDefault();
            event.stopPropagation();
            document.getElementById('fileUploadArea').classList.remove('dragover');
            
            const files = event.dataTransfer.files;
            handleFiles(files);
        }

        function handleFiles(files) {
            const newFilesList = document.getElementById('newFilesList');
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                // Validasi ukuran file (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert(`File ${file.name} terlalu besar. Maksimal 5MB`);
                    continue;
                }
                
                // Validasi tipe file
                const allowedTypes = [
                    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/plain'
                ];
                
                if (!allowedTypes.includes(file.type)) {
                    alert(`Tipe file ${file.name} tidak diizinkan`);
                    continue;
                }
                
                filesToUpload.push(file);
                
                // Tambahkan ke daftar tampilan
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <div class="file-icon ${getFileIconClass(file.type)}">
                        <i class="${getFileIcon(file.type)}"></i>
                    </div>
                    <div class="file-info">
                        <div class="file-name">${escapeHtml(file.name)}</div>
                        <div class="file-size">${formatFileSize(file.size)}</div>
                    </div>
                    <div class="file-actions">
                        <button type="button" class="file-action-btn delete" onclick="removeNewFile(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;
                newFilesList.appendChild(fileItem);
            }
            
            // Update file input
            updateFileInput();
        }

        function updateFileInput() {
            const dataTransfer = new DataTransfer();
            filesToUpload.forEach(file => {
                dataTransfer.items.add(file);
            });
            document.getElementById('fileInput').files = dataTransfer.files;
        }

        function removeNewFile(button) {
            const fileItem = button.closest('.file-item');
            const fileName = fileItem.querySelector('.file-name').textContent;
            
            // Hapus dari array filesToUpload
            filesToUpload = filesToUpload.filter(file => file.name !== fileName);
            
            // Hapus dari tampilan
            fileItem.remove();
            
            // Update file input
            updateFileInput();
        }

        function deleteAttachment(fileName) {
            if (confirm('Apakah Anda yakin ingin menghapus lampiran ini?')) {
                // Tambahkan ke daftar file yang akan dihapus
                filesToDelete.push(fileName);
                
                // Update hidden input
                document.getElementById('deleteAttachments').value = filesToDelete.join(',');
                
                // Hapus dari tampilan
                const fileItem = document.querySelector(`.file-item[data-file-name="${fileName}"]`);
                if (fileItem) {
                    fileItem.style.opacity = '0.5';
                    fileItem.querySelector('.file-actions').innerHTML = '<span style="color: #dc2626; font-size: 12px;">Akan dihapus</span>';
                }
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
                formData.append('assigned_users[]', document.getElementById('assignedUsers').value);
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
    </script>
</body>
</html>
