<?php
session_start();

// Cek login - jika belum login sebagai admin atau user, redirect ke login
if (!isset($_SESSION['admin']) && !isset($_SESSION['user'])) {
  header("Location: ../auth/login.php");
  exit;
}

// Tentukan username saat ini (bisa admin atau user)
$currentUsername = isset($_SESSION['admin']) ? $_SESSION['admin'] : $_SESSION['user'];

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

// FUNGSI SEDERHANA - ambil semua data user
function getAllUsers($pdo) {
    try {
        // PERBAIKAN: gunakan 'users' bukan 'user'
        $stmt = $pdo->query("SELECT * FROM users ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Jika error, coba dengan kolom spesifik
        try {
            // PERBAIKAN: gunakan 'users' bukan 'user'
            $stmt = $pdo->query("SELECT id, username, nama_lengkap, email, role, status, phone FROM users ORDER BY id DESC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            return [];
        }
    }
}

// Fungsi untuk menambahkan user baru - SEDERHANA
function addUser($pdo, $data) {
    try {
        // Generate username dari nama
        $username = strtolower(str_replace(' ', '.', $data['firstName'])) . '.' . strtolower($data['lastName']);
        
        // PERBAIKAN: gunakan 'users' bukan 'user'
        // Cek apakah email sudah ada
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $checkStmt->execute([':email' => $data['email']]);
        
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Email sudah terdaftar'];
        }
        
        // Coba dengan berbagai kemungkinan kolom
        $namaLengkap = $data['firstName'] . ' ' . $data['lastName'];
        
        // PERBAIKAN: gunakan 'users' bukan 'user'
        // Query sederhana dengan kolom yang sesuai dengan database
        $stmt = $pdo->prepare("
            INSERT INTO users 
            (username, nama_lengkap, email, password, role, status, phone) 
            VALUES 
            (:username, :nama_lengkap, :email, :password, :role, :status, :phone)
        ");
        
        $result = $stmt->execute([
            ':username' => $username,
            ':nama_lengkap' => $namaLengkap,
            ':email' => $data['email'],
            ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':role' => $data['role'] ?? 'user',
            ':status' => 'active',
            ':phone' => $data['phone']
        ]);
        
        return ['success' => $result, 'message' => $result ? 'User berhasil ditambahkan!' : 'Gagal menambahkan user'];
    } catch (PDOException $e) {
        // Coba query alternatif jika gagal
        try {
            // PERBAIKAN: gunakan 'users' bukan 'user'
            $stmt = $pdo->prepare("
                INSERT INTO users 
                (username, email, password, role, status) 
                VALUES 
                (:username, :email, :password, :role, :status)
            ");
            
            $result = $stmt->execute([
                ':username' => $username,
                ':email' => $data['email'],
                ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
                ':role' => $data['role'] ?? 'user',
                ':status' => 'active'
            ]);
            
            return ['success' => $result, 'message' => $result ? 'User berhasil ditambahkan!' : 'Gagal menambahkan user'];
        } catch (PDOException $e2) {
            return ['success' => false, 'message' => 'Error: ' . $e2->getMessage()];
        }
    }
}

// Fungsi untuk update user - SEDERHANA
function updateUser($pdo, $id, $data) {
    try {
        // PERBAIKAN: gunakan 'users' bukan 'user'
        // Cek apakah email sudah digunakan oleh user lain
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
        $checkStmt->execute([
            ':email' => $data['email'],
            ':id' => $id
        ]);
        
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Email sudah digunakan oleh user lain'];
        }
        
        $namaLengkap = $data['firstName'] . ' ' . $data['lastName'];
        
        // PERBAIKAN: gunakan 'users' bukan 'user'
        // Update dengan kolom yang sesuai dengan database
        $stmt = $pdo->prepare("
            UPDATE users SET 
            nama_lengkap = :nama_lengkap,
            email = :email,
            phone = :phone,
            role = :role,
            status = :status
            WHERE id = :id
        ");
        
        $result = $stmt->execute([
            ':id' => $id,
            ':nama_lengkap' => $namaLengkap,
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':role' => $data['role'],
            ':status' => $data['status']
        ]);
        
        return ['success' => $result, 'message' => $result ? 'User berhasil diperbarui!' : 'Gagal memperbarui user'];
    } catch (PDOException $e) {
        // Coba update tanpa kolom yang mungkin tidak ada
        try {
            // PERBAIKAN: gunakan 'users' bukan 'user'
            $stmt = $pdo->prepare("
                UPDATE users SET 
                email = :email,
                role = :role,
                status = :status
                WHERE id = :id
            ");
            
            $result = $stmt->execute([
                ':id' => $id,
                ':email' => $data['email'],
                ':role' => $data['role'],
                ':status' => $data['status']
            ]);
            
            return ['success' => $result, 'message' => $result ? 'User berhasil diperbarui!' : 'Gagal memperbarui user'];
        } catch (PDOException $e2) {
            return ['success' => false, 'message' => 'Error: ' . $e2->getMessage()];
        }
    }
}

// Fungsi untuk menghapus user
function deleteUser($pdo, $id) {
    try {
        // PERBAIKAN: gunakan 'users' bukan 'user'
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $result = $stmt->execute([':id' => $id]);
        return ['success' => $result, 'message' => $result ? 'User berhasil dihapus!' : 'Gagal menghapus user'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// Handle form submissions via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    switch ($action) {
        case 'add':
            $result = addUser($pdo, [
                'firstName' => $_POST['firstName'],
                'lastName' => $_POST['lastName'],
                'email' => $_POST['email'],
                'phone' => $_POST['phone'],
                'password' => $_POST['password'],
                'role' => $_POST['role'] ?? 'user'
            ]);
            echo json_encode($result);
            exit;
            
        case 'update':
            $result = updateUser($pdo, $_POST['id'], [
                'firstName' => $_POST['firstName'],
                'lastName' => $_POST['lastName'],
                'email' => $_POST['email'],
                'phone' => $_POST['phone'],
                'role' => $_POST['role'],
                'status' => $_POST['status']
            ]);
            echo json_encode($result);
            exit;
            
        case 'delete':
            $result = deleteUser($pdo, $_POST['id']);
            echo json_encode($result);
            exit;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak valid']);
            exit;
    }
}

// Endpoint untuk load data via AJAX
if (isset($_GET['load']) && $_GET['load'] == 1) {
    $users = getAllUsers($pdo);
    echo json_encode($users);
    exit;
}

// Get all users initially untuk tampilan pertama
$users = getAllUsers($pdo);
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* Reset dan Base Styles - SAMA DENGAN TASKS.PHP */
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
      background: linear-gradient(180deg, #f0f4ff 0%, #e8f0ff 100%);
      min-height: 100vh;
      padding-bottom: 100px;
      overflow-x: hidden;
    }

    .container {
      max-width: 480px;
      margin: 0 auto;
      padding: 0;
    }

    /* Header - SAMA DENGAN TASKS.PHP */
    header {
      background: linear-gradient(135deg, #4f46e5, #3b82f6);
      color: #fff;
      padding: 20px 15px 25px 15px;
      position: relative;
      border-radius: 0 0 20px 20px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.15);
      width: 100%;
    }

    .header-content {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 18px;
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
      font-weight: 700;
      letter-spacing: 0.3px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Content - SAMA DENGAN TASKS.PHP */
    .content {
      padding: 18px 15px;
    }

    /* Search Box - SAMA DENGAN TASKS.PHP */
    .search-box {
      background: white;
      border-radius: 14px;
      padding: 12px 16px;
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 15px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      border: none;
    }

    .search-box i {
      color: #9ca3af;
      font-size: 16px;
      flex-shrink: 0;
    }

    .search-box input {
      border: none;
      outline: none;
      flex: 1;
      font-size: 14px;
      color: #333;
      width: 100%;
      background: transparent;
    }

    .search-box input::placeholder {
      color: #c4c8d0;
    }

    /* User Card - SAMA DENGAN TASK CARD */
    .user-card {
      background: white;
      border-radius: 16px;
      padding: 16px;
      margin-bottom: 14px;
      box-shadow: 0 3px 12px rgba(0,0,0,0.06);
      border: none;
      cursor: pointer;
      transition: all 0.3s;
      position: relative;
      z-index: 1;
    }

    .user-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(0,0,0,0.1);
    }

    .user-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 12px;
      gap: 10px;
    }

    .user-avatar {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1rem;
      font-weight: 600;
      border: 3px solid #fff;
      box-shadow: 0 3px 8px rgba(0,0,0,0.08);
      flex-shrink: 0;
    }

    .user-avatar.avatar-2 {
      background: linear-gradient(135deg, #f093fb, #f5576c);
    }

    .user-avatar.avatar-3 {
      background: linear-gradient(135deg, #4facfe, #00f2fe);
    }

    .user-avatar.avatar-4 {
      background: linear-gradient(135deg, #43e97b, #38f9d7);
    }

    .user-avatar.avatar-5 {
      background: linear-gradient(135deg, #fa709a, #fee140);
    }

    .user-info {
      flex: 1;
      min-width: 0;
    }

    .user-name {
      font-weight: 700;
      color: #1f2937;
      margin-bottom: 6px;
      font-size: 15px;
      line-height: 1.4;
      word-break: break-word;
    }

    .user-role {
      font-size: 11px;
      font-weight: 700;
      color: #666;
      background: #f8faff;
      padding: 4px 10px;
      border-radius: 8px;
      display: inline-block;
      border: 1px solid #e8f0ff;
      margin-bottom: 6px;
      text-transform: capitalize;
      letter-spacing: 0.3px;
    }

    .user-status {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 5px;
    }

    .status-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #00c853;
      flex-shrink: 0;
    }

    .status-text {
      font-size: 0.75rem;
      color: #666;
    }

    .user-menu {
      background: none;
      border: none;
      color: #9ca3af;
      font-size: 1.1rem;
      cursor: pointer;
      padding: 4px;
      position: relative;
      transition: all 0.2s;
      flex-shrink: 0;
    }

    .user-menu:hover {
      color: #4169E1;
    }

    /* Dropdown Menu - SAMA DENGAN TASKS.PHP */
    .user-dropdown-menu {
      position: absolute;
      top: 100%;
      right: 0;
      background: white;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: none;
      z-index: 9999999;
      min-width: 140px;
      overflow: hidden;
    }

    .user-dropdown-menu.active {
      display: block;
    }

    .user-dropdown-item {
      padding: 10px 14px;
      cursor: pointer;
      transition: background 0.2s;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.85rem;
    }

    .user-dropdown-item:hover {
      background: #f3f4f6;
    }

    .user-dropdown-item i {
      width: 18px;
    }

    .user-dropdown-item.delete {
      color: #ef4444;
    }

    /* User Details Section */
    .user-details {
      padding-top: 10px;
      border-top: 1px solid #f0f3f8;
      margin-top: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
    }

    .user-detail-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.75rem;
      color: #6b7280;
      flex-shrink: 0;
      margin-bottom: 4px;
    }

    .user-detail-item i {
      font-size: 0.85rem;
    }

    /* Floating Add Button - SAMA DENGAN TASKS.PHP */
    .floating-add-btn {
      position: fixed;
      right: 15px;
      bottom: 80px;
      background: linear-gradient(135deg, #4169E1, #5b7ff5);
      color: white;
      border: none;
      border-radius: 50%;
      width: 55px;
      height: 55px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      cursor: pointer;
      box-shadow: 0 6px 20px rgba(65, 105, 225, 0.4);
      z-index: 99;
      transition: all 0.3s ease;
    }

    .floating-add-btn:hover {
      transform: scale(1.08) rotate(90deg);
      box-shadow: 0 8px 25px rgba(65, 105, 225, 0.5);
    }

    .floating-add-btn:active {
      transform: scale(0.95) rotate(90deg);
    }

    /* Modal styles - DIADAPTASI DARI TASKS.PHP */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.3);
      z-index: 1000;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideUp {
      from { transform: translateY(100%); }
      to { transform: translateY(0); }
    }

    .modal-content {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      background-color: white;
      border-top-left-radius: 25px;
      border-top-right-radius: 25px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 -5px 30px rgba(0, 0, 0, 0.15);
      animation: slideUp 0.3s ease;
    }

    .modal-header {
      background: linear-gradient(135deg, #4169E1, #1e3a8a);
      color: white;
      padding: 20px 15px;
      border-top-left-radius: 25px;
      border-top-right-radius: 25px;
      position: relative;
      text-align: center;
    }

    .modal-back-btn {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(255, 255, 255, 0.2);
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
      flex-shrink: 0;
    }

    .modal-back-btn:hover {
      background: rgba(255, 255, 255, 0.3);
    }

    .modal-header h3 {
      color: white;
      font-size: 1.2rem;
      margin: 0;
      font-weight: 600;
      padding: 0 40px;
    }

    .modal-body {
      padding: 20px 15px;
    }

    .form-title {
      font-size: 1rem;
      font-weight: 700;
      color: #333;
      margin-bottom: 20px;
    }

    .form-group {
      margin-bottom: 15px;
    }

    .form-group label {
      display: block;
      margin-bottom: 6px;
      color: #333;
      font-weight: 500;
      font-size: 0.85rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid #ddd;
      border-radius: 8px;
      font-size: 0.9rem;
      background: white;
      transition: all 0.3s;
      font-family: 'Poppins', sans-serif;
    }

    .form-group input::placeholder {
      color: #999;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: #4169E1;
      box-shadow: 0 0 0 3px rgba(65, 105, 225, 0.1);
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .phone-input {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .phone-prefix {
      background: #f0f4ff;
      padding: 12px 10px;
      border-radius: 10px;
      color: #2455ff;
      font-weight: 600;
      min-width: 60px;
      text-align: center;
      border: 2px solid #e8f0ff;
      font-size: 0.95rem;
      flex-shrink: 0;
    }

    .password-toggle {
      position: relative;
    }

    .password-toggle input {
      padding-right: 45px;
    }

    .toggle-icon {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #666;
      cursor: pointer;
      background: none;
      border: none;
      font-size: 0.95rem;
      padding: 5px;
    }

    .form-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 25px;
      padding: 15px;
      background: white;
    }

    .btn {
      padding: 14px 25px;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      font-size: 0.95rem;
      font-family: 'Poppins', sans-serif;
      width: 100%;
    }

    .btn-cancel {
      background: white;
      color: #333;
      border: 1px solid #ddd;
    }

    .btn-cancel:hover {
      background: #f5f5f5;
    }

    .btn-save {
      background: #4169E1;
      color: white;
      border: none;
    }

    .btn-save:hover {
      background: #1e3a8a;
      box-shadow: 0 4px 12px rgba(65, 105, 225, 0.3);
    }

    /* No results state - SAMA DENGAN TASKS.PHP */
    .no-results {
      text-align: center;
      padding: 50px 15px;
      color: #666;
    }

    .no-results i {
      font-size: 3rem;
      color: #ddd;
      margin-bottom: 15px;
    }

    .no-results p {
      margin-bottom: 10px;
      font-size: 1rem;
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

    /* Delete Confirmation Modal */
    .delete-modal {
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

    .delete-modal-content {
      background-color: white;
      padding: 25px 20px;
      border-radius: 15px;
      width: 100%;
      max-width: 380px;
      text-align: center;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    }

    .delete-modal h3 {
      color: #333;
      margin-bottom: 12px;
      font-size: 1.2rem;
    }

    .delete-modal p {
      color: #666;
      margin-bottom: 20px;
      font-size: 0.9rem;
      line-height: 1.4;
    }

    .delete-actions {
      display: flex;
      gap: 12px;
      justify-content: center;
    }

    .btn-delete-confirm {
      background: #f44336;
      color: white;
    }

    .btn-delete-confirm:hover {
      background: #d32f2f;
    }

    /* Success notification */
    .notification {
      position: fixed;
      top: 15px;
      right: 15px;
      left: 15px;
      background: #00c853;
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

    .notification i {
      font-size: 1.1rem;
      flex-shrink: 0;
    }

    /* View Modal */
    .view-modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.3);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 15px;
    }
    
    .view-modal-content {
      background-color: white;
      padding: 25px 20px;
      border-radius: 15px;
      width: 100%;
      max-width: 380px;
      text-align: center;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    }
    
    .user-detail-avatar {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.5rem;
      font-weight: 600;
      margin: 0 auto 15px;
      border: 3px solid #fff;
      box-shadow: 0 3px 10px rgba(0,0,0,0.1);
    }
    
    .user-detail-info {
      text-align: left;
      margin: 15px 0;
    }
    
    .user-detail-item {
      margin-bottom: 12px;
      padding-bottom: 12px;
      border-bottom: 1px solid #f0f0f0;
    }
    
    .user-detail-label {
      font-size: 0.8rem;
      color: #666;
      margin-bottom: 4px;
    }
    
    .user-detail-value {
      font-size: 0.95rem;
      color: #333;
      font-weight: 500;
      word-break: break-word;
    }

    /* Media Queries for Responsiveness - SAMA DENGAN TASKS.PHP */
    @media (max-width: 480px) {
      html {
        font-size: 14px;
      }
      
      header {
        padding: 15px 12px 20px 12px;
      }
      
      .back-btn {
        width: 34px;
        height: 34px;
        font-size: 0.9rem;
      }
      
      .header-title {
        font-size: 1.1rem;
      }
      
      .content {
        padding: 12px;
      }
      
      .search-box {
        padding: 10px 12px;
      }
      
      .user-card {
        padding: 16px;
        margin-bottom: 14px;
      }
      
      .user-avatar {
        width: 50px;
        height: 50px;
        font-size: 18px;
        border-width: 3px;
      }
      
      .user-name {
        font-size: 15px;
      }
      
      .floating-add-btn {
        width: 50px;
        height: 50px;
        font-size: 1.2rem;
        right: 12px;
        bottom: 70px;
      }
      
      .modal-header {
        padding: 15px 12px;
      }
      
      .modal-header h3 {
        font-size: 1.1rem;
        padding: 0 35px;
      }
      
      .modal-body {
        padding: 15px 12px;
      }
      
      .form-row {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .form-actions {
        grid-template-columns: 1fr;
        gap: 10px;
        padding: 12px;
      }
      
      .btn {
        padding: 12px 20px;
        font-size: 0.9rem;
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



    /* Scrollbar Styling - SAMA DENGAN TASKS.PHP */
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

    /* Touch-friendly improvements - SAMA DENGAN TASKS.PHP */
    @media (hover: none) and (pointer: coarse) {
      .user-menu:hover {
        color: #9ca3af;
      }
      
      .btn:hover {
        transform: none;
      }
      
      .floating-add-btn:hover {
        transform: none;
        box-shadow: 0 6px 20px rgba(65, 105, 225, 0.4);
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <!-- Header - SAMA DENGAN TASKS.PHP -->
    <header>
      <div class="header-content">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
          <i class="fas fa-arrow-left"></i>
        </button>
        <div class="header-title">Users</div>
      </div>
    </header>

    <!-- Success Notification -->
    <div class="notification" id="successNotification">
      <i class="fas fa-check-circle"></i>
      <span id="notificationText">User berhasil ditambahkan!</span>
    </div>

    <!-- Content -->
    <div class="content">
    <!-- Search Box -->
    <div class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search user" id="searchInput">
    </div>

    <!-- User Cards Container -->
    <div id="usersContainer">
      <!-- Users will be populated here -->
    </div>
  </div>

  <!-- Floating Add Button -->
  <button class="floating-add-btn" id="addUserBtn">
    <i class="fas fa-plus"></i>
  </button>

  <!-- Add User Modal -->
  <div class="modal" id="addUserModal">
    <div class="modal-content">
      <div class="modal-header">
        <button class="modal-back-btn" id="closeModalBtn">
          <i class="fas fa-arrow-left"></i>
        </button>
        <h3>Tambah User</h3>
      </div>
      
      <div class="modal-body">
        <div class="form-title">Form Tambah User</div>
        
        <form id="addUserForm">
          <div class="form-row">
            <div class="form-group">
              <label for="firstName">Nama Depan</label>
              <input type="text" id="firstName" placeholder="Nama depan" required>
            </div>

            <div class="form-group">
              <label for="lastName">Nama Belakang</label>
              <input type="text" id="lastName" placeholder="Nama belakang" required>
            </div>
          </div>

          <div class="form-group">
            <label for="userEmail">Email</label>
            <input type="email" id="userEmail" placeholder="email@example.com" required>
          </div>

          <div class="form-group">
            <label for="phoneNumber">Telepon</label>
            <div class="phone-input">
              <div class="phone-prefix">+62</div>
              <input type="tel" id="phoneNumber" placeholder="8123456789" required>
            </div>
          </div>

          <div class="form-group">
            <label for="password">Password</label>
            <div class="password-toggle">
              <input type="password" id="password" placeholder="Password" required>
              <button type="button" class="toggle-icon" id="togglePassword">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label for="confirmPassword">Konfirmasi Password</label>
            <div class="password-toggle">
              <input type="password" id="confirmPassword" placeholder="Konfirmasi password" required>
              <button type="button" class="toggle-icon" id="toggleConfirmPassword">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label for="userRole">Role</label>
            <select id="userRole" required>
              <option value="user">User</option>
              <option value="admin">Admin</option>
              <option value="manager">Manager</option>
              <option value="staff">Staff</option>
            </select>
          </div>

          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="cancelBtn">Batal</button>
            <button type="submit" class="btn btn-save">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- View User Modal -->
  <div class="view-modal" id="viewUserModal">
    <div class="view-modal-content">
      <div class="user-detail-avatar" id="viewUserAvatar">D</div>
      <h3 id="viewUserName">User Name</h3>
      <div class="user-detail-info">
        <div class="user-detail-item">
          <div class="user-detail-label">Email</div>
          <div class="user-detail-value" id="viewUserEmail">email@example.com</div>
        </div>
        <div class="user-detail-item">
          <div class="user-detail-label">Telepon</div>
          <div class="user-detail-value" id="viewUserPhone">+62 8123456789</div>
        </div>
        <div class="user-detail-item">
          <div class="user-detail-label">Role</div>
          <div class="user-detail-value" id="viewUserRole">User</div>
        </div>
        <div class="user-detail-item">
          <div class="user-detail-label">Status</div>
          <div class="user-detail-value">
            <span class="status-dot" id="viewUserStatusDot"></span>
            <span id="viewUserStatusText">Active</span>
          </div>
        </div>
      </div>
      <button class="btn btn-cancel" onclick="closeViewModal()">Tutup</button>
    </div>
  </div>

  <!-- Edit User Modal -->
  <div class="modal" id="editUserModal">
    <div class="modal-content">
      <div class="modal-header">
        <button class="modal-back-btn" id="closeEditModalBtn">
          <i class="fas fa-arrow-left"></i>
        </button>
        <h3>Edit User</h3>
      </div>
      
      <div class="modal-body">
        <div class="form-title">Form Edit User</div>
        
        <form id="editUserForm">
          <input type="hidden" id="editUserId">
          
          <div class="form-row">
            <div class="form-group">
              <label for="editFirstName">Nama Depan</label>
              <input type="text" id="editFirstName" placeholder="Nama depan" required>
            </div>

            <div class="form-group">
              <label for="editLastName">Nama Belakang</label>
              <input type="text" id="editLastName" placeholder="Nama belakang" required>
            </div>
          </div>

          <div class="form-group">
            <label for="editUserEmail">Email</label>
            <input type="email" id="editUserEmail" placeholder="email@example.com" required>
          </div>

          <div class="form-group">
            <label for="editPhoneNumber">Telepon</label>
            <div class="phone-input">
              <div class="phone-prefix">+62</div>
              <input type="tel" id="editPhoneNumber" placeholder="8123456789" required>
            </div>
          </div>

          <div class="form-group">
            <label for="editUserRole">Role</label>
            <select id="editUserRole" required>
              <option value="user">User</option>
              <option value="admin">Admin</option>
              <option value="manager">Manager</option>
              <option value="staff">Staff</option>
            </select>
          </div>

          <div class="form-group">
            <label for="editUserStatus">Status</label>
            <select id="editUserStatus" required>
              <option value="active">Aktif</option>
              <option value="inactive">Non-aktif</option>
              <option value="suspended">Ditangguhkan</option>
            </select>
          </div>

          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="cancelEditBtn">Batal</button>
            <button type="submit" class="btn btn-save">Update</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <div class="delete-modal" id="deleteModal">
    <div class="delete-modal-content">
      <h3>Hapus User</h3>
      <p>Apakah Anda yakin ingin menghapus user ini? Aksi ini tidak dapat dibatalkan.</p>
      <div class="delete-actions">
        <button class="btn btn-cancel" id="cancelDeleteBtn">Batal</button>
        <button class="btn btn-delete-confirm" id="confirmDeleteBtn">Hapus</button>
      </div>
    </div>
  </div>
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
        <a href="users.php" class="active">
            <i class="fa-solid fa-user-group"></i>
            <span>Users</span>
        </a>
        <a href="profile.php">
            <i class="fa-solid fa-user"></i>
            <span>Profil</span>
        </a>
    </div>


  <script>
    // Load users from database
    const usersFromDB = <?php
      $usersArray = [];
      if (!empty($users)) {
          foreach ($users as $row) {
              $usersArray[] = [
                  'id' => $row['id'],
                  'username' => $row['username'] ?? '',
                  'nama_lengkap' => $row['nama_lengkap'] ?? ($row['nama'] ?? ''),
                  'email' => $row['email'] ?? '',
                  'role' => $row['role'] ?? 'user',
                  'status' => $row['status'] ?? 'active',
                  'phone' => $row['phone'] ?? ''
              ];
          }
      }
      echo json_encode($usersArray);
    ?>;

    let users = usersFromDB;
    let userToDelete = null;
    let userToEdit = null;

    // DOM Elements
    const usersContainer = document.getElementById('usersContainer');
    const searchInput = document.getElementById('searchInput');
    const addUserBtn = document.getElementById('addUserBtn');
    const addUserModal = document.getElementById('addUserModal');
    const addUserForm = document.getElementById('addUserForm');
    const cancelBtn = document.getElementById('cancelBtn');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const togglePassword = document.getElementById('togglePassword');
    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const successNotification = document.getElementById('successNotification');
    const notificationText = document.getElementById('notificationText');
    const deleteModal = document.getElementById('deleteModal');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    const userRole = document.getElementById('userRole');
    
    // View modal elements
    const viewUserModal = document.getElementById('viewUserModal');
    const viewUserAvatar = document.getElementById('viewUserAvatar');
    const viewUserName = document.getElementById('viewUserName');
    const viewUserEmail = document.getElementById('viewUserEmail');
    const viewUserPhone = document.getElementById('viewUserPhone');
    const viewUserRole = document.getElementById('viewUserRole');
    const viewUserStatusDot = document.getElementById('viewUserStatusDot');
    const viewUserStatusText = document.getElementById('viewUserStatusText');
    
    // Edit modal elements
    const editUserModal = document.getElementById('editUserModal');
    const editUserForm = document.getElementById('editUserForm');
    const editUserId = document.getElementById('editUserId');
    const editFirstName = document.getElementById('editFirstName');
    const editLastName = document.getElementById('editLastName');
    const editUserEmail = document.getElementById('editUserEmail');
    const editPhoneNumber = document.getElementById('editPhoneNumber');
    const editUserRole = document.getElementById('editUserRole');
    const editUserStatus = document.getElementById('editUserStatus');
    const closeEditModalBtn = document.getElementById('closeEditModalBtn');
    const cancelEditBtn = document.getElementById('cancelEditBtn');

    // Initialize the app
    function initApp() {
      renderUsers();
      setupEventListeners();
    }

    // Fungsi untuk menampilkan notifikasi sukses
    function showSuccessNotification(message) {
      notificationText.textContent = message;
      successNotification.classList.add('show');
      setTimeout(() => {
        successNotification.classList.remove('show');
      }, 3000);
    }

    function renderUsers(filteredUsers = null) {
      const usersToRender = filteredUsers || users;
      
      if (usersToRender.length === 0) {
        usersContainer.innerHTML = `
          <div class="no-results">
            <i class="fas fa-users"></i>
            <p>Belum ada user</p>
            <p>Tambah user baru untuk memulai!</p>
          </div>
        `;
        return;
      }

      usersContainer.innerHTML = usersToRender.map(user => {
        const avatarClass = `avatar-${(user.id % 5) + 1}`;
        
        // Dapatkan nama untuk ditampilkan
        let displayName = 'Tanpa Nama';
        if (user.nama_lengkap && user.nama_lengkap.trim() !== '') {
          displayName = user.nama_lengkap;
        } else if (user.username && user.username.trim() !== '') {
          displayName = user.username;
        } else if (user.email) {
          displayName = user.email.split('@')[0];
        }
        
        // Dapatkan inisial untuk avatar
        let initial = 'U';
        if (displayName && displayName !== 'Tanpa Nama') {
          initial = displayName.charAt(0).toUpperCase();
        }
        
        const displayRole = user.role || 'user';
        const displayStatus = user.status === 'active' ? 'Aktif' : (user.status === 'inactive' ? 'Non-aktif' : (user.status || 'Non-aktif'));
        const statusColor = user.status === 'active' ? '#00c853' : '#999';
        
        return `
        <div class="user-card">
          <div class="user-header">
            <div class="user-avatar ${avatarClass}">${initial}</div>
            <div class="user-info">
              <div class="user-name">${displayName}</div>
              <div class="user-role">${displayRole}</div>
              <div class="user-status">
                <div class="status-dot" style="background: ${statusColor}"></div>
                <div class="status-text">${displayStatus}</div>
              </div>
            </div>
            <button class="user-menu" onclick="event.stopPropagation(); toggleUserMenu(${user.id})">
              <i class="fas fa-ellipsis-v"></i>
              <div class="user-dropdown-menu" id="menu-${user.id}">
                <div class="user-dropdown-item" onclick="event.stopPropagation(); viewUser(${user.id})">
                  <i class="fas fa-eye"></i> Lihat
                </div>
                <div class="user-dropdown-item" onclick="event.stopPropagation(); editUser(${user.id})">
                  <i class="fas fa-edit"></i> Edit
                </div>
                <div class="user-dropdown-item delete" onclick="event.stopPropagation(); confirmDelete(${user.id})">
                  <i class="fas fa-trash"></i> Hapus
                </div>
              </div>
            </button>
          </div>

          <div class="user-details">
            <div class="user-detail-item">
              <i class="fas fa-envelope"></i>
              <span>${user.email || 'Tidak ada email'}</span>
            </div>
            <div class="user-detail-item">
              <i class="fas fa-phone"></i>
              <span>${user.phone || 'Tidak ada telepon'}</span>
            </div>
          </div>
        </div>
      `}).join('');
    }

    function setupEventListeners() {
      searchInput.addEventListener('input', handleSearch);
      
      addUserBtn.addEventListener('click', () => {
        addUserModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
      });

      cancelBtn.addEventListener('click', closeAddModal);
      closeModalBtn.addEventListener('click', closeAddModal);

      addUserModal.addEventListener('click', (e) => {
        if (e.target === addUserModal) closeAddModal();
      });

      editUserModal.addEventListener('click', (e) => {
        if (e.target === editUserModal) closeEditModal();
      });

      cancelEditBtn.addEventListener('click', closeEditModal);
      closeEditModalBtn.addEventListener('click', closeEditModal);

      // Password toggle functionality
      togglePassword.addEventListener('click', () => {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        togglePassword.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
      });

      toggleConfirmPassword.addEventListener('click', () => {
        const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        confirmPasswordInput.setAttribute('type', type);
        toggleConfirmPassword.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
      });

      addUserForm.addEventListener('submit', handleAddUser);
      editUserForm.addEventListener('submit', handleEditUser);
      
      // Delete modal events
      cancelDeleteBtn.addEventListener('click', closeDeleteModal);
      confirmDeleteBtn.addEventListener('click', handleDeleteUser);
      
      // Close dropdowns when clicking outside
      document.addEventListener('click', (e) => {
        if (!e.target.closest('.user-menu') && !e.target.closest('.user-dropdown-menu')) {
          document.querySelectorAll('.user-dropdown-menu').forEach(menu => {
            menu.classList.remove('active');
          });
        }
      });
    }

    function handleSearch() {
      const searchTerm = searchInput.value.toLowerCase().trim();
      
      if (searchTerm === '') {
        renderUsers();
        return;
      }

      const filteredUsers = users.filter(user => {
        const nama_lengkap = user.nama_lengkap ? user.nama_lengkap.toLowerCase() : '';
        const username = user.username ? user.username.toLowerCase() : '';
        const email = user.email ? user.email.toLowerCase() : '';
        const role = user.role ? user.role.toLowerCase() : '';
        
        return nama_lengkap.includes(searchTerm) ||
               username.includes(searchTerm) || 
               email.includes(searchTerm) ||
               role.includes(searchTerm);
      });
      
      renderUsers(filteredUsers);
    }

    async function handleAddUser(e) {
      e.preventDefault();
      
      const firstName = document.getElementById('firstName').value;
      const lastName = document.getElementById('lastName').value;
      const email = document.getElementById('userEmail').value;
      const phone = document.getElementById('phoneNumber').value;
      const password = document.getElementById('password').value;
      const confirmPassword = document.getElementById('confirmPassword').value;
      const role = userRole.value;

      // Validasi email format
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        alert('Format email tidak valid!');
        return;
      }

      // Validasi password
      if (password !== confirmPassword) {
        alert('Password dan konfirmasi password tidak cocok!');
        return;
      }

      if (password.length < 6) {
        alert('Password harus minimal 6 karakter!');
        return;
      }

      // Validasi phone number (hanya angka, minimal 8 digit)
      const phoneRegex = /^[0-9]{8,}$/;
      if (!phoneRegex.test(phone)) {
        alert('Nomor telepon harus minimal 8 digit angka!');
        return;
      }

      try {
        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('firstName', firstName);
        formData.append('lastName', lastName);
        formData.append('email', email);
        formData.append('phone', '+62' + phone);
        formData.append('password', password);
        formData.append('role', role);

        const response = await fetch('users.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();
        
        if (result.success) {
          showSuccessNotification(result.message);
          closeAddModal();
          // Reload users
          await loadUsers();
        } else {
          alert(result.message);
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menambahkan user');
      }
    }

    async function handleEditUser(e) {
      e.preventDefault();
      
      const userData = {
        firstName: editFirstName.value,
        lastName: editLastName.value,
        email: editUserEmail.value,
        phone: '+62' + editPhoneNumber.value,
        role: editUserRole.value,
        status: editUserStatus.value
      };
      
      try {
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id', editUserId.value);
        formData.append('firstName', userData.firstName);
        formData.append('lastName', userData.lastName);
        formData.append('email', userData.email);
        formData.append('phone', userData.phone);
        formData.append('role', userData.role);
        formData.append('status', userData.status);

        const response = await fetch('users.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();
        
        if (result.success) {
          showSuccessNotification(result.message);
          closeEditModal();
          // Reload users
          await loadUsers();
        } else {
          alert(result.message);
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat memperbarui user');
      }
    }

    async function handleDeleteUser() {
      if (!userToDelete) return;
      
      try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', userToDelete);

        const response = await fetch('users.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();
        
        if (result.success) {
          showSuccessNotification(result.message);
          closeDeleteModal();
          // Reload users
          await loadUsers();
        } else {
          alert(result.message);
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menghapus user');
      }
    }

    // Fungsi untuk memuat users dari database
    async function loadUsers() {
      try {
        const response = await fetch('users.php?load=1');
        const usersData = await response.json();
        users = usersData;
        renderUsers();
      } catch (error) {
        console.error('Error loading users:', error);
      }
    }

    function closeAddModal() {
      addUserModal.style.display = 'none';
      document.body.style.overflow = 'auto';
      addUserForm.reset();
    }

    function closeEditModal() {
      editUserModal.style.display = 'none';
      document.body.style.overflow = 'auto';
      userToEdit = null;
    }

    function closeDeleteModal() {
      deleteModal.style.display = 'none';
      document.body.style.overflow = 'auto';
      userToDelete = null;
    }

    function closeViewModal() {
      viewUserModal.style.display = 'none';
      document.body.style.overflow = 'auto';
    }

    // Toggle user menu
    function toggleUserMenu(userId) {
      const menu = document.getElementById('menu-' + userId);
      const allMenus = document.querySelectorAll('.user-dropdown-menu');
      
      allMenus.forEach(m => {
        if (m !== menu) m.classList.remove('active');
      });
      
      menu.classList.toggle('active');
    }

    // View user
    function viewUser(userId) {
      const user = users.find(u => u.id == userId);
      if (user) {
        // Dapatkan nama untuk ditampilkan
        let displayName = 'Tanpa Nama';
        if (user.nama_lengkap && user.nama_lengkap.trim() !== '') {
          displayName = user.nama_lengkap;
        } else if (user.username && user.username.trim() !== '') {
          displayName = user.username;
        } else if (user.email) {
          displayName = user.email.split('@')[0];
        }
        
        // Dapatkan inisial untuk avatar
        let initial = 'U';
        if (displayName && displayName !== 'Tanpa Nama') {
          initial = displayName.charAt(0).toUpperCase();
        }
        
        const avatarClass = `avatar-${(user.id % 5) + 1}`;
        const displayStatus = user.status === 'active' ? 'Aktif' : (user.status === 'inactive' ? 'Non-aktif' : (user.status || 'Non-aktif'));
        const statusColor = user.status === 'active' ? '#00c853' : '#999';
        
        viewUserAvatar.textContent = initial;
        viewUserAvatar.className = `user-detail-avatar ${avatarClass}`;
        viewUserName.textContent = displayName;
        viewUserEmail.textContent = user.email || 'Tidak tersedia';
        viewUserPhone.textContent = user.phone || 'Tidak tersedia';
        viewUserRole.textContent = user.role || 'user';
        viewUserStatusDot.style.backgroundColor = statusColor;
        viewUserStatusText.textContent = displayStatus;
        viewUserModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
      }
    }

    // Edit user
    function editUser(userId) {
      const user = users.find(u => u.id == userId);
      if (user) {
        userToEdit = userId;
        
        // Parse nama lengkap menjadi first dan last name
        let firstName = '';
        let lastName = '';
        
        let fullName = '';
        if (user.nama_lengkap) fullName = user.nama_lengkap;
        else if (user.username) fullName = user.username;
        
        if (fullName) {
          const nameParts = fullName.split(' ');
          firstName = nameParts[0] || '';
          lastName = nameParts.slice(1).join(' ') || '';
        }
        
        editUserId.value = user.id;
        editFirstName.value = firstName;
        editLastName.value = lastName;
        editUserEmail.value = user.email || '';
        // Hapus '+62' jika ada di phone number
        let phone = user.phone || '';
        if (phone.startsWith('+62')) {
          phone = phone.substring(3);
        }
        editPhoneNumber.value = phone;
        editUserRole.value = user.role || 'user';
        editUserStatus.value = user.status || 'active';
        editUserModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
      }
    }

    // Confirm delete
    function confirmDelete(userId) {
      userToDelete = userId;
      deleteModal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    // Initialize the app when DOM is loaded
    document.addEventListener('DOMContentLoaded', initApp);
    
    // Handle window resize
    window.addEventListener('resize', function() {
      if (window.innerWidth <= 480) {
        document.querySelectorAll('.user-details').forEach(details => {
          details.style.gap = '8px';
        });
      }
    });

    // Export fungsi ke global scope untuk event handler
    window.toggleUserMenu = toggleUserMenu;
    window.viewUser = viewUser;
    window.editUser = editUser;
    window.confirmDelete = confirmDelete;
    window.closeViewModal = closeViewModal;
  </script>
</body>
</html>