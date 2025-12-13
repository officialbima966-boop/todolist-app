<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

$username = $_SESSION['user'];
$taskId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($taskId <= 0) {
    echo "<script>alert('Tugas tidak ditemukan'); window.location.href='tasks.php';</script>";
    exit;
}

// Ambil data user yang login
$userQuery = $mysqli->query("SELECT * FROM users WHERE username = '$username'");
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

// Cek apakah user adalah pembuat tugas atau ditugaskan
if ($task['created_by'] !== $username && strpos($task['assigned_users'], $username) === false) {
    echo "<script>alert('Anda tidak memiliki akses untuk mengedit tugas ini'); window.location.href='tasks.php';</script>";
    exit;
}

// Ambil subtasks jika ada
$subtasks = [];
if (!empty($task['subtasks'])) {
    $subtasks = json_decode($task['subtasks'], true) ?: [];
}

// Ambil attachments jika ada
$attachments = [];
if (!empty($task['attachments'])) {
    $attachments = json_decode($task['attachments'], true) ?: [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $mysqli->real_escape_string($_POST['title']);
    $note = $mysqli->real_escape_string($_POST['note']);
    $startDate = $mysqli->real_escape_string($_POST['start_date']);
    $endDate = $mysqli->real_escape_string($_POST['end_date']);
    
    // Handle subtasks
    $subtasksJson = isset($_POST['subtasks']) ? $_POST['subtasks'] : '[]';
    $subtasksEncoded = $mysqli->real_escape_string($subtasksJson);
    
    // Handle file uploads for attachments
    $currentAttachments = $attachments;
    if (isset($_FILES['new_attachments'])) {
        $uploadDir = "../uploads/tasks/";
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        foreach ($_FILES['new_attachments']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['new_attachments']['error'][$key] === UPLOAD_ERR_OK) {
                $fileName = time() . '_' . basename($_FILES['new_attachments']['name'][$key]);
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($tmpName, $filePath)) {
                    $currentAttachments[] = [
                        'name' => $_FILES['new_attachments']['name'][$key],
                        'path' => $filePath,
                        'size' => $_FILES['new_attachments']['size'][$key]
                    ];
                }
            }
        }
    }
    
    // Handle attachment deletions
    if (isset($_POST['delete_attachments'])) {
        $deleteIndexes = json_decode($_POST['delete_attachments'], true);
        foreach ($deleteIndexes as $index) {
            if (isset($currentAttachments[$index])) {
                // Delete physical file
                if (file_exists($currentAttachments[$index]['path'])) {
                    unlink($currentAttachments[$index]['path']);
                }
                unset($currentAttachments[$index]);
            }
        }
        $currentAttachments = array_values($currentAttachments); // Reindex array
    }
    
    $attachmentsJson = json_encode($currentAttachments);
    $attachmentsEncoded = $mysqli->real_escape_string($attachmentsJson);

    $sql = "UPDATE tasks SET 
            title = '$title', 
            note = '$note', 
            start_date = '$startDate', 
            end_date = '$endDate',
            subtasks = '$subtasksEncoded',
            attachments = '$attachmentsEncoded'
            WHERE id = $taskId";

    if ($mysqli->query($sql)) {
        echo "<script>alert('Tugas berhasil diperbarui'); window.location.href='tasks.php';</script>";
    } else {
        echo "<script>alert('Gagal memperbarui tugas: " . $mysqli->error . "'); window.location.href='tasks.php';</script>";
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Edit Task - <?= htmlspecialchars($task["title"]) ?></title>
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

    .container {
        padding: 20px;
        max-width: 800px;
        margin: 0 auto;
        padding-bottom: 80px;
    }

    .form-section {
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
    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        color: #374151;
        background: white;
        transition: all 0.3s;
    }

    .form-group input:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #3550dc;
        box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
    }

    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .date-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .btn-group {
        display: flex;
        gap: 12px;
        margin-top: 30px;
    }

    .btn {
        flex: 1;
        padding: 14px 20px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-align: center;
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
        background: #2b44c9;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(53, 80, 220, 0.25);
    }

    /* Subtasks Section */
    .subtasks-section {
        background: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e5e7eb;
    }

    .subtask-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        background: #f9fafb;
        border-radius: 8px;
        margin-bottom: 10px;
        border: 1px solid #e5e7eb;
    }

    .subtask-checkbox {
        width: 20px;
        height: 20px;
        border: 2px solid #d1d5db;
        border-radius: 4px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: all 0.3s;
    }

    .subtask-checkbox.completed {
        background: #3550dc;
        border-color: #3550dc;
        color: white;
    }

    .subtask-text {
        flex: 1;
        font-size: 14px;
        color: #374151;
    }

    .subtask-text.completed {
        text-decoration: line-through;
        color: #9ca3af;
    }
    
    .subtask-remove {
        color: #ef4444;
        cursor: pointer;
        font-size: 18px;
        padding: 4px;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    
    .subtask-remove:hover {
        color: #dc2626;
        transform: scale(1.1);
    }
    
    .add-subtask-box {
        display: flex;
        gap: 10px;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #e5e7eb;
    }
    
    .add-subtask-input {
        flex: 1;
        padding: 10px 14px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        color: #374151;
        transition: all 0.3s;
    }
    
    .add-subtask-input:focus {
        outline: none;
        border-color: #3550dc;
        box-shadow: 0 0 0 3px rgba(53, 80, 220, 0.1);
    }
    
    .add-subtask-btn {
        padding: 10px 20px;
        background: #3550dc;
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        flex-shrink: 0;
    }
    
    .add-subtask-btn:hover {
        background: #2b44c9;
        transform: translateY(-1px);
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #9ca3af;
    }

    .empty-state i {
        font-size: 48px;
        margin-bottom: 12px;
        opacity: 0.3;
    }

    .empty-state p {
        font-size: 14px;
        margin: 0;
    }

    /* Attachments Section */
    .attachments-section {
        background: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e5e7eb;
    }

    .attachment-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: #f9fafb;
        border-radius: 8px;
        margin-bottom: 10px;
        border: 1px solid #e5e7eb;
        position: relative;
    }

    .attachment-item a {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        color: inherit;
        flex: 1;
    }
    
    .attachment-item:hover {
        background: #f3f4f6;
        border-color: #3550dc;
    }

    .attachment-icon {
        width: 40px;
        height: 40px;
        background: #3550dc;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 18px;
        flex-shrink: 0;
    }

    .attachment-info {
        flex: 1;
    }

    .attachment-name {
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 2px;
    }

    .attachment-size {
        font-size: 12px;
        color: #9ca3af;
    }

    .attachment-download {
        color: #3550dc;
        font-size: 18px;
        transition: all 0.2s;
        margin-right: 8px;
    }

    .attachment-download:hover {
        transform: scale(1.1);
    }
    
    .attachment-remove {
        color: #ef4444;
        cursor: pointer;
        font-size: 18px;
        padding: 4px;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    
    .attachment-remove:hover {
        color: #dc2626;
        transform: scale(1.1);
    }
    
    .add-attachment-box {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #e5e7eb;
    }
    
    .file-upload-area {
        border: 2px dashed #d1d5db;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s;
        background: #f9fafb;
    }
    
    .file-upload-area:hover {
        border-color: #3550dc;
        background: #f3f4f6;
    }
    
    .file-upload-area.dragover {
        border-color: #3550dc;
        background: #e8ecf4;
    }
    
    .file-upload-icon {
        font-size: 32px;
        color: #9ca3af;
        margin-bottom: 8px;
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

    @media (max-width: 768px) {
        .container {
            padding: 16px;
        }

        .date-row {
            grid-template-columns: 1fr;
        }

        .btn-group {
            flex-direction: column;
        }
    }
</style>
</head>
<body>

<div class="header">
    <div class="back-btn" onclick="window.location.href='tasks.php'">
        <i class="fas fa-arrow-left"></i>
    </div>
    <div class="header-title">Edit Tugas</div>
</div>

<div class="container">
    <form method="POST" action="" enctype="multipart/form-data">
        <div class="form-section">
            <div class="section-title">
                <i class="fas fa-edit"></i>
                Edit Tugas
            </div>

            <div class="form-group">
                <label for="title">Judul Tugas</label>
                <input type="text" id="title" name="title" value="<?= htmlspecialchars($task['title']) ?>" required>
            </div>

            <div class="date-row">
                <div class="form-group">
                    <label for="start_date">Tanggal Mulai</label>
                    <input type="date" id="start_date" name="start_date" value="<?= $task['start_date'] ?>" required>
                </div>

                <div class="form-group">
                    <label for="end_date">Tanggal Selesai</label>
                    <input type="date" id="end_date" name="end_date" value="<?= $task['end_date'] ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="note">Catatan / Deskripsi</label>
                <textarea id="note" name="note" placeholder="Tambahkan catatan atau instruksi..."><?= htmlspecialchars($task['note']) ?></textarea>
            </div>
        </div>

        <!-- Subtasks Section -->
        <div class="subtasks-section">
            <div class="section-title">
                <i class="fas fa-list-check"></i>
                Subtasks / Pekerjaan
            </div>

            <div id="subtasksList">
                <?php if (count($subtasks) > 0): ?>
                    <?php foreach ($subtasks as $index => $subtask): ?>
                    <div class="subtask-item" data-index="<?= $index ?>">
                        <div class="subtask-checkbox <?= $subtask['completed'] ? 'completed' : '' ?>" onclick="toggleSubtask(<?= $index ?>)">
                            <?php if ($subtask['completed']): ?>
                                <i class="fas fa-check"></i>
                            <?php endif; ?>
                        </div>
                        <div class="subtask-text <?= $subtask['completed'] ? 'completed' : '' ?>">
                            <?= htmlspecialchars($subtask['text']) ?>
                            <?php if (!empty($subtask['assigned'])): ?>
                                <div style="font-size: 12px; color: #9ca3af; margin-top: 4px;">
                                    <i class="fas fa-user"></i> Assigned to: <?= htmlspecialchars($subtask['assigned']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="subtask-remove" onclick="removeSubtask(<?= $index ?>)">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" id="emptySubtasks">
                        <i class="fas fa-list-check"></i>
                        <p>Belum ada subtask</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="add-subtask-box">
                <input type="text" id="newSubtaskInput" class="add-subtask-input" placeholder="Tambah subtask baru...">
                <button type="button" class="add-subtask-btn" onclick="addSubtask()">
                    <i class="fas fa-plus"></i> Tambah
                </button>
            </div>
            
            <input type="hidden" name="subtasks" id="subtasksData" value='<?= htmlspecialchars(json_encode($subtasks)) ?>'>
        </div>

        <!-- Attachments Section -->
        <div class="attachments-section">
            <div class="section-title">
                <i class="fas fa-paperclip"></i>
                Lampiran ( <span id="attachmentCount"><?= count($attachments) ?></span> )
            </div>

            <div id="attachmentsList">
                <?php if (count($attachments) > 0): ?>
                    <?php foreach ($attachments as $index => $attachment): ?>
                    <div class="attachment-item" data-index="<?= $index ?>">
                        <a href="<?= htmlspecialchars($attachment['path']) ?>" target="_blank">
                            <div class="attachment-icon">
                                <?php
                                $ext = strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION));
                                $icon = 'fa-file';
                                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                    $icon = 'fa-image';
                                } elseif ($ext === 'pdf') {
                                    $icon = 'fa-file-pdf';
                                } elseif (in_array($ext, ['doc', 'docx'])) {
                                    $icon = 'fa-file-word';
                                }
                                ?>
                                <i class="fas <?= $icon ?>"></i>
                            </div>
                            <div class="attachment-info">
                                <div class="attachment-name"><?= htmlspecialchars($attachment['name']) ?></div>
                                <div class="attachment-size">
                                    <?php
                                    if (isset($attachment['size'])) {
                                        echo number_format($attachment['size'] / 1024, 1) . ' KB';
                                    } elseif (file_exists($attachment['path'])) {
                                        echo number_format(filesize($attachment['path']) / 1024, 1) . ' KB';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="attachment-download">
                                <i class="fas fa-download"></i>
                            </div>
                        </a>
                        <div class="attachment-remove" onclick="removeAttachment(<?= $index ?>)">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" id="emptyAttachments">
                        <i class="fas fa-paperclip"></i>
                        <p>Tidak ada lampiran</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="add-attachment-box">
                <div class="file-upload-area" id="fileUploadArea" onclick="document.getElementById('fileInput').click()">
                    <div class="file-upload-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <div class="file-upload-text">Klik untuk upload file</div>
                    <div class="file-upload-hint">Maksimal 5MB per file</div>
                </div>
                <input type="file" id="fileInput" name="new_attachments[]" multiple style="display: none;" onchange="handleFileSelect(event)">
            </div>
            
            <input type="hidden" name="delete_attachments" id="deleteAttachmentsData" value="[]">
        </div>

        <div class="form-section">
            <div class="btn-group">
                <button type="button" class="btn btn-cancel" onclick="window.location.href='tasks.php'">Batal</button>
                <button type="submit" class="btn btn-save">Simpan Perubahan</button>
            </div>
        </div>
    </form>
</div>

<script>
// Subtasks Management
let subtasks = <?= json_encode($subtasks) ?>;

function renderSubtasks() {
    const container = document.getElementById('subtasksList');
    const emptyState = document.getElementById('emptySubtasks');
    
    if (subtasks.length === 0) {
        if (!emptyState) {
            container.innerHTML = `
                <div class="empty-state" id="emptySubtasks">
                    <i class="fas fa-list-check"></i>
                    <p>Belum ada subtask</p>
                </div>
            `;
        }
        return;
    }
    
    // Remove empty state if exists
    if (emptyState) {
        emptyState.remove();
    }
    
    container.innerHTML = subtasks.map((subtask, index) => `
        <div class="subtask-item" data-index="${index}">
            <div class="subtask-checkbox ${subtask.completed ? 'completed' : ''}" onclick="toggleSubtask(${index})">
                ${subtask.completed ? '<i class="fas fa-check"></i>' : ''}
            </div>
            <div class="subtask-text ${subtask.completed ? 'completed' : ''}">
                ${escapeHtml(subtask.text)}
                ${subtask.assigned ? `<div style="font-size: 12px; color: #9ca3af; margin-top: 4px;">
                    <i class="fas fa-user"></i> Assigned to: ${escapeHtml(subtask.assigned)}
                </div>` : ''}
            </div>
            <div class="subtask-remove" onclick="removeSubtask(${index})">
                <i class="fas fa-times"></i>
            </div>
        </div>
    `).join('');
    
    updateSubtasksData();
}

function addSubtask() {
    const input = document.getElementById('newSubtaskInput');
    const text = input.value.trim();
    
    if (!text) {
        alert('Masukkan text subtask!');
        return;
    }
    
    subtasks.push({
        text: text,
        completed: false,
        assigned: ''
    });
    
    input.value = '';
    renderSubtasks();
}

function toggleSubtask(index) {
    subtasks[index].completed = !subtasks[index].completed;
    renderSubtasks();
}

function removeSubtask(index) {
    if (confirm('Hapus subtask ini?')) {
        subtasks.splice(index, 1);
        renderSubtasks();
    }
}

function updateSubtasksData() {
    document.getElementById('subtasksData').value = JSON.stringify(subtasks);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Attachments Management
let deleteAttachments = [];
let currentAttachmentCount = <?= count($attachments) ?>;

function removeAttachment(index) {
    if (confirm('Hapus lampiran ini?')) {
        const item = document.querySelector(`.attachment-item[data-index="${index}"]`);
        if (item) {
            item.remove();
            deleteAttachments.push(index);
            document.getElementById('deleteAttachmentsData').value = JSON.stringify(deleteAttachments);
            
            // Update count
            currentAttachmentCount--;
            document.getElementById('attachmentCount').textContent = currentAttachmentCount;
            
            // Show empty state if no attachments
            const container = document.getElementById('attachmentsList');
            const items = container.querySelectorAll('.attachment-item');
            if (items.length === 0) {
                container.innerHTML = `
                    <div class="empty-state" id="emptyAttachments">
                        <i class="fas fa-paperclip"></i>
                        <p>Tidak ada lampiran</p>
                    </div>
                `;
            }
        }
    }
}

function handleFileSelect(event) {
    const files = event.target.files;
    if (files.length === 0) return;
    
    const container = document.getElementById('attachmentsList');
    const emptyState = document.getElementById('emptyAttachments');
    
    // Remove empty state if exists
    if (emptyState) {
        emptyState.remove();
    }
    
    // Add preview for new files
    Array.from(files).forEach((file, idx) => {
        const ext = file.name.split('.').pop().toLowerCase();
        let icon = 'fa-file';
        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
            icon = 'fa-image';
        } else if (ext === 'pdf') {
            icon = 'fa-file-pdf';
        } else if (['doc', 'docx'].includes(ext)) {
            icon = 'fa-file-word';
        }
        
        const preview = document.createElement('div');
        preview.className = 'attachment-item';
        preview.innerHTML = `
            <a href="#" onclick="return false;">
                <div class="attachment-icon">
                    <i class="fas ${icon}"></i>
                </div>
                <div class="attachment-info">
                    <div class="attachment-name">${escapeHtml(file.name)}</div>
                    <div class="attachment-size">${(file.size / 1024).toFixed(1)} KB</div>
                </div>
                <div class="attachment-download">
                    <i class="fas fa-upload"></i>
                </div>
            </a>
        `;
        container.appendChild(preview);
        
        currentAttachmentCount++;
    });
    
    // Update count
    document.getElementById('attachmentCount').textContent = currentAttachmentCount;
}

// Drag and drop for file upload
const fileUploadArea = document.getElementById('fileUploadArea');

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
    
    const fileInput = document.getElementById('fileInput');
    fileInput.files = e.dataTransfer.files;
    handleFileSelect({ target: fileInput });
});

// Allow Enter key to add subtask
document.getElementById('newSubtaskInput').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        addSubtask();
    }
});
</script>

</body>
</html>