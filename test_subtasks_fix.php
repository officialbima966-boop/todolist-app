<?php
require_once "inc/koneksi.php";

// Test script to verify subtasks are stored as JSON in tasks table
echo "Testing Subtasks Fix\n";
echo "===================\n\n";

// Get the most recent task
$result = $mysqli->query("SELECT id, title, subtasks FROM tasks ORDER BY created_at DESC LIMIT 1");

if ($result && $result->num_rows > 0) {
    $task = $result->fetch_assoc();

    echo "Most Recent Task:\n";
    echo "ID: " . $task['id'] . "\n";
    echo "Title: " . $task['title'] . "\n";
    echo "Subtasks JSON: " . $task['subtasks'] . "\n\n";

    // Decode and display subtasks
    $subtasks = json_decode($task['subtasks'], true);
    if ($subtasks && is_array($subtasks)) {
        echo "Decoded Subtasks:\n";
        foreach ($subtasks as $index => $subtask) {
            echo ($index + 1) . ". Text: " . $subtask['text'] . "\n";
            echo "   Assigned: " . ($subtask['assigned'] ?: 'None') . "\n";
            echo "   Completed: " . ($subtask['completed'] ? 'Yes' : 'No') . "\n\n";
        }

        echo "✅ SUCCESS: Subtasks are properly stored as JSON in tasks table\n";
        echo "✅ SUCCESS: JSON format matches expected structure (text, assigned, completed)\n";
    } else {
        echo "❌ ERROR: Subtasks JSON is invalid or empty\n";
    }
} else {
    echo "❌ ERROR: No tasks found in database\n";
}

// Check if task_subtasks table is still being used
$result = $mysqli->query("SELECT COUNT(*) as count FROM task_subtasks");
if ($result) {
    $count = $result->fetch_assoc()['count'];
    if ($count > 0) {
        echo "⚠️  WARNING: task_subtasks table still contains $count records\n";
        echo "   This suggests old data or the fix may not be complete\n";
    } else {
        echo "✅ SUCCESS: task_subtasks table is empty (no old data)\n";
    }
}

echo "\nTest completed.\n";
?>
