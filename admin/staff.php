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
            if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/D', $username) || $displayName === '' || mb_strlen($displayName, 'UTF-8') > 80 || !in_array($role, array('cashier','waiter'), true) || strlen($password) < 8) {
                throw new RuntimeException('Bilgileri kontrol edin. Kullanıcı adı 3–50 karakter, şifre en az 8 karakter olmalı.');
            }
            $stmt = $db->prepare('INSERT INTO `qr_service_users` (`username`,`display_name`,`password`,`role`) VALUES (?,?,?,?)');
            $stmt->execute(array($username, $displayName, password_hash($password, PASSWORD_DEFAULT), $role));
            $success = 'Personel hesabı oluşturuldu.';
        } elseif (in_array($action, array('enable','disable','password','delete'), true)) {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Hesap seçin.');
            if ($action === 'delete') {
                $db->prepare('DELETE FROM `qr_service_users` WHERE `id` = ?')->execute(array($id));
                $success = 'Personel hesabı silindi.';
            } elseif ($action === 'password') {
                $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
                if (strlen($password) < 8) throw new RuntimeException('Yeni şifre en az 8 karakter olmalı.');
                $stmt = $db->prepare('UPDATE `qr_service_users` SET `password` = ? WHERE `id` = ?');
                $stmt->execute(array(password_hash($password, PASSWORD_DEFAULT), $id));
                $success = 'Şifre yenilendi.';
            } else {
                $stmt = $db->prepare('UPDATE `qr_service_users` SET `is_active` = ? WHERE `id` = ?');
                $stmt->execute(array($action === 'enable' ? 1 : 0, $id));
                $success = 'Hesap durumu güncellendi.';
            }
        } else {
            throw new RuntimeException('İşlem geçersiz.');
        }
        qr_flash($success);
        qr_redirect('admin/staff.php');
    } catch (Exception $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'İşlem yapılamadı. Kullanıcı adı zaten kullanılıyor olabilir.';
    }
}
$users = $db->query('SELECT `id`,`username`,`display_name`,`role`,`is_active`,`created_at` FROM `qr_service_users` ORDER BY `role`,`display_name`,`id`')->fetchAll();

$activeStaff = 0;
$waiterCount = 0;
$cashierCount = 0;
foreach ($users as $u) {
    if ($u['is_active']) $activeStaff++;
    if ($u['role'] === 'waiter') $waiterCount++;
    if ($u['role'] === 'cashier') $cashierCount++;
}

qr_ensure_feedback_table($db);
$unreadFeedback = (int)$db->query('SELECT COUNT(*) FROM `qr_menu_feedback` WHERE `is_read` = 0')->fetchColumn();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Personel Hesapları | LAZE QR Menü</title>
  <link rel="icon" href="<?= QR_BASE ?>assets/favicon.png">
  <link rel="stylesheet" href="<?= QR_BASE ?>assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
</head>
<body class="admin-body">

<aside class="sidebar">
  <a class="admin-brand" href="<?= QR_BASE ?>admin/index.php">
    <img src="<?= QR_BASE ?>assets/logo.webp" alt="" width="46" height="46">
    <span><b>LAZE</b><small>QR MENÜ YÖNETİMİ</small></span>
  </a>
  <nav aria-label="Yönetim menüsü">
    <a href="<?= QR_BASE ?>admin/index.php">⌂ <span>Genel bakış</span></a>
    <a href="<?= QR_BASE ?>admin/index.php?view=products">▣ <span>Ürün &amp; Kategori</span></a>
    <a href="<?= QR_BASE ?>admin/allergens.php">🛡️ <span>Alerjenler</span></a>
    <a href="<?= QR_BASE ?>admin/tables.php">⊞ <span>Masalar &amp; QR</span></a>
    <a class="current" href="<?= QR_BASE ?>admin/staff.php">👤 <span>Personel hesapları</span></a>
    <a href="<?= QR_BASE ?>admin/index.php?view=translations">A文 <span>İngilizce çeviriler</span></a>
    <a href="<?= QR_BASE ?>admin/index.php?view=feedback">✎ <span>Geri bildirimler<?= $unreadFeedback ? ' (' . $unreadFeedback . ')' : '' ?></span></a>
    <a href="<?= QR_BASE ?>admin/index.php?view=settings">⚙ <span>QR menü ayarları</span></a>
  </nav>
  <div class="sidebar-bottom">
    <a href="<?= QR_BASE ?>" target="_blank">Menüyü görüntüle ↗</a>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit">Çıkış yap</button>
    </form>
  </div>
</aside>

