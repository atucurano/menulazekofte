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
            $label = trim(isset($_POST['label']) ? $_POST['label'] : '');
            $section = trim(isset($_POST['section']) ? $_POST['section'] : '');
            if ($section === '') $section = 'Salon';
            if ($label === '' || mb_strlen($label, 'UTF-8') > 60) throw new RuntimeException('Masa adı 1–60 karakter olmalı.');
            if (mb_strlen($section, 'UTF-8') > 60) throw new RuntimeException('Bölüm adı en fazla 60 karakter olmalı.');
            $stmt = $db->prepare('INSERT INTO `qr_tables` (`label`, `section`, `token`) VALUES (?, ?, ?)');
            $stmt->execute(array($label, $section, bin2hex(random_bytes(16))));
            $success = 'Masa başarıyla oluşturuldu.';
        } elseif ($action === 'rename' || $action === 'update') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Masa seçin.');
            $label = trim(isset($_POST['label']) ? $_POST['label'] : '');
            $section = trim(isset($_POST['section']) ? $_POST['section'] : '');
            if ($section === '') $section = 'Salon';
            if ($label === '' || mb_strlen($label, 'UTF-8') > 60) throw new RuntimeException('Masa adı 1–60 karakter olmalı.');
            if (mb_strlen($section, 'UTF-8') > 60) throw new RuntimeException('Bölüm adı en fazla 60 karakter olmalı.');
            $stmt = $db->prepare('UPDATE `qr_tables` SET `label` = ?, `section` = ? WHERE `id` = ?');
            $stmt->execute(array($label, $section, $id));
            $success = 'Masa bilgileri güncellendi.';
        } elseif (in_array($action, array('disable', 'enable', 'rotate'), true)) {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Masa seçin.');
            if ($action === 'rotate') {
                $stmt = $db->prepare('UPDATE `qr_tables` SET `token` = ? WHERE `id` = ?');
                $stmt->execute(array(bin2hex(random_bytes(16)), $id));
                $success = 'QR bağlantısı yenilendi. Eski QR kartı artık çalışmaz.';
            } else {
                $stmt = $db->prepare('UPDATE `qr_tables` SET `is_active` = ? WHERE `id` = ?');
                $stmt->execute(array($action === 'enable' ? 1 : 0, $id));
                $success = $action === 'enable' ? 'Masa etkinleştirildi.' : 'Masa kapatıldı.';
            }
        } elseif ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Masa seçin.');
            $db->prepare('DELETE FROM `qr_waiter_calls` WHERE `table_id` = ?')->execute(array($id));
            $db->prepare('DELETE FROM `qr_tables` WHERE `id` = ?')->execute(array($id));
            $success = 'Masa başarıyla silindi.';
        } else {
            throw new RuntimeException('Geçersiz işlem.');
        }
        qr_flash($success);
        qr_redirect('admin/tables.php');
    } catch (Exception $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'İşlem tamamlanamadı.';
    }
}

$tables = $db->query('SELECT `id`, `label`, `section`, `token`, `is_active`, `created_at` FROM `qr_tables` ORDER BY `section`, `id`')->fetchAll();
$activeCount = 0;
$sectionCounts = array();
foreach ($tables as $entry) {
    if ($entry['is_active']) $activeCount++;
    $sec = !empty($entry['section']) ? $entry['section'] : 'Salon';
    if (!isset($sectionCounts[$sec])) $sectionCounts[$sec] = 0;
    $sectionCounts[$sec]++;
}
ksort($sectionCounts);

qr_ensure_feedback_table($db);
$unreadFeedback = (int)$db->query('SELECT COUNT(*) FROM `qr_menu_feedback` WHERE `is_read` = 0')->fetchColumn();
$origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Masalar ve QR Kodları | LAZE</title>
  <link rel="icon" href="<?= QR_BASE ?>assets/favicon.png">
  <link rel="stylesheet" href="<?= QR_BASE ?>assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
  <script src="<?= QR_BASE ?>assets/qrcode.min.js"></script>
</head>
<body class="admin-body">

<?php $view = 'tables'; require dirname(__DIR__) . '/inc/admin-sidebar.php'; ?>

