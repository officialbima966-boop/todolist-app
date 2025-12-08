<?php
// set_password.php - untuk reset password (hapus setelah selesai)
require_once __DIR__ . '/inc/koneksi.php'; // ← pastikan ini sesuai path aslinya

$username = 'admin';
$new = 'bima123'; // password baru

$hash = password_hash($new, PASSWORD_DEFAULT);

$stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE username = ?");
$stmt->bind_param("ss", $hash, $username);

if ($stmt->execute()) {
    echo "✅ Password untuk user '$username' berhasil diubah jadi: <b>$new</b><br>";
    echo "🔐 Hash tersimpan: <b>$hash</b><br><br>";
    echo "<small>Hapus file ini setelah selesai demi keamanan.</small>";
} else {
    echo "❌ Gagal update: " . $mysqli->error;
}
