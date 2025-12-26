<?php
require_once "inc/koneksi.php";

if (!isset($mysqli) || $mysqli->connect_error) {
    die("Koneksi database gagal: " . $mysqli->connect_error);
}

// Create admin table
$admin_table_sql = "
    CREATE TABLE IF NOT EXISTS admin (
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

if ($mysqli->query($admin_table_sql)) {
    echo "Tabel admin berhasil dibuat\n";
} else {
    die("Gagal membuat tabel admin: " . $mysqli->error);
}

// Create users table
$users_table_sql = "
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        nama VARCHAR(100) NOT NULL,
        email VARCHAR(100),
        no_hp VARCHAR(20),
        foto VARCHAR(255),
        role ENUM('user', 'admin') DEFAULT 'user',
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
";

if ($mysqli->query($users_table_sql)) {
    echo "Tabel users berhasil dibuat\n";
} else {
    die("Gagal membuat tabel users: " . $mysqli->error);
}

// Insert default admin user
$default_admin_username = 'admin';
$default_admin_password = password_hash('admin123', PASSWORD_DEFAULT);
$default_admin_nama = 'Administrator';

$check_admin = $mysqli->query("SELECT id FROM admin WHERE username = '$default_admin_username'");
if ($check_admin->num_rows == 0) {
    $insert_admin = "INSERT INTO admin (username, password, nama) VALUES ('$default_admin_username', '$default_admin_password', '$default_admin_nama')";
    if ($mysqli->query($insert_admin)) {
        echo "Default admin user berhasil dibuat\n";
    } else {
        echo "Gagal membuat default admin user: " . $mysqli->error . "\n";
    }
} else {
    echo "Default admin user sudah ada\n";
}

// Insert default user
$default_user_username = 'user';
$default_user_password = password_hash('user123', PASSWORD_DEFAULT);
$default_user_nama = 'User Biasa';

$check_user = $mysqli->query("SELECT id FROM users WHERE username = '$default_user_username'");
if ($check_user->num_rows == 0) {
    $insert_user = "INSERT INTO users (username, password, nama, role, status) VALUES ('$default_user_username', '$default_user_password', '$default_user_nama', 'user', 'active')";
    if ($mysqli->query($insert_user)) {
        echo "Default user berhasil dibuat\n";
    } else {
        echo "Gagal membuat default user: " . $mysqli->error . "\n";
    }
} else {
    echo "Default user sudah ada\n";
}

echo "Setup database selesai!\n";
?>
