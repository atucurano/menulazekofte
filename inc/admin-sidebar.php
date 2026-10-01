<?php
/**
 * LAZE QR Admin - Ortak Sol Menü (Sidebar) Bileşeni
 */
if (!isset($unreadFeedback) && isset($db)) {
    $unreadFeedback = (int)$db->query('SELECT COUNT(*) FROM `qr_menu_feedback` WHERE `is_read` = 0')->fetchColumn();
}
$currentView = isset($view) ? $view : '';
?>
<aside class="sidebar">
  <a class="admin-brand" href="<?= QR_BASE ?>admin/index.php">
    <img src="<?= QR_BASE ?>assets/logo.webp" alt="" width="46" height="46">
    <span><b>LAZE</b><small>QR MENÜ YÖNETİMİ</small></span>
  </a>
  <nav aria-label="Yönetim menüsü">
    <a class="<?= $currentView === 'dashboard' ? 'current' : '' ?>" href="<?= QR_BASE ?>admin/index.php">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      <span>Genel bakış</span>
    </a>
    <a class="<?= in_array($currentView, array('products','product','categories'), true) ? 'current' : '' ?>" href="<?= QR_BASE ?>admin/index.php?view=products">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>
      <span>Ürün &amp; Kategori</span>
    </a>
    <a class="<?= $currentView === 'allergens' ? 'current' : '' ?>" href="<?= QR_BASE ?>admin/allergens.php">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span>Alerjenler</span>
    </a>
    <a class="<?= $currentView === 'tables' ? 'current' : '' ?>" href="<?= QR_BASE ?>admin/tables.php">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 12h18"/><path d="M12 3v18"/></svg>
      <span>Masalar &amp; QR</span>
    </a>
    <a class="<?= $currentView === 'staff' ? 'current' : '' ?>" href="<?= QR_BASE ?>admin/staff.php">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Personel hesapları</span>
    </a>
    <a class="<?= $currentView === 'translations' ? 'current' : '' ?>" href="<?= QR_BASE ?>admin/index.php?view=translations">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/></svg>
      <span>İngilizce çeviriler</span>
    </a>
    <a class="<?= $currentView === 'feedback' ? 'current' : '' ?>" href="<?= QR_BASE ?>admin/index.php?view=feedback">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      <span>Geri bildirimler<?= !empty($unreadFeedback) ? ' (' . $unreadFeedback . ')' : '' ?></span>
    </a>
    <a class="<?= $currentView === 'settings' ? 'current' : '' ?>" href="<?= QR_BASE ?>admin/index.php?view=settings">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
      <span>QR menü ayarları</span>
    </a>
  </nav>
  <div class="sidebar-bottom">
    <a href="<?= QR_BASE ?>" target="_blank">Menüyü görüntüle ↗</a>
    <form method="post" action="<?= QR_BASE ?>admin/index.php">
      <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit">Çıkış yap</button>
    </form>
  </div>
</aside>
