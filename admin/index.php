<?php
require_once dirname(__DIR__) . '/inc/app.php';
$db = qr_db();
qr_ensure_translation_schema($db);
qr_ensure_stock_column($db);
qr_ensure_product_meta_columns($db);
$view = isset($_GET['view']) && in_array($_GET['view'], array('dashboard','products','product','categories','translations','settings','feedback'), true) ? $_GET['view'] : 'dashboard';
if ($view === 'categories' || $view === 'product') {
    qr_redirect('admin/index.php?view=products');
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qr_check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'login') {
        $attempts = isset($_SESSION['qr_login_attempts']) ? $_SESSION['qr_login_attempts'] : array();
        $attempts = array_values(array_filter($attempts, function ($time) { return $time > time() - 900; }));
        if (count($attempts) >= 5) {
            $error = 'Çok fazla deneme yapıldı. 15 dakika sonra tekrar deneyin.';
        } else {
            $name = trim(isset($_POST['username']) ? $_POST['username'] : '');
            $stmt = $db->prepare('SELECT `id`, `name`, `password` FROM `admins` WHERE `username` = ? LIMIT 1');
            $stmt->execute(array($name));
            $admin = $stmt->fetch();
            if ($admin && password_verify(isset($_POST['password']) ? $_POST['password'] : '', $admin['password'])) {
                session_regenerate_id(true);
                $_SESSION['qr_admin_id'] = (int)$admin['id'];
                $_SESSION['qr_admin_name'] = $admin['name'];
                unset($_SESSION['qr_login_attempts']);
                qr_redirect('admin/index.php');
            }
            $attempts[] = time();
            $_SESSION['qr_login_attempts'] = $attempts;
            $error = 'Kullanıcı adı veya şifre hatalı.';
        }
    } elseif ($action === 'logout') {
        $_SESSION = array();
        session_regenerate_id(true);
        qr_redirect('admin/index.php');
    } else {
        qr_admin_required();
        try {
            if ($action === 'feedback_read') {
                qr_ensure_feedback_table($db);
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                if ($id < 1) throw new RuntimeException('Geri bildirim bulunamadı.');
                $stmt = $db->prepare('UPDATE `qr_menu_feedback` SET `is_read` = 1 WHERE `id` = ?');
                $stmt->execute(array($id));
                qr_redirect('admin/index.php?view=feedback');
            } elseif ($action === 'feedback_delete') {
                qr_ensure_feedback_table($db);
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                if ($id < 1) throw new RuntimeException('Geri bildirim bulunamadı.');
                $stmt = $db->prepare('DELETE FROM `qr_menu_feedback` WHERE `id` = ?');
                $stmt->execute(array($id));
                qr_flash('Geri bildirim silindi.');
                qr_redirect('admin/index.php?view=feedback');
            } elseif ($action === 'feedback_delete_all') {
                qr_ensure_feedback_table($db);
                $db->exec('DELETE FROM `qr_menu_feedback`');
                qr_flash('Tüm geri bildirimler silindi.');
                qr_redirect('admin/index.php?view=feedback');
            } elseif ($action === 'translations') {
                $categoryNames = isset($_POST['category_name_en']) && is_array($_POST['category_name_en']) ? $_POST['category_name_en'] : array();
                $categoryDescriptions = isset($_POST['category_description_en']) && is_array($_POST['category_description_en']) ? $_POST['category_description_en'] : array();
                $productNames = isset($_POST['product_name_en']) && is_array($_POST['product_name_en']) ? $_POST['product_name_en'] : array();
                $productDescriptions = isset($_POST['product_description_en']) && is_array($_POST['product_description_en']) ? $_POST['product_description_en'] : array();
                $categoryExists = $db->prepare('SELECT `id` FROM `categories` WHERE `id` = ?');
                $productExists = $db->prepare('SELECT `id` FROM `products` WHERE `id` = ?');
                $saveCategory = $db->prepare('INSERT INTO `qr_menu_category_translations` (`category_id`,`name_en`,`description_en`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `name_en` = VALUES(`name_en`), `description_en` = VALUES(`description_en`)');
                $saveProduct = $db->prepare('INSERT INTO `qr_menu_product_translations` (`product_id`,`name_en`,`description_en`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `name_en` = VALUES(`name_en`), `description_en` = VALUES(`description_en`)');
                $deleteCategory = $db->prepare('DELETE FROM `qr_menu_category_translations` WHERE `category_id` = ?');
                $deleteProduct = $db->prepare('DELETE FROM `qr_menu_product_translations` WHERE `product_id` = ?');
                foreach (array_unique(array_merge(array_keys($categoryNames), array_keys($categoryDescriptions))) as $id) {
                    $id = (int)$id;
                    if ($id < 1) continue;
                    $name = trim(isset($categoryNames[$id]) && is_string($categoryNames[$id]) ? $categoryNames[$id] : '');
                    $description = trim(isset($categoryDescriptions[$id]) && is_string($categoryDescriptions[$id]) ? $categoryDescriptions[$id] : '');
                    if (mb_strlen($name, 'UTF-8') > 100 || mb_strlen($description, 'UTF-8') > 1000) throw new RuntimeException('Kategori çevirilerinden biri çok uzun.');
                    $categoryExists->execute(array($id));
                    if (!$categoryExists->fetch()) continue;
                    if ($name === '' && $description === '') $deleteCategory->execute(array($id)); else $saveCategory->execute(array($id, $name, $description));
                }
                foreach (array_unique(array_merge(array_keys($productNames), array_keys($productDescriptions))) as $id) {
                    $id = (int)$id;
                    if ($id < 1) continue;
                    $name = trim(isset($productNames[$id]) && is_string($productNames[$id]) ? $productNames[$id] : '');
                    $description = trim(isset($productDescriptions[$id]) && is_string($productDescriptions[$id]) ? $productDescriptions[$id] : '');
                    if (mb_strlen($name, 'UTF-8') > 150 || mb_strlen($description, 'UTF-8') > 3000) throw new RuntimeException('Ürün çevirilerinden biri çok uzun.');
                    $productExists->execute(array($id));
                    if (!$productExists->fetch()) continue;
                    if ($name === '' && $description === '') $deleteProduct->execute(array($id)); else $saveProduct->execute(array($id, $name, $description));
                }
                qr_flash('İngilizce çeviriler kaydedildi.');
                qr_redirect('admin/index.php?view=translations');
            } elseif ($action === 'settings') {
                $allowed = array('headline' => 70, 'headline_en' => 70);
                $stmt = $db->prepare('INSERT INTO `qr_menu_settings` (`key_name`, `key_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `key_value` = VALUES(`key_value`)');
                foreach ($allowed as $key => $limit) {
                    $value = trim(isset($_POST[$key]) ? $_POST[$key] : '');
                    if ($key === 'headline' && $value === '') throw new RuntimeException('Menü başlığını girin.');
                    if (mb_strlen($value, 'UTF-8') > $limit) throw new RuntimeException('Metinlerden biri çok uzun.');
                    $stmt->execute(array($key, $value));
                }
                $stmt->execute(array('show_prices', isset($_POST['show_prices']) ? '1' : '0'));
                $stmt->execute(array('allergen_filter_enabled', isset($_POST['allergen_filter_enabled']) ? '1' : '0'));
                $stmt->execute(array('waiter_call_enabled', isset($_POST['waiter_call_enabled']) ? '1' : '0'));
                $stmt->execute(array('onesignal_enabled', isset($_POST['onesignal_enabled']) ? '1' : '0'));
                $stmt->execute(array('onesignal_app_id', trim(isset($_POST['onesignal_app_id']) ? $_POST['onesignal_app_id'] : '')));
                $stmt->execute(array('onesignal_rest_key', trim(isset($_POST['onesignal_rest_key']) ? $_POST['onesignal_rest_key'] : '')));
                $stmt->execute(array('location_check_enabled', isset($_POST['location_check_enabled']) ? '1' : '0'));
                $dist = (int)(isset($_POST['location_max_distance']) ? $_POST['location_max_distance'] : 150);
                if ($dist < 10) $dist = 10;
                if ($dist > 5000) $dist = 5000;
                $stmt->execute(array('location_max_distance', (string)$dist));
                $lat = trim(isset($_POST['restaurant_lat']) ? $_POST['restaurant_lat'] : '40.9252987');
                $lng = trim(isset($_POST['restaurant_lng']) ? $_POST['restaurant_lng'] : '29.3113258');
                if (!is_numeric($lat)) $lat = '40.9252987';
                if (!is_numeric($lng)) $lng = '29.3113258';
                $stmt->execute(array('restaurant_lat', $lat));
                $stmt->execute(array('restaurant_lng', $lng));
                qr_flash('QR menü, konum ve bildirim ayarları kaydedildi.');
                qr_redirect('admin/index.php?view=settings');
            } elseif ($action === 'test_onesignal') {
                $testSuccess = qr_send_onesignal_notification('🔔 LAZE Test Bildirimi', 'OneSignal kilit ekranı bildirim sistemi başarıyla çalışıyor!', 'https://menu.lazekofte.com/staff/');
                if ($testSuccess) {
                    qr_flash('Test bildirimi başarıyla OneSignal üzerinden gönderildi!');
                } else {
                    qr_flash('Test bildirimi gönderilemedi. OneSignal App ID ve API Key bilgilerinizi kontrol edin.', 'error');
                }
                qr_redirect('admin/index.php?view=settings');
            } elseif ($action === 'visibility') {
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                $check = $db->prepare('SELECT `id` FROM `products` WHERE `id` = ?');
                $check->execute(array($id));
                if (!$check->fetch()) throw new RuntimeException('Ürün bulunamadı.');
                if (isset($_POST['hidden']) && $_POST['hidden'] === '1') {
                    $stmt = $db->prepare('INSERT IGNORE INTO `qr_menu_hidden_products` (`product_id`) VALUES (?)');
                } else {
                    $stmt = $db->prepare('DELETE FROM `qr_menu_hidden_products` WHERE `product_id` = ?');
                }
                $stmt->execute(array($id));
                qr_flash('QR menü görünürlüğü güncellendi.');
                qr_redirect('admin/index.php?view=products');
            } elseif ($action === 'category') {
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
                $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
                $order = (int)(isset($_POST['sort_order']) ? $_POST['sort_order'] : 0);
                if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || mb_strlen($description, 'UTF-8') > 1000) throw new RuntimeException('Kategori bilgilerini kontrol edin.');
                $slug = qr_slug($name);
                if ($id) {
                    $stmt = $db->prepare('UPDATE `categories` SET `name` = ?, `description` = ?, `sort_order` = ?, `is_active` = ? WHERE `id` = ?');
                    $stmt->execute(array($name, $description, $order, isset($_POST['is_active']) ? 1 : 0, $id));
                } else {
                    $stmt = $db->prepare('INSERT INTO `categories` (`slug`,`name`,`description`,`sort_order`,`is_active`) VALUES (?,?,?,?,?)');
                    $stmt->execute(array($slug, $name, $description, $order, isset($_POST['is_active']) ? 1 : 0));
                }
                qr_flash('Kategori kaydedildi. Değişiklikler ana sitede de görünür.');
                qr_redirect('admin/index.php?view=products');
            } elseif ($action === 'category_delete') {
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                $check = $db->prepare('SELECT `id` FROM `categories` WHERE `id` = ?');
                $check->execute(array($id));
                if (!$check->fetch()) throw new RuntimeException('Kategori bulunamadı.');
                $pCount = $db->prepare('SELECT COUNT(*) FROM `products` WHERE `category_id` = ?');
                $pCount->execute(array($id));
                if ((int)$pCount->fetchColumn() > 0) {
                    throw new RuntimeException('Bu kategoride ürünler bulunduğu için silinemez. Önce ürünleri başka bir kategoriye taşıyın.');
                }
                $db->prepare('DELETE FROM `categories` WHERE `id` = ?')->execute(array($id));
                $db->prepare('DELETE FROM `qr_menu_category_translations` WHERE `category_id` = ?')->execute(array($id));
                qr_flash('Kategori silindi.');
                qr_redirect('admin/index.php?view=products');
            } elseif ($action === 'product_toggle') {
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                $check = $db->prepare('SELECT `id`, `is_active` FROM `products` WHERE `id` = ?');
                $check->execute(array($id));
                $p = $check->fetch();
                if (!$p) throw new RuntimeException('Ürün bulunamadı.');
                $newState = $p['is_active'] ? 0 : 1;
                $stmt = $db->prepare('UPDATE `products` SET `is_active` = ? WHERE `id` = ?');
                $stmt->execute(array($newState, $id));
                qr_flash($newState ? 'Ürün aktif edildi.' : 'Ürün pasife alındı.');
                qr_redirect('admin/index.php?view=products');
            } elseif ($action === 'product_delete') {
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                $check = $db->prepare('SELECT `id` FROM `products` WHERE `id` = ?');
                $check->execute(array($id));
                if (!$check->fetch()) throw new RuntimeException('Ürün bulunamadı.');
                $db->prepare('DELETE FROM `products` WHERE `id` = ?')->execute(array($id));
                $db->prepare('DELETE FROM `qr_menu_hidden_products` WHERE `product_id` = ?')->execute(array($id));
                $db->prepare('DELETE FROM `qr_menu_product_translations` WHERE `product_id` = ?')->execute(array($id));
                qr_flash('Ürün silindi.');
                qr_redirect('admin/index.php?view=products');
            } elseif ($action === 'product') {
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                $category = (int)(isset($_POST['category_id']) ? $_POST['category_id'] : 0);
                $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
                $tag = trim(isset($_POST['tag']) ? $_POST['tag'] : '');
                $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
                $order = (int)(isset($_POST['sort_order']) ? $_POST['sort_order'] : 0);
                $priceInput = str_replace(',', '.', trim(isset($_POST['price']) ? $_POST['price'] : ''));
                $price = $priceInput === '' ? null : (is_numeric($priceInput) && (float)$priceInput >= 0 ? round((float)$priceInput, 2) : false);
                $discountPriceInput = str_replace(',', '.', trim(isset($_POST['discount_price']) ? $_POST['discount_price'] : ''));
                $discountPrice = ($discountPriceInput === '' || !is_numeric($discountPriceInput) || (float)$discountPriceInput <= 0) ? null : round((float)$discountPriceInput, 2);
                $stockInput = trim(isset($_POST['stock']) ? $_POST['stock'] : '');
                $stock = ($stockInput === '' || !is_numeric($stockInput)) ? null : max(0, (int)$stockInput);
                $prepTime = trim(isset($_POST['prep_time']) ? $_POST['prep_time'] : '');
                $calories = trim(isset($_POST['calories']) ? $_POST['calories'] : '');
                if ($calories === '0' || strtolower($calories) === '0 kcal' || strtolower($calories) === '0kcal') {
                    $calories = '';
                }
                $weight = trim(isset($_POST['weight']) ? $_POST['weight'] : '');
                $allergens = trim(isset($_POST['allergens']) ? $_POST['allergens'] : '');
                $image = trim(isset($_POST['image']) ? $_POST['image'] : '');
                $check = $db->prepare('SELECT `id` FROM `categories` WHERE `id` = ?');
                $check->execute(array($category));
                if (!$check->fetch() || $name === '' || mb_strlen($name, 'UTF-8') > 150 || mb_strlen($tag, 'UTF-8') > 60 || mb_strlen($description, 'UTF-8') > 3000 || $price === false) throw new RuntimeException('Ürün bilgilerini kontrol edin.');
                if ($image !== '' && qr_image_url($image) === QR_BASE . 'assets/placeholder.webp') throw new RuntimeException('Görsel adresi geçersiz.');
                if (!empty($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) $image = qr_store_image($_FILES['image_file']);
                $featured = isset($_POST['is_featured']) ? 1 : 0;
                $websiteHidden = isset($_POST['website_hidden']) ? 1 : 0;
                $active = isset($_POST['is_active']) ? 1 : 0;
                if ($id) {
                    $stmt = $db->prepare('UPDATE `products` SET `category_id`=?,`name`=?,`tag`=?,`description`=?,`price`=?,`discount_price`=?,`stock`=?,`prep_time`=?,`calories`=?,`weight`=?,`allergens`=?,`image`=?,`is_featured`=?,`website_hidden`=?,`is_active`=?,`sort_order`=? WHERE `id`=?');
                    $stmt->execute(array($category,$name,$tag,$description,$price,$discountPrice,$stock,$prepTime,$calories,$weight,$allergens,$image,$featured,$websiteHidden,$active,$order,$id));
                    $targetId = $id;
                } else {
                    $slug = qr_slug($name);
                    $stmt = $db->prepare('SELECT 1 FROM `products` WHERE `slug` = ?');
                    $stmt->execute(array($slug));
                    if ($stmt->fetch()) $slug .= '-' . bin2hex(random_bytes(3));
                    $stmt = $db->prepare('INSERT INTO `products` (`category_id`,`slug`,`name`,`tag`,`description`,`price`,`discount_price`,`stock`,`prep_time`,`calories`,`weight`,`allergens`,`image`,`is_featured`,`website_hidden`,`is_active`,`sort_order`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute(array($category,$slug,$name,$tag,$description,$price,$discountPrice,$stock,$prepTime,$calories,$weight,$allergens,$image,$featured,$websiteHidden,$active,$order));
                    $targetId = (int)$db->lastInsertId();
                }
                if ($targetId > 0) {
                    if (isset($_POST['qr_hidden']) && $_POST['qr_hidden'] === '1') {
                        $db->prepare('INSERT IGNORE INTO `qr_menu_hidden_products` (`product_id`) VALUES (?)')->execute(array($targetId));
                    } else {
                        $db->prepare('DELETE FROM `qr_menu_hidden_products` WHERE `product_id` = ?')->execute(array($targetId));
                    }
                }
                qr_flash('Ürün kaydedildi. Değişiklikler ana sitede de görünür.');
                qr_redirect('admin/index.php?view=products');
            }
        } catch (Exception $e) {
            error_log('QR menu admin: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Kaydedilemedi. Aynı adlı bir kayıt olabilir.';
        }
    }
}

if (empty($_SESSION['qr_admin_id'])):
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Yönetici Girişi | LAZE QR Menü</title><link rel="stylesheet" href="<?= QR_BASE ?>assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>"></head><body class="auth-body"><main class="auth-card"><img src="<?= QR_BASE ?>assets/logo.webp" width="74" height="74" alt="LAZE"><span class="eyebrow">LAZE QR MENÜ</span><h1>Yönetici girişi</h1><p>Ana sitedeki yönetici hesabınızla giriş yapın.</p><?php if ($error): ?><div class="alert error"><?= qr_e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>"><input type="hidden" name="action" value="login"><label>Kullanıcı adı<input name="username" autocomplete="username" required autofocus></label><label>Şifre<input name="password" type="password" autocomplete="current-password" required></label><button class="primary" type="submit">Giriş yap →</button></form></main></body></html>
<?php exit; endif;

$flash = qr_take_flash();
$settings = qr_settings($db);
$categories = $db->query('
    SELECT c.*, COUNT(p.id) AS product_count 
    FROM `categories` c 
    LEFT JOIN `products` p ON p.`category_id` = c.`id` 
    GROUP BY c.`id` 
    ORDER BY c.`sort_order`, c.`id`
')->fetchAll();
$categoryMap = array(); foreach ($categories as $category) $categoryMap[$category['id']] = $category['name'];
$products = $db->query('SELECT p.*, h.`product_id` AS `qr_hidden` FROM `products` p LEFT JOIN `qr_menu_hidden_products` h ON h.`product_id`=p.`id` ORDER BY p.`sort_order`,p.`id`')->fetchAll();
$categoryTranslations = array(); foreach ($db->query('SELECT `category_id`,`name_en`,`description_en` FROM `qr_menu_category_translations`') as $item) $categoryTranslations[$item['category_id']] = $item;
$productTranslations = array(); foreach ($db->query('SELECT `product_id`,`name_en`,`description_en` FROM `qr_menu_product_translations`') as $item) $productTranslations[$item['product_id']] = $item;
$activeCount = 0; foreach ($products as $product) if ($product['is_active'] && !$product['qr_hidden']) $activeCount++;
qr_ensure_feedback_table($db);
$unreadFeedback = (int)$db->query('SELECT COUNT(*) FROM `qr_menu_feedback` WHERE `is_read` = 0')->fetchColumn();
$feedbackItems = $view === 'feedback' ? $db->query('SELECT `id`,`rating`,`comment`,`is_read`,`created_at` FROM `qr_menu_feedback` ORDER BY `id` DESC LIMIT 100')->fetchAll() : array();
$feedbackStats = array('total' => 0, 'avg_rating' => 0, 'five_star' => 0);
if ($view === 'feedback') {
    $fbStatRow = $db->query('SELECT COUNT(*) AS total, AVG(rating) AS avg_rating, SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS five_star FROM `qr_menu_feedback`')->fetch();
    if ($fbStatRow) {
        $feedbackStats['total'] = (int)$fbStatRow['total'];
        $feedbackStats['avg_rating'] = $fbStatRow['total'] > 0 ? round((float)$fbStatRow['avg_rating'], 1) : 0;
        $feedbackStats['five_star'] = (int)$fbStatRow['five_star'];
    }
}
$allAllergens = qr_allergens('tr');
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>QR Menü Yönetimi | LAZE</title><link rel="icon" href="<?= QR_BASE ?>assets/favicon.png"><link rel="stylesheet" href="<?= QR_BASE ?>assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>"></head><body class="admin-body">
<?php require dirname(__DIR__) . '/inc/admin-sidebar.php'; ?>
<div class="admin-main"><header class="admin-top"><div><?php if ($view === 'products'): ?><h1 class="admin-header-title">Ürün Yönetimi</h1><p class="admin-header-sub">Ürünleri ve kategorileri yönetin</p><?php else: ?><span class="eyebrow">LAZE KÖFTE &amp; ÇORBA</span><h1><?= qr_e(array('dashboard'=>'Genel bakış','translations'=>'İngilizce çeviriler','feedback'=>'Geri bildirimler','settings'=>'QR menü ayarları')[$view]) ?></h1><?php endif; ?></div><span class="admin-person"><?= qr_e(isset($_SESSION['qr_admin_name']) ? $_SESSION['qr_admin_name'] : 'Yönetici') ?></span></header><main class="admin-content">
<?php if ($flash): ?><div class="alert <?= qr_e($flash[1]) ?>"><?= qr_e($flash[0]) ?></div><?php endif; ?><?php if ($error): ?><div class="alert error"><?= qr_e($error) ?></div><?php endif; ?>
<?php if ($view === 'dashboard'): ?>
<div class="panel" style="margin-bottom:20px"><div class="panel-head"><div><h2>Masa servisi</h2><p>Masa QR kodlarını ve kasa/garson hesaplarını yönetin.</p></div></div><div class="actions"><a class="primary link-button" href="<?= QR_BASE ?>admin/service.php">Canlı çağrıları aç →</a><a class="secondary link-button" href="<?= QR_BASE ?>admin/tables.php">Masalar ve QR kodları →</a><a class="secondary link-button" href="<?= QR_BASE ?>admin/staff.php">Personel hesapları →</a></div></div>
<div class="stats"><div><small>QR MENÜDE</small><strong><?= $activeCount ?></strong><span>yayındaki ürün</span></div><div><small>KATEGORİ</small><strong><?= count($categories) ?></strong><span>ortak kategori</span></div><div><small>TOPLAM ÜRÜN</small><strong><?= count($products) ?></strong><span>ana siteyle ortak</span></div></div><div class="panel welcome-panel"><span class="eyebrow">SOFRAYA HOŞ GELDİNİZ</span><h2>Menünüzü buradan yönetin.</h2><p>Ürün, kategori ve fiyat değişiklikleri lazekofte.com ile ortaktır. Bir ürünü yalnızca QR menüden kaldırmak için Ürünler bölümündeki QR görünürlüğünü kullanın.</p><div class="actions"><a class="primary link-button" href="<?= QR_BASE ?>admin/index.php?view=products">Ürünleri düzenle →</a><a class="secondary link-button" href="<?= QR_BASE ?>" target="_blank">Menüyü aç ↗</a></div></div>
<?php elseif ($view === 'products'): ?>
<div class="unified-mgmt-grid">
  <!-- Sol Sütun: Kategoriler Kartı -->
  <div class="mgmt-cat-card">
    <div class="mgmt-cat-header">
      <h3>Kategoriler</h3>
      <button type="button" class="btn-add-cat-round" id="btnAddCat" title="Yeni Kategori Ekle">+</button>
    </div>
    <div class="mgmt-cat-list" id="catList">
      <div class="cat-item-row active" data-cat-id="all">
        <div class="cat-item-main">
          <span class="cat-item-title">Tümü</span>
        </div>
        <span class="cat-item-badge"><?= count($products) ?></span>
      </div>
      <?php foreach ($categories as $cat): ?>
        <div class="cat-item-row" 
             data-cat-id="<?= (int)$cat['id'] ?>" 
             data-cat-name="<?= qr_e($cat['name']) ?>" 
             data-cat-desc="<?= qr_e($cat['description']) ?>" 
             data-cat-order="<?= (int)$cat['sort_order'] ?>" 
             data-cat-active="<?= (int)$cat['is_active'] ?>" 
             data-cat-count="<?= (int)$cat['product_count'] ?>">
          <div class="cat-item-main">
            <span class="cat-item-title"><?= qr_e($cat['name']) ?></span>
          </div>
          <span class="cat-item-badge"><?= (int)$cat['product_count'] ?></span>
          <div class="cat-item-actions">
            <button type="button" class="cat-action-btn edit-cat-btn" title="Kategoriyi Düzenle">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </button>
            <?php if ((int)$cat['product_count'] === 0): ?>
              <button type="button" class="cat-action-btn delete-cat-btn" title="Kategoriyi Sil">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Sağ Sütun: Arama Çubuğu + Tablo -->
  <div class="mgmt-prod-area">
    <div class="mgmt-topbar">
      <div class="mgmt-search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" class="mgmt-search-input" id="prodSearchInput" placeholder="Ürün ara..." autocomplete="off">
        <button type="button" class="mgmt-search-clear" id="prodSearchClear" style="display:none;">&times;</button>
      </div>
      <button type="button" class="btn-add-product" id="btnAddProd">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        <span>Ürün Ekle</span>
      </button>
    </div>

    <div class="mgmt-table-card">
      <table class="mgmt-table" id="prodTable">
        <thead>
          <tr>
            <th>Ürün</th>
            <th>Kategori</th>
            <th>Fiyat</th>
            <th>Durum</th>
            <th style="text-align:right">İşlemler</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $item): ?>
            <tr class="prod-row" 
                data-id="<?= (int)$item['id'] ?>" 
                data-cat-id="<?= (int)$item['category_id'] ?>" 
                data-name="<?= qr_e(mb_strtolower($item['name'], 'UTF-8')) ?>" 
                data-tag="<?= qr_e(mb_strtolower($item['tag'], 'UTF-8')) ?>" 
                data-cat-name="<?= qr_e(mb_strtolower(isset($categoryMap[$item['category_id']]) ? $categoryMap[$item['category_id']] : '', 'UTF-8')) ?>" 
                data-json="<?= qr_e(json_encode(array(
                    'id' => (int)$item['id'],
                    'category_id' => (int)$item['category_id'],
                    'name' => $item['name'],
                    'tag' => $item['tag'],
                    'description' => $item['description'],
                    'price' => $item['price'],
                    'discount_price' => isset($item['discount_price']) ? $item['discount_price'] : null,
                    'stock' => $item['stock'],
                    'prep_time' => isset($item['prep_time']) ? $item['prep_time'] : '',
                    'calories' => isset($item['calories']) ? $item['calories'] : '',
                    'weight' => isset($item['weight']) ? $item['weight'] : '',
                    'allergens' => isset($item['allergens']) ? $item['allergens'] : '',
                    'image' => $item['image'],
                    'image_url' => qr_image_url($item['image']),
                    'is_featured' => (int)$item['is_featured'],
                    'is_active' => (int)$item['is_active'],
                    'sort_order' => (int)$item['sort_order'],
                    'website_hidden' => !empty($item['website_hidden']) ? 1 : 0,
                    'qr_hidden' => !empty($item['qr_hidden']) ? 1 : 0
                ), JSON_UNESCAPED_UNICODE)) ?>">
              <td>
                <div class="cell-product">
                  <?php if (!empty($item['image'])): ?>
                    <img class="cell-product-img" src="<?= qr_e(qr_image_url($item['image'])) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="cell-product-placeholder">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </div>
                  <?php endif; ?>
                  <div class="cell-product-meta">
                    <span class="cell-product-name"><?= qr_e($item['name']) ?></span>
                    <span class="cell-product-sub">
                      <?= qr_e($item['tag'] !== '' ? $item['tag'] : (isset($categoryMap[$item['category_id']]) ? $categoryMap[$item['category_id']] : 'Ürün #' . $item['id'])) ?>
                      <?php 
                      $cAdmin = trim(isset($item['calories']) ? $item['calories'] : '');
                      $hasCAdmin = ($cAdmin !== '' && $cAdmin !== '0' && strtolower($cAdmin) !== '0 kcal' && strtolower($cAdmin) !== '0kcal');
                      $metaList = array_filter(array(isset($item['prep_time']) ? $item['prep_time'] : '', $hasCAdmin ? $cAdmin : '', isset($item['weight']) ? $item['weight'] : ''));
                      if (!empty($metaList)): ?>
                        · <span style="color:#777;font-size:10.5px;"><?= qr_e(implode(' · ', $metaList)) ?></span>
                      <?php endif; ?>
                    </span>
                    <?php if (!empty($item['allergens'])): 
                      $itemAllergens = array_filter(array_map('trim', explode(',', $item['allergens'])));
                      if (!empty($itemAllergens)):
                    ?>
                      <span class="cell-allergen-chips" title="Alerjenler: <?= qr_e(implode(', ', array_map(function($k) use ($allAllergens) { return isset($allAllergens[$k]) ? $allAllergens[$k]['name'] : $k; }, $itemAllergens))) ?>">
                        <?php foreach ($itemAllergens as $ak): if (isset($allAllergens[$ak])): ?>
                          <span class="cell-allergen-mini" title="<?= qr_e($allAllergens[$ak]['name']) ?>"><?= $allAllergens[$ak]['icon'] ?></span>
                        <?php endif; endforeach; ?>
                      </span>
                    <?php endif; endif; ?>
                  </div>
                </div>
              </td>
              <td><span class="cell-category"><?= qr_e(isset($categoryMap[$item['category_id']]) ? $categoryMap[$item['category_id']] : '—') ?></span></td>
              <td>
                <?php if (!empty($item['discount_price']) && (float)$item['discount_price'] > 0 && (float)$item['discount_price'] < (float)$item['price']): ?>
                  <div style="display:flex;flex-direction:column;gap:1px;line-height:1.2;">
                    <span class="cell-price" style="color:var(--admin-orange);font-weight:700;">₺<?= number_format((float)$item['discount_price'], 2, ',', '.') ?></span>
                    <span style="font-size:11px;color:#888;text-decoration:line-through;">₺<?= number_format((float)$item['price'], 2, ',', '.') ?></span>
                  </div>
                <?php else: ?>
                  <span class="cell-price"><?= $item['price'] !== null ? '₺' . number_format((float)$item['price'], 2, ',', '.') : '—' ?></span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
                  <form method="post" class="toggle-form" style="display:inline;margin:0;">
                    <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                    <input type="hidden" name="action" value="product_toggle">
                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                    <button type="submit" class="badge-status <?= $item['is_active'] ? 'active' : 'passive' ?>" title="Durumu değiştirmek için tıklayın">
                      <?= $item['is_active'] ? 'Aktif' : 'Pasif' ?>
                    </button>
                  </form>
                  <?php if (!empty($item['website_hidden'])): ?>
                    <span class="badge muted" title="lazekofte.com/menu sayfasında gizli" style="font-size:9.5px;padding:3px 6px;white-space:nowrap;">Web'de Gizli</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="cell-actions">
                  <button type="button" class="action-icon-btn edit-prod-btn" title="Ürünü Düzenle">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                  </button>
                  <button type="button" class="action-icon-btn delete delete-prod-btn" data-id="<?= (int)$item['id'] ?>" data-name="<?= qr_e($item['name']) ?>" title="Ürünü Sil">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="mgmt-empty" id="prodEmpty" style="display:none;">
        <p>Aradığınız kriterlere uygun ürün bulunamadı.</p>
        <button type="button" id="btnResetFilters">Filtreleri Temizle</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Ürün Ekle / Düzenle -->
