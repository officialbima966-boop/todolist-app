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
$userData = $result->fetch_assoc();
$userQuery->close();

// Jika user tidak ditemukan, redirect ke login
if (!$userData) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Proses penyimpanan perubahan
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nama = trim($_POST['nama']);
    $email = trim($_POST['email']);
    $no_hp = trim($_POST['no_hp']);
    $foto_baru = $userData['foto'] ?? null;

    // Upload foto baru jika ada
    if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] == 0) {
        $targetDir = "../uploads/";

        // Buat folder uploads jika belum ada
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // Validasi tipe file
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        $fileType = $_FILES['foto']['type'];

        if (!in_array($fileType, $allowedTypes)) {
            $error = "Hanya file gambar (JPG, PNG, GIF) yang diizinkan!";
        } else {
            $namaFile = time() . "_" . uniqid() . "_" . basename($_FILES["foto"]["name"]);
            $targetFile = $targetDir . $namaFile;

            if (move_uploaded_file($_FILES["foto"]["tmp_name"], $targetFile)) {
                $foto_baru = $namaFile;

                // Hapus foto lama jika ada
                if (!empty($userData['foto']) && file_exists($targetDir . $userData['foto'])) {
                    unlink($targetDir . $userData['foto']);
                }
            } else {
                $error = "Gagal mengupload foto!";
            }
        }
    }

    // Jika tidak ada error, update data
    if (!isset($error)) {
        $updateQuery = "UPDATE users SET nama = ?, email = ?, no_hp = ?, foto = ? WHERE username = ?";
        $stmt = $mysqli->prepare($updateQuery);

        if ($stmt) {
            $stmt->bind_param("sssss", $nama, $email, $no_hp, $foto_baru, $username);

            if ($stmt->execute()) {
                $success = "Profil berhasil diperbarui!";
                // Refresh data user
                $userQuery = $mysqli->prepare("SELECT * FROM users WHERE username = ?");
                $userQuery->bind_param("s", $username);
                $userQuery->execute();
                $result = $userQuery->get_result();
                $userData = $result->fetch_assoc();
                $userQuery->close();

                // Update session
                $_SESSION['user_data'] = $userData;
            } else {
                $error = "Gagal memperbarui profil: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Gagal mempersiapkan query: " . $mysqli->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Edit Profile | BM Garage</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f8faff;
            min-height: 100vh;
            padding-bottom: 90px;
        }

        header {
            background: linear-gradient(135deg, #0022a8, #0044ff);
            color: #fff;
            padding: 25px 20px 40px;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
            position: relative;
            text-align: center;
        }

        .back-button {
            position: absolute;
            left: 20px;
            top: 25px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .header-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-top: 10px;
        }

        .container {
            padding: 30px 20px;
            max-width: 500px;
            margin: 0 auto;
        }

        form {
            background: #fff;
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            font-weight: 600;
            color: #333;
            display: block;
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: #2455ff;
            box-shadow: 0 0 0 2px rgba(36, 85, 255, 0.1);
        }

        .save-btn {
            width: 100%;
            padding: 12px;
            background: #2455ff;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .save-btn:hover {
            background: #003ee0;
            transform: translateY(-2px);
        }

        .save-btn:active {
            transform: translateY(0);
        }

        .profile-photo {
            text-align: center;
            margin-bottom: 20px;
        }

        .profile-photo img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
            border: 3px solid #2455ff;
            background: #f0f0f0;
        }

        .error-message {
            background: #ffebee;
            color: #c62828;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
            border: 1px solid #ffcdd2;
        }

        .success-message {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
            border: 1px solid #c8e6c9;
        }

        .file-input-container {
            position: relative;
            display: inline-block;
            width: 100%;
            margin-bottom: 5px;
        }

        .file-input-label {
            display: block;
            padding: 10px 15px;
            background: #f5f5f5;
            border: 1px dashed #ccc;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
        }

        .file-input-label:hover {
            background: #e8f0ff;
            border-color: #2455ff;
            color: #2455ff;
        }

        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-info {
            font-size: 0.8rem;
            color: #666;
            text-align: center;
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2455ff;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: #1565c0;
        }

        /* Bottom nav */
        .bottom-nav {
            position: fixed;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 4px;
            width: auto;
            max-width: 95%;
            padding: 6px 8px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            z-index: 100;
        }

        .bottom-nav a {
            text-align: center;
            color: #9ca3af;
            text-decoration: none;
            font-weight: 500;
            border-radius: 25px;
            padding: 9px 16px;
            font-size: 0.75rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .bottom-nav a i {
            font-size: 0.85rem;
        }

        .bottom-nav a.active {
            background: #2455ff;
            color: #fff;
        }

        .bottom-nav a:not(.active):hover {
            color: #2455ff;
            background: #f3f4f6;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .container {
                padding: 20px 15px;
            }

            form {
                padding: 20px;
            }

            .profile-photo img {
                width: 100px;
                height: 100px;
            }
        }
    </style>
</head>
<body>

<header>
    <a href="profile.php" class="back-button">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div class="header-title">Edit Profile</div>
</header>

<div class="container">
    <?php if (isset($error)): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <strong>Informasi:</strong> Anda dapat mengubah nama, email, nomor telepon, dan foto profil Anda.
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="profile-photo">
            <?php
            $foto_src = "https://ui-avatars.com/api/?name=" . urlencode($userData['nama'] ?? '') . "&size=120&background=2455ff&color=fff";

            if (!empty($userData['foto'] ?? '')) {
                $foto_path = "../uploads/" . $userData['foto'];
                if (file_exists($foto_path)) {
                    $foto_src = $foto_path . "?t=" . time();
                }
            }
            ?>
            <img src="<?= $foto_src ?>" alt="Foto Profil" id="profile-preview">
            <br>
            <div class="file-input-container">
                <label class="file-input-label">
                    <i class="fas fa-camera"></i> Pilih Foto Baru
                    <input type="file" name="foto" accept="image/jpeg,image/png,image/gif,image/jpg" class="file-input" id="file-input">
                </label>
            </div>
            <div class="file-info">Format: JPG, PNG, GIF (Maks. 2MB)</div>
        </div>

        <div class="form-group">
            <label for="nama">Nama Lengkap</label>
            <input type="text" id="nama" name="nama" value="<?= htmlspecialchars($userData['nama'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($userData['email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="no_hp">No. HP</label>
            <input type="text" id="no_hp" name="no_hp" value="<?= htmlspecialchars($userData['no_hp'] ?? '') ?>" required>
        </div>

        <button type="submit" class="save-btn">
            <i class="fas fa-save"></i> Simpan Perubahan
        </button>
    </form>
</div>



<script>
    // Preview image sebelum upload
    document.getElementById('file-input').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const preview = document.getElementById('profile-preview');

        if (file) {
            // Validasi ukuran file (max 2MB)
            if (file.size > 2 * 1024 * 1024) {
                alert('Ukuran file terlalu besar! Maksimal 2MB.');
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
            }
            reader.readAsDataURL(file);
        }
    });

    // Validasi form sebelum submit
    document.querySelector('form').addEventListener('submit', function(e) {
        const inputs = this.querySelectorAll('input[required]');
        let valid = true;

        inputs.forEach(input => {
            if (!input.value.trim()) {
                valid = false;
                input.style.borderColor = '#f44336';
            } else {
                input.style.borderColor = '#ddd';
            }
        });

        if (!valid) {
            e.preventDefault();
            alert('Harap lengkapi semua field yang wajib diisi!');
            return;
        }
    });
</script>

</body>
</html>
