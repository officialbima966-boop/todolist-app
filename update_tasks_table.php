<?php
require_once "inc/koneksi.php";

if (!isset($mysqli) || $mysqli->connect_error) {
    die("Koneksi database gagal: " . $mysqli->connect_error);
}

echo "Memeriksa dan memperbarui tabel tasks...\n";

// Cek apakah tabel tasks ada
$table_check = $mysqli->query("SHOW TABLES LIKE 'tasks'");
if ($table_check->num_rows == 0) {
    echo "Tabel tasks tidak ditemukan. Membuat tabel baru...\n";

    $create_table = "
        CREATE TABLE tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            start_date DATE,
            end_date DATE,
            category VARCHAR(100),
            progress INT DEFAULT 0,
            status ENUM('todo', 'progress', 'completed') DEFAULT 'todo',
            note TEXT,
            assigned_users TEXT,
            attachments TEXT,
            subtasks TEXT,
            member_subtasks TEXT,
            created_by VARCHAR(100) NOT NULL,
            tasks_total INT DEFAULT 0,
            tasks_completed INT DEFAULT 0,
            comments INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ";

    if ($mysqli->query($create_table)) {
        echo "Tabel tasks berhasil dibuat!\n";
    } else {
        die("Gagal membuat tabel tasks: " . $mysqli->error . "\n");
    }
} else {
    echo "Tabel tasks sudah ada. Memeriksa kolom yang diperlukan...\n";

    // Daftar kolom yang diperlukan
    $required_columns = [
        'start_date' => "ADD COLUMN start_date DATE AFTER title",
        'end_date' => "ADD COLUMN end_date DATE AFTER start_date",
        'attachments' => "ADD COLUMN attachments TEXT AFTER assigned_users",
        'subtasks' => "ADD COLUMN subtasks TEXT AFTER attachments",
        'member_subtasks' => "ADD COLUMN member_subtasks TEXT AFTER subtasks",
        'tasks_total' => "ADD COLUMN tasks_total INT DEFAULT 0 AFTER created_by",
        'tasks_completed' => "ADD COLUMN tasks_completed INT DEFAULT 0 AFTER tasks_total",
        'comments' => "ADD COLUMN comments INT DEFAULT 0 AFTER tasks_completed"
    ];

    // Cek kolom yang ada
    $existing_columns = [];
    $columns_result = $mysqli->query("DESCRIBE tasks");
    while ($row = $columns_result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }

    // Tambahkan kolom yang belum ada
    foreach ($required_columns as $column => $alter_sql) {
        if (!in_array($column, $existing_columns)) {
            $alter_query = "ALTER TABLE tasks $alter_sql";
            if ($mysqli->query($alter_query)) {
                echo "Kolom $column berhasil ditambahkan!\n";
            } else {
                echo "Gagal menambahkan kolom $column: " . $mysqli->error . "\n";
            }
        } else {
            echo "Kolom $column sudah ada.\n";
        }
    }

    // Update status enum jika perlu
    $status_check = $mysqli->query("DESCRIBE tasks status");
    if ($status_check) {
        $status_row = $status_check->fetch_assoc();
        if (strpos($status_row['Type'], 'in_progress') !== false) {
            // Update enum values
            $alter_status = "ALTER TABLE tasks MODIFY COLUMN status ENUM('todo', 'progress', 'completed') DEFAULT 'todo'";
            if ($mysqli->query($alter_status)) {
                echo "Kolom status berhasil diperbarui!\n";
            } else {
                echo "Gagal memperbarui kolom status: " . $mysqli->error . "\n";
            }
        }
    }
}

echo "Pembaruan tabel tasks selesai!\n";

// Cek apakah tabel task_comments ada
$comments_table_check = $mysqli->query("SHOW TABLES LIKE 'task_comments'");
if ($comments_table_check->num_rows == 0) {
    echo "Membuat tabel task_comments...\n";

    $create_comments_table = "
        CREATE TABLE task_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )
    ";

    if ($mysqli->query($create_comments_table)) {
        echo "Tabel task_comments berhasil dibuat!\n";
    } else {
        echo "Gagal membuat tabel task_comments: " . $mysqli->error . "\n";
    }
} else {
    echo "Tabel task_comments sudah ada.\n";
}

echo "Semua pembaruan database selesai!\n";
?>