<div class="admin-modal-overlay" id="productModal">
  <div class="admin-modal-box">
    <div class="admin-modal-header">
      <h2 id="productModalTitle">Yeni Ürün Ekle</h2>
      <button type="button" class="admin-modal-close" data-close="productModal">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
      <input type="hidden" name="action" value="product">
      <input type="hidden" name="id" id="prodFormId" value="0">
      <div class="admin-modal-body">
        <div class="form-row">
          <label>Ürün adı *
            <input name="name" id="prodFormName" required maxlength="150" placeholder="Örn: Laze Köfte">
          </label>
          <label>Kategori *
            <select name="category_id" id="prodFormCat" required>
              <option value="">Kategori seçin</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= qr_e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="form-row form-row-3">
          <label>Fiyat (₺) *
            <input name="price" id="prodFormPrice" inputmode="decimal" placeholder="170,00">
          </label>
          <label>İndirimli Fiyat (₺)
            <input name="discount_price" id="prodFormDiscountPrice" inputmode="decimal" placeholder="İndirim yoksa boş">
          </label>
          <label>Stok
            <input name="stock" id="prodFormStock" type="number" min="0" placeholder="Boş = sınırsız">
          </label>
        </div>
        <div class="form-row">
          <label>Etiket / Alt Başlık
            <input name="tag" id="prodFormTag" maxlength="60" placeholder="Örn: Özel Baharatlı">
          </label>
          <label>Sıralama
            <input name="sort_order" id="prodFormOrder" type="number" value="1">
          </label>
        </div>
        <div class="form-row form-row-3">
          <label>Hazırlanma Süresi
            <input name="prep_time" id="prodFormPrepTime" placeholder="Örn: 15 dk">
          </label>
          <label>Kalori
            <input name="calories" id="prodFormCalories" placeholder="Örn: 350 kcal">
          </label>
          <label>Porsiyon / Gramaj
            <input name="weight" id="prodFormWeight" placeholder="Örn: 250 g">
          </label>
        </div>
        <div class="admin-allergen-section">
          <div class="admin-allergen-header">
            <div>
              <span class="admin-allergen-title">🛡️ Alerjen Bildirimi</span>
              <span class="admin-allergen-sub">Ürünün içerdiği alerjenleri tıklayarak işaretleyin. <a href="<?= QR_BASE ?>admin/allergens.php" target="_blank" style="color:var(--admin-orange);text-decoration:underline;margin-left:4px;">Alerjenleri Yönet ↗</a></span>
            </div>
            <button type="button" class="btn-clear-allergens" id="btnClearAllergens">Tümünü Kaldır</button>
          </div>
          <div class="admin-allergen-grid" id="adminAllergenGrid">
            <?php foreach ($allAllergens as $aKey => $aData): ?>
              <button type="button" class="admin-allergen-chip" data-allergen="<?= qr_e($aKey) ?>" title="<?= qr_e($aData['desc']) ?>">
                <span class="allergen-icon"><?= $aData['icon'] ?></span>
                <span class="allergen-name"><?= qr_e($aData['name']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="allergens" id="prodFormAllergens" value="">
        </div>
        <label>Açıklama
          <textarea name="description" id="prodFormDesc" rows="3" maxlength="3000" placeholder="Ürün açıklaması..."></textarea>
        </label>
        <label>Yeni Görsel Yükle
          <input name="image_file" id="prodFormFile" type="file" accept="image/jpeg,image/png,image/webp">
          <small style="font-weight:400;color:#777">JPG, PNG veya WebP. Otomatik WebP formatına çevrilir ve küçültülür.</small>
        </label>
        <div class="upload-preview-wrap" id="prodImagePreviewWrap" style="display:none;">
          <img class="upload-preview-thumb" id="prodImagePreview" src="" alt="Önizleme">
          <span style="font-size:12px;color:#777" id="prodImagePreviewText"></span>
        </div>
        <label>veya Mevcut Görsel Adresi / Yolu
          <input name="image" id="prodFormImage" placeholder="uploads/products/fotograf.webp">
        </label>
        <div class="checks" style="display:flex;flex-wrap:wrap;gap:16px;padding-top:4px;">
          <label style="flex-direction:row;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" name="is_active" id="prodFormActive" checked> Aktif / Yayında
          </label>
          <label style="flex-direction:row;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" name="is_featured" id="prodFormFeatured"> Ana sitede öne çıkar
          </label>
          <label style="flex-direction:row;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" name="website_hidden" id="prodFormWebsiteHidden" value="1"> Website menüde gizle
          </label>
          <label style="flex-direction:row;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" name="qr_hidden" id="prodFormQrHidden" value="1"> QR Menüde Gizle
          </label>
        </div>
      </div>
      <div class="admin-modal-footer">
        <button type="button" class="btn-modal-cancel" data-close="productModal">İptal</button>
        <button type="submit" class="btn-modal-submit">Kaydet</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Kategori Ekle / Düzenle -->
<div class="admin-modal-overlay" id="categoryModal">
  <div class="admin-modal-box" style="width:min(460px,100%);">
    <div class="admin-modal-header">
      <h2 id="categoryModalTitle">Yeni Kategori Ekle</h2>
      <button type="button" class="admin-modal-close" data-close="categoryModal">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
      <input type="hidden" name="action" value="category">
      <input type="hidden" name="id" id="catFormId" value="0">
      <div class="admin-modal-body">
        <label>Kategori Adı *
          <input name="name" id="catFormName" required maxlength="100" placeholder="Örn: Köfteler">
        </label>
        <label>Açıklama
          <textarea name="description" id="catFormDesc" rows="3" maxlength="1000" placeholder="Kategori açıklaması..."></textarea>
        </label>
        <label>Sıralama
          <input name="sort_order" id="catFormOrder" type="number" value="1">
        </label>
        <label class="check" style="flex-direction:row;align-items:center;gap:6px;cursor:pointer;">
          <input type="checkbox" name="is_active" id="catFormActive" checked> Yayında
        </label>
      </div>
      <div class="admin-modal-footer">
        <button type="button" class="btn-modal-cancel" data-close="categoryModal">İptal</button>
        <button type="submit" class="btn-modal-submit">Kaydet</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Silme Onayı -->
<div class="admin-modal-overlay" id="deleteModal">
  <div class="admin-modal-box" style="width:min(420px,100%);">
    <div class="admin-modal-header">
      <h2 id="deleteModalTitle">Silme Onayı</h2>
      <button type="button" class="admin-modal-close" data-close="deleteModal">&times;</button>
    </div>
    <form method="post" id="deleteModalForm">
      <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
      <input type="hidden" name="action" id="deleteModalAction" value="">
      <input type="hidden" name="id" id="deleteModalId" value="0">
      <div class="admin-modal-body">
        <p id="deleteModalMessage" style="margin:0;font-size:14px;color:#333;line-height:1.5;"></p>
      </div>
      <div class="admin-modal-footer">
        <button type="button" class="btn-modal-cancel" data-close="deleteModal">Vazgeç</button>
        <button type="submit" class="btn-modal-submit" style="background:#dc2626;">Sil</button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  var catRows = document.querySelectorAll('.cat-item-row');
  var prodRows = document.querySelectorAll('.prod-row');
  var searchInput = document.getElementById('prodSearchInput');
  var searchClear = document.getElementById('prodSearchClear');
  var emptyState = document.getElementById('prodEmpty');
  var btnResetFilters = document.getElementById('btnResetFilters');

  var activeCatId = 'all';
  var searchQuery = '';

  function filterTable() {
    var visibleCount = 0;
    var query = searchQuery.trim().toLocaleLowerCase('tr');

    prodRows.forEach(function(row) {
      var catId = row.dataset.catId;
      var name = row.dataset.name || '';
      var tag = row.dataset.tag || '';
      var catName = row.dataset.catName || '';

      var matchesCat = (activeCatId === 'all') || (catId === activeCatId);
      var matchesSearch = !query || name.indexOf(query) !== -1 || tag.indexOf(query) !== -1 || catName.indexOf(query) !== -1;

      if (matchesCat && matchesSearch) {
        row.classList.remove('filtered-out');
        visibleCount++;
      } else {
        row.classList.add('filtered-out');
      }
    });

    if (emptyState) {
      emptyState.style.display = visibleCount === 0 ? 'flex' : 'none';
    }
  }

  catRows.forEach(function(row) {
    row.addEventListener('click', function(e) {
      if (e.target.closest('.cat-item-actions')) return;
      catRows.forEach(function(r) { r.classList.remove('active'); });
      row.classList.add('active');
      activeCatId = row.dataset.catId || 'all';
      filterTable();
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', function() {
      searchQuery = searchInput.value;
      if (searchClear) searchClear.style.display = searchQuery ? 'block' : 'none';
      filterTable();
    });
  }

  if (searchClear) {
    searchClear.addEventListener('click', function() {
      searchInput.value = '';
      searchQuery = '';
      searchClear.style.display = 'none';
      filterTable();
      searchInput.focus();
    });
  }

  if (btnResetFilters) {
    btnResetFilters.addEventListener('click', function() {
      activeCatId = 'all';
      catRows.forEach(function(r) { r.classList.toggle('active', r.dataset.catId === 'all'); });
      if (searchInput) {
        searchInput.value = '';
        searchQuery = '';
        if (searchClear) searchClear.style.display = 'none';
      }
      filterTable();
    });
  }

  function openModal(id) {
    var modal = document.getElementById(id);
    if (modal) modal.classList.add('open');
  }

  function closeModal(id) {
    var modal = document.getElementById(id);
    if (modal) modal.classList.remove('open');
  }

  document.querySelectorAll('[data-close]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      closeModal(btn.getAttribute('data-close'));
    });
  });

  document.querySelectorAll('.admin-modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
      if (e.target === modal) modal.classList.remove('open');
    });
  });

  var btnAddProd = document.getElementById('btnAddProd');
  if (btnAddProd) {
    btnAddProd.addEventListener('click', function() {
      document.getElementById('productModalTitle').textContent = 'Yeni Ürün Ekle';
      document.getElementById('prodFormId').value = '0';
      document.getElementById('prodFormName').value = '';
      document.getElementById('prodFormCat').value = activeCatId !== 'all' ? activeCatId : '';
      document.getElementById('prodFormPrice').value = '';
      document.getElementById('prodFormDiscountPrice').value = '';
      document.getElementById('prodFormStock').value = '';
      document.getElementById('prodFormTag').value = '';
      document.getElementById('prodFormPrepTime').value = '';
      document.getElementById('prodFormCalories').value = '';
      document.getElementById('prodFormWeight').value = '';
      document.getElementById('prodFormAllergens').value = '';
      document.querySelectorAll('.admin-allergen-chip').forEach(function(c) { c.classList.remove('active'); });
      document.getElementById('prodFormOrder').value = prodRows.length + 1;
      document.getElementById('prodFormDesc').value = '';
      document.getElementById('prodFormImage').value = '';
      document.getElementById('prodFormFile').value = '';
      document.getElementById('prodFormActive').checked = true;
      document.getElementById('prodFormFeatured').checked = false;
      document.getElementById('prodFormWebsiteHidden').checked = false;
      document.getElementById('prodFormQrHidden').checked = false;
      document.getElementById('prodImagePreviewWrap').style.display = 'none';
      openModal('productModal');
    });
  }

  document.querySelectorAll('.edit-prod-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tr = btn.closest('tr');
      if (!tr) return;
      try {
        var d = JSON.parse(tr.dataset.json);
        document.getElementById('productModalTitle').textContent = 'Ürünü Düzenle: ' + d.name;
        document.getElementById('prodFormId').value = d.id;
        document.getElementById('prodFormName').value = d.name || '';
        document.getElementById('prodFormCat').value = d.category_id || '';
        document.getElementById('prodFormPrice').value = d.price !== null ? d.price : '';
        document.getElementById('prodFormDiscountPrice').value = d.discount_price !== null && d.discount_price !== undefined ? d.discount_price : '';
        document.getElementById('prodFormStock').value = d.stock !== null ? d.stock : '';
        document.getElementById('prodFormTag').value = d.tag || '';
        document.getElementById('prodFormPrepTime').value = d.prep_time || '';
        document.getElementById('prodFormCalories').value = d.calories || '';
        document.getElementById('prodFormWeight').value = d.weight || '';
        document.getElementById('prodFormAllergens').value = d.allergens || '';
        
        var activeAllergens = (d.allergens || '').split(',').map(function(s) { return s.trim(); });
        document.querySelectorAll('.admin-allergen-chip').forEach(function(c) {
          c.classList.toggle('active', activeAllergens.indexOf(c.dataset.allergen) !== -1);
        });

        document.getElementById('prodFormOrder').value = d.sort_order || 0;
        document.getElementById('prodFormDesc').value = d.description || '';
        document.getElementById('prodFormImage').value = d.image || '';
        document.getElementById('prodFormFile').value = '';
        document.getElementById('prodFormActive').checked = parseInt(d.is_active) === 1;
        document.getElementById('prodFormFeatured').checked = parseInt(d.is_featured) === 1;
        document.getElementById('prodFormWebsiteHidden').checked = parseInt(d.website_hidden) === 1;
        document.getElementById('prodFormQrHidden').checked = parseInt(d.qr_hidden) === 1;

        var previewWrap = document.getElementById('prodImagePreviewWrap');
        var previewImg = document.getElementById('prodImagePreview');
        var previewText = document.getElementById('prodImagePreviewText');
        if (d.image_url) {
          previewImg.src = d.image_url;
          previewText.textContent = 'Mevcut ürün görseli';
          previewWrap.style.display = 'flex';
        } else {
          previewWrap.style.display = 'none';
        }
        openModal('productModal');
      } catch (err) {
        console.error(err);
      }
    });
  });

  // Allergen Chip Seçimleri
  document.querySelectorAll('.admin-allergen-chip').forEach(function(chip) {
    chip.addEventListener('click', function(e) {
      e.preventDefault();
      chip.classList.toggle('active');
      var selected = [];
      document.querySelectorAll('.admin-allergen-chip.active').forEach(function(ac) {
        selected.push(ac.dataset.allergen);
      });
      document.getElementById('prodFormAllergens').value = selected.join(',');
    });
  });

  var btnClearAllergens = document.getElementById('btnClearAllergens');
  if (btnClearAllergens) {
    btnClearAllergens.addEventListener('click', function(e) {
      e.preventDefault();
      document.querySelectorAll('.admin-allergen-chip').forEach(function(c) { c.classList.remove('active'); });
      document.getElementById('prodFormAllergens').value = '';
    });
  }

  document.querySelectorAll('.delete-prod-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var id = btn.dataset.id;
      var name = btn.dataset.name;
      document.getElementById('deleteModalTitle').textContent = 'Ürünü Sil';
      document.getElementById('deleteModalAction').value = 'product_delete';
      document.getElementById('deleteModalId').value = id;
      document.getElementById('deleteModalMessage').textContent = '"' + name + '" adlı ürünü silmek istediğinize emin misiniz? Bu işlem geri alınamaz.';
      openModal('deleteModal');
    });
  });

  var fileInput = document.getElementById('prodFormFile');
  if (fileInput) {
    fileInput.addEventListener('change', function() {
      if (fileInput.files && fileInput.files[0]) {
        var file = fileInput.files[0];
        var previewWrap = document.getElementById('prodImagePreviewWrap');
        var previewImg = document.getElementById('prodImagePreview');
        var previewText = document.getElementById('prodImagePreviewText');
        previewImg.src = URL.createObjectURL(file);
        previewText.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
        previewWrap.style.display = 'flex';
      }
    });
  }

  var btnAddCat = document.getElementById('btnAddCat');
  if (btnAddCat) {
    btnAddCat.addEventListener('click', function(e) {
      e.stopPropagation();
      document.getElementById('categoryModalTitle').textContent = 'Yeni Kategori Ekle';
      document.getElementById('catFormId').value = '0';
      document.getElementById('catFormName').value = '';
      document.getElementById('catFormDesc').value = '';
      document.getElementById('catFormOrder').value = catRows.length;
      document.getElementById('catFormActive').checked = true;
      openModal('categoryModal');
    });
  }

  document.querySelectorAll('.edit-cat-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var row = btn.closest('.cat-item-row');
      if (!row) return;
      document.getElementById('categoryModalTitle').textContent = 'Kategoriyi Düzenle: ' + row.dataset.catName;
      document.getElementById('catFormId').value = row.dataset.catId;
      document.getElementById('catFormName').value = row.dataset.catName || '';
      document.getElementById('catFormDesc').value = row.dataset.catDesc || '';
      document.getElementById('catFormOrder').value = row.dataset.catOrder || 0;
      document.getElementById('catFormActive').checked = parseInt(row.dataset.catActive) === 1;
      openModal('categoryModal');
    });
  });

  document.querySelectorAll('.delete-cat-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var row = btn.closest('.cat-item-row');
      if (!row) return;
      document.getElementById('deleteModalTitle').textContent = 'Kategoriyi Sil';
      document.getElementById('deleteModalAction').value = 'category_delete';
      document.getElementById('deleteModalId').value = row.dataset.catId;
      document.getElementById('deleteModalMessage').textContent = '"' + row.dataset.catName + '" adlı kategoriyi silmek istediğinize emin misiniz?';
      openModal('deleteModal');
    });
  });

  var urlParams = new URLSearchParams(window.location.search);
  var editProdId = urlParams.get('edit_product');
  if (editProdId) {
    var targetRow = document.querySelector('.prod-row[data-id="' + editProdId + '"]');
    if (targetRow) {
      var editBtn = targetRow.querySelector('.edit-prod-btn');
      if (editBtn) editBtn.click();
    }
  }
})();
</script>
<?php elseif ($view === 'translations'):
  $catStats = array('total' => count($categories), 'translated' => 0, 'missing' => 0);
  foreach ($categories as $c) {
      $t = isset($categoryTranslations[$c['id']]) ? $categoryTranslations[$c['id']] : null;
      if ($t && trim($t['name_en']) !== '') {
          $catStats['translated']++;
      } else {
          $catStats['missing']++;
      }
  }

  $prodStats = array('total' => count($products), 'translated' => 0, 'missing' => 0);
  foreach ($products as $p) {
      $t = isset($productTranslations[$p['id']]) ? $productTranslations[$p['id']] : null;
      if ($t && trim($t['name_en']) !== '') {
          $prodStats['translated']++;
      } else {
          $prodStats['missing']++;
      }
  }

  $totalAll = $catStats['total'] + $prodStats['total'];
  $totalTranslated = $catStats['translated'] + $prodStats['translated'];
  $totalMissing = $catStats['missing'] + $prodStats['missing'];
