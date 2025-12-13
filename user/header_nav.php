<?php
// Shared header + bottom navigation for user pages
if (session_status() === PHP_SESSION_NONE) session_start();

// Ensure we have $userData available
if (empty($userData)) {
    $uname = $_SESSION['user'] ?? $_SESSION['admin'] ?? null;
    if ($uname) {
        if (isset($mysqli)) {
            $res = $mysqli->query("SELECT * FROM users WHERE username = '" . $mysqli->real_escape_string($uname) . "'");
            if ($res) $userData = $res->fetch_assoc();
        } elseif (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$uname]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // ignore
            }
        }
    }
}

$title = $pageTitle ?? (isset($headerTitle) ? $headerTitle : 'Profile page');
$currentFile = basename($_SERVER['PHP_SELF']);

// allow pages to override where the back button should go
$back_url = $back_url ?? 'dashboard.php';

?>
<!-- Shared Header -->
<?php if (empty($hide_top_header)): ?>
<div class="common-top-header">
    <a class="common-back" href="<?= htmlspecialchars($back_url) ?>"><i class="fas fa-arrow-left"></i></a>
    <div class="common-title"><?= htmlspecialchars($title) ?></div>
</div>
<?php endif; ?>

<!-- small CSS scoped to avoid clash -->
<style>
    .common-top-header{background:linear-gradient(135deg,#3550dc,#4c6ef5);padding:18px;border-radius:0 0 14px 14px;position:relative;color:#fff}
    .common-back{position:absolute;left:12px;top:12px;width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.18);color:#fff;text-decoration:none}
    .common-title{text-align:center;font-weight:600;font-size:17px}
    .common-profile-mini{max-width:480px;margin:-40px auto 12px;background:#fff;border-radius:14px;padding:14px 16px;box-shadow:0 10px 24px rgba(0,0,0,0.08);display:flex;gap:12px;align-items:center}
    .common-avatar{width:56px;height:56px;border-radius:50%;overflow:hidden;border:3px solid #fff;background:linear-gradient(135deg,#f59e0b,#f97316);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700}
    .common-user-info{flex:1}
    .common-user-name{font-weight:700}
    .common-user-email{font-size:13px;color:#6b7280}
    .common-bottom-nav{
        position:fixed;
        left:50%;
        transform:translateX(-50%);
        bottom:14px;
        background:#fff;
        padding:8px 10px;
        border-radius:999px;
        box-shadow:0 8px 24px rgba(16,24,40,0.08);
        display:flex;
        gap:8px;
        align-items:center;
        z-index:120;
        max-width:520px;
        width:92%;
        justify-content:space-between;
        border:1px solid rgba(15,23,42,0.06);
    }
    .common-bottom-nav a{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:20px;color:#6b7280;text-decoration:none;font-weight:600;transition:all 0.18s ease}
    .common-bottom-nav a i{font-size:18px}
    .common-bottom-nav a span{font-size:13px}
    .common-bottom-nav a.active{background:#3550dc;color:#fff;box-shadow:0 6px 18px rgba(53,80,220,0.18);transform:translateY(-2px);padding:10px 18px;border-radius:999px}
    @media(min-width:700px){.common-profile-mini{max-width:520px}}
</style>

<?php if (!empty($userData) && empty($hide_profile_mini)): ?>
    <div class="common-profile-mini">
        <div class="common-avatar">
            <?php if (!empty($userData['foto'])): ?>
                <img src="<?= htmlspecialchars($userData['foto']) ?>" style="width:100%;height:100%;object-fit:cover" alt="avatar">
            <?php else: ?>
                <?= strtoupper(substr($userData['name'] ?? $userData['username'] ?? 'U',0,1)) ?>
            <?php endif; ?>
        </div>
        <div class="common-user-info">
            <div class="common-user-name"><?= htmlspecialchars($userData['name'] ?? $userData['username']) ?></div>
            <div class="common-user-email"><?= htmlspecialchars($userData['email'] ?? '') ?></div>
        </div>
    </div>
<?php endif; ?>

<!-- bottom nav -->
<div class="common-bottom-nav">
    <a href="dashboard.php" class="<?= $currentFile === 'dashboard.php' ? 'active' : '' ?>"><i class="fas fa-home"></i><span>Home</span></a>
    <a href="tasks.php" class="<?= $currentFile === 'tasks.php' ? 'active' : '' ?>"><i class="fas fa-list-check"></i><span>Tasks</span></a>
    <a href="users.php" class="<?= $currentFile === 'users.php' ? 'active' : '' ?>"><i class="fas fa-user-friends"></i><span>Users</span></a>
    <a href="profil.php" class="<?= $currentFile === 'profil.php' ? 'active' : '' ?>"><i class="fas fa-user"></i><span>Profil</span></a>
</div>
