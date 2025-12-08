<?php
// check_tasks_table.php
require_once "../inc/koneksi.php";

// Cek apakah tabel tasks ada
$table_check = $mysqli->query("SHOW TABLES LIKE 'tasks'");
if ($table_check->num_rows == 0) {
    // Buat tabel tasks
    $create_table = "
        CREATE TABLE tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100),
            progress INT DEFAULT 0,
            status ENUM('todo', 'in_progress', 'completed') DEFAULT 'todo',
            note TEXT,
            assigned_users TEXT,
            created_by VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ";
    
    if ($mysqli->query($create_table)) {
        echo "Tabel tasks berhasil dibuat!<br>";
        
        // Insert sample data
        $sample_data = [
            ['title' => 'Setup Project', 'category' => 'Development', 'progress' => 100, 'status' => 'completed', 'note' => 'Setup initial project structure', 'created_by' => 'wotan'],
            ['title' => 'Design Dashboard', 'category' => 'Design', 'progress' => 75, 'status' => 'in_progress', 'note' => 'Create dashboard UI design', 'created_by' => 'wotan'],
            ['title' => 'Database Schema', 'category' => 'Development', 'progress' => 50, 'status' => 'in_progress', 'note' => 'Design database structure', 'created_by' => 'wotan'],
            ['title' => 'User Authentication', 'category' => 'Development', 'progress' => 25, 'status' => 'todo', 'note' => 'Implement login system', 'created_by' => 'wotan']
        ];
        
        foreach ($sample_data as $data) {
            $insert = $mysqli->prepare("INSERT INTO tasks (title, category, progress, status, note, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $insert->bind_param("ssisss", $data['title'], $data['category'], $data['progress'], $data['status'], $data['note'], $data['created_by']);
            $insert->execute();
        }
        
        echo "Sample data berhasil ditambahkan!<br>";
    } else {
        echo "Gagal membuat tabel tasks: " . $mysqli->error . "<br>";
    }
} else {
    echo "Tabel tasks sudah ada!<br>";
}

echo "<a href='dashboard.php'>Kembali ke Dashboard</a>";
?>