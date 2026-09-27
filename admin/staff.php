<?php
require_once dirname(__DIR__) . '/inc/app.php';
header('Cache-Control: no-store');
qr_admin_required();
$db = qr_db();
qr_ensure_service_schema($db);
$error = '';
$flash = qr_take_flash();
$success = $flash ? $flash[0] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qr_check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'create') {
            $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
            $displayName = trim(isset($_POST['display_name']) ? $_POST['display_name'] : '');
            $role = isset($_POST['role']) ? $_POST['role'] : '';
            $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
            if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/D', $username) || $displayName === '' || mb_strlen($displayName, 'UTF-8') > 80 || !in_array($role, array('cashier','waiter'), true) || strlen($password) < 12) throw new RuntimeException('Bilgileri kontrol edin. Kullanıcı adı 3–50 karakter, şifre en az 12 karakter olmalı.');
            $stmt = $db->prepare('INSERT INTO `qr_service_users` (`username`,`display_name`,`password`,`role`) VALUES (?,?,?,?)');
            $stmt->execute(array($username, $displayName, password_hash($password, PASSWORD_DEFAULT), $role));
            $success = 'Personel hesabı oluşturuldu.';
        } elseif (in_array($action, array('enable','disable','password'), true)) {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Hesap seçin.');
            if ($action === 'password') {
                $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
                if (strlen($password) < 12) throw new RuntimeException('Yeni şifre en az 12 karakter olmalı.');
                $stmt = $db->prepare('UPDATE `qr_service_users` SET `password` = ? WHERE `id` = ?');
                $stmt->execute(array(password_hash($password, PASSWORD_DEFAULT), $id));
                $success = 'Şifre yenilendi.';
            } else {
                $stmt = $db->prepare('UPDATE `qr_service_users` SET `is_active` = ? WHERE `id` = ?');
                $stmt->execute(array($action === 'enable' ? 1 : 0, $id));
                $success = 'Hesap durumu güncellendi.';
            }
        } else throw new RuntimeException('İşlem geçersiz.');
        qr_flash($success);
        qr_redirect('admin/staff.php');
    } catch (Exception $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : 'İşlem yapılamadı. Kullanıcı adı zaten kullanılıyor olabilir.'; }
}
$users = $db->query('SELECT `id`,`username`,`display_name`,`role`,`is_active`,`created_at` FROM `qr_service_users` ORDER BY `role`,`display_name`,`id`')->fetchAll();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Personel hesapları | LAZE</title><link rel="stylesheet" href="<?= QR_BASE ?>assets/admin.css"><link rel="stylesheet" href="<?= QR_BASE ?>assets/service.css"></head><body class="admin-body"><main class="service-wrap admin-service"><nav class="crumbs"><a href="<?= QR_BASE ?>admin/index.php">Yönetim</a><span>/</span><span>Personel hesapları</span></nav><header class="page-heading"><div><span class="eyebrow">ERİŞİM YÖNETİMİ</span><h1>Personel hesapları</h1><p>Kasa ve garson hesapları yalnızca canlı çağrı ekranına erişir.</p></div><a class="secondary link-button" href="<?= QR_BASE ?>admin/service.php">Canlı ekranı aç</a></header><?php if ($error): ?><div class="alert error"><?= qr_e($error) ?></div><?php endif; ?><?php if ($success): ?><div class="alert"><?= qr_e($success) ?></div><?php endif; ?><div class="management-grid"><section class="management-panel"><h2>Yeni hesap</h2><p>Her personel için ayrı hesap oluşturun.</p><form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>"><input type="hidden" name="action" value="create"><label>Görünen ad<input name="display_name" maxlength="80" required placeholder="Ayşe Yılmaz"></label><label>Kullanıcı adı<input name="username" maxlength="50" pattern="[a-zA-Z0-9._-]{3,50}" required placeholder="ayse.garson"></label><label>Görev<select name="role" required><option value="cashier">Kasa</option><option value="waiter">Garson</option></select></label><label>Şifre<input name="password" type="password" minlength="12" required autocomplete="new-password"></label><button class="primary" type="submit">Hesap oluştur</button></form></section><section class="management-panel"><div class="section-title"><h2>Hesaplar</h2><span><?= count($users) ?> kayıt</span></div><?php if (!$users): ?><p class="empty-state">Henüz personel hesabı yok.</p><?php endif; ?><div class="staff-user-list"><?php foreach ($users as $user): ?><article class="staff-user"><div class="staff-avatar" aria-hidden="true"><?= qr_e(mb_strtoupper(mb_substr($user['display_name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div><div class="staff-user-info"><strong><?= qr_e($user['display_name']) ?></strong><small>@<?= qr_e($user['username']) ?> · <?= $user['role'] === 'cashier' ? 'Kasa' : 'Garson' ?></small><span class="state-pill <?= $user['is_active'] ? 'active' : 'inactive' ?>"><?= $user['is_active'] ? 'Aktif' : 'Kapalı' ?></span></div><div class="staff-user-actions"><form method="post"><input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><input type="hidden" name="action" value="<?= $user['is_active'] ? 'disable' : 'enable' ?>"><button type="submit" class="quiet-button"><?= $user['is_active'] ? 'Kapat' : 'Aç' ?></button></form><details><summary>Şifre yenile</summary><form method="post"><input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><input type="hidden" name="action" value="password"><input name="password" type="password" minlength="12" autocomplete="new-password" placeholder="Yeni şifre" required><button type="submit">Kaydet</button></form></details></div></article><?php endforeach; ?></div></section></div></main></body></html>
