<?php
session_start();
require_once "../inc/koneksi.php";

// Cek koneksi
if (!isset($mysqli)) {
    die("⚠️ Koneksi belum terbentuk. Cek file koneksi.php");
}

// Cek login
if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$admin = $_SESSION['admin'];
$query = $mysqli->query("SELECT * FROM admin WHERE username = '$admin'");
$user = $query->fetch_assoc();

// Create tasks table if not exists
$createTableQuery = "CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) DEFAULT 'Belum Dijalankan',
    progress INT DEFAULT 0,
    status VARCHAR(50) DEFAULT 'todo',
    start_date VARCHAR(50),
    end_date VARCHAR(50),
    note TEXT,
    assigned_users TEXT,
    subtasks TEXT,
    tasks_completed INT DEFAULT 0,
    tasks_total INT DEFAULT 0,
    comments INT DEFAULT 0,
    attachments TEXT,
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$mysqli->query($createTableQuery);

// Create task_comments table if not exists
$createCommentsTable = "CREATE TABLE IF NOT EXISTS task_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(100),
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
)";
$mysqli->query($createCommentsTable);

// Create task_subtasks table if not exists
$createSubtasksTable = "CREATE TABLE IF NOT EXISTS task_subtasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    assigned_to VARCHAR(100),
    is_completed BOOLEAN DEFAULT FALSE,
    completed_by VARCHAR(100),
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
)";
$mysqli->query($createSubtasksTable);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'add_task') {
        $title = $mysqli->real_escape_string($_POST['title']);
        $startDate = $mysqli->real_escape_string($_POST['startDate']);
        $endDate = $mysqli->real_escape_string($_POST['endDate']);
        $note = $mysqli->real_escape_string($_POST['note']);
        $assignedUsers = $mysqli->real_escape_string($_POST['assignedUsers']);
        $subtasks = isset($_POST['subtasks']) ? $_POST['subtasks'] : [];
        $subtaskAssignments = isset($_POST['subtaskAssignments']) ? $_POST['subtaskAssignments'] : [];
        
        // Handle file upload
        $attachments = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploadDir = "../uploads/tasks/";

            // Create directory if not exists
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $tempName = $_FILES['attachments']['tmp_name'][$key];
                    $fileExtension = pathinfo($name, PATHINFO_EXTENSION);
                    $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
                    $filePath = $uploadDir . $fileName;

                    if (move_uploaded_file($tempName, $filePath)) {
                        $attachments[] = [
                            'name' => $name,
                            'path' => $fileName,
                            'size' => $_FILES['attachments']['size'][$key]
                        ];
                    }
                }
            }
        }

        $attachmentsString = json_encode($attachments);

        // Insert main task
        $sql = "INSERT INTO tasks (title, start_date, end_date, note, assigned_users, attachments, created_by, tasks_total)
                VALUES ('$title', '$startDate', '$endDate', '$note', '$assignedUsers', '$attachmentsString', '$admin', " . count($subtasks) . ")";

        if ($mysqli->query($sql)) {
            $taskId = $mysqli->insert_id;

            // Insert subtasks dengan assign
            $subtasksJson = [];
            foreach ($subtasks as $index => $subtaskTitle) {
                $subtaskTitle = $mysqli->real_escape_string($subtaskTitle);
                $assignedTo = isset($subtaskAssignments[$index]) ? $mysqli->real_escape_string($subtaskAssignments[$index]) : '';

                $mysqli->query("INSERT INTO task_subtasks (task_id, title, assigned_to) VALUES ($taskId, '$subtaskTitle', '$assignedTo')");

                // Build JSON array for tasks.subtasks field
                $subtasksJson[] = [
                    'text' => $subtaskTitle,
                    'assigned' => $assignedTo,
                    'completed' => false
                ];
            }

            // Update tasks.subtasks with JSON
            if (!empty($subtasksJson)) {
                $subtasksJsonString = $mysqli->real_escape_string(json_encode($subtasksJson));
                $mysqli->query("UPDATE tasks SET subtasks = '$subtasksJsonString' WHERE id = $taskId");
            }

            echo json_encode(['success' => true, 'message' => 'Task berhasil ditambahkan dan dibagikan ke teman!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menambahkan task: ' . $mysqli->error]);
        }
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
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
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
                $attachments = json_decode($taskData['attachments'], true);
                if (is_array($attachments)) {
                    foreach ($attachments as $attachment) {
                        $filePath = $uploadDir . $attachment['path'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                }
            }
        }

        $sql = "DELETE FROM tasks WHERE id = $taskId";

        if ($mysqli->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'add_comment') {
        $taskId = (int)$_POST['taskId'];
        $comment = $mysqli->real_escape_string($_POST['comment']);
        $userId = $user['id'];
        $username = $user['username'];
        
        $sql = "INSERT INTO task_comments (task_id, user_id, username, comment) 
                VALUES ($taskId, $userId, '$username', '$comment')";
        
        if ($mysqli->query($sql)) {
            // Update comment count
            $mysqli->query("UPDATE tasks SET comments = comments + 1 WHERE id = $taskId");
            echo json_encode(['success' => true, 'username' => $username, 'comment' => $comment]);
        } else {
            echo json_encode(['success' => false]);
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
        $completedBy = $mysqli->real_escape_string($user['username']);
        
        if ($isCompleted) {
            $sql = "UPDATE task_subtasks SET is_completed = 1, completed_by = '$completedBy', completed_at = NOW() WHERE id = $subtaskId";
        } else {
            $sql = "UPDATE task_subtasks SET is_completed = 0, completed_by = NULL, completed_at = NULL WHERE id = $subtaskId";
        }
        
        if ($mysqli->query($sql)) {
            // Update task progress
            $taskId = (int)$_POST['taskId'];
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
            echo json_encode(['success' => false]);
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
            echo json_encode(['success' => false]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'assign_subtask') {
        $subtaskId = (int)$_POST['subtaskId'];
        $assignedTo = $mysqli->real_escape_string($_POST['assignedTo']);

        $sql = "UPDATE task_subtasks SET assigned_to = '$assignedTo' WHERE id = $subtaskId";

        if ($mysqli->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    if ($_POST['action'] === 'add_subtask_to_task') {
        $taskId = (int)$_POST['taskId'];
        $title = $mysqli->real_escape_string($_POST['title']);
        $assignedTo = $mysqli->real_escape_string($_POST['assignedTo'] ?? '');

        $sql = "INSERT INTO task_subtasks (task_id, title, assigned_to) VALUES ($taskId, '$title', '$assignedTo')";

        if ($mysqli->query($sql)) {
            // Update total tasks count
            $mysqli->query("UPDATE tasks SET tasks_total = tasks_total + 1 WHERE id = $taskId");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
}

// Get all tasks
$tasksQuery = "SELECT * FROM tasks ORDER BY created_at DESC";
$tasksResult = $mysqli->query($tasksQuery);

// Get all users for assignment
$usersQuery = "SELECT * FROM users WHERE username != '$admin'";
$usersResult = $mysqli->query($usersQuery);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Tasks | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* Reset dan Base Styles */
    * {
      margin: 0; 
      padding: 0; 
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }

    html {
      font-size: 16px;
      -webkit-text-size-adjust: 100%;
    }

    body {
      background: #f8f9fe;
      min-height: 100vh;
      padding-bottom: 90px;
      overflow-x: hidden;
    }

    /* Header */
    header {
      background: linear-gradient(135deg, #4169E1, #1e3a8a);
      color: #fff;
      padding: 20px 15px 25px 15px;
      position: relative;
      border-radius: 0 0 20px 20px;
      box-shadow: 0 4px 15px rgba(65, 105, 225, 0.2);
      width: 100%;
    }

    .header-content {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 5px;
    }

    .back-btn {
      background: rgba(255, 255, 255, 0.25);
      border: none;
      color: white;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 1rem;
      transition: all 0.3s;
      flex-shrink: 0;
    }

    .back-btn:hover {
      background: rgba(255, 255, 255, 0.35);
    }

    .header-title {
      font-size: 1.2rem;
      font-weight: 600;
      letter-spacing: 0.3px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Content */
    .content {
      padding: 15px;
      margin-top: -10px;
    }

    /* Search Box */
    .search-box {
      background: white;
      border-radius: 12px;
      padding: 12px 15px;
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 15px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
      border: 1px solid #e8ecf4;
    }

    .search-box i {
      color: #9ca3af;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .search-box input {
      border: none;
      outline: none;
      flex: 1;
      font-size: 0.9rem;
      color: #333;
      width: 100%;
      background: transparent;
    }

    .search-box input::placeholder {
      color: #b0b7c3;
    }

    /* Filter Tabs */
    .filter-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 18px;
      overflow-x: auto;
      padding-bottom: 5px;
      -webkit-overflow-scrolling: touch;
    }

    .filter-tabs::-webkit-scrollbar {
      display: none;
    }

    .filter-tab {
      padding: 8px 16px;
      border-radius: 20px;
      border: none;
      background: white;
      color: #4169E1;
      font-weight: 500;
      font-size: 0.82rem;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.3s;
      box-shadow: 0 2px 6px rgba(0,0,0,0.06);
      border: 1px solid transparent;
      flex-shrink: 0;
    }

    .filter-tab:hover {
      border-color: #4169E1;
    }

    .filter-tab.active {
      background: #4169E1;
      color: white;
      box-shadow: 0 3px 10px rgba(65, 105, 225, 0.3);
      transform: translateY(-1px);
    }

    /* Task Card */
    .task-card {
      background: white;
      border-radius: 15px;
      padding: 15px;
      margin-bottom: 12px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.07);
      position: relative;
      cursor: pointer;
      transition: all 0.3s;
      border: 1px solid #f0f3f8;
      width: 100%;
      overflow: visible;
    }

    .task-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(0,0,0,0.12);
      border-color: #e0e5ed;
    }

    .task-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 12px;
      gap: 10px;
    }

    .task-category {
      font-size: 0.7rem;
      color: #f59e0b;
      font-weight: 600;
      margin-bottom: 5px;
      text-transform: capitalize;
      letter-spacing: 0.3px;
    }

    .task-category.sedang {
      color: #4169E1;
    }

    .task-category.selesai {
      color: #10b981;
    }

    .task-category.terlambat {
      color: #ef4444;
    }

    .task-title {
      font-size: 0.95rem;
      font-weight: 600;
      color: #1f2937;
      margin-bottom: 10px;
      line-height: 1.3;
      word-break: break-word;
    }

    .task-menu {
      background: none;
      border: none;
      color: #9ca3af;
      font-size: 1.1rem;
      cursor: pointer !important;
      padding: 4px;
      position: relative;
      transition: all 0.2s;
      flex-shrink: 0;
      touch-action: none;
      min-width: 44px;
      min-height: 44px;
      display: flex;
      align-items: center;
      justify-content: center;
      -webkit-touch-callout: none;
      -webkit-user-select: none;
      user-select: none;
    }

    .task-menu:hover {
      color: #4169E1;
    }

    /* Dropdown Menu */
    .task-dropdown-menu {
      position: absolute;
      background: white;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: none;
      z-index: 9999;
      min-width: 160px;
      overflow: hidden;
      cursor: pointer;
      top: 100%;
      right: 0;
      left: auto;
      margin-top: 5px;
    }

    .task-dropdown-menu.active {
      display: block;
    }

    .task-dropdown-item {
      padding: 10px 14px;
      cursor: pointer !important;
      transition: background 0.2s;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.85rem;
    }

    .task-dropdown-item:hover {
      background: #f3f4f6;
    }

    .task-dropdown-item i {
      width: 18px;
    }

    .task-dropdown-item.delete {
      color: #ef4444;
    }

    /* Progress Section */
    .progress-section {
      margin-bottom: 12px;
    }

    .progress-label {
      font-size: 0.75rem;
      color: #6b7280;
      font-weight: 500;
      margin-bottom: 6px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .progress-bar-container {
      height: 6px;
      background: #e8ecf4;
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 0;
      cursor: pointer;
    }

    .progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #4169E1, #5b7ff5);
      border-radius: 10px;
      transition: width 0.3s ease;
    }

    .progress-percentage {
      text-align: right;
      font-size: 0.8rem;
      font-weight: 600;
      color: #1f2937;
    }

    /* Task Stats */
    .task-stats {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 0;
      padding-top: 10px;
      border-top: 1px solid #f0f3f8;
      flex-wrap: wrap;
    }

    .stat-item {
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: 0.75rem;
      color: #6b7280;
      cursor: pointer;
      transition: color 0.2s;
      flex-shrink: 0;
    }

    .stat-item:hover {
      color: #4169E1;
    }

    .stat-item i {
      font-size: 0.85rem;
    }

    /* Assigned Users */
    .assigned-users {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-left: auto;
      flex-shrink: 0;
    }

    .user-avatar {
      width: 26px;
      height: 26px;
      border-radius: 50%;
      border: 2px solid white;
      margin-left: -8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.12);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 0.65rem;
      font-weight: 600;
      flex-shrink: 0;
    }

    .user-avatar:first-child {
      margin-left: 0;
    }

    .user-avatar.user1 {
      background: linear-gradient(135deg, #f59e0b, #f97316);
    }

    .user-avatar.user2 {
      background: linear-gradient(135deg, #4169E1, #5b7ff5);
    }

    .user-avatar.user3 {
      background: linear-gradient(135deg, #10b981, #059669);
    }

    .user-avatar.user4 {
      background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    }

    /* Floating Add Button */
    .floating-add-btn {
      position: fixed;
      right: 15px;
      bottom: 80px;
      background: linear-gradient(135deg, #4169E1, #5b7ff5);
      color: white;
      border: none;
      border-radius: 50%;
      width: 55px;
      height: 55px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      cursor: pointer;
      box-shadow: 0 6px 20px rgba(65, 105, 225, 0.4);
      z-index: 99;
      transition: all 0.3s ease;
    }

    .floating-add-btn:hover {
      transform: scale(1.08) rotate(90deg);
      box-shadow: 0 8px 25px rgba(65, 105, 225, 0.5);
    }

    .floating-add-btn:active {
      transform: scale(0.95) rotate(90deg);
    }

    /* Modal styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.3);
      z-index: 1000;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideUp {
      from { transform: translateY(100%); }
      to { transform: translateY(0); }
    }

    .modal-content {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      background-color: white;
      border-top-left-radius: 25px;
      border-top-right-radius: 25px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 -5px 30px rgba(0, 0, 0, 0.15);
      animation: slideUp 0.3s ease;
    }

    .modal-header {
      background: linear-gradient(135deg, #4169E1, #1e3a8a);
      color: white;
      padding: 20px 15px;
      border-top-left-radius: 25px;
      border-top-right-radius: 25px;
      position: relative;
      text-align: center;
    }

    .modal-back-btn {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(255, 255, 255, 0.2);
      border: none;
      color: white;
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 1.1rem;
      transition: all 0.3s;
      flex-shrink: 0;
    }

    .modal-back-btn:hover {
      background: rgba(255, 255, 255, 0.3);
    }

    .modal-header h3 {
      color: white;
      font-size: 1.2rem;
      margin: 0;
      font-weight: 600;
      padding: 0 40px;
    }

    .modal-body {
      padding: 20px 15px;
    }

    .form-title {
      font-size: 1rem;
      font-weight: 700;
      color: #333;
      margin-bottom: 20px;
    }

    .form-group {
      margin-bottom: 15px;
    }

    .form-group label {
      display: block;
      margin-bottom: 6px;
      color: #333;
      font-weight: 500;
      font-size: 0.85rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid #ddd;
      border-radius: 8px;
      font-size: 0.9rem;
      background: white;
      transition: all 0.3s;
      font-family: 'Poppins', sans-serif;
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
      color: #999;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: #4169E1;
      box-shadow: 0 0 0 3px rgba(65, 105, 225, 0.1);
    }

    .form-group textarea {
      resize: vertical;
      min-height: 80px;
    }

    .date-input-wrapper {
      position: relative;
    }

    .date-input-wrapper input {
      padding-right: 40px;
    }

    .date-input-wrapper i {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #666;
      pointer-events: none;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .form-row .form-group {
      margin-bottom: 0;
    }

    .user-selection {
      position: relative;
    }

    .user-selection input {
      cursor: pointer;
    }

    .user-dropdown {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: rgba(255, 255, 255, 0.98);
      border: 1px solid rgba(225, 233, 233, 0.8);
      border-radius: 20px;
      max-height: 280px;
      overflow-y: auto;
      display: none;
      z-index: 1000;
      box-shadow: 0 12px 40px rgba(0,0,0,0.15), 0 6px 20px rgba(0,0,0,0.08);
      margin-top: 12px;
      width: 100%;
      max-width: 100%;
      padding: 8px 0;
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      transform: translateY(-10px);
      opacity: 0;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .user-dropdown.active {
      display: block;
      transform: translateY(0);
      opacity: 1;
    }

    .user-dropdown-item {
      padding: 8px 12px;
      cursor: pointer;
      transition: background 0.2s;
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.85rem;
    }

    .user-dropdown-item:hover {
      background: #f8faff;
    }

    .user-dropdown-item.selected {
      background: #eff6ff;
      color: #4169E1;
      font-weight: 500;
    }

    .user-dropdown-avatar {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 0.75rem;
      font-weight: 600;
      flex-shrink: 0;
    }

    /* Divider */
    .divider {
      height: 1px;
      background: #e5e7eb;
      margin: 20px 0;
    }

    .form-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 25px;
      padding: 15px;
      background: white;
    }

    .btn {
      padding: 14px 25px;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      font-size: 0.95rem;
      font-family: 'Poppins', sans-serif;
      width: 100%;
    }

    .btn-cancel {
      background: white;
      color: #333;
      border: 1px solid #ddd;
    }

    .btn-cancel:hover {
      background: #f5f5f5;
    }

    .btn-save {
      background: #4169E1;
      color: white;
      border: none;
    }

    .btn-save:hover {
      background: #1e3a8a;
      box-shadow: 0 4px 12px rgba(65, 105, 225, 0.3);
    }

    /* Comment Section */
    .comments-section {
      max-height: 250px;
      overflow-y: auto;
      margin-bottom: 15px;
    }

    .comment-item {
      background: #f8faff;
      padding: 10px;
      border-radius: 8px;
      margin-bottom: 8px;
    }

    .comment-header {
      display: flex;
      justify-content: space-between;
      margin-bottom: 4px;
    }

    .comment-username {
      font-weight: 600;
      color: #4169E1;
      font-size: 0.85rem;
    }

    .comment-time {
      font-size: 0.7rem;
      color: #9ca3af;
    }

    .comment-text {
      color: #333;
      font-size: 0.85rem;
      line-height: 1.4;
    }

    .comment-input-group {
      display: flex;
      gap: 8px;
      margin-top: 12px;
    }

    .comment-input-group input {
      flex: 1;
      padding: 10px 12px;
    }

    .comment-input-group button {
      padding: 10px 16px;
      background: #4169E1;
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      font-size: 0.9rem;
      flex-shrink: 0;
    }

    /* Progress Slider */
    .progress-slider {
      margin-top: 12px;
    }

    .progress-slider input[type="range"] {
      width: 100%;
      height: 6px;
      border-radius: 5px;
      background: #e5e7eb;
      outline: none;
      -webkit-appearance: none;
    }

    .progress-slider input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: #4169E1;
      cursor: pointer;
    }

    .progress-slider input[type="range"]::-moz-range-thumb {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: #4169E1;
      cursor: pointer;
    }

    /* No results state */
    .no-results {
      text-align: center;
      padding: 50px 15px;
      color: #666;
    }

    .no-results i {
      font-size: 3rem;
      color: #ddd;
      margin-bottom: 15px;
    }

    .no-results p {
      margin-bottom: 10px;
      font-size: 1rem;
    }

    /* Bottom Navigation */
    .bottom-nav {
      position: fixed;
      bottom: 10px;
      left: 50%;
      transform: translateX(-50%);
      background: #ffffff;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 4px;
      width: auto;
      max-width: 95%;
      padding: 6px 8px;
      border-radius: 50px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      z-index: 100;
    }

    .bottom-nav a {
      text-align: center;
      color: #9ca3af;
      text-decoration: none;
      font-weight: 500;
      border-radius: 25px;
      padding: 9px 16px;
      font-size: 0.75rem;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      flex-shrink: 0;
    }

    .bottom-nav a i {
      font-size: 0.85rem;
    }

    .bottom-nav a.active { 
      background: #4169E1; 
      color: #fff;
    }

    .bottom-nav a:not(.active):hover {
      color: #4169E1;
      background: #f3f4f6;
    }

    /* Subtasks Styles */
    .subtasks-section {
      margin-top: 15px;
    }
    
    .subtask-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px;
      background: #f8faff;
      border-radius: 8px;
      margin-bottom: 6px;
    }
    
    .subtask-checkbox {
      width: 18px;
      height: 18px;
      border-radius: 4px;
      border: 2px solid #ddd;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.3s;
      flex-shrink: 0;
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
      font-size: 0.85rem;
      margin-bottom: 3px;
      word-break: break-word;
    }
    
    .subtask-title.completed {
      text-decoration: line-through;
      color: #9ca3af;
    }
    
    .subtask-assigned {
      font-size: 0.7rem;
      color: #6b7280;
      font-style: italic;
    }
    
    .subtask-completed-by {
      font-size: 0.7rem;
      color: #10b981;
      font-style: italic;
    }
    
    .add-subtask-form {
      display: flex;
      gap: 8px;
      margin-top: 12px;
      flex-wrap: wrap;
    }
    
    .add-subtask-form input {
      flex: 1;
      padding: 8px 10px;
      border: 1px solid #ddd;
      border-radius: 8px;
      font-size: 0.85rem;
      min-width: 150px;
    }
    
    .add-subtask-form button {
      padding: 8px 12px;
      background: #4169E1;
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.85rem;
      flex-shrink: 0;
    }
    
    .progress-info {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 8px;
      font-size: 0.8rem;
      color: #6b7280;
      flex-wrap: wrap;
    }
    
    .progress-stats {
      font-weight: 600;
      color: #4169E1;
    }

    /* Subtasks in create form */
    .subtask-input-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px;
      background: #f8faff;
      border-radius: 8px;
      margin-bottom: 6px;
    }
    
    .subtask-input-content {
      flex: 1;
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }
    
    .subtask-input-content input {
      flex: 1;
      padding: 6px 10px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 0.85rem;
      min-width: 150px;
    }
    
    .subtask-assign-select {
      min-width: 100px;
      padding: 6px 10px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 0.75rem;
      background: white;
    }
    
    .subtask-input-item button {
      background: #ef4444;
      color: white;
      border: none;
      border-radius: 6px;
      width: 28px;
      height: 28px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .assignment-badge {
      display: inline-block;
      padding: 2px 6px;
      background: #dbeafe;
      color: #1e40af;
      border-radius: 10px;
      font-size: 0.65rem;
      font-weight: 500;
      margin-left: 6px;
      flex-shrink: 0;
    }

    .quick-assign-section {
      background: #f0f9ff;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 15px;
      border: 1px solid #bae6fd;
    }

    .quick-assign-title {
      font-size: 0.85rem;
      font-weight: 600;
      color: #0369a1;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .quick-assign-users {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .quick-assign-user {
      display: flex;
      align-items: center;
      gap: 5px;
      padding: 5px 10px;
      background: white;
      border: 1px solid #bae6fd;
      border-radius: 20px;
      cursor: pointer;
      transition: all 0.3s;
      font-size: 0.75rem;
      flex-shrink: 0;
    }

    .quick-assign-user:hover {
      background: #dbeafe;
      border-color: #4169E1;
    }

    .quick-assign-user.selected {
      background: #4169E1;
      color: white;
      border-color: #4169E1;
    }

    .quick-assign-avatar {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 0.55rem;
      font-weight: 600;
      flex-shrink: 0;
    }

    /* File Upload Styles */
    .file-upload-section {
      background: #f0f9ff;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 15px;
      border: 1px solid #bae6fd;
    }

    .file-upload-title {
      font-size: 0.85rem;
      font-weight: 600;
      color: #0369a1;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .file-upload-area {
      border: 2px dashed #bae6fd;
      border-radius: 8px;
      padding: 15px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s;
      background: white;
    }

    .file-upload-area:hover {
      border-color: #4169E1;
      background: #f8faff;
    }

    .file-upload-area.dragover {
      border-color: #4169E1;
      background: #eff6ff;
    }

    .file-upload-icon {
      font-size: 1.5rem;
      color: #4169E1;
      margin-bottom: 8px;
    }

    .file-upload-text {
      color: #6b7280;
      font-size: 0.85rem;
      margin-bottom: 4px;
    }

    .file-upload-hint {
      color: #9ca3af;
      font-size: 0.75rem;
    }

    .file-preview-container {
      margin-top: 12px;
    }

    .file-preview-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px;
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      margin-bottom: 6px;
    }

    .file-preview-icon {
      color: #4169E1;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .file-preview-info {
      flex: 1;
      min-width: 0;
    }

    .file-preview-name {
      font-size: 0.85rem;
      font-weight: 500;
      color: #333;
      word-break: break-all;
    }

    .file-preview-size {
      font-size: 0.7rem;
      color: #6b7280;
    }

    .file-preview-remove {
      background: #ef4444;
      color: white;
      border: none;
      border-radius: 4px;
      width: 22px;
      height: 22px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.7rem;
      flex-shrink: 0;
    }

    /* Attachments in detail modal */
    .attachments-section {
      margin-top: 15px;
    }

    .attachments-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
      gap: 10px;
      margin-top: 8px;
    }

    .attachment-item {
      transition: all 0.2s ease;
      border-radius: 8px;
      overflow: hidden;
    }

    .attachment-item:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }

    .attachment-image {
      width: 100%;
      height: 70px;
      object-fit: cover;
      border-radius: 8px;
    }

    .attachment-file {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: white;
      border-radius: 8px;
      padding: 12px;
      text-align: center;
      border: 1px solid #e5e7eb;
      cursor: pointer;
      height: 100px;
    }

    .attachment-file:hover {
      background: #f8faff;
      border-color: #4169E1;
    }

    .attachment-file-icon {
      font-size: 1.5rem;
      color: #4169E1;
      margin-bottom: 6px;
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

    .attachment-file-name {
      font-size: 0.7rem;
      color: #333;
      word-break: break-all;
      margin-bottom: 4px;
    }

    /* Status badge */
    .status-badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 600;
      margin-bottom: 12px;
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

    /* Media Queries for Responsiveness */
    @media (max-width: 480px) {
      html {
        font-size: 14px;
      }
      
      header {
        padding: 15px 12px 20px 12px;
      }
      
      .back-btn {
        width: 34px;
        height: 34px;
        font-size: 0.9rem;
      }
      
      .header-title {
        font-size: 1.1rem;
      }
      
      .content {
        padding: 12px;
      }
      
      .search-box {
        padding: 10px 12px;
      }
      
      .filter-tab {
        padding: 6px 12px;
        font-size: 0.75rem;
      }
      
      .task-card {
        padding: 12px;
        margin-bottom: 10px;
      }
      
      .task-title {
        font-size: 0.9rem;
      }
      
      .floating-add-btn {
        width: 50px;
        height: 50px;
        font-size: 1.2rem;
        right: 12px;
        bottom: 70px;
      }
      
      .modal-header {
        padding: 15px 12px;
      }
      
      .modal-header h3 {
        font-size: 1.1rem;
        padding: 0 35px;
      }
      
      .modal-body {
        padding: 15px 12px;
      }
      
      .form-row {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .form-actions {
        grid-template-columns: 1fr;
        gap: 10px;
        padding: 12px;
      }
      
      .btn {
        padding: 12px 20px;
        font-size: 0.9rem;
      }
      
      .bottom-nav {
        bottom: 8px;
        padding: 5px 6px;
        gap: 3px;
      }
      
      .bottom-nav a {
        padding: 7px 12px;
        font-size: 0.7rem;
        gap: 4px;
      }
      
      .bottom-nav a i {
        font-size: 0.8rem;
      }
      
      .attachments-grid {
        grid-template-columns: repeat(auto-fill, minmax(85px, 1fr));
        gap: 8px;
      }
      
      .attachment-file {
        padding: 8px;
        height: 85px;
      }
      
      .attachment-file-icon {
        font-size: 1.3rem;
      }
    }

    @media (max-width: 360px) {
      html {
        font-size: 13px;
      }
      
      .header-title {
        font-size: 1rem;
      }
      
      .filter-tab {
        padding: 5px 10px;
        font-size: 0.7rem;
      }
      
      .task-stats {
        gap: 8px;
      }
      
      .stat-item {
        font-size: 0.7rem;
      }
      
      .user-avatar {
        width: 24px;
        height: 24px;
        font-size: 0.6rem;
      }
      
      .bottom-nav a {
        padding: 6px 10px;
        font-size: 0.65rem;
      }
    }

    @media (min-width: 768px) {
      .content {
        max-width: 768px;
        margin: 0 auto;
      }
      
      .bottom-nav {
        max-width: 768px;
        padding: 8px 15px;
      }
      
      .bottom-nav a {
        padding: 10px 20px;
        font-size: 0.85rem;
      }
      
      .bottom-nav a i {
        font-size: 1rem;
      }
    }

    @media (min-width: 1024px) {
      body {
        padding-bottom: 20px;
      }
      
      .floating-add-btn {
        right: 30px;
        bottom: 30px;
        width: 60px;
        height: 60px;
        font-size: 1.4rem;
      }
      
      .bottom-nav {
        bottom: 20px;
        padding: 10px 20px;
      }
      
      .bottom-nav a {
        padding: 12px 24px;
        font-size: 0.9rem;
      }
    }

    /* Scrollbar Styling */
    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    ::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb {
      background: #c1c1c1;
      border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: #a1a1a1;
    }

    /* Touch-friendly improvements */
    @media (hover: none) and (pointer: coarse) {
      .task-menu:hover {
        color: #9ca3af;
      }
      
      .stat-item:hover {
        color: #6b7280;
      }
      
      .btn:hover {
        transform: none;
      }
      
      .floating-add-btn:hover {
        transform: none;
        box-shadow: 0 6px 20px rgba(65, 105, 225, 0.4);
      }
      
      .quick-assign-user:hover {
        background: white;
        border-color: #bae6fd;
      }
      
      .quick-assign-user.selected:hover {
        background: #4169E1;
        border-color: #4169E1;
      }
    }
  </style>
</head>
<body>

  <!-- Header -->
  <header>
    <div class="header-content">
      <button class="back-btn" onclick="window.location.href='dashboard.php'">
        <i class="fas fa-arrow-left"></i>
      </button>
      <div class="header-title">Tugas</div>
    </div>
  </header>

  <!-- Content -->
  <div class="content">
    <!-- Search Box -->
    <div class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search task" id="searchInput">
    </div>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
      <button class="filter-tab active" data-status="all">Semua Tugas</button>
      <button class="filter-tab" data-status="todo">Belum Dimulai</button>
      <button class="filter-tab" data-status="progress">Sedang Berjalan</button>
      <button class="filter-tab" data-status="completed">Selesai</button>
    </div>

    <!-- Task Cards Container -->
    <div id="tasksContainer">
      <!-- Tasks will be populated here -->
    </div>
  </div>

  <!-- Floating Add Button -->
  <button class="floating-add-btn" id="addTaskBtn">
    <i class="fas fa-plus"></i>
  </button>

  <!-- Add Task Modal -->
  <div class="modal" id="addTaskModal">
    <div class="modal-content">
      <div class="modal-header">
        <button class="modal-back-btn" id="closeModalBtn">
          <i class="fas fa-arrow-left"></i>
        </button>
        <h3>Tugas Baru</h3>
      </div>
      
      <div class="modal-body">
        <div class="form-title">Form Tugas - Bagikan ke Teman</div>
        
        <form id="addTaskForm" enctype="multipart/form-data">
          <div class="form-group">
            <label for="taskTitle">Judul Tugas</label>
            <input type="text" id="taskTitle" placeholder="Contoh: Develop Website E-commerce" required>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="startDate">Tanggal Mulai</label>
              <div class="date-input-wrapper">
                <input type="date" id="startDate" required>
                <i class="far fa-calendar-alt"></i>
              </div>
            </div>

            <div class="form-group">
              <label for="endDate">Tanggal Selesai</label>
              <div class="date-input-wrapper">
                <input type="date" id="endDate" required>
                <i class="far fa-calendar-alt"></i>
              </div>
            </div>
          </div>

          <div class="divider"></div>

          <!-- File Upload Section -->
          <div class="file-upload-section">
            <div class="file-upload-title">
              <i class="fas fa-paperclip"></i>
              Lampirkan Foto/Dokumen:
            </div>
            <div class="file-upload-area" id="fileUploadArea">
              <div class="file-upload-icon">
                <i class="fas fa-cloud-upload-alt"></i>
              </div>
              <div class="file-upload-text">Klik atau drag file ke sini untuk upload</div>
              <div class="file-upload-hint">Maksimal 5 file, masing-masing maksimal 5MB</div>
              <input type="file" id="fileInput" multiple accept="image/*,.pdf,.doc,.docx" style="display: none;">
            </div>
            <div class="file-preview-container" id="filePreviewContainer">
              <!-- File previews will be added here -->
            </div>
          </div>

          <!-- Quick Assign Section -->
          <div class="quick-assign-section">
            <div class="quick-assign-title">
              <i class="fas fa-users"></i>
              Pilih Teman untuk Bagi Tugas:
            </div>
            <div class="quick-assign-users" id="quickAssignUsers">
              <?php
              $userIndex = 1;
              while ($userRow = $usersResult->fetch_assoc()) {
                  $initial = strtoupper(substr($userRow['username'], 0, 1));
                  $userClass = 'user' . ($userIndex % 4 + 1);
                  echo '<div class="quick-assign-user" data-user-id="'.$userRow['id'].'" data-user-name="'.$userRow['username'].'">';
                  echo '<div class="quick-assign-avatar '.$userClass.'">'.$initial.'</div>';
                  echo '<span>'.$userRow['username'].'</span>';
                  echo '</div>';
                  $userIndex++;
              }
              ?>
            </div>
          </div>

          <div class="form-group">
            <label for="assignMember">Anggota Tim yang Dipilih</label>
            <div class="user-selection">
              <input type="text" id="assignMember" placeholder="Klik untuk pilih teman..." readonly>
              <div class="user-dropdown" id="userDropdown">
                <?php
                $usersResult->data_seek(0);
                $userIndex = 1;
                while ($userRow = $usersResult->fetch_assoc()) {
                    $initial = strtoupper(substr($userRow['username'], 0, 1));
                    $userClass = 'user' . ($userIndex % 4 + 1);
                    echo '<div class="user-dropdown-item" data-user-id="'.$userRow['id'].'" data-user-name="'.$userRow['username'].'">';
                    echo '<div class="user-dropdown-avatar '.$userClass.'">'.$initial.'</div>';
                    echo '<span>'.$userRow['username'].'</span>';
                    echo '</div>';
                    $userIndex++;
                }
                ?>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label>Subtasks / Pekerjaan (Bisa assign ke teman spesifik)</label>
            <div id="subtasksContainer">
              <!-- Subtasks will be added here -->
            </div>
            <div class="add-subtask-form">
              <input type="text" id="newSubtask" placeholder="Tambah pekerjaan...">
              <select id="subtaskAssign" class="subtask-assign-select">
                <option value="">Pilih teman...</option>
                <?php
                $usersResult->data_seek(0);
                while ($userRow = $usersResult->fetch_assoc()) {
                    echo '<option value="'.$userRow['username'].'">'.$userRow['username'].'</option>';
                }
                ?>
              </select>
              <button type="button" onclick="addSubtaskInput()">
                <i class="fas fa-plus"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label for="taskNote">Catatan</label>
            <textarea id="taskNote" placeholder="Tambahkan catatan atau instruksi..."></textarea>
          </div>

          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="cancelBtn">Batal</button>
            <button type="submit" class="btn btn-save">Bagikan Tugas</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- NAVIGASI BARU - SESUAI GAMBAR -->
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
  <i class="fa-solid fa-clipboard-list"></i>
  <span>Tugas Default</span>
</a>
    <a href="profile.php">
      <i class="fa-solid fa-user"></i>
      <span>Profil</span>
    </a>
  </div>

  <script>
    // Load tasks from database
    const tasksFromDB = <?php
      $tasksArray = [];
      if ($tasksResult->num_rows > 0) {
          $tasksResult->data_seek(0);
          while ($row = $tasksResult->fetch_assoc()) {
              $isOverdue = false;
              if ($row['end_date'] && $row['status'] !== 'completed') {
                  $endDate = strtotime($row['end_date']);
                  $currentDate = strtotime(date('Y-m-d'));
                  $isOverdue = $currentDate > $endDate;
              }

              $tasksArray[] = [
                  'id' => $row['id'],
                  'title' => $row['title'],
                  'category' => $row['category'],
                  'progress' => (int)$row['progress'],
                  'status' => $row['status'],
                  'assignedUsers' => $row['assigned_users'],
                  'tasks' => ['completed' => (int)$row['tasks_completed'], 'total' => (int)$row['tasks_total']],
                  'comments' => (int)$row['comments'],
                  'startDate' => $row['start_date'],
                  'endDate' => $row['end_date'],
                  'note' => $row['note'],
                  'attachments' => $row['attachments'],
                  'isOverdue' => $isOverdue
              ];
          }
      }
      echo json_encode($tasksArray);
    ?>;

    let tasks = tasksFromDB;
    let selectedUsers = [];
    let currentTaskId = null;
    let subtasks = [];
    let selectedFiles = [];

    // DOM Elements
    const tasksContainer = document.getElementById('tasksContainer');
    const searchInput = document.getElementById('searchInput');
    const addTaskBtn = document.getElementById('addTaskBtn');
    const addTaskModal = document.getElementById('addTaskModal');
    const addTaskForm = document.getElementById('addTaskForm');
    const cancelBtn = document.getElementById('cancelBtn');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const filterTabs = document.querySelectorAll('.filter-tab');
    const assignMemberInput = document.getElementById('assignMember');
    const userDropdown = document.getElementById('userDropdown');
    const subtasksContainer = document.getElementById('subtasksContainer');
    const quickAssignUsers = document.getElementById('quickAssignUsers');
    const fileUploadArea = document.getElementById('fileUploadArea');
    const fileInput = document.getElementById('fileInput');
    const filePreviewContainer = document.getElementById('filePreviewContainer');

    // Initialize the app
    function initApp() {
      renderTasks();
      setupEventListeners();
      setupFileUpload();
    }

    // Setup file upload functionality
    function setupFileUpload() {
      fileUploadArea.addEventListener('click', () => {
        fileInput.click();
      });

      fileInput.addEventListener('change', handleFileSelect);

      fileUploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        fileUploadArea.classList.add('dragover');
      });

      fileUploadArea.addEventListener('dragleave', () => {
        fileUploadArea.classList.remove('dragover');
      });

      fileUploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        fileUploadArea.classList.remove('dragover');
        
        if (e.dataTransfer.files.length > 0) {
          fileInput.files = e.dataTransfer.files;
          handleFileSelect();
        }
      });
    }

    function handleFileSelect() {
      const files = Array.from(fileInput.files);
      
      const validFiles = files.filter(file => {
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
          alert(`File ${file.name} terlalu besar. Maksimal 5MB.`);
          return false;
        }
        return true;
      });

      if (selectedFiles.length + validFiles.length > 5) {
        alert('Maksimal 5 file yang dapat diupload.');
        return;
      }

      selectedFiles = [...selectedFiles, ...validFiles];
      renderFilePreviews();
      fileInput.value = '';
    }

    function renderFilePreviews() {
      filePreviewContainer.innerHTML = '';

      selectedFiles.forEach((file, index) => {
        const fileSize = (file.size / (1024 * 1024)).toFixed(2);
        const isImage = file.type.startsWith('image/');
        
        const previewItem = document.createElement('div');
        previewItem.className = 'file-preview-item';
        
        previewItem.innerHTML = `
          <div class="file-preview-icon">
            <i class="fas ${isImage ? 'fa-image' : 'fa-file'}"></i>
          </div>
          <div class="file-preview-info">
            <div class="file-preview-name">${file.name}</div>
            <div class="file-preview-size">${fileSize} MB</div>
          </div>
          <button type="button" class="file-preview-remove" onclick="removeFile(${index})">
            <i class="fas fa-times"></i>
          </button>
        `;
        
        filePreviewContainer.appendChild(previewItem);
      });
    }

    function removeFile(index) {
      selectedFiles.splice(index, 1);
      renderFilePreviews();
    }

    function renderTasks(filteredTasks = null) {
      const tasksToRender = filteredTasks || tasks;
      
      if (tasksToRender.length === 0) {
        tasksContainer.innerHTML = `
          <div class="no-results">
            <i class="fas fa-tasks"></i>
            <p>Belum ada tugas</p>
            <p>Buat tugas baru dan bagikan ke teman-teman!</p>
          </div>
        `;
        return;
      }

      tasksContainer.innerHTML = tasksToRender.map(task => {
        let category = task.category;
        let categoryClass = '';
        if (task.isOverdue) {
          category = 'Terlambat';
          categoryClass = 'terlambat';
        } else {
          categoryClass = task.status === 'progress' ? 'sedang' : task.status === 'completed' ? 'selesai' : '';
        }
        const assignedUsersArray = task.assignedUsers ? task.assignedUsers.split(',') : [];
        const hasAttachments = task.attachments && task.attachments.trim() !== '';

        return `
        <div class="task-card ${task.isOverdue ? 'overdue' : ''}" onclick="window.location.href='task_detail.php?id=${task.id}'">
          <div class="task-header">
            <div>
              <div class="task-category ${categoryClass}">${category}</div>
              <div class="task-title">${task.title}</div>
            </div>
            <button class="task-menu" onclick="event.stopPropagation(); toggleTaskMenu(${task.id})">
              <i class="fas fa-ellipsis-v"></i>
              <div class="task-dropdown-menu" id="menu-${task.id}">
                <div class="task-dropdown-item" onclick="event.stopPropagation(); window.location.href='task_detail.php?id=${task.id}'">
                  <i class="fas fa-eye"></i> Lihat Detail
                </div>
                <div class="task-dropdown-item" onclick="event.stopPropagation(); window.location.href='edit_task.php?id=${task.id}'">
                  <i class="fas fa-edit"></i> Edit
                </div>
                <div class="task-dropdown-item delete" onclick="event.stopPropagation(); confirmDelete(${task.id})">
                  <i class="fas fa-trash"></i> Hapus
                </div>
              </div>
            </button>
          </div>

          <div class="progress-section">
            <div class="progress-label">
              <span>Progress</span>
              <span class="progress-percentage">${task.progress}%</span>
            </div>
            <div class="progress-bar-container">
              <div class="progress-bar-fill" style="width: ${task.progress}%"></div>
            </div>
          </div>

          <div class="task-stats">
            <div class="stat-item" title="Subtasks completed">
              <i class="far fa-check-square"></i>
              <span>${task.tasks.completed}</span>
            </div>
            <div class="stat-item" title="Total tasks">
              <i class="far fa-list-alt"></i>
              <span>${task.tasks.total}</span>
            </div>
            <div class="stat-item" title="Komentar" onclick="event.stopPropagation(); window.location.href='task_detail.php?id=${task.id}'">
              <i class="far fa-comment"></i>
              <span>${task.comments}</span>
            </div>
            ${hasAttachments ? `
            <div class="stat-item" title="Lampiran">
              <i class="fas fa-paperclip"></i>
              <span>${task.attachments.split(',').length}</span>
            </div>
            ` : ''}
            <div class="assigned-users">
              ${assignedUsersArray.slice(0, 3).map((userName, index) => {
                const initial = userName.trim().charAt(0).toUpperCase();
                const userClass = `user${(index % 4) + 1}`;
                return `<div class="user-avatar ${userClass}" title="${userName.trim()}">${initial}</div>`;
              }).join('')}
              ${assignedUsersArray.length > 3 ? `<div class="user-avatar" style="background: #6b7280;">+${assignedUsersArray.length - 3}</div>` : ''}
            </div>
          </div>
        </div>
      `}).join('');
    }

    function setupEventListeners() {
      searchInput.addEventListener('input', handleSearch);
      
      addTaskBtn.addEventListener('click', () => {
        addTaskModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
      });

      cancelBtn.addEventListener('click', closeModal);
      closeModalBtn.addEventListener('click', closeModal);

      addTaskModal.addEventListener('click', (e) => {
        if (e.target === addTaskModal) closeModal();
      });

      quickAssignUsers.addEventListener('click', (e) => {
        const userElement = e.target.closest('.quick-assign-user');
        if (userElement) {
          userElement.classList.toggle('selected');
          const userId = userElement.getAttribute('data-user-id');
          const userName = userElement.getAttribute('data-user-name');
          
          const index = selectedUsers.findIndex(u => u.id === userId);
          if (index > -1) {
            selectedUsers.splice(index, 1);
          } else {
            selectedUsers.push({ id: userId, name: userName });
          }
          
          updateAssignMemberInput();
        }
      });

      assignMemberInput.addEventListener('click', () => {
        userDropdown.classList.toggle('active');
      });

      document.querySelectorAll('.user-dropdown-item').forEach(item => {
        item.addEventListener('click', function() {
          const userId = this.getAttribute('data-user-id');
          const userName = this.getAttribute('data-user-name');

          const index = selectedUsers.findIndex(u => u.id === userId);
          if (index > -1) {
            selectedUsers.splice(index, 1);
            this.classList.remove('selected');
            const quickUser = document.querySelector(`.quick-assign-user[data-user-id="${userId}"]`);
            if (quickUser) quickUser.classList.remove('selected');
          } else {
            selectedUsers.push({ id: userId, name: userName });
            this.classList.add('selected');
            const quickUser = document.querySelector(`.quick-assign-user[data-user-id="${userId}"]`);
            if (quickUser) quickUser.classList.add('selected');
          }

          updateAssignMemberInput();
          userDropdown.classList.remove('active');
        });
      });

      document.getElementById('newSubtask')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') addSubtaskInput();
      });

      filterTabs.forEach(tab => {
        tab.addEventListener('click', () => {
          filterTabs.forEach(t => t.classList.remove('active'));
          tab.classList.add('active');
          filterTasksByStatus(tab.getAttribute('data-status'));
        });
      });

      addTaskForm.addEventListener('submit', handleAddTask);
      
      // Close dropdowns when clicking outside
      document.addEventListener('click', (e) => {
        if (!e.target.closest('.task-menu') && !e.target.closest('.task-dropdown-menu')) {
          document.querySelectorAll('.task-dropdown-menu').forEach(menu => {
            menu.classList.remove('active');
          });
        }
        
        if (!e.target.closest('.user-selection') && !e.target.closest('.user-dropdown')) {
          userDropdown.classList.remove('active');
        }
      });
    }

    function addSubtaskInput() {
      const input = document.getElementById('newSubtask');
      const assignSelect = document.getElementById('subtaskAssign');
      const title = input.value.trim();
      const assignedTo = assignSelect.value;
      
      if (title === '') return;
      
      const subtaskId = Date.now();
      const assignedBadge = assignedTo ? `<span class="assignment-badge">${assignedTo}</span>` : '';
      
      const subtaskHTML = `
        <div class="subtask-input-item" data-id="${subtaskId}">
          <div class="subtask-input-content">
            <input type="text" value="${title}" readonly>
            ${assignedBadge}
          </div>
          <button type="button" onclick="removeSubtaskInput(${subtaskId})">
            <i class="fas fa-times"></i>
          </button>
          <input type="hidden" name="subtasks[]" value="${title}">
          <input type="hidden" name="subtaskAssignments[]" value="${assignedTo}">
        </div>
      `;
      
      subtasksContainer.insertAdjacentHTML('beforeend', subtaskHTML);
      input.value = '';
      assignSelect.value = '';
    }

    function removeSubtaskInput(id) {
      const element = document.querySelector(`[data-id="${id}"]`);
      if (element) element.remove();
    }

    function updateAssignMemberInput() {
      if (selectedUsers.length === 0) {
        assignMemberInput.value = '';
        assignMemberInput.placeholder = 'Klik untuk pilih teman...';
      } else {
        assignMemberInput.value = selectedUsers.map(u => u.name).join(', ');
      }
    }

    function closeModal() {
      addTaskModal.style.display = 'none';
      document.body.style.overflow = 'auto';
      addTaskForm.reset();
      selectedUsers = [];
      selectedFiles = [];
      updateAssignMemberInput();
      document.querySelectorAll('.user-dropdown-item').forEach(item => {
        item.classList.remove('selected');
      });
      document.querySelectorAll('.quick-assign-user').forEach(user => {
        user.classList.remove('selected');
      });
      subtasksContainer.innerHTML = '';
      filePreviewContainer.innerHTML = '';
    }

    function handleSearch() {
      const searchTerm = searchInput.value.toLowerCase().trim();
      
      if (searchTerm === '') {
        renderTasks();
        return;
      }

      const filteredTasks = tasks.filter(task => 
        task.title.toLowerCase().includes(searchTerm) ||
        task.category.toLowerCase().includes(searchTerm)
      );

      renderTasks(filteredTasks);
    }

    function filterTasksByStatus(status) {
      if (status === 'all') {
        renderTasks();
        return;
      }

      const filteredTasks = tasks.filter(task => task.status === status);
      renderTasks(filteredTasks);
    }

    async function handleAddTask(e) {
      e.preventDefault();
      
      const title = document.getElementById('taskTitle').value;
      const startDate = document.getElementById('startDate').value;
      const endDate = document.getElementById('endDate').value;
      const note = document.getElementById('taskNote').value;
      const assignedUsers = selectedUsers.map(u => u.name).join(',');
      
      const subtaskInputs = document.querySelectorAll('input[name="subtasks[]"]');
      const subtaskAssignmentInputs = document.querySelectorAll('input[name="subtaskAssignments[]"]');
      const subtasks = Array.from(subtaskInputs).map(input => input.value);
      const subtaskAssignments = Array.from(subtaskAssignmentInputs).map(input => input.value);
      
      if (selectedUsers.length === 0) {
        alert('Pilih minimal 1 teman untuk membagikan tugas!');
        return;
      }

      const formData = new FormData();
      formData.append('action', 'add_task');
      formData.append('title', title);
      formData.append('startDate', startDate);
      formData.append('endDate', endDate);
      formData.append('note', note);
      formData.append('assignedUsers', assignedUsers);
      subtasks.forEach(subtask => {
        formData.append('subtasks[]', subtask);
      });
      subtaskAssignments.forEach(assignment => {
        formData.append('subtaskAssignments[]', assignment);
      });

      selectedFiles.forEach(file => {
        formData.append('attachments[]', file);
      });

      try {
        const response = await fetch('tasks.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          alert('Tugas berhasil dibuat dan dibagikan ke teman-teman!');
          location.reload();
        } else {
          alert('Gagal menambahkan task: ' + result.message);
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menambahkan task');
      }
    }

    // Toggle task menu
    function toggleTaskMenu(taskId) {
      const menu = document.getElementById('menu-' + taskId);
      const allMenus = document.querySelectorAll('.task-dropdown-menu');

      allMenus.forEach(m => {
        if (m !== menu) m.classList.remove('active');
      });

      if (!menu.classList.contains('active')) {
        menu.classList.add('active');
      } else {
        menu.classList.remove('active');
      }
    }

    // Edit task function
    function editTask(taskId) {
      window.location.href = 'edit_task.php?id=' + taskId;
    }

    // Confirm delete
    function confirmDelete(taskId) {
      if (confirm('Apakah Anda yakin ingin menghapus tugas ini?')) {
        deleteTaskById(taskId);
      }
    }

    // Delete task by ID
    async function deleteTaskById(taskId) {
      try {
        const formData = new FormData();
        formData.append('action', 'delete_task');
        formData.append('taskId', taskId);

        const response = await fetch('tasks.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          // Remove from local array
          tasks = tasks.filter(t => t.id !== taskId);
          renderTasks();
          alert('Tugas berhasil dihapus');
        } else {
          alert('Gagal menghapus tugas');
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menghapus tugas');
      }
    }

    // Initialize the app when DOM is loaded
    document.addEventListener('DOMContentLoaded', initApp);
    
    // Handle window resize
    window.addEventListener('resize', function() {
      // Re-render tasks on orientation change for better layout
      if (window.innerWidth <= 480) {
        document.querySelectorAll('.task-stats').forEach(stats => {
          stats.style.gap = '8px';
        });
      }
    });
  </script>
</body>    
</html>