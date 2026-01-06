<?php
session_start();
require_once "../inc/koneksi.php";

// Load notification handler jika ada (opsional untuk sementara)
$notificationEnabled = true; // Functions embedded in this file
// Notification functions are embedded below (no external file needed)

// Suppress PHP warnings/notices to prevent JSON corruption
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

// Cek login - support untuk user dan admin
if (!isset($_SESSION['user']) && !isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Ambil username dari session (bisa dari user atau admin)
$username = isset($_SESSION['user']) ? $_SESSION['user'] : $_SESSION['admin'];
$isAdmin = isset($_SESSION['admin']);
$taskId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($taskId <= 0) {
    echo "<script>alert('Tugas tidak ditemukan'); window.location.href='tasks.php';</script>";
    exit;
}

// Ambil data user yang login
// ============================================================
// NOTIFICATION FUNCTIONS (EMBEDDED)
// ============================================================

/**
 * Kirim notifikasi ke user(s)
 */
function sendNotification($mysqli, $taskId, $type, $message, $fromUser, $excludeUser = null) {
    // Ambil task info
    $taskQuery = $mysqli->query("SELECT created_by, assigned_users FROM tasks WHERE id = $taskId");
    
    if (!$taskQuery) {
        return 0;
    }
    
    $task = $taskQuery->fetch_assoc();
    if (!$task) {
        return 0;
    }
    
    $creator = $task['created_by'];
    $assignedUsers = $task['assigned_users'] ? explode(',', $task['assigned_users']) : [];
    
    // Build recipient list
    $recipients = [];
    
    // 1. Tambahkan semua assigned users
    foreach ($assignedUsers as $user) {
        $user = trim($user);
        if (!empty($user)) {
            $recipients[] = $user;
        }
    }
    
    // 2. Tambahkan creator jika belum ada di list
    if (!in_array($creator, $recipients)) {
        $recipients[] = $creator;
    }
    
    // 3. Remove excludeUser (biasanya yang melakukan aksi)
    if ($excludeUser) {
        $recipients = array_filter($recipients, function($user) use ($excludeUser) {
            return $user !== $excludeUser;
        });
    }
    
    // 4. Remove duplicates
    $recipients = array_unique($recipients);
    
    // Send notification to each recipient
    $sentCount = 0;
    $messageEscaped = $mysqli->real_escape_string($message);
    $typeEscaped = $mysqli->real_escape_string($type);
    $fromUserEscaped = $mysqli->real_escape_string($fromUser);
    
    foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        if (empty($recipient)) continue;
        
        $recipientEscaped = $mysqli->real_escape_string($recipient);
        
        $sql = "INSERT INTO notifications (username, task_id, type, message, from_user, created_at, is_read) 
                VALUES ('$recipientEscaped', $taskId, '$typeEscaped', '$messageEscaped', '$fromUserEscaped', NOW(), 0)";
        
        if ($mysqli->query($sql)) {
            $sentCount++;
        }
    }
    
    return $sentCount;
}

// ============================================================
// END NOTIFICATION FUNCTIONS
// ============================================================


if ($isAdmin) {
    $userQuery = $mysqli->query("SELECT * FROM admin WHERE username = '$username'");
} else {
    $userQuery = $mysqli->query("SELECT * FROM users WHERE username = '$username'");
}
$currentUser = $userQuery->fetch_assoc();

// Ambil data tugas
$stmt = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->bind_param("i", $taskId);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();

