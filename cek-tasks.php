<?php
// cek-tasks.php
echo "<h1>Cek Tabel Tasks</h1>";

$koneksi = mysqli_connect("localhost", "root", "", "coba");

if (!$koneksi) {
    die("❌ Koneksi gagal");
}

echo "✅ Terhubung ke database: coba<br><br>";

// Cek tabel tasks
$query = "SELECT COUNT(*) as total FROM tasks";
$hasil = mysqli_query($koneksi, $query);

if ($hasil) {
    $data = mysqli_fetch_assoc($hasil);
    echo "📊 Jumlah data di tabel 'tasks': " . $data['total'] . "<br><br>";
    
    if ($data['total'] > 0) {
        echo "<h3>📋 Data di tabel tasks:</h3>";
        
        $query2 = "SELECT * FROM tasks LIMIT 10";
        $hasil2 = mysqli_query($koneksi, $query2);
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Judul</th><th>Kategori</th></tr>";
        
        while ($row = mysqli_fetch_assoc($hasil2)) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['judul'] ?? $row['title'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['kategori'] ?? $row['category'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
} else {
    echo "❌ Error: " . mysqli_error($koneksi);
}

mysqli_close($koneksi);
?>