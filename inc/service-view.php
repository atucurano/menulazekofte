<?php
// $serviceUser is resolved by the entry point and checked again by the API.
$isWaiter = $serviceUser['role'] === 'waiter';
$roleName = $isWaiter ? 'Garson' : ($serviceUser['role'] === 'cashier' ? 'Kasa' : 'Yönetici');
$qrSettings = qr_settings($db);
$oneSignalAppId = !empty($qrSettings['onesignal_app_id']) ? $qrSettings['onesignal_app_id'] : '';
$oneSignalEnabled = !empty($qrSettings['onesignal_enabled']) && $qrSettings['onesignal_enabled'] === '1' && $oneSignalAppId !== '';
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#12352a">
  <link rel="manifest" href="<?= QR_BASE ?>manifest.json">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="LAZE Servis">
  <title><?= $roleName ?> · Canlı çağrılar | LAZE</title>
  <link rel="stylesheet" href="<?= QR_BASE ?>assets/staff.css?v=3">
  <script src="<?= QR_BASE ?>assets/service.js?v=6" defer></script>
  <?php if ($oneSignalEnabled): ?>
    <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
    <script>
      window.OneSignalDeferred = window.OneSignalDeferred || [];
      OneSignalDeferred.push(async function(OneSignal) {
        await OneSignal.init({
          appId: "<?= qr_e($oneSignalAppId) ?>",
          serviceWorkerParam: { scope: "/" },
          serviceWorkerPath: "OneSignalSDKWorker.js",
          notifyButton: { enable: false }
        });
        OneSignal.User.addTag("role", "<?= qr_e($serviceUser['role']) ?>");
        OneSignal.User.addTag("name", "<?= qr_e($serviceUser['display_name']) ?>");
        
        function checkNotifState() {
          var bell = document.getElementById('notif-enable');
          if (!bell) return;
          if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            bell.classList.add('notif-granted');
            bell.classList.remove('pulse');
            bell.title = 'Kilit ekranı bildirimleri açık ✓';
          } else {
            bell.classList.remove('notif-granted');
            bell.title = 'Kilit ekranı bildirimlerini aç (Telefon kapalıyken çalar)';
          }
        }
        setInterval(checkNotifState, 2000);
        checkNotifState();

        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
          setTimeout(function() {
            var bell = document.getElementById('notif-enable');
            if (bell && Notification.permission !== 'granted') bell.classList.add('pulse');
          }, 1500);
        }
      });

      function requestOneSignalPermission() {
        if (window.OneSignalDeferred) {
          OneSignalDeferred.push(async function(OneSignal) {
            try {
              await OneSignal.Notifications.requestPermission();
              var bell = document.getElementById('notif-enable');
              if (bell) {
                bell.classList.remove('pulse');
                if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
                  bell.classList.add('notif-granted');
                  bell.title = 'Kilit ekranı bildirimleri açık ✓';
                }
              }
            } catch(e) { console.error(e); }
          });
        }
      }
    </script>
  <?php endif; ?>
</head>
<body class="service-board <?= $isWaiter ? 'role-waiter' : 'role-cashier' ?>">
<div class="board-shell" data-api="<?= QR_BASE ?>admin/service-api.php" data-role="<?= qr_e($serviceUser['role']) ?>" data-login-url="<?= QR_BASE ?><?= $serviceUser['role'] === 'admin' ? 'admin/index.php' : 'staff/index.php' ?>" data-csrf="<?= qr_e(qr_csrf()) ?>">
  <header class="board-header">
    <div class="board-brand"><img src="<?= QR_BASE ?>assets/logo.webp" alt="" width="42" height="42"><div><strong>LAZE</strong><span>Masa servisi</span></div></div>
    <div class="board-account">
      <time class="header-clock" id="board-time" aria-label="Saat">--:--</time>
      <button type="button" id="sound-enable" class="header-sound" title="Sesli uyarıyı aç ve test et" aria-label="Sesli uyarıyı aç ve test et"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4Z"></path><path d="M17 8a6 6 0 0 1 0 8M19.5 5.5a9.5 9.5 0 0 1 0 13"></path></svg></button>
      <?php if ($oneSignalEnabled): ?>
        <button type="button" id="notif-enable" class="header-sound header-notif" onclick="requestOneSignalPermission()" title="Kilit ekranı bildirimlerini aç (Telefon kapalıyken çalar)" aria-label="Kilit ekranı bildirimlerini aç">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          </svg>
        </button>
      <?php endif; ?>
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
