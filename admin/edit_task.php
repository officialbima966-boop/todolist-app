<?php
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

// Ambil ID task dari URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: tasks.php");
    exit;
}

$taskId = intval($_GET['id']);

// Ambil data task
$taskQuery = $mysqli->prepare("SELECT * FROM tasks WHERE id = ?");
$taskQuery->bind_param("i", $taskId);
$taskQuery->execute();
$taskResult = $taskQuery->get_result();

if ($taskResult->num_rows == 0) {
    header("Location: tasks.php?error=task_not_found");
    exit;
}

$taskData = $taskResult->fetch_assoc();

// Cek apakah user adalah pembuat task atau ditugaskan ke task ini
if ($taskData['created_by'] !== $username && !str_contains($taskData['assigned_users'], $username)) {
    header("Location: tasks.php?error=unauthorized");
    exit;
}

// Ambil semua user untuk assign
$usersQuery = $mysqli->query("SELECT * FROM users WHERE username != '$username'");

// Proses update jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $status = $_POST['status'];
    $progress = $_POST['progress'];
    
    // Ambil assigned users
    $assignedUsers = [];
    if (isset($_POST['assigned_users']) && is_array($_POST['assigned_users'])) {
        $assignedUsers = $_POST['assigned_users'];
    }
    $assignedUsersStr = implode(',', $assignedUsers);
    
    // Update query
    $updateQuery = $mysqli->prepare("
        UPDATE tasks 
        SET title = ?, description = ?, start_date = ?, end_date = ?, 
            status = ?, progress = ?, assigned_users = ?
        WHERE id = ?
    ");
    
    $updateQuery->bind_param(
        "sssssisi",
        $title,
        $description,
        $start_date,
        $end_date,
        $status,
        $progress,
        $assignedUsersStr,
        $taskId
    );
    
    if ($updateQuery->execute()) {
        header("Location: tasks.php?success=task_updated");
        exit;
    } else {
        $error = "Gagal mengupdate tugas";
    }
}

// Ambil subtasks jika ada (dari JSON atau format lainnya)
$subtasks = [];
if (!empty($taskData['subtasks'])) {
    $subtasks = json_decode($taskData['subtasks'], true);
    if ($subtasks === null) {
        $subtasks = [];
    }
}