?>
<div class="trans-panel">
  <div class="trans-header-card">
    <h2 class="trans-header-title">İngilizce Çeviriler</h2>
    <p class="trans-header-sub">Menü öğelerinin İngilizce karşılıklarını buradan düzenleyebilirsiniz. Boş bırakılan alanlarda menüde otomatik olarak Türkçe içerik kullanılır.</p>
  </div>

  <div class="trans-stats-bar">
    <div class="trans-stat-card">
      <span class="trans-stat-label">Toplam Öğe</span>
      <span class="trans-stat-value"><?= $totalAll ?></span>
      <span class="trans-stat-hint"><?= $catStats['total'] ?> kategori, <?= $prodStats['total'] ?> ürün</span>
    </div>
    <div class="trans-stat-card <?= $totalMissing > 0 ? 'highlight-warning' : '' ?>">
      <span class="trans-stat-label">İngilizce Çevirisi Eksik</span>
      <div class="trans-stat-val-row">
        <?php if ($totalMissing > 0): ?>
          <svg class="warning-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        <?php endif; ?>
        <span class="trans-stat-value"><?= $totalMissing ?></span>
      </div>
      <span class="trans-stat-hint"><?= $catStats['missing'] ?> kategori, <?= $prodStats['missing'] ?> ürün çeviri bekliyor</span>
    </div>
    <div class="trans-stat-card">
      <span class="trans-stat-label">Tamamlanan</span>
      <span class="trans-stat-value"><?= $totalTranslated ?></span>
      <span class="trans-stat-hint">%<?= $totalAll > 0 ? round(($totalTranslated / $totalAll) * 100) : 100 ?> tamamlandı</span>
    </div>
  </div>

  <form method="post" id="transForm">
    <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
    <input type="hidden" name="action" value="translations">

    <div class="trans-toolbar">
      <div class="trans-search-wrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="text" id="transSearch" class="trans-search-input" placeholder="Menü öğesi ara (Türkçe veya İngilizce)..." autocomplete="off">
      </div>

      <div class="trans-filters">
        <button type="button" class="trans-filter-btn active" data-filter="all">Tümü (<?= $totalAll ?>)</button>
        <button type="button" class="trans-filter-btn warning-filter" data-filter="missing">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
          Eksikler (<?= $totalMissing ?>)
        </button>
        <button type="button" class="trans-filter-btn" data-filter="category">Kategoriler (<?= $catStats['total'] ?>)</button>
        <button type="button" class="trans-filter-btn" data-filter="product">Ürünler (<?= $prodStats['total'] ?>)</button>
      </div>

      <div class="trans-actions-right">
        <button type="button" class="btn-toggle-all" id="btnToggleAll">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="7 13 12 18 17 13"></polyline>
            <polyline points="7 6 12 11 17 6"></polyline>
          </svg>
          <span id="toggleAllText">Tümünü Aç</span>
        </button>
        <button class="primary btn-save-translations" type="submit">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
            <polyline points="17 21 17 13 7 13 7 21"></polyline>
            <polyline points="7 3 7 8 15 8"></polyline>
          </svg>
          Kaydet
        </button>
      </div>
    </div>

    <!-- Kategoriler Bölümü -->
    <div class="trans-section-block" id="secCategories">
      <div class="trans-section-title-wrap">
        <h3 class="trans-section-title">
          <span>Kategoriler</span>
          <span class="trans-count-badge"><?= $catStats['total'] ?></span>
        </h3>
        <?php if ($catStats['missing'] > 0): ?>
          <span class="trans-warning-badge" style="font-size:10px; padding:2px 7px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/>
              <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <?= $catStats['missing'] ?> kategori eksik
          </span>
        <?php endif; ?>
      </div>

      <div class="trans-accordion-list">
        <?php foreach ($categories as $category):
          $translation = isset($categoryTranslations[$category['id']]) ? $categoryTranslations[$category['id']] : array('name_en'=>'','description_en'=>'');
          $isTranslated = trim($translation['name_en']) !== '';
        ?>
        <details class="trans-item <?= $isTranslated ? 'is-translated' : 'is-missing' ?>" data-type="category" data-status="<?= $isTranslated ? 'translated' : 'missing' ?>" data-search="<?= qr_e(mb_strtolower($category['name'] . ' ' . $translation['name_en'], 'UTF-8')) ?>">
          <summary class="trans-summary">
            <div class="trans-summary-left">
              <?php if (!$isTranslated): ?>
                <span class="trans-warning-badge" title="İngilizce çevirisi eksik">
                  <svg class="warning-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                  </svg>
                  Eksik
                </span>
              <?php else: ?>
                <span class="trans-check-badge" title="İngilizce çevirisi yapıldı">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                  Çevrildi
                </span>
              <?php endif; ?>

              <span class="trans-item-title"><?= qr_e($category['name']) ?></span>
              <span class="trans-type-pill">Kategori</span>

              <?php if ($isTranslated): ?>
                <span class="trans-preview-text">EN: <?= qr_e($translation['name_en']) ?></span>
              <?php endif; ?>
            </div>

            <div class="trans-summary-right">
              <svg class="trans-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </summary>

          <div class="trans-body">
            <div class="trans-body-grid">
              <div class="trans-source-box">
                <span class="trans-box-label">Türkçe (Orijinal)</span>
                <div class="trans-source-title"><?= qr_e($category['name']) ?></div>
                <div class="trans-source-desc"><?= $category['description'] !== '' ? qr_e($category['description']) : '<em>Açıklama girilmemiş.</em>' ?></div>
              </div>

              <div class="trans-inputs-box">
                <label>
                  <span>İngilizce Kategori Adı (English Name)</span>
                  <input name="category_name_en[<?= (int)$category['id'] ?>]" maxlength="100" value="<?= qr_e($translation['name_en']) ?>" placeholder="Örn: Soups" class="trans-input-name">
                </label>
                <label>
                  <span>İngilizce Açıklama (English Description)</span>
                  <textarea name="category_description_en[<?= (int)$category['id'] ?>]" rows="2" maxlength="1000" placeholder="Örn: Traditional soups prepared daily..."><?= qr_e($translation['description_en']) ?></textarea>
                </label>
              </div>
            </div>
          </div>
        </details>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Ürünler Bölümü -->
    <div class="trans-section-block" id="secProducts">
      <div class="trans-section-title-wrap">
        <h3 class="trans-section-title">
          <span>Ürünler</span>
          <span class="trans-count-badge"><?= $prodStats['total'] ?></span>
        </h3>
        <?php if ($prodStats['missing'] > 0): ?>
          <span class="trans-warning-badge" style="font-size:10px; padding:2px 7px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/>
              <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <?= $prodStats['missing'] ?> ürün eksik
          </span>
        <?php endif; ?>
      </div>

      <div class="trans-accordion-list">
        <?php foreach ($products as $product):
          $translation = isset($productTranslations[$product['id']]) ? $productTranslations[$product['id']] : array('name_en'=>'','description_en'=>'');
          $isTranslated = trim($translation['name_en']) !== '';
          $catName = isset($categoryMap[$product['category_id']]) ? $categoryMap[$product['category_id']] : '';
        ?>
        <details class="trans-item <?= $isTranslated ? 'is-translated' : 'is-missing' ?>" data-type="product" data-status="<?= $isTranslated ? 'translated' : 'missing' ?>" data-search="<?= qr_e(mb_strtolower($product['name'] . ' ' . $catName . ' ' . $translation['name_en'], 'UTF-8')) ?>">
          <summary class="trans-summary">
            <div class="trans-summary-left">
              <?php if (!$isTranslated): ?>
                <span class="trans-warning-badge" title="İngilizce çevirisi eksik">
                  <svg class="warning-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                  </svg>
                  Eksik
                </span>
              <?php else: ?>
                <span class="trans-check-badge" title="İngilizce çevirisi yapıldı">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                  Çevrildi
                </span>
              <?php endif; ?>

              <span class="trans-item-title"><?= qr_e($product['name']) ?></span>

              <?php if ($catName): ?>
                <span class="trans-category-pill"><?= qr_e($catName) ?></span>
              <?php endif; ?>

              <?php if ($isTranslated): ?>
                <span class="trans-preview-text">EN: <?= qr_e($translation['name_en']) ?></span>
              <?php endif; ?>
            </div>

            <div class="trans-summary-right">
              <svg class="trans-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </summary>

          <div class="trans-body">
            <div class="trans-body-grid">
              <div class="trans-source-box">
                <span class="trans-box-label">Türkçe (Orijinal)</span>
                <div class="trans-source-title"><?= qr_e($product['name']) ?></div>
                <div class="trans-source-desc"><?= $product['description'] !== '' ? qr_e($product['description']) : '<em>Açıklama girilmemiş.</em>' ?></div>
              </div>

              <div class="trans-inputs-box">
                <label>
                  <span>İngilizce Ürün Adı (English Name)</span>
                  <input name="product_name_en[<?= (int)$product['id'] ?>]" maxlength="150" value="<?= qr_e($translation['name_en']) ?>" placeholder="Örn: Lamb Neck Soup" class="trans-input-name">
                </label>
                <label>
                  <span>İngilizce Açıklama (English Description)</span>
                  <textarea name="product_description_en[<?= (int)$product['id'] ?>]" rows="2" maxlength="3000" placeholder="Örn: Slow cooked with fresh ingredients..."><?= qr_e($translation['description_en']) ?></textarea>
                </label>
              </div>
            </div>
          </div>
        </details>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Arama Sonucu Bulunamadı Uyarısı -->
    <div class="trans-empty-state" id="transEmptyState">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        <line x1="8" y1="11" x2="14" y2="11"></line>
      </svg>
      <p>Arama kriterinize uygun çeviri öğesi bulunamadı.</p>
    </div>

    <!-- Sabit Alt Kaydetme Çubuğu -->
    <div class="trans-sticky-footer">
      <div class="trans-footer-info">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ba8664" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="12" y1="16" x2="12" y2="12"></line>
          <line x1="12" y1="8" x2="12.01" y2="8"></line>
        </svg>
        <span>Boş bırakılan alanlarda ziyaretçilere orijinal Türkçe metin gösterilir. Değişiklikleri kaydetmeyi unutmayın.</span>
      </div>
      <button class="primary btn-save-translations" type="submit">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
          <polyline points="17 21 17 13 7 13 7 21"></polyline>
          <polyline points="7 3 7 8 15 8"></polyline>
        </svg>
        İngilizce Çevirileri Kaydet
      </button>
    </div>
  </form>
