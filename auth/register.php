<?php
require_once "../inc/koneksi.php";
session_start();

// 🔹 Pastikan koneksi tersedia
if (!isset($conn) && isset($mysqli)) {
  $conn = $mysqli;
} elseif (!isset($conn)) {
  die("❌ Gagal koneksi ke database. Pastikan koneksi.php benar.");
}

$error = "";
$success = "";

// 🔹 Proses registrasi
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = isset($_POST["username"]) ? trim($_POST["username"]) : "";
  $password = isset($_POST["password"]) ? trim($_POST["password"]) : "";
  $confirm  = isset($_POST["confirm"]) ? trim($_POST["confirm"]) : "";

  if ($username === "" || $password === "" || $confirm === "") {
    $error = "⚠️ Semua kolom harus diisi!";
  } elseif ($password !== $confirm) {
    $error = "⚠️ Password dan konfirmasi tidak sama!";
  } else {
    // 🔹 Cek username sudah dipakai atau belum
    $check = $conn->prepare("SELECT * FROM admin WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
      $error = "⚠️ Username sudah digunakan!";
    } else {
      // 🔹 Hash password biar aman
      $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
      $query = $conn->prepare("INSERT INTO admin (username, password) VALUES (?, ?)");
      $query->bind_param("ss", $username, $hashedPassword);

      if ($query->execute()) {
        $success = "✅ Registrasi berhasil! Silakan <a href='login.php'>Login</a>.";
      } else {
        $error = "❌ Gagal mendaftar: " . htmlspecialchars($conn->error);
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign Up | BM Garage</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
    body {
      background: linear-gradient(135deg, #0022a8, #0044ff);
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; padding: 20px;
    }
    .register-container {
      background: #fff;
      padding: 40px;
      border-radius: 15px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    }
    .register-header { text-align: center; margin-bottom: 30px; }
    .register-header h1 { color: #2455ff; font-size: 24px; font-weight: 600; margin-bottom: 10px; }
    .register-header p { color: #666; font-size: 14px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
    .form-group input {
      width: 100%; padding: 12px 15px;
      border: 1px solid #ddd; border-radius: 8px; font-size: 14px;
      transition: border-color 0.3s;
    }
    .form-group input:focus { border-color: #2455ff; outline: none; }
    .signup-btn {
      width: 100%; padding: 12px;
      border: none; border-radius: 8px;
      background: #2455ff; color: #fff;
      font-weight: 600; font-size: 16px;
      cursor: pointer; transition: background 0.3s;
      margin-bottom: 20px;
    }
    .signup-btn:hover { background: #0038cc; }
    .login-link { text-align: center; font-size: 14px; color: #666; }
    .login-link a { color: #2455ff; text-decoration: none; font-weight: 500; }
    .login-link a:hover { text-decoration: underline; }
    .error, .success {
      text-align: center; margin-bottom: 15px; padding: 10px; border-radius: 5px; font-size: 14px;
    }
    .error { color: #ff3333; background-color: #ffe6e6; }
    .success { color: #00a859; background-color: #e6ffee; }
  </style>
</head>
<body>
  <div class="register-container">
    <div class="register-header">
      <h1>Sign Up</h1>
      <p>Create your account to continue</p>
    </div>

    <?php if (!empty($error)): ?>
      <div class="error"><?= $error ?></div>
    <?php elseif (!empty($success)): ?>
      <div class="success"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <div class="form-group">
        <label>Konfirmasi Password</label>
        <input type="password" name="confirm" required>
      </div>
      <button type="submit" class="signup-btn">Sign Up</button>
    </form>

    <div class="login-link">
      Sudah punya akun? <a href="login.php">Sign In</a>
    </div>
  </div>
</body>
</html>