if (!$task) {
    echo "<script>alert('Tugas tidak ditemukan'); window.location.href='tasks.php';</script>";
    exit;
}
// Validasi akses: user hanya bisa akses task yang dibuat atau di-assign ke mereka
// Admin bisa akses semua task
if (!$isAdmin) {
    $assignedUsers = $task['assigned_users'] ? explode(',', $task['assigned_users']) : [];
    $hasAccess = ($task['created_by'] === $username) || in_array($username, $assignedUsers);
    
    if (!$hasAccess) {
        echo "<script>alert('Anda tidak memiliki akses ke tugas ini'); window.location.href='tasks.php';</script>";
        exit;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clear any previous output and start clean buffer
    ob_clean();
    ob_start();
    
    // Suppress any PHP errors from showing in JSON response
    error_reporting(0);
    header('Content-Type: application/json');
    
    // Polling untuk data real-time
    if ($_POST['action'] === 'get_task_data') {
        // Return current task data for polling
        $currentSubtasks = [];
        if (!empty($task['subtasks'])) {
            $currentSubtasks = json_decode($task['subtasks'], true);
            
            // Jika decode gagal, format ulang
            if ($currentSubtasks === null) {
                $subtasksArray = explode(',', $task['subtasks']);
                $currentSubtasks = [];
                foreach ($subtasksArray as $text) {
                    $text = trim($text);
                    if (!empty($text)) {
                        $currentSubtasks[] = [
                            'text' => $text,
                            'assigned' => '',
                            'completed' => false
                        ];
                    }
                }
            }
            
            if (!is_array($currentSubtasks)) {
                $currentSubtasks = [];
            }
        }

        echo json_encode([
            'success' => true,
            'task' => [
                'id' => $task['id'],
                'title' => $task['title'],
                'progress' => $task['progress'],
                'status' => $task['status'],
                'category' => $task['category'],
                'subtasks' => $currentSubtasks,
                'tasks_completed' => $task['tasks_completed'],
                'tasks_total' => $task['tasks_total'],
                'note' => $task['note'],
                'start_date' => $task['start_date'],
                'end_date' => $task['end_date'],
                'assigned_users' => $task['assigned_users'],
                'attachments' => $task['attachments']
            ]
        ]);
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
        exit;
    }
    
    if ($_POST['action'] === 'add_comment') {
        $taskId = (int)$_POST['taskId'];
        $comment = $mysqli->real_escape_string($_POST['comment']);
        $userId = $currentUser['id'];
        $username = $currentUser['username'];
        
        $sql = "INSERT INTO task_comments (task_id, user_id, username, comment) 
                VALUES ($taskId, $userId, '$username', '$comment')";
        
        if ($mysqli->query($sql)) {
            $mysqli->query("UPDATE tasks SET comments = comments + 1 WHERE id = $taskId");
            
            $newCommentQuery = $mysqli->query("SELECT * FROM task_comments WHERE id = LAST_INSERT_ID()");
            $newComment = $newCommentQuery->fetch_assoc();
            
            // ========== TAMBAHAN: KIRIM NOTIFIKASI ==========
            if ($notificationEnabled && function_exists('sendNotification')) {
                $taskInfoQuery = $mysqli->query("SELECT title FROM tasks WHERE id = $taskId");
                $taskInfo = $taskInfoQuery->fetch_assoc();
                $taskTitle = $taskInfo['title'];
                
                $message = "$username memberikan komentar di tugas \"$taskTitle\"";
                sendNotification($mysqli, $taskId, 'comment', $message, $username, $username);
            }
            // ========== AKHIR TAMBAHAN NOTIFIKASI ==========
            
            echo json_encode([
                'success' => true, 
                'username' => $username,
                'comment' => $comment,
                'created_at' => $newComment['created_at'],
                'commentId' => $newComment['id']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        exit;
    }
    
    // ========== TAMBAHAN: GET COMMENTS API ==========
    if ($_POST['action'] === 'get_comments') {
        // Clear any previous output
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        $taskId = (int)$_POST['taskId'];
        
        $commentsQuery = "SELECT * FROM task_comments WHERE task_id = $taskId ORDER BY created_at DESC";
        $result = $mysqli->query($commentsQuery);
        
        $comments = [];
        while ($row = $result->fetch_assoc()) {
            $isOwner = ($row['username'] === $username);
            $isEditable = $isOwner || $isAdmin;
            
            $comments[] = [
                'id' => $row['id'],
                'username' => $row['username'],
                'comment' => $row['comment'],
                'created_at' => $row['created_at'],
                'isOwner' => $isOwner,
                'canEdit' => $isOwner,
                'canDelete' => $isEditable
            ];
        }
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'comments' => $comments,
            'currentUsername' => $username
        ]);
        exit;
    }
    // ========== AKHIR TAMBAHAN GET COMMENTS ==========
    
    // ========== TAMBAHAN: EDIT KOMENTAR ==========
    if ($_POST['action'] === 'edit_comment') {
        // Clear any previous output
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        try {
            // Validate input
            if (!isset($_POST['commentId']) || !isset($_POST['comment'])) {
                throw new Exception('Missing required parameters');
            }
            
            $commentId = (int)$_POST['commentId'];
            $newComment = $mysqli->real_escape_string(trim($_POST['comment']));
            $username = $currentUser['username'];
            
            if (empty($newComment)) {
                throw new Exception('Comment cannot be empty');
            }
            
            // Cek apakah komentar milik user ini
            $checkQuery = $mysqli->query("SELECT * FROM task_comments WHERE id = $commentId AND username = '$username'");
            
            if (!$checkQuery) {
                throw new Exception('Database error: ' . $mysqli->error);
            }
            
            if ($checkQuery->num_rows > 0) {
                // Try update with updated_at, fallback without if column doesn't exist
                $sql = "UPDATE task_comments SET comment = '$newComment', updated_at = NOW() WHERE id = $commentId";
                
                if (!$mysqli->query($sql)) {
                    // If updated_at column doesn't exist, try without it
                    $sql = "UPDATE task_comments SET comment = '$newComment' WHERE id = $commentId";
                    
                    if (!$mysqli->query($sql)) {
                        throw new Exception('Update failed: ' . $mysqli->error);
                    }
                }
                
                ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'comment' => $newComment,
                    'commentId' => $commentId
                ]);
            } else {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => 'Unauthorized: You can only edit your own comments']);
            }
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode([
                'success' => false, 
                'error' => $e->getMessage(),
                'debug' => [
                    'commentId' => $_POST['commentId'] ?? 'not set',
                    'username' => $username ?? 'not set'
                ]
            ]);
        }
        exit;
    }
    
    // ========== TAMBAHAN: HAPUS KOMENTAR ==========
    if ($_POST['action'] === 'delete_comment') {
        // Clear any previous output
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        try {
            // Validate input
            if (!isset($_POST['commentId']) || !isset($_POST['taskId'])) {
                throw new Exception('Missing required parameters');
            }
            
            $commentId = (int)$_POST['commentId'];
            $taskId = (int)$_POST['taskId'];
            $username = $currentUser['username'];
            
            // Cek apakah komentar milik user ini atau user adalah admin
            $checkQuery = $mysqli->query("SELECT * FROM task_comments WHERE id = $commentId AND username = '$username'");
            
            if (!$checkQuery) {
                throw new Exception('Database error: ' . $mysqli->error);
            }
            
            if ($checkQuery->num_rows > 0 || $isAdmin) {
                $sql = "DELETE FROM task_comments WHERE id = $commentId";
                
                if (!$mysqli->query($sql)) {
                    throw new Exception('Delete failed: ' . $mysqli->error);
                }
                
                // Update comment count
                $mysqli->query("UPDATE tasks SET comments = GREATEST(comments - 1, 0) WHERE id = $taskId");
                
                ob_end_clean();
                echo json_encode(['success' => true]);
            } else {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => 'Unauthorized: You can only delete your own comments']);
            }
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode([
                'success' => false, 
                'error' => $e->getMessage(),
                'debug' => [
                    'commentId' => $_POST['commentId'] ?? 'not set',
                    'taskId' => $_POST['taskId'] ?? 'not set',
                    'username' => $username ?? 'not set'
                ]
            ]);
        }
        exit;
    }
    // ========== AKHIR TAMBAHAN EDIT/DELETE ==========
    
    if ($_POST['action'] === 'toggle_subtask') {
        $index = (int)$_POST['index'];
        $username = $_POST['username'] ?? '';
        $taskId = (int)$_POST['taskId'];

        // Get current subtasks
        $taskQuery = $mysqli->query("SELECT subtasks FROM tasks WHERE id = $taskId");
        $taskData = $taskQuery->fetch_assoc();
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
                        'completed_by' => '',
                        'completed' => false
                    ];
                }
            }
        }

        if (!is_array($subtasks)) {
            $subtasks = [];
        }

        if (isset($subtasks[$index]) && !empty($username)) {
            // Initialize completed_by as array
            if (!isset($subtasks[$index]['completed_by'])) {
                $subtasks[$index]['completed_by'] = [];
            } else if (!is_array($subtasks[$index]['completed_by'])) {
                $completedStr = $subtasks[$index]['completed_by'];
                $subtasks[$index]['completed_by'] = !empty($completedStr) 
                    ? array_map('trim', explode(',', $completedStr)) 
                    : [];
            }

            // Toggle user completion
            $completedBy = &$subtasks[$index]['completed_by'];
            $userIndex = array_search($username, $completedBy);
            
            $wasCompleted = ($userIndex !== false); // Track apakah user sudah complete sebelumnya
            
            if ($userIndex !== false) {
                // Remove user from completed list
                array_splice($completedBy, $userIndex, 1);
            } else {
                // Add user to completed list
                $completedBy[] = $username;
                
                // ========== TAMBAHAN: KIRIM NOTIFIKASI ==========
                // Hanya kirim notifikasi saat user MENYELESAIKAN (bukan membatalkan)
                if ($notificationEnabled && function_exists('sendNotification')) {
                    $taskInfoQuery = $mysqli->query("SELECT title FROM tasks WHERE id = $taskId");
                    $taskInfo = $taskInfoQuery->fetch_assoc();
                    $taskTitle = $taskInfo['title'];
                    
                    $subtaskText = $subtasks[$index]['text'];
                    $message = "$username menyelesaikan subtask \"$subtaskText\" di tugas \"$taskTitle\"";
                    sendNotification($mysqli, $taskId, 'subtask_complete', $message, $username, $username);
                }
                // ========== AKHIR TAMBAHAN NOTIFIKASI ==========
            }

            // Check if all assigned users completed - handle nested arrays
            $assignedUsers = [];
            if (isset($subtasks[$index]['assigned'])) {
                if (is_array($subtasks[$index]['assigned'])) {
                    // Flatten nested array
                    foreach ($subtasks[$index]['assigned'] as $item) {
                        if (is_array($item)) {
                            // Extract user from nested array
                            if (isset($item['user'])) {
                                $assignedUsers[] = $item['user'];
                            }
                        } else {
                            $assignedUsers[] = $item;
                        }
                    }
                } else {
                    $assignedUsers = array_map('trim', explode(',', $subtasks[$index]['assigned']));
                }
            }
            
            // Ensure all are strings and trim - handle arrays safely
            $assignedUsers = array_map(function($user) {
                if (is_array($user)) {
                    return isset($user['user']) ? trim($user['user']) : '';
                }
                return is_string($user) ? trim($user) : trim((string)$user);
            }, $assignedUsers);
            $assignedUsers = array_filter($assignedUsers);

            // Subtask is completed only if all assigned users completed
            $subtasks[$index]['completed'] = count($assignedUsers) > 0 && 
                count(array_diff($assignedUsers, $completedBy)) === 0;

            // Save back to database
            $subtasksJson = json_encode($subtasks);
            $subtasksEncoded = $mysqli->real_escape_string($subtasksJson);
            $mysqli->query("UPDATE tasks SET subtasks = '$subtasksEncoded' WHERE id = $taskId");

            // Calculate progress - HANYA hitung subtask yang punya assigned users
            $totalAssignedSubtasks = 0;
            $completedAssignedSubtasks = 0;
            
            // DEBUG: Log subtasks data
            error_log("DEBUG - Total subtasks: " . count($subtasks));
            
            foreach ($subtasks as $idx => $subtask) {
                error_log("DEBUG - Subtask #$idx: " . json_encode($subtask));
                // Parse assigned users
                $stAssignedUsers = [];
                if (isset($subtask['assigned'])) {
                    if (is_array($subtask['assigned'])) {
                        foreach ($subtask['assigned'] as $item) {
                            if (is_array($item) && isset($item['user'])) {
                                $stAssignedUsers[] = trim($item['user']);
                            } else {
                                $stAssignedUsers[] = trim((string)$item);
                            }
                        }
                    } else if (!empty($subtask['assigned'])) {
                        $stAssignedUsers = array_map('trim', explode(',', $subtask['assigned']));
                    }
                }
                $stAssignedUsers = array_filter($stAssignedUsers);
                
                error_log("DEBUG - Subtask #$idx assigned: " . json_encode($stAssignedUsers));
                
                // HANYA hitung subtask yang punya assigned user
                if (count($stAssignedUsers) > 0) {
                    $totalAssignedSubtasks++;
                    
                    // Parse completed_by
                    $stCompletedBy = [];
                    if (isset($subtask['completed_by'])) {
                        if (is_array($subtask['completed_by'])) {
                            $stCompletedBy = array_map(function($u) {
                                return is_string($u) ? trim($u) : trim((string)$u);
                            }, $subtask['completed_by']);
                        } else if (!empty($subtask['completed_by'])) {
                            $stCompletedBy = array_map('trim', explode(',', $subtask['completed_by']));
                        }
                    }
                    $stCompletedBy = array_filter($stCompletedBy);
                    
                    error_log("DEBUG - Subtask #$idx completed_by: " . json_encode($stCompletedBy));
                    
                    // Check if all assigned users completed
                    if (count(array_diff($stAssignedUsers, $stCompletedBy)) === 0) {
                        $completedAssignedSubtasks++;
                        error_log("DEBUG - Subtask #$idx COMPLETED!");
                    } else {
                        error_log("DEBUG - Subtask #$idx NOT completed, missing: " . json_encode(array_diff($stAssignedUsers, $stCompletedBy)));
                    }
                }
            }
            
            // Jika ada subtask dengan assigned users, hitung progress dari mereka saja
            // Jika tidak ada, hitung dari semua subtask
            $total = $totalAssignedSubtasks > 0 ? $totalAssignedSubtasks : count($subtasks);
            $completed = $totalAssignedSubtasks > 0 ? $completedAssignedSubtasks : 0;
            $progress = $total > 0 ? round(($completed / $total) * 100) : 0;
            
            error_log("DEBUG - FINAL: totalAssignedSubtasks=$totalAssignedSubtasks, completedAssignedSubtasks=$completedAssignedSubtasks");
            error_log("DEBUG - FINAL: total=$total, completed=$completed, progress=$progress%");

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

            
            // Clear any warnings/errors from output buffer
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_start();
            
            echo json_encode([
                'success' => true,
                'progress' => $progress,
                'completed' => $completed,
                'total' => $total,
                'subtasks' => $subtasks
            ]);
            
            ob_end_flush();
        } else {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo json_encode(['success' => false, 'error' => 'Subtask not found']);
        }
        exit;
    }

    if ($_POST['action'] === 'upload_attachments') {
        $taskId = (int)$_POST['taskId'];
        
        // FIXED: Path relatif yang benar
        // Dari: C:\xamppp\htdocs\htdocs\coba\user\task_detail.php
        // Ke:   C:\xamppp\htdocs\htdocs\coba\uploads\tasks\
        $uploadDir = __DIR__ . '/../uploads/tasks/';
        
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
                $fileType = $files['type'][$i];

                if ($fileError === UPLOAD_ERR_OK) {
                    // Generate unique filename
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $uniqueName = uniqid() . '_' . time() . '.' . $fileExtension;
                    $absolutePath = $uploadDir . $uniqueName;
                    
                    // Path untuk disimpan di database (relatif dari root project)
                    $relativePath = 'uploads/tasks/' . $uniqueName;

                    // Allowed file types
                    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'webp', 'bmp'];
                    if (!in_array($fileExtension, $allowedTypes)) {
                        $errors[] = "File type not allowed: $fileName";
                        continue;
                    }

                    // Max file size (10MB)
                    if ($fileSize > 10 * 1024 * 1024) {
                        $errors[] = "File too large: $fileName";
                        continue;
                    }

                    // Move uploaded file
                    if (move_uploaded_file($fileTmp, $absolutePath)) {
                        $uploadedFiles[] = [
                            'name' => $fileName,
                            'path' => $relativePath,
                            'size' => $fileSize,
                            'type' => $fileType
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
                                'size' => 0 // Size not available for old format
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
            
            // ========== TAMBAHAN: KIRIM NOTIFIKASI ==========
            if ($notificationEnabled && function_exists('sendNotification')) {
                // Ambil data task untuk judul
                $taskInfoQuery = $mysqli->query("SELECT title FROM tasks WHERE id = $taskId");
                $taskInfo = $taskInfoQuery->fetch_assoc();
                $taskTitle = $taskInfo['title'];
                
                // Hitung file dan cek jenis
                $fileCount = count($uploadedFiles);
                $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
                $allImages = true;
                
                foreach ($uploadedFiles as $file) {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $imageTypes)) {
                        $allImages = false;
                        break;
                    }
                }
                
                // Buat pesan notifikasi
                if ($allImages) {
                    if ($fileCount === 1) {
                        $message = "$username menambahkan 1 foto ke tugas \"$taskTitle\"";
                    } else {
                        $message = "$username menambahkan $fileCount foto ke tugas \"$taskTitle\"";
                    }
                    $notificationType = 'photo_upload';
                } else {
                    if ($fileCount === 1) {
                        $message = "$username menambahkan 1 file ke tugas \"$taskTitle\"";
                    } else {
                        $message = "$username menambahkan $fileCount file ke tugas \"$taskTitle\"";
                    }
                    $notificationType = 'file_upload';
                }
                
                // Kirim notifikasi ke semua anggota kecuali yang upload
                sendNotification($mysqli, $taskId, $notificationType, $message, $username, $username);
            }
            // ========== AKHIR TAMBAHAN NOTIFIKASI ==========
        }

        echo json_encode([
            'success' => empty($errors),
            'uploaded' => $uploadedFiles,
            'errors' => $errors
        ]);
        exit;
    }

}