</div>

<script>
(function() {
  var items = Array.prototype.slice.call(document.querySelectorAll('.trans-item'));
  var searchInput = document.getElementById('transSearch');
  var filterBtns = Array.prototype.slice.call(document.querySelectorAll('.trans-filter-btn'));
  var btnToggleAll = document.getElementById('btnToggleAll');
  var toggleAllText = document.getElementById('toggleAllText');
  var emptyState = document.getElementById('transEmptyState');
  var secCategories = document.getElementById('secCategories');
  var secProducts = document.getElementById('secProducts');

  var currentFilter = 'all';
  var searchQuery = '';

  function applyFilters() {
    var visibleCount = 0;
    var catVisibleCount = 0;
    var prodVisibleCount = 0;

    items.forEach(function(item) {
      var itemType = item.getAttribute('data-type');
      var itemStatus = item.getAttribute('data-status');
      var itemSearch = item.getAttribute('data-search') || '';

      var matchesType = true;
      if (currentFilter === 'missing') {
        matchesType = (itemStatus === 'missing');
      } else if (currentFilter === 'category') {
        matchesType = (itemType === 'category');
      } else if (currentFilter === 'product') {
        matchesType = (itemType === 'product');
      }

      var matchesSearch = true;
      if (searchQuery) {
        matchesSearch = (itemSearch.indexOf(searchQuery) !== -1);
      }

      var isVisible = matchesType && matchesSearch;
      item.style.display = isVisible ? '' : 'none';

      if (isVisible) {
        visibleCount++;
        if (itemType === 'category') catVisibleCount++;
        if (itemType === 'product') prodVisibleCount++;
      }
    });

    if (secCategories) secCategories.style.display = (catVisibleCount > 0) ? '' : 'none';
    if (secProducts) secProducts.style.display = (prodVisibleCount > 0) ? '' : 'none';
    if (emptyState) emptyState.style.display = (visibleCount === 0) ? 'block' : 'none';

    updateToggleAllText();
  }

  // Arama dinleyicisi
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      searchQuery = this.value.trim().toLowerCase();
      applyFilters();
    });
  }

  // Filtre butonları
  filterBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      filterBtns.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      currentFilter = this.getAttribute('data-filter');
      applyFilters();
    });
  });

  // Tümünü Aç / Kapat
  function updateToggleAllText() {
    var visibleItems = items.filter(function(item) {
      return item.style.display !== 'none';
    });
    if (visibleItems.length === 0) return;
    var anyClosed = visibleItems.some(function(item) { return !item.open; });
    toggleAllText.textContent = anyClosed ? 'Tümünü Aç' : 'Tümünü Kapat';
  }

  if (btnToggleAll) {
    btnToggleAll.addEventListener('click', function() {
      var visibleItems = items.filter(function(item) {
        return item.style.display !== 'none';
      });
      var anyClosed = visibleItems.some(function(item) { return !item.open; });
      visibleItems.forEach(function(item) {
        item.open = anyClosed;
      });
      updateToggleAllText();
    });
  }

  items.forEach(function(item) {
    item.addEventListener('toggle', function() {
      updateToggleAllText();
    });
  });

  updateToggleAllText();
})();
</script>
<?php elseif ($view === 'feedback'): ?>
<div class="stats feedback-stats">
  <div class="stat-card-rating">
    <small>ORTALAMA PUAN</small>
    <div class="stat-rating-value">
      <strong><?= $feedbackStats['total'] > 0 ? number_format($feedbackStats['avg_rating'], 1, '.', '') : '—' ?></strong>
      <span class="stat-stars" aria-label="<?= $feedbackStats['total'] > 0 ? $feedbackStats['avg_rating'] . ' / 5' : '' ?>" title="<?= $feedbackStats['total'] > 0 ? $feedbackStats['avg_rating'] . ' / 5.0' : '' ?>">
        <?php 
          $rounded = (int)round($feedbackStats['avg_rating']);
          echo $feedbackStats['total'] > 0 ? str_repeat('★', $rounded) . str_repeat('☆', 5 - $rounded) : '☆☆☆☆☆';
        ?>
      </span>
    </div>
    <span><?= $feedbackStats['total'] > 0 ? '5.0 üzerinden puan ortalaması' : 'Henüz değerlendirme bulunmuyor' ?></span>
  </div>

  <div>
    <small>TOPLAM DEĞERLENDİRME</small>
    <strong><?= $feedbackStats['total'] ?></strong>
    <span>Kayıtlı müşteri değerlendirmesi</span>
  </div>

  <div>
    <small>YENİ / OKUNMAMIŞ</small>
    <strong class="<?= $unreadFeedback > 0 ? 'stat-highlight' : '' ?>"><?= $unreadFeedback ?></strong>
    <span><?= $unreadFeedback > 0 ? $unreadFeedback . ' yeni inceleme bekliyor' : 'Tüm bildirimler okundu' ?></span>
  </div>
