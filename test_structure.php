<?php
session_start();

// Cek login - jika belum login, redirect ke login
if (!isset($_SESSION['admin'])) {
  header("Location: ../auth/login.php");
  exit;
}

// KONEKSI KE DATABASE 'COBA'
$host = 'localhost';
$dbname = 'coba';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// FUNGSI SEDERHANA - hanya ambil kolom yang pasti ada
function getAllUsers($pdo) {
    // Cek kolom yang ada
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Bangun query berdasarkan kolom yang ada
    $selectColumns = ['id', 'username', 'email', 'role', 'status', 'created_at'];
    
    // Tambahkan kolom yang mungkin ada
    if (in_array('nama', $columns)) $selectColumns[] = 'nama';
    if (in_array('nama_lengkap', $columns)) $selectColumns[] = 'nama_lengkap';
    if (in_array('phone', $columns)) $selectColumns[] = 'phone';
    if (in_array('no_hp', $columns)) $selectColumns[] = 'no_hp';
    if (in_array('avatar', $columns)) $selectColumns[] = 'avatar';
    if (in_array('foto', $columns)) $selectColumns[] = 'foto';
    
    $query = "SELECT " . implode(', ', $selectColumns) . " FROM users ORDER BY created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ... (sisa kode fungsi CRUD dengan penyesuaian serupa)