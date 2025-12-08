<?php
// fix-kolom.php
$conn = new mysqli("localhost", "root", "", "coba");

echo "<h1>🔧 FIX KOLOM TABEL TUGAS</h1>";

// 1. Cek kolom yang salah
$result = $conn->query("DESCRIBE tugas");
echo "<h3>Struktur tabel tugas saat ini:</h3>";
echo "<table border='1'><tr><th>Field</th><th>Type</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td></tr>";
}
echo "</table>";

// 2. Fix kolom jika ada yang salah
$fix_queries = [
    "ALTER TABLE tugas CHANGE COLUMN jubile judul VARCHAR(255)",
    "ALTER TABLE tugas CHANGE COLUMN judid judul VARCHAR(255)", 
    "ALTER TABLE tugas CHANGE COLUMN tabepgit kategori VARCHAR(100)",
    "ALTER TABLE tugas CHANGE COLUMN custom catatan TEXT"
];

foreach ($fix_queries as $query) {
    if ($conn->query($query)) {
        echo "✅ Query executed<br>";
    }
}

// 3. Cek akhir
echo "<h3>Struktur setelah diperbaiki:</h3>";
$result = $conn->query("DESCRIBE tugas");
echo "<table border='1'><tr><th>Field</th><th>Type</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td></tr>";
}
echo "</table>";

// 4. Test query aplikasi
echo "<h3>Test query aplikasi:</h3>";
$test = $conn->query("SELECT id, judul, kategori, status FROM tugas");
if ($test) {
    echo "✅ Query SELECT berhasil!<br>";
    echo "Data: " . $test->num_rows . " rows<br>";
} else {
    echo "❌ Error: " . $conn->error . "<br>";
}

$conn->close();
?>