<?php
$host = 'localhost';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=coba;charset=utf8mb4", $username, $password);
    echo "Koneksi ke database 'coba' BERHASIL!<br>";
    
    // Cek tabel users
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tabel di database coba: " . implode(', ', $tables);
    
} catch (PDOException $e) {
    echo "Gagal ke 'coba': " . $e->getMessage() . "<br>";
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=penerus;charset=utf8mb4", $username, $password);
        echo "Koneksi ke database 'penerus' BERHASIL!<br>";
        
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Tabel di database penerus: " . implode(', ', $tables);
        
    } catch (PDOException $e2) {
        echo "Gagal ke 'penerus': " . $e2->getMessage();
    }
}
?>