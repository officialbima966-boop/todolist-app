<?php
session_start();
if (!isset($_SESSION['admin'])) {
  header("Location: login.php");
  exit;
}

require_once __DIR__ . '/../inc/koneksi.php';

// Ambil username dari session
$username = $_SESSION['admin'];

// Ambil username dari tabel admin
$stmt_admin = $mysqli->prepare("SELECT username FROM admin WHERE username = ?");
if (!$stmt_admin) {
  die("Error prepare admin: " . $mysqli->error);
}
$stmt_admin->bind_param("s", $username);
$stmt_admin->execute();
$result_admin = $stmt_admin->get_result();
$admin_data = $result_admin->fetch_assoc();
$stmt_admin->close();

// Ambil data dari tabel users (untuk profil lengkap)
$stmt = $mysqli->prepare("SELECT nama, email, no_hp, foto FROM users WHERE username = ?");
if (!$stmt) {
  die("Error prepare users: " . $mysqli->error);
}
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Jika user tidak ditemukan (data hilang/rusak)
if (!$user) {
  $user = [
    'nama' => $_SESSION['admin'],
    'email' => 'Email belum terdaftar',
    'no_hp' => '-',
    'foto' => ''
  ];
}

// Tambahkan username dari tabel adminme ke array $user
if ($admin_data) {
  $user['username'] = $admin_data['username'];
} else {
  $user['username'] = $_SESSION['admin']; // fallback ke session
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Profile | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
      padding: 60px 15px 40px;
      border-bottom-left-radius: 25px;
      border-bottom-right-radius: 25px;
      position: relative;
      overflow: hidden;
      text-align: center;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      min-height: 180px;
      width: 100%;
    }

    .back-button {
      position: absolute;
      left: 15px;
      top: 30px;
      background: rgba(255,255,255,0.2);
      border: none;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      cursor: pointer;
      transition: all 0.3s ease;
      z-index: 10;
    }

    .back-button:hover {
      background: rgba(255,255,255,0.3);
      transform: scale(1.1);
    }

    .header-title {
      font-size: 1.8rem;
      font-weight: 700;
      text-align: center;
      letter-spacing: 0.5px;
      padding: 0 10px;
      word-break: break-word;
      margin-top: 10px;
    }

    .content {
      padding: 20px 15px 100px;
      flex: 1;
      width: 100%;
    }

    .profile-header {
      background: #fff;
      border-radius: 20px;
      padding: 25px 20px;
      text-align: center;
      box-shadow: 0 4px 15px rgba(0,0,0,0.08);
      margin-bottom: 20px;
      width: 100%;
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
      color: #0022a8;
      font-weight: 700;
      margin-bottom: 5px;
      word-break: break-word;
    }

    .profile-email {
      font-size: 0.95rem;
      color: #666;
      margin-bottom: 8px;
      word-break: break-word;
    }

    .profile-phone {
      color: #555;
      font-size: 0.9rem;
      margin-top: 5px;
      word-break: break-word;
    }

    .profile-menu {
      background: #fff;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 4px 15px rgba(0,0,0,0.08);
      width: 100%;
    }

    .menu-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px 20px;
      border-bottom: 1px solid #f0f4ff;
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
    }

    .menu-item-content {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .menu-icon {
      color: #2455ff;
      font-size: 1.3rem;
      width: 30px;
      text-align: center;
    }

    .menu-text {
      flex: 1;
      min-width: 0;
    }

    .menu-title {
      font-size: 1rem;
      color: #333;
      font-weight: 600;
      margin-bottom: 2px;
      word-break: break-word;
    }

    .menu-arrow {
      color: #999;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .menu-item.logout .menu-icon {
      color: #ff3b30;
    }

    .menu-item.logout .menu-title {
      color: #ff3b30;
    }

    /* NAVIGASI SAMA SEPERTI DI TUGAS_DEFAULT.PHP */
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

    /* Media Queries for Responsiveness */
    @media (max-width: 480px) {
      html {
        font-size: 14px;
      }
      
      header {
        padding: 50px 12px 30px;
        min-height: 160px;
      }
      
      .header-title {
        font-size: 1.6rem;
      }
      
      .back-button {
        left: 12px;
        top: 25px;
        width: 38px;
        height: 38px;
      }
      
      .content {
        padding: 15px 12px 70px;
      }
      
      .profile-header {
        padding: 20px 15px;
        border-radius: 15px;
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
        font-size: 0.95rem;
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
    }

    @media (max-width: 360px) {
      html {
        font-size: 13px;
      }
      
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
        font-size: 0.9rem;
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
      
      .bottom-nav {
        bottom: 20px;
        padding: 10px 20px;
      }
      
      .bottom-nav a {
        padding: 12px 24px;
        font-size: 0.9rem;
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
      .back-button:hover {
        transform: none;
      }
      
      .menu-item:hover {
        background: #fff;
      }
      
      .bottom-nav a:not(.active):hover {
        color: #9ca3af;
        background: transparent;
      }
    }
  </style>
</head>
<body>

<header>
  <button class="back-button" onclick="goToDashboard()">
    <i class="fas fa-arrow-left"></i>
  </button>
  <div class="header-title">My Profile</div>
</header>

<div class="content">
  <div class="profile-header">
    <div class="profile-avatar">
      <?php if (!empty($user['foto'])): ?>
        <img src="<?= !empty($user['foto']) ? '../uploads/' . $user['foto'] : 'https://i.pravatar.cc/100' ?>" alt="Foto Profil">
      <?php else: ?>
        <?= strtoupper(substr($user['nama'], 0, 1)) ?>
      <?php endif; ?>
    </div>
    <h2 class="profile-name">Hi, <?= htmlspecialchars($user['username']) ?></h2>
    <p class="profile-email"><?= htmlspecialchars($user['email']) ?></p>
    <p class="profile-phone">📞 <?= htmlspecialchars($user['no_hp']) ?></p>
  </div>

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

    <!-- PERBAIKAN: Link logout mengarah ke logout.php dengan konfirmasi -->
    <a href="logout.php" class="menu-item logout" onclick="return confirm('Yakin ingin logout?')">
      <div class="menu-item-content">
        <div class="menu-icon"><i class="fas fa-sign-out-alt"></i></div>
        <div class="menu-text"><div class="menu-title">Logout</div></div>
      </div>
      <div class="menu-arrow"><i class="fas fa-chevron-right"></i></div>
    </a>
  </div>
</div>

<!-- NAVIGASI SAMA SEPERTI TUGAS_DEFAULT - DENGAN ACTIVE PADA PROFILE -->
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
  <a href="tugas_default.php">
    <i class="fa-solid fa-clipboard-list"></i>
    <span>Tugas Default</span>
  </a>
  <a href="profile.php" class="active">
    <i class="fa-solid fa-user"></i>
    <span>Profil</span>
  </a>
</div>

<script>
  function goToDashboard() {
    window.location.href = 'dashboard.php';
  }

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