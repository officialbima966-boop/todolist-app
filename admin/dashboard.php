<?php
session_start();

// Cek login – kalau belum login, langsung arahkan ke halaman login
if (!isset($_SESSION['admin'])) {
  header("Location: ../auth/login.php");
  exit;
}

require_once "../inc/koneksi.php";

// Cek koneksi database - TAMBAHAN PERBAIKAN
if (!isset($mysqli) || $mysqli === null) {
    die("Koneksi database gagal. Periksa file koneksi.php");
}

// Handle AJAX request untuk check profile update
if (isset($_GET['check_profile_update']) && isset($_GET['last_check'])) {
    header('Content-Type: application/json');
    
    $response = [
        'updated' => false,
        'foto' => null,
        'timestamp' => $_SESSION['profile_timestamp'] ?? 0
    ];
    
    $last_check = intval($_GET['last_check']);
    
    // Jika timestamp session lebih baru dari last_check, berarti ada update
    if (($_SESSION['profile_timestamp'] ?? 0) > $last_check) {
        $response['updated'] = true;
        $response['foto'] = $_SESSION['foto'] ?? '';
        $response['timestamp'] = $_SESSION['profile_timestamp'];
    }
    
    echo json_encode($response);
    exit;
}

// Handle AJAX request untuk refresh stats
if (isset($_GET['refresh_stats'])) {
    header('Content-Type: application/json');
    
    $username = $_SESSION['admin'];
    $stats = getTaskStats($mysqli, $username);
    
    echo json_encode([
        'success' => true,
        'total_tugas' => $stats['total_tugas_diberikan'],
        'tugas_selesai' => $stats['total_tugas_selesai'],
        'tugas_belum_dikerjakan' => $stats['total_tugas_belum_dikerjakan'],
        'tugas_dibuat' => $stats['total_tugas_dibuat']
    ]);
    exit;
}

// Handle AJAX request untuk mendapatkan anggota tim
if (isset($_GET['get_team_members'])) {
    header('Content-Type: application/json');
    
    $teamMembers = getTeamMembers($mysqli, $_SESSION['admin']);
    
    echo json_encode([
        'success' => true,
        'members' => $teamMembers
    ]);
    exit;
}

// Handle AJAX request untuk check team updates
if (isset($_GET['check_team_updates'])) {
    header('Content-Type: application/json');
    
    // Ambil timestamp terakhir update tim dari session
    $last_team_update = $_SESSION['last_team_update'] ?? 0;
    
    // Query untuk cek apakah ada user baru atau perubahan
    $check_query = "SELECT MAX(updated_at) as latest_update FROM users WHERE aktif = 1";
    $stmt = $mysqli->prepare($check_query);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $latest_update = strtotime($row['latest_update'] ?? '1970-01-01');
    
    // Jika ada update baru
    $response = [
        'updated' => $latest_update > $last_team_update,
        'latest_update' => $latest_update
    ];
    
    // Update session dengan timestamp terbaru
    if ($response['updated']) {
        $_SESSION['last_team_update'] = $latest_update;
    }
    
    echo json_encode($response);
    exit;
}

// Handle AJAX request untuk mendapatkan statistik tugas per anggota
if (isset($_GET['get_member_stats']) && isset($_GET['username'])) {
    header('Content-Type: application/json');
    
    $member_username = $_GET['username'];
    $member_stats = getMemberTaskStats($mysqli, $member_username);
    
    echo json_encode([
        'success' => true,
        'stats' => $member_stats
    ]);
    exit;
}

// Handle AJAX request untuk mendapatkan detail lengkap anggota (termasuk tanggal bergabung)
if (isset($_GET['get_member_detail']) && isset($_GET['username'])) {
    header('Content-Type: application/json');
    
    $member_username = $_GET['username'];
    $member_detail = getMemberDetail($mysqli, $member_username);
    
    echo json_encode([
        'success' => true,
        'detail' => $member_detail
    ]);
    exit;
}

// Fungsi untuk mendapatkan detail lengkap anggota
function getMemberDetail($mysqli, $username) {
    $detail = [
        'username' => $username,
        'nama_lengkap' => '',
        'email' => '',
        'jabatan' => '',
        'foto' => '',
        'telepon' => '',
        'tanggal_bergabung' => '',
        'tanggal_bergabung_formatted' => ''
    ];

    try {
        $query = "SELECT username, nama_lengkap, email, jabatan, foto, telepon, 
                         DATE(created_at) as tanggal_bergabung
                  FROM users 
                  WHERE username = ? AND aktif = 1";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $detail['nama_lengkap'] = $row['nama_lengkap'];
            $detail['email'] = $row['email'];
            $detail['jabatan'] = $row['jabatan'] ?? 'Team Member';
            $detail['telepon'] = $row['telepon'] ?? '';
            
            // Foto profil
            if (!empty($row['foto']) && $row['foto'] !== 'default_avatar.jpg') {
                $foto_path = "../uploads/" . $row['foto'];
                $detail['foto'] = file_exists($foto_path) ? $foto_path : "https://i.pravatar.cc/100?u=" . $username;
            } else {
                $detail['foto'] = "https://i.pravatar.cc/100?u=" . $username;
            }
            
            // Tanggal bergabung
            if (!empty($row['tanggal_bergabung'])) {
                $detail['tanggal_bergabung'] = $row['tanggal_bergabung'];
                
                // Format tanggal menjadi bahasa Indonesia
                $tanggal = strtotime($row['tanggal_bergabung']);
                $bulan = [
                    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                ];
                $detail['tanggal_bergabung_formatted'] = date('d', $tanggal) . ' ' . 
                    $bulan[date('n', $tanggal)] . ' ' . date('Y', $tanggal);
            } else {
                $detail['tanggal_bergabung_formatted'] = 'Tidak diketahui';
            }
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error dalam query member detail: " . $e->getMessage());
    }

    return $detail;
}

// Fungsi untuk mengambil statistik tugas per anggota
function getMemberTaskStats($mysqli, $username) {
    $stats = [
        'tugas_dibuat' => 0,
        'tugas_selesai' => 0,
        'tugas_diberikan' => 0,
        'tugas_dikerjakan' => 0
    ];

    try {
        // Tugas yang dibuat oleh anggota
        $query_dibuat = "SELECT COUNT(*) as total FROM tasks WHERE created_by = ?";
        $stmt = $mysqli->prepare($query_dibuat);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['tugas_dibuat'] = $row['total'];
        }
        $stmt->close();

        // Tugas yang selesai (dibuat oleh anggota)
        $query_selesai = "SELECT COUNT(*) as total FROM tasks WHERE created_by = ? AND status = 'completed'";
        $stmt = $mysqli->prepare($query_selesai);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['tugas_selesai'] = $row['total'];
        }
        $stmt->close();

        // Tugas yang diberikan kepada anggota (assigned)
        $query_diberikan = "SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ?";
        $search_pattern = "%" . $username . "%";
        $stmt = $mysqli->prepare($query_diberikan);
        $stmt->bind_param("s", $search_pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['tugas_diberikan'] = $row['total'];
        }
        $stmt->close();

        // Tugas yang dikerjakan dan selesai oleh anggota
        $query_dikerjakan = "SELECT COUNT(*) as total FROM tasks WHERE assigned_users LIKE ? AND status = 'completed'";
        $stmt = $mysqli->prepare($query_dikerjakan);
        $stmt->bind_param("s", $search_pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['tugas_dikerjakan'] = $row['total'];
        }
        $stmt->close();

    } catch (Exception $e) {
        error_log("Error dalam query member stats: " . $e->getMessage());
    }

    return $stats;
}

// Fungsi untuk mengambil statistik tugas
function getTaskStats($mysqli, $username) {
    $stats = [
        'total_tugas_diberikan' => 0,
        'total_tugas_selesai' => 0,
        'total_tugas_belum_dikerjakan' => 0,
        'total_tugas_dibuat' => 0
    ];

    // Query untuk menghitung statistik tugas
    $stats_query = "
        SELECT 
            COUNT(*) as total_tugas,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as tugas_selesai,
            SUM(CASE WHEN status = 'todo' THEN 1 ELSE 0 END) as tugas_belum_dikerjakan,
            SUM(CASE WHEN created_by = ? THEN 1 ELSE 0 END) as tugas_dibuat
        FROM tasks
    ";

    try {
        $stmt = $mysqli->prepare($stats_query);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $row = $result->fetch_assoc()) {
                $stats['total_tugas_diberikan'] = $row['total_tugas'] ?? 0;
                $stats['total_tugas_selesai'] = $row['tugas_selesai'] ?? 0;
                $stats['total_tugas_belum_dikerjakan'] = $row['tugas_belum_dikerjakan'] ?? 0;
                $stats['total_tugas_dibuat'] = $row['tugas_dibuat'] ?? 0;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error dalam query statistik: " . $e->getMessage());
    }

    return $stats;
}

