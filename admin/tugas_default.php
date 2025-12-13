<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$admin = $_SESSION['admin'];

// Prepared statement untuk mencegah SQL injection
$stmt = $mysqli->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $admin);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'get_tasks') {
        $filter = isset($_POST['filter']) ? $_POST['filter'] : 'all';
        $search = isset($_POST['search']) ? $_POST['search'] : '';
        
        // Base SQL dengan prepared statement
        $sql = "SELECT t.*, 
                GROUP_CONCAT(DISTINCT ts.title SEPARATOR '|||') as subtasks_list,
                GROUP_CONCAT(DISTINCT ts.is_completed SEPARATOR ',') as subtasks_status,
                GROUP_CONCAT(DISTINCT ts.id SEPARATOR ',') as subtask_ids,
                COUNT(DISTINCT ts.id) as total_subtasks,
                SUM(ts.is_completed) as completed_subtasks
                FROM tasks t
                LEFT JOIN task_subtasks ts ON t.id = ts.task_id
                WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($filter === 'today') {
            $sql .= " AND DATE(t.start_date) = CURDATE()";
        } elseif ($filter === 'this_week') {
            $sql .= " AND YEARWEEK(t.start_date, 1) = YEARWEEK(CURDATE(), 1)";
        } elseif ($filter === 'pending') {
            $sql .= " AND t.progress < 100";
        } elseif ($filter === 'completed') {
            $sql .= " AND t.progress = 100";
        }
        
        if (!empty($search)) {
            $sql .= " AND (t.title LIKE ? OR t.category LIKE ? OR t.note LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_fill(0, 3, $searchTerm);
            $types = "sss";
        }
        
        $sql .= " GROUP BY t.id ORDER BY t.created_at DESC";
        
        // Gunakan prepared statement
        $stmt = $mysqli->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tasks = [];
        
        while ($row = $result->fetch_assoc()) {
            // Parse subtasks
            $subtasks = [];
            if (!empty($row['subtasks_list'])) {
                $subtask_titles = explode('|||', $row['subtasks_list']);
                $subtask_statuses = explode(',', $row['subtasks_status']);
                $subtask_ids = explode(',', $row['subtask_ids']);
                
                for ($i = 0; $i < count($subtask_titles); $i++) {
                    $subtasks[] = [
                        'id' => isset($subtask_ids[$i]) ? $subtask_ids[$i] : null,
                        'title' => $subtask_titles[$i],
                        'completed' => isset($subtask_statuses[$i]) ? (bool)$subtask_statuses[$i] : false
                    ];
                }
            }
            
            // Count assigned users
            $assigned_count = 0;
            if (!empty($row['assigned_users'])) {
                $assigned_count = count(explode(',', $row['assigned_users']));
            }
            
            // Format dates
            $start_date = !empty($row['start_date']) ? date('d M Y', strtotime($row['start_date'])) : '-';
            $end_date = !empty($row['end_date']) ? date('d M Y', strtotime($row['end_date'])) : '-';
            
            // Calculate time remaining
            $time_remaining = '';
            if (!empty($row['end_date'])) {
                $end = strtotime($row['end_date']);
                $today = time();
                $diff = $end - $today;
                $days = floor($diff / (60 * 60 * 24));
                
                if ($days > 0) {
                    $time_remaining = "$days hari lagi";
                } elseif ($days == 0) {
                    $time_remaining = "Hari ini";
                } else {
                    $time_remaining = "Lewat " . abs($days) . " hari";
                }
            }
            
            $tasks[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'category' => $row['category'],
                'progress' => (int)$row['progress'],
                'status' => $row['status'],
                'start_date' => $start_date,
                'end_date' => $end_date,
                'time_remaining' => $time_remaining,
                'assigned_users' => $row['assigned_users'],
                'assigned_count' => $assigned_count,
                'tasks_completed' => (int)$row['tasks_completed'],
                'tasks_total' => (int)$row['tasks_total'],
                'comments' => (int)$row['comments'],
                'note' => $row['note'],
                'attachments' => $row['attachments'],
                'subtasks' => $subtasks,
                'completed_subtasks' => (int)$row['completed_subtasks'],
                'total_subtasks' => (int)$row['total_subtasks'],
                'created_by' => $row['created_by'],
                'created_at' => date('d M Y H:i', strtotime($row['created_at']))
            ];
        }
        
        echo json_encode(['success' => true, 'tasks' => $tasks]);
        exit;
    }
    
    if ($_POST['action'] === 'get_task_stats') {
        // Get task statistics
        $stats = [];
        
        // Total tasks
        $result = $mysqli->query("SELECT COUNT(*) as total FROM tasks");
        $row = $result->fetch_assoc();
        $stats['total'] = $row['total'];
        
        // Completed tasks
        $result = $mysqli->query("SELECT COUNT(*) as completed FROM tasks WHERE progress = 100");
        $row = $result->fetch_assoc();
        $stats['completed'] = $row['completed'];
        
        // In progress tasks
        $result = $mysqli->query("SELECT COUNT(*) as in_progress FROM tasks WHERE progress > 0 AND progress < 100");
        $row = $result->fetch_assoc();
        $stats['in_progress'] = $row['in_progress'];
        
        // Pending tasks
        $result = $mysqli->query("SELECT COUNT(*) as pending FROM tasks WHERE progress = 0");
        $row = $result->fetch_assoc();
        $stats['pending'] = $row['pending'];
        
        // Today's tasks
        $result = $mysqli->query("SELECT COUNT(*) as today FROM tasks WHERE DATE(start_date) = CURDATE()");
        $row = $result->fetch_assoc();
        $stats['today'] = $row['today'];
        
        // This week's tasks
        $result = $mysqli->query("SELECT COUNT(*) as this_week FROM tasks WHERE YEARWEEK(start_date, 1) = YEARWEEK(CURDATE(), 1)");
        $row = $result->fetch_assoc();
        $stats['this_week'] = $row['this_week'];
        
        // Late tasks
        $result = $mysqli->query("SELECT COUNT(*) as late FROM tasks WHERE progress < 100 AND end_date < CURDATE()");
        $row = $result->fetch_assoc();
        $stats['late'] = $row['late'];
        
        // Calculate completion rate
        $stats['completion_rate'] = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100) : 0;
        
        echo json_encode(['success' => true, 'stats' => $stats]);
        exit;
    }
    
    if ($_POST['action'] === 'update_task_status') {
        $taskId = (int)$_POST['taskId'];
        $status = $_POST['status'];
        
        $progress = 0;
        $category = 'Belum Dijalankan';
        
        if ($status === 'progress') {
            $progress = 50;
            $category = 'Sedang Berjalan';
        } elseif ($status === 'completed') {
            $progress = 100;
            $category = 'Selesai';
        }
        
        $stmt = $mysqli->prepare("UPDATE tasks SET status = ?, progress = ?, category = ? WHERE id = ?");
        $stmt->bind_param("sisi", $status, $progress, $category, $taskId);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'get_task_detail') {
        $taskId = (int)$_POST['taskId'];
        
        $stmt = $mysqli->prepare("SELECT t.*, 
                GROUP_CONCAT(DISTINCT ts.id SEPARATOR ',') as subtask_ids,
                GROUP_CONCAT(DISTINCT ts.title SEPARATOR '|||') as subtask_titles,
                GROUP_CONCAT(DISTINCT ts.assigned_to SEPARATOR '|||') as subtask_assignments,
                GROUP_CONCAT(DISTINCT ts.is_completed SEPARATOR ',') as subtask_completions,
                GROUP_CONCAT(DISTINCT ts.completed_by SEPARATOR '|||') as subtask_completed_by,
                GROUP_CONCAT(DISTINCT ts.completed_at SEPARATOR '|||') as subtask_completed_at,
                GROUP_CONCAT(DISTINCT tc.comment SEPARATOR '|||') as comments,
                GROUP_CONCAT(DISTINCT tc.username SEPARATOR '|||') as comment_authors,
                GROUP_CONCAT(DISTINCT tc.created_at SEPARATOR '|||') as comment_times
                FROM tasks t
                LEFT JOIN task_subtasks ts ON t.id = ts.task_id
                LEFT JOIN task_comments tc ON t.id = tc.task_id
                WHERE t.id = ?
                GROUP BY t.id");
        $stmt->bind_param("i", $taskId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            // Parse subtasks
            $subtasks = [];
            if (!empty($row['subtask_titles'])) {
                $titles = explode('|||', $row['subtask_titles']);
                $assignments = explode('|||', $row['subtask_assignments']);
                $completions = explode(',', $row['subtask_completions']);
                $completed_by = explode('|||', $row['subtask_completed_by']);
                $completed_at = explode('|||', $row['subtask_completed_at']);
                
                for ($i = 0; $i < count($titles); $i++) {
                    $subtasks[] = [
                        'id' => isset(explode(',', $row['subtask_ids'])[$i]) ? explode(',', $row['subtask_ids'])[$i] : null,
                        'title' => $titles[$i],
                        'assigned_to' => isset($assignments[$i]) ? $assignments[$i] : '',
                        'is_completed' => isset($completions[$i]) ? (bool)$completions[$i] : false,
                        'completed_by' => isset($completed_by[$i]) ? $completed_by[$i] : '',
                        'completed_at' => isset($completed_at[$i]) ? $completed_at[$i] : ''
                    ];
                }
            }
            
            // Parse comments
            $comments = [];
            if (!empty($row['comments'])) {
                $comment_texts = explode('|||', $row['comments']);
                $comment_authors = explode('|||', $row['comment_authors']);
                $comment_times = explode('|||', $row['comment_times']);
                
                for ($i = 0; $i < count($comment_texts); $i++) {
                    $comments[] = [
                        'comment' => $comment_texts[$i],
                        'username' => isset($comment_authors[$i]) ? $comment_authors[$i] : '',
                        'created_at' => isset($comment_times[$i]) ? $comment_times[$i] : ''
                    ];
                }
            }
            
            // Parse attachments
            $attachments = [];
            if (!empty($row['attachments'])) {
                $attachment_files = explode(',', $row['attachments']);
                foreach ($attachment_files as $file) {
                    if (!empty($file)) {
                        $attachments[] = $file;
                    }
                }
            }
            
            // Parse assigned users
            $assigned_users = [];
            if (!empty($row['assigned_users'])) {
                $assigned_users = explode(',', $row['assigned_users']);
            }
            
            $task_detail = [
                'id' => $row['id'],
                'title' => $row['title'],
                'category' => $row['category'],
                'progress' => (int)$row['progress'],
                'status' => $row['status'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'note' => $row['note'],
                'assigned_users' => $assigned_users,
                'tasks_completed' => (int)$row['tasks_completed'],
                'tasks_total' => (int)$row['tasks_total'],
                'comments' => $comments,
                'attachments' => $attachments,
                'subtasks' => $subtasks,
                'created_by' => $row['created_by'],
                'created_at' => $row['created_at']
            ];
            
            echo json_encode(['success' => true, 'task' => $task_detail]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Task not found']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'add_quick_task') {
        $title = $_POST['title'];
        $assigned_to = $_POST['assigned_to'];
        $due_date = $_POST['due_date'];
        
        // Insert quick task dengan prepared statement
        $stmt = $mysqli->prepare("INSERT INTO tasks (title, assigned_users, end_date, created_by, category, status) 
                VALUES (?, ?, ?, ?, 'Belum Dijalankan', 'todo')");
        $stmt->bind_param("ssss", $title, $assigned_to, $due_date, $admin);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Task berhasil ditambahkan!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menambahkan task']);
        }
        exit;
    }
    
    // FITUR BARU: Hapus tugas
    if ($_POST['action'] === 'delete_task') {
        $taskId = (int)$_POST['taskId'];
        
        $stmt = $mysqli->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->bind_param("i", $taskId);
        
        if ($stmt->execute()) {
            // Hapus juga subtasks terkait
            $stmt2 = $mysqli->prepare("DELETE FROM task_subtasks WHERE task_id = ?");
            $stmt2->bind_param("i", $taskId);
            $stmt2->execute();
            
            echo json_encode(['success' => true, 'message' => 'Tugas berhasil dihapus!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus tugas']);
        }
        exit;
    }
    
    // FITUR BARU: Update subtask status
    if ($_POST['action'] === 'update_subtask_status') {
        $subtaskId = (int)$_POST['subtaskId'];
        $isCompleted = (int)$_POST['is_completed'];
        
        $stmt = $mysqli->prepare("UPDATE task_subtasks SET is_completed = ?, completed_by = ?, completed_at = NOW() WHERE id = ?");
        $stmt->bind_param("isi", $isCompleted, $admin, $subtaskId);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Subtask berhasil diperbarui!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui subtask']);
        }
        exit;
    }
    
    // FITUR BARU: Tambah komentar
    if ($_POST['action'] === 'add_comment') {
        $taskId = (int)$_POST['taskId'];
        $comment = $_POST['comment'];
        
        $stmt = $mysqli->prepare("INSERT INTO task_comments (task_id, username, comment) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $taskId, $admin, $comment);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Komentar berhasil ditambahkan!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menambahkan komentar']);
        }
        exit;
    }
    
    // FITUR BARU: Dapatkan data chart real
    if ($_POST['action'] === 'get_chart_data') {
        $chartData = [];
        
        // Data untuk 30 hari terakhir
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            
            // Hitung tugas selesai per hari
            $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM tasks WHERE DATE(created_at) = ? AND progress = 100");
            $stmt->bind_param("s", $date);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $completed = $row['count'];
            
            // Hitung tugas tertunda per hari
            $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM tasks WHERE DATE(created_at) = ? AND progress < 100");
            $stmt->bind_param("s", $date);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $pending = $row['count'];
            
            $chartData['labels'][] = date('d M', strtotime($date));
            $chartData['completed'][] = $completed;
            $chartData['pending'][] = $pending;
        }
        
        echo json_encode(['success' => true, 'data' => $chartData]);
        exit;
    }
}

// Get all users for dropdown
$usersQuery = "SELECT id, username, nama_lengkap FROM users ORDER BY nama_lengkap ASC";
$usersResult = $mysqli->query($usersQuery);

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Tugas Default | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
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
      background: #f8faff;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      padding-bottom: 90px;
      overflow-x: hidden;
    }

    header {
      background: linear-gradient(135deg, #0022a8, #0044ff);
      color: #fff;
      padding: 60px 15px 30px;
      border-bottom-left-radius: 25px;
      border-bottom-right-radius: 25px;
      position: relative;
      overflow: hidden;
      text-align: center;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      min-height: 150px;
      width: 100%;
    }

    .page-title {
      font-size: 1.8rem;
      font-weight: 700;
      text-align: center;
      letter-spacing: 0.5px;
      padding: 0 10px;
      word-break: break-word;
      margin-bottom: 5px;
    }

    .page-subtitle {
      font-size: 0.9rem;
      opacity: 0.9;
      font-weight: 400;
      text-align: center;
    }

    /* Search Container */
    .search-container {
      padding: 0 15px;
      margin-top: 15px;
      margin-bottom: 15px;
    }

    .search-box {
      background: #fff;
      border-radius: 12px;
      width: 100%;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      display: flex;
      align-items: center;
      padding: 10px 14px;
      border: 1px solid #e8f0ff;
    }

    .search-box input {
      border: none;
      outline: none;
      flex: 1;
      padding: 8px 10px;
      font-size: 0.9rem;
      color: #333;
      background: transparent;
      width: 100%;
    }

    .search-box i {
      color: #666;
      margin-right: 6px;
      font-size: 0.95rem;
      flex-shrink: 0;
    }

    /* Filter Tabs */
    .filter-tabs {
      display: flex;
      gap: 8px;
      overflow-x: auto;
      padding: 10px 15px 5px;
      margin-bottom: 10px;
      -webkit-overflow-scrolling: touch;
    }

    .filter-tabs::-webkit-scrollbar {
      display: none;
    }

    .filter-tab {
      padding: 8px 15px;
      border-radius: 20px;
      border: none;
      background: white;
      color: #666;
      font-weight: 500;
      font-size: 0.8rem;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.3s;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      border: 1px solid transparent;
      display: flex;
      align-items: center;
      gap: 6px;
      flex-shrink: 0;
      min-height: 36px;
    }

    .filter-tab:hover {
      border-color: #2455ff;
      color: #2455ff;
    }

    .filter-tab.active {
      background: #2455ff;
      color: white;
      box-shadow: 0 3px 12px rgba(36, 85, 255, 0.3);
    }

    /* Content Area */
    .content {
      padding: 0 15px 80px;
      flex: 1;
    }

    /* PERUBAHAN UTAMA: Memperbesar ukuran task card */
    .tasks-grid {
      display: flex;
      flex-direction: column;
      gap: 15px; /* Meningkatkan gap */
    }

    .task-card {
      background: white;
      border-radius: 16px; /* Membulatkan sudut lebih besar */
      padding: 20px; /* Meningkatkan padding */
      box-shadow: 0 4px 20px rgba(0,0,0,0.1); /* Shadow lebih besar */
      transition: all 0.3s;
      border: 1px solid #f0f4ff;
      cursor: pointer;
      position: relative;
      margin-bottom: 5px;
    }

    .task-card:hover {
      transform: translateY(-3px); /* Efek hover lebih kuat */
      box-shadow: 0 6px 25px rgba(0,0,0,0.15);
    }

    .task-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 15px; /* Meningkatkan margin */
      gap: 15px;
    }

    .task-title {
      font-size: 1.1rem; /* Memperbesar font */
      font-weight: 700; /* Membuat lebih tebal */
      color: #333;
      flex: 1;
      line-height: 1.5;
      word-break: break-word;
    }

    .task-status {
      padding: 6px 14px; /* Meningkatkan padding */
      border-radius: 20px; /* Membulatkan lebih besar */
      font-size: 0.8rem;
      font-weight: 700; /* Membuat lebih tebal */
      white-space: nowrap;
      flex-shrink: 0;
    }

    .status-pending { background: #fee2e2; color: #dc2626; }
    .status-progress { background: #fef3c7; color: #d97706; }
    .status-completed { background: #d1fae5; color: #059669; }

    .task-meta {
      display: flex;
      flex-direction: column;
      gap: 10px; /* Meningkatkan gap */
      margin-bottom: 20px; /* Meningkatkan margin */
      font-size: 0.9rem; /* Memperbesar font */
      color: #666;
    }

    .task-date {
      display: flex;
      align-items: center;
      gap: 8px; /* Meningkatkan gap */
    }

    .task-assignees {
      display: flex;
      align-items: center;
      gap: 8px; /* Meningkatkan gap */
    }

    .assignee-avatar {
      width: 35px; /* Memperbesar ukuran */
      height: 35px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 0.9rem; /* Memperbesar font */
      font-weight: 600;
      border: 2px solid white;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
      flex-shrink: 0;
    }

    .more-assignees {
      width: 35px; /* Memperbesar ukuran */
      height: 35px;
      border-radius: 50%;
      background: #f3f4f6;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #666;
      font-size: 0.9rem; /* Memperbesar font */
      font-weight: 600;
      flex-shrink: 0;
    }

    .progress-section {
      margin-bottom: 20px; /* Meningkatkan margin */
    }

    .progress-label {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px; /* Meningkatkan margin */
    }

    .progress-label span {
      font-size: 0.95rem; /* Memperbesar font */
      color: #666;
      font-weight: 600; /* Membuat lebih tebal */
    }

    .progress-percentage {
      font-size: 0.95rem; /* Memperbesar font */
      font-weight: 700;
      color: #2455ff;
    }

    .progress-bar {
      height: 8px; /* Memperbesar tinggi */
      background: #f0f4ff;
      border-radius: 10px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #2455ff, #4f7eff);
      border-radius: 10px;
      transition: width 0.5s ease;
    }

    .task-stats {
      display: flex;
      gap: 20px; /* Meningkatkan gap */
      margin-bottom: 20px; /* Meningkatkan margin */
    }

    .task-stat {
      display: flex;
      flex-direction: column;
      align-items: center;
      flex: 1;
    }

    .stat-number {
      font-size: 1.3rem; /* Memperbesar font */
      font-weight: 700;
      color: #333;
    }

    .stat-label {
      font-size: 0.8rem;
      color: #666;
    }

    .subtask-preview {
      margin-top: 15px; /* Meningkatkan margin */
      padding-top: 15px;
      border-top: 2px solid #f0f4ff; /* Membuat border lebih tebal */
    }

    .subtask-item {
      display: flex;
      align-items: center;
      gap: 10px; /* Meningkatkan gap */
      margin-bottom: 8px;
      font-size: 0.9rem; /* Memperbesar font */
      cursor: pointer;
    }

    .subtask-check {
      width: 22px; /* Memperbesar ukuran */
      height: 22px;
      border-radius: 6px; /* Membulatkan lebih besar */
      border: 2px solid #ddd;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      transition: all 0.2s;
      background: white;
      flex-shrink: 0;
    }

    .subtask-check:hover {
      border-color: #2455ff;
    }

    .subtask-check.completed {
      background: #10b981;
      border-color: #10b981;
      color: white;
    }

    .subtask-title {
      flex: 1;
      transition: all 0.2s;
      user-select: none;
      word-break: break-word;
    }

    .subtask-title.completed {
      text-decoration: line-through;
      color: #9ca3af;
    }

    .task-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 20px; /* Meningkatkan margin */
      padding-top: 20px;
      border-top: 2px solid #f0f4ff; /* Membuat border lebih tebal */
      flex-wrap: wrap;
      gap: 15px; /* Meningkatkan gap */
    }

    .time-remaining {
      font-size: 0.85rem; /* Memperbesar font */
      font-weight: 700;
      padding: 8px 15px; /* Meningkatkan padding */
      border-radius: 15px; /* Membulatkan lebih besar */
      background: #f0f9ff;
      color: #0369a1;
      white-space: nowrap;
    }

    .time-remaining.urgent {
      background: #fef2f2;
      color: #dc2626;
    }

    .task-actions {
      display: flex;
      gap: 10px; /* Meningkatkan gap */
      flex-shrink: 0;
    }

    .action-btn {
      width: 40px; /* Memperbesar ukuran */
      height: 40px;
      border-radius: 10px; /* Membulatkan lebih besar */
      border: none;
      background: #f8faff;
      color: #666;
      cursor: pointer;
      transition: all 0.3s;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem; /* Memperbesar font */
      flex-shrink: 0;
    }

    .action-btn:hover {
      background: #2455ff;
      color: white;
      transform: scale(1.1);
    }

    .action-btn.delete:hover {
      background: #ef4444;
    }

    /* Floating Action Buttons */
    .fab-container {
      position: fixed;
      right: 15px;
      bottom: 75px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      z-index: 99;
    }

    .fab {
      width: 55px;
      height: 55px;
      border-radius: 50%;
      border: none;
      background: #2455ff;
      color: white;
      font-size: 1.2rem;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(36, 85, 255, 0.4);
      transition: all 0.3s;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .fab:hover {
      transform: scale(1.08);
      box-shadow: 0 6px 20px rgba(36, 85, 255, 0.6);
    }

    .fab-secondary {
      background: white;
      color: #2455ff;
      box-shadow: 0 3px 12px rgba(0,0,0,0.1);
    }

    /* Modal styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 15px;
    }

    .modal-content {
      background-color: white;
      padding: 25px 20px;
      border-radius: 15px;
      width: 100%;
      max-width: 450px;
      max-height: 85vh;
      overflow-y: auto;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .modal-header h3 {
      font-size: 1.3rem;
      font-weight: 700;
      color: #333;
    }

    .close-modal {
      background: none;
      border: none;
      font-size: 1.5rem;
      color: #666;
      cursor: pointer;
      transition: all 0.3s;
      padding: 5px;
    }

    .close-modal:hover {
      color: #ef4444;
      transform: rotate(90deg);
    }

    .form-group {
      margin-bottom: 15px;
    }

    .form-group label {
      display: block;
      margin-bottom: 6px;
      color: #333;
      font-weight: 600;
      font-size: 0.9rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 12px 14px;
      border: 2px solid #e8f0ff;
      border-radius: 10px;
      font-size: 0.95rem;
      background: #f8faff;
      transition: all 0.3s;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: #2455ff;
      box-shadow: 0 0 0 3px rgba(36, 85, 255, 0.1);
      background: white;
    }

    .form-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .form-row .form-group {
      flex: 1;
      min-width: 140px;
    }

    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 25px;
    }

    .btn {
      padding: 12px 20px;
      border: none;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      font-size: 0.9rem;
      min-height: 44px;
    }

    .btn-secondary {
      background: #f3f4f6;
      color: #666;
    }

    .btn-secondary:hover {
      background: #e5e7eb;
      transform: translateY(-2px);
    }

    .btn-primary {
      background: #2455ff;
      color: white;
    }

    .btn-primary:hover {
      background: #1a45e0;
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(36, 85, 255, 0.3);
    }

    .btn-danger {
      background: #ef4444;
      color: white;
    }

    .btn-danger:hover {
      background: #dc2626;
      box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
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
      background: #2455ff; 
      color: #fff;
    }

    .bottom-nav a:not(.active):hover {
      color: #2455ff;
      background: #f3f4f6;
    }

    /* Loading States */
    .loading {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 3px solid #f3f3f3;
      border-top: 3px solid #2455ff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .loading-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, 0.8);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 2000;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 50px 15px;
      color: #666;
    }

    .empty-state i {
      font-size: 3rem;
      color: #ddd;
      margin-bottom: 15px;
    }

    .empty-state h3 {
      font-size: 1.2rem;
      margin-bottom: 8px;
      color: #333;
    }

    .empty-state p {
      margin-bottom: 15px;
      font-size: 0.9rem;
    }

    /* Notification */
    .notification {
      position: fixed;
      top: 15px;
      right: 15px;
      left: 15px;
      background: #10b981;
      color: white;
      padding: 12px 18px;
      border-radius: 8px;
      box-shadow: 0 3px 10px rgba(0,0,0,0.2);
      z-index: 1000;
      display: flex;
      align-items: center;
      gap: 8px;
      transform: translateY(-100%);
      opacity: 0;
      transition: all 0.3s ease;
      max-width: 400px;
      margin: 0 auto;
    }

    .notification.show {
      transform: translateY(0);
      opacity: 1;
    }

    .notification.error {
      background: #ef4444;
    }

    /* Detail Modal Responsive */
    .detail-modal {
      max-width: 500px;
    }

    /* Responsive Design */
    @media (min-width: 768px) {
      .content {
        padding: 0 20px 90px;
        max-width: 768px;
        margin: 0 auto;
      }
      
      /* PERUBAHAN UTAMA: Grid 2 kolom di desktop untuk task card lebih besar */
      .tasks-grid {
        display: grid;
        grid-template-columns: repeat(1, 1fr); /* 1 kolom untuk tablet */
        gap: 20px; /* Meningkatkan gap lebih besar */
      }
      
      .task-card {
        padding: 25px; /* Meningkatkan padding lebih besar */
        margin-bottom: 10px;
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
      
      .fab-container {
        right: 20px;
        bottom: 85px;
      }
      
      .fab {
        width: 60px;
        height: 60px;
        font-size: 1.3rem;
      }
      
      .notification {
        left: auto;
        right: 20px;
        max-width: 350px;
      }
    }

    @media (min-width: 1024px) {
      body {
        padding-bottom: 20px;
      }
      
      .content {
        max-width: 1024px;
        padding: 0 30px 100px;
      }
      
      /* PERUBAHAN UTAMA: Grid 2 kolom di desktop besar */
      .tasks-grid {
        grid-template-columns: repeat(2, 1fr); /* 2 kolom untuk desktop */
        gap: 25px; /* Meningkatkan gap lebih besar */
      }
      
      .fab-container {
        right: 30px;
        bottom: 30px;
      }
      
      .bottom-nav {
        bottom: 20px;
        padding: 10px 20px;
        max-width: 1024px;
      }
      
      .bottom-nav a {
        padding: 12px 24px;
        font-size: 0.9rem;
      }
    }

    @media (max-width: 480px) {
      html {
        font-size: 14px;
      }
      
      header {
        padding: 50px 12px 25px;
        min-height: 140px;
      }
      
      .page-title {
        font-size: 1.6rem;
      }
      
      .search-container {
        padding: 0 12px;
        margin-top: 12px;
        margin-bottom: 12px;
      }
      
      .filter-tabs {
        padding: 8px 12px 5px;
      }
      
      .filter-tab {
        padding: 7px 12px;
        font-size: 0.75rem;
        min-height: 34px;
      }
      
      /* Task card mobile adjustments */
      .task-card {
        padding: 18px;
        border-radius: 14px;
      }
      
      .task-title {
        font-size: 1rem;
      }
      
      .task-stats {
        gap: 15px;
      }
      
      .stat-number {
        font-size: 1.2rem;
      }
      
      .fab-container {
        right: 12px;
        bottom: 70px;
        gap: 10px;
      }
      
      .fab {
        width: 50px;
        height: 50px;
        font-size: 1.1rem;
      }
      
      .bottom-nav {
        bottom: 8px;
        padding: 5px 6px;
      }
      
      .bottom-nav a {
        padding: 7px 12px;
        font-size: 0.7rem;
      }
      
      .modal {
        padding: 10px;
      }
      
      .modal-content {
        padding: 20px 15px;
      }
      
      .form-row {
        flex-direction: column;
        gap: 10px;
      }
      
      .form-row .form-group {
        min-width: 100%;
      }
      
      .btn {
        padding: 10px 18px;
        min-height: 40px;
      }
      
      .empty-state i {
        font-size: 2.5rem;
      }
      
      .notification {
        top: 10px;
        right: 10px;
        left: 10px;
        padding: 10px 15px;
      }
    }

    @media (max-width: 360px) {
      html {
        font-size: 13px;
      }
      
      .page-title {
        font-size: 1.4rem;
      }
      
      .filter-tab span {
        display: none;
      }
      
      .filter-tab i {
        margin-right: 0;
        font-size: 0.9rem;
      }
      
      .task-footer {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
      }
      
      .time-remaining {
        width: 100%;
        text-align: center;
      }
      
      .task-actions {
        width: 100%;
        justify-content: space-between;
      }
      
      .action-btn {
        flex: 1;
      }
      
      .bottom-nav a {
        padding: 6px 10px;
        font-size: 0.65rem;
      }
    }

    /* Touch-friendly improvements */
    @media (hover: none) and (pointer: coarse) {
      .task-card:hover {
        transform: none;
      }
      
      .action-btn:hover {
        transform: none;
      }
      
      .fab:hover {
        transform: none;
      }
      
      .btn:hover {
        transform: none;
      }
      
      .btn-primary:hover {
        box-shadow: none;
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

    /* Fix for iOS Safari */
    @supports (-webkit-touch-callout: none) {
      body {
        min-height: -webkit-fill-available;
      }
    }
  </style>
</head>
<body>

  <!-- Header -->
  <header>
    <div class="page-title">Tugas Default</div>
    <div class="page-subtitle">Kelola semua tugas dari sistem</div>
  </header>

  <!-- Search and Filter -->
  <div class="search-container">
    <div class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" id="searchInput" placeholder="Cari tugas...">
    </div>
  </div>

  <div class="filter-tabs">
    <button class="filter-tab active" data-filter="all">
      <i class="fas fa-list"></i> <span>Semua</span>
    </button>
    <button class="filter-tab" data-filter="today">
      <i class="fas fa-calendar-day"></i> <span>Hari Ini</span>
    </button>
    <button class="filter-tab" data-filter="this_week">
      <i class="fas fa-calendar-week"></i> <span>Minggu Ini</span>
    </button>
    <button class="filter-tab" data-filter="pending">
      <i class="fas fa-clock"></i> <span>Menunggu</span>
    </button>
    <button class="filter-tab" data-filter="completed">
      <i class="fas fa-check-circle"></i> <span>Selesai</span>
    </button>
  </div>

  <!-- Tasks Grid -->
  <div class="content">
    <div id="tasksContainer">
      <!-- Tasks akan dimuat di sini -->
    </div>
  </div>

  <!-- Floating Action Buttons -->
  <div class="fab-container">
    <button class="fab" id="quickTaskBtn" title="Tugas Cepat">
      <i class="fas fa-plus"></i>
    </button>
    <button class="fab fab-secondary" id="refreshBtn" title="Refresh">
      <i class="fas fa-sync-alt"></i>
    </button>
  </div>

  <!-- Quick Task Modal -->
  <div class="modal" id="quickTaskModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Tambah Tugas Cepat</h3>
        <button class="close-modal" id="closeQuickTaskModal">&times;</button>
      </div>
      
      <form id="quickTaskForm">
        <div class="form-group">
          <label for="quickTaskTitle">Judul Tugas</label>
          <input type="text" id="quickTaskTitle" placeholder="Apa yang perlu dikerjakan?" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="quickTaskAssignee">Ditugaskan Ke</label>
            <select id="quickTaskAssignee" required>
              <option value="">Pilih Anggota</option>
              <?php
              $usersResult->data_seek(0);
              while ($user = $usersResult->fetch_assoc()) {
                echo '<option value="'.$user['username'].'">'.$user['nama_lengkap'].' ('.$user['username'].')</option>';
              }
              ?>
            </select>
          </div>

          <div class="form-group">
            <label for="quickTaskDueDate">Tanggal Jatuh Tempo</label>
            <input type="text" id="quickTaskDueDate" class="datepicker" placeholder="Pilih tanggal" required>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="btn btn-secondary" id="cancelQuickTask">Batal</button>
          <button type="submit" class="btn btn-primary" id="saveQuickTask">Simpan Tugas</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Task Detail Modal -->
  <div class="modal" id="taskDetailModal">
    <div class="modal-content detail-modal">
      <div class="modal-header">
        <h3>Detail Tugas</h3>
        <button class="close-modal" id="closeDetailModal">&times;</button>
      </div>
      
      <div id="taskDetailContent">
        <!-- Detail tugas akan dimuat di sini -->
      </div>
    </div>
  </div>

  <!-- Confirmation Modal -->
  <div class="modal" id="confirmationModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Konfirmasi</h3>
        <button class="close-modal" id="closeConfirmationModal">&times;</button>
      </div>
      
      <div style="text-align: center; margin-bottom: 20px; font-size: 0.95rem; color: #333;">
        Apakah Anda yakin ingin menghapus tugas ini?
      </div>
      
      <div class="form-actions">
        <button type="button" class="btn btn-secondary" id="cancelConfirmation">Batal</button>
        <button type="button" class="btn btn-danger" id="confirmAction">Ya, Hapus</button>
      </div>
    </div>
  </div>

  <!-- Loading Overlay -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="loading"></div>
  </div>

  <!-- Notification -->
  <div class="notification" id="notification">
    <i class="fas fa-check-circle"></i>
    <span id="notificationText"></span>
  </div>

  <!-- Bottom Navigation -->
  <div class="bottom-nav">
    <a href="dashboard.php">
      <i class="fa-solid fa-house"></i>
      <span>Home</span>
    </a>
    <a href="tasks.php">
      <i class="fa-solid fa-list-check"></i>
      <span>Tasks</span>
    </a>
    <a href="users.php">
      <i class="fa-solid fa-user-group"></i>
      <span>Users</span>
    </a>
    <a href="tugas_default.php" class="active">
      <i class="fa-solid fa-clipboard-list"></i>
      <span>Tugas Default</span>
    </a>
    <a href="profile.php">
      <i class="fa-solid fa-user"></i>
      <span>Profil</span>
    </a>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script>
    // Initialize Flatpickr
    flatpickr(".datepicker", {
      dateFormat: "Y-m-d",
      minDate: "today"
    });

    // Global Variables
    let currentFilter = 'all';
    let currentSearch = '';
    let currentTaskId = null;
    let taskToDelete = null;

    // DOM Elements
    const tasksContainer = document.getElementById('tasksContainer');
    const searchInput = document.getElementById('searchInput');
    const filterTabs = document.querySelectorAll('.filter-tab');
    const quickTaskBtn = document.getElementById('quickTaskBtn');
    const refreshBtn = document.getElementById('refreshBtn');
    const quickTaskModal = document.getElementById('quickTaskModal');
    const closeQuickTaskModal = document.getElementById('closeQuickTaskModal');
    const cancelQuickTask = document.getElementById('cancelQuickTask');
    const quickTaskForm = document.getElementById('quickTaskForm');
    const taskDetailModal = document.getElementById('taskDetailModal');
    const closeDetailModal = document.getElementById('closeDetailModal');
    const confirmationModal = document.getElementById('confirmationModal');
    const closeConfirmationModal = document.getElementById('closeConfirmationModal');
    const cancelConfirmation = document.getElementById('cancelConfirmation');
    const confirmAction = document.getElementById('confirmAction');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const notification = document.getElementById('notification');
    const notificationText = document.getElementById('notificationText');

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
      // Tampilkan loading overlay dulu
      showLoading();
      
      // Set tasks container ke loading state
      tasksContainer.innerHTML = `
        <div style="text-align: center; padding: 40px; color: #666;">
          <div class="loading" style="margin: 0 auto 20px;"></div>
          <p>Memuat tugas...</p>
        </div>
      `;
      
      // Muat data
      loadTasks();
      setupEventListeners();
    });

    // Event Listeners
    function setupEventListeners() {
      searchInput.addEventListener('input', debounce(function() {
        currentSearch = this.value;
        loadTasks();
      }, 300));

      filterTabs.forEach(tab => {
        tab.addEventListener('click', function() {
          filterTabs.forEach(t => t.classList.remove('active'));
          this.classList.add('active');
          currentFilter = this.dataset.filter;
          loadTasks();
        });
      });

      quickTaskBtn.addEventListener('click', () => quickTaskModal.style.display = 'flex');
      refreshBtn.addEventListener('click', () => {
        loadTasks();
      });

      closeQuickTaskModal.addEventListener('click', () => quickTaskModal.style.display = 'none');
      cancelQuickTask.addEventListener('click', () => quickTaskModal.style.display = 'none');
      closeDetailModal.addEventListener('click', () => taskDetailModal.style.display = 'none');
      closeConfirmationModal.addEventListener('click', () => confirmationModal.style.display = 'none');
      cancelConfirmation.addEventListener('click', () => confirmationModal.style.display = 'none');

      quickTaskForm.addEventListener('submit', handleQuickTaskSubmit);
      confirmAction.addEventListener('click', handleDeleteTask);

      // Close modals on outside click
      window.addEventListener('click', (e) => {
        if (e.target === quickTaskModal) quickTaskModal.style.display = 'none';
        if (e.target === taskDetailModal) taskDetailModal.style.display = 'none';
        if (e.target === confirmationModal) confirmationModal.style.display = 'none';
      });
    }

    // Load Tasks
    async function loadTasks() {
      showLoading();
      
      try {
        const formData = new FormData();
        formData.append('action', 'get_tasks');
        formData.append('filter', currentFilter);
        formData.append('search', currentSearch);

        const response = await fetch('tugas_default.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          renderTasks(result.tasks);
        } else {
          showNotification('Gagal memuat tugas', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('Terjadi kesalahan', 'error');
      } finally {
        hideLoading();
      }
    }

    // Render Tasks dengan fitur checkbox langsung
    function renderTasks(tasks) {
      if (tasks.length === 0) {
        tasksContainer.innerHTML = `
          <div class="empty-state">
            <i class="fas fa-tasks"></i>
            <h3>Tidak ada tugas ditemukan</h3>
            <p>Coba ubah filter atau pencarian Anda</p>
            <button class="btn btn-primary" onclick="quickTaskModal.style.display='flex'" style="margin-top: 15px;">
              <i class="fas fa-plus"></i> Tambah Tugas Baru
            </button>
          </div>
        `;
        return;
      }

      tasksContainer.innerHTML = tasks.map(task => `
        <div class="task-card" onclick="openTaskDetail(${task.id})">
          <div class="task-header">
            <div class="task-title">${escapeHtml(task.title)}</div>
            <div class="task-status status-${task.status}">${escapeHtml(task.category)}</div>
          </div>
          
          <div class="task-meta">
            <div class="task-date">
              <i class="far fa-calendar"></i>
              ${task.start_date} - ${task.end_date}
            </div>
            <div class="task-assignees">
              ${getAssigneeAvatars(task.assigned_users)}
            </div>
          </div>
          
          <div class="progress-section">
            <div class="progress-label">
              <span>Progress</span>
              <span class="progress-percentage">${task.progress}%</span>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width: ${task.progress}%"></div>
            </div>
          </div>
          
          <div class="task-stats">
            <div class="task-stat">
              <div class="stat-number">${task.completed_subtasks}/${task.total_subtasks}</div>
              <div class="stat-label">Subtugas</div>
            </div>
            <div class="task-stat">
              <div class="stat-number">${task.comments}</div>
              <div class="stat-label">Komentar</div>
            </div>
            <div class="task-stat">
              <div class="stat-number">${task.assigned_count}</div>
              <div class="stat-label">Anggota</div>
            </div>
          </div>
          
          ${task.subtasks && task.subtasks.length > 0 ? `
          <div class="subtask-preview">
            ${task.subtasks.slice(0, 3).map(subtask => `
              <div class="subtask-item" onclick="event.stopPropagation(); updateSubtaskStatusDirect(${task.id}, ${subtask.id}, ${subtask.completed ? 0 : 1}, this)">
                <div class="subtask-check ${subtask.completed ? 'completed' : ''}" id="subtask-check-${subtask.id}">
                  <i class="fas fa-check"></i>
                </div>
                <div class="subtask-title ${subtask.completed ? 'completed' : ''}" id="subtask-title-${subtask.id}">
                  ${escapeHtml(subtask.title)}
                </div>
              </div>
            `).join('')}
            ${task.subtasks.length > 3 ? `
              <div class="subtask-item">
                <div></div>
                <div class="subtask-title">+${task.subtasks.length - 3} lainnya...</div>
              </div>
            ` : ''}
          </div>
          ` : ''}
          
          <div class="task-footer">
            <div class="time-remaining ${isTaskUrgent(task.end_date) ? 'urgent' : ''}">
              <i class="far fa-clock"></i> ${task.time_remaining}
            </div>
            <div class="task-actions">
              <button class="action-btn" onclick="event.stopPropagation(); updateTaskStatus(${task.id}, '${task.status === 'todo' ? 'progress' : task.status === 'progress' ? 'completed' : 'todo'}')">
                <i class="fas fa-${task.status === 'todo' ? 'play' : task.status === 'progress' ? 'check' : 'redo'}"></i>
              </button>
              <button class="action-btn" onclick="event.stopPropagation(); openTaskDetail(${task.id})">
                <i class="fas fa-eye"></i>
              </button>
              <button class="action-btn delete" onclick="event.stopPropagation(); showDeleteConfirmation(${task.id})">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>
        </div>
      `).join('');
    }

    // Get Assignee Avatars
    function getAssigneeAvatars(assignedUsers) {
      if (!assignedUsers) return '';
      
      const users = assignedUsers.split(',');
      let avatars = '';
      
      users.slice(0, 3).forEach((user, index) => {
        const initial = user.trim().charAt(0).toUpperCase();
        avatars += `<div class="assignee-avatar" title="${escapeHtml(user.trim())}">${initial}</div>`;
      });
      
      if (users.length > 3) {
        avatars += `<div class="more-assignees" title="${escapeHtml(users.slice(3).join(', '))}">+${users.length - 3}</div>`;
      }
      
      return avatars;
    }

    // Check if task is urgent
    function isTaskUrgent(endDate) {
      if (!endDate || endDate === '-') return false;
      
      const end = new Date(endDate);
      const today = new Date();
      const diffTime = end - today;
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
      
      return diffDays <= 3 && diffDays >= 0;
    }

    // Handle Quick Task
    async function handleQuickTaskSubmit(e) {
      e.preventDefault();
      
      const title = document.getElementById('quickTaskTitle').value;
      const assigned_to = document.getElementById('quickTaskAssignee').value;
      const due_date = document.getElementById('quickTaskDueDate').value;
      
      if (!title || !assigned_to || !due_date) {
        showNotification('Harap isi semua field', 'error');
        return;
      }
      
      showLoading();
      
      try {
        const formData = new FormData();
        formData.append('action', 'add_quick_task');
        formData.append('title', title);
        formData.append('assigned_to', assigned_to);
        formData.append('due_date', due_date);
        
        const response = await fetch('tugas_default.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification(result.message);
          quickTaskModal.style.display = 'none';
          quickTaskForm.reset();
          loadTasks();
        } else {
          showNotification(result.message, 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('Terjadi kesalahan', 'error');
      } finally {
        hideLoading();
      }
    }

    // Open Task Detail
    async function openTaskDetail(taskId) {
      currentTaskId = taskId;
      showLoading();
      
      try {
        const formData = new FormData();
        formData.append('action', 'get_task_detail');
        formData.append('taskId', taskId);
        
        const response = await fetch('tugas_default.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          renderTaskDetail(result.task);
          taskDetailModal.style.display = 'flex';
        } else {
          showNotification(result.message, 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('Terjadi kesalahan', 'error');
      } finally {
        hideLoading();
      }
    }

    // Render Task Detail dengan fitur tambahan
    function renderTaskDetail(task) {
      const assignedUsers = task.assigned_users || [];
      
      document.getElementById('taskDetailContent').innerHTML = `
        <div style="margin-bottom: 20px;">
          <div style="font-size: 1.2rem; font-weight: 700; color: #333; margin-bottom: 10px;">${escapeHtml(task.title)}</div>
          <div class="task-status status-${task.status}" style="display: inline-block; margin-bottom: 15px;">${escapeHtml(task.category)}</div>
          <div style="font-size: 0.9rem; color: #666; margin-bottom: 15px;">Progress: ${task.progress}%</div>
        </div>
        
        <div style="margin-bottom: 20px;">
          <div style="font-size: 0.95rem; color: #333; font-weight: 600; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-info-circle"></i> Informasi Tugas
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
            <div style="background: #f8faff; padding: 12px; border-radius: 10px;">
              <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">Tanggal Mulai</div>
              <div style="font-size: 0.95rem; font-weight: 600; color: #333;">${formatDate(task.start_date)}</div>
            </div>
            <div style="background: #f8faff; padding: 12px; border-radius: 10px;">
              <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">Tanggal Selesai</div>
              <div style="font-size: 0.95rem; font-weight: 600; color: #333;">${formatDate(task.end_date)}</div>
            </div>
            <div style="background: #f8faff; padding: 12px; border-radius: 10px;">
              <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">Subtugas</div>
              <div style="font-size: 0.95rem; font-weight: 600; color: #333;">${task.tasks_completed}/${task.tasks_total} selesai</div>
            </div>
            <div style="background: #f8faff; padding: 12px; border-radius: 10px;">
              <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">Komentar</div>
              <div style="font-size: 0.95rem; font-weight: 600; color: #333;">${task.comments.length}</div>
            </div>
          </div>
        </div>
        
        ${task.note ? `
        <div style="margin-bottom: 20px;">
          <div style="font-size: 0.95rem; color: #333; font-weight: 600; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-sticky-note"></i> Catatan
          </div>
          <div style="background: #f8faff; padding: 15px; border-radius: 10px; line-height: 1.5; font-size: 0.9rem;">
            ${escapeHtml(task.note)}
          </div>
        </div>
        ` : ''}
        
        ${assignedUsers.length > 0 ? `
        <div style="margin-bottom: 20px;">
          <div style="font-size: 0.95rem; color: #333; font-weight: 600; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-users"></i> Anggota Tim
          </div>
          <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            ${assignedUsers.map(user => `
              <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: white; border: 1px solid #e8f0ff; border-radius: 15px; font-size: 0.85rem;">
                <div style="width: 24px; height: 24px; border-radius: 50%; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; color: white; font-size: 0.7rem; font-weight: 600;">${escapeHtml(user.charAt(0).toUpperCase())}</div>
                <span>${escapeHtml(user)}</span>
              </div>
            `).join('')}
          </div>
        </div>
        ` : ''}
        
        ${task.subtasks && task.subtasks.length > 0 ? `
        <div style="margin-bottom: 20px;">
          <div style="font-size: 0.95rem; color: #333; font-weight: 600; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-tasks"></i> Subtugas (${task.tasks_completed}/${task.tasks_total})
          </div>
          <div style="max-height: 150px; overflow-y: auto; padding: 5px;">
            ${task.subtasks.map(subtask => `
              <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; padding: 8px; background: #f8faff; border-radius: 8px;">
                <div class="subtask-check ${subtask.is_completed ? 'completed' : ''}" 
                     onclick="updateSubtaskStatus(${subtask.id}, ${subtask.is_completed ? 0 : 1})" style="cursor: pointer; flex-shrink: 0;">
                  <i class="fas fa-check"></i>
                </div>
                <div style="flex: 1; font-size: 0.85rem; word-break: break-word;">
                  <span class="${subtask.is_completed ? 'completed' : ''}" style="${subtask.is_completed ? 'text-decoration: line-through; color: #9ca3af;' : ''}">
                    ${escapeHtml(subtask.title)}
                  </span>
                  ${subtask.assigned_to ? `<span style="font-size: 0.75rem; color: #666; margin-left: 5px;">(${escapeHtml(subtask.assigned_to)})</span>` : ''}
                </div>
                ${subtask.is_completed && subtask.completed_by ? `
                  <div style="font-size: 0.75rem; color: #10b981; flex-shrink: 0;">
                    <i class="fas fa-user-check"></i> ${escapeHtml(subtask.completed_by)}
                  </div>
                ` : ''}
              </div>
            `).join('')}
          </div>
        </div>
        ` : ''}
        
        <div style="margin-top: 25px; display: flex; gap: 10px; flex-wrap: wrap;">
          <button type="button" class="btn btn-secondary" onclick="taskDetailModal.style.display='none'" style="flex: 1; min-width: 120px;">
            Tutup
          </button>
          <button type="button" class="btn btn-danger" onclick="showDeleteConfirmation(${task.id})" style="flex: 1; min-width: 120px;">
            <i class="fas fa-trash"></i> Hapus
          </button>
        </div>
      `;
    }

    // Update Task Status
    async function updateTaskStatus(taskId, newStatus) {
      if (event) event.stopPropagation();
      
      showLoading();
      
      try {
        const formData = new FormData();
        formData.append('action', 'update_task_status');
        formData.append('taskId', taskId);
        formData.append('status', newStatus);
        
        const response = await fetch('tugas_default.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('Status berhasil diperbarui');
          loadTasks();
          if (currentTaskId === taskId) {
            openTaskDetail(taskId);
          }
        } else {
          showNotification(result.message || 'Gagal memperbarui status', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('Terjadi kesalahan', 'error');
      } finally {
        hideLoading();
      }
    }

    // Update Subtask Status dari kartu tugas langsung
    async function updateSubtaskStatusDirect(taskId, subtaskId, newStatus, element) {
      if (event) event.stopPropagation();
      
      // Update UI terlebih dahulu untuk feedback langsung
      const checkElement = element.querySelector('.subtask-check');
      const titleElement = element.querySelector('.subtask-title');
      
      if (newStatus === 1) {
        checkElement.classList.add('completed');
        titleElement.classList.add('completed');
      } else {
        checkElement.classList.remove('completed');
        titleElement.classList.remove('completed');
      }
      
      showLoading();
      
      try {
        const formData = new FormData();
        formData.append('action', 'update_subtask_status');
        formData.append('subtaskId', subtaskId);
        formData.append('is_completed', newStatus);
        
        const response = await fetch('tugas_default.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('Subtask berhasil diperbarui');
          loadTasks(); // Refresh tasks untuk update progress
        } else {
          // Rollback UI jika gagal
          if (newStatus === 1) {
            checkElement.classList.remove('completed');
            titleElement.classList.remove('completed');
          } else {
            checkElement.classList.add('completed');
            titleElement.classList.add('completed');
          }
          showNotification(result.message || 'Gagal memperbarui subtask', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        // Rollback UI jika error
        if (newStatus === 1) {
          checkElement.classList.remove('completed');
          titleElement.classList.remove('completed');
        } else {
          checkElement.classList.add('completed');
          titleElement.classList.add('completed');
        }
        showNotification('Terjadi kesalahan', 'error');
      } finally {
        hideLoading();
      }
    }

    // Update Subtask Status dari modal detail
    async function updateSubtaskStatus(subtaskId, newStatus) {
      if (event) event.stopPropagation();
      
      showLoading();
      
      try {
        const formData = new FormData();
        formData.append('action', 'update_subtask_status');
        formData.append('subtaskId', subtaskId);
        formData.append('is_completed', newStatus);
        
        const response = await fetch('tugas_default.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('Subtask berhasil diperbarui');
          openTaskDetail(currentTaskId);
          loadTasks(); // Refresh tasks untuk update progress
        } else {
          showNotification(result.message || 'Gagal memperbarui subtask', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('Terjadi kesalahan', 'error');
      } finally {
        hideLoading();
      }
    }

    // Show Delete Confirmation
    function showDeleteConfirmation(taskId) {
      if (event) event.stopPropagation();
      
      taskToDelete = taskId;
      confirmationModal.style.display = 'flex';
    }

    // Handle Delete Task
    async function handleDeleteTask() {
      if (!taskToDelete) return;
      
      showLoading();
      confirmationModal.style.display = 'none';
      
      try {
        const formData = new FormData();
        formData.append('action', 'delete_task');
        formData.append('taskId', taskToDelete);
        
        const response = await fetch('tugas_default.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification(result.message);
          taskDetailModal.style.display = 'none';
          loadTasks();
        } else {
          showNotification(result.message || 'Gagal menghapus tugas', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('Terjadi kesalahan', 'error');
      } finally {
        hideLoading();
        taskToDelete = null;
      }
    }

    // Format Date
    function formatDate(dateString) {
      if (!dateString || dateString === '-') return '-';
      
      const date = new Date(dateString);
      return date.toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric'
      });
    }

    // Show Loading
    function showLoading() {
      loadingOverlay.style.display = 'flex';
    }

    // Hide Loading
    function hideLoading() {
      loadingOverlay.style.display = 'none';
    }

    // Show Notification
    function showNotification(message, type = 'success') {
      notificationText.textContent = message;
      notification.className = 'notification';
      notification.classList.add(type === 'error' ? 'error' : 'show');
      
      setTimeout(() => {
        notification.classList.remove('show');
      }, 3000);
    }

    // Escape HTML untuk mencegah XSS
    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Debounce Function
    function debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    }
  </script>
</body>
</html>