<?php
session_start();
require_once "../inc/koneksi.php";

if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$admin = $_SESSION['admin'];
$query = $mysqli->query("SELECT * FROM users WHERE username = '$admin'");
$user = $query->fetch_assoc();
$current_user_id = $user['id'];

// Get tasks yang di-assign ke user
$myTasksQuery = "SELECT t.*, ta.status as assignment_status 
                 FROM tasks t 
                 JOIN task_assignments ta ON t.id = ta.task_id 
                 WHERE ta.user_id = $current_user_id 
                 ORDER BY 
                   CASE ta.status 
                     WHEN 'in_progress' THEN 1
                     WHEN 'assigned' THEN 2
                     WHEN 'completed' THEN 3
                     ELSE 4
                   END,
                   t.created_at DESC";
$myTasksResult = $mysqli->query($myTasksQuery);
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tugas Saya | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    * {
      margin: 0; padding: 0; box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }

    body {
      background: #f8f9fe;
      min-height: 100vh;
      padding-bottom: 90px;
    }

    /* Header */
    header {
      background: linear-gradient(135deg, #4169E1, #1e3a8a);
      color: #fff;
      padding: 20px 20px 30px 20px;
      position: relative;
      border-radius: 0 0 25px 25px;
      box-shadow: 0 4px 20px rgba(65, 105, 225, 0.2);
    }

    .header-content {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 5px;
    }

    .back-btn {
      background: rgba(255, 255, 255, 0.25);
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
    }

    .back-btn:hover {
      background: rgba(255, 255, 255, 0.35);
    }

    .header-title {
      font-size: 1.4rem;
      font-weight: 600;
      letter-spacing: 0.3px;
    }

    /* Content */
    .content {
      padding: 20px;
      margin-top: -15px;
    }

    /* Task Card */
    .task-card {
      background: white;
      border-radius: 18px;
      padding: 18px;
      margin-bottom: 14px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.07);
      position: relative;
      cursor: pointer;
      transition: all 0.3s;
      border: 1px solid #f0f3f8;
    }

    .task-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 20px rgba(0,0,0,0.12);
      border-color: #e0e5ed;
    }

    .task-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 14px;
    }

    .task-category {
      font-size: 0.75rem;
      color: #f59e0b;
      font-weight: 600;
      margin-bottom: 6px;
      text-transform: capitalize;
      letter-spacing: 0.3px;
    }

    .task-category.sedang {
      color: #4169E1;
    }

    .task-category.selesai {
      color: #10b981;
    }

    .task-title {
      font-size: 1.05rem;
      font-weight: 600;
      color: #1f2937;
      margin-bottom: 12px;
      line-height: 1.4;
    }

    /* Assignment Status */
    .assignment-status {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 0.7rem;
      font-weight: 600;
      margin-left: 10px;
    }

    .assignment-status.assigned {
      background: #fef3c7;
      color: #d97706;
    }

    .assignment-status.in_progress {
      background: #dbeafe;
      color: #1e40af;
    }

    .assignment-status.completed {
      background: #d1fae5;
      color: #065f46;
    }

    /* Progress Section */
    .progress-section {
      margin-bottom: 14px;
    }

    .progress-label {
      font-size: 0.8rem;
      color: #6b7280;
      font-weight: 500;
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .progress-bar-container {
      height: 7px;
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
      font-size: 0.85rem;
      font-weight: 600;
      color: #1f2937;
    }

    /* Start Task Button */
    .btn-start-task {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      border: none;
      border-radius: 8px;
      padding: 8px 15px;
      font-size: 0.8rem;
      cursor: pointer;
      transition: all 0.3s;
      margin-top: 10px;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 5px;
    }

    .btn-start-task:hover {
      background: linear-gradient(135deg, #059669, #047857);
      transform: translateY(-1px);
    }

    /* Bottom Navigation */
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
      max-width: 90%;
      padding: 8px;
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
      padding: 11px 20px;
      font-size: 15px;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      white-space: nowrap;
    }

    .bottom-nav a i {
      font-size: 17px;
    }

    .bottom-nav a.active { 
      background: #4169E1; 
      color: #fff;
    }

    .bottom-nav a:not(.active):hover {
      color: #4169E1;
      background: #f3f4f6;
    }

    /* No results state */
    .no-results {
      text-align: center;
      padding: 60px 20px;
      color: #666;
    }

    .no-results i {
      font-size: 4rem;
      color: #ddd;
      margin-bottom: 20px;
    }

    .no-results p {
      margin-bottom: 15px;
      font-size: 1.1rem;
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
      <div class="header-title">Tugas Saya</div>
    </div>
  </header>

  <!-- Content -->
  <div class="content">
    <div class="tasks-container">
      <?php if ($myTasksResult->num_rows > 0): ?>
        <?php while($task = $myTasksResult->fetch_assoc()): ?>
          <div class="task-card" onclick="openTaskDetail(<?= $task['id'] ?>)">
            <div class="task-header">
              <div>
                <div class="task-category <?= $task['status'] === 'progress' ? 'sedang' : ($task['status'] === 'completed' ? 'selesai' : '') ?>">
                  <?= $task['category'] ?>
                </div>
                <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                <div class="assignment-status <?= $task['assignment_status'] ?>">
                  Status: <?= $task['assignment_status'] === 'in_progress' ? 'Sedang Dikerjakan' : 
                                 ($task['assignment_status'] === 'completed' ? 'Selesai' : 'Ditugaskan') ?>
                </div>
              </div>
            </div>

            <div class="progress-section">
              <div class="progress-label">
                <span>Progress</span>
                <span class="progress-percentage"><?= $task['progress'] ?>%</span>
              </div>
              <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?= $task['progress'] ?>%"></div>
              </div>
            </div>

            <?php if($task['assignment_status'] === 'assigned'): ?>
              <button class="btn-start-task" onclick="event.stopPropagation(); startTask(<?= $task['id'] ?>)">
                <i class="fas fa-play"></i> Mulai Kerjakan
              </button>
            <?php endif; ?>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="no-results">
          <i class="fas fa-tasks"></i>
          <p>Belum ada tugas yang di-assign ke Anda</p>
          <p>Ambil tugas dari halaman Tasks atau tunggu assignment dari teman</p>
        </div>
      <?php endif; ?>
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
    function openTaskDetail(taskId) {
      window.location.href = `tasks.php?view_task=${taskId}`;
    }

    async function startTask(taskId) {
      try {
        const formData = new FormData();
        formData.append('action', 'start_task');
        formData.append('taskId', taskId);

        const response = await fetch('tasks.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          alert('Tugas dimulai! Selamat bekerja!');
          location.reload();
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat memulai tugas');
      }
    }
  </script>
</body>
</html>