// Fungsi untuk mengambil tugas
function getTasks($mysqli, $username) {
    $tasks = [];
    
    $tasks_query = "
        SELECT id, title, category, progress, status, note, created_at, created_by
        FROM tasks 
        WHERE assigned_users LIKE ? OR created_by = ? OR ? IN (
            SELECT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(assigned_users, ',', n), ',', -1))
            FROM (
                SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            ) numbers
            WHERE CHAR_LENGTH(assigned_users) - CHAR_LENGTH(REPLACE(assigned_users, ',', '')) >= n - 1
        )
        ORDER BY created_at DESC 
        LIMIT 5
    ";

    $search_pattern = "%" . $username . "%";
    
    try {
        $stmt = $mysqli->prepare($tasks_query);
        if ($stmt) {
            $stmt->bind_param("sss", $search_pattern, $username, $username);
            $stmt->execute();
            $tasks_result = $stmt->get_result();

            while ($task = $tasks_result->fetch_assoc()) {
                $tasks[] = $task;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error dalam query tasks: " . $e->getMessage());
        
        // Fallback query jika query utama gagal
        $fallback_query = "
            SELECT id, title, category, progress, status, note, created_at, created_by
            FROM tasks 
            WHERE assigned_users LIKE ? OR created_by = ?
            ORDER BY created_at DESC 
            LIMIT 5
        ";
        
        try {
            $stmt = $mysqli->prepare($fallback_query);
            if ($stmt) {
                $stmt->bind_param("ss", $search_pattern, $username);
                $stmt->execute();
                $tasks_result = $stmt->get_result();

                while ($task = $tasks_result->fetch_assoc()) {
                    $tasks[] = $task;
                }
                $stmt->close();
            }
        } catch (Exception $e2) {
            error_log("Error dalam fallback query tasks: " . $e2->getMessage());
        }
    }

    return $tasks;
}

// Fungsi untuk mengambil data anggota tim dari database (DIPERBAIKI)
function getTeamMembers($mysqli, $currentUsername) {
    $members = [];
    
    // PERBAIKAN: Tambahkan kolom created_at (tanggal bergabung)
    $query = "
        SELECT id, username, nama_lengkap, email, jabatan, foto, telepon, 
               DATE(created_at) as tanggal_bergabung
        FROM users 
        WHERE aktif = 1  -- Hanya user aktif
        ORDER BY nama_lengkap ASC
        LIMIT 10
    ";
    
    try {
        $stmt = $mysqli->prepare($query);
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                // Jika foto kosong, gunakan default avatar dari pravatar
                $foto = !empty($row['foto']) && $row['foto'] !== 'default_avatar.jpg' 
                    ? "../uploads/" . $row['foto'] 
                    : "https://i.pravatar.cc/100?u=" . $row['username'];
                
                // Format tanggal bergabung ke bahasa Indonesia
                $tanggal_bergabung_formatted = 'Tidak diketahui';
                if (!empty($row['tanggal_bergabung'])) {
                    $tanggal = strtotime($row['tanggal_bergabung']);
                    $bulan = [
                        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                    ];
                    $tanggal_bergabung_formatted = date('d', $tanggal) . ' ' . 
                        $bulan[date('n', $tanggal)] . ' ' . date('Y', $tanggal);
                }
                
                $members[] = [
                    'id' => $row['id'],
                    'username' => $row['username'],
                    'nama_lengkap' => $row['nama_lengkap'],
                    'email' => $row['email'],
                    'jabatan' => $row['jabatan'] ?? 'Team Member',
                    'foto' => $foto,
                    'telepon' => $row['telepon'],
                    'tanggal_bergabung' => $row['tanggal_bergabung'] ?? '',
                    'tanggal_bergabung_formatted' => $tanggal_bergabung_formatted
                ];
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error dalam query team members: " . $e->getMessage());
        
        // Fallback ke data statis jika query gagal
        $members = [
            [
                'username' => 'aditya',
                'nama_lengkap' => 'Aditya Devinza',
                'email' => 'adityadevinza87@gmail.com',
                'jabatan' => 'Programmer',
                'foto' => 'https://i.pravatar.cc/100?u=aditya',
                'tanggal_bergabung' => '2024-01-15',
                'tanggal_bergabung_formatted' => '15 Januari 2024'
            ],
            [
                'username' => $currentUsername,
                'nama_lengkap' => $_SESSION['nama_lengkap'] ?? 'User',
                'email' => $_SESSION['email'] ?? '',
                'jabatan' => $_SESSION['jabatan'] ?? 'Team Member',
                'foto' => isset($_SESSION['foto']) && !empty($_SESSION['foto']) && $_SESSION['foto'] !== 'default_avatar.jpg' 
                    ? "../uploads/" . $_SESSION['foto'] 
                    : "https://i.pravatar.cc/100?u=" . $currentUsername,
                'tanggal_bergabung' => date('Y-m-d'),
                'tanggal_bergabung_formatted' => date('d F Y')
            ]
        ];
    }

    return $members;
}

// Siapkan variabel untuk foto profil
$foto_profil = "https://i.pravatar.cc/100"; // Default

// Cek apakah ada foto profil di session
if (isset($_SESSION['foto']) && !empty($_SESSION['foto']) && $_SESSION['foto'] !== 'default_avatar.jpg') {
    $foto_path = "../uploads/" . $_SESSION['foto'];
    if (file_exists($foto_path)) {
        $foto_profil = $foto_path;
    }
}

// Inisialisasi timestamp untuk foto profil
if (!isset($_SESSION['profile_timestamp'])) {
    $_SESSION['profile_timestamp'] = time();
}

// Inisialisasi timestamp untuk update tim
if (!isset($_SESSION['last_team_update'])) {
    $_SESSION['last_team_update'] = time();
}

// Ambil username untuk dijadikan key localStorage
$username = $_SESSION['admin'];

// AMBIL DATA TUGAS DARI DATABASE UNTUK STATISTIK
$stats = getTaskStats($mysqli, $username);
$total_tugas_diberikan = $stats['total_tugas_diberikan'];
$total_tugas_selesai = $stats['total_tugas_selesai'];
$total_tugas_belum_dikerjakan = $stats['total_tugas_belum_dikerjakan'];
$total_tugas_dibuat = $stats['total_tugas_dibuat'];

// AMBIL TUGAS UNTUK DITAMPILKAN DI DASHBOARD
$tasks = getTasks($mysqli, $username);

// AMBIL ANGGOTA TIM DARI DATABASE
$team_members = getTeamMembers($mysqli, $username);

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

    body {
      background: #f8faff;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      padding-bottom: 90px;
      overflow-x: hidden;
    }

    /* ===== HEADER RESPONSIVE ===== */
    header {
      background: linear-gradient(135deg, #0022a8, #0044ff);
      color: #fff;
      padding: 25px 20px 80px;
      border-bottom-left-radius: 30px;
      border-bottom-right-radius: 30px;
      position: relative;
    }

    @media (max-width: 768px) {
      header {
        padding: 20px 15px 60px;
        border-bottom-left-radius: 25px;
        border-bottom-right-radius: 25px;
      }
    }

    @media (max-width: 480px) {
      header {
        padding: 15px 12px 50px;
        border-bottom-left-radius: 20px;
        border-bottom-right-radius: 20px;
      }
    }

    header .profile {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 10px;
    }

    @media (max-width: 480px) {
      header .profile {
        gap: 10px;
        margin-bottom: 8px;
      }
    }

    header .profile img {
      width: 45px; height: 45px;
      border-radius: 50%;
      border: 2px solid #fff;
      object-fit: cover;
      transition: all 0.3s ease;
    }

    @media (max-width: 768px) {
      header .profile img {
        width: 40px; height: 40px;
      }
    }

    @media (max-width: 480px) {
      header .profile img {
        width: 35px; height: 35px;
      }
    }

    header h2 { 
      font-size: 1.2rem; 
      margin-bottom: 3px; 
    }
    
    header p { 
      font-size: 0.85rem; 
      opacity: 0.9; 
    }

    @media (max-width: 768px) {
      header h2 { font-size: 1.1rem; }
      header p { font-size: 0.8rem; }
    }

    @media (max-width: 480px) {
      header h2 { font-size: 1rem; }
      header p { font-size: 0.75rem; }
    }

    .search-box {
      background: #fff;
      border-radius: 15px;
      width: 100%;
      box-shadow: 0 6px 25px rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      padding: 12px 16px;
      border: 1px solid #e8f0ff;
    }

    @media (max-width: 480px) {
      .search-box {
        padding: 10px 14px;
        border-radius: 12px;
      }
    }

    .search-box input {
      border: none;
      outline: none;
      flex: 1;
      padding: 10px;
      font-size: 0.95rem;
      color: #333;
      background: transparent;
    }

    @media (max-width: 480px) {
      .search-box input {
        font-size: 0.85rem;
        padding: 8px;
      }
    }

    /* ===== CONTENT RESPONSIVE ===== */
    .content { 
      padding: 60px 20px 100px; 
      flex: 1; 
    }

    @media (max-width: 768px) {
      .content {
        padding: 40px 15px 100px;
      }
    }

    @media (max-width: 480px) {
      .content {
        padding: 30px 12px 100px;
      }
    }

    .content h3 { 
      font-size: 1rem; 
      margin-bottom: 15px; 
      color: #333; 
    }

    @media (max-width: 480px) {
      .content h3 {
        font-size: 0.9rem;
        margin-bottom: 12px;
      }
    }

    .task-summary {
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      margin-bottom: 25px;
    }

    @media (max-width: 480px) {
      .task-summary {
        padding: 15px;
        margin-bottom: 20px;
      }
    }

    /* ===== STATS GRID RESPONSIVE ===== */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 15px;
      margin-bottom: 25px;
    }

    @media (max-width: 992px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }
    }

    @media (max-width: 480px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-bottom: 20px;
      }
    }

    .stat-card {
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      text-align: center;
      border-top: 4px solid #0038ff;
    }

    @media (max-width: 768px) {
      .stat-card {
        padding: 15px;
      }
    }

    @media (max-width: 480px) {
      .stat-card {
        padding: 12px;
        border-radius: 10px;
        border-top-width: 3px;
      }
    }

    .stat-card:nth-child(2) { border-top-color: #00c853; }
    .stat-card:nth-child(3) { border-top-color: #ff9800; }
    .stat-card:nth-child(4) { border-top-color: #f44336; }

    .stat-card .stat-number {
      font-size: 1.8rem;
      font-weight: 600;
      margin-bottom: 5px;
    }

    @media (max-width: 768px) {
      .stat-card .stat-number {
        font-size: 1.5rem;
      }
    }

    @media (max-width: 480px) {
      .stat-card .stat-number {
        font-size: 1.3rem;
      }
    }

    .stat-card .stat-label {
      font-size: 0.8rem;
      color: #666;
    }

    @media (max-width: 480px) {
      .stat-card .stat-label {
        font-size: 0.7rem;
      }
    }

    /* ===== TASKS SECTION RESPONSIVE ===== */
    .tasks-section {
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      margin-bottom: 25px;
    }

    @media (max-width: 480px) {
      .tasks-section {
        padding: 15px;
        margin-bottom: 20px;
      }
    }

    .task-tabs { 
      display: flex; 
      border-bottom: 1px solid #f0f0f0; 
      margin-bottom: 15px; 
    }

    @media (max-width: 480px) {
      .task-tabs {
        margin-bottom: 12px;
      }
    }

    .task-tab {
      flex: 1;
      text-align: center;
      padding: 10px;
      font-size: 0.85rem;
      color: #666;
      cursor: pointer;
      transition: all 0.3s ease;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    @media (max-width: 480px) {
      .task-tab {
        padding: 8px 5px;
        font-size: 0.75rem;
      }
    }

    .task-tab.active {
      color: #0038ff;
      border-bottom: 2px solid #0038ff;
      font-weight: 600;
    }

    .task-list { 
      display: flex; 
      flex-direction: column; 
      gap: 12px; 
    }

    @media (max-width: 480px) {
      .task-list {
        gap: 10px;
      }
    }

    .task-card {
      background: #f8faff;
      border-radius: 8px;
      padding: 12px;
      border-left: 3px solid #0038ff;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    @media (max-width: 480px) {
      .task-card {
        padding: 10px;
      }
    }

    .task-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .task-card h4 {
      font-size: 0.95rem;
      color: #333;
      margin-bottom: 5px;
      line-height: 1.3;
    }

    @media (max-width: 480px) {
      .task-card h4 {
        font-size: 0.85rem;
      }
    }

    .task-card p {
      font-size: 0.8rem;
      color: #666;
      margin-bottom: 0;
      line-height: 1.4;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    @media (max-width: 480px) {
      .task-card p {
        font-size: 0.75rem;
      }
    }

    .task-meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 8px;
      font-size: 0.7rem;
      color: #888;
      flex-wrap: wrap;
      gap: 5px;
    }

    @media (max-width: 480px) {
      .task-meta {
        font-size: 0.65rem;
        margin-top: 6px;
      }
    }

    .task-progress {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 8px;
    }

    @media (max-width: 480px) {
      .task-progress {
        gap: 6px;
        margin-top: 6px;
      }
    }

    .progress-bar {
      flex: 1;
      height: 6px;
      background: #e8ecf4;
      border-radius: 3px;
      overflow: hidden;
    }

    @media (max-width: 480px) {
      .progress-bar {
        height: 5px;
      }
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #0038ff, #0055ff);
      border-radius: 3px;
      transition: width 0.3s ease;
    }

    .progress-text {
      font-size: 0.7rem;
      color: #666;
      font-weight: 600;
      min-width: 35px;
      text-align: right;
    }

    @media (max-width: 480px) {
      .progress-text {
        font-size: 0.65rem;
        min-width: 30px;
      }
    }

    /* ===== USER CARD RESPONSIVE ===== */
    .user-card {
      background: #fff;
      border-radius: 12px;
      padding: 15px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      margin-bottom: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: 0.2s;
      cursor: pointer;
    }

    @media (max-width: 480px) {
      .user-card {
        padding: 12px;
        margin-bottom: 12px;
      }
    }

    .user-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .user-card .user-info {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
      min-width: 0;
    }

    @media (max-width: 480px) {
      .user-card .user-info {
        gap: 10px;
      }
    }

    .user-card .user-avatar {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #f0f4ff;
      flex-shrink: 0;
    }

    @media (max-width: 480px) {
      .user-card .user-avatar {
        width: 40px;
        height: 40px;
      }
    }

    .user-card .user-details {
      flex: 1;
      min-width: 0;
    }

    .user-card .user-details h4 {
      font-size: 0.95rem;
      margin-bottom: 3px;
      color: #333;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    @media (max-width: 480px) {
      .user-card .user-details h4 {
        font-size: 0.85rem;
      }
    }

    .user-card .user-details p {
      font-size: 0.8rem;
      color: #666;
      margin: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    @media (max-width: 480px) {
      .user-card .user-details p {
        font-size: 0.75rem;
      }
    }

    .btn-detail {
      background: #0038ff;
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 6px 10px;
      font-size: 0.8rem;
      cursor: pointer;
      transition: 0.3s;
      white-space: nowrap;
      flex-shrink: 0;
    }

    @media (max-width: 480px) {
      .btn-detail {
        padding: 5px 8px;
        font-size: 0.75rem;
      }
    }

    .btn-detail:hover {
      background: #0022a8;
    }

    /* ===== NAVIGASI RESPONSIVE ===== */
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
      overflow-x: auto;
      scrollbar-width: none;
      -ms-overflow-style: none;
    }

    .bottom-nav::-webkit-scrollbar {
      display: none;
    }

    @media (max-width: 1200px) {
      .bottom-nav {
        gap: 4px;
        padding: 7px;
      }
    }

    @media (max-width: 992px) {
      .bottom-nav {
        gap: 3px;
        padding: 6px;
        bottom: 15px;
      }
    }

    @media (max-width: 768px) {
      .bottom-nav {
        width: 95%;
        max-width: 95%;
        gap: 2px;
        padding: 5px;
        bottom: 12px;
        border-radius: 40px;
      }
    }

    @media (max-width: 480px) {
      .bottom-nav {
        width: 97%;
        max-width: 97%;
        gap: 1px;
        padding: 4px;
        bottom: 10px;
        border-radius: 35px;
      }
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
      flex-shrink: 0;
    }

    @media (max-width: 1200px) {
      .bottom-nav a {
        padding: 10px 18px;
        font-size: 14px;
        gap: 6px;
      }
    }

    @media (max-width: 992px) {
      .bottom-nav a {
        padding: 9px 16px;
        font-size: 13px;
        gap: 5px;
      }
    }

    @media (max-width: 768px) {
      .bottom-nav a {
        padding: 8px 14px;
        font-size: 12px;
        gap: 4px;
        border-radius: 20px;
      }
    }

    @media (max-width: 480px) {
      .bottom-nav a {
        padding: 7px 12px;
        font-size: 11px;
        gap: 3px;
        border-radius: 18px;
      }
    }

    @media (max-width: 360px) {
      .bottom-nav a {
        padding: 6px 10px;
        font-size: 10px;
      }
    }

    .bottom-nav a i {
      font-size: 17px;
    }

    @media (max-width: 1200px) {
      .bottom-nav a i {
        font-size: 16px;
      }
    }

    @media (max-width: 768px) {
      .bottom-nav a i {
        font-size: 15px;
      }
    }

    @media (max-width: 480px) {
      .bottom-nav a i {
        font-size: 14px;
      }
    }

    .bottom-nav a.active { 
      background: #3b82f6; 
      color: #fff;
    }

    .bottom-nav a:not(.active):hover {
      color: #3b82f6;
      background: #f3f4f6;
    }

    /* ===== OTHER STYLES ===== */
    .profile-loading {
      opacity: 0.5;
      filter: blur(1px);
    }

    .profile-updated {
      animation: pulse 0.5s ease-in-out;
    }

    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.05); }
      100% { transform: scale(1); }
    }

    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #666;
    }

    @media (max-width: 480px) {
      .empty-state {
        padding: 30px 15px;
      }
    }

    .empty-state i {
      font-size: 3rem;
      color: #ddd;
      margin-bottom: 15px;
    }

    @media (max-width: 480px) {
      .empty-state i {
        font-size: 2.5rem;
        margin-bottom: 12px;
      }
    }

    .empty-state p {
      margin-bottom: 10px;
      font-size: 0.9rem;
    }

    @media (max-width: 480px) {
      .empty-state p {
        font-size: 0.8rem;
      }
    }

    /* Refresh button */
    .refresh-btn {
      background: #0038ff;
      color: white;
      border: none;
      border-radius: 8px;
      padding: 8px 15px;
      cursor: pointer;
      font-size: 0.8rem;
      display: flex;
      align-items: center;
      gap: 5px;
      margin-left: 10px;
      white-space: nowrap;
    }

    @media (max-width: 480px) {
      .refresh-btn {
        padding: 6px 12px;
        font-size: 0.75rem;
        margin-left: 5px;
      }
    }

    .refresh-btn:hover {
      background: #0022a8;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
      flex-wrap: wrap;
      gap: 10px;
    }

    @media (max-width: 480px) {
      .section-header {
        margin-bottom: 12px;
        gap: 8px;
      }
    }

    /* ===== MODAL RESPONSIVE ===== */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .modal-content {
      background-color: #fff;
      margin: 10% auto;
      padding: 0;
      width: 90%;
      max-width: 400px;
      border-radius: 15px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.2);
      animation: slideUp 0.3s ease;
      overflow: hidden;
      max-height: 90vh;
      overflow-y: auto;
    }

    @media (max-width: 768px) {
      .modal-content {
        margin: 5% auto;
        width: 95%;
        max-height: 85vh;
      }
    }

    @media (max-width: 480px) {
      .modal-content {
        margin: 2% auto;
        width: 98%;
        max-height: 96vh;
        border-radius: 12px;
      }
    }

    @keyframes slideUp {
      from { transform: translateY(50px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
      background: linear-gradient(135deg, #0022a8, #0044ff);
      color: white;
      padding: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    @media (max-width: 480px) {
      .modal-header {
        padding: 15px;
      }
    }

    .modal-header h3 {
      margin: 0;
      font-size: 1.2rem;
    }

    @media (max-width: 480px) {
      .modal-header h3 {
        font-size: 1.1rem;
      }
    }

    .close-modal {
      background: none;
      border: none;
      color: white;
      font-size: 1.5rem;
      cursor: pointer;
      padding: 0;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: background 0.3s;
      flex-shrink: 0;
    }

    @media (max-width: 480px) {
      .close-modal {
        width: 25px;
        height: 25px;
        font-size: 1.3rem;
      }
    }

    .close-modal:hover {
      background: rgba(255, 255, 255, 0.2);
    }

    .modal-body {
      padding: 25px;
    }

    @media (max-width: 480px) {
      .modal-body {
        padding: 20px;
      }
    }

    .member-detail {
      text-align: center;
    }

    .member-avatar {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      border: 4px solid #0038ff;
      object-fit: cover;
      margin: 0 auto 20px;
      display: block;
    }

    @media (max-width: 480px) {
      .member-avatar {
        width: 80px;
        height: 80px;
        border-width: 3px;
        margin-bottom: 15px;
      }
    }

    .member-name {
      font-size: 1.3rem;
      font-weight: 600;
      margin-bottom: 5px;
      color: #333;
    }

    @media (max-width: 480px) {
      .member-name {
        font-size: 1.1rem;
      }
    }

    .member-role {
      background: #e8ecf4;
      color: #0038ff;
      padding: 5px 15px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 500;
      margin-bottom: 20px;
      display: inline-block;
    }

    @media (max-width: 480px) {
      .member-role {
        font-size: 0.75rem;
        padding: 4px 12px;
        margin-bottom: 15px;
      }
    }

    .member-info {
      text-align: left;
      margin-top: 20px;
    }

    @media (max-width: 480px) {
      .member-info {
        margin-top: 15px;
      }
    }

    .info-item {
      display: flex;
      align-items: center;
      margin-bottom: 15px;
      padding: 12px;
      background: #f8faff;
      border-radius: 10px;
    }

    @media (max-width: 480px) {
      .info-item {
        padding: 10px;
        margin-bottom: 12px;
        border-radius: 8px;
      }
    }

    .info-item i {
      color: #0038ff;
      font-size: 1.1rem;
      margin-right: 12px;
      width: 24px;
      text-align: center;
      flex-shrink: 0;
    }

    @media (max-width: 480px) {
      .info-item i {
        font-size: 1rem;
        margin-right: 10px;
        width: 20px;
      }
    }

    .info-content {
      flex: 1;
      min-width: 0;
    }

    .info-content h4 {
      font-size: 0.85rem;
      color: #666;
      margin-bottom: 3px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    @media (max-width: 480px) {
      .info-content h4 {
        font-size: 0.8rem;
      }
    }

    .info-content p {
      font-size: 0.95rem;
      color: #333;
      font-weight: 500;
      margin: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    @media (max-width: 480px) {
      .info-content p {
        font-size: 0.85rem;
      }
    }

    .member-stats {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px;
      margin-top: 25px;
    }

    @media (max-width: 480px) {
      .member-stats {
        gap: 10px;
        margin-top: 20px;
      }
    }

    .stat-item {
      background: #f8faff;
      border-radius: 10px;
      padding: 15px;
      text-align: center;
    }

    @media (max-width: 480px) {
      .stat-item {
        padding: 12px;
        border-radius: 8px;
      }
    }

    .stat-number {
      font-size: 1.5rem;
      font-weight: 600;
      color: #0038ff;
      margin-bottom: 5px;
    }

    @media (max-width: 480px) {
      .stat-number {
        font-size: 1.3rem;
      }
    }

    .stat-label {
      font-size: 0.75rem;
      color: #666;
    }

    @media (max-width: 480px) {
      .stat-label {
        font-size: 0.7rem;
      }
    }

    .action-buttons {
      display: flex;
      gap: 10px;
      margin-top: 25px;
    }

    @media (max-width: 480px) {
      .action-buttons {
        gap: 8px;
        margin-top: 20px;
      }
    }

    .btn-action {
      flex: 1;
      padding: 12px;
      border-radius: 10px;
      border: none;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.3s;
      white-space: nowrap;
      font-size: 0.9rem;
    }

    @media (max-width: 480px) {
      .btn-action {
        padding: 10px;
        font-size: 0.85rem;
        gap: 5px;
        border-radius: 8px;
      }
    }

    .btn-message {
      background: #0038ff;
      color: white;
    }

    .btn-message:hover {
      background: #0022a8;
    }

    .btn-assign {
      background: #f0f4ff;
      color: #0038ff;
      border: 1px solid #0038ff;
    }

    .btn-assign:hover {
      background: #e0e8ff;
    }

    /* Refresh button for team members */
    .refresh-team-btn {
      background: transparent;
      border: none;
      color: #0038ff;
      cursor: pointer;
      font-size: 1rem;
      margin-left: 10px;
      flex-shrink: 0;
    }

    @media (max-width: 480px) {
      .refresh-team-btn {
        font-size: 0.9rem;
        margin-left: 5px;
      }
    }

    /* Loading indicator */
    .loading-indicator {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 2px solid #f3f3f3;
      border-top: 2px solid #0038ff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* Notification styles */
    .notification {
      position: fixed;
      top: 20px;
      right: 20px;
      background: #4CAF50;
      color: white;
      padding: 15px 20px;
      border-radius: 8px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
      display: flex;
      justify-content: space-between;
      align-items: center;
      z-index: 9999;
      animation: slideIn 0.3s ease;
      min-width: 300px;
      max-width: 400px;
    }

    @media (max-width: 768px) {
      .notification {
        left: 20px;
        right: 20px;
        min-width: auto;
        max-width: none;
      }
    }

    @media (max-width: 480px) {
      .notification {
        left: 10px;
        right: 10px;
        top: 10px;
        padding: 12px 15px;
      }
    }

    .notification-error {
      background: #F44336;
    }

    .notification-info {
      background: #2196F3;
    }

    .notification button {
      background: none;
      border: none;
      color: white;
      font-size: 1.2rem;
      cursor: pointer;
      margin-left: 10px;
      flex-shrink: 0;
    }

    @keyframes slideIn {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }

    @keyframes slideOut {
      from { transform: translateX(0); opacity: 1; }
      to { transform: translateX(100%); opacity: 0; }
    }

    /* Indicator untuk user saat ini */
    .current-user-indicator {
      color: #0038ff;
      font-size: 0.7rem;
      margin-left: 5px;
      font-weight: 500;
      white-space: nowrap;
    }

    @media (max-width: 480px) {
      .current-user-indicator {
        font-size: 0.65rem;
        margin-left: 3px;
      }
    }

    /* New item for join date in modal */
    .info-item.join-date {
      background: #f0f8ff;
    }

    .info-item.join-date i {
      color: #4CAF50;
    }
  </style>
</head>
<body>

  <header>
    <div class="profile">
      <img src="<?= htmlspecialchars($foto_profil) ?>?t=<?= $_SESSION['profile_timestamp'] ?>" alt="profile" id="profile-picture">
      <div>
        <h2>Hi, <?= htmlspecialchars($_SESSION['admin']); ?></h2>
        <p>Selamat datang kembali 👋</p>
      </div>
    </div>

    <div class="search-box">
      🔍 <input type="text" placeholder="Search project" id="searchProject" />
    </div>
  </header>

  <div class="content">
    <!-- Task Summary -->
    <div class="task-summary">
      <h3>Anda Memiliki total <span id="totalTasks"><?= $total_tugas_diberikan ?></span> tugas</h3>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number" id="tugasDiberikan"><?= $total_tugas_diberikan ?></div>
        <div class="stat-label">Tugas diberikan</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" id="tugasSelesai"><?= $total_tugas_selesai ?></div>
        <div class="stat-label">Tugas selesai</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" id="tugasBelumDikerjakan"><?= $total_tugas_belum_dikerjakan ?></div>
        <div class="stat-label">Belum Dikerjakan</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" id="tugasDibuat"><?= $total_tugas_dibuat ?></div>
        <div class="stat-label">Tugas dibuat</div>
      </div>
    </div>

    <!-- Tasks Section -->
    <div class="tasks-section">
      <div class="section-header">
        <h3>Tasks</h3>
        <button class="refresh-btn" onclick="refreshTasks()">
          <i class="fas fa-sync-alt"></i> Refresh
        </button>
      </div>
      <div class="task-tabs">
        <div class="task-tab active" data-filter="all">Hari ini</div>
        <div class="task-tab" data-filter="upcoming">Mendatang</div>
        <div class="task-tab" data-filter="completed">Selesai</div>
      </div>
      <div class="task-list" id="taskList">
        <?php if (empty($tasks)): ?>
          <div class="empty-state">
            <i class="fas fa-tasks"></i>
            <p>Belum ada tugas</p>
            <p>Buat tugas baru di halaman Tasks</p>
          </div>
        <?php else: ?>
          <?php foreach ($tasks as $task): ?>
            <div class="task-card" onclick="openTaskDetail(<?= $task['id'] ?>)">
              <h4><?= htmlspecialchars($task['title']) ?></h4>
              <p><?= htmlspecialchars($task['note'] ?: 'Tidak ada deskripsi') ?></p>
              <div class="task-meta">
                <span>Oleh: <?= htmlspecialchars($task['created_by']) ?></span>
                <span><?= date('d M Y', strtotime($task['created_at'])) ?></span>
              </div>
              <div class="task-progress">
                <div class="progress-bar">
                  <div class="progress-fill" style="width: <?= $task['progress'] ?>%"></div>
                </div>
                <div class="progress-text"><?= $task['progress'] ?>%</div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Team Members Section -->
    <div class="section-header">
      <h3>Anggota Tim <span id="teamCount">(<?= count($team_members) ?> orang)</span></h3>
      <button class="refresh-team-btn" onclick="refreshTeamMembers()" title="Refresh anggota tim">
        <i class="fas fa-sync-alt"></i>
      </button>
    </div>
    <div id="teamMembersList">
      <?php if (empty($team_members)): ?>
        <div class="empty-state">
          <i class="fas fa-user-group"></i>
          <p>Belum ada anggota tim</p>
          <p>Tambahkan anggota baru di halaman Users</p>
        </div>
      <?php else: ?>
        <?php foreach ($team_members as $member): ?>
          <div class="user-card" onclick="showMemberDetail('<?= $member['username'] ?>')">
            <div class="user-info">
              <img src="<?= htmlspecialchars($member['foto']) ?>" alt="<?= htmlspecialchars($member['nama_lengkap']) ?>" class="user-avatar">
              <div class="user-details">
                <h4>
                  <?= htmlspecialchars($member['nama_lengkap']) ?>
                  <?php if ($member['username'] === $_SESSION['admin']): ?>
                    <span class="current-user-indicator">(Anda)</span>
                  <?php endif; ?>
                </h4>
                <p><?= htmlspecialchars($member['jabatan']) ?></p>
              </div>
            </div>
            <button class="btn-detail">Detail</button>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal untuk Detail Anggota Tim -->
  <div id="memberModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Detail Anggota</h3>
        <button class="close-modal" onclick="closeMemberModal()">&times;</button>
      </div>
      <div class="modal-body">
        <div class="member-detail">
          <img id="modalAvatar" src="" alt="Avatar" class="member-avatar">
          <h3 id="modalName" class="member-name"></h3>
          <div id="modalRole" class="member-role"></div>
          
          <div class="member-info">
            <div class="info-item">
              <i class="fas fa-envelope"></i>
              <div class="info-content">
                <h4>Email</h4>
                <p id="modalEmail"></p>
              </div>
            </div>
            <div class="info-item">
              <i class="fas fa-user-tag"></i>
              <div class="info-content">
                <h4>Username</h4>
                <p id="modalUsername"></p>
              </div>
            </div>
            <div class="info-item">
              <i class="fas fa-id-card"></i>
              <div class="info-content">
                <h4>Jabatan</h4>
                <p id="modalPosition"></p>
              </div>
            </div>
            <div class="info-item join-date">
              <i class="fas fa-calendar-check"></i>
              <div class="info-content">
                <h4>Tanggal Bergabung</h4>
                <p id="modalJoinDate"></p>
              </div>
            </div>
          </div>
          
          <div class="member-stats">
            <div class="stat-item">
              <div id="modalTasksCreated" class="stat-number">0</div>
              <div class="stat-label">Tugas Dibuat</div>
            </div>
            <div class="stat-item">
              <div id="modalTasksCompleted" class="stat-number">0</div>
              <div class="stat-label">Tugas Selesai</div>
            </div>
            <div class="stat-item">
              <div id="modalTasksAssigned" class="stat-number">0</div>
              <div class="stat-label">Tugas Diberikan</div>
            </div>
            <div class="stat-item">
              <div id="modalTasksWorkedOn" class="stat-number">0</div>
              <div class="stat-label">Tugas Dikerjakan</div>
            </div>
          </div>
          
          <div class="action-buttons">
            <button class="btn-action btn-message" onclick="sendMessageToMember()">
              <i class="fas fa-comment"></i> Kirim Pesan
            </button>
            <button class="btn-action btn-assign" onclick="assignTaskToMember()">
              <i class="fas fa-tasks"></i> Beri Tugas
            </button>
          </div>
        </div>
      </div>
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
     <a href="tugas_default.php">
      <i class="fa-solid fa-user"></i>
      <span>Tugas default</span>
    </a>
     <a href="profile.php">
      <i class="fa-solid fa-user"></i>
      <span>Profil</span>
    </a>
  </div>

  <script>
    // Key untuk localStorage berdasarkan username
    const username = '<?= $username ?>';
    const currentUsername = '<?= $_SESSION['admin'] ?>';
    const PROFILE_STORAGE_KEY = `bm_garage_profile_${username}`;
    const PROFILE_UPDATE_KEY = `bm_garage_profile_updated_${username}`;
    const TEAM_MEMBERS_KEY = `bm_garage_team_members_${username}`;
    const MEMBER_DETAILS_KEY = `bm_garage_member_details_${username}`;

    // Sistem auto-update foto profil dengan localStorage
    let lastProfileCheck = <?= $_SESSION['profile_timestamp'] ?>;
    const profileImg = document.getElementById('profile-picture');

    // Data anggota tim dari PHP
    let teamMembersData = <?= json_encode($team_members) ?>;
    let currentMemberDetail = null;

    // Variabel untuk kontrol refresh
    let isFirstLoad = true;
    let lastTeamRefresh = 0;
    let autoRefreshInterval = null;

    // Fungsi untuk menampilkan notifikasi
    function showNotification(message, type = 'success') {
      // Hapus notifikasi sebelumnya jika ada
      const existingNotif = document.querySelector('.notification');
      if (existingNotif) existingNotif.remove();
      
      const notification = document.createElement('div');
      notification.className = `notification notification-${type}`;
      notification.innerHTML = `
        <div>${message}</div>
        <button onclick="this.parentElement.remove()">&times;</button>
      `;
      
      document.body.appendChild(notification);
      
      // Auto-hide setelah 5 detik
      setTimeout(() => {
        if (notification.parentElement) {
          notification.style.animation = 'slideOut 0.3s ease';
          setTimeout(() => notification.remove(), 300);
        }
      }, 5000);
    }

    // Fungsi untuk refresh tasks dan stats
    async function refreshTasks() {
      try {
        // Refresh stats
        const statsResponse = await fetch('dashboard.php?refresh_stats=1');
        const statsData = await statsResponse.json();
        
        if (statsData.success) {
          document.getElementById('totalTasks').textContent = statsData.total_tugas;
          document.getElementById('tugasDiberikan').textContent = statsData.total_tugas;
          document.getElementById('tugasSelesai').textContent = statsData.tugas_selesai;
          document.getElementById('tugasBelumDikerjakan').textContent = statsData.tugas_belum_dikerjakan;
          document.getElementById('tugasDibuat').textContent = statsData.tugas_dibuat;
        }

        // Refresh page untuk update task list
        location.reload();
      } catch (error) {
        console.error('Error refreshing tasks:', error);
        // Fallback: refresh page
        location.reload();
      }
    }

    // Fungsi untuk refresh anggota tim (HANYA jika diperlukan)
    async function refreshTeamMembers(forceRefresh = false) {
      // Cek jika refresh dipanggil dalam 10 detik terakhir (untuk mencegah spam)
      const now = Date.now();
      if (!forceRefresh && now - lastTeamRefresh < 10000) {
        console.log('⏸️ Refresh tim ditunda (baru saja di-refresh)');
        return;
      }
      
      lastTeamRefresh = now;
      
      try {
        const refreshBtn = document.querySelector('.refresh-team-btn');
        const originalIcon = refreshBtn.innerHTML;
        refreshBtn.innerHTML = '<div class="loading-indicator"></div>';
        
        const response = await fetch('dashboard.php?get_team_members=1');
        const data = await response.json();
        
        if (data.success) {
          const oldMembersCount = teamMembersData.length;
          const newMembersCount = data.members.length;
          teamMembersData = data.members;
          updateTeamMembersDisplay(data.members);
          saveTeamMembersToLocalStorage(data.members);
          
          // Simpan juga detail masing-masing anggota
          saveAllMemberDetails(data.members);
          
          // Hanya tampilkan notifikasi jika bukan load pertama DAN ada perubahan
          if (!isFirstLoad && (oldMembersCount !== newMembersCount || forceRefresh)) {
            showNotification('Anggota tim diperbarui', 'success');
          }
        }
        
        refreshBtn.innerHTML = originalIcon;
        isFirstLoad = false;
      } catch (error) {
        console.error('Error refreshing team members:', error);
        if (!isFirstLoad) {
          showNotification('Gagal memuat ulang anggota tim', 'error');
        }
      }
    }

    // Fungsi untuk mengambil detail lengkap anggota (termasuk tanggal bergabung)
    async function loadMemberDetail(username) {
      try {
        // Cek dulu di localStorage
        const cachedDetail = getMemberDetailFromLocalStorage(username);
        if (cachedDetail) {
          console.log('✅ Detail anggota dimuat dari localStorage:', username);
          return cachedDetail;
        }
        
        // Jika tidak ada di localStorage, ambil dari server
        const response = await fetch(`dashboard.php?get_member_detail=1&username=${username}`);
        const data = await response.json();
        
        if (data.success && data.detail) {
          // Simpan ke localStorage untuk penggunaan berikutnya
          saveMemberDetailToLocalStorage(username, data.detail);
          return data.detail;
        }
      } catch (error) {
        console.error('Error loading member detail:', error);
      }
      
      // Fallback: cari di data anggota tim yang sudah ada
      const member = teamMembersData.find(m => m.username === username);
      if (member) {
        return {
          username: member.username,
          nama_lengkap: member.nama_lengkap,
          email: member.email,
          jabatan: member.jabatan,
          foto: member.foto,
          telepon: member.telepon,
          tanggal_bergabung_formatted: member.tanggal_bergabung_formatted || 'Tidak diketahui'
        };
      }
      
      return null;
    }

    // Fungsi untuk mengambil statistik tugas anggota
    async function loadMemberStats(username) {
      try {
        const response = await fetch(`dashboard.php?get_member_stats=1&username=${username}`);
        const data = await response.json();
        
        if (data.success) {
          const stats = data.stats;
          document.getElementById('modalTasksCreated').textContent = stats.tugas_dibuat;
          document.getElementById('modalTasksCompleted').textContent = stats.tugas_selesai;
          document.getElementById('modalTasksAssigned').textContent = stats.tugas_diberikan;
          document.getElementById('modalTasksWorkedOn').textContent = stats.tugas_dikerjakan;
        }
      } catch (error) {
        console.error('Error loading member stats:', error);
      }
    }

    // Fungsi untuk menampilkan anggota tim dengan indikator "Anda"
    function updateTeamMembersDisplay(members) {
      const teamMembersList = document.getElementById('teamMembersList');
      const teamCount = document.getElementById('teamCount');
      
      teamCount.textContent = `(${members.length} orang)`;
      
      if (members.length === 0) {
        teamMembersList.innerHTML = `
          <div class="empty-state">
            <i class="fas fa-user-group"></i>
            <p>Belum ada anggota tim</p>
            <p>Tambahkan anggota baru di halaman Users</p>
          </div>
        `;
        return;
      }
      
      let html = '';
      members.forEach((member) => {
        const isCurrentUser = member.username === currentUsername;
        html += `
          <div class="user-card" onclick="showMemberDetail('${member.username}')">
            <div class="user-info">
              <img src="${member.foto}" alt="${member.nama_lengkap}" class="user-avatar">
              <div class="user-details">
                <h4>
                  ${member.nama_lengkap}
                  ${isCurrentUser ? '<span class="current-user-indicator">(Anda)</span>' : ''}
                </h4>
                <p>${member.jabatan}</p>
              </div>
            </div>
            <button class="btn-detail">Detail</button>
          </div>
        `;
      });
      
      teamMembersList.innerHTML = html;
    }

    // Fungsi untuk menyimpan detail anggota ke localStorage
    function saveMemberDetailToLocalStorage(username, detail) {
      try {
        const key = `${MEMBER_DETAILS_KEY}_${username}`;
        const data = {
          detail: detail,
          timestamp: new Date().getTime()
        };
        
        localStorage.setItem(key, JSON.stringify(data));
      } catch (error) {
        console.error('❌ Gagal menyimpan detail anggota:', error);
      }
    }

    // Fungsi untuk mengambil detail anggota dari localStorage
    function getMemberDetailFromLocalStorage(username) {
      try {
        const key = `${MEMBER_DETAILS_KEY}_${username}`;
        const savedData = localStorage.getItem(key);
        
        if (savedData) {
          const data = JSON.parse(savedData);
          const oneDayAgo = new Date().getTime() - (24 * 60 * 60 * 1000);
          
          // Jika data kurang dari 1 hari, gunakan
          if (data.timestamp > oneDayAgo) {
            return data.detail;
          }
        }
      } catch (error) {
        console.error('❌ Gagal memuat detail anggota:', error);
      }
      return null;
    }

    // Fungsi untuk menyimpan semua detail anggota
    function saveAllMemberDetails(members) {
      members.forEach(member => {
        const detail = {
          username: member.username,
          nama_lengkap: member.nama_lengkap,
          email: member.email,
          jabatan: member.jabatan,
          foto: member.foto,
          telepon: member.telepon,
          tanggal_bergabung_formatted: member.tanggal_bergabung_formatted
        };
        saveMemberDetailToLocalStorage(member.username, detail);
      });
    }

    // Fungsi untuk menyimpan data anggota tim ke localStorage
    function saveTeamMembersToLocalStorage(members) {
      try {
        const data = {
          members: members,
          timestamp: new Date().getTime()
        };
        
        localStorage.setItem(TEAM_MEMBERS_KEY, JSON.stringify(data));
        console.log('✅ Data anggota tim disimpan ke localStorage');
      } catch (error) {
        console.error('❌ Gagal menyimpan data anggota tim:', error);
      }
    }

    // Fungsi untuk memuat data anggota tim dari localStorage
    function loadTeamMembersFromLocalStorage() {
      try {
        const savedData = localStorage.getItem(TEAM_MEMBERS_KEY);
        if (savedData) {
          const data = JSON.parse(savedData);
          const oneHourAgo = new Date().getTime() - (60 * 60 * 1000);
          
          // Jika data lebih baru dari 1 jam yang lalu, gunakan
          if (data.timestamp > oneHourAgo) {
            console.log('✅ Data anggota tim dimuat dari localStorage');
            return data.members;
          }
        }
      } catch (error) {
        console.error('❌ Gagal memuat data anggota tim:', error);
      }
      return null;
    }

    // Fungsi untuk convert image ke Base64
    function imageToBase64(imgElement) {
      return new Promise((resolve) => {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        
        canvas.width = imgElement.naturalWidth;
        canvas.height = imgElement.naturalHeight;
        
        ctx.drawImage(imgElement, 0, 0);
        
        try {
          const base64 = canvas.toDataURL('image/jpeg', 0.8);
          resolve(base64);
        } catch (error) {
          console.error('Error converting image to Base64:', error);
          resolve(null);
        }
      });
    }

    // Fungsi untuk menyimpan foto profil ke localStorage sebagai Base64
    async function saveProfileToLocalStorage() {
      try {
        // Tunggu sebentar untuk memastikan gambar sudah dimuat
        await new Promise(resolve => setTimeout(resolve, 500));
        
        const base64Image = await imageToBase64(profileImg);
        
        if (base64Image) {
          const profileData = {
            base64: base64Image,
            timestamp: new Date().getTime(),
            username: username
          };
          
          localStorage.setItem(PROFILE_STORAGE_KEY, JSON.stringify(profileData));
          console.log('✅ Foto profil disimpan permanen di localStorage');
        }
      } catch (error) {
        console.error('❌ Gagal menyimpan foto ke localStorage:', error);
      }
    }

    // Fungsi untuk memuat foto profil dari localStorage
    function loadProfileFromLocalStorage() {
      try {
        const savedProfile = localStorage.getItem(PROFILE_STORAGE_KEY);
        if (savedProfile) {
          const profileData = JSON.parse(savedProfile);
          console.log('✅ Foto profil dimuat dari localStorage');
          return profileData;
        }
      } catch (error) {
        console.error('❌ Gagal memuat dari localStorage:', error);
      }
      return null;
    }

    // Fungsi untuk update foto profil
    function updateProfilePicture(newSrc, timestamp, fromLocalStorage = false) {
      // Tampilkan loading indicator
      profileImg.classList.add('profile-loading');
      
      const newTimestamp = new Date().getTime();
      const finalSrc = fromLocalStorage ? newSrc : `${newSrc}?t=${newTimestamp}`;
      
      // Buat image baru untuk preload
      const newImage = new Image();
      newImage.onload = function() {
        // Setelah gambar berhasil dimuat, update src dan hilangkan loading
        profileImg.src = finalSrc;
        profileImg.classList.remove('profile-loading');
        profileImg.classList.add('profile-updated');
        
        // Update timestamp terakhir
        lastProfileCheck = timestamp;
        
        // Simpan ke localStorage jika bukan dari localStorage
        if (!fromLocalStorage) {
          setTimeout(saveProfileToLocalStorage, 1000);
        }
        
        // Hapus animasi setelah selesai
        setTimeout(() => {
          profileImg.classList.remove('profile-updated');
        }, 500);
      };
      
      newImage.onerror = function() {
        // Jika gagal memuat gambar, gunakan default
        profileImg.src = `https://i.pravatar.cc/100?t=${newTimestamp}`;
        profileImg.classList.remove('profile-loading');
      };
      
      newImage.src = finalSrc;
    }

    // Fungsi untuk memeriksa dan memperbarui foto profil
    async function checkProfileUpdate() {
      try {
        const response = await fetch(`dashboard.php?check_profile_update=1&last_check=${lastProfileCheck}`);
        const data = await response.json();
        
        if (data.updated && data.foto) {
          console.log('🔄 Foto profil diperbarui dari server:', data.foto);
          updateProfilePicture(`../uploads/${data.foto}`, data.timestamp);
        }
      } catch (error) {
        console.error('❌ Error checking profile update:', error);
      }
    }

    // Fungsi untuk cek update dari halaman lain (profile.php)
    function checkForProfileUpdates() {
      const lastUpdate = localStorage.getItem(PROFILE_UPDATE_KEY);
      const savedProfile = loadProfileFromLocalStorage();
      
      if (lastUpdate && savedProfile) {
        const updateTime = parseInt(lastUpdate);
        if (updateTime > lastProfileCheck) {
          console.log('🔄 Foto profil diperbarui dari halaman lain');
          updateProfilePicture(savedProfile.base64, updateTime, true);
          lastProfileCheck = updateTime;
        }
      }
    }

    // Fungsi untuk refresh statistik tugas
    async function refreshTaskStats() {
      try {
        const response = await fetch('dashboard.php?refresh_stats=1');
        const data = await response.json();
        
        if (data.success) {
          document.getElementById('totalTasks').textContent = data.total_tugas;
          document.getElementById('tugasDiberikan').textContent = data.total_tugas;
          document.getElementById('tugasSelesai').textContent = data.tugas_selesai;
          document.getElementById('tugasBelumDikerjakan').textContent = data.tugas_belum_dikerjakan;
          document.getElementById('tugasDibuat').textContent = data.tugas_dibuat;
        }
      } catch (error) {
        console.error('Error refreshing stats:', error);
      }
    }

    // Fungsi untuk cek perubahan anggota tim secara real-time
    async function checkTeamUpdates() {
      try {
        const response = await fetch('dashboard.php?check_team_updates=1');
        const data = await response.json();
        
        if (data.updated) {
          console.log('🔄 Ada perubahan data tim, melakukan refresh...');
          await refreshTeamMembers(true);
        }
      } catch (error) {
        console.error('Error checking team updates:', error);
      }
    }

    // Fungsi untuk membuka detail task
    function openTaskDetail(taskId) {
      window.location.href = `tasks.php?view_task=${taskId}`;
    }

    // Fungsi untuk menampilkan detail anggota dengan statistik real
    async function showMemberDetail(username) {
      // Tampilkan loading di modal
      const modalBody = document.querySelector('.modal-body');
      const originalContent = modalBody.innerHTML;
      modalBody.innerHTML = `
        <div style="text-align: center; padding: 40px;">
          <div class="loading-indicator" style="margin: 0 auto 20px;"></div>
          <p>Memuat data anggota...</p>
        </div>
      `;
      
      // Tampilkan modal
      document.getElementById('memberModal').style.display = 'block';
      document.body.style.overflow = 'hidden';
      
      try {
        // Load detail anggota
        const memberDetail = await loadMemberDetail(username);
        
        if (!memberDetail) {
          showNotification('Data anggota tidak ditemukan', 'error');
          closeMemberModal();
          return;
        }
        
        currentMemberDetail = memberDetail;
        
        // Kembalikan konten modal
        modalBody.innerHTML = originalContent;
        
        // Isi data modal
        document.getElementById('modalAvatar').src = memberDetail.foto;
        document.getElementById('modalName').textContent = memberDetail.nama_lengkap;
        document.getElementById('modalRole').textContent = memberDetail.jabatan;
        document.getElementById('modalEmail').textContent = memberDetail.email;
        document.getElementById('modalUsername').textContent = memberDetail.username;
        document.getElementById('modalPosition').textContent = memberDetail.jabatan;
        document.getElementById('modalJoinDate').textContent = memberDetail.tanggal_bergabung_formatted;
        
        // Tampilkan loading untuk statistik
        document.getElementById('modalTasksCreated').textContent = '...';
        document.getElementById('modalTasksCompleted').textContent = '...';
        document.getElementById('modalTasksAssigned').textContent = '...';
        document.getElementById('modalTasksWorkedOn').textContent = '...';
        
        // Load statistik tugas real dari database
        await loadMemberStats(username);
        
      } catch (error) {
        console.error('Error loading member detail:', error);
        showNotification('Gagal memuat detail anggota', 'error');
        closeMemberModal();
      }
    }

    // Fungsi untuk menutup modal
    function closeMemberModal() {
      document.getElementById('memberModal').style.display = 'none';
      document.body.style.overflow = 'auto';
      currentMemberDetail = null;
    }

    // Fungsi untuk mengirim pesan ke anggota
    function sendMessageToMember() {
      if (!currentMemberDetail) return;
      
      const username = currentMemberDetail.username;
      const name = currentMemberDetail.nama_lengkap;
      
      showNotification(`Membuka chat dengan ${name} (${username})`, 'info');
      // Di sini bisa diarahkan ke halaman chat
    }

    // Fungsi untuk memberikan tugas ke anggota
    function assignTaskToMember() {
      if (!currentMemberDetail) return;
      
      const username = currentMemberDetail.username;
      window.location.href = `tasks.php?assign_to=${username}`;
    }

    // Event Listeners untuk tab tasks
    document.querySelectorAll('.task-tab').forEach(tab => {
      tab.addEventListener('click', function() {
        document.querySelectorAll('.task-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.getAttribute('data-filter');
        filterTasks(filter);
      });
    });

    // Fungsi untuk filter tasks
    function filterTasks(filter) {
      const taskCards = document.querySelectorAll('.task-card');
      
      taskCards.forEach(card => {
        switch(filter) {
          case 'all':
            card.style.display = 'block';
            break;
          case 'completed':
            // Logic untuk filter completed tasks
            card.style.display = 'block'; // Sementara tampilkan semua
            break;
          case 'upcoming':
            // Logic untuk filter upcoming tasks
            card.style.display = 'block'; // Sementara tampilkan semua
            break;
        }
      });
    }

    // Search functionality
    document.getElementById('searchProject').addEventListener('input', function(e) {
      const searchTerm = e.target.value.toLowerCase();
      const taskCards = document.querySelectorAll('.task-card');
      
      taskCards.forEach(card => {
        const title = card.querySelector('h4').textContent.toLowerCase();
        const description = card.querySelector('p').textContent.toLowerCase();
        
        if (title.includes(searchTerm) || description.includes(searchTerm)) {
          card.style.display = 'block';
        } else {
          card.style.display = 'none';
        }
      });
    });

    // Fungsi untuk setup auto-refresh dengan interval yang lebih panjang
    function setupAutoRefresh() {
      // Hapus interval sebelumnya jika ada
      if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
      }
      
      // Set interval untuk refresh stats setiap 60 detik
      autoRefreshInterval = setInterval(refreshTaskStats, 60000);
      
      // Set interval untuk cek update tim setiap 120 detik
      setInterval(checkTeamUpdates, 120000);
    }

    // Saat halaman dimuat
    window.addEventListener('load', function() {
      // 1. Load team members from localStorage first
      const savedTeamMembers = loadTeamMembersFromLocalStorage();
      if (savedTeamMembers && savedTeamMembers.length > 0) {
        console.log('🎯 Menggunakan data anggota tim dari localStorage');
        teamMembersData = savedTeamMembers;
        updateTeamMembersDisplay(savedTeamMembers);
      } else {
        // Tampilkan data dari PHP
        updateTeamMembersDisplay(teamMembersData);
      }
      
      // 2. Load profile picture
      checkForProfileUpdates();
      
      const savedProfile = loadProfileFromLocalStorage();
      if (savedProfile && savedProfile.base64) {
        console.log('🎯 Menggunakan foto profil dari localStorage');
        updateProfilePicture(savedProfile.base64, savedProfile.timestamp, true);
      } else {
        // Jika tidak ada di localStorage, gunakan dari server
        console.log('🌐 Memuat foto profil dari server');
        const currentProfileSrc = '<?= htmlspecialchars($foto_profil) ?>?t=<?= $_SESSION['profile_timestamp'] ?>';
        updateProfilePicture(currentProfileSrc, <?= $_SESSION['profile_timestamp'] ?>);
      }
      
      // 3. Setup auto-refresh dengan interval yang lebih lama
      setupAutoRefresh();
      
      // 4. Simpan foto saat ini ke localStorage
      setTimeout(saveProfileToLocalStorage, 3000);
      
      // 5. Simpan detail semua anggota ke localStorage
      saveAllMemberDetails(teamMembersData);
      
      // 6. Refresh team members setelah 5 detik (hanya sekali)
      setTimeout(() => refreshTeamMembers(), 5000);
    });

    // Periksa pembaruan setiap 30 detik
    setInterval(() => {
      checkProfileUpdate();
      checkForProfileUpdates();
    }, 30000);

    // Juga periksa saat halaman difokuskan kembali
    document.addEventListener('visibilitychange', function() {
      if (!document.hidden) {
        checkProfileUpdate();
        checkForProfileUpdates();
        refreshTaskStats();
      }
    });

    // Simpan juga sebelum browser/tab ditutup
    window.addEventListener('beforeunload', function() {
      saveProfileToLocalStorage();
      saveTeamMembersToLocalStorage(teamMembersData);
      saveAllMemberDetails(teamMembersData);
    });

    // Listen untuk storage events (perubahan localStorage dari tab lain)
    window.addEventListener('storage', function(e) {
      if (e.key === PROFILE_UPDATE_KEY || e.key === PROFILE_STORAGE_KEY) {
        console.log('📢 Storage event terdeteksi, memperbarui foto profil');
        setTimeout(checkForProfileUpdates, 1000);
      }
      
      if (e.key === TEAM_MEMBERS_KEY) {
        console.log('📢 Storage event terdeteksi, memperbarui anggota tim');
        setTimeout(() => {
          const savedTeamMembers = loadTeamMembersFromLocalStorage();
          if (savedTeamMembers) {
            teamMembersData = savedTeamMembers;
            updateTeamMembersDisplay(savedTeamMembers);
          }
        }, 1000);
      }
      
      // Cek jika ada update detail anggota
      if (e.key && e.key.startsWith(MEMBER_DETAILS_KEY)) {
        console.log('📢 Storage event terdeteksi, memperbarui detail anggota');
      }
    });

    // Auto-refresh ketika kembali dari halaman tasks
    if (performance.navigation.type === 2 || performance.getEntriesByType("navigation")[0]?.type === 'back_forward') {
      setTimeout(refreshTasks, 1000);
    }

    // Tutup modal ketika klik di luar konten modal
    window.addEventListener('click', function(event) {
      const modal = document.getElementById('memberModal');
      if (event.target === modal) {
        closeMemberModal();
      }
    });

    // Tutup modal dengan tombol ESC
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        closeMemberModal();
      }
    });

    // Handle responsive behavior for very small screens
    function handleResponsiveLayout() {
      const screenWidth = window.innerWidth;
      
      // Adjust navigation for very small screens
      if (screenWidth < 360) {
        const navLinks = document.querySelectorAll('.bottom-nav a span');
        navLinks.forEach(span => {
          if (span.textContent === 'Tugas default') {
            span.textContent = 'Tugas';
          } else if (span.textContent === 'Profil') {
            span.textContent = 'Profil';
          }
        });
      }
    }

    // Run on load and resize
    window.addEventListener('load', handleResponsiveLayout);
    window.addEventListener('resize', handleResponsiveLayout);
  </script>
</body>
</html>