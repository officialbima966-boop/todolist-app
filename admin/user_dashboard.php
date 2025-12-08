<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login untuk user biasa
if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

$username = $_SESSION['user'];
$query = $mysqli->query("SELECT * FROM users WHERE username = '$username'");
$user = $query->fetch_assoc();

// Get tasks assigned to this user
$tasksQuery = "SELECT t.* FROM tasks t 
               WHERE t.assigned_users LIKE '%$username%' 
               OR EXISTS (
                   SELECT 1 FROM task_subtasks ts 
                   WHERE ts.task_id = t.id AND ts.assigned_to = '$username'
               )
               ORDER BY t.created_at DESC";
$tasksResult = $mysqli->query($tasksQuery);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - BM Garage</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Gunakan style yang sama seperti tasks.php */
        * {
            margin: 0; padding: 0; box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f5f7fa;
            min-height: 100vh;
            padding-bottom: 90px;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            padding: 20px;
            position: relative;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b, #f97316);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        /* ... (style lainnya sama seperti tasks.php) ... */
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div>
                <h1>BM Garage</h1>
                <p style="opacity: 0.8; font-size: 0.9rem;">Task Management System</p>
            </div>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight: 600;"><?php echo $user['full_name']; ?></div>
                    <div style="font-size: 0.8rem; opacity: 0.8;">@<?php echo $user['username']; ?></div>
                </div>
            </div>
        </div>
    </header>

    <div class="content">
        <div style="text-align: center; margin: 30px 0;">
            <h2>Selamat Datang, <?php echo $user['full_name']; ?>! 👋</h2>
            <p style="color: #666; margin-top: 10px;">Ini adalah tugas yang ditugaskan kepada Anda</p>
        </div>

        <!-- Task Cards -->
        <div id="tasksContainer">
            <?php
            if ($tasksResult->num_rows > 0) {
                while ($task = $tasksResult->fetch_assoc()) {
                    $categoryClass = $task['status'] === 'progress' ? 'sedang' : ($task['status'] === 'completed' ? 'selesai' : '');
                    $assignedUsersArray = $task['assigned_users'] ? explode(',', $task['assigned_users']) : [];
                    
                    echo '
                    <div class="task-card" onclick="openTaskDetail('.$task['id'].')">
                        <div class="task-header">
                            <div>
                                <div class="task-category '.$categoryClass.'">'.$task['category'].'</div>
                                <div class="task-title">'.$task['title'].'</div>
                            </div>
                        </div>

                        <div class="progress-section">
                            <span class="progress-label">Progress</span>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: '.$task['progress'].'%"></div>
                            </div>
                            <div class="progress-percentage">'.$task['progress'].'%</div>
                        </div>

                        <div class="task-stats">
                            <div class="stat-item" title="Subtasks completed">
                                <i class="far fa-check-square"></i>
                                <span>'.$task['tasks_completed'].'/'.$task['tasks_total'].'</span>
                            </div>
                            <div class="stat-item" title="Komentar">
                                <i class="far fa-comment"></i>
                                <span>'.$task['comments'].'</span>
                            </div>
                        </div>
                    </div>';
                }
            } else {
                echo '
                <div class="no-results">
                    <i class="fas fa-tasks"></i>
                    <p>Belum ada tugas untuk Anda</p>
                    <p>Tunggu sampai admin memberikan tugas kepada Anda</p>
                </div>';
            }
            ?>
        </div>
    </div>

  <!-- NAVIGASI BARU - SESUAI GAMBAR -->
  <div class="bottom-nav">
    <a href="dashboard.php" class="active">
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
    <a href="profile.php">
      <i class="fa-solid fa-user"></i>
      <span>Profile</span>
    </a>
    <a href="profile.php">
      <i class="fa-solid fa-user"></i>
      <span>TUGAS DEFAULT</span>
    </a>
  </div>

    <script>
        // Fungsi untuk membuka detail task
        function openTaskDetail(taskId) {
            // Redirect ke halaman detail task untuk user
            window.location.href = 'user_task_detail.php?id=' + taskId;
        }
    </script>
</body>
</html>