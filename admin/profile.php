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
  <title>Profile | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * {margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
    body {background:#f8faff;min-height:100vh;display:flex;flex-direction:column;padding-bottom:90px;}
    header {background:linear-gradient(135deg,#0022a8,#0044ff);color:#fff;padding:25px 20px 40px;border-bottom-left-radius:30px;border-bottom-right-radius:30px;position:relative;text-align:center;}
    .back-button {position:absolute;left:20px;top:25px;background:rgba(255,255,255,0.2);border:none;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;color:white;cursor:pointer;transition:all 0.3s ease;}
    .back-button:hover {background:rgba(255,255,255,0.3);transform:scale(1.1);}
    .header-title {font-size:1.4rem;font-weight:700;margin-top:10px;}
    .content {padding:30px 20px 100px;flex:1;}
    .profile-header {background:#fff;border-radius:20px;padding:25px;text-align:center;box-shadow:0 4px 15px rgba(0,0,0,0.08);margin-bottom:20px;}
    .profile-avatar {width:80px;height:80px;border-radius:50%;margin:0 auto 15px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:white;font-size:2rem;font-weight:600;overflow:hidden;}
    .profile-avatar img {width:100%;height:100%;object-fit:cover;border-radius:50%;}
    .profile-name {font-size:1.3rem;color:#0022a8;font-weight:700;margin-bottom:5px;}
    .profile-email {font-size:0.95rem;color:#666;margin-bottom:0;}
    .profile-menu {background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,0.08);}
    .menu-item {display:flex;align-items:center;justify-content:space-between;padding:20px 25px;border-bottom:1px solid #f0f4ff;cursor:pointer;transition:all 0.3s ease;text-decoration:none;color:inherit;}
    .menu-item:last-child {border-bottom:none;}
    .menu-item:hover {background:#f8faff;}
    .menu-item-content {display:flex;align-items:center;gap:15px;}
    .menu-icon {color:#2455ff;font-size:1.3rem;width:30px;text-align:center;}
    .menu-text {flex:1;}
    .menu-title {font-size:1rem;color:#333;font-weight:600;margin-bottom:2px;}
    .menu-arrow {color:#999;font-size:1rem;}
    .menu-item.logout .menu-icon {color:#ff3b30;}
    .menu-item.logout .menu-title {color:#ff3b30;}
    
    /* NAVIGASI SAMA SEPERTI DI TUGAS_DEFAULT.PHP */
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
      background: #2455ff; 
      color: #fff;
    }

    .bottom-nav a:not(.active):hover {
      color: #2455ff;
      background: #f3f4f6;
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
    <p style="color:#555;font-size:0.9rem;margin-top:5px;">📞 <?= htmlspecialchars($user['no_hp']) ?></p>
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
    <i class="fa-solid fa-tasks"></i>
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
</script>

</body>
</html>