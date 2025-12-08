<?php
session_start();
require_once "../inc/koneksi.php";

if (!isset($_SESSION['admin'])) {
  header("Location: ../auth/login.php");
  exit;
}

$username = $_SESSION['admin'];

// Debug info
error_log("Mencari user dengan username: " . $username);

// Cek koneksi database
if (!isset($mysqli) || $mysqli->connect_error) {
    die("Koneksi database gagal: " . $mysqli->connect_error);
}

// Cek struktur tabel users
$table_check = $mysqli->query("SHOW TABLES LIKE 'users'");
if ($table_check->num_rows == 0) {
    // Buat tabel users jika belum ada
    $create_table_sql = "
        CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            nama VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            no_hp VARCHAR(20),
            foto VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ";
    if ($mysqli->query($create_table_sql)) {
        error_log("Tabel users berhasil dibuat");
    } else {
        die("Gagal membuat tabel users: " . $mysqli->error);
    }
}

// Cek kolom yang ada di tabel users
$columns_check = $mysqli->query("SHOW COLUMNS FROM users");
$existing_columns = [];
while ($column = $columns_check->fetch_assoc()) {
    $existing_columns[] = $column['Field'];
}

error_log("Kolom yang ada: " . implode(', ', $existing_columns));

// Fungsi untuk membuat user default
function createDefaultUser($username, $mysqli) {
    $default_data = [
        'nama' => ucfirst($username),
        'email' => $username . '@example.com',
        'no_hp' => '081234567890',
        'foto' => null,
        'password' => password_hash('password123', PASSWORD_DEFAULT)
    ];
    
    // Query tanpa kolom level
    $sql = "INSERT INTO users (username, password, nama, email, no_hp) VALUES (?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sssss", $username, $default_data['password'], $default_data['nama'], $default_data['email'], $default_data['no_hp']);
        
        if ($stmt->execute()) {
            return $default_data;
        }
        $stmt->close();
    }
    
    return null;
}

// Ambil data pengguna dari database
$user = null;
$sql = "SELECT nama, email, no_hp, foto FROM users WHERE username = ?";
$stmt = $mysqli->prepare($sql);

if ($stmt) {
    $stmt->bind_param("s", $username);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            error_log("User ditemukan: " . print_r($user, true));
        } else {
            error_log("User tidak ditemukan dengan username: " . $username);
            // Coba buat user otomatis
            $user = createDefaultUser($username, $mysqli);
        }
    } else {
        error_log("Error executing query: " . $stmt->error);
    }
    $stmt->close();
} else {
    error_log("Error preparing statement: " . $mysqli->error);
}

// Jika user masih null, buat user default manual
if (!$user) {
    $user = [
        'nama' => $username,
        'email' => $username . '@example.com',
        'no_hp' => '081234567890',
        'foto' => null
    ];
    error_log("Menggunakan data user default manual");
}

