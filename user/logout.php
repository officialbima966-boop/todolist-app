<?php
session_start();

// Pastikan hanya user yang bisa akses
if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Ambil username untuk ditampilkan
$username = $_SESSION['user'];

// Jika klik tombol logout
if (isset($_GET['logout']) && $_GET['logout'] === 'yes') {
    // Hapus session
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();

    // Langsung redirect ke login
    header("Location: ../auth/login.php");
    exit;
}

// Cek apakah ada reason untuk logout
$reason = isset($_GET['reason']) ? $_GET['reason'] : '';
$message = '';

if ($reason === 'password_changed') {
    $message = 'Password berhasil diubah. Silakan login kembali dengan password baru Anda.';
} elseif ($reason === 'session_expired') {
    $message = 'Sesi Anda telah berakhir. Silakan login kembali.';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Logout | BM Garage</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .logout-box {
            background: #fff;
            padding: 40px 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .logout-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #0022a8, #0044ff);
        }

        .icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ff6b6b, #ff5252);
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 25px;
            font-size: 32px;
            color: #fff;
            box-shadow: 0 8px 20px rgba(255, 107, 107, 0.3);
        }

        .logout-box h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .logout-box p {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .user-info {
            background: #f8faff;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 25px;
            border: 1px solid #e0e7ff;
        }

        .user-info .username {
            font-weight: 600;
            color: #2455ff;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .user-info .status {
            color: #666;
            font-size: 0.9rem;
        }

        .btn-container {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .btn {
            padding: 14px 25px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            flex: 1;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }

        .btn-cancel:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
        }

        .btn-logout {
            background: linear-gradient(135deg, #ff6b6b, #ff5252);
            color: #fff;
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
        }

        .btn-logout:hover {
            background: linear-gradient(135deg, #ff5252, #ff3838);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 107, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .reason-message {
            background: #e3f2fd;
            border-left: 4px solid #2455ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: #1565c0;
        }

        .reason-message i {
            margin-right: 8px;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .logout-box {
                padding: 30px 20px;
                margin: 20px;
            }

            .icon {
                width: 70px;
                height: 70px;
                font-size: 28px;
            }

            .logout-box h2 {
                font-size: 1.3rem;
            }

            .btn-container {
                flex-direction: column;
            }

            .btn {
                margin-bottom: 8px;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logout-box {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>

<div class="logout-box">
    <div class="icon">
        <i class="fas fa-sign-out-alt"></i>
    </div>

    <?php if ($message): ?>
        <div class="reason-message">
            <i class="fas fa-info-circle"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <h2>Konfirmasi Logout</h2>
    <p>Anda akan keluar dari akun BM Garage. Pastikan semua pekerjaan Anda sudah disimpan.</p>

    <div class="user-info">
        <div class="username">
            <i class="fas fa-user"></i> <?= htmlspecialchars($username) ?>
        </div>
        <div class="status">Status: Sedang login</div>
    </div>

    <div class="btn-container">
        <a href="dashboard.php" class="btn btn-cancel">
            <i class="fas fa-arrow-left"></i>
            Batal
        </a>
        <a href="?logout=yes" class="btn btn-logout">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>
    </div>
</div>

<script>
    // Auto redirect jika ada parameter auto
    <?php if (isset($_GET['auto']) && $_GET['auto'] === 'true'): ?>
        setTimeout(() => {
            window.location.href = '?logout=yes';
        }, 3000);
    <?php endif; ?>

    // Tambahkan efek smooth pada hover
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px) scale(1.02)';
        });

        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Log untuk debugging
    console.log('Logout page loaded for user: <?= htmlspecialchars($username) ?>');
</script>

</body>
</html>
