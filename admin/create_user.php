<?php
// create_user.php - File sementara untuk membuat user
session_start();
require_once "../inc/koneksi.php";

// Buat user wotan jika belum ada
$username = "wotan";
$password = password_hash("password123", PASSWORD_DEFAULT);
$nama = "Wotan User";
$email = "wotan@example.com";
$no_hp = "081234567890";

$check_sql = "SELECT * FROM users WHERE username = ?";
$check_stmt = $mysqli->prepare($check_sql);
$check_stmt->bind_param("s", $username);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result->num_rows == 0) {
    // User belum ada, buat baru
    $insert_sql = "INSERT INTO users (username, password, nama, email, no_hp, level) VALUES (?, ?, ?, ?, ?, 'admin')";
    $insert_stmt = $mysqli->prepare($insert_sql);
    $insert_stmt->bind_param("sssss", $username, $password, $nama, $email, $no_hp);
    
    if ($insert_stmt->execute()) {
        echo "User wotan berhasil dibuat!<br>";
        echo "Username: wotan<br>";
        echo "Password: password123<br>";
        echo "<a href='login.php'>Login sekarang</a>";
    } else {
        echo "Gagal membuat user: " . $insert_stmt->error;
    }
    $insert_stmt->close();
} else {
    echo "User wotan sudah ada di database.";
}

$check_stmt->close();
?>