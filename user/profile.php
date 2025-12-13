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
$userData = $result->fetch_assoc();
$userQuery->close();

// Redirect jika admin
if ($userData['role'] == 'admin') {
    header("Location: ../admin/dashboard.php");
    exit;
}

// Jika baru selesai update profil → tampilkan alert
if (isset($_GET['update']) && $_GET['update'] == 'success') {
    echo "<script>alert('✅ Profil berhasil diperbarui!');</script>";
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Profile - <?= htmlspecialchars($userData['name']) ?></title>
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

        /* Header - SAMA PERSIS TASKS.PHP */
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

        /* Content - SAMA PERSIS TASKS.PHP */
        .content {
            padding: 15px;
        }

        /* Profile Header - DIUBAH AGAR PERSIS CARD TASKS */
        .profile-header {
            background: white;
            border-radius: 15px;
            padding: 25px 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid #f0f3f8;
            margin-bottom: 12px;
            transition: all 0.3s;
        }

        .profile-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: 600;
            overflow: hidden;
            border: 3px solid #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-name {
            font-size: 1.3rem;
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 5px;
            word-break: break-word;
        }

        .profile-email {
            font-size: 0.95rem;
            color: #6b7280;
            margin-bottom: 8px;
            word-break: break-word;
        }

        .profile-phone {
            color: #6b7280;
            font-size: 0.9rem;
            margin-top: 5px;
            word-break: break-word;
        }

        /* Profile Menu - DIUBAH AGAR PERSIS CARD TASKS */
        .profile-menu {
            background: #fff;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid #f0f3f8;
        }

        .menu-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 20px;
            border-bottom: 1px solid #f0f3f8;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .menu-item:last-child {
            border-bottom: none;
        }

        .menu-item:hover {
            background: #f8faff;
            transform: translateY(-1px);
        }

        .menu-item-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .menu-icon {
            color: #3550dc;
            font-size: 1.3rem;
            width: 30px;
            text-align: center;
        }

        .menu-text {
            flex: 1;
            min-width: 0;
        }

        .menu-title {
            font-size: 14px;
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 2px;
            word-break: break-word;
        }

        .menu-arrow {
            color: #9ca3af;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .menu-item.logout .menu-icon {
            color: #ef4444;
        }

        .menu-item.logout .menu-title {
            color: #ef4444;
        }

        /* Bottom Navigation - SAMA PERSIS TASKS.PHP */
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

        /* Responsive - SAMA PERSIS TASKS.PHP */
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

            .profile-header {
                padding: 20px 15px;
                border-radius: 12px;
            }

            .profile-avatar {
                width: 70px;
                height: 70px;
                font-size: 1.7rem;
            }

            .profile-name {
                font-size: 1.2rem;
            }

            .profile-email {
                font-size: 0.9rem;
            }

            .profile-phone {
                font-size: 0.85rem;
            }

            .menu-item {
                padding: 16px 15px;
            }

            .menu-icon {
                font-size: 1.2rem;
                width: 28px;
            }

            .menu-title {
                font-size: 13px;
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
        }

        @media (max-width: 360px) {
            .header-title {
                font-size: 1.4rem;
            }

            .profile-avatar {
                width: 65px;
                height: 65px;
                font-size: 1.5rem;
            }

            .profile-name {
                font-size: 1.1rem;
            }

            .menu-item {
                padding: 14px 12px;
            }

            .menu-item-content {
                gap: 12px;
            }

            .menu-icon {
                font-size: 1.1rem;
                width: 26px;
            }

            .menu-title {
                font-size: 12px;
            }

            .bottom-nav a {
                padding: 6px 10px;
                font-size: 11px;
            }
        }

        @media (min-width: 768px) {
            .container {
                max-width: 768px;
            }

            .content {
                max-width: 768px;
                margin: 0 auto;
                padding: 25px 20px 90px;
            }

            .profile-header {
                max-width: 768px;
                margin: 0 auto 25px;
            }

            .profile-menu {
                max-width: 768px;
                margin: 0 auto;
            }

            .bottom-nav {
                max-width: 768px;
                padding: 8px 15px;
            }

            .bottom-nav a {
                padding: 10px 20px;
                font-size: 14px;
            }

            .bottom-nav a i {
                font-size: 16px;
            }
        }

        @media (min-width: 1024px) {
            body {
                padding-bottom: 20px;
            }

            .bottom-nav {
                bottom: 20px;
                padding: 10px 20px;
            }

            .bottom-nav a {
                padding: 12px 24px;
                font-size: 15px;
            }

            .profile-header {
                padding: 30px;
            }

            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
            }
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .back-btn:hover {
                transform: none;
            }

            .profile-header:hover {
                transform: none;
            }

            .menu-item:hover {
                background: #fff;
                transform: none;
            }

            .bottom-nav a:not(.active):hover {
                color: #6b7280;
                background: transparent;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header - SAMA PERSIS TASKS.PHP -->
        <div class="header">
            <div class="header-content">
                <button class="back-btn" onclick="window.location.href='dashboard.php'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">My Profile</div>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if (!empty($userData['foto'])): ?>
                        <img src="<?= !empty($userData['foto']) ? '../uploads/' . $userData['foto'] : 'https://i.pravatar.cc/100' ?>" alt="Foto Profil">
                    <?php else: ?>
                        <?= strtoupper(substr($userData['name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <h2 class="profile-name">Hi, <?= htmlspecialchars($userData['username']) ?></h2>
                <p class="profile-email"><?= htmlspecialchars($userData['email']) ?></p>
                <p class="profile-phone">📞 <?= htmlspecialchars($userData['phone']) ?></p>
            </div>

            <!-- Profile Menu -->
            <div class="profile-menu">
                <a href="edit_profile.php" class="menu-item">
                    <div class="menu-item-content">
                        <div class="menu-icon"><i class="fas fa-user-edit"></i></div>
                        <div class="menu-text"><div class="menu-title">Edit Profile</div></div>
                    </div>
                    <div class="menu-arrow"><i class="fas fa-chevron-right"></i></div>
                </a>

                <a href="change_password.php" class="menu-item">
                    <div class="menu-item-content">
                        <div class="menu-icon"><i class="fas fa-lock"></i></div>
                        <div class="menu-text"><div class="menu-title">Ganti Password</div></div>
                    </div>
                    <div class="menu-arrow"><i class="fas fa-chevron-right"></i></div>
                </a>

                <a href="logout.php" class="menu-item logout" onclick="return confirm('Yakin ingin logout?')">
                    <div class="menu-item-content">
                        <div class="menu-icon"><i class="fas fa-sign-out-alt"></i></div>
                        <div class="menu-text"><div class="menu-title">Logout</div></div>
                    </div>
                    <div class="menu-arrow"><i class="fas fa-chevron-right"></i></div>
                </a>
            </div>
        </div>
    </div>

    <!-- Bottom Navigation - SAMA PERSIS TASKS.PHP -->
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
        <a href="profile.php" class="active">
            <i class="fa-solid fa-user"></i>
            <span>Profil</span>
        </a>
    </div>

    <script>
        // Handle window resize untuk optimalisasi responsive
        window.addEventListener('resize', function() {
            // Adjust layout for different screen sizes
            if (window.innerWidth <= 480) {
                document.querySelectorAll('.menu-item').forEach(item => {
                    item.style.padding = '14px 12px';
                });
            }
        });

        // Inisialisasi saat halaman dimuat
        window.onload = function() {
            console.log('Profile page loaded successfully');
        };
    </script>
</body>
</html>