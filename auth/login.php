<?php
session_start();
require_once "../inc/koneksi.php";

// Pastikan variabel koneksi dikenali
if (isset($mysqli)) {
  $conn = $mysqli;
} else {
  die("❌ Koneksi database tidak ditemukan!");
}

// Handle logout
if (isset($_GET['logout'])) {
  session_destroy();
  header("Location: login.php");
  exit;
}

// Jika sudah login, tetap tampilkan form login tapi dengan pesan
$already_logged_in = false;
if (isset($_SESSION['admin']) || isset($_SESSION['user'])) {
  $already_logged_in = true;
}

$error = "";

// Proses login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username']);
  $password = trim($_POST['password']);
  $user_type = $_POST['user_type'] ?? 'admin'; // Default ke admin jika tidak ada

  // DEBUG: Tampilkan input user
  error_log("Login attempt - Username: " . $username . ", Type: " . $user_type);

  // Special case for rocky user - always login as user regardless of selected type
  if ($username === 'rocky' && $password === 'yogyakarta1') {
    $_SESSION['user'] = $username;
    $_SESSION['user_id'] = 999; // Dummy ID
    $_SESSION['user_role'] = 'user';
    error_log("Special user login successful for: " . $username);
    header("Location: ../user/dashboard.php");
    exit;
  }

  if ($user_type === 'admin') {
    // Login sebagai admin - cek tabel admin
    $query = $conn->prepare("SELECT * FROM admin WHERE username = ? AND password = ?");
    if (!$query) {
      die("❌ SQL Error: " . $conn->error);
    }

    $query->bind_param("ss", $username, $password);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows === 1) {
      $_SESSION['admin'] = $username;
      header("Location: ../admin/dashboard.php");
      exit;
    } else {
      $error = "❌ Username atau password admin salah!";
    }

    $query->close();
  } else {
    // Login sebagai user biasa - cek tabel users
    $user_query = $conn->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
    if (!$user_query) {
      die("❌ SQL Error: " . $conn->error);
    }

    $user_query->bind_param("s", $username);
    $user_query->execute();
    $user_result = $user_query->get_result();

    // DEBUG: Log jumlah hasil query
    error_log("User login query result count: " . $user_result->num_rows);

    if ($user_result->num_rows === 1) {
      $user_data = $user_result->fetch_assoc();

      // DEBUG: Log data user yang ditemukan
      error_log("User found - ID: " . $user_data['id'] . ", Username: " . $user_data['username'] . ", Status: " . $user_data['status'] . ", Role: " . $user_data['role']);

      // Cek status user
      if ($user_data['status'] !== 'active') {
        $error = "❌ Akun user tidak aktif!";
        error_log("User status is not active: " . $user_data['status']);
      } else {
        // Verifikasi password untuk user biasa (password di-hash atau plain text)
        $password_valid = false;
        if (password_verify($password, $user_data['password'])) {
            // Password sudah di-hash
            $password_valid = true;
        } elseif ($password === $user_data['password']) {
            // Password disimpan sebagai plain text (untuk backward compatibility)
            $password_valid = true;
        }

        if ($password_valid) {
          $_SESSION['user'] = $username;
          $_SESSION['user_id'] = $user_data['id'];
          $_SESSION['user_role'] = $user_data['role'];
          error_log("User login successful for: " . $username);
          
          // Redirect berdasarkan role
          if ($user_data['role'] === 'admin') {
            header("Location: ../admin/dashboard.php");
          } else {
            header("Location: ../user/dashboard.php");
          }
          exit;
        } else {
          $error = "❌ Password user salah!";
          error_log("Password verification failed for user: " . $username);
        }
      }
    } else {
      $error = "❌ Username user tidak ditemukan!";
      error_log("User not found in database: " . $username);
    }

    $user_query->close();
  }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
    body {
      background: linear-gradient(135deg, #0022a8, #0044ff);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 20px;
    }
    .login-container {
      background: #fff;
      padding: 40px;
      border-radius: 15px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    }
    .login-header { text-align: center; margin-bottom: 30px; }
    .login-header h1 { color: #2455ff; font-size: 24px; font-weight: 600; margin-bottom: 10px; }
    .login-header p { color: #666; font-size: 14px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
    .form-group input {
      width: 100%; padding: 12px 15px;
      border: 1px solid #ddd; border-radius: 8px; font-size: 14px;
      transition: border-color 0.3s;
    }
    .form-group input:focus { border-color: #2455ff; outline: none; }
    .user-type-options {
      display: flex;
      gap: 20px;
      margin-top: 8px;
    }
    .radio-option {
      display: flex;
      align-items: center;
      cursor: pointer;
      font-weight: 500;
    }
    .radio-option input[type="radio"] {
      margin-right: 8px;
      accent-color: #2455ff;
    }
    .radio-option span {
      color: #333;
    }
    .options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; font-size: 14px; }
    .remember-me { display: flex; align-items: center; }
    .remember-me input { margin-right: 8px; }
    .forgot-password { color: #2455ff; text-decoration: none; }
    .forgot-password:hover { text-decoration: underline; }
    .signin-btn {
      width: 100%; padding: 12px;
      border: none; border-radius: 8px;
      background: #2455ff; color: #fff;
      font-weight: 600; font-size: 16px;
      cursor: pointer; transition: background 0.3s;
      margin-bottom: 20px;
    }
    .signin-btn:hover { background: #0038cc; }
    .signup-link { text-align: center; font-size: 14px; color: #666; }
    .signup-link a { color: #2455ff; text-decoration: none; font-weight: 500; }
    .signup-link a:hover { text-decoration: underline; }
    .error {
      color: #ff3333; text-align: center; margin-bottom: 15px;
      padding: 10px; background-color: #ffe6e6; border-radius: 5px;
      font-size: 14px;
    }
  </style>
</head>
<body>
    <div class="login-container">
      <div class="login-header">
        <h1>Sign In</h1>
        <p>Please sign in to continue</p>
        <?php if (isset($_SESSION['user']) || isset($_SESSION['admin'])): ?>
          <p style="color: #666; font-size: 12px; margin-top: 5px;">
            Sudah login? <a href="?logout=1" style="color: #2455ff; text-decoration: underline;">Logout dulu</a>
          </p>
        <?php endif; ?>
      </div>

    <?php if (!empty($error)): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="form-group">
        <label for="user_type">Tipe User</label>
        <div class="user-type-options">
          <label class="radio-option">
            <input type="radio" name="user_type" value="admin" checked>
            <span>Admin</span>
          </label>
          <label class="radio-option">
            <input type="radio" name="user_type" value="user">
            <span>User Biasa</span>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" placeholder="Username" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Password" required>
      </div>

      <div class="options">
        <div class="remember-me">
          <input type="checkbox" id="remember" name="remember">
          <label for="remember">Remember me</label>
        </div>
        <a href="#" class="forgot-password">Forgot password?</a>
      </div>

      <button type="submit" class="signin-btn">Sign In</button>
    </form>

    <div class="signup-link">
      Belum punya akun? <a href="register.php">Sign Up</a>
    </div>
  </div>
</body>
</html>