<div class="admin-main">
  <header class="admin-top">
    <div>
      <h1 class="admin-header-title">Masalar ve QR Kodları</h1>
      <p class="admin-header-sub">Masa QR kodlarını ve salon / bahçe bölümlerini yönetin</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <a class="secondary link-button" href="<?= QR_BASE ?>admin/staff.php" style="padding:9px 15px;font-size:12px;">Personel hesapları</a>
      <a class="secondary link-button" href="<?= QR_BASE ?>admin/service.php" style="padding:9px 15px;font-size:12px;">Canlı çağrılar</a>
      <button type="button" class="primary link-button" id="btnOpenNewTableModal" style="padding:9px 16px;font-size:12.5px;display:inline-flex;align-items:center;gap:7px;cursor:pointer;background:#ba8664;border-color:#ba8664;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        <span>Yeni Masa Ekle</span>
      </button>
      <span class="admin-person"><?= qr_e(isset($_SESSION['qr_admin_name']) ? $_SESSION['qr_admin_name'] : 'Yönetici') ?></span>
    </div>
  </header>

  <main class="admin-content tables-page-content">
    <?php if ($flash): ?><div class="alert <?= qr_e($flash[1]) ?>"><?= qr_e($flash[0]) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= qr_e($error) ?></div><?php endif; ?>

    <!-- İstatistik Kartları -->
    <div class="stats tables-stats">
      <div>
        <small>TOPLAM MASA</small>
        <strong><?= count($tables) ?></strong>
        <span>kayıtlı masa</span>
      </div>
      <div>
        <small>AKTİF MASA</small>
        <strong style="<?= $activeCount > 0 ? 'color:#15803d;' : '' ?>"><?= $activeCount ?></strong>
        <span>kullanımda</span>
      </div>
      <div>
        <small>KAPALI MASA</small>
        <strong><?= count($tables) - $activeCount ?></strong>
        <span>devre dışı</span>
      </div>
      <div>
        <small>BÖLÜM SAYISI</small>
        <strong><?= count($sectionCounts) ?></strong>
        <span>salon / bahçe</span>
      </div>
    </div>

    <!-- Yeni Masa Ekle Modal -->
    <div class="table-modal-overlay" id="newTableModal" aria-hidden="true" style="display:none;">
      <div class="table-modal-backdrop" id="modalBackdrop"></div>
      <div class="table-modal-dialog" role="dialog" aria-labelledby="modalTitle" aria-modal="true">
        <div class="table-modal-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <div class="table-modal-icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 12h18"/><path d="M12 3v18"/></svg>
            </div>
            <div>
              <h2 id="modalTitle">Yeni Masa Ekle</h2>
              <p>Masa adını ve bulunduğu bölümü belirleyerek anında QR bağlantısı oluşturun.</p>
            </div>
          </div>
          <button type="button" class="table-modal-close" id="btnCloseModal" aria-label="Kapat">✕</button>
        </div>

        <form method="post" class="table-modal-form">
          <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
          <input type="hidden" name="action" value="create">

          <div class="table-field-group">
            <label for="new-table-label">Masa Adı <span class="req-star">*</span></label>
            <input id="new-table-label" name="label" maxlength="60" placeholder="Örn: Masa 1, Masa 12, Bahçe 4" required autocomplete="off">
          </div>

          <div class="table-field-group">
            <label for="new-table-section">Bölüm <span class="req-star">*</span></label>
            <input id="new-table-section" name="section" list="section-suggestions" maxlength="60" placeholder="Örn: Salon, Bahçe, Teras" value="Salon" required autocomplete="off">
            <datalist id="section-suggestions">
              <option value="Salon">
              <option value="Bahçe">
              <option value="Teras">
              <option value="Giriş">
              <option value="Üst Kat">
              <option value="VIP">
              <?php foreach (array_keys($sectionCounts) as $s): ?>
                <?php if (!in_array($s, array('Salon','Bahçe','Teras','Giriş','Üst Kat','VIP'), true)): ?>
                  <option value="<?= qr_e($s) ?>">
                <?php endif; ?>
              <?php endforeach; ?>
            </datalist>
          </div>

          <div class="quick-section-row">
            <span class="quick-sec-label">Hızlı Bölüm Seç:</span>
            <div class="quick-section-chips">
              <button type="button" class="quick-sec-tag" data-quick-section="Salon">Salon</button>
              <button type="button" class="quick-sec-tag" data-quick-section="Bahçe">Bahçe</button>
              <button type="button" class="quick-sec-tag" data-quick-section="Teras">Teras</button>
              <button type="button" class="quick-sec-tag" data-quick-section="Üst Kat">Üst Kat</button>
              <button type="button" class="quick-sec-tag" data-quick-section="Giriş">Giriş</button>
            </div>
          </div>

          <div class="table-modal-footer">
            <button type="button" class="secondary" id="btnCancelModal" style="padding:10px 18px;">Vazgeç</button>
            <button class="primary table-submit-btn" type="submit" style="padding:10px 22px;">+ Masayı Oluştur</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Bölüm Filtre Çubuğu -->
    <?php if ($tables): ?>
      <div class="table-filter-bar">
        <button type="button" class="table-filter-chip active" data-section-filter="all">
          <span>Tümü</span>
          <span class="chip-count"><?= count($tables) ?></span>
        </button>
        <?php foreach ($sectionCounts as $secName => $cnt): ?>
          <button type="button" class="table-filter-chip" data-section-filter="<?= qr_e($secName) ?>">
            <span><?= qr_e($secName) ?></span>
            <span class="chip-count"><?= $cnt ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Masa Listesi -->
    <?php if (!$tables): ?>
      <div class="feedback-empty-state">
        <p>Henüz tanımlanmış bir masa bulunmuyor. <button type="button" class="text-button" onclick="openNewTableModal()" style="color:var(--copper);text-decoration:underline;cursor:pointer;">+ Yeni Masa Ekle</button> butonuna tıklayarak ilk masanızı oluşturabilirsiniz.</p>
      </div>
    <?php else: ?>
      <div class="table-cards-grid" id="tablesGrid">
        <?php foreach ($tables as $table): 
          $url = $origin . QR_BASE . '?table=' . $table['token'];
          $sectionName = !empty($table['section']) ? $table['section'] : 'Salon';
        ?>
          <article class="adm-table-card <?= $table['is_active'] ? '' : 'table-disabled' ?>" data-table-section="<?= qr_e($sectionName) ?>">
            <div class="adm-table-head">
              <div class="table-meta-left">
                <div class="table-badges-row">
                  <span class="table-section-tag"><?= qr_e($sectionName) ?></span>
                </div>
                <h3><?= qr_e($table['label']) ?></h3>
              </div>
              <span class="state-pill <?= $table['is_active'] ? 'active' : 'inactive' ?>">
                <?= $table['is_active'] ? 'Aktif' : 'Kapalı' ?>
              </span>
            </div>

            <div class="adm-table-body">
              <div class="adm-table-qr" data-qr="<?= qr_e($url) ?>" aria-label="<?= qr_e($table['label']) ?> QR kodu"></div>
              <p class="adm-table-subnote">Müşteri menüsü ve garson çağırma QR kodu</p>
            </div>

            <div class="adm-table-link-box">
              <span class="adm-table-link-label">Masa Bağlantısı</span>
              <a class="adm-table-link-val" href="<?= qr_e($url) ?>" target="_blank" rel="noopener"><?= qr_e($url) ?></a>
            </div>

            <div class="adm-table-actions">
              <a class="primary link-button" href="<?= QR_BASE ?>admin/table-print.php?id=<?= (int)$table['id'] ?>" target="_blank" style="text-align:center;">
                Kartı Yazdır ↗
              </a>
              <button type="button" class="secondary" data-copy="<?= qr_e($url) ?>">
                Bağlantıyı Kopyala
              </button>
            </div>

            <details class="adm-table-details">
              <summary>Masa ve Bölüm Ayarları</summary>
              <form method="post" class="adm-table-edit-form">
                <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                <input type="hidden" name="id" value="<?= (int)$table['id'] ?>">
                <input type="hidden" name="action" value="rename">

                <div class="table-field-group">
                  <label>Masa Adı</label>
                  <input name="label" maxlength="60" value="<?= qr_e($table['label']) ?>" required>
                </div>

                <div class="table-field-group">
                  <label>Bölüm (Salon, Bahçe vb.)</label>
                  <input name="section" list="section-suggestions" maxlength="60" value="<?= qr_e($sectionName) ?>" required>
                </div>

                <button class="adm-btn-action" type="submit" style="justify-self:start;margin-top:2px;">
                  Değişiklikleri Kaydet
                </button>
              </form>

              <div class="adm-table-danger-bar">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$table['id'] ?>">
                  <input type="hidden" name="action" value="<?= $table['is_active'] ? 'disable' : 'enable' ?>">
                  <button type="submit" class="adm-btn-action">
                    <?= $table['is_active'] ? 'Masayı Kapat' : 'Masayı Aç' ?>
                  </button>
                </form>

                <form method="post" data-confirm="Eski QR kodu artık menüyü açmayacaktır. Yeni bir QR kod oluşturulsun mu?">
                  <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$table['id'] ?>">
                  <input type="hidden" name="action" value="rotate">
                  <button type="submit" class="adm-btn-action">
                    QR Kodu Yenile
                  </button>
                </form>

                <form method="post" data-confirm="Bu masayı ve geçmiş garson çağrılarını tamamen silmek istediğinize emin misiniz?">
                  <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$table['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button type="submit" class="adm-btn-danger">
                    Masayı Sil
                  </button>
                </form>
              </div>
            </details>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<script>
// QR Kodlarını Oluştur (Kompakt 130px)
function renderTableQRs() {
  document.querySelectorAll('[data-qr]').forEach(function(el) {
    if (typeof QRCode !== 'undefined' && !el.dataset.rendered) {
      el.dataset.rendered = '1';
      new QRCode(el, {
        text: el.dataset.qr,
        width: 130,
        height: 130,
        colorDark: '#142c24',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }
  });
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', renderTableQRs);
} else {
  renderTableQRs();
}

// Modal Kontrolleri
var tableModal = document.getElementById('newTableModal');
var btnOpenTableModal = document.getElementById('btnOpenNewTableModal');
var btnCloseTableModal = document.getElementById('btnCloseModal');
var btnCancelTableModal = document.getElementById('btnCancelModal');
var modalBackdrop = document.getElementById('modalBackdrop');
var newTableLabelInput = document.getElementById('new-table-label');

function openNewTableModal() {
  if (!tableModal) return;
  tableModal.style.display = 'flex';
  tableModal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  if (newTableLabelInput) {
    setTimeout(function() { newTableLabelInput.focus(); }, 60);
  }
}

function closeNewTableModal() {
  if (!tableModal) return;
  tableModal.style.display = 'none';
  tableModal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

if (btnOpenTableModal) btnOpenTableModal.addEventListener('click', openNewTableModal);
if (btnCloseTableModal) btnCloseTableModal.addEventListener('click', closeNewTableModal);
if (btnCancelTableModal) btnCancelTableModal.addEventListener('click', closeNewTableModal);
if (modalBackdrop) modalBackdrop.addEventListener('click', closeNewTableModal);

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && tableModal && tableModal.style.display === 'flex') {
    closeNewTableModal();
  }
});

// Onay Kutuları
document.querySelectorAll('form[data-confirm]').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    if (!confirm(form.dataset.confirm)) {
      e.preventDefault();
    }
  });
});

