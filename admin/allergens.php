<?php
require_once dirname(__DIR__) . '/inc/app.php';
header('Cache-Control: no-store');
qr_admin_required();
$db = qr_db();
qr_ensure_allergens_schema($db);
qr_ensure_feedback_table($db);

$error = '';
$flash = qr_take_flash();
$success = $flash ? $flash[0] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qr_check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'create') {
            $nameTr = trim(isset($_POST['name_tr']) ? $_POST['name_tr'] : '');
            $nameEn = trim(isset($_POST['name_en']) ? $_POST['name_en'] : '');
            $icon = trim(isset($_POST['icon']) ? $_POST['icon'] : '🛡️');
            $code = trim(isset($_POST['code']) ? $_POST['code'] : '');
            $descTr = trim(isset($_POST['desc_tr']) ? $_POST['desc_tr'] : '');
            $descEn = trim(isset($_POST['desc_en']) ? $_POST['desc_en'] : '');
            $sortOrder = isset($_POST['sort_order']) && is_numeric($_POST['sort_order']) ? (int)$_POST['sort_order'] : 100;

            if ($nameTr === '') {
                throw new RuntimeException('Lütfen alerjen adını (Türkçe) belirtin.');
            }
            if ($icon === '') {
                $icon = '🛡️';
            }

            if ($code === '') {
                $code = qr_slug($nameTr);
            } else {
                $code = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $code));
                $code = trim($code, '-');
            }

            // Check if code already exists
            $check = $db->prepare('SELECT `id` FROM `qr_allergens` WHERE `code` = ?');
            $check->execute(array($code));
            if ($check->fetch()) {
                // Append random suffix
                $code .= '-' . bin2hex(random_bytes(2));
            }

            $stmt = $db->prepare('INSERT INTO `qr_allergens` (`code`, `name_tr`, `name_en`, `icon`, `desc_tr`, `desc_en`, `is_default`, `sort_order`) VALUES (?, ?, ?, ?, ?, ?, 0, ?)');
            $stmt->execute(array($code, $nameTr, $nameEn, $icon, $descTr, $descEn, $sortOrder));

            qr_flash('Yeni alerjen (' . $nameTr . ') başarıyla eklendi.');
            qr_redirect('admin/allergens.php');

        } elseif ($action === 'update') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Alerjen seçilmedi.');

            $nameTr = trim(isset($_POST['name_tr']) ? $_POST['name_tr'] : '');
            $nameEn = trim(isset($_POST['name_en']) ? $_POST['name_en'] : '');
            $icon = trim(isset($_POST['icon']) ? $_POST['icon'] : '🛡️');
            $descTr = trim(isset($_POST['desc_tr']) ? $_POST['desc_tr'] : '');
            $descEn = trim(isset($_POST['desc_en']) ? $_POST['desc_en'] : '');
            $sortOrder = isset($_POST['sort_order']) && is_numeric($_POST['sort_order']) ? (int)$_POST['sort_order'] : 100;

            if ($nameTr === '') {
                throw new RuntimeException('Lütfen alerjen adını belirtin.');
            }
            if ($icon === '') {
                $icon = '🛡️';
            }

            $stmt = $db->prepare('UPDATE `qr_allergens` SET `name_tr` = ?, `name_en` = ?, `icon` = ?, `desc_tr` = ?, `desc_en` = ?, `sort_order` = ? WHERE `id` = ?');
            $stmt->execute(array($nameTr, $nameEn, $icon, $descTr, $descEn, $sortOrder, $id));

            qr_flash('Alerjen bilgileri güncellendi.');
            qr_redirect('admin/allergens.php');

        } elseif ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Geçersiz istek.');

            $stmt = $db->prepare('SELECT `code`, `name_tr` FROM `qr_allergens` WHERE `id` = ?');
            $stmt->execute(array($id));
            $allergen = $stmt->fetch();
            if (!$allergen) throw new RuntimeException('Alerjen bulunamadı.');

            $code = $allergen['code'];

            // Delete allergen row
            $db->prepare('DELETE FROM `qr_allergens` WHERE `id` = ?')->execute(array($id));

            // Clean up products that had this allergen code assigned
            try {
                $affectedProducts = $db->query("SELECT `id`, `allergens` FROM `products` WHERE `allergens` LIKE '%" . addcslashes($code, '%_') . "%'")->fetchAll();
                foreach ($affectedProducts as $prod) {
                    $tags = array_filter(array_map('trim', explode(',', $prod['allergens'])));
                    $newTags = array_values(array_diff($tags, array($code)));
                    $newVal = !empty($newTags) ? implode(',', $newTags) : null;
                    $db->prepare('UPDATE `products` SET `allergens` = ? WHERE `id` = ?')->execute(array($newVal, $prod['id']));
                }
            } catch (Exception $ignored) {}

            qr_flash('Alerjen (' . $allergen['name_tr'] . ') silindi ve ürünlerden kaldırıldı.');
            qr_redirect('admin/allergens.php');

        } elseif ($action === 'restore_defaults') {
            qr_restore_default_allergens($db);
            qr_flash('Varsayılan 14 Türk Gıda Kodeksi alerjeni başarıyla geri yüklendi/güncellendi.');
            qr_redirect('admin/allergens.php');
        } else {
            throw new RuntimeException('Geçersiz işlem.');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch all allergens with product count
$allergens = $db->query("
    SELECT a.*, 
           (SELECT COUNT(*) FROM `products` p WHERE p.`is_active` = 1 AND FIND_IN_SET(a.`code`, REPLACE(p.`allergens`, ' ', '')) > 0) AS `product_count`
    FROM `qr_allergens` a 
    ORDER BY a.`sort_order` ASC, a.`id` ASC
")->fetchAll();

$totalAllergens = count($allergens);
$defaultCount = 0;
$customCount = 0;
$totalProductUses = 0;
foreach ($allergens as $a) {
    if (!empty($a['is_default'])) $defaultCount++;
    else $customCount++;
    $totalProductUses += (int)$a['product_count'];
}

$unreadFeedback = (int)$db->query('SELECT COUNT(*) FROM `qr_menu_feedback` WHERE `is_read` = 0')->fetchColumn();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Alerjen Yönetimi | LAZE QR Menü</title>
  <link rel="icon" href="<?= QR_BASE ?>assets/favicon.png">
  <link rel="stylesheet" href="<?= QR_BASE ?>assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
  <style>
    .allergen-icon-display {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      border-radius: 8px;
      background: #fdfaf6;
      border: 1px solid #ebd9cb;
      font-size: 20px;
    }
    .allergen-code-tag {
      font-family: monospace;
      font-size: 11px;
      background: #f1f4f0;
      color: #294738;
      padding: 2px 6px;
      border-radius: 4px;
      font-weight: 600;
    }
    .allergen-badge-default {
      display: inline-block;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 10px;
      background: #e8f5e9;
      color: #2e7d32;
      border: 1px solid #c8e6c9;
    }
    .allergen-badge-custom {
      display: inline-block;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 10px;
      background: #fff3e0;
      color: #e65100;
      border: 1px solid #ffe0b2;
    }
    .emoji-picker-row {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 6px;
      padding: 8px 10px;
      background: #faf8f5;
      border: 1px solid #eee8e0;
      border-radius: 8px;
    }
    .emoji-pick-btn {
      background: #fff;
      border: 1px solid #e0d8ce;
      border-radius: 6px;
      font-size: 17px;
      width: 32px;
      height: 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: transform 0.15s, border-color 0.15s;
    }
    .emoji-pick-btn:hover {
      transform: scale(1.18);
      border-color: #b67745;
      background: #fff8f2;
    }
    .allergen-filter-search {
      max-width: 280px;
      padding: 8px 12px;
      border: 1px solid var(--line);
      border-radius: 6px;
      font-size: 13px;
    }
  </style>
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
    <a class="current" href="<?= QR_BASE ?>admin/allergens.php">🛡️ <span>Alerjenler</span></a>
    <a href="<?= QR_BASE ?>admin/tables.php">⊞ <span>Masalar &amp; QR</span></a>
    <a href="<?= QR_BASE ?>admin/staff.php">👤 <span>Personel hesapları</span></a>
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
      <h1 class="admin-header-title">Alerjen Yönetimi</h1>
      <p class="admin-header-sub">Menüde ve ürünlerde kullanılacak gıda alerjenlerini ekleyin, düzenleyin ve silin.</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <button type="button" class="primary link-button" id="btnOpenAddModal" style="padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;">
        + Yeni Alerjen Ekle
      </button>
      <form method="post" style="margin:0;" onsubmit="return confirm('Varsayılan 14 Türk Gıda Kodeksi alerjeni geri yüklenecek ve güncellenecektir. Onaylıyor musunuz?');">
        <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
        <input type="hidden" name="action" value="restore_defaults">
        <button type="submit" class="secondary link-button" style="padding:9px 14px;font-size:12px;cursor:pointer;" title="14 Türk Gıda Kodeksi zorunlu alerjenini geri yükler">
          Standardı Sıfırla (14 TGK)
        </button>
      </form>
      <span class="admin-person"><?= qr_e(isset($_SESSION['qr_admin_name']) ? $_SESSION['qr_admin_name'] : 'Yönetici') ?></span>
    </div>
  </header>

  <main class="admin-content">
    <?php if ($flash): ?><div class="alert <?= qr_e($flash[1]) ?>"><?= qr_e($flash[0]) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= qr_e($error) ?></div><?php endif; ?>

    <!-- İstatistik Kartları -->
    <div class="stats tables-stats" style="margin-bottom:24px;">
      <div>
        <small>TOPLAM ALERJEN</small>
        <strong><?= $totalAllergens ?></strong>
        <span>kayıtlı alerjen</span>
      </div>
      <div>
        <small>STANDART TGK</small>
        <strong><?= $defaultCount ?></strong>
        <span>zorunlu alerjen</span>
      </div>
      <div>
        <small>ÖZEL ALERJEN</small>
        <strong><?= $customCount ?></strong>
        <span>elle eklenen</span>
      </div>
      <div>
        <small>ÜRÜN KULLANIMI</small>
        <strong><?= $totalProductUses ?></strong>
        <span>eşleşen ürün sayısı</span>
      </div>
    </div>

    <!-- Alerjen Listesi Paneli -->
    <div class="panel">
      <div class="panel-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
        <div>
          <h2>Kayıtlı Alerjenler</h2>
          <p>Ürün detaylarında ve müşteri alerjen filtresinde listelenen tüm alerjenler</p>
        </div>
        <div>
          <input type="search" id="allergenSearchInput" class="allergen-filter-search" placeholder="Alerjen ara... (örn: Gluten, Süt)">
        </div>
      </div>

      <div class="table-wrap">
        <table class="data-table" id="allergensTable">
          <thead>
            <tr>
              <th style="width:50px;">Simge</th>
              <th>Alerjen Adı (TR)</th>
              <th>İngilizce Adı (EN)</th>
              <th>Sistem Kodu</th>
              <th>Açıklama</th>
              <th style="width:60px;text-align:center;">Sıra</th>
              <th style="width:100px;text-align:center;">Kullanım</th>
              <th style="width:130px;text-align:right;">İşlemler</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allergens)): ?>
              <tr>
                <td colspan="8" style="text-align:center;padding:32px;color:var(--muted);">
                  Henüz kayıtlı alerjen bulunamadı. "Standardı Sıfırla (14 TGK)" butonuna tıklayarak varsayılanları yükleyebilirsiniz.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($allergens as $a): ?>
                <tr data-name="<?= qr_e(mb_strtolower($a['name_tr'] . ' ' . $a['name_en'] . ' ' . $a['code'])) ?>">
                  <td>
                    <span class="allergen-icon-display" title="<?= qr_e($a['name_tr']) ?>">
                      <?= qr_e($a['icon']) ?>
                    </span>
                  </td>
                  <td>
                    <strong style="font-size:14px;color:var(--ink);"><?= qr_e($a['name_tr']) ?></strong>
                    <?php if (!empty($a['is_default'])): ?>
                      <span class="allergen-badge-default" title="Türk Gıda Kodeksi Zorunlu Alerjeni">TGK</span>
                    <?php else: ?>
                      <span class="allergen-badge-custom">Özel</span>
                    <?php endif; ?>
                  </td>
                  <td style="color:#5f695f;"><?= qr_e($a['name_en'] ?: '—') ?></td>
                  <td><span class="allergen-code-tag"><?= qr_e($a['code']) ?></span></td>
                  <td style="font-size:12px;color:#6b786c;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= qr_e($a['desc_tr']) ?>">
                    <?= qr_e($a['desc_tr'] ?: '—') ?>
                  </td>
                  <td style="text-align:center;font-weight:600;color:var(--muted);"><?= (int)$a['sort_order'] ?></td>
                  <td style="text-align:center;">
                    <?php if ($a['product_count'] > 0): ?>
                      <a href="<?= QR_BASE ?>admin/index.php?view=products" title="Ürünleri gör" style="color:var(--admin-orange);font-weight:700;text-decoration:underline;">
                        <?= (int)$a['product_count'] ?> ürün
                      </a>
                    <?php else: ?>
                      <span style="color:#aaa;font-size:12px;">0 ürün</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:right;">
                    <div style="display:inline-flex;gap:6px;align-items:center;">
                      <button type="button" class="btn-action edit-allergen-btn" 
                        data-id="<?= $a['id'] ?>"
                        data-code="<?= qr_e($a['code']) ?>"
                        data-name-tr="<?= qr_e($a['name_tr']) ?>"
                        data-name-en="<?= qr_e($a['name_en']) ?>"
                        data-icon="<?= qr_e($a['icon']) ?>"
                        data-desc-tr="<?= qr_e($a['desc_tr']) ?>"
                        data-desc-en="<?= qr_e($a['desc_en']) ?>"
                        data-sort="<?= (int)$a['sort_order'] ?>"
                        title="Alerjeni Düzenle">
                        ✎
                      </button>
                      <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?= qr_e($a['name_tr']) ?> alerjenini silmek istediğinize emin misiniz?<?= $a['product_count'] > 0 ? ' (Bu alerjen ' . $a['product_count'] . ' üründen de kaldırılacaktır!)' : '' ?>');">
                        <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn-action delete" title="Alerjeni Sil" style="color:#d93225;">
                          ✕
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<!-- Yeni / Düzenle Alerjen Modalı -->
<div class="admin-modal-overlay" id="allergenModalOverlay" style="display:none;">
  <div class="admin-modal-box" style="max-width:540px;">
    <form method="post" id="allergenForm">
      <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
      <input type="hidden" name="action" id="modalAction" value="create">
      <input type="hidden" name="id" id="modalAllergenId" value="">

      <div class="admin-modal-header">
        <h2 id="modalTitle">Yeni Alerjen Ekle</h2>
        <button type="button" class="admin-modal-close" id="btnCloseModal" aria-label="Kapat">✕</button>
      </div>

      <div class="admin-modal-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <label>Alerjen Adı (Türkçe) *
            <input type="text" name="name_tr" id="inputNameTr" required placeholder="örn: Mantar, Bal, Gluten">
          </label>
          <label>İngilizce Adı (Opsiyonel)
            <input type="text" name="name_en" id="inputNameEn" placeholder="örn: Mushroom, Honey">
          </label>
        </div>

        <div style="margin-top:14px;">
          <label>Simge / Emoji *
            <input type="text" name="icon" id="inputIcon" required value="🛡️" style="max-width:110px;font-size:18px;text-align:center;">
          </label>
          <div class="emoji-picker-row">
            <span style="font-size:11px;font-weight:600;color:var(--muted);width:100%;margin-bottom:2px;">Önerilen Simgeler (Tıklayarak seçin):</span>
            <?php 
              $emojis = array('🌾', '🥛', '🥚', '🌰', '🥜', '🫘', '⚪', '🥬', '🟡', '🐟', '🦐', '🦪', '🍷', '🌸', '🍄', '🍓', '🍯', '🥩', '🧀', '🍞', '🌶️', '🍫', '🧄', '🧅', '🛡️');
              foreach ($emojis as $em):
            ?>
              <button type="button" class="emoji-pick-btn" onclick="selectEmoji('<?= $em ?>')"><?= $em ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-top:14px;" id="codeRowWrapper">
          <label>Sistem Kodu (Opsiyonel)
            <input type="text" name="code" id="inputCode" placeholder="Boş bırakılırsa addan üretilir">
            <small style="font-size:10px;color:var(--muted);margin-top:3px;">Harf, rakam ve tire içerebilir (örn: mantar)</small>
          </label>
          <label>Sıralama
            <input type="number" name="sort_order" id="inputSort" value="100" min="0" step="5">
          </label>
        </div>

        <div style="margin-top:14px;">
          <label>Türkçe Açıklama
            <input type="text" name="desc_tr" id="inputDescTr" placeholder="örn: Kültür veya orman mantarı içeren ürünler">
          </label>
        </div>

        <div style="margin-top:14px;">
          <label>İngilizce Açıklama
            <input type="text" name="desc_en" id="inputDescEn" placeholder="örn: Products containing mushrooms">
          </label>
        </div>
      </div>

      <div class="admin-modal-footer">
        <button type="button" class="btn-modal-cancel" id="btnCancelModal">İptal</button>
        <button type="submit" class="btn-modal-submit" id="btnSubmitModal">Kaydet</button>
      </div>
    </form>
  </div>
