<?php
session_start();
require_once "../inc/koneksi.php";

// Cek login
if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

$username = $_SESSION['user'];

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validasi input
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'Semua field harus diisi!';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Password baru dan konfirmasi password tidak cocok!';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password baru harus minimal 6 karakter!';
    } else {
        try {
            // Ambil data user termasuk password
            $stmt = $mysqli->prepare("SELECT id, name, email, password FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user) {
                $error = 'User tidak ditemukan!';
            } else {
                // Verifikasi password saat ini
                if (password_verify($current_password, $user['password'])) {
                    // Update password baru
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $stmt->bind_param("ss", $hashed_password, $username);

                    if ($stmt->execute()) {
                        $success = 'Password berhasil diubah!';

                        // Reset form fields
                        $_POST = [];

                    } else {
                        $error = 'Terjadi kesalahan saat mengubah password. Silakan coba lagi.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Password saat ini salah!';
                }
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
        }
    }
}

// Ambil data user untuk ditampilkan
try {
    $stmt = $mysqli->prepare("SELECT name, email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $user = [
            'name' => $_SESSION['user'],
            'email' => 'Email belum terdaftar'
        ];
    }
} catch (Exception $e) {
    $user = [
        'name' => $_SESSION['user'],
        'email' => 'Error loading data'
    ];
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ganti Password | BM Garage</title>
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
            display: flex;
            flex-direction: column;
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

        .content {
            padding: 30px 20px 100px;
            flex: 1;
        }

        .user-info {
            background: #fff;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            text-align: center;
        }

        .user-info .username {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2455ff;
            margin-bottom: 5px;
        }

        .user-info .email {
            font-size: 0.9rem;
            color: #666;
        }

        .password-form {
            background: #fff;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-group input {
            width: 100%;
            padding: 14px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 0.95rem;
            background: #f8faff;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2455ff;
            box-shadow: 0 0 0 3px rgba(36, 85, 255, 0.1);
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle input {
            padding-right: 50px;
        }

        .toggle-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .btn {
            padding: 14px 25px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: #2455ff;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .password-strength {
            margin-top: 8px;
            font-size: 0.8rem;
        }

        .strength-bar {
            height: 4px;
            border-radius: 2px;
            margin-top: 5px;
            background: #eee;
            width: 100%;
        }

        .strength-fill {
            height: 100%;
            border-radius: 2px;
            transition: all 0.3s;
            width: 0%;
        }

        .logout-option {
            background: #fff8e1;
            border: 1px solid #ffd54f;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            text-align: center;
        }

        .logout-option p {
            margin-bottom: 10px;
            color: #e65100;
            font-size: 0.9rem;
        }

        .btn-warning {
            background: #ff9800;
            color: white;
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
            .content {
                padding: 20px 15px 70px;
            }

            .password-form {
                padding: 20px;
            }

            .user-info {
                padding: 15px;
            }
        }
    </style>
</head>
<body>

<header>
    <button class="back-button" onclick="goToProfile()">
        <i class="fas fa-arrow-left"></i>
    </button>
    <div class="header-title">Ganti Password</div>
</header>

<div class="content">
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- Informasi User -->
    <div class="user-info">
        <div class="username"><?= htmlspecialchars($username) ?></div>
        <div class="email"><?= htmlspecialchars($user['email']) ?></div>
    </div>

    <form method="POST" class="password-form">
        <div class="form-group password-toggle">
            <label for="current_password">Password Saat Ini</label>
            <input type="password" id="current_password" name="current_password"
                   placeholder="Masukkan password saat ini" required
                   value="<?= htmlspecialchars($_POST['current_password'] ?? '') ?>">
            <span class="toggle-icon" onclick="togglePassword('current_password', this)">
                <i class="fas fa-eye"></i>
            </span>
        </div>

        <div class="form-group password-toggle">
            <label for="new_password">Password Baru</label>
            <input type="password" id="new_password" name="new_password"
                   placeholder="Masukkan password baru (min. 6 karakter)" required minlength="6"
                   value="<?= htmlspecialchars($_POST['new_password'] ?? '') ?>">
            <span class="toggle-icon" onclick="togglePassword('new_password', this)">
                <i class="fas fa-eye"></i>
            </span>
            <div class="password-strength">
                <div id="passwordStrengthText"></div>
                <div class="strength-bar">
                    <div class="strength-fill" id="passwordStrengthBar"></div>
                </div>
            </div>
        </div>

        <div class="form-group password-toggle">
            <label for="confirm_password">Konfirmasi Password Baru</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   placeholder="Konfirmasi password baru" required
                   value="<?= htmlspecialchars($_POST['confirm_password'] ?? '') ?>">
            <span class="toggle-icon" onclick="togglePassword('confirm_password', this)">
                <i class="fas fa-eye"></i>
            </span>
            <div id="confirmMessage" style="font-size:0.8rem;margin-top:5px;"></div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-key"></i> Ganti Password
        </button>
    </form>

    <!-- Opsi logout setelah ganti password -->
    <?php if ($success): ?>
    <div class="logout-option">
        <p>Untuk keamanan, disarankan untuk login ulang dengan password baru Anda.</p>
        <button type="button" class="btn btn-warning" onclick="logout()">
            <i class="fas fa-sign-out-alt"></i> Login Ulang
        </button>
    </div>
    <?php endif; ?>
</div>



<script>
    function goToProfile() {
        window.location.href = 'profile.php';
    }

    function logout() {
        window.location.href = 'logout.php?reason=password_changed';
    }

    function togglePassword(inputId, icon) {
        const input = document.getElementById(inputId);
        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
        input.setAttribute('type', type);
        icon.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
    }

    // Password strength indicator
    document.getElementById('new_password').addEventListener('input', function() {
        const password = this.value;
        const strengthText = document.getElementById('passwordStrengthText');
        const strengthBar = document.getElementById('passwordStrengthBar');

        let strength = 0;
        let color = '#ff4757';
        let text = 'Lemah';

        // Length check
        if (password.length >= 6) strength += 25;
        // Mixed case check
        if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength += 25;
        // Number check
        if (password.match(/\d/)) strength += 25;
        // Special character check
        if (password.match(/[^a-zA-Z\d]/)) strength += 25;

        if (strength >= 75) {
            color = '#2ed573';
            text = 'Kuat';
        } else if (strength >= 50) {
            color = '#ffa502';
            text = 'Sedang';
        } else if (strength >= 25) {
            color = '#ff7f50';
            text = 'Cukup';
        }

        strengthText.textContent = `Kekuatan password: ${text}`;
        strengthText.style.color = color;
        strengthBar.style.width = strength + '%';
        strengthBar.style.background = color;
    });

    // Confirm password validation
    document.getElementById('confirm_password').addEventListener('input', function() {
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = this.value;
        const message = document.getElementById('confirmMessage');

        if (confirmPassword === '') {
            message.textContent = '';
            message.style.color = '#666';
        } else if (newPassword === confirmPassword) {
            message.textContent = '✓ Password cocok';
            message.style.color = '#00c853';
        } else {
            message.textContent = '✗ Password tidak cocok';
            message.style.color = '#ff4757';
        }
    });

    // Clear success message after 5 seconds
    <?php if ($success): ?>
        setTimeout(() => {
            const alert = document.querySelector('.alert-success');
            if (alert) alert.style.display = 'none';
        }, 5000);
    <?php endif; ?>

    // Clear error message after 5 seconds
    <?php if ($error): ?>
        setTimeout(() => {
            const alert = document.querySelector('.alert-error');
            if (alert) alert.style.display = 'none';
        }, 5000);
    <?php endif; ?>
</script>

</body>
</html>
