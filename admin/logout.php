<?php
session_start();

// Pastikan hanya admin yang bisa akses
if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Ambil username untuk ditampilkan
$username = $_SESSION['admin'];

// Jika klik tombol logout
if (isset($_GET['logout']) && $_GET['logout'] === 'yes') {
    // Hapus session
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();

    // Langsung redirect ke login
    header("Location: ../auth/login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logout | Todolist</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<style>
body {
    font-family: 'Poppins', sans-serif;
    background: #f5f7fa;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    margin: 0;
}
.logout-box {
    background: #fff;
    padding: 40px 30px;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    text-align: center;
    max-width: 400px;
    width: 100%;
}
.logout-box .icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #ff6b6b;
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 0 auto 20px;
    font-size: 24px;
    color: #fff;
}
.logout-box h2 {
    margin-bottom: 10px;
}
.logout-box p {
    color: #555;
    font-size: 0.95rem;
    margin-bottom: 25px;
}
.logout-box .btn {
    padding: 12px 25px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    margin: 0 5px;
    font-size: 0.95rem;
}
.btn-cancel {
    background: #ccc;
    color: #333;
}
.btn-logout {
    background: #ff6b6b;
    color: #fff;
}
.btn-logout:hover {
    background: #ff5252;
}
.btn-cancel:hover {
    background: #bbb;
}
</style>
</head>
<body>

<div class="logout-box">
    <div class="icon">&#x21B7;</div>
    <h2>Konfirmasi Logout</h2>
    <p>Anda akan keluar dari akun Todolist. Pastikan semua pekerjaan Anda sudah disimpan.</p>
    <div>
        <button class="btn btn-cancel" onclick="window.location.href='dashboard.php'">Batal</button>
        <a href="?logout=yes" class="btn btn-logout">Logout</a>
    </div>
</div>

</body>
</html>