</div>

<div class="panel">
  <div class="panel-head feedback-panel-head">
    <div>
      <h2>Müşteri geri bildirimleri</h2>
      <p>QR menüden gelen son 100 değerlendirme · <?= $unreadFeedback ?> okunmamış</p>
    </div>
    <?php if ($feedbackItems): ?>
      <form method="post" onsubmit="return confirm('Tüm müşteri geri bildirimlerini silmek istediğinizden emin misiniz? Bu işlem geri alınamaz!');">
        <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
        <input type="hidden" name="action" value="feedback_delete_all">
        <button class="btn-clear-all-feedback" type="submit">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
          Tümünü Sil
        </button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!$feedbackItems): ?>
    <div class="feedback-empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ba8664" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      <p>Henüz geri bildirim bulunmuyor.</p>
    </div>
  <?php else: ?>
    <div class="feedback-list">
      <?php foreach ($feedbackItems as $item): ?>
        <article class="feedback-item <?= $item['is_read'] ? '' : 'unread' ?>">
          <div class="feedback-header">
            <div class="feedback-meta">
              <strong aria-label="<?= (int)$item['rating'] ?> / 5 yıldız"><?= str_repeat('★', (int)$item['rating']) ?><span><?= str_repeat('☆', 5 - (int)$item['rating']) ?></span></strong>
              <time datetime="<?= qr_e($item['created_at']) ?>"><?= qr_e(date('d.m.Y H:i', strtotime($item['created_at']))) ?></time>
              <?php if (!$item['is_read']): ?><span class="badge positive">Yeni</span><?php endif; ?>
            </div>
            <form method="post" onsubmit="return confirm('Bu geri bildirimi silmek istediğinizden emin misiniz?');">
              <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
              <input type="hidden" name="action" value="feedback_delete">
              <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
              <button class="feedback-delete-btn" type="submit" title="Geri bildirimi sil">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Sil
              </button>
            </form>
          </div>
          <p><?= $item['comment'] !== '' ? nl2br(qr_e($item['comment'])) : '<em>Yorum bırakılmadı.</em>' ?></p>
          <?php if (!$item['is_read']): ?>
            <div class="feedback-footer">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
                <input type="hidden" name="action" value="feedback_read">
                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                <button class="text-button" type="submit">Okundu olarak işaretle</button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php elseif ($view === 'settings'): ?>
