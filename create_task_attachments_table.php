<?php
// Script to create the missing task_attachments table
$conn = new mysqli("localhost", "root", "", "coba");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h1>Creating task_attachments table...</h1>";

// Create task_attachments table
$createTableSQL = "
CREATE TABLE IF NOT EXISTS task_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    filesize INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
)";

if ($conn->query($createTableSQL)) {
    echo "<p style='color: green;'>✅ task_attachments table created successfully!</p>";
} else {
    echo "<p style='color: red;'>❌ Error creating table: " . $conn->error . "</p>";
}

$conn->close();
?>
