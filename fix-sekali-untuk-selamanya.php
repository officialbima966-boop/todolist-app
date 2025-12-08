<?php
// File: C:\xamppp\htdocs\htdocs\coba\fix.php
$conn = new mysqli("localhost", "root", "", "coba");

echo "<h1>🔄 FIX DATABASE TUGAS</h1>";

// OPTION 1: Copy data dengan mapping
$sql = "INSERT INTO tugas (judul, kategori, progress, status, catatan, dhugaskan, deadline)
        SELECT title, category, progress, status, note, created_by, end_date FROM tasks
        WHERE NOT EXISTS (SELECT 1 FROM tugas WHERE tugas.id = tasks.id)";

if ($conn->query($sql)) {
    echo "✅ Data berhasil dicopy<br>";
} else {
    echo "❌ Error: " . $conn->error . "<br>";
    
    // OPTION 2: Drop and create
    echo "<h3>Mencoba cara alternatif...</h3>";
    
    $conn->query("DROP TABLE IF EXISTS tugas_temp");
    $conn->query("CREATE TABLE tugas_temp AS SELECT * FROM tasks");
    $conn->query("RENAME TABLE tugas TO tugas_backup, tugas_temp TO tugas");
    
    echo "✅ Tabel tugas dibuat ulang dari tasks<br>";
}

// Cek hasil
$result = $conn->query("SELECT COUNT(*) as total FROM tugas");
$row = $result->fetch_assoc();
echo "<h2>📊 HASIL: {$row['total']} data di tabel tugas</h2>";

$conn->close();
?>