// Bağlantı Kopyalama
document.querySelectorAll('[data-copy]').forEach(function(btn) {
  btn.addEventListener('click', async function() {
    try {
      await navigator.clipboard.writeText(btn.dataset.copy);
      var original = btn.textContent;
      btn.textContent = 'Kopyalandı ✓';
      setTimeout(function() { btn.textContent = original; }, 2000);
    } catch (_) {
      btn.textContent = 'Kopyalanamadı';
    }
  });
});

// Hızlı Bölüm Seçimi
document.querySelectorAll('[data-quick-section]').forEach(function(tag) {
  tag.addEventListener('click', function() {
    var inp = document.getElementById('new-table-section');
    if (inp) {
      inp.value = tag.dataset.quickSection;
      inp.focus();
    }
  });
});

// Bölüme Göre Filtreleme (Salon, Bahçe, Tümü)
document.querySelectorAll('[data-section-filter]').forEach(function(chip) {
  chip.addEventListener('click', function() {
    document.querySelectorAll('[data-section-filter]').forEach(function(c) { c.classList.remove('active'); });
    chip.classList.add('active');
    var targetSec = chip.dataset.sectionFilter;
    document.querySelectorAll('[data-table-section]').forEach(function(card) {
      if (targetSec === 'all' || card.dataset.tableSection === targetSec) {
        card.style.display = '';
      } else {
        card.style.display = 'none';
      }
    });
  });
});
</script>
</body>
</html>