// Proses penyimpanan perubahan
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $nama = trim($_POST['nama']);
  $email = trim($_POST['email']);
  $no_hp = trim($_POST['no_hp']);
  $foto_baru = $user['foto'] ?? null;
  
  // Buat username baru dari nama (lowercase, tanpa spasi)
  $username_baru = strtolower(str_replace(' ', '', $nama));

  // Upload foto baru jika ada
  if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] == 0) {
    $targetDir = "../uploads/";
    
    // Buat folder uploads jika belum ada
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    // Validasi tipe file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
    $fileType = $_FILES['foto']['type'];
    
    if (!in_array($fileType, $allowedTypes)) {
        $error = "Hanya file gambar (JPG, PNG, GIF) yang diizinkan!";
    } else {
        $namaFile = time() . "_" . uniqid() . "_" . basename($_FILES["foto"]["name"]);
        $targetFile = $targetDir . $namaFile;

        if (move_uploaded_file($_FILES["foto"]["tmp_name"], $targetFile)) {
            $foto_baru = $namaFile;
            
            // Hapus foto lama jika ada
            if (!empty($user['foto']) && file_exists($targetDir . $user['foto'])) {
                unlink($targetDir . $user['foto']);
            }
        } else {
            $error = "Gagal mengupload foto!";
        }
    }
  }

  // Jika tidak ada error, update data
  if (!isset($error)) {
    // Mulai transaksi untuk memastikan semua update berhasil atau gagal bersama
    $mysqli->begin_transaction();
    
    try {
        // 1. Update username di tabel admin
        $update_admin = "UPDATE admin SET username=? WHERE username=?";
        $stmt_admin = $mysqli->prepare($update_admin);
        if (!$stmt_admin) {
            throw new Exception("Gagal prepare admin: " . $mysqli->error);
        }
        $stmt_admin->bind_param("ss", $username_baru, $username);
        if (!$stmt_admin->execute()) {
            throw new Exception("Gagal update admin: " . $stmt_admin->error);
        }
        $stmt_admin->close();

        // 2. Update username dan nama di tabel users
        $check_sql = "SELECT username FROM users WHERE username = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update user yang sudah ada
            $update_sql = "UPDATE users SET username=?, nama=?, email=?, no_hp=?, foto=? WHERE username=?";
            $update_stmt = $mysqli->prepare($update_sql);
            
            if (!$update_stmt) {
                throw new Exception("Gagal prepare users update: " . $mysqli->error);
            }
            
            $update_stmt->bind_param("ssssss", $username_baru, $nama, $email, $no_hp, $foto_baru, $username);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Gagal update users: " . $update_stmt->error);
            }
            $update_stmt->close();
        } else {
            // Buat user baru
            $insert_sql = "INSERT INTO users (username, nama, email, no_hp, foto, password) VALUES (?, ?, ?, ?, ?, ?)";
            $default_password = password_hash('password123', PASSWORD_DEFAULT);
            $insert_stmt = $mysqli->prepare($insert_sql);
            
            if (!$insert_stmt) {
                throw new Exception("Gagal prepare users insert: " . $mysqli->error);
            }
            
            $insert_stmt->bind_param("ssssss", $username_baru, $nama, $email, $no_hp, $foto_baru, $default_password);
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Gagal insert users: " . $insert_stmt->error);
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
        
        // Commit transaksi jika semua berhasil
        $mysqli->commit();
        
        // Update session dengan username baru
        $_SESSION['admin'] = $username_baru;
        $_SESSION['nama'] = $nama;
        $_SESSION['email'] = $email;
        $_SESSION['no_hp'] = $no_hp;
        $_SESSION['foto'] = $foto_baru;
        $_SESSION['profile_timestamp'] = time();

        // Redirect dengan pesan sukses
        header("Location: profile.php?update=success");
        exit;
        
    } catch (Exception $e) {
        // Rollback jika ada error
        $mysqli->rollback();
        $error = "Gagal memperbarui profil: " . $e->getMessage();
        error_log($error);
    }
  }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Profil | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }
    
    body {
      background: #f8faff;
      min-height: 100vh;
      padding-bottom: 20px;
    }
    
    header {
      background: linear-gradient(135deg, #0022a8, #0044ff);
      color: #fff;
      padding: 25px 20px 40px;
      border-bottom-left-radius: 30px;
      border-bottom-right-radius: 30px;
      position: relative;
      text-align: center;
    }
    
    .back-button {
      position: absolute;
      left: 20px;
      top: 25px;
      background: rgba(255, 255, 255, 0.2);
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
      text-decoration: none;
    }
    
    .back-button:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: scale(1.1);
    }
    
    .header-title {
      font-size: 1.4rem;
      font-weight: 700;
      margin-top: 10px;
    }
    
    .container {
      padding: 30px 20px;
      max-width: 500px;
      margin: 0 auto;
    }
    
    form {
      background: #fff;
      padding: 25px;
      border-radius: 20px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    label {
      font-weight: 600;
      color: #333;
      display: block;
      margin-bottom: 8px;
    }
    
    input {
      width: 100%;
      padding: 12px;
      border: 1px solid #ddd;
      border-radius: 10px;
      font-size: 1rem;
      transition: border-color 0.3s ease;
    }
    
    input:focus {
      outline: none;
      border-color: #2455ff;
      box-shadow: 0 0 0 2px rgba(36, 85, 255, 0.1);
    }
    
    .save-btn {
      width: 100%;
      padding: 12px;
      background: #2455ff;
      color: #fff;
      font-size: 1rem;
      font-weight: 600;
      border: none;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-top: 10px;
    }
    
    .save-btn:hover {
      background: #003ee0;
      transform: translateY(-2px);
    }
    
    .save-btn:active {
      transform: translateY(0);
    }
    
    .profile-photo {
      text-align: center;
      margin-bottom: 20px;
    }
    
    .profile-photo img {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      object-fit: cover;
      margin-bottom: 15px;
      border: 3px solid #2455ff;
      background: #f0f0f0;
    }
    
    .error-message {
      background: #ffebee;
      color: #c62828;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 15px;
      text-align: center;
      border: 1px solid #ffcdd2;
    }
    
    .success-message {
      background: #e8f5e8;
      color: #2e7d32;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 15px;
      text-align: center;
      border: 1px solid #c8e6c9;
    }
    
    .file-input-container {
      position: relative;
      display: inline-block;
      width: 100%;
      margin-bottom: 5px;
    }
    
    .file-input-label {
      display: block;
      padding: 10px 15px;
      background: #f5f5f5;
      border: 1px dashed #ccc;
      border-radius: 8px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s ease;
      color: #666;
    }
    
    .file-input-label:hover {
      background: #e8f0ff;
      border-color: #2455ff;
      color: #2455ff;
    }
    
    .file-input {
      position: absolute;
      opacity: 0;
      width: 100%;
      height: 100%;
      cursor: pointer;
    }
    
    .file-info {
      font-size: 0.8rem;
      color: #666;
      text-align: center;
    }
    
    .info-box {
      background: #e3f2fd;
      border-left: 4px solid #2455ff;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-size: 0.9rem;
      color: #1565c0;
    }
  </style>
</head>
<body>

<header>
  <a href="profile.php" class="back-button">
    <i class="fas fa-arrow-left"></i>
  </a>
  <div class="header-title">Edit Profil</div>
</header>

<div class="container">
  <?php if (isset($error)): ?>
    <div class="error-message">
      <i class="fas fa-exclamation-circle"></i> 
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>
  
  <?php if (isset($_GET['update']) && $_GET['update'] == 'success'): ?>
    <div class="success-message">
      <i class="fas fa-check-circle"></i> 
      Profil berhasil diperbarui!
    </div>
  <?php endif; ?>
  
  <div class="info-box">
    <i class="fas fa-info-circle"></i> 
    <strong>Perhatian:</strong> Mengubah nama akan otomatis mengubah username Anda di sistem. Username baru akan dibuat dari nama tanpa spasi dan huruf kecil.
  </div>
  
  <form method="POST" enctype="multipart/form-data">
    <div class="profile-photo">
      <?php
      $foto_src = "https://ui-avatars.com/api/?name=" . urlencode($user['nama']) . "&size=120&background=2455ff&color=fff";
      
      if (!empty($user['foto'])) {
          $foto_path = "../uploads/" . $user['foto'];
          if (file_exists($foto_path)) {
              $foto_src = $foto_path . "?t=" . time();
          }
      }
      ?>
      <img src="<?= $foto_src ?>" alt="Foto Profil" id="profile-preview">
      <br>
      <div class="file-input-container">
        <label class="file-input-label">
          <i class="fas fa-camera"></i> Pilih Foto Baru
          <input type="file" name="foto" accept="image/jpeg,image/png,image/gif,image/jpg" class="file-input" id="file-input">
        </label>
      </div>
      <div class="file-info">Format: JPG, PNG, GIF (Maks. 2MB)</div>
    </div>

    <div class="form-group">
      <label for="nama">Nama Lengkap</label>
      <input type="text" id="nama" name="nama" value="<?= htmlspecialchars($user['nama']) ?>" required>
      <small style="color: #666; font-size: 0.85rem;">
        <i class="fas fa-user"></i> Username akan menjadi: <strong id="preview-username"><?= strtolower(str_replace(' ', '', $user['nama'])) ?></strong>
      </small>
    </div>

    <div class="form-group">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
    </div>

    <div class="form-group">
      <label for="no_hp">No. HP</label>
      <input type="text" id="no_hp" name="no_hp" value="<?= htmlspecialchars($user['no_hp']) ?>" required>
    </div>

    <button type="submit" class="save-btn">
      <i class="fas fa-save"></i> Simpan Perubahan
    </button>
  </form>
</div>

<script>
  // Preview username saat mengetik nama
  document.getElementById('nama').addEventListener('input', function(e) {
    const nama = e.target.value;
    const username = nama.toLowerCase().replace(/\s+/g, '');
    document.getElementById('preview-username').textContent = username || '(kosong)';
  });

  // Preview image sebelum upload
  document.getElementById('file-input').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('profile-preview');
    
    if (file) {
      // Validasi ukuran file (max 2MB)
      if (file.size > 2 * 1024 * 1024) {
        alert('Ukuran file terlalu besar! Maksimal 2MB.');
        this.value = '';
        return;
      }
      
      const reader = new FileReader();
      reader.onload = function(e) {
        preview.src = e.target.result;
      }
      reader.readAsDataURL(file);
    }
  });

  // Validasi form sebelum submit
  document.querySelector('form').addEventListener('submit', function(e) {
    const inputs = this.querySelectorAll('input[required]');
    let valid = true;
    
    inputs.forEach(input => {
      if (!input.value.trim()) {
        valid = false;
        input.style.borderColor = '#f44336';
      } else {
        input.style.borderColor = '#ddd';
      }
    });
    
    if (!valid) {
      e.preventDefault();
      alert('Harap lengkapi semua field yang wajib diisi!');
      return;
    }
    
    // Konfirmasi perubahan username
    const nama = document.getElementById('nama').value;
    const usernameBaru = nama.toLowerCase().replace(/\s+/g, '');
    
    if (!confirm(`Username Anda akan diubah menjadi: ${usernameBaru}\n\nApakah Anda yakin ingin melanjutkan?`)) {
      e.preventDefault();
    }
  });
</script>

</body>
</html>