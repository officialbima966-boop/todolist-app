<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$username = $_SESSION['admin'];
$taskId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($taskId <= 0) {
    echo "<script>alert('Tugas tidak ditemukan'); window.location.href='tasks.php';</script>";
    exit;
}

// Ambil data user yang login
$userQuery = $mysqli->query("SELECT * FROM users WHERE username = '$username'");
$currentUser = $userQuery->fetch_assoc();

// Ambil data tugas
$stmt = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->bind_param("i", $taskId);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();

if (!$task) {
    echo "<script>alert('Tugas tidak ditemukan'); window.location.href='tasks.php';</script>";
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Polling untuk data real-time
    if ($_POST['action'] === 'get_task_data') {
        // Return current task data for polling
        $currentSubtasks = [];
        if (!empty($task['subtasks'])) {
            $currentSubtasks = json_decode($task['subtasks'], true);
            
            // Jika decode gagal, format ulang
            if ($currentSubtasks === null) {
                $subtasksArray = explode(',', $task['subtasks']);
                $currentSubtasks = [];
                foreach ($subtasksArray as $text) {
                    $text = trim($text);
                    if (!empty($text)) {
                        $currentSubtasks[] = [
                            'text' => $text,
                            'assigned' => '',
                            'completed' => false
                        ];
                    }
                }
            }
            
            if (!is_array($currentSubtasks)) {
                $currentSubtasks = [];
            }
        }

        echo json_encode([
            'success' => true,
            'task' => [
                'id' => $task['id'],
                'title' => $task['title'],
                'progress' => $task['progress'],
                'status' => $task['status'],
                'category' => $task['category'],
                'subtasks' => $currentSubtasks,
                'tasks_completed' => $task['tasks_completed'],
                'tasks_total' => $task['tasks_total'],
                'note' => $task['note'],
                'start_date' => $task['start_date'],
                'end_date' => $task['end_date'],
                'assigned_users' => $task['assigned_users'],
                'attachments' => $task['attachments']
            ]
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'update_progress') {
        $taskId = (int)$_POST['taskId'];
        $progress = (int)$_POST['progress'];
        
        $category = 'Belum Dijalankan';
        $status = 'todo';
        
        if ($progress > 0 && $progress < 100) {
            $category = 'Sedang Berjalan';
            $status = 'progress';
        } elseif ($progress === 100) {
            $category = 'Selesai';
            $status = 'completed';
        }
        
        $sql = "UPDATE tasks SET progress = $progress, category = '$category', status = '$status' WHERE id = $taskId";
        
        if ($mysqli->query($sql)) {
            echo json_encode(['success' => true, 'progress' => $progress, 'category' => $category, 'status' => $status]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'add_comment') {
        $taskId = (int)$_POST['taskId'];
        $comment = $mysqli->real_escape_string($_POST['comment']);
        $userId = $currentUser['id'];
        $username = $currentUser['username'];
        
        $sql = "INSERT INTO task_comments (task_id, user_id, username, comment) 
                VALUES ($taskId, $userId, '$username', '$comment')";
        
        if ($mysqli->query($sql)) {
            $mysqli->query("UPDATE tasks SET comments = comments + 1 WHERE id = $taskId");
            
            $newCommentQuery = $mysqli->query("SELECT * FROM task_comments WHERE id = LAST_INSERT_ID()");
            $newComment = $newCommentQuery->fetch_assoc();
            
            echo json_encode([
                'success' => true, 
                'username' => $username,
                'comment' => $comment,
                'created_at' => $newComment['created_at'],
                'commentId' => $newComment['id']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'toggle_subtask') {
        $index = (int)$_POST['index'];
        $username = $_POST['username'] ?? '';
        $taskId = (int)$_POST['taskId'];

        // Get current subtasks
        $taskQuery = $mysqli->query("SELECT subtasks FROM tasks WHERE id = $taskId");
        $taskData = $taskQuery->fetch_assoc();
        $subtasks = json_decode($taskData['subtasks'], true);

        // Jika decode gagal, format ulang
        if ($subtasks === null) {
            $subtasksArray = explode(',', $taskData['subtasks']);
            $subtasks = [];
            foreach ($subtasksArray as $text) {
                $text = trim($text);
                if (!empty($text)) {
                    $subtasks[] = [
                        'text' => $text,
                        'assigned' => '',
                        'completed_by' => '',
                        'completed' => false
                    ];
                }
            }
        }

        if (!is_array($subtasks)) {
            $subtasks = [];
        }

        if (isset($subtasks[$index]) && !empty($username)) {
            // Initialize completed_by as array
            if (!isset($subtasks[$index]['completed_by'])) {
                $subtasks[$index]['completed_by'] = [];
            } else if (!is_array($subtasks[$index]['completed_by'])) {
                $completedStr = $subtasks[$index]['completed_by'];
                $subtasks[$index]['completed_by'] = !empty($completedStr) 
                    ? array_map('trim', explode(',', $completedStr)) 
                    : [];
            }

            // Toggle user completion
            $completedBy = &$subtasks[$index]['completed_by'];
            $userIndex = array_search($username, $completedBy);
            
            if ($userIndex !== false) {
                // Remove user from completed list
                array_splice($completedBy, $userIndex, 1);
            } else {
                // Add user to completed list
                $completedBy[] = $username;
            }

            // Check if all assigned users completed
            $assignedUsers = [];
            if (isset($subtasks[$index]['assigned'])) {
                if (is_array($subtasks[$index]['assigned'])) {
                    $assignedUsers = $subtasks[$index]['assigned'];
                } else {
                    $assignedUsers = array_map('trim', explode(',', $subtasks[$index]['assigned']));
                }
            }

            // Subtask is completed only if all assigned users completed
            $subtasks[$index]['completed'] = count($assignedUsers) > 0 && 
                count(array_diff($assignedUsers, $completedBy)) === 0;

            // Save back to database
            $subtasksJson = json_encode($subtasks);
            $subtasksEncoded = $mysqli->real_escape_string($subtasksJson);
            $mysqli->query("UPDATE tasks SET subtasks = '$subtasksEncoded' WHERE id = $taskId");

            // Calculate progress
            $total = count($subtasks);
            $completed = count(array_filter($subtasks, function($s) {
                return isset($s['completed']) ? $s['completed'] : false;
            }));
            $progress = $total > 0 ? round(($completed / $total) * 100) : 0;

            $category = 'Belum Dijalankan';
            $status = 'todo';

            if ($progress > 0 && $progress < 100) {
                $category = 'Sedang Berjalan';
                $status = 'progress';
            } elseif ($progress === 100) {
                $category = 'Selesai';
                $status = 'completed';
            }

            $mysqli->query("UPDATE tasks SET progress = $progress, category = '$category', status = '$status', tasks_completed = $completed, tasks_total = $total WHERE id = $taskId");

            echo json_encode([
                'success' => true,
                'progress' => $progress,
                'completed' => $completed,
                'total' => $total,
                'subtasks' => $subtasks
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Subtask not found']);
        }
        exit;
    }

    if ($_POST['action'] === 'upload_attachments') {
        $taskId = (int)$_POST['taskId'];
        $uploadDir = '/uploads/tasks/';

        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $uploadedFiles = [];
        $errors = [];

        if (isset($_FILES['files'])) {
            $files = $_FILES['files'];

            // Handle single file upload (files is not an array)
            if (!is_array($files['name'])) {
                $files = [
                    'name' => [$files['name']],
                    'type' => [$files['type']],
                    'tmp_name' => [$files['tmp_name']],
                    'error' => [$files['error']],
                    'size' => [$files['size']]
                ];
            }

            for ($i = 0; $i < count($files['name']); $i++) {
                $fileName = $files['name'][$i];
                $fileTmp = $files['tmp_name'][$i];
                $fileError = $files['error'][$i];
                $fileSize = $files['size'][$i];

                if ($fileError === UPLOAD_ERR_OK) {
                    // Generate unique filename
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $uniqueName = uniqid() . '_' . time() . '.' . $fileExtension;
                    $filePath = $uploadDir . $uniqueName;

                    // Allowed file types
                    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                    if (!in_array($fileExtension, $allowedTypes)) {
                        $errors[] = "File type not allowed: $fileName";
                        continue;
                    }

                    // Max file size (10MB)
                    if ($fileSize > 10 * 1024 * 1024) {
                        $errors[] = "File too large: $fileName";
                        continue;
                    }

                    if (move_uploaded_file($fileTmp, $filePath)) {
                        $uploadedFiles[] = [
                            'name' => $fileName,
                            'path' => 'uploads/tasks/' . $uniqueName,
                            'size' => $fileSize
                        ];
                    } else {
                        $errors[] = "Failed to upload: $fileName";
                    }
                } else {
                    $errors[] = "Upload error for: $fileName";
                }
            }
        }

        if (!empty($uploadedFiles)) {
            // Get current attachments
            $taskQuery = $mysqli->query("SELECT attachments FROM tasks WHERE id = $taskId");
            $taskData = $taskQuery->fetch_assoc();
            $currentAttachments = [];

            if (!empty($taskData['attachments'])) {
                $attachmentsData = json_decode($taskData['attachments'], true);

                if (is_array($attachmentsData) && count($attachmentsData) > 0) {
                    // JSON format - data lengkap
                    $currentAttachments = $attachmentsData;
                } else {
                    // Format lama - comma separated, convert to array format
                    $attachments = explode(',', $taskData['attachments']);
                    $currentAttachments = [];
                    foreach ($attachments as $filename) {
                        $filename = trim($filename);
                        if (!empty($filename)) {
                            $currentAttachments[] = [
                                'name' => $filename,
                                'path' => 'uploads/tasks/' . $filename,
                                'size' => 0 // Size not available for old format
                            ];
                        }
                    }
                }
            }

            // Merge new attachments
            $allAttachments = array_merge($currentAttachments, $uploadedFiles);
            $attachmentsJson = json_encode($allAttachments);
            $attachmentsEncoded = $mysqli->real_escape_string($attachmentsJson);

            $mysqli->query("UPDATE tasks SET attachments = '$attachmentsEncoded' WHERE id = $taskId");
        }

        echo json_encode([
            'success' => empty($errors),
            'uploaded' => $uploadedFiles,
            'errors' => $errors
        ]);
        exit;
    }

}

// Get initial comments
$commentsQuery = "SELECT * FROM task_comments WHERE task_id = $taskId ORDER BY created_at DESC";
$commentsResult = $mysqli->query($commentsQuery);

// Get initial subtasks from JSON field (consistent with polling)
$subtasks = [];
if (!empty($task['subtasks'])) {
    $subtasksData = json_decode($task['subtasks'], true);
    
    // Handle decode errors or old format
    if ($subtasksData === null) {
        $subtasksArray = explode(',', $task['subtasks']);
        $subtasks = [];
        foreach ($subtasksArray as $text) {
            $text = trim($text);
            if (!empty($text)) {
                $subtasks[] = [
                    'text' => $text,
                    'assigned' => '',
                    'completed' => false
                ];
            }
        }
    } elseif (is_array($subtasksData)) {
        $subtasks = $subtasksData;
    }
}

function formatTimeAgo($dateString) {
    $date = new DateTime($dateString);
    $now = new DateTime();
    $interval = $now->diff($date);
    
    if ($interval->y > 0) return $interval->y . ' tahun lalu';
    if ($interval->m > 0) return $interval->m . ' bulan lalu';
    if ($interval->d > 0) return $interval->d . ' hari lalu';
    if ($interval->h > 0) return $interval->h . ' jam lalu';
    if ($interval->i > 0) return $interval->i . ' menit lalu';
    return 'Baru saja';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Detail Task - <?= htmlspecialchars($task["title"]) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    * {
        margin: 0; padding: 0; box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
    }

    body {
        background: #f5f6fa;
        min-height: 100vh;
    }

    /* Header */
    .header {
        background: #3550dc;
        color: white;
        padding: 18px 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        position: sticky;
        top: 0;
        z-index: 10;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }

    .back-btn {
        font-size: 20px;
        cursor: pointer;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
        flex-shrink: 0;
    }

    .back-btn:hover {
        background: rgba(255, 255, 255, 0.25);
    }

    .header-title {
        font-size: 18px;
        font-weight: 600;
        flex: 1;
    }

    .container {
        padding: 20px;
        max-width: 800px;
        margin: 0 auto;
        padding-bottom: 40px;
    }

    /* Main Task Info */
    .task-header {
        margin-bottom: 20px;
    }

    .status-badge {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 10px;
        background: #e8ecf4;
        color: #3550dc;
    }

    .title {
        font-size: 22px;
        font-weight: 700;
        color: #1f2937;
        line-height: 1.4;
        margin-bottom: 15px;
    }

    /* Progress Section */
    .progress-box {
        background: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e5e7eb;
    }

    .progress-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .progress-label {
        font-size: 15px;
        font-weight: 600;
        color: #333;
    }

    .progress-percentage {
        font-size: 18px;
        font-weight: 700;
        color: #3550dc;
    }

    .progress-bar-bg {
        height: 8px;
        background: #e8ecf4;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 8px;
    }

    .progress-bar {
        height: 100%;
        background: #3550dc;
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    .progress-slider {
        margin-top: 15px;
    }

    .progress-slider input[type="range"] {
        width: 100%;
        height: 6px;
        border-radius: 3px;
        background: #e5e7eb;
        outline: none;
        -webkit-appearance: none;
    }

    .progress-slider input[type="range"]::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #3550dc;
        cursor: pointer;
        border: 2px solid white;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }

    .progress-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 10px;
        font-size: 13px;
        color: #6b7280;
    }

    .progress-stats {
        font-weight: 600;
        color: #3550dc;
    }

    /* Section Styling */
    .section {
        background: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e5e7eb;
    }

    .section-title {
        font-size: 16px;
        font-weight: 700;
        color: #333;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e5e7eb;
    }

    .section-title i {
        color: #3550dc;
        font-size: 14px;
    }

    /* Task Description */
    .task-description {
        color: #4b5563;
        line-height: 1.6;
        font-size: 14px;
        background: #f8faff;
        border-radius: 8px;
        padding: 15px;
        word-break: break-word;
    }

    /* Dates Section */
    .dates-section {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .date-box {
        background: #f8faff;
        padding: 15px;
        border-radius: 8px;
        border: 1px solid #e0e5ed;
    }

    .date-label {
        font-size: 11px;
        color: #6b7280;
        margin-bottom: 5px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .date-value {
        font-size: 15px;
        font-weight: 600;
        color: #333;
    }

    /* User Info */
    .user-info-box {
        background: #fff3cd;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        border: 1px solid #ffeaa7;
    }

    .user-info-label {
        font-size: 11px;
        color: #8a6d00;
        margin-bottom: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .user-info-content {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .avatar {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 15px;
        font-weight: 600;
        flex-shrink: 0;
    }

    .user-name {
        font-size: 15px;
        font-weight: 600;
        color: #333;
    }

    .user-role {
        font-size: 12px;
        color: #6b7280;
        font-style: italic;
    }

    /* Assigned Users */
    .assigned-users-label {
        font-size: 11px;
        color: #6b7280;
        margin-bottom: 10px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .assigned-users-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .assigned-user {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 15px;
        background: #f8faff;
        border-radius: 20px;
        border: 1px solid #e0e5ed;
        flex-shrink: 0;
        max-width: 100%;
    }

    .assigned-user-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 11px;
        font-weight: 600;
        flex-shrink: 0;
    }

    .assigned-user-name {
        font-size: 13px;
        font-weight: 500;
        color: #333;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Subtasks - NEW STYLE */
    .subtasks-section {
        margin-bottom: 20px;
    }

    .subtask-item {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 16px;
        background: #f8faff;
        border-radius: 12px;
        margin-bottom: 12px;
        border: 2px solid #e0e5ed;
        transition: all 0.3s;
    }

    .subtask-item:hover {
        border-color: #C7D2FE;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .subtask-content-full {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .subtask-title-text {
        font-size: 15px;
        font-weight: 600;
        color: #1F2937;
        transition: all 0.3s;
    }

    .subtask-title-text.all-completed {
        text-decoration: line-through;
        color: #9CA3AF;
        opacity: 0.7;
    }

    .subtask-users-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }

    .user-badge-detail {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: linear-gradient(135deg, #EEF2FF 0%, #E0E7FF 100%);
        color: #4F46E5;
        padding: 6px 12px;
        border-radius: 16px;
        font-size: 13px;
        font-weight: 600;
        border: 1.5px solid #C7D2FE;
        cursor: pointer;
        transition: all 0.2s;
    }

    .user-badge-detail:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
    }

    .user-badge-detail.completed {
        background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
        color: #059669;
        border-color: #6EE7B7;
        text-decoration: line-through;
    }

    .user-badge-detail.completed i {
        text-decoration: none;
    }

    /* Attachments */
    .attachments-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 12px;
        margin-top: 10px;
    }

    .attachment-item {
        transition: all 0.2s ease;
        border-radius: 8px;
        overflow: hidden;
    }

    .attachment-image {
        width: 100%;
        height: 100px;
        object-fit: cover;
        border-radius: 8px;
        cursor: pointer;
        border: 1px solid #e5e7eb;
    }

    .attachment-file {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: white;
        border-radius: 8px;
        padding: 15px;
        text-align: center;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        height: 130px;
        transition: all 0.3s;
    }

    .attachment-file:hover {
        background: #f8faff;
        border-color: #3550dc;
        transform: translateY(-2px);
    }

    .attachment-file-icon {
        font-size: 2.2rem;
        margin-bottom: 8px;
    }

    .attachment-file-icon .fa-file-pdf {
        color: #ef4444;
    }

    .attachment-file-icon .fa-file-word {
        color: #2563eb;
    }

    .attachment-file-icon .fa-image {
        color: #10b981;
    }

    .attachment-file-icon .fa-file-excel {
        color: #059669;
    }

    .attachment-file-name {
        font-size: 11px;
        color: #333;
        word-break: break-all;
        margin-bottom: 4px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        line-height: 1.3;
        max-height: 2.6em;
    }

    .attachment-file-type {
        font-size: 9px;
        color: #6b7280;
        text-transform: uppercase;
    }

    /* Comments */
    .comments-section {
        max-height: 300px;
        overflow-y: auto;
        margin-bottom: 15px;
        padding-right: 5px;
    }

    .comment-item {
        background: #f8faff;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 10px;
        border-left: 4px solid #3550dc;
    }

    .comment-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 8px;
    }

    .comment-user {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .comment-avatar {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 11px;
        font-weight: 600;
        flex-shrink: 0;
    }

    .comment-username {
        font-weight: 600;
        color: #3550dc;
        font-size: 13px;
    }

    .comment-time {
        font-size: 11px;
        color: #9ca3af;
        text-align: right;
        flex-shrink: 0;
    }

    .comment-text {
        color: #333;
        font-size: 13px;
        line-height: 1.5;
        padding-left: 40px;
        word-break: break-word;
    }

    .comment-input-group {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }

    .comment-input-group input {
        flex: 1;
        padding: 14px 18px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        transition: all 0.3s;
    }

    .comment-input-group input:focus {
        outline: none;
        border-color: #3550dc;
        box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
    }

    .comment-input-group button {
        padding: 14px 20px;
        background: #3550dc;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
        white-space: nowrap;
        font-size: 14px;
    }

    .comment-input-group button:hover {
        background: #2b44c9;
    }

    /* No Data States */
    .no-data {
        text-align: center;
        padding: 30px 20px;
        color: #9ca3af;
    }

    .no-data i {
        font-size: 2.5rem;
        margin-bottom: 12px;
        opacity: 0.5;
    }

    .no-data p {
        font-size: 13px;
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
        margin-top: 15px;
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

    /* Responsive */
    @media (max-width: 768px) {
        .container {
            padding: 16px;
        }
        
        .dates-section {
            grid-template-columns: 1fr;
        }
        
        .attachments-grid {
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 12px;
        }
        
        .header {
            padding: 16px;
        }
        
        .title {
            font-size: 20px;
        }
        
        .comment-input-group {
            flex-direction: column;
        }
        
        .comment-input-group input,
        .comment-input-group button {
            width: 100%;
        }
    }
</style>
</head>
<body>

<!-- Header Sederhana -->
<div class="header">
    <div class="back-btn" onclick="window.location.href='tasks.php'">
        <i class="fas fa-arrow-left"></i>
    </div>
    <div class="header-title">Detail Tugas</div>
</div>

<div class="container">
    <div class="task-header">
        <div id="statusBadge" class="status-badge <?= $task['status'] ?>">
            <?= $task['category'] ?>
        </div>
        <div class="title"><?= htmlspecialchars($task["title"]) ?></div>
    </div>

    <!-- Progress Section -->
    <div class="progress-box">
        <div class="progress-header">
            <div class="progress-label">Progress</div>
            <div class="progress-percentage" id="currentProgress"><?= $task["progress"] ?>%</div>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar" id="progressBar" style="width: <?= $task["progress"] ?>%"></div>
        </div>
        
        <div class="progress-slider">
            <input type="range" id="progressSlider" min="0" max="100" value="<?= $task["progress"] ?>">
            <div class="progress-info">
                <span>0%</span>
                <span class="progress-stats" id="progressStats">
                    <?= $task["tasks_completed"] ?>/<?= $task["tasks_total"] ?> selesai
                </span>
                <span>100%</span>
            </div>
        </div>
    </div>

    <!-- Description -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-align-left"></i>
            Deskripsi Tugas
        </div>
        <div class="task-description">
            <?= nl2br(htmlspecialchars($task["note"] ?: "Tidak ada deskripsi")) ?>
        </div>
    </div>

    <!-- Dates -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-calendar-alt"></i>
            Timeline Tugas
        </div>
        <div class="dates-section">
            <div class="date-box">
                <div class="date-label">Tanggal Mulai</div>
                <div class="date-value"><?= $task["start_date"] ? date('d/m/Y', strtotime($task["start_date"])) : '-' ?></div>
            </div>
            
            <div class="date-box">
                <div class="date-label">Tanggal Selesai</div>
                <div class="date-value"><?= $task["end_date"] ? date('d/m/Y', strtotime($task["end_date"])) : '-' ?></div>
            </div>
        </div>
    </div>

    <!-- Creator & Assigned Users -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-users"></i>
            Informasi Tim
        </div>
        
        <!-- Creator -->
        <div class="user-info-box">
            <div class="user-info-label">Pembuat Tugas</div>
            <div class="user-info-content">
                <div class="avatar">
                    <?= strtoupper(substr($task["created_by"], 0, 1)) ?>
                </div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($task["created_by"]) ?></div>
                    <div class="user-role">Pembuat Tugas</div>
                </div>
            </div>
        </div>

        <!-- Assigned Users -->
        <div>
            <div class="assigned-users-label">Ditugaskan Kepada</div>
            <div class="assigned-users-grid">
                <?php
                if (!empty($task["assigned_users"])) {
                    $assignedUsers = explode(',', $task["assigned_users"]);
                    foreach ($assignedUsers as $user) {
                        $user = trim($user);
                        if (empty($user)) continue;
                        
                        $initial = strtoupper(substr($user, 0, 1));
                        echo '<div class="assigned-user">';
                        echo '<div class="assigned-user-avatar">' . $initial . '</div>';
                        echo '<div class="assigned-user-name">' . htmlspecialchars($user) . '</div>';
                        echo '</div>';
                    }
                } else {
                    echo '<div class="no-data" style="padding: 20px 0;">';
                    echo '<i class="fas fa-user-friends"></i>';
                    echo '<p>Belum ada anggota yang ditugaskan</p>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Subtasks -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-tasks"></i>
            Subtasks / Pekerjaan
        </div>
        <div class="subtasks-section" id="subtasksSection">
            <?php
            if (count($subtasks) > 0) {
                foreach ($subtasks as $index => $subtask) {
                    // Pastikan subtask adalah array dengan format yang benar
                    if (is_array($subtask)) {
                        $subtaskText = isset($subtask['text']) ? $subtask['text'] : '';
                        $completed = isset($subtask['completed']) ? $subtask['completed'] : false;
                        
                        // Parse assigned users
                        $assignedUsers = [];
                        if (isset($subtask['assigned'])) {
                            if (is_array($subtask['assigned'])) {
                                $assignedUsers = $subtask['assigned'];
                            } else if (!empty($subtask['assigned'])) {
                                $assignedUsers = explode(',', $subtask['assigned']);
                            }
                        }
                        
                        // Parse completed_by users
                        $completedBy = [];
                        if (isset($subtask['completed_by'])) {
                            if (is_array($subtask['completed_by'])) {
                                $completedBy = $subtask['completed_by'];
                            } else if (!empty($subtask['completed_by'])) {
                                $completedBy = explode(',', $subtask['completed_by']);
                            }
                        }
                    } else {
                        // Jika bukan array, anggap sebagai string
                        $subtaskText = (string)$subtask;
                        $completed = false;
                        $assignedUsers = [];
                        $completedBy = [];
                    }
                    
                    if (empty($subtaskText)) continue;
                    
                    // Check if all users completed
                    $assignedUsersTrimmed = array_map('trim', $assignedUsers);
                    $completedByTrimmed = array_map('trim', $completedBy);
                    $assignedUsersTrimmed = array_filter($assignedUsersTrimmed);
                    $completedByTrimmed = array_filter($completedByTrimmed);
                    
                    $allCompleted = count($assignedUsersTrimmed) > 0 && 
                        count(array_diff($assignedUsersTrimmed, $completedByTrimmed)) === 0;
                    $titleClass = $allCompleted ? 'subtask-title-text all-completed' : 'subtask-title-text';
                    
                    echo '<div class="subtask-item" data-index="' . $index . '">';
                    echo '<div class="subtask-content-full">';
                    echo '<div class="' . $titleClass . '">' . htmlspecialchars($subtaskText) . '</div>';
                    
                    // Display user badges
                    if (count($assignedUsers) > 0) {
                        echo '<div class="subtask-users-badges">';
                        foreach ($assignedUsers as $user) {
                            $user = trim($user);
                            if (empty($user)) continue;
                            
                            $isCompleted = in_array($user, $completedBy);
                            $badgeClass = $isCompleted ? 'user-badge-detail completed' : 'user-badge-detail';
                            $checkIcon = $isCompleted ? '<i class="fas fa-check"></i> ' : '';
                            
                            echo '<span class="' . $badgeClass . '" onclick="toggleUserSubtask(' . $index . ', \'' . htmlspecialchars($user) . '\')">';
                            echo $checkIcon . htmlspecialchars($user);
                            echo '</span>';
                        }
                        echo '</div>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                }
            } else {
                echo '<div class="no-data">';
                echo '<i class="fas fa-tasks"></i>';
                echo '<p>Belum ada subtask</p>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <!-- Attachments -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-paperclip"></i>
            Lampiran (<span id="attachmentCount">
                <?php
                $attachmentCount = 0;
                if (!empty($task["attachments"])) {
                    $attachmentsData = json_decode($task["attachments"], true);
                    if (is_array($attachmentsData)) {
                        $attachmentCount = count($attachmentsData);
                    }
                }
                echo $attachmentCount;
                ?>
            </span>)
        </div>
        <div id="attachmentsSection">
            <?php
            if (!empty($task["attachments"])) {
                // Coba decode JSON dulu
                $attachmentsData = json_decode($task["attachments"], true);
                
                if (is_array($attachmentsData) && count($attachmentsData) > 0) {
                    // Format JSON - data lengkap
                    echo '<div class="attachments-grid">';
                    foreach ($attachmentsData as $attachment) {
                        // Skip jika bukan array atau tidak punya key 'name'
                        if (!is_array($attachment) || !isset($attachment['name'])) continue;
                        
                        $filename = $attachment['name'];
                        $filePath = $attachment['path'] ?? '';
                        $fileSize = $attachment['size'] ?? 0;

                        if (empty($filename)) continue;

                        // Prepend '../' to make path relative from admin/ directory
                        $fullFilePath = '../' . $filePath;

                        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $isImage = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
                        $safeFilename = strlen($filename) > 20 ? substr($filename, 0, 20) . '...' : $filename;

                        if ($isImage && !empty($filePath)) {
                            echo '<div class="attachment-item">';
                            echo '<img src="' . htmlspecialchars($fullFilePath) . '?t=' . time() . '" alt="' . htmlspecialchars($filename) . '" class="attachment-image" onclick="openImage(\'' . htmlspecialchars($fullFilePath) . '?t=' . time() . '\')">';
                            echo '</div>';
                        } else {
                            $icon = 'fa-file';
                            if ($fileExtension === 'pdf') $icon = 'fa-file-pdf';
                            elseif (in_array($fileExtension, ['doc', 'docx'])) $icon = 'fa-file-word';
                            elseif (in_array($fileExtension, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
                            elseif (in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'fa-image';
                            
                            $fileSizeText = $fileSize > 0 ? number_format($fileSize / 1024, 1) . ' KB' : '';
                            
                            echo '<div class="attachment-item">';
                            echo '<div class="attachment-file" onclick="window.open(\'' . htmlspecialchars($fullFilePath) . '\', \'_blank\')">';
                            echo '<div class="attachment-file-icon"><i class="fas ' . $icon . '"></i></div>';
                            echo '<div class="attachment-file-name">' . htmlspecialchars($safeFilename) . '</div>';
                            if ($fileSizeText) {
                                echo '<div class="attachment-file-type">' . $fileSizeText . '</div>';
                            } else {
                                echo '<div class="attachment-file-type">' . strtoupper($fileExtension) . '</div>';
                            }
                            echo '</div>';
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                } else {
                    // Format lama - comma separated
                    $attachments = explode(',', $task["attachments"]);
                    $uploadDir = '../uploads/tasks/';
                    
                    echo '<div class="attachments-grid">';
                    foreach ($attachments as $filename) {
                        $filename = trim($filename);
                        if (empty($filename)) continue;
                        
                        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $isImage = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
                        $fileUrl = $uploadDir . $filename;
                        $safeFilename = strlen($filename) > 20 ? substr($filename, 0, 20) . '...' : $filename;
                        
                        if ($isImage) {
                            echo '<div class="attachment-item">';
                            echo '<img src="' . htmlspecialchars($fileUrl) . '" alt="' . htmlspecialchars($filename) . '" class="attachment-image" onclick="openImage(\'' . htmlspecialchars($fileUrl) . '\')">';
                            echo '</div>';
                        } else {
                            $icon = 'fa-file';
                            if ($fileExtension === 'pdf') $icon = 'fa-file-pdf';
                            elseif (in_array($fileExtension, ['doc', 'docx'])) $icon = 'fa-file-word';
                            elseif (in_array($fileExtension, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
                            
                            echo '<div class="attachment-item">';
                            echo '<div class="attachment-file" onclick="window.open(\'' . htmlspecialchars($fileUrl) . '\', \'_blank\')">';
                            echo '<div class="attachment-file-icon"><i class="fas ' . $icon . '"></i></div>';
                            echo '<div class="attachment-file-name">' . htmlspecialchars($safeFilename) . '</div>';
                            echo '<div class="attachment-file-type">' . strtoupper($fileExtension) . '</div>';
                            echo '</div>';
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                }
            } else {
                echo '<div class="no-data">';
                echo '<i class="fas fa-paperclip"></i>';
                echo '<p>Tidak ada lampiran</p>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- File Upload -->
        <div class="file-upload-wrapper" id="fileUploadWrapper">
            <div class="file-upload-icon">
                <i class="fas fa-cloud-upload-alt"></i>
            </div>
            <div class="file-upload-text">Klik untuk upload file</div>
            <div class="file-upload-hint">atau drag & drop file di sini</div>
        </div>
        <input type="file" id="fileInput" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">
    </div>

    <!-- Comments -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-comments"></i>
            Komentar (<span id="commentCount">
                <?= $commentsResult->num_rows ?>
            </span>)
        </div>
        
        <div class="comments-section" id="commentsSection">
            <?php
            if ($commentsResult->num_rows > 0) {
                while ($comment = $commentsResult->fetch_assoc()) {
                    $initial = strtoupper(substr($comment['username'], 0, 1));
                    $timeAgo = formatTimeAgo($comment['created_at']);
                    echo '<div class="comment-item">';
                    echo '<div class="comment-header">';
                    echo '<div class="comment-user">';
                    echo '<div class="comment-avatar">' . $initial . '</div>';
                    echo '<div class="comment-username">' . htmlspecialchars($comment['username']) . '</div>';
                    echo '</div>';
                    echo '<div class="comment-time">' . $timeAgo . '</div>';
                    echo '</div>';
                    echo '<div class="comment-text">' . htmlspecialchars($comment['comment']) . '</div>';
                    echo '</div>';
                }
            } else {
                echo '<div class="no-data">';
                echo '<i class="fas fa-comment"></i>';
                echo '<p>Belum ada komentar</p>';
                echo '</div>';
            }
            ?>
        </div>
        
        <div class="comment-input-group">
            <input type="text" id="commentInput" placeholder="Tulis komentar..." onkeypress="if(event.key === 'Enter') addComment()">
            <button onclick="addComment()">
                <i class="fas fa-paper-plane"></i> Kirim
            </button>
        </div>
    </div>
</div>

<script>
    let currentTaskId = <?= $taskId ?>;
    let currentProgress = <?= $task["progress"] ?>;
    let lastUpdateTime = Date.now();
    let pollingInterval;

    // Progress Slider
    const progressSlider = document.getElementById('progressSlider');
    const progressBar = document.getElementById('progressBar');
    const currentProgressElement = document.getElementById('currentProgress');
    const statusBadge = document.getElementById('statusBadge');
    const progressStats = document.getElementById('progressStats');

    progressSlider.addEventListener('input', function() {
        const progress = this.value;
        progressBar.style.width = progress + '%';
        currentProgressElement.textContent = progress + '%';
    });

    progressSlider.addEventListener('change', async function() {
        const newProgress = parseInt(this.value);
        await updateProgress(newProgress);
    });

    async function updateProgress(progress) {
        try {
            const formData = new FormData();
            formData.append('action', 'update_progress');
            formData.append('taskId', currentTaskId);
            formData.append('progress', progress);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                currentProgress = progress;
                statusBadge.textContent = result.category;
                statusBadge.className = 'status-badge ' + result.status;
                alert('Progress berhasil diperbarui!');
            } else {
                alert('Gagal memperbarui progress: ' + result.error);
                progressSlider.value = currentProgress;
                progressBar.style.width = currentProgress + '%';
                currentProgressElement.textContent = currentProgress + '%';
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat memperbarui progress');
        }
    }

    async function addComment() {
        const commentInput = document.getElementById('commentInput');
        const comment = commentInput.value.trim();

        if (!comment) {
            alert('Komentar tidak boleh kosong');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'add_comment');
            formData.append('taskId', currentTaskId);
            formData.append('comment', comment);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                commentInput.value = '';
                
                const commentsSection = document.getElementById('commentsSection');
                const noData = commentsSection.querySelector('.no-data');
                if (noData) {
                    noData.remove();
                }
                
                const initial = result.username.charAt(0).toUpperCase();
                const newCommentHTML = `
                    <div class="comment-item">
                        <div class="comment-header">
                            <div class="comment-user">
                                <div class="comment-avatar">${initial}</div>
                                <div class="comment-username">${result.username}</div>
                            </div>
                            <div class="comment-time">Baru saja</div>
                        </div>
                        <div class="comment-text">${comment}</div>
                    </div>
                `;
                
                commentsSection.insertAdjacentHTML('afterbegin', newCommentHTML);
                
                const commentCount = document.getElementById('commentCount');
                commentCount.textContent = parseInt(commentCount.textContent) + 1;
                
                commentsSection.scrollTop = 0;
            } else {
                alert('Gagal menambahkan komentar: ' + result.error);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menambahkan komentar');
        }
    }

    function renderSubtasks(subtasks) {
        const subtasksSection = document.getElementById('subtasksSection');

        if (subtasks.length === 0) {
            subtasksSection.innerHTML = `
                <div class="no-data">
                    <i class="fas fa-tasks"></i>
                    <p>Belum ada subtask</p>
                </div>
            `;
            progressStats.textContent = '0/0 selesai';
            return;
        }

        const completed = subtasks.filter(st => st.completed).length;
        const total = subtasks.length;

        progressStats.textContent = `${completed}/${total} selesai`;

        subtasksSection.innerHTML = subtasks.map((subtask, index) => {
            // Parse assigned users
            let assignedUsers = [];
            if (Array.isArray(subtask.assigned)) {
                assignedUsers = subtask.assigned.map(u => String(u).trim()).filter(u => u);
            } else if (subtask.assigned) {
                assignedUsers = subtask.assigned.split(',').map(u => u.trim()).filter(u => u);
            }
            
            // Parse completed_by users
            let completedBy = [];
            if (Array.isArray(subtask.completed_by)) {
                completedBy = subtask.completed_by.map(u => String(u).trim()).filter(u => u);
            } else if (subtask.completed_by) {
                completedBy = subtask.completed_by.split(',').map(u => u.trim()).filter(u => u);
            }
            
            // Check if all users completed
            const allCompleted = assignedUsers.length > 0 && 
                assignedUsers.every(user => completedBy.includes(user));
            const titleClass = allCompleted ? 'subtask-title-text all-completed' : 'subtask-title-text';
            
            // Generate user badges HTML
            let userBadgesHtml = '';
            if (assignedUsers.length > 0) {
                userBadgesHtml = '<div class="subtask-users-badges">';
                assignedUsers.forEach(user => {
                    const isCompleted = completedBy.includes(user);
                    const badgeClass = isCompleted ? 'user-badge-detail completed' : 'user-badge-detail';
                    const checkIcon = isCompleted ? '<i class="fas fa-check"></i> ' : '';
                    userBadgesHtml += `
                        <span class="${badgeClass}" onclick="toggleUserSubtask(${index}, '${escapeHtml(user)}')">
                            ${checkIcon}${escapeHtml(user)}
                        </span>
                    `;
                });
                userBadgesHtml += '</div>';
            }

            return `
                <div class="subtask-item" data-index="${index}">
                    <div class="subtask-content-full">
                        <div class="${titleClass}">${escapeHtml(subtask.text)}</div>
                        ${userBadgesHtml}
                    </div>
                </div>
            `;
        }).join('');
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Toggle user completion for subtask
    async function toggleUserSubtask(index, username) {
        try {
            const formData = new FormData();
            formData.append('action', 'toggle_subtask');
            formData.append('index', index);
            formData.append('username', username);
            formData.append('taskId', currentTaskId);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                progressSlider.value = result.progress;
                progressBar.style.width = result.progress + '%';
                currentProgressElement.textContent = result.progress + '%';
                progressStats.textContent = `${result.completed}/${result.total} selesai`;
                
                // Render subtasks with new data
                renderSubtasks(result.subtasks);
                
                let category = 'Belum Dijalankan';
                let status = 'todo';
                
                if (result.progress > 0 && result.progress < 100) {
                    category = 'Sedang Berjalan';
                    status = 'progress';
                } else if (result.progress === 100) {
                    category = 'Selesai';
                    status = 'completed';
                }
                
                statusBadge.textContent = category;
                statusBadge.className = 'status-badge ' + status;
                currentProgress = result.progress;
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat mengupdate subtask');
        }
    }

    function openImage(imageUrl) {
        window.open(imageUrl, '_blank', 'width=800,height=600');
    }

    // Polling for real-time updates
    async function pollTaskData() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_task_data');
            formData.append('taskId', currentTaskId);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                const taskData = result.task;

                // Update progress if changed
                if (taskData.progress !== currentProgress) {
                    currentProgress = taskData.progress;
                    progressSlider.value = currentProgress;
                    progressBar.style.width = currentProgress + '%';
                    currentProgressElement.textContent = currentProgress + '%';

                    // Update status badge
                    statusBadge.textContent = taskData.category;
                    statusBadge.className = 'status-badge ' + taskData.status;
                }

                // Update subtasks if changed
                const newSubtasks = taskData.subtasks;
                const existingSubtasks = Array.from(document.querySelectorAll('.subtask-item')).map(item => {
                    const index = parseInt(item.dataset.index);
                    const title = item.querySelector('.subtask-title-text');
                    const badges = item.querySelectorAll('.user-badge-detail');
                    
                    // Extract user completion data
                    const assignedUsers = Array.from(badges).map(badge => {
                        return badge.textContent.trim().replace(/^✔\s*/, '');
                    });
                    
                    const completedBy = Array.from(badges).filter(badge => 
                        badge.classList.contains('completed')
                    ).map(badge => badge.textContent.trim().replace(/^✔\s*/, ''));
                    
                    const allCompleted = assignedUsers.length > 0 && 
                        assignedUsers.every(user => completedBy.includes(user));
                    
                    return {
                        text: title ? title.textContent : '',
                        completed: allCompleted,
                        assigned: assignedUsers,
                        completed_by: completedBy
                    };
                });

                if (JSON.stringify(newSubtasks) !== JSON.stringify(existingSubtasks)) {
                    renderSubtasks(newSubtasks);
                }

                // Update progress stats
                progressStats.textContent = `${taskData.tasks_completed}/${taskData.tasks_total} selesai`;

                lastUpdateTime = Date.now();
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }

    function startPolling() {
        // Poll every 5 seconds
        pollingInterval = setInterval(pollTaskData, 5000);
    }

    function stopPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
        }
    }

    // File Upload Functionality
    const fileUploadWrapper = document.getElementById('fileUploadWrapper');
    const fileInput = document.getElementById('fileInput');
    const attachmentsSection = document.getElementById('attachmentsSection');
    const attachmentCount = document.getElementById('attachmentCount');

    // Click to upload
    fileUploadWrapper.addEventListener('click', () => {
        fileInput.click();
    });

    // File input change
    fileInput.addEventListener('change', (e) => {
        const files = e.target.files;
        if (files.length > 0) {
            uploadFiles(files);
        }
    });

    // Drag and drop
    fileUploadWrapper.addEventListener('dragover', (e) => {
        e.preventDefault();
        fileUploadWrapper.classList.add('dragover');
    });

    fileUploadWrapper.addEventListener('dragleave', () => {
        fileUploadWrapper.classList.remove('dragover');
    });

    fileUploadWrapper.addEventListener('drop', (e) => {
        e.preventDefault();
        fileUploadWrapper.classList.remove('dragover');

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            uploadFiles(files);
        }
    });

    async function uploadFiles(files) {
        const formData = new FormData();
        formData.append('action', 'upload_attachments');
        formData.append('taskId', currentTaskId);

        // Handle multiple files
        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                if (result.uploaded && result.uploaded.length > 0) {
                    // Update attachments section
                    updateAttachmentsSection(result.uploaded);
                    alert(`Berhasil upload ${result.uploaded.length} file!`);
                }

                if (result.errors && result.errors.length > 0) {
                    alert('Beberapa file gagal diupload:\n' + result.errors.join('\n'));
                }
            } else {
                alert('Gagal upload file: ' + (result.errors ? result.errors.join('\n') : 'Unknown error'));
            }
        } catch (error) {
            console.error('Upload error:', error);
            alert('Terjadi kesalahan saat upload file');
        }
    }

    function updateAttachmentsSection(newAttachments) {
        // Get current attachments count
        let currentCount = parseInt(attachmentCount.textContent) || 0;
        currentCount += newAttachments.length;
        attachmentCount.textContent = currentCount;

        // Remove no-data message if exists
        const noData = attachmentsSection.querySelector('.no-data');
        if (noData) {
            noData.remove();
        }

        // Create or get attachments grid
        let attachmentsGrid = attachmentsSection.querySelector('.attachments-grid');
        if (!attachmentsGrid) {
            attachmentsGrid = document.createElement('div');
            attachmentsGrid.className = 'attachments-grid';
            attachmentsSection.appendChild(attachmentsGrid);
        }

        // Add new attachments
        newAttachments.forEach(attachment => {
            const fileExtension = attachment.name.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(fileExtension);
            const safeFilename = attachment.name.length > 20 ? attachment.name.substring(0, 20) + '...' : attachment.name;

            let attachmentHTML = '';

            if (isImage) {
                attachmentHTML = `
                    <div class="attachment-item">
                        <img src="../${attachment.path}?t=${Date.now()}" alt="${attachment.name}" class="attachment-image" onclick="openImage('../${attachment.path}?t=${Date.now()}')">
                    </div>
                `;
            } else {
                let icon = 'fa-file';
                if (fileExtension === 'pdf') icon = 'fa-file-pdf';
                else if (['doc', 'docx'].includes(fileExtension)) icon = 'fa-file-word';
                else if (['xls', 'xlsx'].includes(fileExtension)) icon = 'fa-file-excel';

                const fileSizeText = attachment.size > 0 ? (attachment.size / 1024).toFixed(1) + ' KB' : '';

                attachmentHTML = `
                    <div class="attachment-item">
                        <div class="attachment-file" onclick="window.open('../${attachment.path}', '_blank')">
                            <div class="attachment-file-icon"><i class="fas ${icon}"></i></div>
                            <div class="attachment-file-name">${safeFilename}</div>
                            <div class="attachment-file-type">${fileSizeText || fileExtension.toUpperCase()}</div>
                        </div>
                    </div>
                `;
            }

            attachmentsGrid.insertAdjacentHTML('beforeend', attachmentHTML);
        });
    }

    // Start polling when page loads
    window.addEventListener('load', startPolling);

    // Stop polling when page unloads
    window.addEventListener('beforeunload', stopPolling);
</script>

</body>
</html>