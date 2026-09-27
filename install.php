<?php
require_once __DIR__ . '/inc/app.php';
if (is_file(QR_DB_FILE)) { http_response_code(404); exit('Kurulum tamamlandı.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qr_check_csrf();
    $config = array(
        'host' => trim(isset($_POST['host']) ? $_POST['host'] : ''),
        'port' => (int)(isset($_POST['port']) ? $_POST['port'] : 3306),
        'name' => trim(isset($_POST['name']) ? $_POST['name'] : ''),
        'user' => trim(isset($_POST['user']) ? $_POST['user'] : ''),
        'password' => isset($_POST['password']) ? (string)$_POST['password'] : ''
    );
    $adminName = trim(isset($_POST['admin_name']) ? $_POST['admin_name'] : '');
    $adminPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
    try {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $config['host']) || $config['port'] < 1 || $config['port'] > 65535 ||
            !preg_match('/^[a-zA-Z0-9_-]+$/D', $config['name']) || $config['user'] === '') throw new RuntimeException('Veritabanı bilgilerini kontrol edin.');
        $db = qr_connect($config);
        $stmt = $db->prepare('SELECT `id`, `password` FROM `admins` WHERE `username` = ? LIMIT 1');
        $stmt->execute(array($adminName));
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($adminPassword, $admin['password'])) throw new RuntimeException('Mevcut site yönetici hesabı doğrulanamadı.');
        $db->query('SELECT 1 FROM `products` LIMIT 1');
        $db->query('SELECT 1 FROM `categories` LIMIT 1');
        qr_schema($db);
        $contents = "<?php\nreturn " . var_export($config, true) . ";\n";
        $temporary = QR_DB_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, QR_DB_FILE)) throw new RuntimeException('Bağlantı dosyası yazılamadı; inc klasörünün yazılabilir olduğunu kontrol edin.');
        @chmod(QR_DB_FILE, 0600);
        session_regenerate_id(true);
        $_SESSION['qr_admin_id'] = (int)$admin['id'];
        qr_redirect('admin/index.php');
    } catch (Exception $e) {
        error_log('QR menu install: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Bağlantı kurulamadı. Bilgileri kontrol edin.';
    }
}
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>QR Menü Kurulumu | LAZE</title><link rel="stylesheet" href="<?= QR_BASE ?>assets/admin.css"></head><body class="auth-body"><main class="auth-card"><img src="<?= QR_BASE ?>assets/logo.webp" width="74" height="74" alt="LAZE"><span class="eyebrow">LAZE QR MENÜ</span><h1>Kuruluma hoş geldiniz</h1><p>Mevcut lazekofte.com veritabanı bilgilerini ve site yönetici hesabınızı girin. Ürünler ortak kullanılacaktır.</p><?php if ($error): ?><div class="alert error"><?= qr_e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>"><label>Veritabanı sunucusu<input name="host" value="<?= qr_e(isset($_POST['host']) ? $_POST['host'] : 'localhost') ?>" required></label><div class="form-row"><label>Port<input name="port" type="number" value="<?= qr_e(isset($_POST['port']) ? $_POST['port'] : '3306') ?>" required></label><label>Veritabanı adı<input name="name" value="<?= qr_e(isset($_POST['name']) ? $_POST['name'] : '') ?>" required></label></div><label>Veritabanı kullanıcı adı<input name="user" value="<?= qr_e(isset($_POST['user']) ? $_POST['user'] : '') ?>" required></label><label>Veritabanı şifresi<input name="password" type="password" autocomplete="new-password"></label><hr><label>Mevcut site yönetici kullanıcı adı<input name="admin_name" required></label><label>Mevcut site yönetici şifresi<input name="admin_password" type="password" required></label><button class="primary" type="submit">Bağlan ve QR menüyü aç →</button></form></main></body></html>
