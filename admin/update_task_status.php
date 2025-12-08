<?php
session_start();
include '../inc/koneksi.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_POST) {
    $subtask_id = $_POST['subtask_id'];
    $status = $_POST['status'];
    $user_id = $_SESSION['user_id'];

    // Verify that the user is assigned to this subtask
    $verify = mysqli_query($conn, 
        "SELECT * FROM subtasks WHERE id = $subtask_id AND assigned_to = $user_id"
    );

    if (mysqli_num_rows($verify) == 1) {
        mysqli_query($conn, 
            "UPDATE subtasks SET status = '$status' WHERE id = $subtask_id"
        );
        $_SESSION['success'] = "Task status updated successfully!";
    } else {
        $_SESSION['error'] = "You are not assigned to this task!";
    }
}

header("Location: tasks.php");
exit();
?>