<?php
// stats.php - Komponen stats yang digunakan di semua halaman user
// Pastikan session dan koneksi sudah di-include sebelumnya

if (!isset($mysqli) || !isset($username)) {
    die("Error: Database connection atau username tidak ditemukan");
}

// Hitung tugas dikerjakan
$inProgressQuery = $mysqli->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ? AND status = 'progress'");
$searchUsername = "%$username%";
$inProgressQuery->bind_param("s", $searchUsername);
$inProgressQuery->execute();
$inProgressCount = $inProgressQuery->get_result()->fetch_assoc()['total'];

// Hitung tugas selesai
$completedQuery = $mysqli->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ? AND status = 'completed'");
$completedQuery->bind_param("s", $searchUsername);
$completedQuery->execute();
$completedCount = $completedQuery->get_result()->fetch_assoc()['total'];

// Hitung tugas dibuat oleh user
$createdByUserQuery = $mysqli->prepare("SELECT COUNT(*) as total FROM tasks WHERE created_by = ?");
$createdByUserQuery->bind_param("s", $username);
$createdByUserQuery->execute();
$createdByUserCount = $createdByUserQuery->get_result()->fetch_assoc()['total'];

// Hitung tugas belum dikerjakan
$todoQuery = $mysqli->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ? AND status = 'todo'");
$todoQuery->bind_param("s", $searchUsername);
$todoQuery->execute();
$todoCount = $todoQuery->get_result()->fetch_assoc()['total'];

// Hitung total users
$totalUsersQuery = $mysqli->query("SELECT COUNT(*) as total FROM users WHERE role != 'admin'");
$totalUsers = $totalUsersQuery->fetch_assoc()['total'];

// Hitung total tasks
$totalTasksQuery = $mysqli->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ?");
$totalTasksQuery->bind_param("s", $searchUsername);
$totalTasksQuery->execute();
$totalTasks = $totalTasksQuery->get_result()->fetch_assoc()['total'];
?>

<div class="stats-container">
    <?php if (empty($hide_stats_header)): ?>
    <div class="stats-header">
        <h3>Anda Memiliki</h3>
        <p>total <?= $totalTasks ?> tugas</p>
    </div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <!-- Tugas Dikerjakan -->
        <div class="stat-card stat-card-blue">
            <div class="stat-icon">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stat-label">Tugas Dikerjakan</div>
            <div class="stat-number"><?= $inProgressCount ?></div>
        </div>

        <!-- Tugas Selesai -->
        <div class="stat-card stat-card-green">
            <div class="stat-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-label">Tugas Selesai</div>
            <div class="stat-number"><?= $completedCount ?></div>
        </div>

        <!-- Tugas Dibuat -->
        <div class="stat-card stat-card-orange">
            <div class="stat-icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="stat-label">Tugas Dibuat</div>
            <div class="stat-number"><?= $createdByUserCount ?></div>
        </div>

        <!-- Tugas Belum Dikerjakan -->
        <div class="stat-card stat-card-pink">
            <div class="stat-icon">
                <i class="fas fa-lock"></i>
            </div>
            <div class="stat-label">Belum Dikerjakan</div>
            <div class="stat-number"><?= $todoCount ?></div>
        </div>

        <!-- Total User -->
        <div class="stat-card stat-card-gray">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-label">Total User</div>
            <div class="stat-number"><?= $totalUsers ?></div>
        </div>
    </div>
</div>

<style>
    .stats-container {
        padding: 0 15px 20px 15px;
        animation: fadeIn 0.4s ease-in;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .stats-header {
        margin-bottom: 16px;
        color: #1f2937;
    }

    .stats-header h3 {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 4px;
        letter-spacing: -0.3px;
    }

    .stats-header p {
        font-size: 13px;
        color: #6b7280;
        font-weight: 500;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 16px;
    }

    .stat-card {
        background: white;
        border-radius: 16px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        min-height: 120px;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    }

    /* Blue - Tugas Dikerjakan */
    .stat-card-blue {
        background: linear-gradient(135deg, #5b6dd8 0%, #4f46e5 100%);
        color: white;
    }

    .stat-card-blue .stat-icon {
        font-size: 28px;
        margin-bottom: 8px;
        opacity: 0.9;
    }

    /* Green - Tugas Selesai */
    .stat-card-green {
        background: linear-gradient(135deg, #48a78a 0%, #3b9b7c 100%);
        color: white;
    }

    .stat-card-green .stat-icon {
        font-size: 28px;
        margin-bottom: 8px;
        opacity: 0.9;
    }

    /* Orange - Tugas Dibuat */
    .stat-card-orange {
        background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
        color: white;
    }

    .stat-card-orange .stat-icon {
        font-size: 28px;
        margin-bottom: 8px;
        opacity: 0.9;
    }

    /* Pink - Belum Dikerjakan */
    .stat-card-pink {
        background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
        color: white;
    }

    .stat-card-pink .stat-icon {
        font-size: 28px;
        margin-bottom: 8px;
        opacity: 0.9;
    }

    /* Gray - Total User */
    .stat-card-gray {
        background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
        color: #1f2937;
    }

    .stat-card-gray .stat-icon {
        font-size: 28px;
        margin-bottom: 8px;
        opacity: 0.7;
    }

    .stat-label {
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 8px;
        opacity: 0.9;
        letter-spacing: 0.3px;
    }

    .stat-number {
        font-size: 28px;
        font-weight: 700;
        line-height: 1;
    }

    @media (max-width: 480px) {
        .stats-container {
            padding: 0 12px 16px 12px;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .stat-card {
            padding: 14px;
            min-height: 110px;
        }

        .stat-label {
            font-size: 11px;
        }

        .stat-number {
            font-size: 24px;
        }

        .stat-icon {
            font-size: 24px !important;
        }
    }
</style>
