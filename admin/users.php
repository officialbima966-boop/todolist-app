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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Users | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * {
      margin: 0; padding: 0; box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }

    body {
      background: #f8faff;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      padding-bottom: 90px;
    }

    header {
      background: linear-gradient(135deg, #0022a8, #0044ff);
      color: #fff;
      padding: 80px 20px 60px;
      border-bottom-left-radius: 30px;
      border-bottom-right-radius: 30px;
      position: relative;
      overflow: visible;
      text-align: center;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      min-height: 200px;
    }

    .page-title {
      font-size: 2.5rem;
      font-weight: 700;
      text-align: center;
      letter-spacing: 1px;
    }

    /* Search Container */
    .search-container {
      padding: 0 20px;
      margin-top: 20px;
      margin-bottom: 20px;
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

    .search-box input {
      border: none;
      outline: none;
      flex: 1;
      padding: 10px;
      font-size: 0.95rem;
      color: #333;
      background: transparent;
    }

    .search-box i {
      color: #666;
      margin-right: 8px;
      font-size: 1rem;
    }

    .content {
      padding: 20px 20px 100px;
      flex: 1;
    }

    .section-title {
      font-size: 1.1rem;
      margin-bottom: 20px;
      color: #333;
      font-weight: 600;
    }

    .user-list {
      display: flex;
      flex-direction: column;
      gap: 15px;
    }

    .user-item {
      background: #fff;
      border-radius: 15px;
      padding: 20px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.08);
      display: flex;
      align-items: center;
      gap: 15px;
      transition: all 0.3s ease;
      border: 1px solid #f0f4ff;
    }

    .user-item:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    }

    .user-avatar {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.1rem;
      font-weight: 600;
      border: 3px solid #fff;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
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

    .user-info-list {
      flex: 1;
    }

    .user-name {
      font-weight: 600;
      color: #333;
      margin-bottom: 5px;
      font-size: 1rem;
    }

    .user-role {
      font-size: 0.85rem;
      color: #666;
      background: #f8faff;
      padding: 4px 10px;
      border-radius: 8px;
      display: inline-block;
      border: 1px solid #e8f0ff;
    }

    .user-status {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 8px;
    }

    .status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #00c853;
    }

    .status-text {
      font-size: 0.8rem;
      color: #666;
    }

    .user-action {
      display: flex;
      gap: 10px;
    }

    .action-btn {
      background: #f8faff;
      border: none;
      border-radius: 8px;
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: #666;
      transition: all 0.3s ease;
      font-size: 0.9rem;
    }

    .action-btn:hover {
      transform: scale(1.1);
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .btn-view {
      background: #e8f0ff;
      color: #2455ff;
    }

    .btn-view:hover {
      background: #2455ff;
      color: white;
    }

    .btn-edit {
      background: #fff8e1;
      color: #ff9800;
    }

    .btn-edit:hover {
      background: #ff9800;
      color: white;
    }

    .btn-delete {
      background: #ffebee;
      color: #f44336;
    }

    .btn-delete:hover {
      background: #f44336;
      color: white;
    }

    /* Floating Add Button */
    .floating-add-btn {
      position: fixed;
      right: 25px;
      bottom: 100px;
      background: #2455ff;
      color: white;
      border: none;
      border-radius: 50%;
      width: 60px;
      height: 60px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      cursor: pointer;
      box-shadow: 0 6px 20px rgba(36, 85, 255, 0.4);
      z-index: 99;
      transition: all 0.3s ease;
    }

    .floating-add-btn:hover {
      transform: scale(1.1);
      box-shadow: 0 8px 25px rgba(36, 85, 255, 0.6);
    }

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

    /* Modal styles */
    .modal {
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
      padding: 20px;
    }

    .modal-content {
      background-color: white;
      padding: 30px;
      border-radius: 20px;
      width: 100%;
      max-width: 450px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
    }

    .modal-header {
      text-align: center;
      margin-bottom: 30px;
    }

    .modal-header h3 {
      color: #333;
      font-size: 1.5rem;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .modal-header p {
      color: #666;
      font-size: 0.9rem;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: #333;
      font-weight: 600;
      font-size: 0.95rem;
    }

    .form-group input, .form-group select {
      width: 100%;
      padding: 15px;
      border: 2px solid #e8f0ff;
      border-radius: 12px;
      font-size: 1rem;
      background: #f8faff;
      transition: all 0.3s;
    }

    .form-group input:focus, .form-group select:focus {
      outline: none;
      border-color: #2455ff;
      box-shadow: 0 0 0 3px rgba(36, 85, 255, 0.1);
      background: white;
    }

    .form-row {
      display: flex;
      gap: 15px;
    }

    .form-row .form-group {
      flex: 1;
    }

    .phone-input {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .phone-prefix {
      background: #f0f4ff;
      padding: 15px;
      border-radius: 12px;
      color: #2455ff;
      font-weight: 600;
      min-width: 70px;
      text-align: center;
      border: 2px solid #e8f0ff;
      font-size: 1rem;
    }

    .phone-input input {
      flex: 1;
    }

    .password-toggle {
      position: relative;
    }

    .password-toggle input {
      padding-right: 50px;
    }

    .toggle-icon {
      position: absolute;
      right: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #666;
      cursor: pointer;
      background: none;
      border: none;
      font-size: 1rem;
    }

    .form-actions {
      display: flex;
      justify-content: space-between;
      margin-top: 30px;
      gap: 15px;
    }

    .btn {
      padding: 16px 30px;
      border: none;
      border-radius: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      flex: 1;
      font-size: 1rem;
      font-weight: 700;
    }

    .btn-cancel {
      background: #f0f0f0;
      color: #666;
    }

    .btn-cancel:hover {
      background: #e0e0e0;
      transform: translateY(-2px);
    }

    .btn-save {
      background: #2455ff;
      color: white;
    }

    .btn-save:hover {
      background: #1a45e0;
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(36, 85, 255, 0.4);
    }

    /* Success notification */
    .notification {
      position: fixed;
      top: 20px;
      right: 20px;
      background: #00c853;
      color: white;
      padding: 15px 25px;
      border-radius: 10px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
      z-index: 1000;
      display: flex;
      align-items: center;
      gap: 10px;
      transform: translateX(150%);
      transition: transform 0.3s ease;
    }

    .notification.show {
      transform: translateX(0);
    }

    .notification i {
      font-size: 1.2rem;
    }

    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: #666;
    }

    .empty-state i {
      font-size: 4rem;
      color: #ddd;
      margin-bottom: 20px;
    }

    .empty-state h3 {
      font-size: 1.3rem;
      margin-bottom: 10px;
      color: #333;
    }

    .empty-state p {
      margin-bottom: 20px;
      font-size: 0.95rem;
    }

    .btn-add-first {
      background: #2455ff;
      color: white;
      border: none;
      border-radius: 12px;
      padding: 12px 25px;
      font-weight: 600;
      cursor: pointer;
      font-size: 0.95rem;
      transition: all 0.3s;
    }

    .btn-add-first:hover {
      background: #1a45e0;
      transform: translateY(-2px);
    }

    /* Placeholder styling */
    input::placeholder {
      color: #999;
      font-weight: 400;
    }

    /* Delete confirmation modal */
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
      padding: 20px;
    }

    .delete-modal-content {
      background-color: white;
      padding: 30px;
      border-radius: 20px;
      width: 100%;
      max-width: 400px;
      text-align: center;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
    }

    .delete-modal h3 {
      color: #333;
      margin-bottom: 15px;
      font-size: 1.3rem;
    }

    .delete-modal p {
      color: #666;
      margin-bottom: 25px;
    }

    .delete-actions {
      display: flex;
      gap: 15px;
      justify-content: center;
    }

    .btn-delete-confirm {
      background: #f44336;
      color: white;
    }

    .btn-delete-confirm:hover {
      background: #d32f2f;
    }
    
    /* View Modal */
    .view-modal {
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
      padding: 20px;
    }
    
    .view-modal-content {
      background-color: white;
      padding: 30px;
      border-radius: 20px;
      width: 100%;
      max-width: 400px;
      text-align: center;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
    }
    
    .user-detail-avatar {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 2rem;
      font-weight: 600;
      margin: 0 auto 20px;
      border: 4px solid #fff;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    
    .user-detail-info {
      text-align: left;
      margin: 20px 0;
    }
    
    .user-detail-item {
      margin-bottom: 15px;
      padding-bottom: 15px;
      border-bottom: 1px solid #f0f0f0;
    }
    
    .user-detail-label {
      font-size: 0.85rem;
      color: #666;
      margin-bottom: 5px;
    }
    
    .user-detail-value {
      font-size: 1rem;
      color: #333;
      font-weight: 500;
    }
    
    /* Edit Modal */
    .edit-modal {
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
      padding: 20px;
    }
    
    .edit-modal-content {
      background-color: white;
      padding: 30px;
      border-radius: 20px;
      width: 100%;
      max-width: 450px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
    }
  </style>
</head>
<body>

  <header>
    <div class="page-title">Users</div>
  </header>

  <!-- Success Notification -->
  <div class="notification" id="successNotification">
    <i class="fas fa-check-circle"></i>
    <span id="notificationText">User berhasil ditambahkan!</span>
  </div>

  <!-- Search Container -->
  <div class="search-container">
    <div class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search" id="searchInput" />
    </div>
  </div>

  <div class="content">
    <h2 class="section-title">Users</h2>

    <div class="user-list" id="userList">
      <?php if (empty($users)): ?>
      <!-- Empty State -->
      <div class="empty-state">
        <i class="fas fa-users"></i>
        <h3>No Users Yet</h3>
        <p>Start by adding your first user</p>
        <button class="btn-add-first" id="addFirstUserBtn">+ Add User</button>
      </div>
      <?php else: ?>
        <!-- User items will be added here by JavaScript -->
      <?php endif; ?>
    </div>
  </div>

  <!-- Floating Add Button -->
  <button class="floating-add-btn" id="addUserBtn">+</button>

  <!-- Add User Modal -->
  <div class="modal" id="addUserModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Add User</h3>
        <p>Create a new user account</p>
      </div>
      
      <form id="addUserForm">
        <!-- First Name -->
        <div class="form-group">
          <label for="firstName">First name</label>
          <input type="text" id="firstName" placeholder="first" required>
        </div>

        <!-- Last Name -->
        <div class="form-group">
          <label for="lastName">Last Name</label>
          <input type="text" id="lastName" placeholder="last" required>
        </div>

        <!-- Email -->
        <div class="form-group">
          <label for="userEmail">Email Address</label>
          <input type="email" id="userEmail" placeholder="email@xyz.com" required>
        </div>

        <!-- Phone Number -->
        <div class="form-group">
          <label for="phoneNumber">Phone</label>
          <div class="phone-input">
            <div class="phone-prefix">+62</div>
            <input type="tel" id="phoneNumber" placeholder="XXXXXXXX" required>
          </div>
        </div>

        <!-- Password -->
        <div class="form-group">
          <label for="password">Password</label>
          <div class="password-toggle">
            <input type="password" id="password" placeholder="Password" required>
            <button type="button" class="toggle-icon" id="togglePassword">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>

        <!-- Confirm Password -->
        <div class="form-group">
          <label for="confirmPassword">Re-Password</label>
          <div class="password-toggle">
            <input type="password" id="confirmPassword" placeholder="Password" required>
            <button type="button" class="toggle-icon" id="toggleConfirmPassword">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>

        <!-- Role -->
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
          <button type="button" class="btn btn-cancel" id="cancelBtn">Cancel</button>
          <button type="submit" class="btn btn-save">Save</button>
        </div>
      </form>
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
          <div class="user-detail-label">Phone</div>
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
      <button class="btn btn-cancel" onclick="closeViewModal()">Close</button>
    </div>
  </div>

  <!-- Edit User Modal -->
  <div class="edit-modal" id="editUserModal">
    <div class="edit-modal-content">
      <div class="modal-header">
        <h3>Edit User</h3>
        <p>Update user information</p>
      </div>
      
      <form id="editUserForm">
        <input type="hidden" id="editUserId">
        
        <!-- First Name -->
        <div class="form-group">
          <label for="editFirstName">First name</label>
          <input type="text" id="editFirstName" placeholder="first" required>
        </div>

        <!-- Last Name -->
        <div class="form-group">
          <label for="editLastName">Last Name</label>
          <input type="text" id="editLastName" placeholder="last" required>
        </div>

        <!-- Email -->
        <div class="form-group">
          <label for="editUserEmail">Email Address</label>
          <input type="email" id="editUserEmail" placeholder="email@xyz.com" required>
        </div>

        <!-- Phone Number -->
        <div class="form-group">
          <label for="editPhoneNumber">Phone</label>
          <div class="phone-input">
            <div class="phone-prefix">+62</div>
            <input type="tel" id="editPhoneNumber" placeholder="XXXXXXXX" required>
          </div>
        </div>

        <!-- Role -->
        <div class="form-group">
          <label for="editUserRole">Role</label>
          <select id="editUserRole" required>
            <option value="user">User</option>
            <option value="admin">Admin</option>
            <option value="manager">Manager</option>
            <option value="staff">Staff</option>
          </select>
        </div>

        <!-- Status -->
        <div class="form-group">
          <label for="editUserStatus">Status</label>
          <select id="editUserStatus" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>

        <div class="form-actions">
          <button type="button" class="btn btn-cancel" onclick="closeEditModal()">Cancel</button>
          <button type="submit" class="btn btn-save">Update</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <div class="delete-modal" id="deleteModal">
    <div class="delete-modal-content">
      <h3>Delete User</h3>
      <p>Are you sure you want to delete this user? This action cannot be undone.</p>
      <div class="delete-actions">
        <button class="btn btn-cancel" id="cancelDeleteBtn">Cancel</button>
        <button class="btn btn-delete-confirm" id="confirmDeleteBtn">Delete</button>
      </div>
    </div>
  </div>

  <!-- NAVIGASI SAMA SEPERTI TUGAS_DEFAULT - DENGAN ACTIVE PADA USERS -->
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
    <a href="tugas_default.php">
      <i class="fa-solid fa-tasks"></i>
      <span>Tugas Default</span>
    </a>
    <a href="profile.php">
      <i class="fa-solid fa-user"></i>
      <span>Profil</span>
    </a>
  </div>

  <script>
    // Inisialisasi users dari PHP
    let users = <?php echo json_encode($users); ?>;
    let userToDelete = null;
    let userToEdit = null;

    // DOM Elements
    const userList = document.getElementById('userList');
    const searchInput = document.getElementById('searchInput');
    const addUserBtn = document.getElementById('addUserBtn');
    const addFirstUserBtn = document.getElementById('addFirstUserBtn');
    const addUserModal = document.getElementById('addUserModal');
    const addUserForm = document.getElementById('addUserForm');
    const cancelBtn = document.getElementById('cancelBtn');
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

    // Fungsi untuk menampilkan notifikasi sukses
    function showSuccessNotification(message) {
      notificationText.textContent = message;
      successNotification.classList.add('show');
      setTimeout(() => {
        successNotification.classList.remove('show');
      }, 3000);
    }

    // Fungsi untuk menampilkan daftar pengguna
    function displayUsers(userArray) {
      userList.innerHTML = '';
      
      if (userArray.length === 0) {
        userList.innerHTML = `
          <div class="empty-state">
            <i class="fas fa-users"></i>
            <h3>No Users Found</h3>
            <p>Try a different search or add a new user</p>
            <button class="btn-add-first" id="addFirstUserBtn">+ Add User</button>
          </div>
        `;
        // Re-attach event listener to the new button
        document.getElementById('addFirstUserBtn').addEventListener('click', openAddModal);
        return;
      }
      
      userArray.forEach((user) => {
        const userItem = document.createElement('div');
        userItem.className = 'user-item';
        userItem.setAttribute('data-user-id', user.id);
        
        const avatarClass = `avatar-${(user.id % 5) + 1}`;
        
        // PERBAIKAN: gunakan 'nama_lengkap' bukan 'nama'
        let displayName = 'No Name';
        if (user.nama_lengkap) displayName = user.nama_lengkap;
        else if (user.nama) displayName = user.nama;
        else if (user.username) displayName = user.username;
        else if (user.email) displayName = user.email.split('@')[0];
        
        // Dapatkan inisial untuk avatar
        let initial = 'U';
        if (displayName && displayName !== 'No Name') {
          initial = displayName.charAt(0).toUpperCase();
        }
        
        const displayRole = user.role || 'user';
        const displayStatus = user.status === 'active' ? 'Active' : (user.status || 'Inactive');
        const statusColor = user.status === 'active' ? '#00c853' : '#999';
        
        userItem.innerHTML = `
          <div class="user-avatar ${avatarClass}">${initial}</div>
          <div class="user-info-list">
            <div class="user-name">${displayName}</div>
            <div class="user-role">${displayRole}</div>
            <div class="user-status">
              <div class="status-dot" style="background: ${statusColor}"></div>
              <div class="status-text">${displayStatus}</div>
            </div>
          </div>
          <div class="user-action">
            <button class="action-btn btn-view" onclick="viewUser(${user.id})">
              <i class="fas fa-eye"></i>
            </button>
            <button class="action-btn btn-edit" onclick="editUser(${user.id})">
              <i class="fas fa-edit"></i>
            </button>
            <button class="action-btn btn-delete" onclick="showDeleteConfirmation(${user.id})">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        `;
        
        userList.appendChild(userItem);
      });
    }

    // Fungsi untuk mencari pengguna
    function searchUsers() {
      const searchTerm = searchInput.value.toLowerCase();
      const filteredUsers = users.filter(user => {
        // PERBAIKAN: gunakan 'nama_lengkap' bukan 'nama'
        const nama_lengkap = user.nama_lengkap ? user.nama_lengkap.toLowerCase() : '';
        const nama = user.nama ? user.nama.toLowerCase() : '';
        const username = user.username ? user.username.toLowerCase() : '';
        const email = user.email ? user.email.toLowerCase() : '';
        const role = user.role ? user.role.toLowerCase() : '';
        
        return nama_lengkap.includes(searchTerm) ||
               nama.includes(searchTerm) || 
               username.includes(searchTerm) || 
               email.includes(searchTerm) ||
               role.includes(searchTerm);
      });
      displayUsers(filteredUsers);
    }

    // Fungsi AJAX untuk menambahkan pengguna baru
    async function addUserToDatabase(userData) {
      try {
        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('firstName', userData.firstName);
        formData.append('lastName', userData.lastName);
        formData.append('email', userData.email);
        formData.append('phone', userData.phone);
        formData.append('password', userData.password);
        formData.append('role', userData.role);

        const response = await fetch('users.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();
        
        if (result.success) {
          // Reload users dari database
          await loadUsers();
          closeAddModal();
          showSuccessNotification(result.message);
        } else {
          alert(result.message);
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menambahkan user');
      }
    }

    // Fungsi AJAX untuk update pengguna
    async function updateUserInDatabase(userId, userData) {
      try {
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id', userId);
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
          await loadUsers();
          closeEditModal();
          showSuccessNotification(result.message);
        } else {
          alert(result.message);
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat memperbarui user');
      }
    }

    // Fungsi AJAX untuk menghapus pengguna
    async function deleteUserFromDatabase(userId) {
      try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', userId);

        const response = await fetch('users.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();
        
        if (result.success) {
          await loadUsers();
          closeDeleteModal();
          showSuccessNotification(result.message);
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
        displayUsers(users);
      } catch (error) {
        console.error('Error loading users:', error);
      }
    }

    // Fungsi untuk membuka modal tambah
    function openAddModal() {
      addUserModal.style.display = 'flex';
    }

    // Fungsi untuk menutup modal tambah
    function closeAddModal() {
      addUserModal.style.display = 'none';
      addUserForm.reset();
    }

    // Fungsi untuk membuka modal view
    function viewUser(userId) {
      const user = users.find(u => u.id == userId);
      if (user) {
        // PERBAIKAN: gunakan 'nama_lengkap' bukan 'nama'
        let displayName = 'No Name';
        if (user.nama_lengkap) displayName = user.nama_lengkap;
        else if (user.nama) displayName = user.nama;
        else if (user.username) displayName = user.username;
        else if (user.email) displayName = user.email.split('@')[0];
        
        // Dapatkan inisial untuk avatar
        let initial = 'U';
        if (displayName && displayName !== 'No Name') {
          initial = displayName.charAt(0).toUpperCase();
        }
        
        viewUserAvatar.textContent = initial;
        viewUserAvatar.className = `user-detail-avatar avatar-${(user.id % 5) + 1}`;
        viewUserName.textContent = displayName;
        viewUserEmail.textContent = user.email || 'Tidak tersedia';
        viewUserPhone.textContent = user.phone || 'Tidak tersedia';
        viewUserRole.textContent = user.role || 'user';
        viewUserStatusDot.style.backgroundColor = user.status === 'active' ? '#00c853' : '#999';
        viewUserStatusText.textContent = user.status === 'active' ? 'Active' : (user.status || 'Inactive');
        viewUserModal.style.display = 'flex';
      }
    }

    // Fungsi untuk menutup modal view
    function closeViewModal() {
      viewUserModal.style.display = 'none';
    }

    // Fungsi untuk membuka modal edit
    function editUser(userId) {
      const user = users.find(u => u.id == userId);
      if (user) {
        userToEdit = userId;
        
        // Parse nama lengkap menjadi first dan last name
        let firstName = '';
        let lastName = '';
        
        // Coba dapatkan nama dari berbagai sumber
        // PERBAIKAN: prioritaskan 'nama_lengkap'
        let fullName = '';
        if (user.nama_lengkap) fullName = user.nama_lengkap;
        else if (user.nama) fullName = user.nama;
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
        // PERBAIKAN: hapus '+62' jika ada di phone number
        let phone = user.phone || '';
        if (phone.startsWith('+62')) {
          phone = phone.substring(3);
        }
        editPhoneNumber.value = phone;
        editUserRole.value = user.role || 'user';
        editUserStatus.value = user.status || 'active';
        editUserModal.style.display = 'flex';
      }
    }

    // Fungsi untuk menutup modal edit
    function closeEditModal() {
      editUserModal.style.display = 'none';
      userToEdit = null;
    }

    // Fungsi untuk konfirmasi hapus
    function showDeleteConfirmation(userId) {
      userToDelete = userId;
      deleteModal.style.display = 'flex';
    }

    // Fungsi untuk menutup modal hapus
    function closeDeleteModal() {
      deleteModal.style.display = 'none';
      userToDelete = null;
    }

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

    // Event Listeners
    searchInput.addEventListener('input', searchUsers);
    
    addUserBtn.addEventListener('click', openAddModal);
    
    if (addFirstUserBtn) {
      addFirstUserBtn.addEventListener('click', openAddModal);
    }
    
    cancelBtn.addEventListener('click', closeAddModal);
    
    addUserForm.addEventListener('submit', function(e) {
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

      const userData = {
        firstName,
        lastName,
        email,
        phone: '+62' + phone,
        password,
        role
      };

      addUserToDatabase(userData);
    });
    
    // Edit form submission
    editUserForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const userData = {
        firstName: editFirstName.value,
        lastName: editLastName.value,
        email: editUserEmail.value,
        phone: '+62' + editPhoneNumber.value,
        role: editUserRole.value,
        status: editUserStatus.value
      };
      
      updateUserInDatabase(userToEdit, userData);
    });

    // Event listeners untuk modal hapus
    cancelDeleteBtn.addEventListener('click', closeDeleteModal);
    
    confirmDeleteBtn.addEventListener('click', () => {
      if (userToDelete) {
        deleteUserFromDatabase(userToDelete);
      }
    });

    // Menutup modal ketika mengklik di luar konten modal
    window.addEventListener('click', function(e) {
      if (e.target === addUserModal) {
        closeAddModal();
      }
      if (e.target === viewUserModal) {
        closeViewModal();
      }
      if (e.target === editUserModal) {
        closeEditModal();
      }
      if (e.target === deleteModal) {
        closeDeleteModal();
      }
    });

    // Menampilkan semua pengguna saat halaman dimuat
    window.onload = function() {
      displayUsers(users);
    };

    // Export fungsi ke global scope untuk event handler
    window.showDeleteConfirmation = showDeleteConfirmation;
    window.viewUser = viewUser;
    window.editUser = editUser;
    window.closeViewModal = closeViewModal;
    window.closeEditModal = closeEditModal;
  </script>
</body>
</html>