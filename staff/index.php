<?php
require_once dirname(__DIR__) . '/inc/app.php';
header('Cache-Control: no-store');
$db = qr_db();
qr_ensure_service_schema($db);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qr_check_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        qr_clear_staff_persistent_token($db);
        unset($_SESSION['qr_service_user_id'], $_SESSION['qr_service_password_hash']);
        session_regenerate_id(true);
        qr_redirect('staff/index.php');
    }
    $attempts = isset($_SESSION['qr_staff_attempts']) ? array_values(array_filter($_SESSION['qr_staff_attempts'], function ($time) { return $time > time() - 900; })) : array();
    if (count($attempts) >= 5) {
        $error = 'Çok fazla deneme yapıldı. 15 dakika sonra tekrar deneyin.';
    } else {
        $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
        $stmt = $db->prepare('SELECT `id`, `password` FROM `qr_service_users` WHERE `username` = ? AND `is_active` = 1 LIMIT 1');
        $stmt->execute(array($username));
        $candidate = $stmt->fetch();
        if ($candidate && password_verify($password, $candidate['password'])) {
            session_regenerate_id(true);
            $_SESSION['qr_service_user_id'] = (int)$candidate['id'];
            $_SESSION['qr_service_password_hash'] = $candidate['password'];
            qr_set_staff_persistent_token($db, (int)$candidate['id']);
            unset($_SESSION['qr_staff_attempts']);
            qr_redirect('staff/index.php');
        }
        $attempts[] = time();
        $_SESSION['qr_staff_attempts'] = $attempts;
        $error = 'Kullanıcı adı veya şifre hatalı.';
    }
}
$serviceUser = qr_service_identity($db);
if ($serviceUser) { require dirname(__DIR__) . '/inc/service-view.php'; exit; }
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Personel girişi | LAZE</title><link rel="stylesheet" href="<?= QR_BASE ?>assets/staff.css"></head><body class="staff-login"><main class="login-card"><img src="<?= QR_BASE ?>assets/logo.webp" alt="LAZE" width="62" height="62"><span class="login-eyebrow">LAZE · MASA SERVİSİ</span><h1>Personel girişi</h1><p>Kasa veya garson hesabınızla giriş yapın.</p><?php if ($error): ?><div class="login-error" role="alert"><?= qr_e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>"><label>Kullanıcı adı<input name="username" autocomplete="username" required autofocus></label><label>Şifre<input name="password" type="password" autocomplete="current-password" required></label><button type="submit">Giriş yap</button></form></main></body></html>