// Get initial comments
$commentsQuery = "SELECT * FROM task_comments WHERE task_id = $taskId ORDER BY created_at DESC";
$commentsResult = $mysqli->query($commentsQuery);

// Get initial subtasks from JSON field (consistent with polling)
$subtasks = [];
if (!empty($task['subtasks'])) {
    $subtasksData = json_decode($task['subtasks'], true);
    
    // Handle decode errors or old format
    if ($subtasksData === null) {
        $subtasksArray = explode(',', $task['subtasks']);
        $subtasks = [];
        foreach ($subtasksArray as $text) {
            $text = trim($text);
            if (!empty($text)) {
                $subtasks[] = [
                    'text' => $text,
                    'assigned' => '',
                    'completed' => false
                ];
            }
        }
    } elseif (is_array($subtasksData)) {
        $subtasks = $subtasksData;
    }
}

function formatTimeAgo($dateString) {
    $date = new DateTime($dateString);
    $now = new DateTime();
    $interval = $now->diff($date);
    
    if ($interval->y > 0) return $interval->y . ' tahun lalu';
    if ($interval->m > 0) return $interval->m . ' bulan lalu';
    if ($interval->d > 0) return $interval->d . ' hari lalu';
    if ($interval->h > 0) return $interval->h . ' jam lalu';
    if ($interval->i > 0) return $interval->i . ' menit lalu';
    return 'Baru saja';

    if ($_POST['action'] === 'delete_task') {
        $taskId = (int)$_POST['taskId'];
        
        // Validasi: hanya pembuat yang bisa hapus (kecuali admin)
        if (!$isAdmin && $task['created_by'] !== $username) {
            echo json_encode(['success' => false, 'error' => 'Tidak memiliki akses']);
            exit;
        }
        
        // Hapus task
        $deleteQuery = $mysqli->prepare("DELETE FROM tasks WHERE id = ?");
        $deleteQuery->bind_param("i", $taskId);
        
        if ($deleteQuery->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Gagal menghapus task']);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Detail Task - <?= htmlspecialchars($task["title"]) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    * {
        margin: 0; padding: 0; box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
    }

    body {
        background: #f5f6fa;
        min-height: 100vh;
    }

    /* Header */
    .header {
        background: #3550dc;
        color: white;
        padding: 18px 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        position: sticky;
        top: 0;
        z-index: 10;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }

    .back-btn {
        font-size: 20px;
        cursor: pointer;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
        flex-shrink: 0;
    }

    .back-btn:hover {
        background: rgba(255, 255, 255, 0.25);
    }

    .header-title {
        font-size: 18px;
        font-weight: 600;
        flex: 1;
    }

    /* ========== TAMBAHAN: CSS UNTUK NOTIFICATION BELL DI HEADER ========== */
    .header-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    /* Adjust notification bell untuk header biru */
    .header .notification-bell-btn {
        color: white;
        background: rgba(255, 255, 255, 0.15);
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .header .notification-bell-btn:hover {
        background: rgba(255, 255, 255, 0.25);
        color: white;
    }
    
    .header .notification-badge {
        top: 2px;
        right: 2px;
    }
    /* ========== AKHIR TAMBAHAN CSS ========== */

    .header-menu {
        position: relative;
    }

    .menu-btn {
        background: rgba(255, 255, 255, 0.15);
        border: none;
        color: white;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 18px;
    }

    .menu-btn:hover {
        background: rgba(255, 255, 255, 0.25);
    }

    .menu-dropdown {
        position: absolute;
        top: 45px;
        right: 0;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        min-width: 180px;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.3s;
        z-index: 100;
    }

    .menu-dropdown.active {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .menu-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 18px;
        color: #1f2937;
        text-decoration: none;
        transition: background 0.2s;
        font-size: 14px;
        border-bottom: 1px solid #f3f4f6;
    }

    .menu-item:first-child {
        border-radius: 12px 12px 0 0;
    }

    .menu-item:last-child {
        border-radius: 0 0 12px 12px;
        border-bottom: none;
    }

    .menu-item:hover {
        background: #f9fafb;
    }

    .menu-item.delete-item {
        color: #ef4444;
    }

    .menu-item.delete-item:hover {
        background: #fef2f2;
    }

    .menu-item i {
        width: 16px;
        font-size: 14px;
    }

    .container {
        padding: 20px;
        max-width: 800px;
        margin: 0 auto;
        padding-bottom: 40px;
    }

    /* Main Task Info */
    .task-header {
        margin-bottom: 20px;
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

    /* Progress Section */
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

    .progress-slider input[type="range"]:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .progress-slider input[type="range"]:disabled::-webkit-slider-thumb {
        cursor: not-allowed;
        background: #9ca3af;
    }

    .progress-slider input[type="range"]:disabled::-moz-range-thumb {
        cursor: not-allowed;
        background: #9ca3af;
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

    /* Section Styling */
    .section {
        background: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e5e7eb;
    }

    .section-title {
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

    .section-title i {
        color: #3550dc;
        font-size: 14px;
    }

    /* Task Description */
    .task-description {
        color: #4b5563;
        line-height: 1.6;
        font-size: 14px;
        background: #f8faff;
        border-radius: 8px;
        padding: 15px;
        word-break: break-word;
    }

    /* Dates Section */
    .dates-section {
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

    /* User Info */
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

    /* Assigned Users */
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

    /* Subtasks - NEW STYLE */
    .subtasks-section {
        margin-bottom: 20px;
    }

    .subtask-item {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 16px;
        background: #f8faff;
        border-radius: 12px;
        margin-bottom: 12px;
        border: 2px solid #e0e5ed;
        transition: all 0.3s;
    }

    .subtask-item:hover {
        border-color: #C7D2FE;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .subtask-content-full {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .subtask-title-text {
        font-size: 15px;
        font-weight: 600;
        color: #1F2937;
        transition: all 0.3s;
    }

    .subtask-title-text.all-completed {
        text-decoration: line-through;
        color: #9CA3AF;
        opacity: 0.7;
    }

    .subtask-users-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }

    .user-badge-detail {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: linear-gradient(135deg, #EEF2FF 0%, #E0E7FF 100%);
        color: #4F46E5;
        padding: 6px 12px;
        border-radius: 16px;
        font-size: 13px;
        font-weight: 600;
        border: 1.5px solid #C7D2FE;
        cursor: pointer;
        transition: all 0.2s;
    }

    .user-badge-detail:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
    }

    .user-badge-detail.completed {
        background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
        color: #059669;
        border-color: #6EE7B7;
        text-decoration: line-through;
    }

    .user-badge-detail.completed i {
        text-decoration: none;
    }

    /* Attachments */
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

    /* Comments */
    .comments-section {
        max-height: 300px;
        overflow-y: auto;
        margin-bottom: 15px;
        padding-right: 5px;
    }

    .comment-item {
        background: #f8faff;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 10px;
        border-left: 4px solid #3550dc;
    }

    .comment-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 8px;
    }
    
    .comment-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .comment-user {
        display: flex;
        align-items: center;
        gap: 10px;
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

    .comment-username {
        font-weight: 600;
        color: #3550dc;
        font-size: 13px;
    }

    .comment-time {
        font-size: 11px;
        color: #9ca3af;
        text-align: right;
        flex-shrink: 0;
    }

    .comment-text {
        color: #333;
        font-size: 13px;
        line-height: 1.5;
        padding-left: 40px;
        word-break: break-word;
    }
    
    /* ========== TAMBAHAN: CSS UNTUK EDIT/DELETE KOMENTAR ========== */
    .comment-menu {
        position: relative;
    }
    
    .comment-menu-btn {
        background: none;
        border: none;
        color: #9ca3af;
        cursor: pointer;
        padding: 5px 8px;
        border-radius: 4px;
        transition: all 0.2s;
        font-size: 14px;
    }
    
    .comment-menu-btn:hover {
        background: rgba(0, 0, 0, 0.05);
        color: #666;
    }
    
    .comment-menu-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        min-width: 120px;
        display: none;
        z-index: 100;
        overflow: hidden;
    }
    
    .comment-menu-dropdown.active {
        display: block;
    }
    
    .comment-menu-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 15px;
        text-decoration: none;
        color: #374151;
        font-size: 13px;
        transition: background 0.2s;
        cursor: pointer;
    }
    
    .comment-menu-item:hover {
        background: #f3f4f6;
    }
    
    .comment-menu-item.delete-item {
        color: #ef4444;
    }
    
    .comment-menu-item.delete-item:hover {
        background: #fee2e2;
    }
    
    .comment-menu-item i {
        font-size: 12px;
    }
    
        .comment-edit-form {
        padding-left: 40px;
        margin-top: 8px;
    }
    
    /* Hidden by default via inline style */
    .comment-edit-form[style*="display: none"] {
        display: none;
    }
    
    /* Show when display is set to block */
    .comment-edit-form[style*="display: block"] {
        display: block;
    }
    
    .comment-text[style*="display: none"] {
        display: none;
    }
    
    .comment-edit-input {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid #3550dc;
        border-radius: 6px;
        font-size: 13px;
        font-family: inherit;
        outline: none;
        transition: all 0.2s;
    }
    
    .comment-edit-input:focus {
        box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
    }
    
    .comment-edit-actions {
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }
    
    .comment-edit-actions button {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .comment-edit-actions .btn-save {
        background: #3550dc;
        color: white;
    }
    
    .comment-edit-actions .btn-save:hover {
        background: #2940b8;
    }
    
    .comment-edit-actions .btn-cancel {
        background: #e5e7eb;
        color: #374151;
    }
    
    .comment-edit-actions .btn-cancel:hover {
        background: #d1d5db;
    }
    /* ========== AKHIR TAMBAHAN CSS ========== */

    .comment-input-group {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }

    .comment-input-group input {
        flex: 1;
        padding: 14px 18px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        transition: all 0.3s;
    }

    .comment-input-group input:focus {
        outline: none;
        border-color: #3550dc;
        box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
    }

    .comment-input-group button {
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

    .comment-input-group button:hover {
        background: #2b44c9;
    }

    /* No Data States */
    .no-data {
        text-align: center;
        padding: 30px 20px;
        color: #9ca3af;
    }

    .no-data i {
        font-size: 2.5rem;
        margin-bottom: 12px;
        opacity: 0.5;
    }

    .no-data p {
        font-size: 13px;
    }

    /* File Upload */
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

    .file-upload-icon {
        font-size: 40px;
        color: #3550dc;
        margin-bottom: 10px;
    }

    .file-upload-text {
        font-size: 14px;
        color: #6b7280;
        margin-bottom: 5px;
    }

    .file-upload-hint {
        font-size: 12px;
        color: #9ca3af;
    }

    #fileInput {
        display: none;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .container {
            padding: 16px;
        }
        
        .dates-section {
            grid-template-columns: 1fr;
        }
        
        .attachments-grid {
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 12px;
        }
        
        .header {
            padding: 16px;
        }
        
        .title {
            font-size: 20px;
        }
        
        .comment-input-group {
            flex-direction: column;
        }
        
        .comment-input-group input,
        .comment-input-group button {
            width: 100%;
        }
    }
</style>
</head>
<body>

<!-- Header Sederhana -->
<div class="header">
    <div class="back-btn" onclick="window.location.href='tasks.php'">
        <i class="fas fa-arrow-left"></i>
    </div>
    <div class="header-title">Detail Tugas</div>
    
    <!-- ========== TAMBAHAN: NOTIFICATION BELL ========== -->
    <div class="header-actions">
        <?php 
        // Include notification bell component
        $notificationBellPath = '../components/notification_bell.php';
        if (file_exists($notificationBellPath)) {
            include $notificationBellPath;
        }
        ?>
        
        <?php if ($task['created_by'] === $username): ?>
        <div class="header-menu">
            <button class="menu-btn" id="taskMenuBtn" onclick="toggleTaskMenu(event)">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <div class="menu-dropdown" id="taskMenuDropdown">
                <a href="edit_task.php?id=<?= $taskId ?>" class="menu-item">
                    <i class="fas fa-edit"></i> Edit Tugas
                </a>
                <a href="#" onclick="deleteTask(event)" class="menu-item delete-item">
                    <i class="fas fa-trash"></i> Hapus Tugas
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <!-- ========== AKHIR TAMBAHAN NOTIFICATION BELL ========== -->
</div>

<div class="container">
    <div class="task-header">
        <div id="statusBadge" class="status-badge <?= $task['status'] ?>">
            <?= $task['category'] ?>
        </div>
        <div class="title"><?= htmlspecialchars($task["title"]) ?></div>
    </div>

    <!-- Progress Section -->
    <div class="progress-box">
        <div class="progress-header">
            <div class="progress-label">Progress</div>
            <div class="progress-percentage" id="currentProgress"><?= $task["progress"] ?>%</div>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar" id="progressBar" style="width: <?= $task["progress"] ?>%"></div>
        </div>
        
        <div class="progress-slider">
            <input type="range" id="progressSlider" min="0" max="100" value="<?= $task["progress"] ?>" disabled>
            <div class="progress-info">
                <span>0%</span>
                <span class="progress-stats" id="progressStats">
                    <?= $task["tasks_completed"] ?>/<?= $task["tasks_total"] ?> selesai
                </span>
                <span>100%</span>
            </div>
        </div>
    </div>

    <!-- Description -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-align-left"></i>
            Deskripsi Tugas
        </div>
        <div class="task-description">
            <?= nl2br(htmlspecialchars($task["note"] ?: "Tidak ada deskripsi")) ?>
        </div>
    </div>

    <!-- Dates -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-calendar-alt"></i>
            Timeline Tugas
        </div>
        <div class="dates-section">
            <div class="date-box">
                <div class="date-label">Tanggal Mulai</div>
                <div class="date-value"><?= $task["start_date"] ? date('d/m/Y', strtotime($task["start_date"])) : '-' ?></div>
            </div>
            
            <div class="date-box">
                <div class="date-label">Tanggal Selesai</div>
                <div class="date-value"><?= $task["end_date"] ? date('d/m/Y', strtotime($task["end_date"])) : '-' ?></div>
            </div>
        </div>
    </div>

    <!-- Creator & Assigned Users -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-users"></i>
            Informasi Tim
        </div>
        
        <!-- Creator -->
        <div class="user-info-box">
            <div class="user-info-label">Pembuat Tugas</div>
            <div class="user-info-content">
                <div class="avatar">
                    <?= strtoupper(substr($task["created_by"], 0, 1)) ?>
                </div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($task["created_by"]) ?></div>
                    <div class="user-role">Pembuat Tugas</div>
                </div>
            </div>
        </div>

        <!-- Assigned Users -->
        <div>
            <div class="assigned-users-label">Ditugaskan Kepada</div>
            <div class="assigned-users-grid">
                <?php
                if (!empty($task["assigned_users"])) {
                    $assignedUsers = explode(',', $task["assigned_users"]);
                    foreach ($assignedUsers as $user) {
                        $user = trim($user);
                        if (empty($user)) continue;
                        
                        $initial = strtoupper(substr($user, 0, 1));
                        echo '<div class="assigned-user">';
                        echo '<div class="assigned-user-avatar">' . $initial . '</div>';
                        echo '<div class="assigned-user-name">' . htmlspecialchars($user) . '</div>';
                        echo '</div>';
                    }
                } else {
                    echo '<div class="no-data" style="padding: 20px 0;">';
                    echo '<i class="fas fa-user-friends"></i>';
                    echo '<p>Belum ada anggota yang ditugaskan</p>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Subtasks -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-tasks"></i>
            Subtasks / Pekerjaan
        </div>
        <div class="subtasks-section" id="subtasksSection">
            <?php
            if (count($subtasks) > 0) {
                foreach ($subtasks as $index => $subtask) {
                    // Pastikan subtask adalah array dengan format yang benar
                    if (is_array($subtask)) {
                        $subtaskText = isset($subtask['text']) ? $subtask['text'] : '';
                        $completed = isset($subtask['completed']) ? $subtask['completed'] : false;
                        
                        // Parse assigned users - handle nested arrays
                        $assignedUsers = [];
                        if (isset($subtask['assigned'])) {
                            if (is_array($subtask['assigned'])) {
                                // Flatten array dan konversi semua ke string
                                foreach ($subtask['assigned'] as $item) {
                                    if (is_array($item)) {
                                        // Jika item adalah array (nested), extract user
                                        if (isset($item['user'])) {
                                            $assignedUsers[] = $item['user'];
                                        }
                                    } else {
                                        // Jika item adalah string
                                        $assignedUsers[] = $item;
                                    }
                                }
                            } else if (!empty($subtask['assigned'])) {
                                $assignedUsers = explode(',', $subtask['assigned']);
                            }
                        }
                        
                        // Parse completed_by users - handle nested arrays
                        $completedBy = [];
                        if (isset($subtask['completed_by'])) {
                            if (is_array($subtask['completed_by'])) {
                                // Flatten array dan konversi semua ke string
                                foreach ($subtask['completed_by'] as $item) {
                                    if (is_array($item)) {
                                        // Skip nested arrays di completed_by
                                        continue;
                                    } else {
                                        $completedBy[] = $item;
                                    }
                                }
                            } else if (!empty($subtask['completed_by'])) {
                                $completedBy = explode(',', $subtask['completed_by']);
                            }
                        }
                    } else {
                        // Jika bukan array, anggap sebagai string
                        $subtaskText = (string)$subtask;
                        $completed = false;
                        $assignedUsers = [];
                        $completedBy = [];
                    }
                    
                    if (empty($subtaskText)) continue;
                    
                    // Check if all users completed - ensure all are strings
                    // Konversi semua elemen ke string terlebih dahulu
                    $assignedUsersString = array_map(function($user) {
                        return is_string($user) ? $user : (string)$user;
                    }, $assignedUsers);
                    $completedByString = array_map(function($user) {
                        return is_string($user) ? $user : (string)$user;
                    }, $completedBy);
                    
                    // Trim dan filter
                    $assignedUsersTrimmed = array_map('trim', $assignedUsersString);
                    $completedByTrimmed = array_map('trim', $completedByString);
                    $assignedUsersTrimmed = array_filter($assignedUsersTrimmed);
                    $completedByTrimmed = array_filter($completedByTrimmed);
                    
                    $allCompleted = count($assignedUsersTrimmed) > 0 && 
                        count(array_diff($assignedUsersTrimmed, $completedByTrimmed)) === 0;
                    $titleClass = $allCompleted ? 'subtask-title-text all-completed' : 'subtask-title-text';
                    
                    echo '<div class="subtask-item" data-index="' . $index . '">';
                    echo '<div class="subtask-content-full">';
                    echo '<div class="' . $titleClass . '">' . htmlspecialchars($subtaskText) . '</div>';
                    
                    // Display user badges
                    if (count($assignedUsers) > 0) {
                        echo '<div class="subtask-users-badges">';
                        foreach ($assignedUsers as $user) {
                            $user = trim($user);
                            if (empty($user)) continue;
                            
                            $isCompleted = in_array($user, $completedBy);
                            $badgeClass = $isCompleted ? 'user-badge-detail completed' : 'user-badge-detail';
                            $checkIcon = $isCompleted ? '<i class="fas fa-check"></i> ' : '';
                            
                            // Hanya user sendiri yang bisa klik badge mereka
                            $isCurrentUser = ($user === $username);
                            if ($isCurrentUser) {
                                // User bisa klik badge sendiri
                                echo '<span class="' . $badgeClass . '" onclick="toggleUserSubtask(' . $index . ', \'' . htmlspecialchars($user) . '\')" style="cursor: pointer;">';
                            } else {
                                // User lain tidak bisa diklik
                                echo '<span class="' . $badgeClass . '" style="cursor: not-allowed; opacity: 0.6;">';
                            }
                            echo $checkIcon . htmlspecialchars($user);
                            echo '</span>';
                        }
                        echo '</div>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                }
            } else {
                echo '<div class="no-data">';
                echo '<i class="fas fa-tasks"></i>';
                echo '<p>Belum ada subtask</p>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <!-- Attachments -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-paperclip"></i>
            Lampiran (<span id="attachmentCount">
                <?php
                $attachmentCount = 0;
                if (!empty($task["attachments"])) {
                    $attachmentsData = json_decode($task["attachments"], true);
                    if (is_array($attachmentsData)) {
                        $attachmentCount = count($attachmentsData);
                    }
                }
                echo $attachmentCount;
                ?>
            </span>)
        </div>
        <div id="attachmentsSection">
            <?php
            if (!empty($task["attachments"])) {
                // Coba decode JSON dulu
                $attachmentsData = json_decode($task["attachments"], true);
                
                // DEBUG: Uncomment untuk melihat data attachments
                // echo "<!-- DEBUG attachments: " . htmlspecialchars($task["attachments"]) . " -->";
                // echo "<!-- DEBUG decoded: " . print_r($attachmentsData, true) . " -->";
                
                if (is_array($attachmentsData) && count($attachmentsData) > 0) {
                    // Format JSON - data lengkap
                    echo '<div class="attachments-grid">';
                    foreach ($attachmentsData as $attachment) {
                        // Skip jika bukan array atau tidak punya key 'name'
                        if (!is_array($attachment) || !isset($attachment['name'])) continue;
                        
                        $filename = $attachment['name'];
                        $filePath = $attachment['path'] ?? '';
                        $fileSize = $attachment['size'] ?? 0;

                        if (empty($filename)) continue;

                        // Cek apakah path sudah lengkap atau perlu prepend
                        if (strpos($filePath, 'uploads/') === 0) {
                            // Path sudah relatif dari root, tambah ../ untuk naik satu level
                            $fullFilePath = '../' . $filePath;
                        } elseif (strpos($filePath, '/uploads/') === 0) {
                            // Path absolute, remove leading slash dan tambah ../
                            $fullFilePath = '..' . $filePath;
                        } else {
                            // Path lain, gunakan apa adanya
                            $fullFilePath = $filePath;
                        }

                        // DEBUG path
                        // echo "<!-- File: $filename | Path: $filePath | Full: $fullFilePath -->";

                        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $isImage = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
                        $safeFilename = strlen($filename) > 20 ? substr($filename, 0, 20) . '...' : $filename;

                        if ($isImage && !empty($filePath)) {
                            echo '<div class="attachment-item">';
                            echo '<img src="' . htmlspecialchars($fullFilePath) . '?t=' . time() . '" ';
                            echo 'alt="' . htmlspecialchars($filename) . '" ';
                            echo 'class="attachment-image" ';
                            echo 'onclick="openImage(\'' . htmlspecialchars($fullFilePath) . '?t=' . time() . '\')" ';
                            echo 'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23ddd\' width=\'200\' height=\'200\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3EImage not found%3C/text%3E%3C/svg%3E\';">';
                            echo '</div>';
                        } else {
                            $icon = 'fa-file';
                            if ($fileExtension === 'pdf') $icon = 'fa-file-pdf';
                            elseif (in_array($fileExtension, ['doc', 'docx'])) $icon = 'fa-file-word';
                            elseif (in_array($fileExtension, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
                            elseif (in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'fa-image';
                            
                            $fileSizeText = $fileSize > 0 ? number_format($fileSize / 1024, 1) . ' KB' : '';
                            
                            echo '<div class="attachment-item">';
                            echo '<div class="attachment-file" onclick="window.open(\'' . htmlspecialchars($fullFilePath) . '\', \'_blank\')">';
                            echo '<div class="attachment-file-icon"><i class="fas ' . $icon . '"></i></div>';
                            echo '<div class="attachment-file-name">' . htmlspecialchars($safeFilename) . '</div>';
                            if ($fileSizeText) {
                                echo '<div class="attachment-file-type">' . $fileSizeText . '</div>';
                            } else {
                                echo '<div class="attachment-file-type">' . strtoupper($fileExtension) . '</div>';
                            }
                            echo '</div>';
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                } else {
                    // Format lama - comma separated
                    $attachments = explode(',', $task["attachments"]);
                    $uploadDir = '../uploads/tasks/';
                    
                    echo '<div class="attachments-grid">';
                    foreach ($attachments as $filename) {
                        $filename = trim($filename);
                        if (empty($filename)) continue;
                        
                        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $isImage = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
                        $fileUrl = $uploadDir . $filename;
                        $safeFilename = strlen($filename) > 20 ? substr($filename, 0, 20) . '...' : $filename;
                        
                        if ($isImage) {
                            echo '<div class="attachment-item">';
                            echo '<img src="' . htmlspecialchars($fileUrl) . '?t=' . time() . '" ';
                            echo 'alt="' . htmlspecialchars($filename) . '" ';
                            echo 'class="attachment-image" ';
                            echo 'onclick="openImage(\'' . htmlspecialchars($fileUrl) . '?t=' . time() . '\')" ';
                            echo 'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23ddd\' width=\'200\' height=\'200\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3EImage not found%3C/text%3E%3C/svg%3E\';">';
                            echo '</div>';
                        } else {
                            $icon = 'fa-file';
                            if ($fileExtension === 'pdf') $icon = 'fa-file-pdf';
                            elseif (in_array($fileExtension, ['doc', 'docx'])) $icon = 'fa-file-word';
                            elseif (in_array($fileExtension, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
                            
                            echo '<div class="attachment-item">';
                            echo '<div class="attachment-file" onclick="window.open(\'' . htmlspecialchars($fileUrl) . '\', \'_blank\')">';
                            echo '<div class="attachment-file-icon"><i class="fas ' . $icon . '"></i></div>';
                            echo '<div class="attachment-file-name">' . htmlspecialchars($safeFilename) . '</div>';
                            echo '<div class="attachment-file-type">' . strtoupper($fileExtension) . '</div>';
                            echo '</div>';
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                }
            } else {
                echo '<div class="no-data">';
                echo '<i class="fas fa-paperclip"></i>';
                echo '<p>Tidak ada lampiran</p>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- File Upload -->
        <div class="file-upload-wrapper" id="fileUploadWrapper">
            <div class="file-upload-icon">
                <i class="fas fa-cloud-upload-alt"></i>
            </div>
            <div class="file-upload-text">Klik untuk upload file</div>
            <div class="file-upload-hint">atau drag & drop file di sini</div>
        </div>
        <input type="file" id="fileInput" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">
    </div>

    <!-- Comments -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-comments"></i>
            Komentar (<span id="commentCount">
                <?= $commentsResult->num_rows ?>
            </span>)
        </div>
        
        <div class="comments-section" id="commentsSection">
            <?php
            if ($commentsResult->num_rows > 0) {
                while ($comment = $commentsResult->fetch_assoc()) {
                    $initial = strtoupper(substr($comment['username'], 0, 1));
                    $timeAgo = formatTimeAgo($comment['created_at']);
                    $isOwner = ($comment['username'] === $username);
                    $commentId = $comment['id'];
                    
                    echo '<div class="comment-item" id="comment-' . $commentId . '">';
                    echo '<div class="comment-header">';
                    echo '<div class="comment-user">';
                    echo '<div class="comment-avatar">' . $initial . '</div>';
                    echo '<div class="comment-username">' . htmlspecialchars($comment['username']) . '</div>';
                    echo '</div>';
                    echo '<div class="comment-actions">';
                    echo '<div class="comment-time">' . $timeAgo . '</div>';
                    
                    // Tombol edit/delete hanya untuk pemilik komentar atau admin
                    if ($isOwner || $isAdmin) {
                        echo '<div class="comment-menu">';
                        echo '<button class="comment-menu-btn" onclick="toggleCommentMenu(event, ' . $commentId . ')">';
                        echo '<i class="fas fa-ellipsis-v"></i>';
                        echo '</button>';
                        echo '<div class="comment-menu-dropdown" id="commentMenu-' . $commentId . '">';
                        
                        if ($isOwner || $isAdmin) {
                            echo '<a href="#" onclick="editComment(event, ' . $commentId . ', \'' . htmlspecialchars(addslashes($comment['comment'])) . '\')" class="comment-menu-item">';
                            echo '<i class="fas fa-edit"></i> Edit';
                            echo '</a>';
                        }
                        
                        echo '<a href="#" onclick="deleteComment(event, ' . $commentId . ')" class="comment-menu-item delete-item">';
                        echo '<i class="fas fa-trash"></i> Hapus';
                        echo '</a>';
                        
                        echo '</div>';
                        echo '</div>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                    echo '<div class="comment-text" id="commentText-' . $commentId . '">' . htmlspecialchars($comment['comment']) . '</div>';
                    echo '<div class="comment-edit-form" id="commentEditForm-' . $commentId . '" style="display: none;">';
                    echo '<input type="text" class="comment-edit-input" id="commentEditInput-' . $commentId . '" value="' . htmlspecialchars($comment['comment']) . '">';
                    echo '<div class="comment-edit-actions">';
                    echo '<button type="button" class="btn-save" onclick="saveEditComment(' . $commentId . '); return false;">Simpan</button>';
                    echo '<button type="button" class="btn-cancel" onclick="cancelEditComment(' . $commentId . '); return false;">Batal</button>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
            } else {
                echo '<div class="no-data">';
                echo '<i class="fas fa-comment"></i>';
                echo '<p>Belum ada komentar</p>';
                echo '</div>';
            }
            ?>
        </div>
        
        <div class="comment-input-group">
            <input type="text" id="commentInput" placeholder="Tulis komentar..." onkeypress="if(event.key === 'Enter') addComment()">
            <button onclick="addComment()">
                <i class="fas fa-paper-plane"></i> Kirim
            </button>
        </div>
    </div>
</div>

<script>
    let currentTaskId = <?= $taskId ?>;
    let currentProgress = <?= $task["progress"] ?>;
    let lastUpdateTime = Date.now();
    let pollingInterval;
    let commentPollingInterval;
    let lastCommentCount = <?= $commentsResult->num_rows ?>;
    const currentUsername = "<?= htmlspecialchars($username) ?>";
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;

    // Progress Slider
    const progressSlider = document.getElementById('progressSlider');
    const progressBar = document.getElementById('progressBar');
    const currentProgressElement = document.getElementById('currentProgress');
    const statusBadge = document.getElementById('statusBadge');
    const progressStats = document.getElementById('progressStats');

    //     progressSlider.addEventListener('input', function() {
    //         const progress = this.value;
    //         progressBar.style.width = progress + '%';
    //         currentProgressElement.textContent = progress + '%';
    //     });
    // 
    //     progressSlider.addEventListener('change', async function() {
    //         const newProgress = parseInt(this.value);
    //         await updateProgress(newProgress);
    //     });

    async function updateProgress(progress) {
        try {
            const formData = new FormData();
            formData.append('action', 'update_progress');
            formData.append('taskId', currentTaskId);
            formData.append('progress', progress);

            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);

            const result = await response.json();

            if (result.success) {
                currentProgress = progress;
                statusBadge.textContent = result.category;
                statusBadge.className = 'status-badge ' + result.status;
                alert('Progress berhasil diperbarui!');
            } else {
                alert('Gagal memperbarui progress: ' + result.error);
                progressSlider.value = currentProgress;
                progressBar.style.width = currentProgress + '%';
                currentProgressElement.textContent = currentProgress + '%';
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat memperbarui progress');
        }
    }

    async function addComment() {
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

            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);

            const result = await response.json();

            if (result.success) {
                commentInput.value = '';
                
                // Trigger polling segera untuk update UI
                await pollComments();
                
                const commentsSection = document.getElementById('commentsSection');
                commentsSection.scrollTop = 0;
            } else {
                alert('Gagal menambahkan komentar: ' + result.error);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menambahkan komentar');
        }
    }
    
    // ========== TAMBAHAN: FUNGSI EDIT/DELETE KOMENTAR ==========
    
    // Toggle comment menu dropdown - MUST BE GLOBAL for inline onclick
    window.toggleCommentMenu = function(event, commentId) {
        event.stopPropagation();
        const dropdown = document.getElementById(`commentMenu-${commentId}`);
        
        if (!dropdown) {
            console.error('Dropdown not found for comment:', commentId);
            return;
        }
        
        // Close all other dropdowns
        document.querySelectorAll('.comment-menu-dropdown').forEach(d => {
            if (d.id !== `commentMenu-${commentId}`) {
                d.classList.remove('active');
            }
        });
        
        dropdown.classList.toggle('active');
    };
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.comment-menu')) {
            document.querySelectorAll('.comment-menu-dropdown').forEach(dropdown => {
                dropdown.classList.remove('active');
            });
        }
    });
    
    // Edit comment - MUST BE GLOBAL
    window.editComment = function(event, commentId, currentComment) {
        event.preventDefault();
        console.log('[EDIT] Comment ID:', commentId);
        
        // Hide menu
        const menu = document.getElementById(`commentMenu-${commentId}`);
        if (menu) {
            menu.classList.remove('active');
        }
        
        // Hide text, show edit form
        const textEl = document.getElementById(`commentText-${commentId}`);
        const formEl = document.getElementById(`commentEditForm-${commentId}`);
        
        console.log('[EDIT] Text element:', textEl);
        console.log('[EDIT] Form element:', formEl);
        
        if (textEl && formEl) {
            console.log('[EDIT] Before - Text display:', textEl.style.display);
            console.log('[EDIT] Before - Form display:', formEl.style.display);
            
            textEl.style.display = 'none';
            formEl.style.display = 'block';
            
            console.log('[EDIT] After - Text display:', textEl.style.display);
            console.log('[EDIT] After - Form display:', formEl.style.display);
            console.log('[EDIT] Form visible?', formEl.offsetParent !== null);
            
            // Focus on input
            const input = document.getElementById(`commentEditInput-${commentId}`);
            if (input) {
                input.focus();
                input.select();
            }
        } else {
            console.error('[EDIT ERROR] Elements not found!');
            if (!textEl) console.error('[EDIT ERROR] Text element missing for ID:', commentId);
            if (!formEl) console.error('[EDIT ERROR] Form element missing for ID:', commentId);
        }
    };
    
    // Save edited comment - MUST BE GLOBAL
    window.saveEditComment = async function(commentId) {
        console.log('[SAVE] Starting save for comment ID:', commentId);
        
        const input = document.getElementById(`commentEditInput-${commentId}`);
        console.log('[SAVE] Input element:', input);
        
        if (!input) {
            console.error('[SAVE ERROR] Input element not found!');
            alert('Error: Input tidak ditemukan untuk comment ID: ' + commentId);
            return;
        }
        
        const newComment = input.value.trim();
        console.log('[SAVE] New comment value:', newComment);
        
        if (!newComment) {
            alert('Komentar tidak boleh kosong');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'edit_comment');
            formData.append('commentId', commentId);
            formData.append('comment', newComment);
            
            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);
            
            // Cek apakah response OK
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // Get response text first untuk debug
            const responseText = await response.text();
            
            // Try parse JSON
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Response is not valid JSON:', responseText);
                alert('Server error: Response tidak valid.\n\nCek console (F12) untuk detail.');
                return;
            }
            
            if (result.success) {
                console.log('[SAVE SUCCESS] Comment updated successfully');
                
                // Update UI immediately
                const textEl = document.getElementById(`commentText-${commentId}`);
                const formEl = document.getElementById(`commentEditForm-${commentId}`);
                
                if (textEl && formEl) {
                    console.log('[SAVE] Updating UI...');
                    textEl.textContent = newComment;
                    textEl.style.display = 'block';
                    formEl.style.display = 'none';
                    console.log('[SAVE] UI updated - form hidden, text shown');
                }
                
                // Trigger polling untuk refresh dari server
                console.log('[SAVE] Triggering pollComments...');
                if (typeof pollComments === 'function') {
                    try {
                        await pollComments();
                        console.log('[SAVE] pollComments completed');
                    } catch (pollError) {
                        console.warn('[SAVE] pollComments error:', pollError);
                    }
                } else {
                    console.warn('[SAVE] pollComments not available');
                }
            } else {
                // Show detailed error
                let errorMsg = 'Gagal mengedit komentar:\n' + (result.error || 'Unknown error');
                
                if (result.debug) {
                    errorMsg += '\n\nDebug Info:';
                    for (let key in result.debug) {
                        errorMsg += '\n- ' + key + ': ' + result.debug[key];
                    }
                }
                
                console.error('Edit comment error:', result);
                alert(errorMsg);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat mengedit komentar:\n' + error.message);
        }
    };
    
    // Cancel edit comment - MUST BE GLOBAL
    window.cancelEditComment = function(commentId) {
        // Hide edit form, show text
        const formEl = document.getElementById(`commentEditForm-${commentId}`);
        const textEl = document.getElementById(`commentText-${commentId}`);
        
        if (formEl && textEl) {
            formEl.style.display = 'none';
            textEl.style.display = 'block';
        }
    };
    
    // Delete comment - MUST BE GLOBAL
    window.deleteComment = async function(event, commentId) {
        event.preventDefault();
        
        if (!confirm('Apakah Anda yakin ingin menghapus komentar ini?')) {
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'delete_comment');
            formData.append('commentId', commentId);
            formData.append('taskId', currentTaskId);
            
            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const responseText = await response.text();
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Response is not valid JSON:', responseText);
                alert('Server error: Response tidak valid. Cek console untuk detail.');
                return;
            }
            
            if (result.success) {
                console.log('[DELETE SUCCESS] Comment deleted successfully');
                
                // Remove comment element from DOM immediately
                const commentEl = document.getElementById(`comment-${commentId}`);
                if (commentEl) {
                    console.log('[DELETE] Removing comment element from DOM...');
                    commentEl.remove();
                    console.log('[DELETE] Comment element removed');
                }
                
                // Trigger polling untuk refresh dari server
                console.log('[DELETE] Triggering pollComments...');
                if (typeof pollComments === 'function') {
                    try {
                        await pollComments();
                        console.log('[DELETE] pollComments completed');
                    } catch (pollError) {
                        console.warn('[DELETE] pollComments error:', pollError);
                    }
                } else {
                    console.warn('[DELETE] pollComments not available');
                }
            } else {
                // Show detailed error
                let errorMsg = 'Gagal menghapus komentar:\n' + (result.error || 'Unknown error');
                
                if (result.debug) {
                    errorMsg += '\n\nDebug Info:';
                    for (let key in result.debug) {
                        errorMsg += '\n- ' + key + ': ' + result.debug[key];
                    }
                }
                
                console.error('Delete comment error:', result);
                alert(errorMsg);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menghapus komentar:\n' + error.message);
        }
    };
    // ========== AKHIR TAMBAHAN FUNGSI ==========
    
    // ========== TAMBAHAN: AUTO-REFRESH COMMENTS ==========
    
    // Function untuk render comments dari API
    function renderComments(comments) {
        const commentsSection = document.getElementById('commentsSection');
        
        if (comments.length === 0) {
            commentsSection.innerHTML = `
                <div class="no-data">
                    <i class="fas fa-comment"></i>
                    <p>Belum ada komentar</p>
                </div>
            `;
            return;
        }
        
        const commentsHTML = comments.map(comment => {
            const initial = comment.username.charAt(0).toUpperCase();
            const timeAgo = formatTimeAgo(comment.created_at);
            
            // Hanya tampilkan menu jika user adalah owner atau admin
            const showMenu = comment.canEdit || comment.canDelete;
            
            return `
                <div class="comment-item" id="comment-${comment.id}">
                    <div class="comment-header">
                        <div class="comment-user">
                            <div class="comment-avatar">${initial}</div>
                            <div class="comment-username">${escapeHtml(comment.username)}</div>
                        </div>
                        <div class="comment-actions">
                            <div class="comment-time" data-created-at="${comment.created_at}">${timeAgo}</div>
                            ${showMenu ? `
                                <div class="comment-menu">
                                    <button class="comment-menu-btn" onclick="toggleCommentMenu(event, ${comment.id})">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div class="comment-menu-dropdown" id="commentMenu-${comment.id}">
                                        ${comment.canEdit ? `
                                            <a href="#" onclick="editComment(event, ${comment.id}, '${escapeHtml(comment.comment).replace(/'/g, "\\'")}' )" class="comment-menu-item">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                        ` : ''}
                                        ${comment.canDelete ? `
                                            <a href="#" onclick="deleteComment(event, ${comment.id})" class="comment-menu-item delete-item">
                                                <i class="fas fa-trash"></i> Hapus
                                            </a>
                                        ` : ''}
                                    </div>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                    <div class="comment-text" id="commentText-${comment.id}">${escapeHtml(comment.comment)}</div>
                    <div class="comment-edit-form" id="commentEditForm-${comment.id}" style="display: none;">
                        <input type="text" class="comment-edit-input" id="commentEditInput-${comment.id}" value="${escapeHtml(comment.comment)}">
                        <div class="comment-edit-actions">
                            <button type="button" class="btn-save" onclick="saveEditComment(${comment.id}); return false;">Simpan</button>
                            <button type="button" class="btn-cancel" onclick="cancelEditComment(${comment.id}); return false;">Batal</button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        
        commentsSection.innerHTML = commentsHTML;
    }
    
    // Helper function untuk escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Helper function untuk format time ago
    function formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000); // seconds
        
        if (diff < 10) return 'Baru saja';
        if (diff < 60) return diff + ' detik yang lalu';
        if (diff < 3600) {
            const minutes = Math.floor(diff / 60);
            return minutes + ' menit yang lalu';
        }
        if (diff < 86400) {
            const hours = Math.floor(diff / 3600);
            return hours + ' jam yang lalu';
        }
        if (diff < 2592000) {
            const days = Math.floor(diff / 86400);
            return days + ' hari yang lalu';
        }
        if (diff < 31536000) {
            const months = Math.floor(diff / 2592000);
            return months + ' bulan yang lalu';
        }
        const years = Math.floor(diff / 31536000);
        return years + ' tahun yang lalu';
    }
    
    // Fungsi untuk update waktu relatif secara otomatis
    function updateAllCommentTimes() {
        document.querySelectorAll('.comment-time').forEach(timeElement => {
            const createdAt = timeElement.getAttribute('data-created-at');
            if (createdAt) {
                timeElement.textContent = formatTimeAgo(createdAt);
            }
        });
    }
    
    // Update waktu setiap 60 detik
    setInterval(updateAllCommentTimes, 60000);
    
    // Polling untuk update comments otomatis
    async function pollComments() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_comments');
            formData.append('taskId', currentTaskId);
            
            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);
            
            const result = await response.json();
            
            if (result.success) {
                // Update comment count
                const commentCount = document.getElementById('commentCount');
                if (commentCount) {
                    commentCount.textContent = result.comments.length;
                }
                
                // Jika jumlah comment berubah, render ulang
                if (result.comments.length !== lastCommentCount) {
                    renderComments(result.comments);
                    lastCommentCount = result.comments.length;
                }
            }
        } catch (error) {
            console.error('Error polling comments:', error);
        }
    }
    
    // Start polling setiap 5 detik
    commentPollingInterval = setInterval(pollComments, 5000);
    
    // ========== AKHIR AUTO-REFRESH COMMENTS ==========

    function renderSubtasks(subtasks) {
        const subtasksSection = document.getElementById('subtasksSection');

        if (subtasks.length === 0) {
            subtasksSection.innerHTML = `
                <div class="no-data">
                    <i class="fas fa-tasks"></i>
                    <p>Belum ada subtask</p>
                </div>
            `;
            progressStats.textContent = '0/0 selesai';
            return;
        }

        const completed = subtasks.filter(st => st.completed).length;
        const total = subtasks.length;

        progressStats.textContent = `${completed}/${total} selesai`;

        subtasksSection.innerHTML = subtasks.map((subtask, index) => {
            // Parse assigned users - handle nested objects
            let assignedUsers = [];
            if (Array.isArray(subtask.assigned)) {
                assignedUsers = subtask.assigned.map(u => {
                    // Jika u adalah object dengan property 'user'
                    if (typeof u === 'object' && u !== null && u.user) {
                        return String(u.user).trim();
                    }
                    // Jika u adalah string biasa
                    return String(u).trim();
                }).filter(u => u && u !== '[object Object]');
            } else if (subtask.assigned) {
                assignedUsers = subtask.assigned.split(',').map(u => u.trim()).filter(u => u);
            }
            
            // Parse completed_by users - handle nested objects
            let completedBy = [];
            if (Array.isArray(subtask.completed_by)) {
                completedBy = subtask.completed_by.map(u => {
                    // Jika u adalah object, skip
                    if (typeof u === 'object' && u !== null) {
                        return '';
                    }
                    return String(u).trim();
                }).filter(u => u && u !== '[object Object]');
            } else if (subtask.completed_by) {
                completedBy = subtask.completed_by.split(',').map(u => u.trim()).filter(u => u);
            }
            
            // Check if all users completed
            const allCompleted = assignedUsers.length > 0 && 
                assignedUsers.every(user => completedBy.includes(user));
            const titleClass = allCompleted ? 'subtask-title-text all-completed' : 'subtask-title-text';
            
            // Generate user badges HTML
            let userBadgesHtml = '';
            if (assignedUsers.length > 0) {
                userBadgesHtml = '<div class="subtask-users-badges">';
                assignedUsers.forEach(user => {
                    const isCompleted = completedBy.includes(user);
                    const badgeClass = isCompleted ? 'user-badge-detail completed' : 'user-badge-detail';
                    const checkIcon = isCompleted ? '<i class="fas fa-check"></i> ' : '';
                    
                    // Hanya user sendiri yang bisa klik badge mereka
                    const isCurrentUser = (user === currentUsername);
                    const onclick = isCurrentUser ? `onclick="toggleUserSubtask(${index}, '${escapeHtml(user)}')"` : '';
                    const cursorStyle = isCurrentUser ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;';
                    
                    userBadgesHtml += `
                        <span class="${badgeClass}" ${onclick} style="${cursorStyle}">
                            ${checkIcon}${escapeHtml(user)}
                        </span>
                    `;
                });
                userBadgesHtml += '</div>';
            }

            return `
                <div class="subtask-item" data-index="${index}">
                    <div class="subtask-content-full">
                        <div class="${titleClass}">${escapeHtml(subtask.text)}</div>
                        ${userBadgesHtml}
                    </div>
                </div>
            `;
        }).join('');
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Toggle user completion for subtask
    async function toggleUserSubtask(index, username) {
        try {
            const formData = new FormData();
            formData.append('action', 'toggle_subtask');
            formData.append('index', index);
            formData.append('username', username);
            formData.append('taskId', currentTaskId);

            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);

            const result = await response.json();

            if (result.success) {
                progressSlider.value = result.progress;
                progressBar.style.width = result.progress + '%';
                currentProgressElement.textContent = result.progress + '%';
                progressStats.textContent = `${result.completed}/${result.total} selesai`;
                
                // Render subtasks with new data
                renderSubtasks(result.subtasks);
                
                let category = 'Belum Dijalankan';
                let status = 'todo';
                
                if (result.progress > 0 && result.progress < 100) {
                    category = 'Sedang Berjalan';
                    status = 'progress';
                } else if (result.progress === 100) {
                    category = 'Selesai';
                    status = 'completed';
                }
                
                statusBadge.textContent = category;
                statusBadge.className = 'status-badge ' + status;
                currentProgress = result.progress;
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat mengupdate subtask');
        }
    }

    function openImage(imageUrl) {
        window.open(imageUrl, '_blank', 'width=800,height=600');
    }

    // Polling for real-time updates
    async function pollTaskData() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_task_data');
            formData.append('taskId', currentTaskId);

            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);

            const result = await response.json();

            if (result.success) {
                const taskData = result.task;

                // Update progress if changed
                if (taskData.progress !== currentProgress) {
                    currentProgress = taskData.progress;
                    progressSlider.value = currentProgress;
                    progressBar.style.width = currentProgress + '%';
                    currentProgressElement.textContent = currentProgress + '%';

                    // Update status badge
                    statusBadge.textContent = taskData.category;
                    statusBadge.className = 'status-badge ' + taskData.status;
                }

                // Update subtasks if changed
                const newSubtasks = taskData.subtasks;
                const existingSubtasks = Array.from(document.querySelectorAll('.subtask-item')).map(item => {
                    const index = parseInt(item.dataset.index);
                    const title = item.querySelector('.subtask-title-text');
                    const badges = item.querySelectorAll('.user-badge-detail');
                    
                    // Extract user completion data
                    const assignedUsers = Array.from(badges).map(badge => {
                        return badge.textContent.trim().replace(/^✔\s*/, '');
                    });
                    
                    const completedBy = Array.from(badges).filter(badge => 
                        badge.classList.contains('completed')
                    ).map(badge => badge.textContent.trim().replace(/^✔\s*/, ''));
                    
                    const allCompleted = assignedUsers.length > 0 && 
                        assignedUsers.length === completedBy.length;
                    
                    return {
                        text: title ? title.textContent : '',
                        completed: allCompleted,
                        assigned: assignedUsers,
                        completed_by: completedBy
                    };
                });

                if (JSON.stringify(newSubtasks) !== JSON.stringify(existingSubtasks)) {
                    renderSubtasks(newSubtasks);
                }

                // Update progress stats
                progressStats.textContent = `${taskData.tasks_completed}/${taskData.tasks_total} selesai`;

                lastUpdateTime = Date.now();
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }

    function startPolling() {
        // Poll every 5 seconds
        pollingInterval = setInterval(pollTaskData, 5000);
    }

    function stopPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
        }
    }

    // File Upload Functionality
    const fileUploadWrapper = document.getElementById('fileUploadWrapper');
    const fileInput = document.getElementById('fileInput');
    const attachmentsSection = document.getElementById('attachmentsSection');
    const attachmentCount = document.getElementById('attachmentCount');

    // Click to upload
    fileUploadWrapper.addEventListener('click', () => {
        fileInput.click();
    });

    // File input change
    fileInput.addEventListener('change', (e) => {
        const files = e.target.files;
        if (files.length > 0) {
            uploadFiles(files);
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
            uploadFiles(files);
        }
    });

    async function uploadFiles(files) {
        const formData = new FormData();
        formData.append('action', 'upload_attachments');
        formData.append('taskId', currentTaskId);

        // Handle multiple files
        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        try {
            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);

            const result = await response.json();

            if (result.success) {
                if (result.uploaded && result.uploaded.length > 0) {
                    // Update attachments section
                    updateAttachmentsSection(result.uploaded);
                    alert(`Berhasil upload ${result.uploaded.length} file!`);
                }

                if (result.errors && result.errors.length > 0) {
                    alert('Beberapa file gagal diupload:\n' + result.errors.join('\n'));
                }
            } else {
                alert('Gagal upload file: ' + (result.errors ? result.errors.join('\n') : 'Unknown error'));
            }
        } catch (error) {
            console.error('Upload error:', error);
            alert('Terjadi kesalahan saat upload file');
        }
    }

    function updateAttachmentsSection(newAttachments) {
        // Get current attachments count
        let currentCount = parseInt(attachmentCount.textContent) || 0;
        currentCount += newAttachments.length;
        attachmentCount.textContent = currentCount;

        // Remove no-data message if exists
        const noData = attachmentsSection.querySelector('.no-data');
        if (noData) {
            noData.remove();
        }

        // Create or get attachments grid
        let attachmentsGrid = attachmentsSection.querySelector('.attachments-grid');
        if (!attachmentsGrid) {
            attachmentsGrid = document.createElement('div');
            attachmentsGrid.className = 'attachments-grid';
            attachmentsSection.appendChild(attachmentsGrid);
        }

        // Add new attachments
        newAttachments.forEach(attachment => {
            const fileExtension = attachment.name.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(fileExtension);
            const safeFilename = attachment.name.length > 20 ? attachment.name.substring(0, 20) + '...' : attachment.name;

            let attachmentHTML = '';

            if (isImage) {
                attachmentHTML = `
                    <div class="attachment-item">
                        <img src="../${attachment.path}?t=${Date.now()}" alt="${attachment.name}" class="attachment-image" onclick="openImage('../${attachment.path}?t=${Date.now()}')">
                    </div>
                `;
            } else {
                let icon = 'fa-file';
                if (fileExtension === 'pdf') icon = 'fa-file-pdf';
                else if (['doc', 'docx'].includes(fileExtension)) icon = 'fa-file-word';
                else if (['xls', 'xlsx'].includes(fileExtension)) icon = 'fa-file-excel';

                const fileSizeText = attachment.size > 0 ? (attachment.size / 1024).toFixed(1) + ' KB' : '';

                attachmentHTML = `
                    <div class="attachment-item">
                        <div class="attachment-file" onclick="window.open('../${attachment.path}', '_blank')">
                            <div class="attachment-file-icon"><i class="fas ${icon}"></i></div>
                            <div class="attachment-file-name">${safeFilename}</div>
                            <div class="attachment-file-type">${fileSizeText || fileExtension.toUpperCase()}</div>
                        </div>
                    </div>
                `;
            }

            attachmentsGrid.insertAdjacentHTML('beforeend', attachmentHTML);
        });
    }

    // Start polling when page loads
    window.addEventListener('load', startPolling);

    // Stop polling when page unloads
    window.addEventListener('beforeunload', stopPolling);

    // Toggle task menu
    function toggleTaskMenu(event) {
        event.stopPropagation();
        const dropdown = document.getElementById('taskMenuDropdown');
        dropdown.classList.toggle('active');
    }

    // Close menu when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('taskMenuDropdown');
        const menuBtn = document.getElementById('taskMenuBtn');
        
        if (dropdown && !dropdown.contains(event.target) && !menuBtn.contains(event.target)) {
            dropdown.classList.remove('active');
        }
    });

    // Delete task function
    async function deleteTask(event) {
        event.preventDefault();
        
        if (!confirm('Apakah Anda yakin ingin menghapus tugas ini?')) {
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'delete_task');
            formData.append('taskId', currentTaskId);
            
            console.log('[SAVE] Sending request to server...');
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            console.log('[SAVE] Response received, status:', response.status);
            
            const result = await response.json();
            
            if (result.success) {
                alert('Tugas berhasil dihapus!');
                window.location.href = 'tasks.php';g
            } else {
                alert('Gagal menghapus tugas: ' + result.error);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menghapus tugas');
        }
    }
</script>

</body>
</html>