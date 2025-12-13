<?php
session_start();
require_once __DIR__ . '/../inc/koneksi.php';

// Allow only logged-in users (either role 'user' or 'admin')
if (!isset($_SESSION['user']) && !isset($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = $_SESSION['user'] ?? $_SESSION['admin'] ?? '';

// Prepare query: select common columns, keep consistent with admin page
$stmt = $mysqli->prepare("SELECT id, username, COALESCE(nama_lengkap, name, '') AS nama_lengkap, email, COALESCE(phone, '') AS phone, COALESCE(role, 'user') AS role, COALESCE(foto, '') AS foto FROM users WHERE username != ? AND aktif = 1 ORDER BY nama_lengkap ASC");
$stmt->bind_param('s', $currentUser);
$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'users' => $users]);
