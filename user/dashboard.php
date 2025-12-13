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

// Hitung total tugas user
$totalTasksQuery = $mysqli->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ?");
$searchUsername = "%$username%";
$totalTasksQuery->bind_param("s", $searchUsername);
$totalTasksQuery->execute();
$totalTasksResult = $totalTasksQuery->get_result();
$totalTasks = $totalTasksResult->fetch_assoc()['total'];

// Hitung tugas belum selesai (todo + progress)
$todoProgressQuery = $mysqli->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ? AND status IN ('todo', 'progress')");
$todoProgressQuery->bind_param("s", $searchUsername);
$todoProgressQuery->execute();
$todoProgressResult = $todoProgressQuery->get_result();
$todoProgressCount = $todoProgressResult->fetch_assoc()['total'];

// Hitung tugas selesai
$completedQuery = $mysqli->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ? AND status = 'completed'");
$completedQuery->bind_param("s", $searchUsername);
$completedQuery->execute();
$completedResult = $completedQuery->get_result();
$completedCount = $completedResult->fetch_assoc()['total'];

// Hitung total users
$totalUsersQuery = $mysqli->query("SELECT COUNT(*) as total FROM users WHERE role != 'admin'");
$totalUsers = $totalUsersQuery->fetch_assoc()['total'];

// Ambil semua tugas untuk user (untuk filtering di frontend)
$allTasksQuery = $mysqli->prepare("SELECT * FROM tasks WHERE assigned_users LIKE ? ORDER BY end_date ASC");
$allTasksQuery->bind_param("s", $searchUsername);
$allTasksQuery->execute();
$allTasksResult = $allTasksQuery->get_result();
$allTasks = [];
while ($row = $allTasksResult->fetch_assoc()) {
    $allTasks[] = $row;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - <?= htmlspecialchars($userData['name']) ?></title>
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
            background: linear-gradient(180deg, #f0f4ff 0%, #e8f0ff 100%);
            color: #333;
            min-height: 100vh;
            padding-bottom: 100px;
        }

        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 20px 15px;
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

        .profile-btn {
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
            overflow: hidden;
        }

        .profile-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .profile-btn img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
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

        /* Stats Section */
        .stats-section {
            margin-bottom: 28px;
            background: #2a2a2a;
            padding: 24px 18px;
            border-radius: 16px;
        }

        .stats-title {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
        }
        
        .total-tugas-label {
            font-size: 12px;
            color: #b0b0b0;
            font-weight: 500;
            margin-bottom: 18px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin: 0 0 12px 0;
        }

        .stats-grid-three {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .stat-card {
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            border-radius: 16px;
            padding: 28px 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            text-align: center;
            border: none;
            transition: all 0.3s ease;
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 160px;
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.18);
        }

        .stat-card.blue {
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
        }

        .stat-card.green {
            background: linear-gradient(135deg, #14b8a6 0%, #06b6d4 100%);
        }

        .stat-card.yellow {
            background: linear-gradient(135deg, #f59e0b 0%, #ec4899 100%);
        }

        .stat-card.red {
            background: linear-gradient(135deg, #f43f5e 0%, #ec4899 100%);
        }

        .stat-card.white {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #1f2937;
        }

        .stat-icon {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            font-size: 12px;
            font-weight: 600;
            justify-content: center;
            color: inherit;
        }

        .stat-icon i {
            font-size: 18px;
        }

        .stat-number {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 0;
            color: inherit;
            line-height: 1;
        }

        .stat-label {
            color: inherit;
            font-size: 12px;
            font-weight: 500;
            opacity: 0.98;
            margin-top: 0;
        }

        /* Tasks Section */
        .tasks-section {
            margin-bottom: 25px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .section-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 5px;
            -webkit-overflow-scrolling: touch;
        }

        .filter-tabs::-webkit-scrollbar {
            display: none;
        }

        .filter-tab {
            padding: 6px 14px;
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
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
        }

        /* Task Card */
        .task-card {
            background: white;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.3s;
        }

        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .task-category {
            font-size: 10px;
            color: #f59e0b;
            font-weight: 600;
            margin-bottom: 5px;
            text-transform: capitalize;
            letter-spacing: 0.3px;
        }
        
        .task-category.belum {
            color: #f59e0b;
        }
        
        .task-category.sedang {
            color: #3550dc;
        }
        
        .task-category.selesai {
            color: #10b981;
        }

        .task-title {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            line-height: 1.4;
            margin-bottom: 8px;
        }

        .task-menu {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 16px;
            cursor: pointer;
            padding: 0;
        }

        .task-footer {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-top: 10px;
            border-top: 1px solid #f0f3f8;
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

        .task-date {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: #9ca3af;
            margin-left: auto;
        }

        .task-date i {
            font-size: 12px;
        }

        /* Progress Bar Styles */
        .task-progress-container {
            margin: 12px 0 10px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-bar-bg {
            flex: 1;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #0038ff, #0055ff);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 11px;
            font-weight: 600;
            color: #333;
            min-width: 32px;
            text-align: right;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
            background: white;
            border-radius: 15px;
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

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                max-width: 100%;
                padding: 15px 12px;
            }

            .header {
                padding: 18px 12px 20px 12px;
            }

            .header-content {
                gap: 10px;
                margin-bottom: 15px;
            }

            .back-btn {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }

            .header-title {
                font-size: 16px;
            }

            .search-box-header {
                padding: 8px 12px;
                gap: 8px;
            }

            .search-box-header i {
                font-size: 14px;
            }

            .search-box-header input {
                font-size: 13px;
            }

            .stats-section {
                padding: 20px 15px;
                margin-bottom: 24px;
            }

            .stats-title {
                font-size: 13px;
            }

            .total-tugas-label {
                font-size: 11px;
                margin-bottom: 16px;
            }

            .stats-grid {
                gap: 12px;
            }

            .stats-grid-three {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .stat-card {
                padding: 20px 16px;
                min-height: 140px;
            }

            .stat-icon {
                font-size: 11px;
                margin-bottom: 12px;
            }

            .stat-icon i {
                font-size: 16px;
            }

            .stat-number {
                font-size: 36px;
            }

            .stat-label {
                font-size: 11px;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .section-header h3 {
                font-size: 15px;
            }

            .filter-tabs {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .filter-tab {
                padding: 5px 12px;
                font-size: 11px;
                flex-shrink: 0;
            }

            .task-card {
                padding: 12px;
                margin-bottom: 10px;
            }

            .task-title {
                font-size: 13px;
            }

            .task-footer {
                padding-top: 8px;
                gap: 8px;
            }

            .task-icon-item {
                font-size: 11px;
            }

            .task-icon-item i {
                font-size: 12px;
            }

            .task-date {
                font-size: 10px;
            }

            .task-date i {
                font-size: 11px;
            }

            .progress-text {
                font-size: 10px;
                min-width: 28px;
            }

            .bottom-nav {
                max-width: 98%;
                padding: 6px;
                gap: 2px;
            }

            .bottom-nav a {
                padding: 8px 12px;
                font-size: 11px;
                gap: 5px;
            }

            .bottom-nav a i {
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 12px 10px;
            }

            .header {
                padding: 16px 10px 18px 10px;
            }

            .header-content {
                gap: 8px;
                margin-bottom: 12px;
            }

            .back-btn {
                width: 30px;
                height: 30px;
                font-size: 13px;
            }

            .header-title {
                font-size: 15px;
            }

            .search-box-header {
                padding: 7px 10px;
                gap: 6px;
            }

            .search-box-header i {
                font-size: 13px;
            }

            .search-box-header input {
                font-size: 12px;
            }

            .stats-section {
                padding: 18px 12px;
                margin-bottom: 20px;
            }

            .stats-title {
                font-size: 12px;
            }

            .total-tugas-label {
                font-size: 10px;
                margin-bottom: 14px;
            }

            .stats-grid {
                gap: 10px;
            }

            .stats-grid-three {
                gap: 10px;
            }

            .stat-card {
                padding: 16px 12px;
                min-height: 120px;
            }

            .stat-icon {
                font-size: 10px;
                margin-bottom: 10px;
            }

            .stat-icon i {
                font-size: 14px;
            }

            .stat-number {
                font-size: 28px;
            }

            .stat-label {
                font-size: 10px;
            }

            .section-header {
                gap: 10px;
            }

            .section-header h3 {
                font-size: 14px;
            }

            .filter-tab {
                padding: 4px 10px;
                font-size: 10px;
            }

            .task-card {
                padding: 10px;
                margin-bottom: 8px;
            }

            .task-title {
                font-size: 12px;
            }

            .task-footer {
                padding-top: 6px;
                gap: 6px;
            }

            .task-icon-item {
                font-size: 10px;
            }

            .task-icon-item i {
                font-size: 11px;
            }

            .task-date {
                font-size: 9px;
            }

            .task-date i {
                font-size: 10px;
            }

            .progress-text {
                font-size: 9px;
                min-width: 24px;
            }

            .empty-state {
                padding: 30px 15px;
            }

            .empty-state i {
                font-size: 2.5rem;
                margin-bottom: 12px;
            }

            .empty-state p {
                font-size: 13px;
            }

            .bottom-nav {
                max-width: 99%;
                padding: 5px;
                gap: 1px;
                bottom: 10px;
            }

            .bottom-nav a {
                padding: 6px 8px;
                font-size: 10px;
                gap: 3px;
            }

            .bottom-nav a i {
                font-size: 12px;
            }
        }

        @media (max-width: 360px) {
            .container {
                padding: 10px 8px;
            }

            .header {
                padding: 14px 8px 16px 8px;
            }

            .header-content {
                gap: 6px;
                margin-bottom: 10px;
            }

            .back-btn {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }

            .header-title {
                font-size: 14px;
            }

            .search-box-header {
                padding: 6px 8px;
                gap: 5px;
            }

            .search-box-header i {
                font-size: 12px;
            }

            .search-box-header input {
                font-size: 11px;
            }

            .stats-section {
                padding: 16px 10px;
                margin-bottom: 18px;
            }

            .stats-title {
                font-size: 11px;
            }

            .total-tugas-label {
                font-size: 9px;
                margin-bottom: 12px;
            }

            .stats-grid {
                gap: 8px;
            }

            .stats-grid-three {
                gap: 8px;
            }

            .stat-card {
                padding: 14px 10px;
                min-height: 100px;
            }

            .stat-icon {
                font-size: 9px;
                margin-bottom: 8px;
            }

            .stat-icon i {
                font-size: 12px;
            }

            .stat-number {
                font-size: 24px;
            }

            .stat-label {
                font-size: 9px;
            }

            .section-header h3 {
                font-size: 13px;
            }

            .filter-tab {
                padding: 3px 8px;
                font-size: 9px;
            }

            .task-card {
                padding: 8px;
                margin-bottom: 6px;
            }

            .task-title {
                font-size: 11px;
            }

            .task-footer {
                padding-top: 5px;
                gap: 4px;
            }

            .task-icon-item {
                font-size: 9px;
            }

            .task-icon-item i {
                font-size: 10px;
            }

            .task-date {
                font-size: 8px;
            }

            .task-date i {
                font-size: 9px;
            }

            .progress-text {
                font-size: 8px;
                min-width: 20px;
            }

            .empty-state {
                padding: 25px 12px;
            }

            .empty-state i {
                font-size: 2rem;
                margin-bottom: 10px;
            }

            .empty-state p {
                font-size: 12px;
            }

            .bottom-nav {
                max-width: 100%;
                padding: 4px;
                gap: 0px;
                bottom: 8px;
            }

            .bottom-nav a {
                padding: 5px 6px;
                font-size: 9px;
                gap: 2px;
            }

            .bottom-nav a i {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <button class="back-btn" onclick="window.location.href='../auth/login.php'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">Dashboard</div>
                <button class="profile-btn" onclick="window.location.href='profile.php'">
                    <?php if (!empty($userData['foto'])): ?>
                        <img src="../uploads/<?= $userData['foto'] ?>" alt="Profile">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </button>
            </div>
            
            <!-- Search Box -->
            <div class="search-box-header">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search task" id="searchInput">
            </div>
        </div>

        <!-- Stats Section -->
        <div class="stats-section">
            <h3 class="stats-title">Anda Memiliki</h3>
            <p class="total-tugas-label">total <?= $totalTasks ?> tugas</p>
            
            <!-- Stats Grid 2 Column -->
            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Tugas Dikerjakan</span>
                    </div>
                    <div class="stat-number"><?= $todoProgressCount ?></div>
                </div>
                
                <div class="stat-card green">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                        <span>Tugas Selesai</span>
                    </div>
                    <div class="stat-number"><?= $completedCount ?></div>
                </div>
            </div>

            <!-- Stats Grid 3 Column -->
            <div class="stats-grid-three">
                <div class="stat-card yellow">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Tugas Dibuat</span>
                    </div>
                    <div class="stat-number"><?= $totalTasks ?></div>
                </div>
                
                <div class="stat-card red">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard"></i>
                        <span>Belum Dikerjakan</span>
                    </div>
                    <div class="stat-number"><?= $todoProgressCount ?></div>
                </div>

                <div class="stat-card white">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                        <span>Total User</span>
                    </div>
                    <div class="stat-number"><?= $totalUsers ?></div>
                </div>
            </div>
        </div>

        <!-- Tasks Section -->
        <div class="tasks-section">
            <div class="section-header">
                <h3>Tasks</h3>
                <div class="filter-tabs">
                    <button class="filter-tab active" data-status="all">Hari Ini</button>
                    <button class="filter-tab" data-status="upcoming">Mendatang</button>
                    <button class="filter-tab" data-status="completed">Selesai</button>
                </div>
            </div>
            
            <div id="tasksContainer">
                <?php if (!empty($allTasks)): ?>
                    <?php foreach ($allTasks as $task): 
                        $categoryClass = '';
                        if ($task['status'] === 'progress') {
                            $categoryClass = 'sedang';
                        } elseif ($task['status'] === 'completed') {
                            $categoryClass = 'selesai';
                        } else {
                            $categoryClass = 'belum';
                        }
                    ?>
                        <div class="task-card" 
                             data-filter="task-card" 
                             data-status="<?= $task['status'] ?>" 
                             data-end-date="<?= $task['end_date'] ?>"
                             onclick="window.location.href='task_detail.php?id=<?= $task['id'] ?>'">
                            <div class="task-header">
                                <div>
                                    <div class="task-category <?= $categoryClass ?>"><?= ucfirst($task['category']) ?></div>
                                    <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                                </div>
                                <button class="task-menu" onclick="event.stopPropagation()">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div class="task-progress-container">
                                <div class="progress-bar-bg">
                                    <div class="progress-bar-fill" style="width: <?= $task['progress'] ?>%"></div>
                                </div>
                                <div class="progress-text"><?= $task['progress'] ?>%</div>
                            </div>
                            
                            <div class="task-footer">
                                <div class="task-icon-item">
                                    <i class="far fa-check-square"></i>
                                    <span><?= $task['tasks_completed'] ?>/<?= $task['tasks_total'] ?></span>
                                </div>
                                <div class="task-icon-item">
                                    <i class="far fa-comment"></i>
                                    <span><?= $task['comments'] ?></span>
                                </div>
                                <div class="task-date">
                                    <i class="far fa-calendar"></i>
                                    <span><?= date('d M', strtotime($task['end_date'])) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <p>Belum ada tugas yang ditugaskan kepada Anda</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bottom Navigation - SAMA PERSIS DENGAN TASKS.PHP -->
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
            <span>Profil</span>
        </a>
    </div>

    <script>
        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const tasksContainer = document.getElementById('tasksContainer');
        const filterTabs = document.querySelectorAll('.filter-tab');

        function getTodayDateString() {
            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function filterTasks() {
            const searchTerm = searchInput.value.toLowerCase();
            const activeTab = document.querySelector('.filter-tab.active');
            const filterType = activeTab ? activeTab.getAttribute('data-status') : 'all';
            const today = getTodayDateString();
            const taskCards = tasksContainer.querySelectorAll('[data-filter="task-card"]');
            
            taskCards.forEach(card => {
                const title = card.querySelector('.task-title').textContent.toLowerCase();
                const status = card.getAttribute('data-status');
                const endDate = card.getAttribute('data-end-date');
                
                // Search filter
                const matchesSearch = title.includes(searchTerm);
                
                // Date filter
                let matchesFilter = true;
                if (filterType === 'today') {
                    // Tasks due today
                    matchesFilter = endDate === today && status !== 'completed';
                } else if (filterType === 'upcoming') {
                    // Tasks due in the future
                    matchesFilter = endDate > today && status !== 'completed';
                } else if (filterType === 'completed') {
                    // Completed tasks
                    matchesFilter = status === 'completed';
                } else if (filterType === 'all') {
                    // All tasks
                    matchesFilter = true;
                }
                
                card.style.display = (matchesSearch && matchesFilter) ? 'block' : 'none';
            });
        }

        searchInput.addEventListener('input', filterTasks);

        // Filter tabs functionality
        filterTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                filterTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                filterTasks();
            });
        });
    </script>
</body>
</html>