<?php
session_start();
require_once "../inc/koneksi.php";

// Pastikan variabel koneksi dikenali
if (isset($mysqli)) {
  $conn = $mysqli;
} else {
  die("❌ Koneksi database tidak ditemukan!");
}

// Jika sudah login, langsung arahkan ke dashboard
if (isset($_SESSION['admin'])) {
  header("Location: ../admin/dashboard.php");
  exit;
}

$error = "";

// Proses login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username']);
  $password = trim($_POST['password']);

  // DEBUG: Tampilkan input user
  error_log("Login attempt - Username: " . $username);
  error_log("Login attempt - Password: " . $password);

  $query = $conn->prepare("SELECT * FROM admin WHERE username = ? AND password = ?");
  if (!$query) {
    die("❌ SQL Error: " . $conn->error);
  }

  $query->bind_param("ss", $username, $password);
  $query->execute();
  $result = $query->get_result();

  // DEBUG: Tampilkan jumlah row
  error_log("Rows found: " . $result->num_rows);

  // DEBUG: Tampilkan semua data admin
  $debug_query = $conn->query("SELECT username, password FROM admin");
  while ($row = $debug_query->fetch_assoc()) {
    error_log("DB Username: '" . $row['username'] . "' | DB Password: '" . $row['password'] . "'");
  }

  if ($result->num_rows === 1) {
    $_SESSION['admin'] = $username;
    header("Location: ../admin/dashboard.php");
    exit;
  } else {
    $error = "❌ Username atau password salah!";
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
    </div>

    <?php if (!empty($error)): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
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
