<?php
require_once "inc/koneksi.php";

$result = $mysqli->query('SELECT id, title, subtasks, created_at FROM tasks ORDER BY created_at DESC LIMIT 5');

echo "Recent Tasks:\n";
echo "============\n";

while ($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']} | Title: {$row['title']} | Created: {$row['created_at']} | Subtasks: " . substr($row['subtasks'], 0, 50) . "...\n";
}

echo "\nChecking for tasks with subtasks JSON:\n";
$result2 = $mysqli->query("SELECT id, title, subtasks FROM tasks WHERE subtasks != '' AND subtasks IS NOT NULL ORDER BY created_at DESC LIMIT 3");

if ($result2->num_rows > 0) {
    while ($row = $result2->fetch_assoc()) {
        echo "Task ID {$row['id']}: {$row['title']}\n";
        $subtasks = json_decode($row['subtasks'], true);
        if ($subtasks && is_array($subtasks)) {
            echo "  ✅ Valid JSON with " . count($subtasks) . " subtasks\n";
        } else {
            echo "  ❌ Invalid JSON\n";
        }
    }
} else {
    echo "No tasks found with subtasks JSON\n";
}
?>
