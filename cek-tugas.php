<?php
// cek-tugas.php - VERSI FIXED
echo "<h1>Cek Database Tugas - FIXED</h1>";
echo "Database yang benar: <strong>coba</strong><br><br>";

$koneksi = mysqli_connect("localhost", "root", "", "coba"); // ← GANTI "cdba" jadi "coba"

if (!$koneksi) {
    die("❌ Koneksi gagal: " . mysqli_connect_error());
}

echo "✅ Terhubung ke database: <strong>coba</strong><br>";

// Hitung data di tabel tugas
$query = "SELECT COUNT(*) as total FROM tugas";
$hasil = mysqli_query($koneksi, $query);
$data = mysqli_fetch_assoc($hasil);

echo "📊 Jumlah data di tabel 'tugas': " . $data['total'] . "<br><br>";

if ($data['total'] > 0) {
    echo "<h3>📋 Data Tugas:</h3>";
    
    $query2 = "SELECT * FROM tugas ORDER BY id LIMIT 10";
    $hasil2 = mysqli_query($koneksi, $query2);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Judul</th><th>Kategori</th><th>Status</th><th>Deadline</th></tr>";
    
    while ($row = mysqli_fetch_assoc($hasil2)) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['judul']) . "</td>";
        echo "<td>" . htmlspecialchars($row['kategori']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . $row['deadline'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "⚠️ Tabel 'tugas' ada tapi kosong.<br>";
    echo "Silakan tambah data melalui aplikasi atau phpMyAdmin.";
}

mysqli_close($koneksi);
?>