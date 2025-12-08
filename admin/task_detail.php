<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$admin = $_SESSION['admin'];
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo "<script>alert('Tugas tidak ditemukan'); window.location.href='tasks.php';</script>";
    exit;
}

// Ambil data user yang login
$userQuery = $mysqli->query("SELECT * FROM users WHERE username = '$admin'");
$currentUser = $userQuery->fetch_assoc();

// Ambil data tugas
$stmt = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$tugas = $stmt->get_result()->fetch_assoc();

if (!$tugas) {
    echo "<script>alert('Tugas tidak ditemukan'); window.location.href='tasks.php';</script>";
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
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
    
    if ($_POST['action'] === 'update_dates') {
        $taskId = (int)$_POST['taskId'];
        $startDate = $mysqli->real_escape_string($_POST['startDate']);
        $endDate = $mysqli->real_escape_string($_POST['endDate']);
        
        $sql = "UPDATE tasks SET start_date = '$startDate', end_date = '$endDate' WHERE id = $taskId";
        
        if ($mysqli->query($sql)) {
            echo json_encode(['success' => true, 'startDate' => $startDate, 'endDate' => $endDate]);
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
            // Update comment count
            $mysqli->query("UPDATE tasks SET comments = comments + 1 WHERE id = $taskId");
            
            // Get the new comment with timestamp
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
    
    if ($_POST['action'] === 'get_comments') {
        $taskId = (int)$_POST['taskId'];
        $sql = "SELECT * FROM task_comments WHERE task_id = $taskId ORDER BY created_at DESC";
        $result = $mysqli->query($sql);
        $comments = [];
        
        while ($row = $result->fetch_assoc()) {
            $comments[] = $row;
        }
        
        echo json_encode(['success' => true, 'comments' => $comments]);
        exit;
    }
    
    if ($_POST['action'] === 'get_subtasks') {
        $taskId = (int)$_POST['taskId'];
        $sql = "SELECT * FROM task_subtasks WHERE task_id = $taskId ORDER BY created_at ASC";
        $result = $mysqli->query($sql);
        $subtasks = [];
        
        while ($row = $result->fetch_assoc()) {
            $subtasks[] = $row;
        }
        
        echo json_encode(['success' => true, 'subtasks' => $subtasks]);
        exit;
    }
    
    if ($_POST['action'] === 'toggle_subtask') {
        $subtaskId = (int)$_POST['subtaskId'];
        $isCompleted = (int)$_POST['isCompleted'];
        $taskId = (int)$_POST['taskId'];
        $completedBy = $mysqli->real_escape_string($currentUser['username']);
        
        if ($isCompleted) {
            $sql = "UPDATE task_subtasks SET is_completed = 1, completed_by = '$completedBy', completed_at = NOW() WHERE id = $subtaskId";
        } else {
            $sql = "UPDATE task_subtasks SET is_completed = 0, completed_by = NULL, completed_at = NULL WHERE id = $subtaskId";
        }
        
        if ($mysqli->query($sql)) {
            // Update task progress
            $subtasksResult = $mysqli->query("SELECT COUNT(*) as total, SUM(is_completed) as completed FROM task_subtasks WHERE task_id = $taskId");
            $subtasksData = $subtasksResult->fetch_assoc();
            
            $total = $subtasksData['total'];
            $completed = $subtasksData['completed'];
            $progress = $total > 0 ? round(($completed / $total) * 100) : 0;
            
            // Update task progress and counts
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
            
            echo json_encode(['success' => true, 'progress' => $progress, 'completed' => $completed, 'total' => $total]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'add_subtask') {
        $taskId = (int)$_POST['taskId'];
        $title = $mysqli->real_escape_string($_POST['title']);
        $assignedTo = $mysqli->real_escape_string($_POST['assignedTo'] ?? '');
        
        $sql = "INSERT INTO task_subtasks (task_id, title, assigned_to) VALUES ($taskId, '$title', '$assignedTo')";
        
        if ($mysqli->query($sql)) {
            // Update total tasks count
            $mysqli->query("UPDATE tasks SET tasks_total = tasks_total + 1 WHERE id = $taskId");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'delete_task') {
        $taskId = (int)$_POST['taskId'];
        
        // Delete associated files first
        $taskQuery = $mysqli->query("SELECT attachments FROM tasks WHERE id = $taskId");
        if ($taskQuery->num_rows > 0) {
            $taskData = $taskQuery->fetch_assoc();
            if (!empty($taskData['attachments'])) {
                $uploadDir = "../uploads/tasks/";
                $attachments = explode(',', $taskData['attachments']);
                foreach ($attachments as $filename) {
                    $filePath = $uploadDir . $filename;
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            }
        }
        
        $sql = "DELETE FROM tasks WHERE id = $taskId";
        
        if ($mysqli->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        exit;
    }
}

// Get all users for subtask assignment
$usersQuery = "SELECT * FROM users WHERE username != '$admin'";
$usersResult = $mysqli->query($usersQuery);

// Get initial comments
$commentsQuery = "SELECT * FROM task_comments WHERE task_id = $id ORDER BY created_at DESC";
$commentsResult = $mysqli->query($commentsQuery);

// Get initial subtasks
$subtasksQuery = "SELECT * FROM task_subtasks WHERE task_id = $id ORDER BY created_at ASC";
$subtasksResult = $mysqli->query($subtasksQuery);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Detail Task - <?= htmlspecialchars($tugas["title"]) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    * {
        margin: 0; padding: 0; box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
        -webkit-tap-highlight-color: transparent;
    }

    body {
        background: #f5f6fa;
        min-height: 100vh;
        overflow-x: hidden;
    }

    /* Header Responsive */
    .header {
        background: linear-gradient(135deg, #3550dc, #4c6ef5);
        color: white;
        padding: 20px;
        border-radius: 0 0 20px 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        position: relative;
        z-index: 10;
    }

    @media (max-width: 768px) {
        .header {
            padding: 16px;
            border-radius: 0 0 16px 16px;
        }
    }

    @media (max-width: 480px) {
        .header {
            padding: 14px;
            border-radius: 0 0 12px 12px;
        }
    }

    .back-btn {
        font-size: 22px;
        cursor: pointer;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
        flex-shrink: 0;
    }

    @media (max-width: 768px) {
        .back-btn {
            width: 38px;
            height: 38px;
            font-size: 20px;
        }
    }

    @media (max-width: 480px) {
        .back-btn {
            width: 36px;
            height: 36px;
            font-size: 18px;
        }
    }

    .back-btn:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    .header-title {
        font-size: 18px;
        font-weight: 600;
    }

    @media (max-width: 768px) {
        .header-title {
            font-size: 17px;
        }
    }

    @media (max-width: 480px) {
        .header-title {
            font-size: 16px;
        }
    }

    .container {
        padding: 20px;
        margin-top: -10px;
        padding-bottom: 80px;
    }

    @media (max-width: 768px) {
        .container {
            padding: 16px;
            padding-bottom: 70px;
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 12px;
            padding-bottom: 60px;
        }
    }

    /* Status Badge Responsive */
    .status-badge {
        display: inline-block;
        padding: 8px 15px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 15px;
    }

    @media (max-width: 768px) {
        .status-badge {
            padding: 7px 14px;
            font-size: 13px;
            margin-bottom: 12px;
        }
    }

    @media (max-width: 480px) {
        .status-badge {
            padding: 6px 12px;
            font-size: 12px;
            margin-bottom: 10px;
        }
    }

    .status-badge.todo {
        background: #fef3c7;
        color: #92400e;
    }

    .status-badge.progress {
        background: #dbeafe;
        color: #1e40af;
    }

    .status-badge.completed {
        background: #d1fae5;
        color: #065f46;
    }

    .title {
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 20px;
        color: #1f2937;
        line-height: 1.3;
    }

    @media (max-width: 768px) {
        .title {
            font-size: 22px;
            margin-bottom: 18px;
        }
    }

    @media (max-width: 480px) {
        .title {
            font-size: 20px;
            margin-bottom: 16px;
            word-break: break-word;
        }
    }

    /* Progress Section Responsive */
    .progress-box {
        background: white;
        padding: 20px;
        border-radius: 15px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    @media (max-width: 768px) {
        .progress-box {
            padding: 18px;
            border-radius: 14px;
            margin-bottom: 18px;
        }
    }

    @media (max-width: 480px) {
        .progress-box {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 16px;
        }
    }

    .progress-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .progress-label {
        font-size: 16px;
        font-weight: 600;
        color: #333;
    }

    @media (max-width: 480px) {
        .progress-label {
            font-size: 15px;
        }
    }

    .progress-percentage {
        font-size: 20px;
        font-weight: 700;
        color: #3550dc;
    }

    @media (max-width: 480px) {
        .progress-percentage {
            font-size: 18px;
        }
    }

    .progress-bar-bg {
        height: 10px;
        background: #e8ecf4;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 10px;
    }

    @media (max-width: 480px) {
        .progress-bar-bg {
            height: 8px;
            border-radius: 8px;
        }
    }

    .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #3550dc, #5b7ff5);
        border-radius: 10px;
        transition: width 0.5s ease;
    }

    .progress-slider {
        margin-top: 15px;
    }

    .progress-slider input[type="range"] {
        width: 100%;
        height: 8px;
        border-radius: 5px;
        background: #e5e7eb;
        outline: none;
        -webkit-appearance: none;
    }

    @media (max-width: 480px) {
        .progress-slider input[type="range"] {
            height: 6px;
        }
    }

    .progress-slider input[type="range"]::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #3550dc;
        cursor: pointer;
        border: 3px solid white;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }

    @media (max-width: 480px) {
        .progress-slider input[type="range"]::-webkit-slider-thumb {
            width: 22px;
            height: 22px;
            border: 2px solid white;
        }
    }

    .progress-slider input[type="range"]::-moz-range-thumb {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #3550dc;
        cursor: pointer;
        border: 3px solid white;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }

    @media (max-width: 480px) {
        .progress-slider input[type="range"]::-moz-range-thumb {
            width: 22px;
            height: 22px;
            border: 2px solid white;
        }
    }

    .progress-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 10px;
        font-size: 14px;
        color: #6b7280;
        flex-wrap: wrap;
        gap: 5px;
    }

    @media (max-width: 480px) {
        .progress-info {
            font-size: 13px;
        }
    }

    .progress-stats {
        font-weight: 600;
        color: #3550dc;
    }

    /* Section Responsive */
    .section {
        background: white;
        padding: 20px;
        border-radius: 15px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    @media (max-width: 768px) {
        .section {
            padding: 18px;
            border-radius: 14px;
            margin-bottom: 18px;
        }
    }

    @media (max-width: 480px) {
        .section {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 16px;
        }
    }

    .section-title {
        font-size: 18px;
        font-weight: 700;
        color: #333;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    @media (max-width: 768px) {
        .section-title {
            font-size: 17px;
            margin-bottom: 14px;
        }
    }

    @media (max-width: 480px) {
        .section-title {
            font-size: 16px;
            margin-bottom: 12px;
            gap: 8px;
        }
    }

    .section-title i {
        color: #3550dc;
    }

    /* Task Description Responsive */
    .task-description {
        color: #4b5563;
        line-height: 1.6;
        font-size: 15px;
        padding: 15px;
        background: #f8faff;
        border-radius: 10px;
        margin-bottom: 20px;
        word-break: break-word;
    }

    @media (max-width: 768px) {
        .task-description {
            padding: 14px;
            font-size: 14.5px;
            margin-bottom: 18px;
        }
    }

    @media (max-width: 480px) {
        .task-description {
            padding: 12px;
            font-size: 14px;
            margin-bottom: 16px;
            border-radius: 8px;
        }
    }

    /* Dates Section Responsive */
    .dates-section {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
    }

    @media (max-width: 768px) {
        .dates-section {
            gap: 12px;
            margin-bottom: 18px;
        }
    }

    @media (max-width: 480px) {
        .dates-section {
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }
    }

    .date-box {
        background: #f8faff;
        padding: 15px;
        border-radius: 10px;
    }

    @media (max-width: 768px) {
        .date-box {
            padding: 14px;
        }
    }

    @media (max-width: 480px) {
        .date-box {
            padding: 12px;
        }
    }

    .date-label {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 5px;
    }

    .date-value {
        font-size: 16px;
        font-weight: 600;
        color: #333;
    }

    @media (max-width: 480px) {
        .date-value {
            font-size: 15px;
        }
    }

    .date-edit {
        display: flex;
        gap: 8px;
        align-items: center;
        margin-top: 10px;
        flex-wrap: wrap;
    }

    @media (max-width: 480px) {
        .date-edit {
            gap: 6px;
            margin-top: 8px;
        }
    }

    .date-edit input {
        flex: 1;
        padding: 8px 10px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
        min-width: 0;
    }

    @media (max-width: 480px) {
        .date-edit input {
            padding: 7px 9px;
            font-size: 13px;
            width: 100%;
        }
    }

    .date-edit button {
        padding: 8px 12px;
        background: #3550dc;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        display: flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    @media (max-width: 480px) {
        .date-edit button {
            padding: 7px 10px;
            font-size: 11px;
            width: 100%;
            justify-content: center;
        }
    }

    /* User Info Responsive */
    .user-info-box {
        background: #fff3cd;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 15px;
        border: 1px solid #ffeaa7;
    }

    @media (max-width: 480px) {
        .user-info-box {
            padding: 12px;
            margin-bottom: 12px;
        }
    }

    .user-info-label {
        font-size: 12px;
        color: #8a6d00;
        margin-bottom: 5px;
        font-weight: 600;
    }

    @media (max-width: 480px) {
        .user-info-label {
            font-size: 11px;
        }
    }

    .user-info-content {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    @media (max-width: 480px) {
        .user-info-content {
            gap: 8px;
        }
    }

    .avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 16px;
        font-weight: 600;
        flex-shrink: 0;
    }

    @media (max-width: 480px) {
        .avatar {
            width: 36px;
            height: 36px;
            font-size: 15px;
        }
    }

    .user-name {
        font-size: 16px;
        font-weight: 600;
        color: #333;
    }

    @media (max-width: 480px) {
        .user-name {
            font-size: 15px;
        }
    }

    .user-role {
        font-size: 12px;
        color: #6b7280;
        font-style: italic;
    }

    /* Assigned Users Responsive */
    .assigned-users-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    @media (max-width: 480px) {
        .assigned-users-grid {
            gap: 8px;
        }
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

    @media (max-width: 768px) {
        .assigned-user {
            padding: 9px 14px;
        }
    }

    @media (max-width: 480px) {
        .assigned-user {
            padding: 8px 12px;
            border-radius: 18px;
            flex: 1 0 auto;
            min-width: calc(50% - 8px);
            justify-content: center;
        }
    }

    .assigned-user-avatar {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 12px;
        font-weight: 600;
        flex-shrink: 0;
    }

    @media (max-width: 480px) {
        .assigned-user-avatar {
            width: 28px;
            height: 28px;
            font-size: 11px;
        }
    }

    .assigned-user-name {
        font-size: 14px;
        font-weight: 500;
        color: #333;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    @media (max-width: 480px) {
        .assigned-user-name {
            font-size: 13px;
        }
    }

    /* Subtasks Responsive */
    .subtasks-section {
        margin-bottom: 20px;
    }

    @media (max-width: 480px) {
        .subtasks-section {
            margin-bottom: 16px;
        }
    }

    .subtask-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: #f8faff;
        border-radius: 10px;
        margin-bottom: 10px;
        transition: all 0.3s;
    }

    @media (max-width: 768px) {
        .subtask-item {
            padding: 11px;
            gap: 10px;
        }
    }

    @media (max-width: 480px) {
        .subtask-item {
            padding: 10px;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
    }

    .subtask-item:hover {
        background: #eff6ff;
    }

    .subtask-checkbox {
        width: 22px;
        height: 22px;
        border-radius: 6px;
        border: 2px solid #ddd;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
        flex-shrink: 0;
    }

    @media (max-width: 480px) {
        .subtask-checkbox {
            width: 20px;
            height: 20px;
        }
    }

    .subtask-checkbox.checked {
        background: #10b981;
        border-color: #10b981;
        color: white;
    }

    .subtask-content {
        flex: 1;
        min-width: 0;
    }

    .subtask-title {
        font-size: 15px;
        font-weight: 500;
        margin-bottom: 4px;
        word-break: break-word;
    }

    @media (max-width: 480px) {
        .subtask-title {
            font-size: 14px;
        }
    }

    .subtask-title.completed {
        text-decoration: line-through;
        color: #9ca3af;
    }

    .subtask-info {
        display: flex;
        gap: 15px;
        font-size: 12px;
        color: #6b7280;
        flex-wrap: wrap;
    }

    @media (max-width: 480px) {
        .subtask-info {
            gap: 10px;
            font-size: 11px;
        }
    }

    .subtask-assigned {
        font-style: italic;
    }

    .subtask-completed-by {
        color: #10b981;
        font-style: italic;
    }

    .add-subtask-form {
        display: flex;
        gap: 10px;
        margin-top: 15px;
        flex-wrap: wrap;
    }

    @media (max-width: 480px) {
        .add-subtask-form {
            gap: 8px;
            margin-top: 12px;
        }
    }

    .add-subtask-form input {
        flex: 1;
        padding: 12px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        min-width: 200px;
    }

    @media (max-width: 768px) {
        .add-subtask-form input {
            min-width: 150px;
        }
    }

    @media (max-width: 480px) {
        .add-subtask-form input {
            width: 100%;
            min-width: 100%;
            padding: 10px 12px;
            font-size: 13px;
        }
    }

    .add-subtask-form select {
        min-width: 140px;
        padding: 12px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        background: white;
    }

    @media (max-width: 768px) {
        .add-subtask-form select {
            min-width: 120px;
        }
    }

    @media (max-width: 480px) {
        .add-subtask-form select {
            width: 100%;
            min-width: 100%;
            padding: 10px 12px;
            font-size: 13px;
        }
    }

    .add-subtask-form button {
        padding: 12px 20px;
        background: #3550dc;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
    }

    @media (max-width: 768px) {
        .add-subtask-form button {
            padding: 11px 18px;
        }
    }

    @media (max-width: 480px) {
        .add-subtask-form button {
            width: 100%;
            padding: 10px 16px;
            justify-content: center;
        }
    }

    /* Attachments Responsive */
    .attachments-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 15px;
        margin-top: 10px;
    }

    @media (max-width: 768px) {
        .attachments-grid {
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
        }
    }

    @media (max-width: 480px) {
        .attachments-grid {
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
        }
    }

    .attachment-item {
        transition: all 0.2s ease;
        border-radius: 10px;
        overflow: hidden;
    }

    .attachment-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .attachment-image {
        width: 100%;
        height: 100px;
        object-fit: cover;
        border-radius: 10px;
        cursor: pointer;
    }

    @media (max-width: 768px) {
        .attachment-image {
            height: 90px;
        }
    }

    @media (max-width: 480px) {
        .attachment-image {
            height: 80px;
            border-radius: 8px;
        }
    }

    .attachment-file {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: white;
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        height: 140px;
        transition: all 0.3s;
    }

    @media (max-width: 768px) {
        .attachment-file {
            padding: 16px;
            height: 130px;
        }
    }

    @media (max-width: 480px) {
        .attachment-file {
            padding: 14px;
            height: 120px;
            border-radius: 8px;
        }
    }

    .attachment-file:hover {
        background: #f8faff;
        border-color: #3550dc;
    }

    .attachment-file-icon {
        font-size: 2.5rem;
        margin-bottom: 10px;
    }

    @media (max-width: 480px) {
        .attachment-file-icon {
            font-size: 2.2rem;
        }
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
        font-size: 12px;
        color: #333;
        word-break: break-all;
        margin-bottom: 5px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        line-height: 1.3;
        max-height: 2.6em;
    }

    @media (max-width: 480px) {
        .attachment-file-name {
            font-size: 11px;
        }
    }

    .attachment-file-type {
        font-size: 10px;
        color: #6b7280;
        text-transform: uppercase;
    }

    /* Comments Responsive */
    .comments-section {
        max-height: 300px;
        overflow-y: auto;
        margin-bottom: 20px;
        padding-right: 10px;
    }

    @media (max-width: 768px) {
        .comments-section {
            max-height: 280px;
        }
    }

    @media (max-width: 480px) {
        .comments-section {
            max-height: 250px;
        }
    }

    .comment-item {
        background: #f8faff;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 12px;
        border-left: 4px solid #3550dc;
    }

    @media (max-width: 768px) {
        .comment-item {
            padding: 14px;
            margin-bottom: 10px;
        }
    }

    @media (max-width: 480px) {
        .comment-item {
            padding: 12px;
            margin-bottom: 8px;
            border-left-width: 3px;
        }
    }

    .comment-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 8px;
        flex-wrap: wrap;
        gap: 5px;
    }

    .comment-user {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    @media (max-width: 480px) {
        .comment-user {
            gap: 8px;
        }
    }

    .comment-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 12px;
        font-weight: 600;
        flex-shrink: 0;
    }

    @media (max-width: 480px) {
        .comment-avatar {
            width: 30px;
            height: 30px;
            font-size: 11px;
        }
    }

    .comment-username {
        font-weight: 600;
        color: #3550dc;
        font-size: 14px;
    }

    @media (max-width: 480px) {
        .comment-username {
            font-size: 13px;
        }
    }

    .comment-time {
        font-size: 12px;
        color: #9ca3af;
        text-align: right;
        flex-shrink: 0;
    }

    @media (max-width: 480px) {
        .comment-time {
            font-size: 11px;
            width: 100%;
            text-align: left;
            margin-left: 42px;
        }
    }

    .comment-text {
        color: #333;
        font-size: 14px;
        line-height: 1.5;
        padding-left: 42px;
        word-break: break-word;
    }

    @media (max-width: 768px) {
        .comment-text {
            padding-left: 40px;
        }
    }

    @media (max-width: 480px) {
        .comment-text {
            font-size: 13px;
            padding-left: 0;
            margin-top: 5px;
        }
    }

    .comment-input-group {
        display: flex;
        gap: 10px;
        margin-top: 15px;
        flex-wrap: wrap;
    }

    @media (max-width: 480px) {
        .comment-input-group {
            gap: 8px;
            margin-top: 12px;
        }
    }

    .comment-input-group input {
        flex: 1;
        padding: 15px 20px;
        border: 1px solid #ddd;
        border-radius: 10px;
        font-size: 14px;
        background: white;
        transition: all 0.3s;
        min-width: 200px;
    }

    @media (max-width: 768px) {
        .comment-input-group input {
            padding: 14px 18px;
        }
    }

    @media (max-width: 480px) {
        .comment-input-group input {
            width: 100%;
            padding: 12px 16px;
            font-size: 13px;
            min-width: 100%;
        }
    }

    .comment-input-group input:focus {
        outline: none;
        border-color: #3550dc;
        box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
    }

    .comment-input-group button {
        padding: 15px 25px;
        background: #3550dc;
        color: white;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
        white-space: nowrap;
    }

    @media (max-width: 768px) {
        .comment-input-group button {
            padding: 14px 22px;
        }
    }

    @media (max-width: 480px) {
        .comment-input-group button {
            width: 100%;
            padding: 12px 20px;
            justify-content: center;
        }
    }

    .comment-input-group button:hover {
        background: #2b44c9;
    }

    /* Action Buttons Responsive */
    .action-buttons {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-top: 30px;
    }

    @media (max-width: 768px) {
        .action-buttons {
            gap: 12px;
            margin-top: 25px;
        }
    }

    @media (max-width: 480px) {
        .action-buttons {
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 20px;
        }
    }

    .btn {
        padding: 16px 30px;
        border: none;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        white-space: nowrap;
    }

    @media (max-width: 768px) {
        .btn {
            padding: 15px 25px;
            font-size: 15px;
        }
    }

    @media (max-width: 480px) {
        .btn {
            padding: 14px 20px;
            font-size: 14px;
            width: 100%;
            border-radius: 10px;
        }
    }

    .btn-delete {
        background: #ef4444;
        color: white;
        border: none;
    }

    .btn-delete:hover {
        background: #dc2626;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .btn-back {
        background: white;
        color: #333;
        border: 1px solid #ddd;
    }

    .btn-back:hover {
        background: #f5f5f5;
    }

    /* No Data States Responsive */
    .no-data {
        text-align: center;
        padding: 40px 20px;
        color: #9ca3af;
    }

    @media (max-width: 768px) {
        .no-data {
            padding: 35px 18px;
        }
    }

    @media (max-width: 480px) {
        .no-data {
            padding: 30px 16px;
        }
    }

    .no-data i {
        font-size: 3rem;
        margin-bottom: 15px;
        opacity: 0.5;
    }

    @media (max-width: 768px) {
        .no-data i {
            font-size: 2.8rem;
        }
    }

    @media (max-width: 480px) {
        .no-data i {
            font-size: 2.5rem;
            margin-bottom: 12px;
        }
    }

    .no-data p {
        font-size: 14px;
    }

    @media (max-width: 480px) {
        .no-data p {
            font-size: 13px;
        }
    }

    /* Bottom Navigation - Ditambahkan untuk konsistensi */
    .bottom-nav {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #ffffff;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 5px;
        width: auto;
        max-width: 95%;
        padding: 8px;
        border-radius: 50px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        z-index: 100;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        white-space: nowrap;
    }

    .bottom-nav::-webkit-scrollbar {
        display: none;
    }

    @media (max-width: 768px) {
        .bottom-nav {
            bottom: 15px;
            padding: 6px;
            gap: 4px;
            max-width: 98%;
        }
    }

    @media (max-width: 480px) {
        .bottom-nav {
            bottom: 10px;
            padding: 5px;
            gap: 3px;
            max-width: 100%;
            border-radius: 25px;
        }
    }

    .bottom-nav a {
        text-align: center;
        color: #9ca3af;
        text-decoration: none;
        font-weight: 500;
        border-radius: 25px;
        padding: 11px 20px;
        font-size: 15px;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    @media (max-width: 768px) {
        .bottom-nav a {
            padding: 10px 16px;
            font-size: 14px;
            gap: 6px;
            border-radius: 20px;
        }
    }

    @media (max-width: 480px) {
        .bottom-nav a {
            padding: 8px 12px;
            font-size: 13px;
            gap: 5px;
            border-radius: 18px;
        }
        
        .bottom-nav a span {
            display: none;
        }
        
        .bottom-nav a i {
            font-size: 16px;
            margin: 0;
        }
    }

    @media (min-width: 481px) and (max-width: 640px) {
        .bottom-nav a {
            padding: 9px 14px;
            font-size: 13.5px;
            gap: 6px;
        }
        
        .bottom-nav a span {
            font-size: 12.5px;
        }
    }

    .bottom-nav a i {
        font-size: 17px;
    }

    @media (max-width: 768px) {
        .bottom-nav a i {
            font-size: 16px;
        }
    }

    .bottom-nav a.active { 
        background: #3550dc; 
        color: #fff;
    }

    .bottom-nav a:not(.active):hover {
        color: #3550dc;
        background: #f3f4f6;
    }

    /* Fix untuk mobile input zoom */
    @media (max-width: 480px) {
        input, textarea, select {
            font-size: 16px !important;
        }
    }

    /* Safe area for iPhone X and newer */
    @supports (padding: max(0px)) {
        .bottom-nav {
            bottom: max(20px, env(safe-area-inset-bottom));
        }
    }

    /* Touch improvements */
    .subtask-item,
    .btn,
    .assigned-user,
    .attachment-file,
    .comment-input-group button,
    .date-edit button {
        -webkit-user-select: none;
        user-select: none;
    }
</style>
</head>
<body>

<div class="header">
    <div class="back-btn" onclick="history.back()">
        <i class="fas fa-arrow-left"></i>
    </div>
    <div class="header-title">Detail Task</div>
</div>

<div class="container">
    <!-- Status Badge -->
    <div id="statusBadge" class="status-badge <?= $tugas['status'] ?>">
        <?= $tugas['category'] ?>
    </div>

    <!-- Title -->
    <div class="title"><?= htmlspecialchars($tugas["title"]) ?></div>

    <!-- Progress Section -->
    <div class="progress-box">
        <div class="progress-header">
            <div class="progress-label">Progress</div>
            <div class="progress-percentage" id="currentProgress"><?= $tugas["progress"] ?>%</div>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar" id="progressBar" style="width: <?= $tugas["progress"] ?>%"></div>
        </div>
        
        <div class="progress-slider">
            <input type="range" id="progressSlider" min="0" max="100" value="<?= $tugas["progress"] ?>">
            <div class="progress-info">
                <span>0%</span>
                <span class="progress-stats" id="progressStats">
                    <?= $tugas["tasks_completed"] ?>/<?= $tugas["tasks_total"] ?> selesai
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
        <div class="task-description" id="taskDescription">
            <?= nl2br(htmlspecialchars($tugas["note"] ?: "Tidak ada deskripsi")) ?>
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
                <div class="date-value" id="displayStartDate"><?= $tugas["start_date"] ?: '-' ?></div>
                <div class="date-edit">
                    <input type="date" id="editStartDate" value="<?= $tugas["start_date"] ?>">
                    <button onclick="updateTaskDates()">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </div>
            
            <div class="date-box">
                <div class="date-label">Tanggal Selesai</div>
                <div class="date-value" id="displayEndDate"><?= $tugas["end_date"] ?: '-' ?></div>
                <div class="date-edit">
                    <input type="date" id="editEndDate" value="<?= $tugas["end_date"] ?>">
                    <button onclick="updateTaskDates()">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
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
            <div class="user-info-label">PEMBUAT TUGAS</div>
            <div class="user-info-content">
                <div class="avatar">
                    <?= strtoupper(substr($tugas["created_by"], 0, 1)) ?>
                </div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($tugas["created_by"]) ?></div>
                    <div class="user-role">Pembuat Tugas</div>
                </div>
            </div>
        </div>

        <!-- Assigned Users -->
        <div>
            <div class="user-info-label" style="margin-bottom: 10px;">DITUGASKAN KEPADA</div>
            <div class="assigned-users-grid" id="assignedUsersGrid">
                <?php
                if (!empty($tugas["assigned_users"])) {
                    $assignedUsers = explode(',', $tugas["assigned_users"]);
                    foreach ($assignedUsers as $index => $user) {
                        $user = trim($user);
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
            if ($subtasksResult->num_rows > 0) {
                while ($subtask = $subtasksResult->fetch_assoc()) {
                    $completedClass = $subtask['is_completed'] ? 'checked' : '';
                    $titleClass = $subtask['is_completed'] ? 'completed' : '';
                    $completedBy = $subtask['completed_by'] ? "Selesai oleh {$subtask['completed_by']}" : '';
                    $completedDate = $subtask['completed_at'] ? " pada " . date('d/m/Y H:i', strtotime($subtask['completed_at'])) : '';
                    
                    echo '<div class="subtask-item">';
                    echo '<div class="subtask-checkbox ' . $completedClass . '" onclick="toggleSubtask(' . $subtask['id'] . ', ' . ($subtask['is_completed'] ? '0' : '1') . ')">';
                    echo '<i class="fas fa-check" style="font-size: 12px;"></i>';
                    echo '</div>';
                    echo '<div class="subtask-content">';
                    echo '<div class="subtask-title ' . $titleClass . '">' . htmlspecialchars($subtask['title']) . '</div>';
                    echo '<div class="subtask-info">';
                    if ($subtask['assigned_to']) {
                        echo '<span class="subtask-assigned"><i class="fas fa-user-tag"></i> ' . htmlspecialchars($subtask['assigned_to']) . '</span>';
                    }
                    if ($completedBy) {
                        echo '<span class="subtask-completed-by"><i class="fas fa-check-circle"></i> ' . $completedBy . $completedDate . '</span>';
                    }
                    echo '</div>';
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
        
        <div class="add-subtask-form">
            <input type="text" id="newSubtask" placeholder="Tambah pekerjaan baru..." onkeypress="if(event.key === 'Enter') addSubtaskToTask()">
            <select id="subtaskAssign">
                <option value="">Pilih teman...</option>
                <?php
                $usersResult->data_seek(0);
                while ($userRow = $usersResult->fetch_assoc()) {
                    echo '<option value="' . htmlspecialchars($userRow['username']) . '">' . htmlspecialchars($userRow['username']) . '</option>';
                }
                ?>
            </select>
            <button onclick="addSubtaskToTask()">
                <i class="fas fa-plus"></i> Tambah
            </button>
        </div>
    </div>

    <!-- Attachments -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-paperclip"></i>
            Lampiran (<span id="attachmentCount">
                <?= !empty($tugas["attachments"]) ? count(explode(',', $tugas["attachments"])) : 0 ?>
            </span>)
        </div>
        <div id="attachmentsSection">
            <?php
            if (!empty($tugas["attachments"])) {
                $attachments = explode(',', $tugas["attachments"]);
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
                        elseif (in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'fa-image';
                        
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
            } else {
                echo '<div class="no-data">';
                echo '<i class="fas fa-paperclip"></i>';
                echo '<p>Tidak ada lampiran</p>';
                echo '</div>';
            }
            ?>
        </div>
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
                $commentsResult->data_seek(0);
                while ($comment = $commentsResult->fetch_assoc()) {
                    $initial = strtoupper(substr($comment['username'], 0, 1));
                    echo '<div class="comment-item">';
                    echo '<div class="comment-header">';
                    echo '<div class="comment-user">';
                    echo '<div class="comment-avatar">' . $initial . '</div>';
                    echo '<div class="comment-username">' . htmlspecialchars($comment['username']) . '</div>';
                    echo '</div>';
                    echo '<div class="comment-time">' . formatTimeAgo($comment['created_at']) . '</div>';
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

    <!-- Action Buttons -->
    <div class="action-buttons">
        <button class="btn btn-delete" onclick="confirmDelete()">
            <i class="fas fa-trash"></i> Hapus Tugas
        </button>
        <button class="btn btn-back" onclick="window.location.href='tasks.php'">
            <i class="fas fa-arrow-left"></i> Kembali ke Daftar
        </button>
    </div>
</div>

<!-- Bottom Navigation - Ditambahkan -->
<div class="bottom-nav">
    <a href="dashboard.php">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
    </a>
    <a href="tasks.php" class="active">
        <i class="fa-solid fa-list-check"></i>
        <span>Tasks</span>
    </a>
    <a href="users.php">
        <i class="fa-solid fa-user-group"></i>
        <span>Users</span>
    </a>
    <a href="tugas_default.php">
        <i class="fa-solid fa-tasks"></i>
        <span>Tugas default</span>
    </a>
    <a href="profile.php">
        <i class="fa-solid fa-user"></i>
        <span>Profil</span>
    </a>
</div>

<script>
    let currentTaskId = <?= $id ?>;
    let currentProgress = <?= $tugas["progress"] ?>;
    let currentCategory = '<?= $tugas["category"] ?>';
    let currentStatus = '<?= $tugas["status"] ?>';

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
                currentCategory = result.category;
                currentStatus = result.status;
                
                // Update status badge
                statusBadge.textContent = result.category;
                statusBadge.className = 'status-badge ' + result.status;
                
                alert('Progress berhasil diperbarui!');
            } else {
                alert('Gagal memperbarui progress: ' + result.error);
                // Reset to previous value
                progressSlider.value = currentProgress;
                progressBar.style.width = currentProgress + '%';
                currentProgressElement.textContent = currentProgress + '%';
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat memperbarui progress');
        }
    }

    async function updateTaskDates() {
        const startDate = document.getElementById('editStartDate').value;
        const endDate = document.getElementById('editEndDate').value;
        
        if (!startDate || !endDate) {
            alert('Tanggal mulai dan tanggal selesai harus diisi');
            return;
        }
        
        if (new Date(startDate) > new Date(endDate)) {
            alert('Tanggal mulai tidak boleh setelah tanggal selesai');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'update_dates');
            formData.append('taskId', currentTaskId);
            formData.append('startDate', startDate);
            formData.append('endDate', endDate);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                document.getElementById('displayStartDate').textContent = result.startDate;
                document.getElementById('displayEndDate').textContent = result.endDate;
                alert('Tanggal berhasil diperbarui!');
            } else {
                alert('Gagal memperbarui tanggal: ' + result.error);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat memperbarui tanggal');
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
                
                // Add new comment to the list
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
                
                // Update comment count
                const commentCount = document.getElementById('commentCount');
                commentCount.textContent = parseInt(commentCount.textContent) + 1;
            } else {
                alert('Gagal menambahkan komentar: ' + result.error);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menambahkan komentar');
        }
    }

    async function loadSubtasks() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_subtasks');
            formData.append('taskId', currentTaskId);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                renderSubtasks(result.subtasks);
            }
        } catch (error) {
            console.error('Error loading subtasks:', error);
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

        const completed = subtasks.filter(st => st.is_completed).length;
        const total = subtasks.length;
        
        progressStats.textContent = `${completed}/${total} selesai`;
        
        subtasksSection.innerHTML = subtasks.map(subtask => {
            const completedClass = subtask.is_completed ? 'checked' : '';
            const titleClass = subtask.is_completed ? 'completed' : '';
            const assigned = subtask.assigned_to ? 
                `<span class="subtask-assigned"><i class="fas fa-user-tag"></i> ${subtask.assigned_to}</span>` : '';
            const completedBy = subtask.completed_by ? 
                `<span class="subtask-completed-by"><i class="fas fa-check-circle"></i> Selesai oleh ${subtask.completed_by}</span>` : '';
            
            return `
                <div class="subtask-item">
                    <div class="subtask-checkbox ${completedClass}" onclick="toggleSubtask(${subtask.id}, ${subtask.is_completed ? '0' : '1'})">
                        <i class="fas fa-check" style="font-size: 12px;"></i>
                    </div>
                    <div class="subtask-content">
                        <div class="subtask-title ${titleClass}">${subtask.title}</div>
                        <div class="subtask-info">
                            ${assigned}
                            ${completedBy}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    async function toggleSubtask(subtaskId, newStatus) {
        try {
            const formData = new FormData();
            formData.append('action', 'toggle_subtask');
            formData.append('subtaskId', subtaskId);
            formData.append('isCompleted', newStatus);
            formData.append('taskId', currentTaskId);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // Update progress slider
                progressSlider.value = result.progress;
                progressBar.style.width = result.progress + '%';
                currentProgressElement.textContent = result.progress + '%';
                progressStats.textContent = `${result.completed}/${result.total} selesai`;
                
                // Reload subtasks
                await loadSubtasks();
                
                // Update status badge
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
                currentCategory = category;
                currentStatus = status;
                currentProgress = result.progress;
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat mengupdate subtask');
        }
    }

    async function addSubtaskToTask() {
        const input = document.getElementById('newSubtask');
        const assignSelect = document.getElementById('subtaskAssign');
        const title = input.value.trim();
        const assignedTo = assignSelect.value;
        
        if (!title) {
            alert('Judul subtask tidak boleh kosong');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'add_subtask');
            formData.append('taskId', currentTaskId);
            formData.append('title', title);
            formData.append('assignedTo', assignedTo);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                input.value = '';
                assignSelect.value = '';
                await loadSubtasks();
            } else {
                alert('Gagal menambahkan subtask: ' + result.error);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menambahkan subtask');
        }
    }

    function confirmDelete() {
        if (confirm('Apakah Anda yakin ingin menghapus tugas ini? Tindakan ini tidak dapat dibatalkan.')) {
            deleteTask();
        }
    }

    async function deleteTask() {
        try {
            const formData = new FormData();
            formData.append('action', 'delete_task');
            formData.append('taskId', currentTaskId);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                alert('Tugas berhasil dihapus!');
                window.location.href = 'tasks.php';
            } else {
                alert('Gagal menghapus tugas: ' + result.error);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menghapus tugas');
        }
    }

    function openImage(imageUrl) {
        window.open(imageUrl, '_blank', 'width=800,height=600');
    }

    // Format time ago for comments
    function formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);

        if (seconds < 60) return 'Baru saja';
        if (minutes < 60) return minutes + ' menit lalu';
        if (hours < 24) return hours + ' jam lalu';
        if (days < 7) return days + ' hari lalu';
        
        return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        // Load initial comments
        <?php if ($commentsResult->num_rows > 0): ?>
            const commentsSection = document.getElementById('commentsSection');
            commentsSection.scrollTop = 0;
        <?php endif; ?>
        
        // Setup mobile adjustments
        setupMobileAdjustments();
    });
    
    function setupMobileAdjustments() {
        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                adjustLayoutForMobile();
            }, 250);
        });
        
        // Initial adjustment
        adjustLayoutForMobile();
    }
    
    function adjustLayoutForMobile() {
        const isMobile = window.innerWidth <= 480;
        
        // Adjust bottom nav for mobile
        const bottomNavLinks = document.querySelectorAll('.bottom-nav a span');
        if (isMobile) {
            // Hide text on mobile, show only icons
            bottomNavLinks.forEach(span => {
                span.style.display = 'none';
            });
        } else {
            // Show text on larger screens
            bottomNavLinks.forEach(span => {
                span.style.display = 'inline';
            });
        }
    }
</script>

</body>
</html>

<?php
// Helper function to format time ago
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