<div class="admin-main">
  <header class="admin-top">
    <div>
      <h1 class="admin-header-title">Personel Hesapları</h1>
      <p class="admin-header-sub">Kasa ve garson hesapları yalnızca canlı çağrı ekranına erişir</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <a class="secondary link-button" href="<?= QR_BASE ?>admin/tables.php" style="padding:9px 15px;font-size:12px;">Masalar ve QR</a>
      <a class="primary link-button" href="<?= QR_BASE ?>admin/service.php" style="padding:9px 15px;font-size:12px;">Canlı çağrılar</a>
      <span class="admin-person"><?= qr_e(isset($_SESSION['qr_admin_name']) ? $_SESSION['qr_admin_name'] : 'Yönetici') ?></span>
    </div>
  </header>

  <main class="admin-content">
    <?php if ($flash): ?><div class="alert <?= qr_e($flash[1]) ?>"><?= qr_e($flash[0]) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= qr_e($error) ?></div><?php endif; ?>
    <?php if ($success && !$flash): ?><div class="alert"><?= qr_e($success) ?></div><?php endif; ?>

    <!-- İstatistik Kartları -->
    <div class="stats tables-stats" style="margin-bottom:24px;">
      <div>
        <small>TOPLAM PERSONEL</small>
        <strong><?= count($users) ?></strong>
        <span>kayıtlı hesap</span>
      </div>
      <div>
        <small>AKTİF PERSONEL</small>
        <strong style="<?= $activeStaff > 0 ? 'color:#15803d;' : '' ?>"><?= $activeStaff ?></strong>
        <span>görevde</span>
      </div>
      <div>
        <small>GARSON</small>
        <strong><?= $waiterCount ?></strong>
        <span>servis personeli</span>
      </div>
      <div>
        <small>KASA</small>
        <strong><?= $cashierCount ?></strong>
        <span>kasa yetkilisi</span>
      </div>
    </div>

    <!-- İki Sütunlu Yönetim Grid -->
    <div class="two-panels" style="align-items:start;grid-template-columns:360px 1fr;gap:24px;">
      <!-- Sol: Yeni Personel Ekle -->
      <section class="panel form-panel">
        <div class="panel-head">
          <div>
            <h2>Yeni Personel Ekle</h2>
            <p>Her personel için ayrı giriş hesabı oluşturun.</p>
          </div>
        </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
          <input type="hidden" name="action" value="create">

          <label>Görünen Ad
            <input name="display_name" maxlength="80" required placeholder="Örn: Ayşe Yılmaz" autofocus>
          </label>

          <label>Kullanıcı Adı
            <input name="username" maxlength="50" pattern="[a-zA-Z0-9._-]{3,50}" required placeholder="Örn: ayse.garson">
            <small style="color:var(--muted);font-size:11px;">Yalnızca harf, rakam, nokta ve tire</small>
          </label>

          <label>Görev / Rol
            <select name="role" required>
              <option value="waiter">Garson (Masaları ve çağrıları görür)</option>
              <option value="cashier">Kasa (Tüm çağrıları ve kasayı yönetir)</option>
            </select>
          </label>

          <label>Giriş Şifresi
            <input name="password" type="password" minlength="8" placeholder="En az 8 karakter" required autocomplete="new-password">
          </label>

          <button class="primary" type="submit" style="width:100%;margin-top:8px;">Hesap Oluştur →</button>
        </form>
      </section>

      <!-- Sağ: Mevcut Personeller Listesi -->
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Personel Hesapları</h2>
            <p><?= count($users) ?> kayıtlı personel · Canlı çağrı ekranı erişimi</p>
          </div>
        </div>

        <?php if (!$users): ?>
          <div class="feedback-empty-state">
            <p>Henüz tanımlanmış bir personel hesabı yok. Yan taraftaki formdan ilk hesabı ekleyebilirsiniz.</p>
          </div>
        <?php else: ?>
          <div class="staff-admin-list">
            <?php foreach ($users as $user): 
              $isWait = $user['role'] === 'waiter';
            ?>
              <article class="staff-admin-card <?= $user['is_active'] ? '' : 'inactive' ?>">
                <div class="staff-card-left">
                  <div class="staff-avatar-badge <?= $isWait ? 'waiter' : 'cashier' ?>">
                    <?= qr_e(mb_strtoupper(mb_substr($user['display_name'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                  </div>
                  <div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                      <strong style="font-size:15px;color:var(--ink);"><?= qr_e($user['display_name']) ?></strong>
                      <span class="role-badge <?= $isWait ? 'role-waiter' : 'role-cashier' ?>">
                        <?= $isWait ? 'Garson' : 'Kasa' ?>
                      </span>
                      <span class="state-pill <?= $user['is_active'] ? 'active' : 'inactive' ?>">
                        <?= $user['is_active'] ? 'Aktif' : 'Kapalı' ?>
                      </span>
                    </div>
                    <div style="font-size:12px;color:var(--muted);margin-top:3px;font-family:monospace;">
                      @<?= qr_e($user['username']) ?>
                    </div>
                  </div>
                </div>

                <div class="staff-card-right">
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                    <input type="hidden" name="action" value="<?= $user['is_active'] ? 'disable' : 'enable' ?>">
                    <button type="submit" class="adm-btn-action" title="<?= $user['is_active'] ? 'Hesabı dondur' : 'Hesabı etkinleştir' ?>">
                      <?= $user['is_active'] ? 'Durdur' : 'Aktif Et' ?>
                    </button>
                  </form>

                  <details class="staff-pw-details">
                    <summary class="adm-btn-action">Şifre Değiştir</summary>
                    <form method="post" class="staff-pw-dropdown">
                      <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                      <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                      <input type="hidden" name="action" value="password">
                      <input name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Yeni şifre (min 8)" required>
                      <button type="submit" class="primary" style="padding:6px 14px;font-size:11px;">Kaydet</button>
                    </form>
                  </details>

                  <form method="post" onsubmit="return confirm('<?= qr_e($user['display_name']) ?> hesabını silmek istediğinize emin misiniz?');" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="adm-btn-danger" title="Hesabı sil">Sil</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>
</div>
</body>
</html>
