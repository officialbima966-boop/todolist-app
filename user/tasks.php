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
                        $attachments[] = $fileName;
                    }
                }
            }
        }

        $attachmentsString = !empty($attachments) ? implode(',', $attachments) : '';

        // Insert main task
        $sql = "INSERT INTO tasks (title, start_date, end_date, note, assigned_users, attachments, created_by, tasks_total)
                VALUES ('$title', '$startDate', '$endDate', '$note', '$assignedUsers', '$attachmentsString', '$username', " . count($subtasks) . ")";

        if ($mysqli->query($sql)) {
            $taskId = $mysqli->insert_id;

            // Insert subtasks dengan assign
            foreach ($subtasks as $index => $subtaskTitle) {
                $subtaskTitle = $mysqli->real_escape_string($subtaskTitle);
                $assignedTo = isset($subtaskAssignments[$index]) ? $mysqli->real_escape_string($subtaskAssignments[$index]) : '';

                $mysqli->query("INSERT INTO task_subtasks (task_id, title, assigned_to) VALUES ($taskId, '$subtaskTitle', '$assignedTo')");
            }

            echo json_encode(['success' => true, 'message' => 'Task berhasil ditambahkan dan dibagikan ke teman!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menambahkan task']);
        }
        exit;
    }
}

// Ambil semua tugas yang ditugaskan ke user ini atau dibuat oleh user ini
$tasksQuery = $mysqli->prepare("
    SELECT * FROM tasks 
    WHERE assigned_users LIKE ? OR created_by = ?
    ORDER BY created_at DESC
");
$searchPattern = "%" . $username . "%";
$tasksQuery->bind_param("ss", $searchPattern, $username);
$tasksQuery->execute();
$tasksResult = $tasksQuery->get_result();

$allTasks = [];
$belumDimulai = [];
$sedangBerjalan = [];
$selesai = [];

while ($row = $tasksResult->fetch_assoc()) {
    $allTasks[] = $row;
    
    // Pisahkan berdasarkan status
    if ($row['status'] === 'todo') {
        $belumDimulai[] = $row;
    } elseif ($row['status'] === 'progress') {
        $sedangBerjalan[] = $row;
    } elseif ($row['status'] === 'completed') {
        $selesai[] = $row;
    }
}

// Default: tampilkan semua tugas
$tasksToShow = $allTasks;
$activeFilter = 'all';

// Jika ada parameter filter, tampilkan sesuai filter
if (isset($_GET['filter'])) {
    $filter = $_GET['filter'];
    $activeFilter = $filter;
    
    switch ($filter) {
        case 'todo':
            $tasksToShow = $belumDimulai;
            break;
        case 'progress':
            $tasksToShow = $sedangBerjalan;
            break;
        case 'completed':
            $tasksToShow = $selesai;
            break;
        default:
            $tasksToShow = $allTasks;
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tasks - <?= htmlspecialchars($userData['nama']) ?></title>
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
            padding-bottom: 100px;
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

        /* Search Box in Header */
        .search-box-header {
            background: white;
            border-radius: 12px;
            padding: 10px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-box-header i {
            color: #9ca3af;
            font-size: 16px;
        }

        .search-box-header input {
            border: none;
            outline: none;
            flex: 1;
            font-size: 14px;
            color: #333;
            background: transparent;
        }

        .search-box-header input::placeholder {
            color: #b0b7c3;
        }

        /* Content */
        .content {
            padding: 15px;
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
            border: 1px solid #e5e7eb;
            background: white;
            color: #6b7280;
            font-weight: 500;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.3s;
            flex-shrink: 0;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .filter-tab.active {
            background: #3550dc;
            color: white;
            border-color: #3550dc;
        }

        /* Task Card */
        .task-card {
            background: white;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid #f0f3f8;
            transition: all 0.3s;
            position: relative;
        }

        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .task-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .task-status-label {
            font-size: 10px;
            font-weight: 600;
            margin-bottom: 5px;
            text-transform: capitalize;
            letter-spacing: 0.3px;
        }

        .task-status-label.belum {
            color: #f59e0b;
        }

        .task-status-label.sedang {
            color: #3550dc;
        }

        .task-status-label.selesai {
            color: #10b981;
        }

        .task-title {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            line-height: 1.4;
            margin-bottom: 8px;
        }

        /* Task Menu Styles - SAMA DENGAN USERS.PHP */
        .task-menu-container {
            position: relative;
        }

        .task-menu-btn {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 16px;
            padding: 5px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            position: relative;
            cursor: pointer;
            flex-shrink: 0;
        }

        .task-menu-btn:hover {
            color: #4169E1;
        }

        .task-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
            z-index: 100;
            min-width: 140px;
            overflow: hidden;
        }

        .task-menu-dropdown.active {
            display: block;
        }

        .task-menu-item {
            padding: 10px 14px;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }

        .task-menu-item:hover {
            background: #f3f4f6;
        }

        .task-menu-item i {
            width: 18px;
        }

        .task-menu-item.delete {
            color: #ef4444;
        }

        /* Overlay for menu */
        .menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: transparent;
            z-index: 999;
            display: none;
            pointer-events: auto;
        }

        .task-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .task-status-badge.todo {
            background: #fef3c7;
            color: #92400e;
        }

        .task-status-badge.progress {
            background: #e8ecf4;
            color: #3550dc;
        }

        .task-status-badge.completed {
            background: #d1fae5;
            color: #065f46;
        }

        .task-progress-container {
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
            height: 6px;
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

        /* Task Footer */
        .task-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 10px;
            border-top: 1px solid #f0f3f8;
        }

        .task-icons {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .task-icon-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            color: #6b7280;
        }

        .task-icon-item i {
            font-size: 13px;
        }

        .task-avatars {
            display: flex;
            align-items: center;
            gap: -8px;
        }

        .task-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid white;
            margin-left: -8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .task-avatar:first-child {
            margin-left: 0;
        }

        .avatar-color-1 {
            background: linear-gradient(135deg, #f59e0b, #f97316);
        }

        .avatar-color-2 {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .avatar-color-3 {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .avatar-color-4 {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        /* Floating Add Button */
        .floating-add-btn {
            position: fixed;
            right: 15px;
            bottom: 80px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            border: none;
            border-radius: 50%;
            width: 55px;
            height: 55px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(30, 58, 138, 0.4);
            z-index: 99;
            transition: all 0.3s ease;
        }

        .floating-add-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 8px 25px rgba(30, 58, 138, 0.5);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 15px;
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
            border: 1px solid #e5e7eb;
        }

        .bottom-nav::-webkit-scrollbar {
            display: none;
        }

        .bottom-nav a {
            text-align: center;
            color: #6b7280;
            text-decoration: none;
            font-weight: 500;
            border-radius: 25px;
            padding: 10px 18px;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .bottom-nav a i {
            font-size: 16px;
        }

        .bottom-nav a.active {
            background: #3550dc;
            color: #fff;
        }

        .bottom-nav a:not(.active):hover {
            color: #3550dc;
            background: #f3f4f6;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            overflow-y: auto;
        }

        .modal.show {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 20px 0;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        /* Task Detail Modal Styles */
        .task-detail-modal .modal-content {
            max-width: 800px;
        }

        .task-detail-header {
            display: flex;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #3550dc;
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .task-detail-back-btn {
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
            margin-right: 12px;
            transition: all 0.3s;
        }

        .task-detail-back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .task-detail-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .task-detail-body {
            padding: 20px;
        }

        .modal-header {
            display: flex;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #3550dc;
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .modal-back-btn {
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
            margin-right: 12px;
            transition: all 0.3s;
        }

        .modal-back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .modal-body {
            padding: 20px;
        }

        .form-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
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
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
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

        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 20px 0;
        }

        /* File Upload Styles */
        .file-upload-section {
            margin-bottom: 20px;
        }

        .file-upload-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
        }

        .file-upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f9fafb;
        }

        .file-upload-area:hover,
        .file-upload-area.dragover {
            border-color: #3550dc;
            background: #f8faff;
        }

        .file-upload-icon {
            font-size: 32px;
            color: #9ca3af;
            margin-bottom: 12px;
        }

        .file-upload-text {
            font-size: 14px;
            color: #374151;
            margin-bottom: 4px;
        }

        .file-upload-hint {
            font-size: 12px;
            color: #9ca3af;
        }

        .file-preview-container {
            margin-top: 12px;
        }

        .file-preview {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f3f4f6;
            padding: 8px 12px;
            border-radius: 8px;
            margin-right: 8px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .file-preview .remove-file {
            color: #ef4444;
            cursor: pointer;
            font-weight: bold;
        }

        /* Quick Assign Styles */
        .quick-assign-section {
            margin-bottom: 20px;
        }

        .quick-assign-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
        }

        .quick-assign-users {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .quick-assign-user {
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

        .quick-assign-user.selected {
            background: #eff6ff;
            border-color: #1e3a8a;
        }

        .quick-assign-avatar {
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

        .quick-assign-user span {
            font-size: 12px;
            color: #374151;
        }

        .user-selection {
            position: relative;
        }

        .user-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-dropdown-item:hover {
            background: #f3f4f6;
        }

        .user-dropdown-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }

        .user-dropdown-item span {
            font-size: 14px;
            color: #374151;
        }

        /* Subtasks Styles */
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

        .subtask-assigned {
            font-size: 12px;
            color: #6b7280;
            font-style: italic;
        }

        .remove-subtask {
            color: #ef4444;
            cursor: pointer;
            font-weight: bold;
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

        .add-subtask-form select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: white;
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

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        .btn {
            flex: 1;
            padding: 12px 20px;
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

        /* Responsive */
        @media (max-width: 480px) {
            .container {
                padding: 0;
            }

            .content {
                padding: 12px;
            }

            .header {
                padding: 15px 12px 20px 12px;
            }

            .floating-add-btn {
                width: 50px;
                height: 50px;
                font-size: 18px;
                right: 12px;
                bottom: 70px;
            }

            .bottom-nav {
                max-width: 96%;
                padding: 6px;
            }

            .bottom-nav a {
                padding: 8px 14px;
                font-size: 12px;
            }

            .bottom-nav a i {
                font-size: 16px;
            }

            .modal {
                padding: 10px 0;
            }

            .modal-content {
                width: 95%;
                max-height: 95vh;
            }

            .modal-header {
                padding: 15px;
            }

            .modal-body {
                padding: 15px;
            }

            .form-row {
                flex-direction: column;
                gap: 16px;
            }

            .quick-assign-users {
                gap: 6px;
            }

            .quick-assign-user {
                padding: 6px 10px;
                font-size: 11px;
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
                <button class="back-btn" onclick="window.location.href='dashboard.php'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">Task</div>
            </div>
            
            <!-- Search Box -->
            <div class="search-box-header">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search task" id="searchInput">
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?filter=all" class="filter-tab <?= $activeFilter === 'all' ? 'active' : '' ?>">All Task</a>
                <a href="?filter=todo" class="filter-tab <?= $activeFilter === 'todo' ? 'active' : '' ?>">Belum Dimulai</a>
                <a href="?filter=progress" class="filter-tab <?= $activeFilter === 'progress' ? 'active' : '' ?>">Sedang Berjalan</a>
                <a href="?filter=completed" class="filter-tab <?= $activeFilter === 'completed' ? 'active' : '' ?>">Selesai</a>
            </div>

            <!-- Tasks Container -->
            <div id="tasksContainer">
                <?php if (!empty($tasksToShow)): ?>
                    <?php foreach ($tasksToShow as $task): 
                        $statusLabel = '';
                        $statusClass = '';
                        if ($task['status'] === 'progress') {
                            $statusLabel = 'Sedang Berjalan';
                            $statusClass = 'sedang';
                        } elseif ($task['status'] === 'completed') {
                            $statusLabel = 'Selesai';
                            $statusClass = 'selesai';
                        } else {
                            $statusLabel = 'Belum Dikerjakan';
                            $statusClass = 'belum';
                        }
                        
                        $assignedUsers = $task['assigned_users'] ? explode(',', $task['assigned_users']) : [];
                    ?>
                        <div class="task-card" data-task-id="<?= $task['id'] ?>">
                            <div class="task-card-header">
                                <div style="flex: 1;">
                                    <div class="task-status-label <?= $statusClass ?>"><?= $statusLabel ?></div>
                                    <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                                </div>
                                <div class="task-menu-container">
                                    <button class="task-menu-btn" onclick="event.stopPropagation(); toggleTaskMenu(<?= $task['id'] ?>)">
                                        <i class="fas fa-ellipsis-v"></i>
                                        <div class="task-menu-dropdown" id="taskMenu-<?= $task['id'] ?>">
                                            <div class="task-menu-item" onclick="event.stopPropagation(); viewTaskDetail(<?= $task['id'] ?>)">
                                                <i class="fas fa-eye"></i> Lihat Detail
                                            </div>
                                            <div class="task-menu-item" onclick="event.stopPropagation(); editTask(<?= $task['id'] ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </div>
                                            <div class="task-menu-item delete" onclick="event.stopPropagation(); deleteTask(<?= $task['id'] ?>)">
                                                <i class="fas fa-trash"></i> Hapus
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <div class="task-status-badge <?= $task['status'] ?>">
                                <?= ucfirst($task['status']) ?>
                            </div>

                            <div class="task-progress-container">
                                <div class="progress-label">
                                    <span class="progress-label-text">Progress</span>
                                    <span class="progress-percentage"><?= $task['progress'] ?>%</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $task['progress'] ?>%"></div>
                                </div>
                            </div>

                            <div class="task-footer">
                                <div class="task-icons">
                                    <div class="task-icon-item" title="Subtasks">
                                        <i class="far fa-check-square"></i>
                                        <span><?= $task['tasks_completed'] ?></span>
                                    </div>
                                    <div class="task-icon-item" title="Comments">
                                        <i class="far fa-comment"></i>
                                        <span><?= $task['comments'] ?></span>
                                    </div>
                                    <div class="task-icon-item" title="Attachments">
                                        <i class="fas fa-paperclip"></i>
                                        <span><?= $task['attachments'] ? count(explode(',', $task['attachments'])) : 0 ?></span>
                                    </div>
                                </div>

                                <div class="task-avatars">
                                    <?php 
                                    $maxAvatars = 3;
                                    $displayUsers = array_slice($assignedUsers, 0, $maxAvatars);
                                    foreach ($displayUsers as $index => $user): 
                                        $initial = strtoupper(substr(trim($user), 0, 1));
                                        $colorClass = 'avatar-color-' . (($index % 4) + 1);
                                    ?>
                                        <div class="task-avatar <?= $colorClass ?>" title="<?= htmlspecialchars(trim($user)) ?>">
                                            <?= $initial ?>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($assignedUsers) > $maxAvatars): ?>
                                        <div class="task-avatar" style="background: #6b7280;">
                                            +<?= count($assignedUsers) - $maxAvatars ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <p>
                            <?php 
                            if ($activeFilter === 'todo') {
                                echo "Tidak ada tugas yang belum dimulai";
                            } elseif ($activeFilter === 'progress') {
                                echo "Tidak ada tugas yang sedang berjalan";
                            } elseif ($activeFilter === 'completed') {
                                echo "Tidak ada tugas yang selesai";
                            } else {
                                echo "Belum ada tugas yang ditugaskan kepada Anda";
                            }
                            ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Menu Overlay -->
    <div class="menu-overlay" id="menuOverlay"></div>

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
                <h3>Buat Tugas Baru</h3>
            </div>

            <div class="modal-body">
                <div class="form-title">Buat Tugas dan Bagikan ke Teman</div>

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
                            $usersQuery = $mysqli->query("SELECT * FROM users WHERE username != '$username'");
                            while ($userRow = $usersQuery->fetch_assoc()) {
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
                                $usersQuery->data_seek(0);
                                $userIndex = 1;
                                while ($userRow = $usersQuery->fetch_assoc()) {
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
                                $usersQuery->data_seek(0);
                                while ($userRow = $usersQuery->fetch_assoc()) {
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

    <!-- Bottom Navigation -->
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
        <a href="profile.php">
            <i class="fa-solid fa-user"></i>
            <span>Profil</span>
        </a>
    </div>

    <script>
        // DOM Elements
        const searchInput = document.getElementById('searchInput');
        const tasksContainer = document.getElementById('tasksContainer');
        const addTaskBtn = document.getElementById('addTaskBtn');
        const addTaskModal = document.getElementById('addTaskModal');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const addTaskForm = document.getElementById('addTaskForm');
        const fileInput = document.getElementById('fileInput');
        const fileUploadArea = document.getElementById('fileUploadArea');
        const filePreviewContainer = document.getElementById('filePreviewContainer');
        const assignMember = document.getElementById('assignMember');
        const userDropdown = document.getElementById('userDropdown');
        const subtasksContainer = document.getElementById('subtasksContainer');
        const newSubtask = document.getElementById('newSubtask');
        const subtaskAssign = document.getElementById('subtaskAssign');

        // State variables
        let selectedFiles = [];
        let selectedUsers = [];
        let subtasks = [];

        // Task menu functionality - SAMA DENGAN USERS.PHP
        function toggleTaskMenu(taskId) {
            const menu = document.getElementById('taskMenu-' + taskId);
            const allMenus = document.querySelectorAll('.task-menu-dropdown');
            
            allMenus.forEach(m => {
                if (m !== menu) m.classList.remove('active');
            });
            
            menu.classList.toggle('active');
        }

        // Function untuk melihat detail task
        function viewTaskDetail(taskId) {
            window.location.href = `task_detail.php?id=${taskId}`;
        }

        // Function untuk edit task
        function editTask(taskId) {
            window.location.href = `edit_task.php?id=${taskId}`;
        }

        // Function untuk delete task
        function deleteTask(taskId) {
            if (confirm('Apakah Anda yakin ingin menghapus tugas ini?')) {
                // Kirim request POST ke server
                fetch(`delete_task.php?id=${taskId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Tugas berhasil dihapus');
                        location.reload();
                    } else {
                        alert('Gagal menghapus tugas: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Terjadi kesalahan saat menghapus tugas');
                });
            }
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.task-menu-btn') && !e.target.closest('.task-menu-dropdown')) {
                document.querySelectorAll('.task-menu-dropdown').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        // Task card click - hanya jika bukan klik pada menu
        document.querySelectorAll('.task-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Jangan redirect jika klik pada menu atau elemen di dalam menu
                if (e.target.closest('.task-menu-container') || 
                    e.target.closest('.task-menu-dropdown') ||
                    e.target.closest('.task-menu-btn')) {
                    return;
                }
                
                const taskId = this.getAttribute('data-task-id');
                window.location.href = `task_detail.php?id=${taskId}`;
            });
        });

        // Search functionality
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            filterTasks(searchTerm);
        });

        function filterTasks(searchTerm) {
            const taskCards = document.querySelectorAll('.task-card');
            
            taskCards.forEach(card => {
                const title = card.querySelector('.task-title').textContent.toLowerCase();
                const status = card.querySelector('.task-status-label').textContent.toLowerCase();
                
                if (searchTerm === '' || title.includes(searchTerm) || status.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });

            // Check if any tasks are visible
            const visibleTasks = Array.from(taskCards).filter(card => card.style.display !== 'none');
            
            if (visibleTasks.length === 0 && taskCards.length > 0) {
                tasksContainer.innerHTML += `
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <p>Tidak ada tugas yang cocok dengan pencarian</p>
                    </div>
                `;
            }
        }

        // Modal functionality
        addTaskBtn.addEventListener('click', () => {
            addTaskModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        });

        closeModalBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);

        function closeModal() {
            addTaskModal.classList.remove('show');
            document.body.style.overflow = '';
            resetForm();
        }

        function resetForm() {
            addTaskForm.reset();
            selectedFiles = [];
            selectedUsers = [];
            subtasks = [];
            updateFilePreviews();
            updateSelectedUsers();
            updateSubtasks();
        }

        // File upload functionality
        fileUploadArea.addEventListener('click', () => {
            fileInput.click();
        });

        fileInput.addEventListener('change', handleFileSelect);

        // Drag and drop functionality
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
            const files = Array.from(e.dataTransfer.files);
            handleFiles(files);
        });

        function handleFileSelect(e) {
            const files = Array.from(e.target.files);
            handleFiles(files);
        }

        function handleFiles(files) {
            const validFiles = files.filter(file => {
                const isValidType = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'].includes(file.type);
                const isValidSize = file.size <= 5 * 1024 * 1024; // 5MB
                return isValidType && isValidSize;
            });

            if (validFiles.length + selectedFiles.length > 5) {
                alert('Maksimal 5 file yang dapat diupload');
                return;
            }

            selectedFiles = [...selectedFiles, ...validFiles];
            updateFilePreviews();
        }

        function updateFilePreviews() {
            filePreviewContainer.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                const preview = document.createElement('div');
                preview.className = 'file-preview';
                preview.innerHTML = `
                    <i class="fas fa-file"></i>
                    <span>${file.name}</span>
                    <span class="remove-file" onclick="removeFile(${index})">&times;</span>
                `;
                filePreviewContainer.appendChild(preview);
            });
        }

        window.removeFile = function(index) {
            selectedFiles.splice(index, 1);
            updateFilePreviews();
        }

        // User selection functionality
        assignMember.addEventListener('click', () => {
            userDropdown.style.display = userDropdown.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', (e) => {
            if (!assignMember.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.style.display = 'none';
            }
        });

        document.querySelectorAll('.user-dropdown-item').forEach(item => {
            item.addEventListener('click', () => {
                const userId = item.getAttribute('data-user-id');
                const userName = item.getAttribute('data-user-name');

                if (!selectedUsers.find(user => user.id === userId)) {
                    selectedUsers.push({ id: userId, name: userName });
                }

                updateSelectedUsers();
                userDropdown.style.display = 'none';
            });
        });

        // Quick assign functionality
        document.querySelectorAll('.quick-assign-user').forEach(user => {
            user.addEventListener('click', () => {
                const userId = user.getAttribute('data-user-id');
                const userName = user.getAttribute('data-user-name');

                const existingIndex = selectedUsers.findIndex(u => u.id === userId);
                if (existingIndex > -1) {
                    selectedUsers.splice(existingIndex, 1);
                    user.classList.remove('selected');
                } else {
                    selectedUsers.push({ id: userId, name: userName });
                    user.classList.add('selected');
                }

                updateSelectedUsers();
            });
        });

        function updateSelectedUsers() {
            assignMember.value = selectedUsers.map(user => user.name).join(', ');

            // Update quick assign buttons
            document.querySelectorAll('.quick-assign-user').forEach(user => {
                const userId = user.getAttribute('data-user-id');
                if (selectedUsers.find(u => u.id === userId)) {
                    user.classList.add('selected');
                } else {
                    user.classList.remove('selected');
                }
            });
        }

        // Subtasks functionality
        window.addSubtaskInput = function() {
            const subtaskText = newSubtask.value.trim();
            const assignedUser = subtaskAssign.value;

            if (!subtaskText) return;

            subtasks.push({
                text: subtaskText,
                assigned: assignedUser,
                completed: false
            });

            newSubtask.value = '';
            subtaskAssign.value = '';
            updateSubtasks();
        }

        function updateSubtasks() {
            subtasksContainer.innerHTML = '';
            subtasks.forEach((subtask, index) => {
                const subtaskElement = document.createElement('div');
                subtaskElement.className = 'subtask-item';
                const assignedHtml = subtask.assigned ? `<div class="subtask-assigned">Assigned to: ${subtask.assigned}</div>` : '';
                subtaskElement.innerHTML = `
                    <div class="subtask-checkbox" onclick="toggleSubtask(${index})">
                        ${subtask.completed ? '<i class="fas fa-check"></i>' : ''}
                    </div>
                    <div class="subtask-text">${subtask.text}</div>
                    ${assignedHtml}
                    <div class="remove-subtask" onclick="removeSubtask(${index})">&times;</div>
                `;
                subtasksContainer.appendChild(subtaskElement);
            });
        }

        window.toggleSubtask = function(index) {
            subtasks[index].completed = !subtasks[index].completed;
            updateSubtasks();
        }

        window.removeSubtask = function(index) {
            subtasks.splice(index, 1);
            updateSubtasks();
        }

        // Form submission
        addTaskForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const formData = new FormData();
            formData.append('action', 'add_task');
            formData.append('title', document.getElementById('taskTitle').value);
            formData.append('startDate', document.getElementById('startDate').value);
            formData.append('endDate', document.getElementById('endDate').value);
            formData.append('note', document.getElementById('taskNote').value);
            formData.append('assignedUsers', selectedUsers.map(u => u.name).join(','));

            // Add subtasks
            subtasks.forEach((subtask, index) => {
                formData.append('subtasks[]', subtask.text);
                formData.append('subtaskAssignments[]', subtask.assigned || '');
            });

            // Add files
            selectedFiles.forEach((file, index) => {
                formData.append(`attachments[${index}]`, file);
            });

            try {
                const response = await fetch('tasks.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    alert('Tugas berhasil dibuat!');
                    closeModal();
                    location.reload(); // Refresh to show new task
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat membuat tugas');
            }
        });

        // Close modal when clicking outside
        addTaskModal.addEventListener('click', (e) => {
            if (e.target === addTaskModal) {
                closeModal();
            }
        });
    </script>
</body>
</html>