// Parse assigned users
$assignedUsersArray = $taskData['assigned_users'] ? explode(',', $taskData['assigned_users']) : [];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Edit Task - <?= htmlspecialchars($taskData['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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

        /* Content */
        .content {
            padding: 20px 15px;
        }

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }

        .form-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
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
            border-color: #3550dc;
            box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
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

        /* Assign Users */
        .assign-users-section {
            margin-top: 20px;
        }

        .assign-users-title {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .assign-users-title i {
            color: #3550dc;
        }

        .assign-users-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 15px;
        }

        .assign-user-item {
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

        .assign-user-item.selected {
            background: #eff6ff;
            border-color: #3550dc;
        }

        .assign-user-avatar {
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

        .user1 { background: linear-gradient(135deg, #f59e0b, #f97316); }
        .user2 { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .user3 { background: linear-gradient(135deg, #10b981, #059669); }
        .user4 { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

        .assign-user-item span {
            font-size: 12px;
            color: #374151;
        }

        /* Subtasks */
        .subtasks-section {
            margin-top: 20px;
        }

        .subtasks-title {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subtasks-title i {
            color: #3550dc;
        }

        .subtask-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f3f4f6;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .subtask-checkbox {
            width: 16px;
            height: 16px;
            border: 2px solid #d1d5db;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: #10b981;
        }

        .subtask-text {
            flex: 1;
            font-size: 14px;
            color: #374151;
        }

        .add-subtask-form {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }

        .add-subtask-form input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
        }

        .add-subtask-form button {
            background: #3550dc;
            color: white;
            border: none;
            border-radius: 8px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .add-subtask-form button:hover {
            background: #2b44c9;
        }

        /* Progress Section */
        .progress-section {
            margin-top: 20px;
        }

        .progress-title {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-title i {
            color: #3550dc;
        }

        .progress-bar-container {
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
            height: 8px;
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

        .progress-input {
            margin-top: 10px;
        }

        .progress-input input[type="range"] {
            width: 100%;
            height: 6px;
            -webkit-appearance: none;
            background: #e8ecf4;
            border-radius: 10px;
            outline: none;
        }

        .progress-input input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #3550dc;
            cursor: pointer;
            border: 3px solid white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        .btn {
            flex: 1;
            padding: 14px 20px;
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

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .status-badge.todo {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.progress {
            background: #e8ecf4;
            color: #3550dc;
        }

        .status-badge.completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.selected {
            border-color: currentColor;
            transform: scale(1.05);
        }

        .status-selector {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Error Message */
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .container {
                padding: 0;
            }

            .content {
                padding: 15px;
            }

            .form-container {
                padding: 15px;
            }

            .form-row {
                flex-direction: column;
                gap: 16px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <button class="back-btn" onclick="window.history.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">Edit Tugas</div>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Error Message -->
            <?php if (isset($error)): ?>
                <div class="error-message show">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="form-container">
                <!-- Basic Info -->
                <div class="form-group">
                    <label for="title">Judul Tugas</label>
                    <input type="text" id="title" name="title" value="<?= htmlspecialchars($taskData['title']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">Deskripsi</label>
                    <textarea id="description" name="description"><?= htmlspecialchars($taskData['description'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Tanggal Mulai</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="start_date" name="start_date" value="<?= $taskData['start_date'] ?>" required>
                            <i class="far fa-calendar-alt"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="end_date">Tanggal Selesai</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="end_date" name="end_date" value="<?= $taskData['end_date'] ?>" required>
                            <i class="far fa-calendar-alt"></i>
                        </div>
                    </div>
                </div>

                <!-- Status Selector -->
                <div class="form-group">
                    <label>Status Tugas</label>
                    <div class="status-selector">
                        <span class="status-badge todo <?= $taskData['status'] === 'todo' ? 'selected' : '' ?>" onclick="selectStatus('todo')">
                            Belum Dimulai
                        </span>
                        <span class="status-badge progress <?= $taskData['status'] === 'progress' ? 'selected' : '' ?>" onclick="selectStatus('progress')">
                            Sedang Berjalan
                        </span>
                        <span class="status-badge completed <?= $taskData['status'] === 'completed' ? 'selected' : '' ?>" onclick="selectStatus('completed')">
                            Selesai
                        </span>
                    </div>
                    <input type="hidden" id="status" name="status" value="<?= $taskData['status'] ?>">
                </div>

                <!-- Progress Section -->
                <div class="progress-section">
                    <div class="progress-title">
                        <i class="fas fa-chart-line"></i>
                        Progress
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-label">
                            <span class="progress-label-text">Progress</span>
                            <span class="progress-percentage" id="progressValue"><?= $taskData['progress'] ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" id="progressFill" style="width: <?= $taskData['progress'] ?>%"></div>
                        </div>
                    </div>
                    <div class="progress-input">
                        <input type="range" id="progress" name="progress" min="0" max="100" value="<?= $taskData['progress'] ?>" oninput="updateProgress(this.value)">
                    </div>
                    <input type="hidden" id="progressHidden" name="progress" value="<?= $taskData['progress'] ?>">
                </div>

                <!-- Assign Users -->
                <div class="assign-users-section">
                    <div class="assign-users-title">
                        <i class="fas fa-users"></i>
                        Assign ke Anggota Tim
                    </div>
                    <div class="assign-users-list" id="assignUsersList">
                        <?php
                        $userIndex = 1;
                        $usersQuery->data_seek(0);
                        while ($userRow = $usersQuery->fetch_assoc()):
                            $initial = strtoupper(substr($userRow['username'], 0, 1));
                            $userClass = 'user' . ($userIndex % 4 + 1);
                            $isSelected = in_array($userRow['username'], $assignedUsersArray);
                        ?>
                            <div class="assign-user-item <?= $isSelected ? 'selected' : '' ?>" 
                                 data-user-id="<?= $userRow['id'] ?>" 
                                 data-user-name="<?= $userRow['username'] ?>"
                                 onclick="toggleUserSelection(this)">
                                <div class="assign-user-avatar <?= $userClass ?>"><?= $initial ?></div>
                                <span><?= htmlspecialchars($userRow['username']) ?></span>
                            </div>
                        <?php 
                            $userIndex++;
                        endwhile; 
                        ?>
                    </div>
                    <input type="hidden" id="assignedUsers" name="assigned_users[]" value="<?= implode(',', $assignedUsersArray) ?>">
                </div>

                <!-- Subtasks -->
                <div class="subtasks-section">
                    <div class="subtasks-title">
                        <i class="fas fa-tasks"></i>
                        Subtasks / Pekerjaan
                    </div>
                    <div id="subtasksContainer">
                        <?php foreach ($subtasks as $index => $subtask): ?>
                            <div class="subtask-item">
                                <div class="subtask-checkbox <?= $subtask['completed'] ? 'checked' : '' ?>" onclick="toggleSubtask(this)">
                                    <?php if ($subtask['completed']): ?>
                                        <i class="fas fa-check"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="subtask-text"><?= htmlspecialchars($subtask['text']) ?></div>
                                <?php if (!empty($subtask['assigned'])): ?>
                                    <small style="color: #6b7280; font-size: 11px;">
                                        (<?= htmlspecialchars($subtask['assigned']) ?>)
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="add-subtask-form">
                        <input type="text" id="newSubtask" placeholder="Tambah subtask...">
                        <button type="button" onclick="addSubtask()">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="window.history.back()">Batal</button>
                    <button type="submit" class="btn btn-save">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Status selection
        function selectStatus(status) {
            document.getElementById('status').value = status;
            
            // Update UI
            document.querySelectorAll('.status-badge').forEach(badge => {
                badge.classList.remove('selected');
            });
            
            document.querySelectorAll('.status-badge.' + status).forEach(badge => {
                badge.classList.add('selected');
            });
        }

        // Progress update
        function updateProgress(value) {
            document.getElementById('progressValue').textContent = value + '%';
            document.getElementById('progressFill').style.width = value + '%';
            document.getElementById('progressHidden').value = value;
        }

        // User selection
        let selectedUsers = <?= json_encode($assignedUsersArray) ?>;

        function toggleUserSelection(element) {
            const userName = element.getAttribute('data-user-name');
            const index = selectedUsers.indexOf(userName);
            
            if (index > -1) {
                selectedUsers.splice(index, 1);
                element.classList.remove('selected');
            } else {
                selectedUsers.push(userName);
                element.classList.add('selected');
            }
            
            document.getElementById('assignedUsers').value = selectedUsers.join(',');
        }

        // Subtasks
        let subtasks = <?= json_encode($subtasks) ?>;

        function addSubtask() {
            const input = document.getElementById('newSubtask');
            const text = input.value.trim();
            
            if (!text) return;
            
            subtasks.push({
                text: text,
                completed: false,
                assigned: '' // You can add user assignment here if needed
            });
            
            updateSubtasks();
            input.value = '';
        }

        function toggleSubtask(element) {
            const subtaskText = element.nextElementSibling.textContent;
            const index = subtasks.findIndex(s => s.text === subtaskText);
            
            if (index > -1) {
                subtasks[index].completed = !subtasks[index].completed;
                element.classList.toggle('checked');
                
                if (subtasks[index].completed) {
                    element.innerHTML = '<i class="fas fa-check"></i>';
                } else {
                    element.innerHTML = '';
                }
            }
        }

        function updateSubtasks() {
            const container = document.getElementById('subtasksContainer');
            container.innerHTML = '';
            
            subtasks.forEach(subtask => {
                const div = document.createElement('div');
                div.className = 'subtask-item';
                div.innerHTML = `
                    <div class="subtask-checkbox ${subtask.completed ? 'checked' : ''}" onclick="toggleSubtask(this)">
                        ${subtask.completed ? '<i class="fas fa-check"></i>' : ''}
                    </div>
                    <div class="subtask-text">${subtask.text}</div>
                    ${subtask.assigned ? `<small style="color: #6b7280; font-size: 11px;">(${subtask.assigned})</small>` : ''}
                `;
                container.appendChild(div);
            });
            
            // Update hidden field for form submission
            const subtasksInput = document.createElement('input');
            subtasksInput.type = 'hidden';
            subtasksInput.name = 'subtasks';
            subtasksInput.value = JSON.stringify(subtasks);
            
            // Remove existing if any
            const existing = document.querySelector('input[name="subtasks"]');
            if (existing) existing.remove();
            
            document.querySelector('form').appendChild(subtasksInput);
        }

        // Initialize subtasks hidden field
        updateSubtasks();

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('start_date').value);
            const endDate = new Date(document.getElementById('end_date').value);
            
            if (endDate < startDate) {
                e.preventDefault();
                alert('Tanggal selesai tidak boleh sebelum tanggal mulai');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>