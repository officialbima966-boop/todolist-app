<?php
// Tambahkan ini di bagian paling atas untuk menangkap error
error_reporting(E_ALL);
ini_set('display_errors', 0); // Nonaktifkan display error di browser
ini_set('log_errors', 1); // Aktifkan logging error

session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

$username = $_SESSION['user'];

// Ambil data user
$userQuery = $mysqli->prepare("SELECT * FROM users WHERE username = ?");
$userQuery->bind_param("s", $username);
$userQuery->execute();
$result = $userQuery->get_result();

if ($result->num_rows == 0) {
    session_destroy();
    header("Location: ../auth/login.php?error=user_not_found");
    exit;
}

$userData = $result->fetch_assoc();

// Redirect jika admin
if ($userData['role'] == 'admin') {
    header("Location: ../admin/dashboard.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Mulai output buffering untuk menangkap semua output
    ob_start();

    // Set header JSON dulu
    header('Content-Type: application/json');

    if ($_POST['action'] === 'add_task') {
        $title = $mysqli->real_escape_string($_POST['title']);
        $startDate = $mysqli->real_escape_string($_POST['startDate']);
        $endDate = $mysqli->real_escape_string($_POST['endDate']);
        $note = $mysqli->real_escape_string($_POST['note']);
        $assignedUsers = $mysqli->real_escape_string($_POST['assignedUsers']);
        $subtasks = isset($_POST['subtasks']) ? json_decode($_POST['subtasks'], true) : [];
        $subtaskAssignments = isset($_POST['subtaskAssignments']) ? json_decode($_POST['subtaskAssignments'], true) : [];

        // NEW: Organize subtasks by member
        $memberSubtasks = [];
        foreach ($subtasks as $index => $subtaskText) {
            $assignedTo = isset($subtaskAssignments[$index]) ? $mysqli->real_escape_string($subtaskAssignments[$index]) : '';
            if ($assignedTo) {
                if (!isset($memberSubtasks[$assignedTo])) {
                    $memberSubtasks[$assignedTo] = [];
                }
                $memberSubtasks[$assignedTo][] = [
                    'text' => $mysqli->real_escape_string($subtaskText),
                    'assigned' => $assignedTo,
                    'completed' => false,
                    'completed_by' => '',
                    'completed_at' => ''
                ];
            }
        }

        // Handle file upload
        $attachments = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploadDir = "../uploads/tasks/";

            // Create directory if not exists
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $tempName = $_FILES['attachments']['tmp_name'][$key];
                    $fileExtension = pathinfo($name, PATHINFO_EXTENSION);
                    $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
                    $filePath = $uploadDir . $fileName;

                    if (move_uploaded_file($tempName, $filePath)) {
                        $attachments[] = [
                            'name' => $name,
                            'path' => 'uploads/tasks/' . $fileName,
                            'size' => $_FILES['attachments']['size'][$key]
                        ];
                    }
                }
            }
        }

        $attachmentsJson = !empty($attachments) ? json_encode($attachments) : '';

        // NEW: Create subtasks with merged same text and assigned as array
        $subtasksArray = [];
        foreach ($subtasks as $index => $subtaskText) {
            $assignedTo = isset($subtaskAssignments[$index]) ? $mysqli->real_escape_string($subtaskAssignments[$index]) : '';
            if ($assignedTo) {
                $text = $mysqli->real_escape_string($subtaskText);
                // Find if subtask with same text exists
                $existingIndex = -1;
                foreach ($subtasksArray as $i => $existingSubtask) {
                    if ($existingSubtask['text'] === $text) {
                        $existingIndex = $i;
                        break;
                    }
                }
                if ($existingIndex >= 0) {
                    // Check if user already assigned
                    $alreadyAssigned = false;
                    foreach ($subtasksArray[$existingIndex]['assigned'] as $assignment) {
                        if ($assignment['user'] === $assignedTo) {
                            $alreadyAssigned = true;
                            break;
                        }
                    }
                    if (!$alreadyAssigned) {
                        $subtasksArray[$existingIndex]['assigned'][] = [
                            'user' => $assignedTo,
                            'completed' => false,
                            'completed_by' => '',
                            'completed_at' => ''
                        ];
                    }
                } else {
                    // Create new subtask
                    $subtasksArray[] = [
                        'text' => $text,
                        'assigned' => [
                            [
                                'user' => $assignedTo,
                                'completed' => false,
                                'completed_by' => '',
                                'completed_at' => ''
                            ]
                        ]
                    ];
                }
            }
        }
        $subtasksJson = json_encode($subtasksArray);
        $subtasksJson = $mysqli->real_escape_string($subtasksJson);

        // Store member subtasks separately
        $memberSubtasksJson = json_encode($memberSubtasks);
        $memberSubtasksJson = $mysqli->real_escape_string($memberSubtasksJson);

        // Insert main task with both subtask formats
        $sql = "INSERT INTO tasks (title, start_date, end_date, note, assigned_users, attachments, subtasks, member_subtasks, created_by, tasks_total, tasks_completed)
                VALUES ('$title', '$startDate', '$endDate', '$note', '$assignedUsers', '$attachmentsJson', '$subtasksJson', '$memberSubtasksJson', '$username', " . count($subtasks) . ", 0)";

        if ($mysqli->query($sql)) {
            $taskId = $mysqli->insert_id;
            $response = ['success' => true, 'message' => 'Task berhasil ditambahkan dan dibagikan ke teman!', 'taskId' => $taskId];
        } else {
            $response = ['success' => false, 'message' => 'Gagal menambahkan task'];
        }

        $output = ob_get_clean(); // Ambil semua output buffer
        // Pastikan hanya mengirim JSON, hapus jika ada output lain
        if (!empty($output)) {
            // Ada output tambahan yang tidak diinginkan
            echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
        } else {
            // Output aman, kirim response JSON
            echo json_encode($response);
        }
        exit;
    }

    if ($_POST['action'] === 'get_task_detail') {
        $taskId = (int)$_POST['taskId'];
        
        // Ambil data tugas
        $stmt = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->bind_param("i", $taskId);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();

        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan']);
            
            $output = ob_get_clean();
            if (json_decode($output) === null && !empty($output)) {
                echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
            }
            exit;
        }

        // Ambil komentar
        $comments = [];
        $commentQuery = $mysqli->query("SELECT *, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at_formatted FROM task_comments WHERE task_id = $taskId ORDER BY created_at DESC");
        while ($row = $commentQuery->fetch_assoc()) {
            $createdAt = new DateTime($row['created_at_formatted']);
            $now = new DateTime();
            $interval = $now->diff($createdAt);

            $timeAgo = '';
            if ($interval->y > 0) {
                $timeAgo = $interval->y . ' tahun lalu';
            } elseif ($interval->m > 0) {
                $timeAgo = $interval->m . ' bulan lalu';
            } elseif ($interval->d > 0) {
                $timeAgo = $interval->d . ' hari lalu';
            } elseif ($interval->h > 0) {
                $timeAgo = $interval->h . ' jam lalu';
            } elseif ($interval->i > 0) {
                $timeAgo = $interval->i . ' menit lalu';
            } else {
                $timeAgo = 'baru saja';
            }

            $row['time_ago'] = $timeAgo;
            $comments[] = $row;
        }

        // NEW: Parse member subtasks
        $memberSubtasks = [];
        if (!empty($task['member_subtasks'])) {
            $memberSubtasksData = json_decode($task['member_subtasks'], true);
            if ($memberSubtasksData !== null && is_array($memberSubtasksData)) {
                $memberSubtasks = $memberSubtasksData;
            }
        }

        // Parse subtasks (old format for compatibility)
        $subtasks = [];
        if (!empty($task['subtasks'])) {
            $subtasksData = json_decode($task['subtasks'], true);
            if ($subtasksData === null) {
                $subtasksArray = explode(',', $task['subtasks']);
                foreach ($subtasksArray as $text) {
                    $text = trim($text);
                    if (!empty($text)) {
                        $subtasks[] = [
                            'text' => $text,
                            'assigned' => '',
                            'completed' => false,
                            'completed_by' => '',
                            'completed_at' => ''
                        ];
                    }
                }
            } elseif (is_array($subtasksData)) {
                $subtasks = $subtasksData;
            }
        }

        // If member_subtasks is empty but subtasks exists, convert to member_subtasks format
        if (empty($memberSubtasks) && !empty($subtasks)) {
            foreach ($subtasks as $subtask) {
                if (!empty($subtask['assigned'])) {
                    if (!isset($memberSubtasks[$subtask['assigned']])) {
                        $memberSubtasks[$subtask['assigned']] = [];
                    }
                    $memberSubtasks[$subtask['assigned']][] = $subtask;
                }
            }
        }

        // Parse attachments
        $attachments = [];
        if (!empty($task['attachments'])) {
            $attachmentsData = json_decode($task['attachments'], true);
            if (is_array($attachmentsData) && count($attachmentsData) > 0) {
                $attachments = $attachmentsData;
            } else {
                $attachmentsArray = explode(',', $task['attachments']);
                foreach ($attachmentsArray as $filename) {
                    $filename = trim($filename);
                    if (!empty($filename)) {
                        $attachments[] = [
                            'name' => $filename,
                            'path' => 'uploads/tasks/' . $filename,
                            'size' => 0
                        ];
                    }
                }
            }
        }

        // Get assigned users
        $assignedUsers = [];
        if (!empty($task['assigned_users'])) {
            $assignedUsers = explode(',', $task['assigned_users']);
        }

        echo json_encode([
            'success' => true,
            'task' => $task,
            'subtasks' => $subtasks,
            'memberSubtasks' => $memberSubtasks,
            'comments' => $comments,
            'attachments' => $attachments,
            'assignedUsers' => $assignedUsers,
            'currentUser' => $username
        ]);
        
        $output = ob_get_clean();
        if (json_decode($output) === null && !empty($output)) {
            echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
        }
        exit;
    }

    if ($_POST['action'] === 'update_progress') {
        $taskId = (int)$_POST['taskId'];
        $progress = (int)$_POST['progress'];
        
        $category = 'Belum Dijalankan';
        $status = 'todo';
        
        if ($progress > 0 && $progress < 100) {
            $category = 'Sedang Berjalan';
            $status = 'progress';
        } elseif ($progress === 100) {
            $category = 'Selesai';
            $status = 'completed';
        }
        
        $sql = "UPDATE tasks SET progress = $progress, category = '$category', status = '$status' WHERE id = $taskId";
        
        if ($mysqli->query($sql)) {
            echo json_encode(['success' => true, 'progress' => $progress, 'category' => $category, 'status' => $status]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        
        $output = ob_get_clean();
        if (json_decode($output) === null && !empty($output)) {
            echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'add_comment') {
        $taskId = (int)$_POST['taskId'];
        $comment = $mysqli->real_escape_string($_POST['comment']);

        $sql = "INSERT INTO task_comments (task_id, username, comment) VALUES ($taskId, '$username', '$comment')";

        if ($mysqli->query($sql)) {
            // Update comment count in tasks table
            $mysqli->query("UPDATE tasks SET comments = comments + 1 WHERE id = $taskId");

            // Get the newly added comment with time
            $commentId = $mysqli->insert_id;
            $commentQuery = $mysqli->query("SELECT *, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at_formatted FROM task_comments WHERE id = $commentId");
            $commentData = $commentQuery->fetch_assoc();

            // Format time ago
            $createdAt = new DateTime($commentData['created_at_formatted']);
            $now = new DateTime();
            $interval = $now->diff($createdAt);

            $timeAgo = '';
            if ($interval->y > 0) {
                $timeAgo = $interval->y . ' tahun lalu';
            } elseif ($interval->m > 0) {
                $timeAgo = $interval->m . ' bulan lalu';
            } elseif ($interval->d > 0) {
                $timeAgo = $interval->d . ' hari lalu';
            } elseif ($interval->h > 0) {
                $timeAgo = $interval->h . ' jam lalu';
            } elseif ($interval->i > 0) {
                $timeAgo = $interval->i . ' menit lalu';
            } else {
                $timeAgo = 'baru saja';
            }

            $commentData['time_ago'] = $timeAgo;

            echo json_encode(['success' => true, 'comment' => $commentData]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menambahkan komentar']);
        }
        
        $output = ob_get_clean();
        if (json_decode($output) === null && !empty($output)) {
            echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'toggle_subtask') {
        $index = (int)$_POST['index'];
        $taskId = (int)$_POST['taskId'];
        $currentUser = $mysqli->real_escape_string($_POST['currentUser']);

        // Get member subtasks first (new format)
        $taskQuery = $mysqli->query("SELECT member_subtasks, subtasks FROM tasks WHERE id = $taskId");
        $taskData = $taskQuery->fetch_assoc();
        $memberSubtasks = json_decode($taskData['member_subtasks'], true);
        
        // If no member subtasks, fall back to old format
        $useMemberFormat = false;
        if ($memberSubtasks !== null && is_array($memberSubtasks) && !empty($memberSubtasks)) {
            $useMemberFormat = true;
        }

        if ($useMemberFormat) {
            // Find which member this subtask belongs to
            $found = false;
            foreach ($memberSubtasks as $member => &$subtasks) {
                if (isset($subtasks[$index])) {
                    // Toggle completed status
                    $isCompleted = !$subtasks[$index]['completed'];
                    $subtasks[$index]['completed'] = $isCompleted;
                    
                    if ($isCompleted) {
                        $subtasks[$index]['completed_by'] = $currentUser;
                        $subtasks[$index]['completed_at'] = date('Y-m-d H:i:s');
                    } else {
                        $subtasks[$index]['completed_by'] = '';
                        $subtasks[$index]['completed_at'] = '';
                    }
                    $found = true;
                    break;
                }
            }
            
            if ($found) {
                // Save member subtasks back to database
                $memberSubtasksJson = json_encode($memberSubtasks);
                $memberSubtasksEncoded = $mysqli->real_escape_string($memberSubtasksJson);
                $mysqli->query("UPDATE tasks SET member_subtasks = '$memberSubtasksEncoded' WHERE id = $taskId");
                
                // Calculate overall progress
                $total = 0;
                $completed = 0;
                
                foreach ($memberSubtasks as $memberTasks) {
                    foreach ($memberTasks as $subtask) {
                        $total++;
                        if ($subtask['completed']) {
                            $completed++;
                        }
                    }
                }
            }
        } else {
            // Old format handling (existing code)
            $subtasks = json_decode($taskData['subtasks'], true);

            // Jika decode gagal, format ulang
            if ($subtasks === null) {
                $subtasksArray = explode(',', $taskData['subtasks']);
                $subtasks = [];
                foreach ($subtasksArray as $text) {
                    $text = trim($text);
                    if (!empty($text)) {
                        $subtasks[] = [
                            'text' => $text,
                            'assigned' => '',
                            'completed' => false,
                            'completed_by' => '',
                            'completed_at' => ''
                        ];
                    }
                }
            }

            if (!is_array($subtasks)) {
                $subtasks = [];
            }

            if (isset($subtasks[$index])) {
                // Toggle completed status
                $isCompleted = !$subtasks[$index]['completed'];
                $subtasks[$index]['completed'] = $isCompleted;
                
                if ($isCompleted) {
                    $subtasks[$index]['completed_by'] = $currentUser;
                    $subtasks[$index]['completed_at'] = date('Y-m-d H:i:s');
                } else {
                    $subtasks[$index]['completed_by'] = '';
                    $subtasks[$index]['completed_at'] = '';
                }

                // Save back to database
                $subtasksJson = json_encode($subtasks);
                $subtasksEncoded = $mysqli->real_escape_string($subtasksJson);
                $mysqli->query("UPDATE tasks SET subtasks = '$subtasksEncoded' WHERE id = $taskId");

                // Calculate progress
                $total = count($subtasks);
                $completed = count(array_filter($subtasks, function($s) {
                    return isset($s['completed']) ? $s['completed'] : false;
                }));
            }
        }

        $progress = $total > 0 ? round(($completed / $total) * 100) : 0;

        $category = 'Belum Dijalankan';
        $status = 'todo';

        if ($progress > 0 && $progress < 100) {
            $category = 'Sedang Berjalan';
            $status = 'progress';
        } elseif ($progress === 100) {
            $category = 'Selesai';
            $status = 'completed';
        }

        $mysqli->query("UPDATE tasks SET progress = $progress, category = '$category', status = '$status', tasks_completed = $completed, tasks_total = $total WHERE id = $taskId");

        if ($useMemberFormat) {
            echo json_encode([
                'success' => true,
                'progress' => $progress,
                'completed' => $completed,
                'total' => $total,
                'memberSubtasks' => $memberSubtasks,
                'useMemberFormat' => true
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'progress' => $progress,
                'completed' => $completed,
                'total' => $total,
                'subtasks' => $subtasks,
                'useMemberFormat' => false
            ]);
        }
        
        $output = ob_get_clean();
        if (json_decode($output) === null && !empty($output)) {
            echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
        }
        exit;
    }

    if ($_POST['action'] === 'upload_attachments') {
        $taskId = (int)$_POST['taskId'];
        $uploadDir = '../uploads/tasks/';

        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $uploadedFiles = [];
        $errors = [];

        if (isset($_FILES['files'])) {
            $files = $_FILES['files'];

            // Handle single file upload (files is not an array)
            if (!is_array($files['name'])) {
                $files = [
                    'name' => [$files['name']],
                    'type' => [$files['type']],
                    'tmp_name' => [$files['tmp_name']],
                    'error' => [$files['error']],
                    'size' => [$files['size']]
                ];
            }

            for ($i = 0; $i < count($files['name']); $i++) {
                $fileName = $files['name'][$i];
                $fileTmp = $files['tmp_name'][$i];
                $fileError = $files['error'][$i];
                $fileSize = $files['size'][$i];

                if ($fileError === UPLOAD_ERR_OK) {
                    // Generate unique filename
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $uniqueName = uniqid() . '_' . time() . '.' . $fileExtension;
                    $filePath = $uploadDir . $uniqueName;

                    // Allowed file types
                    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                    if (!in_array($fileExtension, $allowedTypes)) {
                        $errors[] = "File type not allowed: $fileName";
                        continue;
                    }

                    // Max file size (10MB)
                    if ($fileSize > 10 * 1024 * 1024) {
                        $errors[] = "File too large: $fileName";
                        continue;
                    }

                    if (move_uploaded_file($fileTmp, $filePath)) {
                        $uploadedFiles[] = [
                            'name' => $fileName,
                            'path' => 'uploads/tasks/' . $uniqueName,
                            'size' => $fileSize
                        ];
                    } else {
                        $errors[] = "Failed to upload: $fileName";
                    }
                } else {
                    $errors[] = "Upload error for: $fileName";
                }
            }
        }

        if (!empty($uploadedFiles)) {
            // Get current attachments
            $taskQuery = $mysqli->query("SELECT attachments FROM tasks WHERE id = $taskId");
            $taskData = $taskQuery->fetch_assoc();
            $currentAttachments = [];

            if (!empty($taskData['attachments'])) {
                $attachmentsData = json_decode($taskData['attachments'], true);

                if (is_array($attachmentsData) && count($attachmentsData) > 0) {
                    // JSON format - data lengkap
                    $currentAttachments = $attachmentsData;
                } else {
                    // Format lama - comma separated, convert to array format
                    $attachments = explode(',', $taskData['attachments']);
                    $currentAttachments = [];
                    foreach ($attachments as $filename) {
                        $filename = trim($filename);
                        if (!empty($filename)) {
                            $currentAttachments[] = [
                                'name' => $filename,
                                'path' => 'uploads/tasks/' . $filename,
                                'size' => 0
                            ];
                        }
                    }
                }
            }

            // Merge new attachments
            $allAttachments = array_merge($currentAttachments, $uploadedFiles);
            $attachmentsJson = json_encode($allAttachments);
            $attachmentsEncoded = $mysqli->real_escape_string($attachmentsJson);

            $mysqli->query("UPDATE tasks SET attachments = '$attachmentsEncoded' WHERE id = $taskId");
        }

        echo json_encode([
            'success' => empty($errors),
            'uploaded' => $uploadedFiles,
            'errors' => $errors
        ]);
        
        $output = ob_get_clean();
        if (json_decode($output) === null && !empty($output)) {
            echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
        }
        exit;
    }

    if ($_POST['action'] === 'delete_task') {
        $taskId = (int)$_POST['taskId'];

        // Check if task exists and user has permission
        $checkQuery = $mysqli->prepare("SELECT created_by, assigned_users FROM tasks WHERE id = ?");
        $checkQuery->bind_param("i", $taskId);
        $checkQuery->execute();
        $checkResult = $checkQuery->get_result();

        if ($checkResult->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan']);
            
            $output = ob_get_clean();
            if (json_decode($output) === null && !empty($output)) {
                echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
            }
            exit;
        }

        $taskData = $checkResult->fetch_assoc();

        // Check permission - user must be creator or assigned
        if ($taskData['created_by'] !== $username && strpos($taskData['assigned_users'], $username) === false) {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki izin untuk menghapus tugas ini']);
            
            $output = ob_get_clean();
            if (json_decode($output) === null && !empty($output)) {
                echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
            }
            exit;
        }

        // Delete task
        $deleteQuery = $mysqli->prepare("DELETE FROM tasks WHERE id = ?");
        $deleteQuery->bind_param("i", $taskId);

        if ($deleteQuery->execute()) {
            // Also delete related comments
            $mysqli->query("DELETE FROM task_comments WHERE task_id = $taskId");

            echo json_encode(['success' => true, 'message' => 'Tugas berhasil dihapus']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus tugas']);
        }
        
        $output = ob_get_clean();
        if (json_decode($output) === null && !empty($output)) {
            echo json_encode(['success' => false, 'message' => 'Server error', 'debug' => $output]);
        }
        exit;
    }
}

// Ambil semua tugas yang ditugaskan kepada user ini atau yang dibuat oleh user ini
$tasksQuery = $mysqli->prepare("
    SELECT * FROM tasks
    WHERE created_by = ? OR FIND_IN_SET(?, assigned_users)
    ORDER BY created_at DESC
");
$tasksQuery->bind_param("ss", $username, $username);
$tasksQuery->execute();
$tasksResult = $tasksQuery->get_result();

$allTasks = [];
$belumDimulai = [];
$sedangBerjalan = [];
$selesai = [];

while ($row = $tasksResult->fetch_assoc()) {
    $allTasks[] = $row;

    // Pisahkan berdasarkan status
    if ($row['status'] === 'todo') {
        $belumDimulai[] = $row;
    } elseif ($row['status'] === 'progress') {
        $sedangBerjalan[] = $row;
    } elseif ($row['status'] === 'completed') {
        $selesai[] = $row;
    }
}

// Default: tampilkan semua tugas
$tasksToShow = $allTasks;
$activeFilter = 'all';

// Jika ada parameter filter, tampilkan sesuai filter
if (isset($_GET['filter'])) {
    $filter = $_GET['filter'];
    $activeFilter = $filter;

    switch ($filter) {
        case 'todo':
            $tasksToShow = $belumDimulai;
            break;
        case 'progress':
            $tasksToShow = $sedangBerjalan;
            break;
        case 'completed':
            $tasksToShow = $selesai;
            break;
        default:
            $tasksToShow = $allTasks;
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Tasks - <?= htmlspecialchars($userData['nama']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* SEMUA CSS YANG SUDAH ADA TETAP SAMA */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            padding-bottom: 100px;
        }

        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 0;
        }

        /* Header */
        .header {
            background: #3550dc;
            color: white;
            padding: 20px 15px 25px 15px;
            position: relative;
            border-radius: 0 0 20px 20px;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .header-title {
            font-size: 18px;
            font-weight: 600;
            flex: 1;
        }

        /* Search Box in Header */
        .search-box-header {
            background: white;
            border-radius: 12px;
            padding: 10px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-box-header i {
            color: #9ca3af;
            font-size: 16px;
        }

        .search-box-header input {
            border: none;
            outline: none;
            flex: 1;
            font-size: 14px;
            color: #333;
            background: transparent;
        }

        .search-box-header input::placeholder {
            color: #b0b7c3;
        }

        /* Content */
        .content {
            padding: 15px;
        }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
            overflow-x: auto;
            padding-bottom: 5px;
            -webkit-overflow-scrolling: touch;
        }

        .filter-tabs::-webkit-scrollbar {
            display: none;
        }

        .filter-tab {
            padding: 8px 16px;
            border-radius: 20px;
            border: 1px solid #e5e7eb;
            background: white;
            color: #6b7280;
            font-weight: 500;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.3s;
            flex-shrink: 0;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .filter-tab.active {
            background: #3550dc;
            color: white;
            border-color: #3550dc;
        }

        /* Task Card */
        .task-card {
            background: white;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            border: 1px solid #f0f3f8;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
            cursor: pointer;
        }

        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .task-card-header {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }

        .task-status-label {
            font-size: 10px;
            font-weight: 600;
            margin-bottom: 5px;
            text-transform: capitalize;
            letter-spacing: 0.3px;
        }

        .task-status-label.belum {
            color: #f59e0b;
        }

        .task-status-label.sedang {
            color: #3550dc;
        }

        .task-status-label.selesai {
            color: #10b981;
        }

        .task-title {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            line-height: 1.4;
            margin-bottom: 8px;
        }

        /* Task Menu Styles */
        .task-menu {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 1.1rem;
            cursor: pointer !important;
            padding: 4px;
            position: relative;
            transition: all 0.2s;
            flex-shrink: 0;
            touch-action: none;
            min-width: 44px;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
        }

        .task-menu:hover {
            color: #4169E1;
        }

        /* Dropdown Menu */
        .task-dropdown-menu {
            position: absolute;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
            z-index: 9999;
            min-width: 160px;
            overflow: hidden;
            cursor: pointer;
            top: 100%;
            right: 0;
            left: auto;
            margin-top: 5px;
        }

        .task-dropdown-menu.active {
            display: block;
        }

        .task-dropdown-item {
            padding: 10px 14px;
            cursor: pointer !important;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }

        .task-dropdown-item:hover {
            background: #f3f4f6;
        }

        .task-dropdown-item i {
            width: 18px;
        }

        .task-dropdown-item.delete {
            color: #ef4444;
        }

        /* Overlay for menu */
        .menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: transparent;
            z-index: 999;
            display: none;
            pointer-events: auto;
        }

        .task-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .task-status-badge.todo {
            background: #fef3c7;
            color: #92400e;
        }

        .task-status-badge.progress {
            background: #e8ecf4;
            color: #3550dc;
        }

        .task-status-badge.completed {
            background: #d1fae5;
            color: #065f46;
        }

        .task-progress-container {
            margin-bottom: 12px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .progress-label-text {
            color: #6b7280;
        }

        .progress-percentage {
            font-weight: 600;
            color: #1f2937;
        }

        .progress-bar {
            height: 6px;
            background: #e8ecf4;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #3550dc;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* Task Footer */
        .task-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 10px;
            border-top: 1px solid #f0f3f8;
        }

        .task-icons {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .task-icon-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            color: #6b7280;
        }

        .task-icon-item i {
            font-size: 13px;
        }

        .task-avatars {
            display: flex;
            align-items: center;
            gap: -8px;
        }

        .task-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid white;
            margin-left: -8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .task-avatar:first-child {
            margin-left: 0;
        }

        .avatar-color-1 {
            background: linear-gradient(135deg, #f59e0b, #f97316);
        }

        .avatar-color-2 {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .avatar-color-3 {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .avatar-color-4 {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        /* Floating Add Button */
        .floating-add-btn {
            position: fixed;
            right: 15px;
            bottom: 15px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            border: none;
            border-radius: 50%;
            width: 55px;
            height: 55px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(30, 58, 138, 0.4);
            z-index: 101;
            transition: all 0.3s ease;
        }

        .floating-add-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 8px 25px rgba(30, 58, 138, 0.5);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            width: auto;
            max-width: 95%;
            padding: 8px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            z-index: 100;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            white-space: nowrap;
            border: 1px solid #e5e7eb;
        }

        .bottom-nav::-webkit-scrollbar {
            display: none;
        }

        .bottom-nav a {
            text-align: center;
            color: #6b7280;
            text-decoration: none;
            font-weight: 500;
            border-radius: 25px;
            padding: 10px 18px;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .bottom-nav a i {
            font-size: 16px;
        }

        .bottom-nav a.active {
            background: #3550dc;
            color: #fff;
        }

        .bottom-nav a:not(.active):hover {
            color: #3550dc;
            background: #f3f4f6;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            overflow-y: auto;
        }

        .modal.show {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 20px 0;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        /* Task Detail Modal Styles */
        .task-detail-modal .modal-content {
            max-width: 800px;
        }

        .task-detail-header {
            display: flex;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #3550dc;
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .task-detail-back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            margin-right: 12px;
            transition: all 0.3s;
        }

        .task-detail-back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .task-detail-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .task-detail-body {
            padding: 20px;
        }

        .modal-header {
            display: flex;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #3550dc;
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .modal-back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            margin-right: 12px;
            transition: all 0.3s;
        }

        .modal-back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .modal-body {
            padding: 20px;
        }

        .form-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            font-size: 14px;
            color: #374151;
            background: white;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: flex;
            gap: 12px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .date-input-wrapper {
            position: relative;
        }

        .date-input-wrapper i {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }

        .date-input-wrapper input {
            padding-right: 40px;
        }

        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 20px 0;
        }

        /* File Upload Styles */
        .file-upload-section {
            margin-bottom: 20px;
        }

        .file-upload-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
        }

        .file-upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f9fafb;
        }

        .file-upload-area:hover,
        .file-upload-area.dragover {
            border-color: #3550dc;
            background: #f8faff;
        }

        .file-upload-icon {
            font-size: 32px;
            color: #9ca3af;
            margin-bottom: 12px;
        }

        .file-upload-text {
            font-size: 14px;
            color: #374151;
            margin-bottom: 4px;
        }

        .file-upload-hint {
            font-size: 12px;
            color: #9ca3af;
        }

        .file-preview-container {
            margin-top: 12px;
        }

        .file-preview {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f3f4f6;
            padding: 8px 12px;
            border-radius: 8px;
            margin-right: 8px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .file-preview .remove-file {
            color: #ef4444;
            cursor: pointer;
            font-weight: bold;
        }

        /* Quick Assign Styles */
        .quick-assign-section {
            margin-bottom: 20px;
        }

        .quick-assign-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
        }

        .quick-assign-users {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .quick-assign-user {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f3f4f6;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .quick-assign-user.selected {
            background: #eff6ff;
            border-color: #1e3a8a;
        }

        .quick-assign-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
            font-weight: 600;
        }

        .user1 {
            background: linear-gradient(135deg, #f59e0b, #f97316);
        }

        .user2 {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .user3 {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .user4 {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        .quick-assign-user span {
            font-size: 12px;
            color: #374151;
        }

        .user-selection {
            position: relative;
        }

        .user-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-dropdown-item:hover {
            background: #f3f4f6;
        }

        .user-dropdown-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }

        .user-dropdown-item span {
            font-size: 14px;
            color: #374151;
        }

        /* Subtasks Styles - IMPROVED */
        .subtasks-section {
            margin-bottom: 20px;
        }

        .subtask-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            background: white;
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid #e0e5ed;
            transition: all 0.3s;
        }

        .subtask-item:hover {
            background: #f8faff;
            border-color: #3550dc;
        }

        .subtask-checkbox {
            width: 20px;
            height: 20px;
            border-radius: 5px;
            border: 2px solid #ddd;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .subtask-checkbox.checked {
            background: #10b981;
            border-color: #10b981;
            color: white;
        }

        .subtask-content {
            flex: 1;
            min-width: 0;
        }

        .subtask-title {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 4px;
            word-break: break-word;
        }

        .subtask-title.completed {
            text-decoration: line-through;
            color: #9ca3af;
        }

        .subtask-info {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: #6b7280;
            flex-wrap: wrap;
        }

        .subtask-assigned {
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .subtask-completed-by {
            color: #10b981;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.7rem;
        }

        .remove-subtask {
            color: #ef4444;
            cursor: pointer;
            font-weight: bold;
            padding: 5px;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .remove-subtask:hover {
            background: #fee2e2;
        }

        /* Improved Add Subtask Form */
        .add-subtask-form-container {
            background: #f8faff;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #e0e5ed;
        }

        .add-subtask-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .add-subtask-form label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .add-subtask-input-group {
            flex: 1;
        }

        .add-subtask-input-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            margin-bottom: 8px;
        }

        .add-subtask-input-group input:focus {
            outline: none;
            border-color: #3550dc;
            box-shadow: 0 0 0 2px rgba(53, 80, 220, 0.1);
        }

        .add-subtask-select-group {
            min-width: 150px;
        }

        .add-subtask-select-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            margin-bottom: 8px;
        }

        .add-subtask-btn {
            padding: 12px 20px;
            background: #3550dc;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            margin-top: 24px;
        }

        .add-subtask-btn:hover {
            background: #2b44c9;
        }

        .subtask-counter {
            font-size: 12px;
            color: #6b7280;
            text-align: right;
            margin-top: 5px;
        }

        /* Drag and drop styles */
        .subtask-item.dragging {
            opacity: 0.5;
            border: 2px dashed #3550dc;
        }

        .subtask-item.drag-over {
            border: 2px solid #3550dc;
            background: #f0f4ff;
        }

        .subtask-drag-handle {
            cursor: move;
            color: #9ca3af;
            padding: 5px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .subtask-drag-handle:hover {
            background: #f3f4f6;
            color: #3550dc;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        .btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #6b7280;
        }

        .btn-cancel:hover {
            background: #e5e7eb;
        }

        .btn-save {
            background: #3550dc;
            color: white;
        }

        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(53, 80, 220, 0.25);
        }

        /* Comments Section Styles */
        .comments-section {
            margin-top: 20px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 12px;
        }

        .comments-section h4 {
            font-size: 16px;
            margin-bottom: 15px;
            color: #1f2937;
        }

        .comments-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 15px;
        }

        .comment-item {
            background: white;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 10px;
            border: 1px solid #e5e7eb;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .comment-user {
            font-weight: 600;
            color: #1f2937;
            font-size: 14px;
        }

        .comment-time {
            font-size: 11px;
            color: #9ca3af;
        }

        .comment-text {
            font-size: 13px;
            color: #374151;
            line-height: 1.4;
        }

        .add-comment-form {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .add-comment-form textarea {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            font-size: 13px;
            resize: none;
            min-height: 50px;
            outline: none;
        }

        .add-comment-form textarea:focus {
            border-color: #3550dc;
        }

        .add-comment-form button {
            background: #3550dc;
            color: white;
            border: none;
            border-radius: 12px;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .add-comment-form button:hover {
            background: #2b44c9;
        }

        /* Task Detail Styles */
        .detail-section {
            margin-bottom: 20px;
        }

        .detail-section-title {
            font-size: 16px;
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .detail-section-title i {
            color: #3550dc;
            font-size: 14px;
        }

        .detail-content {
            color: #4b5563;
            line-height: 1.6;
            font-size: 14px;
            background: #f8faff;
            border-radius: 8px;
            padding: 15px;
            word-break: break-word;
        }

        .dates-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .date-box {
            background: #f8faff;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e5ed;
        }

        .date-label {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 5px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .date-value {
            font-size: 15px;
            font-weight: 600;
            color: #333;
        }

        .user-info-box {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #ffeaa7;
        }

        .user-info-label {
            font-size: 11px;
            color: #8a6d00;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .user-info-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 15px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .user-name {
            font-size: 15px;
            font-weight: 600;
            color: #333;
        }

        .user-role {
            font-size: 12px;
            color: #6b7280;
            font-style: italic;
        }

        .assigned-users-label {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .assigned-users-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .assigned-user {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background: #f8faff;
            border-radius: 20px;
            border: 1px solid #e0e5ed;
            flex-shrink: 0;
            max-width: 100%;
        }

        .assigned-user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 11px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .assigned-user-name {
            font-size: 13px;
            font-weight: 500;
            color: #333;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }

        .attachment-item {
            transition: all 0.2s ease;
            border-radius: 8px;
            overflow: hidden;
        }

        .attachment-image {
            width: 100%;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 1px solid #e5e7eb;
        }

        .attachment-file {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: white;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            height: 130px;
            transition: all 0.3s;
        }

        .attachment-file:hover {
            background: #f8faff;
            border-color: #3550dc;
            transform: translateY(-2px);
        }

        .attachment-file-icon {
            font-size: 2.2rem;
            margin-bottom: 8px;
        }

        .attachment-file-icon .fa-file-pdf {
            color: #ef4444;
        }

        .attachment-file-icon .fa-file-word {
            color: #2563eb;
        }

        .attachment-file-icon .fa-image {
            color: #10b981;
        }

        .attachment-file-icon .fa-file-excel {
            color: #059669;
        }

        .attachment-file-name {
            font-size: 11px;
            color: #333;
            word-break: break-all;
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.3;
            max-height: 2.6em;
        }

        .attachment-file-type {
            font-size: 9px;
            color: #6b7280;
            text-transform: uppercase;
        }

        .comment-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 11px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .detail-comment-item {
            background: #f8faff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #3550dc;
        }

        .detail-comment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .detail-comment-user {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-comment-username {
            font-weight: 600;
            color: #3550dc;
            font-size: 13px;
        }

        .detail-comment-time {
            font-size: 11px;
            color: #9ca3af;
            text-align: right;
            flex-shrink: 0;
        }

        .detail-comment-text {
            color: #333;
            font-size: 13px;
            line-height: 1.5;
            padding-left: 40px;
            word-break: break-word;
        }

        .detail-comment-input-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .detail-comment-input-group input {
            flex: 1;
            padding: 14px 18px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: all 0.3s;
        }

        .detail-comment-input-group input:focus {
            outline: none;
            border-color: #3550dc;
            box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
        }

        .detail-comment-input-group button {
            padding: 14px 20px;
            background: #3550dc;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            font-size: 14px;
        }

        .detail-comment-input-group button:hover {
            background: #2b44c9;
        }

        .detail-no-data {
            text-align: center;
            padding: 30px 20px;
            color: #9ca3af;
        }

        .detail-no-data i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        .detail-no-data p {
            font-size: 13px;
        }

        .file-upload-wrapper {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f9fafb;
            margin-top: 15px;
        }

        .file-upload-wrapper:hover {
            border-color: #3550dc;
            background: #f0f4ff;
        }

        .file-upload-wrapper.dragover {
            border-color: #3550dc;
            background: #e8ecf4;
        }

        .detail-file-upload-icon {
            font-size: 40px;
            color: #3550dc;
            margin-bottom: 10px;
        }

        .detail-file-upload-text {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .detail-file-upload-hint {
            font-size: 12px;
            color: #9ca3af;
        }

        .progress-box {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .progress-label {
            font-size: 15px;
            font-weight: 600;
            color: #333;
        }

        .progress-percentage {
            font-size: 18px;
            font-weight: 700;
            color: #3550dc;
        }

        .progress-bar-bg {
            height: 8px;
            background: #e8ecf4;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .progress-bar {
            height: 100%;
            background: #3550dc;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-slider {
            margin-top: 15px;
        }

        .progress-slider input[type="range"] {
            width: 100%;
            height: 6px;
            border-radius: 3px;
            background: #e5e7eb;
            outline: none;
            -webkit-appearance: none;
        }

        .progress-slider input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #3550dc;
            cursor: pointer;
            border: 2px solid white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .progress-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
            font-size: 13px;
            color: #6b7280;
        }

        .progress-stats {
            font-weight: 600;
            color: #3550dc;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
            background: #e8ecf4;
            color: #3550dc;
        }

        .title {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.4;
            margin-bottom: 15px;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .container {
                padding: 0;
            }

            .content {
                padding: 12px;
            }

            .header {
                padding: 15px 12px 20px 12px;
            }

            .floating-add-btn {
                width: 50px;
                height: 50px;
                font-size: 18px;
                right: 12px;
                bottom: 70px;
            }

            .bottom-nav {
                max-width: 96%;
                padding: 6px;
            }

            .bottom-nav a {
                padding: 8px 14px;
                font-size: 12px;
            }

            .bottom-nav a i {
                font-size: 16px;
            }

            .modal {
                padding: 10px 0;
            }

            .modal-content {
                width: 95%;
                max-height: 95vh;
            }

            .modal-header {
                padding: 15px;
            }

            .modal-body {
                padding: 15px;
            }

            .form-row {
                flex-direction: column;
                gap: 16px;
            }

            .quick-assign-users {
                gap: 6px;
            }

            .quick-assign-user {
                padding: 6px 10px;
                font-size: 11px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .add-subtask-form {
                flex-direction: column;
                gap: 10px;
            }

            .add-subtask-input-group,
            .add-subtask-select-group {
                min-width: 100%;
            }

            .add-subtask-btn {
                margin-top: 0;
                width: 100%;
                justify-content: center;
            }

            .add-comment-form {
                flex-direction: column;
            }

            .add-comment-form button {
                width: 100%;
                height: 40px;
            }

            .dates-grid {
                grid-template-columns: 1fr;
            }

            .attachments-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }

            .detail-comment-input-group {
                flex-direction: column;
            }

            .detail-comment-input-group input,
            .detail-comment-input-group button {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <!-- HTML YANG SUDAH ADA TETAP SAMA -->
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <button class="back-btn" onclick="window.location.href='dashboard.php'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">Task</div>
            </div>

            <!-- Search Box -->
            <div class="search-box-header">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search task" id="searchInput">
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?filter=all" class="filter-tab <?= $activeFilter === 'all' ? 'active' : '' ?>">All Task</a>
                <a href="?filter=todo" class="filter-tab <?= $activeFilter === 'todo' ? 'active' : '' ?>">Belum Dimulai</a>
                <a href="?filter=progress" class="filter-tab <?= $activeFilter === 'progress' ? 'active' : '' ?>">Sedang Berjalan</a>
                <a href="?filter=completed" class="filter-tab <?= $activeFilter === 'completed' ? 'active' : '' ?>">Selesai</a>
            </div>

            <!-- Tasks Container -->
            <div id="tasksContainer">
                <?php if (!empty($tasksToShow)): ?>
                    <?php foreach ($tasksToShow as $task):
                        $statusLabel = '';
                        $statusClass = '';
                        if ($task['status'] === 'progress') {
                            $statusLabel = 'Sedang Berjalan';
                            $statusClass = 'sedang';
                        } elseif ($task['status'] === 'completed') {
                            $statusLabel = 'Selesai';
                            $statusClass = 'selesai';
                        } else {
                            $statusLabel = 'Belum Dikerjakan';
                            $statusClass = 'belum';
                        }

                        $assignedUsers = $task['assigned_users'] ? explode(',', $task['assigned_users']) : [];
                    ?>
                        <div class="task-card" data-task-id="<?= $task['id'] ?>" onclick="window.location.href='task_detail.php?id=<?= $task['id'] ?>'" style="cursor: pointer;">
                            <div class="task-card-header">
                                <div style="flex: 1;">
                                    <div class="task-status-label <?= $statusClass ?>"><?= $statusLabel ?></div>
                                    <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                                    <?php if ($task['created_by'] !== $username): ?>
                                    <div style="font-size: 11px; color: #10b981; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-user-check"></i> Ditugaskan kepada Anda oleh <?= htmlspecialchars($task['created_by']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <button class="task-menu" onclick="event.stopPropagation(); toggleTaskMenu(<?= $task['id'] ?>)">
                                    <i class="fas fa-ellipsis-v"></i>
                                    <div class="task-dropdown-menu" id="menu-<?= $task['id'] ?>">
                                        <div class="task-dropdown-item" onclick="event.stopPropagation(); window.location.href='task_detail.php?id=<?= $task['id'] ?>'">
                                            <i class="fas fa-eye"></i> Lihat Detail
                                        </div>
                                        <?php if ($task['created_by'] === $username): ?>
                                        <div class="task-dropdown-item" onclick="event.stopPropagation(); window.location.href='edit_task.php?id=<?= $task['id'] ?>'">
                                            <i class="fas fa-edit"></i> Edit
                                        </div>
                                        <div class="task-dropdown-item delete" onclick="event.stopPropagation(); confirmDelete(<?= $task['id'] ?>)">
                                            <i class="fas fa-trash"></i> Hapus
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </button>
                            </div>

                            <div class="task-status-badge <?= $task['status'] ?>">
                                <?= ucfirst($task['status']) ?>
                            </div>

                            <div class="task-progress-container">
                                <div class="progress-label">
                                    <span class="progress-label-text">Progress</span>
                                    <span class="progress-percentage"><?= $task['progress'] ?>%</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $task['progress'] ?>%"></div>
                                </div>
                            </div>

                            <div class="task-footer">
                                <div class="task-icons">
                                    <div class="task-icon-item" title="Subtasks">
                                        <i class="far fa-check-square"></i>
                                        <span><?= $task['tasks_completed'] ?>/<?= $task['tasks_total'] ?></span>
                                    </div>
                                    <div class="task-icon-item" title="Comments">
                                        <i class="far fa-comment"></i>
                                        <span><?= $task['comments'] ?></span>
                                    </div>
                                    <div class="task-icon-item" title="Attachments">
                                        <i class="fas fa-paperclip"></i>
                                        <span><?= $task['attachments'] ? count(json_decode($task['attachments'], true) ?: []) : 0 ?></span>
                                    </div>
                                </div>

                                <div class="task-avatars">
                                    <?php
                                    $maxAvatars = 3;
                                    $displayUsers = array_slice($assignedUsers, 0, $maxAvatars);
                                    foreach ($displayUsers as $index => $user):
                                        $initial = strtoupper(substr(trim($user), 0, 1));
                                        $colorClass = 'avatar-color-' . (($index % 4) + 1);
                                    ?>
                                        <div class="task-avatar <?= $colorClass ?>" title="<?= htmlspecialchars(trim($user)) ?>">
                                            <?= $initial ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (count($assignedUsers) > $maxAvatars): ?>
                                        <div class="task-avatar" style="background: #6b7280;">
                                            +<?= count($assignedUsers) - $maxAvatars ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <p>
                            <?php
                            if ($activeFilter === 'todo') {
                                echo "Tidak ada tugas yang belum dimulai";
                            } elseif ($activeFilter === 'progress') {
                                echo "Tidak ada tugas yang sedang berjalan";
                            } elseif ($activeFilter === 'completed') {
                                echo "Tidak ada tugas yang selesai";
                            } else {
                                echo "Belum ada tugas yang ditugaskan kepada Anda";
                            }
                            ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Menu Overlay -->
    <div class="menu-overlay" id="menuOverlay"></div>

    <!-- Floating Add Button -->
    <button class="floating-add-btn" id="addTaskBtn">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Add Task Modal -->
    <div class="modal" id="addTaskModal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="modal-back-btn" id="closeModalBtn">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h3>Buat Tugas Baru</h3>
            </div>

            <div class="modal-body">
                <div class="form-title">Buat Tugas dan Bagikan ke Teman</div>

                <form id="addTaskForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="taskTitle">Judul Tugas</label>
                        <input type="text" id="taskTitle" placeholder="Contoh: Develop Website E-commerce" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="startDate">Tanggal Mulai</label>
                            <div class="date-input-wrapper">
                                <input type="date" id="startDate" required>
                                <i class="far fa-calendar-alt"></i>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="endDate">Tanggal Selesai</label>
                            <div class="date-input-wrapper">
                                <input type="date" id="endDate" required>
                                <i class="far fa-calendar-alt"></i>
                            </div>
                        </div>
                    </div>

                    <div class="divider"></div>

                    <!-- File Upload Section -->
                    <div class="file-upload-section">
                        <div class="file-upload-title">
                            <i class="fas fa-paperclip"></i>
                            Lampirkan Foto/Dokumen:
                        </div>
                        <div class="file-upload-area" id="fileUploadArea">
                            <div class="file-upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="file-upload-text">Klik atau drag file ke sini untuk upload</div>
                            <div class="file-upload-hint">Maksimal 5 file, masing-masing maksimal 5MB</div>
                            <input type="file" id="fileInput" multiple accept="image/*,.pdf,.doc,.docx" style="display: none;">
                        </div>
                        <div class="file-preview-container" id="filePreviewContainer">
                            <!-- File previews will be added here -->
                        </div>
                    </div>

                    <!-- Quick Assign Section -->
                    <div class="quick-assign-section">
                        <div class="quick-assign-title">
                            <i class="fas fa-users"></i>
                            Pilih Teman untuk Bagi Tugas:
                        </div>
                        <div class="quick-assign-users" id="quickAssignUsers">
                            <?php
                            $userIndex = 1;
                            $usersQuery = $mysqli->query("SELECT * FROM users WHERE username != '$username'");
                            while ($userRow = $usersQuery->fetch_assoc()) {
                                $initial = strtoupper(substr($userRow['username'], 0, 1));
                                $userClass = 'user' . ($userIndex % 4 + 1);
                                echo '<div class="quick-assign-user" data-user-id="' . $userRow['id'] . '" data-user-name="' . $userRow['username'] . '">';
                                echo '<div class="quick-assign-avatar ' . $userClass . '">' . $initial . '</div>';
                                echo '<span>' . $userRow['username'] . '</span>';
                                echo '</div>';
                                $userIndex++;
                            }
                            ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="assignMember">Anggota Tim yang Dipilih</label>
                        <div class="user-selection">
                            <input type="text" id="assignMember" placeholder="Klik untuk pilih teman..." readonly>
                            <div class="user-dropdown" id="userDropdown">
                                <?php
                                $usersQuery->data_seek(0);
                                $userIndex = 1;
                                while ($userRow = $usersQuery->fetch_assoc()) {
                                    $initial = strtoupper(substr($userRow['username'], 0, 1));
                                    $userClass = 'user' . ($userIndex % 4 + 1);
                                    echo '<div class="user-dropdown-item" data-user-id="' . $userRow['id'] . '" data-user-name="' . $userRow['username'] . '">';
                                    echo '<div class="user-dropdown-avatar ' . $userClass . '">' . $initial . '</div>';
                                    echo '<span>' . $userRow['username'] . '</span>';
                                    echo '</div>';
                                    $userIndex++;
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- IMPROVED Subtasks Section -->
                    <div class="form-group">
                        <div class="form-title">Subtasks / Pekerjaan</div>
                        <div class="subtask-counter" id="subtaskCounter">0 subtask</div>
                        
                        <div id="subtasksContainer">
                            <!-- Subtasks will be added here -->
                        </div>
                        
                        <div class="add-subtask-form-container">
                            <div class="add-subtask-form">
                                <div class="add-subtask-input-group">
                                    <label for="newSubtask">Tambah Pekerjaan Baru</label>
                                    <input type="text" id="newSubtask" placeholder="Masukkan deskripsi subtask...">
                                </div>
                                <div class="add-subtask-select-group">
                                    <label for="subtaskAssign">Assign ke</label>
                                    <select id="subtaskAssign" class="subtask-assign-select">
                                        <option value="">Pilih teman...</option>
                                        <?php
                                        $usersQuery->data_seek(0);
                                        while ($userRow = $usersQuery->fetch_assoc()) {
                                            echo '<option value="' . $userRow['username'] . '">' . $userRow['username'] . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <button type="button" class="add-subtask-btn" onclick="addSubtaskInput()">
                                    <i class="fas fa-plus"></i> Tambah
                                </button>
                            </div>
                            <div class="subtask-counter" style="text-align: center;">
                                <small>Klik untuk toggle checkbox, drag untuk mengurutkan</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="taskNote">Catatan</label>
                        <textarea id="taskNote" placeholder="Tambahkan catatan atau instruksi..."></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-cancel" id="cancelBtn">Batal</button>
                        <button type="submit" class="btn btn-save">Bagikan Tugas</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Task Detail Modal -->
    <div class="modal" id="taskDetailModal">
        <div class="modal-content task-detail-modal">
            <div class="task-detail-header">
                <button class="task-detail-back-btn" id="closeDetailModalBtn">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h3>Detail Tugas</h3>
            </div>

            <div class="task-detail-body" id="taskDetailBody">
                <!-- Detail task akan dimuat dengan AJAX -->
            </div>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <a href="dashboard.php">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>
        <a href="tasks.php" class="active">
            <i class="fa-solid fa-list-check"></i>
            <span>Tasks</span>
        </a>
        <a href="users.php">
            <i class="fa-solid fa-user-group"></i>
            <span>Users</span>
        </a>
        <a href="profile.php">
            <i class="fa-solid fa-user"></i>
            <span>Profil</span>
        </a>
    </div>

    <script>
        // DOM Elements
        const addTaskModal = document.getElementById('addTaskModal');
        const taskDetailModal = document.getElementById('taskDetailModal');
        const taskDetailBody = document.getElementById('taskDetailBody');
        const addTaskBtn = document.getElementById('addTaskBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const closeDetailModalBtn = document.getElementById('closeDetailModalBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const searchInput = document.getElementById('searchInput');
        const menuOverlay = document.getElementById('menuOverlay');

        // Variables
        let currentTaskId = null;
        let pollingInterval = null;
        let assignedUsers = [];
        let subtasks = [];
        let subtaskAssignments = [];
        let fileInputs = [];
        let dragOverlay = false;

        // Open Add Task Modal
        addTaskBtn.addEventListener('click', () => {
            addTaskModal.classList.add('show');
            document.body.style.overflow = 'hidden';
            resetForm();
        });

        // Close Modals
        closeModalBtn.addEventListener('click', () => {
            addTaskModal.classList.remove('show');
            document.body.style.overflow = 'auto';
        });

        closeDetailModalBtn.addEventListener('click', () => {
            taskDetailModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            stopPolling();
        });

        cancelBtn.addEventListener('click', () => {
            addTaskModal.classList.remove('show');
            document.body.style.overflow = 'auto';
        });

        // Close modals when clicking outside
        addTaskModal.addEventListener('click', (e) => {
            if (e.target === addTaskModal) {
                addTaskModal.classList.remove('show');
                document.body.style.overflow = 'auto';
            }
        });

        taskDetailModal.addEventListener('click', (e) => {
            if (e.target === taskDetailModal) {
                taskDetailModal.classList.remove('show');
                document.body.style.overflow = 'auto';
                stopPolling();
            }
        });

        // Escape key to close modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                addTaskModal.classList.remove('show');
                taskDetailModal.classList.remove('show');
                document.body.style.overflow = 'auto';
                stopPolling();
            }
        });

        // Reset form function
        function resetForm() {
            document.getElementById('addTaskForm').reset();
            assignedUsers = [];
            subtasks = [];
            subtaskAssignments = [];
            fileInputs = [];
            updateSelectedUsers();
            updateSubtasks();
            
            // Clear file previews
            const filePreviewContainer = document.getElementById('filePreviewContainer');
            if (filePreviewContainer) {
                filePreviewContainer.innerHTML = '';
            }
        }

        // Utility function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Toggle task menu
        function toggleTaskMenu(taskId) {
            const menu = document.getElementById('menu-' + taskId);
            const allMenus = document.querySelectorAll('.task-dropdown-menu');

            allMenus.forEach(m => {
                if (m !== menu) m.classList.remove('active');
            });

            if (!menu.classList.contains('active')) {
                menu.classList.add('active');
            } else {
                menu.classList.remove('active');
            }
        }

        // Confirm delete
        function confirmDelete(taskId) {
            if (confirm('Apakah Anda yakin ingin menghapus tugas ini?')) {
                // Create form and submit to delete_task.php
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete_task.php?id=' + taskId;
                document.body.appendChild(form);
                form.submit();
            }
        }


        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.task-menu') && !e.target.closest('.task-dropdown-menu')) {
                document.querySelectorAll('.task-dropdown-menu').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        // Error handling untuk semua fetch request
        function safeFetch(url, options) {
            return fetch(url, options)
                .then(response => {
                    const contentType = response.headers.get("content-type");
                    if (contentType && contentType.includes("application/json")) {
                        return response.json();
                    } else {
                        return response.text().then(text => {
                            console.error("Invalid JSON response:", text.substring(0, 200));
                            throw new Error("Invalid JSON response from server");
                        });
                    }
                });
        }

        // Handle form submission
        document.getElementById('addTaskForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Get form values
            const title = document.getElementById('taskTitle').value.trim();
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const note = document.getElementById('taskNote').value.trim();
            const assignedUsersInput = document.getElementById('assignMember').value;
            
            // Validation
            if (!title) {
                alert('Judul tugas harus diisi');
                return;
            }
            
            if (!startDate || !endDate) {
                alert('Tanggal mulai dan selesai harus diisi');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                alert('Tanggal selesai harus setelah tanggal mulai');
                return;
            }
            
            if (subtasks.length === 0) {
                if (!confirm('Anda belum menambahkan subtask. Lanjutkan tanpa subtask?')) {
                    return;
                }
            }
            
            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'add_task');
            formData.append('title', title);
            formData.append('startDate', startDate);
            formData.append('endDate', endDate);
            formData.append('note', note);
            formData.append('assignedUsers', assignedUsers);
            formData.append('subtasks', JSON.stringify(subtasks));
            formData.append('subtaskAssignments', JSON.stringify(subtaskAssignments));
            
            // Add files
            const fileInput = document.getElementById('fileInput');
            if (fileInput.files.length > 0) {
                for (let i = 0; i < fileInput.files.length; i++) {
                    formData.append('attachments[]', fileInput.files[i]);
                }
            }
            
            // Show loading
            const submitBtn = e.target.querySelector('.btn-save');
            const originalText = submitBtn.textContent;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
            submitBtn.disabled = true;
            
            try {
                const result = await safeFetch('tasks.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (result.success) {
                    alert(result.message);
                    addTaskModal.classList.remove('show');
                    document.body.style.overflow = 'auto';
                    location.reload(); // Reload to show new task
                } else {
                    alert(result.message || 'Terjadi kesalahan');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan: ' + error.message);
            } finally {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }
        });

        // Quick assign users
        document.querySelectorAll('.quick-assign-user').forEach(user => {
            user.addEventListener('click', () => {
                user.classList.toggle('selected');
                const userName = user.dataset.userName;
                
                if (user.classList.contains('selected')) {
                    if (!assignedUsers.includes(userName)) {
                        assignedUsers.push(userName);
                    }
                } else {
                    const index = assignedUsers.indexOf(userName);
                    if (index > -1) {
                        assignedUsers.splice(index, 1);
                    }
                }
                
                updateSelectedUsers();
            });
        });

        // Update selected users display
        function updateSelectedUsers() {
            const assignMemberInput = document.getElementById('assignMember');
            assignMemberInput.value = assignedUsers.join(', ');
        }

        // User dropdown
        const assignMemberInput = document.getElementById('assignMember');
        const userDropdown = document.getElementById('userDropdown');

        if (assignMemberInput) {
            assignMemberInput.addEventListener('click', () => {
                userDropdown.style.display = userDropdown.style.display === 'block' ? 'none' : 'block';
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.user-selection')) {
                    userDropdown.style.display = 'none';
                }
            });
        }

        // User dropdown items
        document.querySelectorAll('.user-dropdown-item').forEach(item => {
            item.addEventListener('click', () => {
                const userName = item.dataset.userName;
                
                if (!assignedUsers.includes(userName)) {
                    assignedUsers.push(userName);
                }
                
                updateSelectedUsers();
                userDropdown.style.display = 'none';
                
                // Highlight in quick assign
                document.querySelectorAll('.quick-assign-user').forEach(user => {
                    if (user.dataset.userName === userName) {
                        user.classList.add('selected');
                    }
                });
            });
        });

        // Subtask functions
        function addSubtaskInput() {
            const subtaskInput = document.getElementById('newSubtask');
            const subtaskAssign = document.getElementById('subtaskAssign');
            
            const text = subtaskInput.value.trim();
            const assignedTo = subtaskAssign.value;
            
            if (!text) {
                alert('Deskripsi subtask harus diisi');
                return;
            }
            
            subtasks.push(text);
            subtaskAssignments.push(assignedTo);
            
            updateSubtasks();
            
            // Clear inputs
            subtaskInput.value = '';
            subtaskAssign.value = '';
            subtaskInput.focus();
        }

        function updateSubtasks() {
            const container = document.getElementById('subtasksContainer');
            const counter = document.getElementById('subtaskCounter');
            
            if (!container) return;
            
            container.innerHTML = '';
            
            subtasks.forEach((text, index) => {
                const assignedTo = subtaskAssignments[index] || 'Belum ditugaskan';
                const subtaskDiv = document.createElement('div');
                subtaskDiv.className = 'subtask-item';
                subtaskDiv.innerHTML = `
                    <div class="subtask-checkbox" onclick="toggleSubtask(${index})"></div>
                    <div class="subtask-content">
                        <div class="subtask-title">${escapeHtml(text)}</div>
                        <div class="subtask-info">
                            <span class="subtask-assigned"><i class="fas fa-user"></i> ${assignedTo}</span>
                        </div>
                    </div>
                    <div class="remove-subtask" onclick="removeSubtask(${index})">
                        <i class="fas fa-times"></i>
                    </div>
                `;
                container.appendChild(subtaskDiv);
            });
            
            if (counter) {
                counter.textContent = `${subtasks.length} subtask`;
            }
        }

        function toggleSubtask(index) {
            // This will be handled in detail view
        }

        function removeSubtask(index) {
            subtasks.splice(index, 1);
            subtaskAssignments.splice(index, 1);
            updateSubtasks();
        }

        // File upload functionality
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('fileInput');

        if (fileUploadArea && fileInput) {
            fileUploadArea.addEventListener('click', () => {
                fileInput.click();
            });
            
            fileInput.addEventListener('change', (e) => {
                handleFiles(e.target.files);
            });
            
            // Drag and drop
            fileUploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                fileUploadArea.classList.add('dragover');
            });
            
            fileUploadArea.addEventListener('dragleave', () => {
                fileUploadArea.classList.remove('dragover');
            });
            
            fileUploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                fileUploadArea.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                handleFiles(files);
            });
        }

        function handleFiles(files) {
            const filePreviewContainer = document.getElementById('filePreviewContainer');
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                // Check file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    alert(`File ${file.name} terlalu besar. Maksimal 5MB`);
                    continue;
                }
                
                // Check file type
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                                     'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                if (!allowedTypes.includes(file.type)) {
                    alert(`File ${file.name} tidak didukung. Hanya gambar, PDF, dan Word documents`);
                    continue;
                }
                
                // Add to file inputs
                fileInputs.push(file);
                
                // Create preview
                const preview = document.createElement('div');
                preview.className = 'file-preview';
                preview.innerHTML = `
                    <span>${escapeHtml(file.name)}</span>
                    <span class="remove-file" onclick="removeFile(${fileInputs.length - 1})">×</span>
                `;
                filePreviewContainer.appendChild(preview);
            }
        }

        function removeFile(index) {
            fileInputs.splice(index, 1);
            updateFilePreviews();
        }

        function updateFilePreviews() {
            const filePreviewContainer = document.getElementById('filePreviewContainer');
            filePreviewContainer.innerHTML = '';
            
            fileInputs.forEach((file, index) => {
                const preview = document.createElement('div');
                preview.className = 'file-preview';
                preview.innerHTML = `
                    <span>${escapeHtml(file.name)}</span>
                    <span class="remove-file" onclick="removeFile(${index})">×</span>
                `;
                filePreviewContainer.appendChild(preview);
            });
        }

        // Polling for real-time updates
        function startPolling() {
            stopPolling();
            pollingInterval = setInterval(() => {
                if (currentTaskId) {
                    updateTaskDetail(currentTaskId);
                }
            }, 5000); // Update every 5 seconds
        }

        function stopPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }

        async function updateTaskDetail(taskId) {
            try {
                const formData = new FormData();
                formData.append('action', 'get_task_detail');
                formData.append('taskId', taskId);

                const result = await safeFetch('tasks.php', {
                    method: 'POST',
                    body: formData
                });

                if (result.success) {
                    renderTaskDetail(result);
                }
            } catch (error) {
                console.error('Error polling:', error);
            }
        }

        // Toggle subtask in detail view
        async function toggleSubtaskDetail(index) {
            if (!currentTaskId) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'toggle_subtask');
                formData.append('index', index);
                formData.append('taskId', currentTaskId);
                formData.append('currentUser', '<?= $username ?>');

                const result = await safeFetch('tasks.php', {
                    method: 'POST',
                    body: formData
                });

                if (result.success) {
                    // Update progress
                    document.getElementById('currentProgress').textContent = result.progress + '%';
                    document.getElementById('progressBar').style.width = result.progress + '%';
                    document.getElementById('progressSlider').value = result.progress;
                    document.getElementById('progressStats').textContent = 
                        `${result.completed}/${result.total} selesai`;
                    
                    // Refresh detail view
                    await updateTaskDetail(currentTaskId);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Gagal mengupdate subtask');
            }
        }

        // Update progress in detail view
        async function updateProgressDetail(progress) {
            if (!currentTaskId) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'update_progress');
                formData.append('taskId', currentTaskId);
                formData.append('progress', progress);

                const result = await safeFetch('tasks.php', {
                    method: 'POST',
                    body: formData
                });

                if (result.success) {
                    // Update status badge
                    const statusBadge = document.getElementById('detailStatusBadge');
                    if (statusBadge) {
                        statusBadge.className = `status-badge ${result.status}`;
                        statusBadge.textContent = result.category;
                    }
                    
                    // Refresh detail view
                    await updateTaskDetail(currentTaskId);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Gagal mengupdate progress');
            }
        }

        // Add comment in detail view
        async function addCommentDetail() {
            if (!currentTaskId) return;
            
            const commentInput = document.getElementById('commentInput');
            const comment = commentInput.value.trim();
            
            if (!comment) {
                alert('Komentar tidak boleh kosong');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'add_comment');
                formData.append('taskId', currentTaskId);
                formData.append('comment', comment);

                const result = await safeFetch('tasks.php', {
                    method: 'POST',
                    body: formData
                });

                if (result.success) {
                    commentInput.value = '';
                    // Refresh detail view
                    await updateTaskDetail(currentTaskId);
                } else {
                    alert('Gagal menambahkan komentar');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat menambahkan komentar');
            }
        }

        // Upload files in detail view
        async function uploadFilesDetail(files) {
            if (!currentTaskId) return;
            
            const formData = new FormData();
            formData.append('action', 'upload_attachments');
            formData.append('taskId', currentTaskId);
            
            for (let i = 0; i < files.length; i++) {
                formData.append('files[]', files[i]);
            }
            
            try {
                const result = await safeFetch('tasks.php', {
                    method: 'POST',
                    body: formData
                });

                if (result.success) {
                    // Refresh detail view
                    await updateTaskDetail(currentTaskId);
                } else {
                    alert('Gagal mengupload file: ' + (result.errors ? result.errors.join(', ') : 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat mengupload file');
            }
        }

        // Open image in full screen
        function openImage(src) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.9);
                z-index: 10001;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            
            const img = document.createElement('img');
            img.src = src;
            img.style.cssText = `
                max-width: 90%;
                max-height: 90%;
                object-fit: contain;
            `;
            
            modal.appendChild(img);
            modal.addEventListener('click', () => {
                document.body.removeChild(modal);
            });
            
            document.body.appendChild(modal);
        }

        // Search functionality
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase();
                const taskCards = document.querySelectorAll('.task-card');
                
                taskCards.forEach(card => {
                    const title = card.querySelector('.task-title').textContent.toLowerCase();
                    const status = card.querySelector('.task-status-label').textContent.toLowerCase();
                    
                    if (title.includes(searchTerm) || status.includes(searchTerm)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Check if all cards are hidden
                const visibleCards = Array.from(taskCards).filter(card => card.style.display !== 'none');
                const emptyState = document.querySelector('.empty-state');
                
                if (visibleCards.length === 0 && searchTerm) {
                    if (!emptyState) {
                        const container = document.getElementById('tasksContainer');
                        const emptyDiv = document.createElement('div');
                        emptyDiv.className = 'empty-state';
                        emptyDiv.innerHTML = `
                            <i class="fas fa-search"></i>
                            <p>Tidak ditemukan tugas dengan kata kunci "${searchTerm}"</p>
                        `;
                        container.appendChild(emptyDiv);
                    }
                } else if (emptyState && searchTerm === '') {
                    emptyState.remove();
                }
            });
        }

        // Enter key to add subtask
        document.getElementById('newSubtask').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                addSubtaskInput();
            }
        });

        // Render task detail function
        async function renderTaskDetail(data) {
            const task = data.task;
            const subtasks = data.subtasks;
            const memberSubtasks = data.memberSubtasks || {};
            const comments = data.comments;
            const attachments = data.attachments;
            const assignedUsers = data.assignedUsers || [];
            const currentUser = data.currentUser;

            // Format tanggal
            const formatDate = (dateString) => {
                if (!dateString) return '-';
                const date = new Date(dateString);
                return date.toLocaleDateString('id-ID', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
            };

            // Format datetime for subtasks
            const formatDateTime = (dateString) => {
                if (!dateString) return '';
                const date = new Date(dateString);
                const now = new Date();
                const diffMs = now - date;
                const diffMins = Math.floor(diffMs / 60000);
                const diffHours = Math.floor(diffMs / 3600000);
                const diffDays = Math.floor(diffMs / 86400000);

                if (diffMins < 60) {
                    return `${diffMins} menit lalu`;
                } else if (diffHours < 24) {
                    return `${diffHours} jam lalu`;
                } else if (diffDays < 7) {
                    return `${diffDays} hari lalu`;
                } else {
                    return date.toLocaleDateString('id-ID', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    });
                }
            };

            // Render status badge
            const statusClass = task.status;
            const statusText = task.category || task.status;

            // Render assigned users
            let assignedUsersHtml = '';
            if (assignedUsers.length > 0) {
                assignedUsersHtml = assignedUsers.map(user => {
                    user = user.trim();
                    if (!user) return '';
                    const initial = user.charAt(0).toUpperCase();
                    return `
                        <div class="assigned-user">
                            <div class="assigned-user-avatar">${initial}</div>
                            <div class="assigned-user-name">${user}</div>
                        </div>
                    `;
                }).join('');
            }

            // NEW: Group subtasks by member
            let subtasksByMember = {};
            let allSubtasks = [];
            
            // Use memberSubtasks if available, otherwise fall back to old format
            if (Object.keys(memberSubtasks).length > 0) {
                subtasksByMember = memberSubtasks;
                // Flatten all subtasks for display
                for (const member in memberSubtasks) {
                    allSubtasks = allSubtasks.concat(memberSubtasks[member]);
                }
            } else if (subtasks.length > 0) {
                // Group by assigned user from old format
                subtasks.forEach(subtask => {
                    if (subtask.assigned) {
                        if (!subtasksByMember[subtask.assigned]) {
                            subtasksByMember[subtask.assigned] = [];
                        }
                        subtasksByMember[subtask.assigned].push(subtask);
                    }
                    allSubtasks.push(subtask);
                });
            }

            // Render subtasks grouped by member
            let subtasksHtml = '';
            if (Object.keys(subtasksByMember).length > 0) {
                for (const member in subtasksByMember) {
                    const memberTasks = subtasksByMember[member];
                    const memberCompleted = memberTasks.filter(st => st.completed).length;
                    const memberTotal = memberTasks.length;
                    
                    subtasksHtml += `
                        <div class="detail-section">
                            <div class="detail-section-title">
                                <i class="fas fa-user"></i>
                                ${member} (${memberCompleted}/${memberTotal} selesai)
                            </div>
                    `;
                    
                    memberTasks.forEach((subtask, index) => {
                        const checked = subtask.completed ? 'checked' : '';
                        const titleClass = subtask.completed ? 'completed' : '';
                        let completedInfo = '';
                        
                        if (subtask.completed && subtask.completed_by) {
                            const timeAgo = subtask.completed_at ? formatDateTime(subtask.completed_at) : '';
                            completedInfo = `<span class="subtask-completed-by"><i class="fas fa-check-circle"></i> Selesai oleh ${subtask.completed_by} ${timeAgo}</span>`;
                        }
                        
                        subtasksHtml += `
                            <div class="subtask-item" data-member="${member}" data-index="${index}">
                                <div class="subtask-checkbox ${checked}" onclick="toggleSubtaskDetail(${index})">
                                    ${subtask.completed ? '<i class="fas fa-check"></i>' : ''}
                                </div>
                                <div class="subtask-content">
                                    <div class="subtask-title ${titleClass}">${escapeHtml(subtask.text)}</div>
                                    <div class="subtask-info">
                                        ${completedInfo}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    subtasksHtml += `</div>`;
                }
            } else {
                subtasksHtml = `
                    <div class="detail-no-data">
                        <i class="fas fa-tasks"></i>
                        <p>Belum ada subtask</p>
                    </div>
                `;
            }

            // Render attachments
            let attachmentsHtml = '';
            if (attachments.length > 0) {
                attachmentsHtml = `
                    <div class="attachments-grid">
                        ${attachments.map(attachment => {
                            const fileExtension = attachment.name.split('.').pop().toLowerCase();
                            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(fileExtension);
                            const safeFilename = attachment.name.length > 20 ? 
                                attachment.name.substring(0, 20) + '...' : attachment.name;
                            
                            if (isImage) {
                                return `
                                    <div class="attachment-item">
                                        <img src="../${attachment.path}?t=${Date.now()}" alt="${attachment.name}" 
                                             class="attachment-image" onclick="openImage('../${attachment.path}?t=${Date.now()}')">
                                    </div>
                                `;
                            } else {
                                let icon = 'fa-file';
                                if (fileExtension === 'pdf') icon = 'fa-file-pdf';
                                else if (['doc', 'docx'].includes(fileExtension)) icon = 'fa-file-word';
                                else if (['xls', 'xlsx'].includes(fileExtension)) icon = 'fa-file-excel';
                                else if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension)) icon = 'fa-image';

                                const fileSizeText = attachment.size > 0 ? 
                                    (attachment.size / 1024).toFixed(1) + ' KB' : '';

                                return `
                                    <div class="attachment-item">
                                        <div class="attachment-file" onclick="window.open('../${attachment.path}', '_blank')">
                                            <div class="attachment-file-icon"><i class="fas ${icon}"></i></div>
                                            <div class="attachment-file-name">${safeFilename}</div>
                                            ${fileSizeText ? 
                                                `<div class="attachment-file-type">${fileSizeText}</div>` : 
                                                `<div class="attachment-file-type">${fileExtension.toUpperCase()}</div>`}
                                        </div>
                                    </div>
                                `;
                            }
                        }).join('')}
                    </div>
                `;
            } else {
                attachmentsHtml = `
                    <div class="detail-no-data">
                        <i class="fas fa-paperclip"></i>
                        <p>Tidak ada lampiran</p>
                    </div>
                `;
            }

            // Render comments
            let commentsHtml = '';
            if (comments.length > 0) {
                commentsHtml = comments.map(comment => {
                    const initial = comment.username.charAt(0).toUpperCase();
                    return `
                        <div class="detail-comment-item">
                            <div class="detail-comment-header">
                                <div class="detail-comment-user">
                                    <div class="comment-avatar">${initial}</div>
                                    <div class="detail-comment-username">${comment.username}</div>
                                </div>
                                <div class="detail-comment-time">${comment.time_ago}</div>
                            </div>
                            <div class="detail-comment-text">${escapeHtml(comment.comment)}</div>
                        </div>
                    `;
                }).join('');
            } else {
                commentsHtml = `
                    <div class="detail-no-data">
                        <i class="fas fa-comment"></i>
                        <p>Belum ada komentar</p>
                    </div>
                `;
            }

            // Build HTML
            const html = `
                <div class="task-header">
                    <div id="detailStatusBadge" class="status-badge ${statusClass}">${statusText}</div>
                    <div class="title">${escapeHtml(task.title)}</div>
                </div>

                <!-- Progress Section -->
                <div class="progress-box">
                    <div class="progress-header">
                        <div class="progress-label">Progress</div>
                        <div class="progress-percentage" id="currentProgress">${task.progress}%</div>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar" id="progressBar" style="width: ${task.progress}%"></div>
                    </div>
                    
                    <div class="progress-slider">
                        <input type="range" id="progressSlider" min="0" max="100" value="${task.progress}">
                        <div class="progress-info">
                            <span>0%</span>
                            <span class="progress-stats" id="progressStats">
                                ${task.tasks_completed}/${task.tasks_total} selesai
                            </span>
                            <span>100%</span>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div class="detail-section">
                    <div class="detail-section-title">
                        <i class="fas fa-align-left"></i>
                        Deskripsi Tugas
                    </div>
                    <div class="detail-content">
                        ${task.note ? escapeHtml(task.note) : 'Tidak ada deskripsi'}
                    </div>
                </div>

                <!-- Dates -->
                <div class="detail-section">
                    <div class="detail-section-title">
                        <i class="fas fa-calendar-alt"></i>
                        Timeline Tugas
                    </div>
                    <div class="dates-grid">
                        <div class="date-box">
                            <div class="date-label">Tanggal Mulai</div>
                            <div class="date-value">${formatDate(task.start_date)}</div>
                        </div>
                        
                        <div class="date-box">
                            <div class="date-label">Tanggal Selesai</div>
                            <div class="date-value">${formatDate(task.end_date)}</div>
                        </div>
                    </div>
                </div>

                <!-- Creator & Assigned Users -->
                <div class="detail-section">
                    <div class="detail-section-title">
                        <i class="fas fa-users"></i>
                        Informasi Tim
                    </div>
                    
                    <!-- Creator -->
                    <div class="user-info-box">
                        <div class="user-info-label">Pembuat Tugas</div>
                        <div class="user-info-content">
                            <div class="avatar">
                                ${task.created_by ? task.created_by.charAt(0).toUpperCase() : '?'}
                            </div>
                            <div>
                                <div class="user-name">${task.created_by || 'Unknown'}</div>
                                <div class="user-role">Pembuat Tugas</div>
                            </div>
                        </div>
                    </div>

                    <!-- Assigned Users -->
                    <div>
                        <div class="assigned-users-label">Ditugaskan Kepada</div>
                        <div class="assigned-users-grid">
                            ${assignedUsersHtml || `
                                <div class="detail-no-data" style="padding: 20px 0;">
                                    <i class="fas fa-user-friends"></i>
                                    <p>Belum ada anggota yang ditugaskan</p>
                                </div>
                            `}
                        </div>
                    </div>
                </div>

                <!-- Subtasks by Member -->
                <div class="detail-section">
                    <div class="detail-section-title">
                        <i class="fas fa-tasks"></i>
                        Subtasks / Pekerjaan (${task.tasks_total})
                    </div>
                    ${subtasksHtml}
                </div>

                <!-- Attachments -->
                <div class="detail-section">
                    <div class="detail-section-title">
                        <i class="fas fa-paperclip"></i>
                        Lampiran (${attachments.length})
                    </div>
                    <div id="attachmentsSection">
                        ${attachmentsHtml}
                    </div>

                    <!-- File Upload -->
                    <div class="file-upload-wrapper" id="fileUploadWrapper">
                        <div class="detail-file-upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="detail-file-upload-text">Klik untuk upload file</div>
                        <div class="detail-file-upload-hint">atau drag & drop file di sini</div>
                    </div>
                    <input type="file" id="detailFileInput" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" style="display: none;">
                </div>

                <!-- Comments -->
                <div class="detail-section">
                    <div class="detail-section-title">
                        <i class="fas fa-comments"></i>
                        Komentar (${comments.length})
                    </div>
                    
                    <div id="commentsSection" style="max-height: 300px; overflow-y: auto; margin-bottom: 15px;">
                        ${commentsHtml}
                    </div>
                    
                    <div class="detail-comment-input-group">
                        <input type="text" id="commentInput" placeholder="Tulis komentar..." 
                               onkeypress="if(event.key === 'Enter') addCommentDetail()">
                        <button onclick="addCommentDetail()">
                            <i class="fas fa-paper-plane"></i> Kirim
                        </button>
                    </div>
                </div>
            `;

            taskDetailBody.innerHTML = html;

            // Setup progress slider
            const progressSlider = document.getElementById('progressSlider');
            const progressBar = document.getElementById('progressBar');
            const currentProgressElement = document.getElementById('currentProgress');

            if (progressSlider) {
                progressSlider.addEventListener('input', function() {
                    const progress = this.value;
                    progressBar.style.width = progress + '%';
                    currentProgressElement.textContent = progress + '%';
                });

                progressSlider.addEventListener('change', async function() {
                    const newProgress = parseInt(this.value);
                    await updateProgressDetail(newProgress);
                });
            }/*  */

            // Setup file upload
            const detailFileInput = document.getElementById('detailFileInput');
            const fileUploadWrapper = document.getElementById('fileUploadWrapper');

            if (fileUploadWrapper && detailFileInput) {
                fileUploadWrapper.addEventListener('click', () => {
                    detailFileInput.click();
                });

                detailFileInput.addEventListener('change', (e) => {
                    const files = e.target.files;
                    if (files.length > 0) {
                        uploadFilesDetail(files);
                    }
                });

                // Drag and drop
                fileUploadWrapper.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    fileUploadWrapper.classList.add('dragover');
                });

                fileUploadWrapper.addEventListener('dragleave', () => {
                    fileUploadWrapper.classList.remove('dragover');
                });

                fileUploadWrapper.addEventListener('drop', (e) => {
                    e.preventDefault();
                    fileUploadWrapper.classList.remove('dragover');

                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        uploadFilesDetail(files);
                    }
                });
            }
        }
    </script>
</body>
</html>