<div class="panel form-panel settings-panel">
  <div class="panel-head">
    <div>
      <h2>QR menü ayarları</h2>
      <p>Başlık, fiyat, alerjen filtresi ve garson çağırma ayarları yalnızca QR menüyü etkiler.</p>
    </div>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
    <input type="hidden" name="action" value="settings">
    <div class="form-row">
      <label>Menü başlığı (Türkçe)
        <input name="headline" maxlength="70" value="<?= qr_e($settings['headline']) ?>" placeholder="Menümüz" required>
      </label>
      <label>Menü başlığı (İngilizce)
        <input name="headline_en" maxlength="70" value="<?= qr_e(isset($settings['headline_en']) && $settings['headline_en'] !== '' ? $settings['headline_en'] : 'Our Menu') ?>" placeholder="Our Menu">
      </label>
    </div>
    <label class="check"><input type="checkbox" name="show_prices" <?= $settings['show_prices'] === '1' ? 'checked' : '' ?>> QR menüde fiyatları göster</label>
    <label class="check"><input type="checkbox" name="allergen_filter_enabled" <?= (!isset($settings['allergen_filter_enabled']) || $settings['allergen_filter_enabled'] === '1') ? 'checked' : '' ?>> Alerjen filtresini menüde göster (Arama yanındaki filtre butonu)</label>
    <label class="check"><input type="checkbox" name="waiter_call_enabled" <?= $settings['waiter_call_enabled'] === '1' ? 'checked' : '' ?>> Garson çağırmayı aç</label>

    <div style="margin:24px 0 16px;padding-top:20px;border-top:1px solid #dce4dc;">
      <h3 style="margin:0 0 6px;font-size:16px;color:#17352a;">📍 Konum Doğrulaması (150m Geofence)</h3>
      <p style="margin:0 0 14px;font-size:13px;color:#526659;">Müşterilerin evden veya restoran dışından sahte çağrı yapmasını önler. Garson çağır butonuna tıklandığında cihazın konumu doğrulanır; restorana belirlenen mesafeden uzaktaysa çağrı engellenir.</p>
      
      <label class="check" style="margin-bottom:14px;font-weight:600;">
        <input type="checkbox" name="location_check_enabled" <?= (!isset($settings['location_check_enabled']) || $settings['location_check_enabled'] === '1') ? 'checked' : '' ?>> 150m Konum doğrulamasını aktif et
      </label>
      <div class="form-row">
        <label>Maksimum Mesafe Sınırı (Metre)
          <input type="number" name="location_max_distance" min="10" max="5000" step="5" value="<?= qr_e(isset($settings['location_max_distance']) ? $settings['location_max_distance'] : '150') ?>" required>
          <small style="color:#666;font-size:11px;">Varsayılan: 150m (Restoran içi ve bahçeyi kapsar)</small>
        </label>
        <label>Restoran Enlem (Latitude)
          <input type="text" name="restaurant_lat" value="<?= qr_e(isset($settings['restaurant_lat']) ? $settings['restaurant_lat'] : '40.9252987') ?>" required>
          <small style="color:#666;font-size:11px;">Laze Köfte: 40.9252987</small>
        </label>
        <label>Restoran Boylam (Longitude)
          <input type="text" name="restaurant_lng" value="<?= qr_e(isset($settings['restaurant_lng']) ? $settings['restaurant_lng'] : '29.3113258') ?>" required>
          <small style="color:#666;font-size:11px;">Laze Köfte: 29.3113258</small>
        </label>
      </div>
    </div>

    <div style="margin:24px 0 16px;padding-top:20px;border-top:1px solid #dce4dc;">
      <h3 style="margin:0 0 6px;font-size:16px;color:#17352a;">OneSignal Kilit Ekranı Bildirimleri</h3>
      <p style="margin:0 0 14px;font-size:13px;color:#526659;">Garson telefonu kapalıyken veya tarayıcı arka plandayken kilit ekranına sesli ve titreşimli çağrı bildirimi gönderir.</p>
      
      <label class="check" style="margin-bottom:14px;">
        <input type="checkbox" name="onesignal_enabled" <?= (!isset($settings['onesignal_enabled']) || $settings['onesignal_enabled'] === '1') ? 'checked' : '' ?>> OneSignal kilit ekranı bildirimlerini aktif et
      </label>
      <div class="form-row">
        <label>OneSignal App ID
          <input name="onesignal_app_id" value="<?= qr_e(isset($settings['onesignal_app_id']) ? $settings['onesignal_app_id'] : '') ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
        </label>
        <label>OneSignal REST API Key
          <input type="password" name="onesignal_rest_key" value="<?= qr_e(isset($settings['onesignal_rest_key']) ? $settings['onesignal_rest_key'] : '') ?>" placeholder="os_v2_app_...">
        </label>
      </div>
    </div>

    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <button class="primary" type="submit">Ayarları kaydet →</button>
    </div>
  </form>

  <?php if (!empty($settings['onesignal_app_id']) && !empty($settings['onesignal_rest_key'])): ?>
    <div style="margin-top:20px;padding-top:16px;border-top:1px dashed #dce4dc;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div>
        <strong style="display:block;font-size:13px;color:#17352a;">OneSignal Test Bildirimi</strong>
        <span style="font-size:12px;color:#526659;">Tüm abone personele anında kilit ekranı test bildirimi gönderin.</span>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
        <input type="hidden" name="action" value="test_onesignal">
        <button class="secondary link-button" type="submit" style="cursor:pointer;padding:8px 16px;font-size:12px;">Test Bildirimi Gönder 🔔</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</main></div></body></html>