</div>

<script>
  function selectEmoji(em) {
    var inp = document.getElementById('inputIcon');
    if (inp) inp.value = em;
  }

  var modal = document.getElementById('allergenModalOverlay');
  var form = document.getElementById('allergenForm');
  var modalTitle = document.getElementById('modalTitle');
  var modalAction = document.getElementById('modalAction');
  var modalId = document.getElementById('modalAllergenId');
  var inputNameTr = document.getElementById('inputNameTr');
  var inputNameEn = document.getElementById('inputNameEn');
  var inputIcon = document.getElementById('inputIcon');
  var inputCode = document.getElementById('inputCode');
  var inputSort = document.getElementById('inputSort');
  var inputDescTr = document.getElementById('inputDescTr');
  var inputDescEn = document.getElementById('inputDescEn');
  var codeRowWrapper = document.getElementById('codeRowWrapper');

  function openCreateModal() {
    form.reset();
    modalAction.value = 'create';
    modalId.value = '';
    modalTitle.textContent = 'Yeni Alerjen Ekle';
    inputIcon.value = '🛡️';
    inputSort.value = '100';
    if (codeRowWrapper) codeRowWrapper.style.display = 'grid';
    modal.style.display = 'grid';
    inputNameTr.focus();
  }

  function openEditModal(btn) {
    modalAction.value = 'update';
    modalId.value = btn.dataset.id;
    modalTitle.textContent = 'Alerjeni Düzenle: ' + btn.dataset.nameTr;
    inputNameTr.value = btn.dataset.nameTr || '';
    inputNameEn.value = btn.dataset.nameEn || '';
    inputIcon.value = btn.dataset.icon || '🛡️';
    inputCode.value = btn.dataset.code || '';
    inputSort.value = btn.dataset.sort || '100';
    inputDescTr.value = btn.dataset.descTr || '';
    inputDescEn.value = btn.dataset.descEn || '';
    // In edit mode, hide custom code input to prevent breaking existing product associations
    modal.style.display = 'grid';
    inputNameTr.focus();
  }

  function closeModal() {
    modal.style.display = 'none';
  }

  var btnAdd = document.getElementById('btnOpenAddModal');
  if (btnAdd) btnAdd.addEventListener('click', openCreateModal);

  var btnClose = document.getElementById('btnCloseModal');
  if (btnClose) btnClose.addEventListener('click', closeModal);

  var btnCancel = document.getElementById('btnCancelModal');
  if (btnCancel) btnCancel.addEventListener('click', closeModal);

  modal.addEventListener('click', function(e) {
    if (e.target === modal) closeModal();
  });

  document.querySelectorAll('.edit-allergen-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openEditModal(btn);
    });
  });

  // Search filter
  var searchInput = document.getElementById('allergenSearchInput');
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      var query = searchInput.value.toLowerCase().trim();
      var rows = document.querySelectorAll('#allergensTable tbody tr[data-name]');
      rows.forEach(function(row) {
        var text = row.dataset.name || '';
        row.style.display = text.indexOf(query) !== -1 ? '' : 'none';
      });
    });
  }
</script>
</body>
</html>
