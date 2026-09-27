<?php
// $serviceUser is resolved by the entry point and checked again by the API.
$isWaiter = $serviceUser['role'] === 'waiter';
$roleName = $isWaiter ? 'Garson' : ($serviceUser['role'] === 'cashier' ? 'Kasa' : 'Yönetici');
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#12352a">
  <title><?= $roleName ?> · Canlı çağrılar | LAZE</title>
  <link rel="stylesheet" href="<?= QR_BASE ?>assets/staff.css?v=2">
  <script src="<?= QR_BASE ?>assets/service.js?v=6" defer></script>
</head>
<body class="service-board <?= $isWaiter ? 'role-waiter' : 'role-cashier' ?>">
<div class="board-shell" data-api="<?= QR_BASE ?>admin/service-api.php" data-role="<?= qr_e($serviceUser['role']) ?>" data-login-url="<?= QR_BASE ?><?= $serviceUser['role'] === 'admin' ? 'admin/index.php' : 'staff/index.php' ?>" data-csrf="<?= qr_e(qr_csrf()) ?>">
  <header class="board-header">
    <div class="board-brand"><img src="<?= QR_BASE ?>assets/logo.webp" alt="" width="42" height="42"><div><strong>LAZE</strong><span>Masa servisi</span></div></div>
    <div class="board-account">
      <time class="header-clock" id="board-time" aria-label="Saat">--:--</time>
      <button type="button" id="sound-enable" class="header-sound" title="Sesli uyarıyı aç ve test et" aria-label="Sesli uyarıyı aç ve test et"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4Z"></path><path d="M17 8a6 6 0 0 1 0 8M19.5 5.5a9.5 9.5 0 0 1 0 13"></path></svg></button>
      <span class="role-tag"><?= $roleName ?></span><span class="account-name"><?= qr_e($serviceUser['display_name']) ?></span>
      <?php if ($serviceUser['role'] === 'admin'): ?><a href="<?= QR_BASE ?>admin/index.php">Yönetim</a><?php else: ?><form method="post" action="<?= QR_BASE ?>staff/index.php"><input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>"><input type="hidden" name="action" value="logout"><button type="submit">Çıkış</button></form><?php endif; ?>
    </div>
  </header>
  <main class="board-main">
    <h1 class="sr-only">Masa çağrıları</h1>
    <span id="service-status" class="sr-only" role="status">Bağlanıyor…</span>
    <span id="sound-status" class="sr-only" role="status">Sesli uyarı için üst bardaki ses simgesine dokunun.</span>
    <div class="board-summary">
      <div><span>Bekleyen masalar</span><strong id="new-count">0</strong></div>
      <div><span>İlgileniliyor</span><strong id="seen-count">0</strong></div>
      <div><span>Toplam açık</span><strong id="open-count">0</strong></div>
    </div>
    <div class="board-columns">
      <section class="call-section new-section"><div class="call-section-head"><div><span class="section-marker new-marker"></span><h2>Bekleyen masalar</h2></div><p>Garson bekleyen masalar</p></div><div id="new-calls" class="call-list" aria-live="polite"></div></section>
      <section class="call-section seen-section"><div class="call-section-head"><div><span class="section-marker seen-marker"></span><h2>İlgileniliyor</h2></div><p>Personelin yanıtladığı masalar</p></div><div id="seen-calls" class="call-list" aria-live="polite"></div></section>
    </div>
  </main>
</div>
</body>
</html>
