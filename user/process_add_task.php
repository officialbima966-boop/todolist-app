<?php
session_start();
require_once "../inc/koneksi.php";

// Check login
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

header('Content-Type: application/json');

try {
    $username = $_SESSION['user'];
    
    // Get form data
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
    $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    $assigned_users = isset($_POST['assigned_users']) ? $_POST['assigned_users'] : '[]';
    $subtasks_json = isset($_POST['subtasks']) ? $_POST['subtasks'] : '[]';
    
    // Validate required fields
    if (empty($title) || empty($start_date) || empty($end_date)) {
        echo json_encode(['success' => false, 'message' => 'Title and dates are required']);
        exit;
    }
    
    // Parse assigned users
    $users_data = json_decode($assigned_users, true);
    $assigned_users_str = '';
    if (is_array($users_data) && !empty($users_data)) {
        $user_names = array_map(function($user) {
            return $user['name'] ?? '';
        }, $users_data);
        $assigned_users_str = implode(',', array_filter($user_names));
    }
    
    // Add current user to assigned users if not already there
    if (empty($assigned_users_str)) {
        $assigned_users_str = $username;
    } else if (strpos($assigned_users_str, $username) === false) {
        $assigned_users_str = $username . ',' . $assigned_users_str;
    }
    
    // Handle file uploads
    $attachments_str = '';
    $upload_dir = '../uploads/tasks/';
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    if (isset($_FILES) && count($_FILES) > 0) {
        $uploaded_files = [];
        
        foreach ($_FILES as $key => $file) {
            if (strpos($key, 'files') === 0 && $file['error'] === UPLOAD_ERR_OK) {
                $tmp_name = $file['tmp_name'];
                $filename = basename($file['name']);
                
                // Generate unique filename
                $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
                $file_name_base = pathinfo($filename, PATHINFO_FILENAME);
                $unique_name = $file_name_base . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $unique_name;
                
                if (move_uploaded_file($tmp_name, $target_path)) {
                    $uploaded_files[] = $unique_name;
                }
            }
        }
        
        if (!empty($uploaded_files)) {
            $attachments_str = implode(',', $uploaded_files);
        }
    }
    
    // Insert task into database
    $status = 'todo';
    $category = 'Belum Dijalankan';
    $progress = 0;
    
    $insert_query = "INSERT INTO tasks (
        title, 
        category, 
        status, 
        progress, 
        note, 
        created_by, 
        assigned_users, 
        start_date, 
        end_date, 
        attachments, 
        created_at, 
        updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $mysqli->prepare($insert_query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $mysqli->error]);
        exit;
    }
    
    $stmt->bind_param(
        'ssssisssss',
        $title,
        $category,
        $status,
        $progress,
        $note,
        $username,
        $assigned_users_str,
        $start_date,
        $end_date,
        $attachments_str
    );
    
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
        exit;
    }
    
    $task_id = $stmt->insert_id;
    $stmt->close();
    
    // Handle subtasks
    $subtasks_data = json_decode($subtasks_json, true);
    if (is_array($subtasks_data) && !empty($subtasks_data)) {
        $subtask_query = "INSERT INTO task_subtasks (task_id, title, assigned_to, created_at) VALUES (?, ?, ?, NOW())";
        $subtask_stmt = $mysqli->prepare($subtask_query);
        
        if ($subtask_stmt) {
            foreach ($subtasks_data as $subtask) {
                $subtask_title = $subtask['text'] ?? '';
                $subtask_assigned = $subtask['assigned'] ?? '';
                
                if (!empty($subtask_title)) {
                    $subtask_stmt->bind_param('iss', $task_id, $subtask_title, $subtask_assigned);
                    $subtask_stmt->execute();
                }
            }
            $subtask_stmt->close();
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Task created successfully',
        'task_id' => $task_id
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
}
?>
