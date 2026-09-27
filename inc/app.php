<?php
/** LAZE QR menu bootstrap. PHP 7.4+ */
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Istanbul');

// QR menü yalnızca masa QR kodu ile ziyaret edilir; arama motoru sonuçlarında yer almaz.
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('LAZE_QR');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
    session_start();
}

define('QR_ROOT', dirname(__DIR__) . DIRECTORY_SEPARATOR);
$script = str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/menu2/index.php');
$scriptDir = dirname($script);
if (substr($scriptDir, -6) === '/admin' || substr($scriptDir, -6) === '/staff') $scriptDir = dirname($scriptDir);
define('QR_BASE', rtrim($scriptDir, '/') . '/');
define('QR_DB_FILE', __DIR__ . '/database.php');
$isLocal = in_array(strtolower(isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : ''), array('localhost', '127.0.0.1'), true) || PHP_SAPI === 'cli';
define('QR_LOCAL', $isLocal);

function qr_e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function qr_redirect($path) { header('Location: ' . QR_BASE . ltrim($path, '/')); exit; }
function qr_csrf() {
    if (empty($_SESSION['qr_csrf'])) $_SESSION['qr_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['qr_csrf'];
}
function qr_check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf']) || !is_string($_POST['csrf']) ||
        !hash_equals(qr_csrf(), $_POST['csrf'])) {
        http_response_code(403);
        exit('Güvenlik doğrulaması başarısız.');
    }
}
function qr_flash($message, $type = 'success') { $_SESSION['qr_flash'] = array($message, $type); }
function qr_take_flash() {
    $flash = isset($_SESSION['qr_flash']) ? $_SESSION['qr_flash'] : null;
    unset($_SESSION['qr_flash']);
    return $flash;
}

function qr_db_config() {
    if (is_file(QR_DB_FILE)) return require QR_DB_FILE;
    $parent = dirname(QR_ROOT) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'site-config.php';
    if (QR_LOCAL && is_file($parent)) return require $parent;
    if (QR_LOCAL) return array('host' => '127.0.0.1', 'port' => 3306, 'name' => 'lazekofte', 'user' => 'root', 'password' => '');
    return null;
}

function qr_connect($config) {
    $dsn = 'mysql:host=' . $config['host'] . ';port=' . (int)$config['port'] . ';dbname=' . $config['name'] . ';charset=utf8mb4';
    return new PDO($dsn, $config['user'], $config['password'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ));
}
function qr_db() {
    static $db = null;
    if ($db !== null) return $db;
    $config = qr_db_config();
    if ($config === null) qr_redirect('install.php');
    $ports = QR_LOCAL && !is_file(QR_DB_FILE) ? array((int)$config['port'], 3307) : array((int)$config['port']);
    foreach (array_unique($ports) as $port) {
        try {
            $config['port'] = $port;
            $db = qr_connect($config);
            $db->query('SELECT 1 FROM `products` LIMIT 1');
            return $db;
        } catch (Exception $e) {
            error_log('QR menu database: ' . $e->getMessage());
        }
    }
    http_response_code(503);
    exit('Menü veritabanına bağlanılamadı. Bağlantı bilgilerini kontrol edin.');
}
function qr_schema($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_menu_settings` (
        `key_name` VARCHAR(60) PRIMARY KEY, `key_value` TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_menu_hidden_products` (
        `product_id` INT PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    qr_ensure_feedback_table($db);
    qr_ensure_translation_schema($db);
    qr_ensure_stock_column($db);
    qr_ensure_discount_price_column($db);
    qr_ensure_product_meta_columns($db);
    qr_ensure_website_hidden_column($db);
}
function qr_ensure_discount_price_column($db) {
    static $ready = false;
    if ($ready) return;
    try {
        $cols = $db->query("SHOW COLUMNS FROM `products` LIKE 'discount_price'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `products` ADD COLUMN `discount_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `price`");
        }
    } catch (Exception $e) {}
    $ready = true;
}
function qr_ensure_website_hidden_column($db) {
    static $ready = false;
    if ($ready) return;
    try {
        $cols = $db->query("SHOW COLUMNS FROM `products` LIKE 'website_hidden'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `products` ADD COLUMN `website_hidden` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_featured`");
        }
    } catch (Exception $e) {}
    $ready = true;
}
function qr_ensure_stock_column($db) {
    static $ready = false;
    if ($ready) return;
    try {
        $cols = $db->query("SHOW COLUMNS FROM `products` LIKE 'stock'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `products` ADD COLUMN `stock` INT NULL DEFAULT NULL AFTER `price`");
        }
    } catch (Exception $e) {}
    $ready = true;
}
function qr_ensure_product_meta_columns($db) {
    static $ready = false;
    if ($ready) return;
    try {
        $cols = $db->query("SHOW COLUMNS FROM `products`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('prep_time', $cols, true)) {
            $db->exec("ALTER TABLE `products` ADD COLUMN `prep_time` VARCHAR(30) NULL DEFAULT NULL AFTER `stock`");
        }
        if (!in_array('calories', $cols, true)) {
            $db->exec("ALTER TABLE `products` ADD COLUMN `calories` VARCHAR(30) NULL DEFAULT NULL AFTER `prep_time`");
        }
        if (!in_array('weight', $cols, true)) {
            $db->exec("ALTER TABLE `products` ADD COLUMN `weight` VARCHAR(30) NULL DEFAULT NULL AFTER `calories`");
        }
        if (!in_array('allergens', $cols, true)) {
            $db->exec("ALTER TABLE `products` ADD COLUMN `allergens` TEXT NULL DEFAULT NULL AFTER `weight`");
        }
    } catch (Exception $e) {
        error_log('QR menu meta columns: ' . $e->getMessage());
    }
    $ready = true;
}
function qr_allergens($lang = 'tr') {
    return array(
        'gluten' => array('id' => 'gluten', 'name' => $lang === 'en' ? 'Gluten' : 'Gluten', 'icon' => '🌾', 'desc' => $lang === 'en' ? 'Cereals containing gluten (wheat, rye, barley, oats)' : 'Gluten içeren tahıllar (buğday, çavdar vb.)'),
        'milk' => array('id' => 'milk', 'name' => $lang === 'en' ? 'Milk & Lactose' : 'Süt ve Laktoz', 'icon' => '🥛', 'desc' => $lang === 'en' ? 'Milk, cheese, butter, lactose' : 'Süt, peynir, tereyağı, laktoz'),
        'eggs' => array('id' => 'eggs', 'name' => $lang === 'en' ? 'Eggs' : 'Yumurta', 'icon' => '🥚', 'desc' => $lang === 'en' ? 'Eggs and egg products' : 'Yumurta ve yumurta ürünleri'),
        'nuts' => array('id' => 'nuts', 'name' => $lang === 'en' ? 'Tree Nuts' : 'Sert Kabuklular', 'icon' => '🌰', 'desc' => $lang === 'en' ? 'Almonds, hazelnuts, walnuts, pistachios' : 'Fındık, ceviz, badem, antep fıstığı'),
        'peanuts' => array('id' => 'peanuts', 'name' => $lang === 'en' ? 'Peanuts' : 'Yer Fıstığı', 'icon' => '🥜', 'desc' => $lang === 'en' ? 'Peanuts and peanut products' : 'Yer fıstığı ve ürünleri'),
        'soy' => array('id' => 'soy', 'name' => $lang === 'en' ? 'Soy' : 'Soya', 'icon' => '🫘', 'desc' => $lang === 'en' ? 'Soybeans and soy products' : 'Soya fasulyesi ve ürünleri'),
        'sesame' => array('id' => 'sesame', 'name' => $lang === 'en' ? 'Sesame' : 'Susam', 'icon' => '⚪', 'desc' => $lang === 'en' ? 'Sesame seeds and tahini' : 'Susam tohumu ve tahin'),
        'celery' => array('id' => 'celery', 'name' => $lang === 'en' ? 'Celery' : 'Kereviz', 'icon' => '🥬', 'desc' => $lang === 'en' ? 'Celery and celery products' : 'Kereviz ve ürünleri'),
        'mustard' => array('id' => 'mustard', 'name' => $lang === 'en' ? 'Mustard' : 'Hardal', 'icon' => '🟡', 'desc' => $lang === 'en' ? 'Mustard and mustard seeds' : 'Hardal ve hardal tohumları'),
        'fish' => array('id' => 'fish', 'name' => $lang === 'en' ? 'Fish' : 'Balık', 'icon' => '🐟', 'desc' => $lang === 'en' ? 'Fish and fish products' : 'Balık ve ürünleri'),
        'crustaceans' => array('id' => 'crustaceans', 'name' => $lang === 'en' ? 'Crustaceans' : 'Kabuklular', 'icon' => '🦐', 'desc' => $lang === 'en' ? 'Shrimp, crab, lobster' : 'Karides, yengeç, ıstakoz vb.'),
        'molluscs' => array('id' => 'molluscs', 'name' => $lang === 'en' ? 'Molluscs' : 'Yumuşakçalar', 'icon' => '🦪', 'desc' => $lang === 'en' ? 'Mussels, squid, octopus' : 'Midye, kalamar, ahtapot vb.'),
        'sulphites' => array('id' => 'sulphites', 'name' => $lang === 'en' ? 'Sulphites' : 'Kükürt Dioksit / Sülfit', 'icon' => '🍷', 'desc' => $lang === 'en' ? 'Sulphur dioxide and sulphites' : 'Kükürt dioksit ve sülfitler'),
        'lupin' => array('id' => 'lupin', 'name' => $lang === 'en' ? 'Lupin' : 'Acı Bakla', 'icon' => '🌸', 'desc' => $lang === 'en' ? 'Lupin and lupin products' : 'Acı bakla (Lupin) ve ürünleri')
    );
}
function qr_ensure_translation_schema($db) {
    static $ready = false;
    if ($ready) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_menu_category_translations` (
        `category_id` INT NOT NULL PRIMARY KEY,
        `name_en` VARCHAR(100) NOT NULL DEFAULT '',
        `description_en` VARCHAR(1000) NOT NULL DEFAULT '',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_menu_product_translations` (
        `product_id` INT NOT NULL PRIMARY KEY,
        `name_en` VARCHAR(150) NOT NULL DEFAULT '',
        `description_en` TEXT NOT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}
function qr_language() {
    if (isset($_GET['lang']) && is_string($_GET['lang']) && in_array($_GET['lang'], array('tr', 'en'), true)) {
        $_SESSION['qr_language'] = $_GET['lang'];
    }
    return isset($_SESSION['qr_language']) && $_SESSION['qr_language'] === 'en' ? 'en' : 'tr';
}
function qr_t($key, $language = null) {
    $language = $language ?: qr_language();
    $strings = array(
        'tr' => array('menu' => 'Menümüz', 'search' => 'Menüde lezzet ara... (örn: Gerdan, Köfte)', 'search_category' => 'Bu kategoride ara...', 'categories' => 'Kategoriler', 'details' => 'İncele', 'back_categories' => 'Tüm kategorilere dön', 'no_results' => 'Aradığınız lezzet bulunamadı', 'feedback' => 'Geri bildirim', 'call' => 'Ara', 'location' => 'Konum', 'review' => 'Görüş', 'experience' => 'Deneyiminiz nasıldı?', 'select_rating' => 'Puanınızı seçin', 'short_note' => 'Kısa notunuz', 'optional' => 'isteğe bağlı', 'send_feedback' => 'Geri bildirimi gönder', 'privacy' => 'İsminiz ve iletişim bilginiz istenmez.', 'back_menu' => 'Menüye dön', 'fresh' => 'Günlük & Taze', 'carefully_prepared' => 'Özenle Hazırlanır', 'traditional_recipe' => 'Geleneksel Tarif', 'allergens' => 'Alerjenler', 'allergen_filter' => 'Alerjen Filtresi', 'allergen_notice' => 'Alerjen Bildirimi (TGK)', 'allergen_subtitle' => 'Türk Gıda Kodeksi 14 zorunlu alerjen', 'allergen_safe' => 'Seçilen alerjenleri içermeyenleri göster', 'allergen_clear' => 'Filtreyi Temizle', 'allergen_apply' => 'Filtrele', 'prep_time' => 'Hazırlanma Süresi', 'calories' => 'Kalori', 'weight' => 'Porsiyon / Gramaj', 'no_allergens' => '14 ana alerjeni içermez'),
        'en' => array('menu' => 'Our Menu', 'search' => 'Search the menu... (e.g. Soup, Meatballs)', 'search_category' => 'Search this category...', 'categories' => 'Categories', 'details' => 'View', 'back_categories' => 'Back to all categories', 'no_results' => 'No matching dish found', 'feedback' => 'Feedback', 'call' => 'Call', 'location' => 'Location', 'review' => 'Review', 'experience' => 'How was your experience?', 'select_rating' => 'Choose your rating', 'short_note' => 'A short note', 'optional' => 'optional', 'send_feedback' => 'Send feedback', 'privacy' => 'We do not ask for your name or contact details.', 'back_menu' => 'Back to menu', 'fresh' => 'Fresh daily', 'carefully_prepared' => 'Prepared with care', 'traditional_recipe' => 'Traditional recipe', 'allergens' => 'Allergens', 'allergen_filter' => 'Allergen Filter', 'allergen_notice' => 'Allergen Notice (Codex)', 'allergen_subtitle' => '14 mandatory allergens under food regulations', 'allergen_safe' => 'Show dishes without selected allergens', 'allergen_clear' => 'Clear Filter', 'allergen_apply' => 'Apply Filter', 'prep_time' => 'Prep Time', 'calories' => 'Calories', 'weight' => 'Portion / Weight', 'no_allergens' => 'Free of 14 main allergens')
    );
    return isset($strings[$language][$key]) ? $strings[$language][$key] : $key;
}
function qr_ensure_feedback_table($db) {
    static $ready = false;
    if ($ready) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_menu_feedback` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `rating` TINYINT UNSIGNED NOT NULL,
        `comment` VARCHAR(1000) NOT NULL DEFAULT '',
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `feedback_created` (`created_at`),
        KEY `feedback_unread` (`is_read`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}
function qr_ensure_service_schema($db) {
    static $ready = false;
    if ($ready) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_tables` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `label` VARCHAR(60) NOT NULL,
        `section` VARCHAR(60) NOT NULL DEFAULT 'Salon',
        `token` CHAR(32) NOT NULL UNIQUE,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $secCol = $db->query("SHOW COLUMNS FROM `qr_tables` LIKE 'section'")->fetch();
    if (!$secCol) $db->exec("ALTER TABLE `qr_tables` ADD COLUMN `section` VARCHAR(60) NOT NULL DEFAULT 'Salon' AFTER `label`");
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_waiter_calls` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `table_id` INT UNSIGNED NOT NULL,
        `status` VARCHAR(12) NOT NULL DEFAULT 'new',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `acknowledged_at` DATETIME NULL,
        `acknowledged_by` VARCHAR(80) NULL,
        `resolved_at` DATETIME NULL,
        KEY `calls_status_id` (`status`, `id`),
        KEY `calls_table_status` (`table_id`, `status`),
        CONSTRAINT `qr_calls_table_fk` FOREIGN KEY (`table_id`) REFERENCES `qr_tables` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $column = $db->query("SHOW COLUMNS FROM `qr_waiter_calls` LIKE 'acknowledged_by'")->fetch();
    if (!$column) $db->exec('ALTER TABLE `qr_waiter_calls` ADD COLUMN `acknowledged_by` VARCHAR(80) NULL AFTER `acknowledged_at`');
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_service_users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `display_name` VARCHAR(80) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(12) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}
function qr_service_identity($db) {
    if (!empty($_SESSION['qr_admin_id'])) return array('id' => (int)$_SESSION['qr_admin_id'], 'display_name' => isset($_SESSION['qr_admin_name']) ? $_SESSION['qr_admin_name'] : 'Yönetici', 'role' => 'admin');
    if (empty($_SESSION['qr_service_user_id'])) return null;
    qr_ensure_service_schema($db);
    $stmt = $db->prepare('SELECT `id`, `display_name`, `role`, `password` FROM `qr_service_users` WHERE `id` = ? AND `is_active` = 1 LIMIT 1');
    $stmt->execute(array((int)$_SESSION['qr_service_user_id']));
    $user = $stmt->fetch();
    if (!$user || !in_array($user['role'], array('cashier','waiter'), true) || empty($_SESSION['qr_service_password_hash']) || !hash_equals($user['password'], $_SESSION['qr_service_password_hash'])) { unset($_SESSION['qr_service_user_id'], $_SESSION['qr_service_password_hash']); return null; }
    unset($user['password']);
    return $user;
}
function qr_table_from_token($db, $token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) return null;
    $stmt = $db->prepare('SELECT `id`, `label`, `token` FROM `qr_tables` WHERE `token` = ? AND `is_active` = 1 LIMIT 1');
    $stmt->execute(array($token));
    return $stmt->fetch() ?: null;
}
function qr_settings($db) {
    $settings = array('headline' => 'Menümüz', 'headline_en' => 'Our Menu', 'show_prices' => '1', 'waiter_call_enabled' => '1', 'allergen_filter_enabled' => '1');
    foreach ($db->query('SELECT `key_name`, `key_value` FROM `qr_menu_settings`') as $row) $settings[$row['key_name']] = $row['key_value'];
    return $settings;
}
function qr_image_url($path, $large = false) {
    if (!is_string($path) || $path === '') return QR_BASE . 'assets/placeholder.webp';
    if (preg_match('#^https://menu\.lazekofte\.com/(uploads/products/[a-zA-Z0-9_/-]+)\.webp$#i', $path, $match)) {
        $variant = $match[1] . ($large ? '.webp' : '-card.webp');
        if (is_file(QR_ROOT . str_replace('/', DIRECTORY_SEPARATOR, $variant))) {
            return QR_LOCAL ? QR_BASE . $variant : 'https://menu.lazekofte.com/' . $variant;
        }
    }
    if (preg_match('#^uploads/products/[a-zA-Z0-9_/-]+\.(?:png|jpe?g|webp)$#i', $path) && strpos($path, '..') === false) {
        $base = preg_replace('/\.[^.]+$/', '', $path);
        $variant = $base . ($large ? '.webp' : '-card.webp');
        if (is_file(QR_ROOT . str_replace('/', DIRECTORY_SEPARATOR, $variant))) return QR_BASE . $variant;
        $parentFile = dirname(QR_ROOT) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $variant);
        if (is_file($parentFile)) return QR_LOCAL ? dirname(QR_BASE) . '/' . $variant : 'https://lazekofte.com/' . $variant;
    }
    if (preg_match('#^assets/([a-zA-Z0-9_/-]+)\.(?:png|jpe?g|webp)$#i', $path, $match)) {
        $variant = 'assets/' . $match[1] . ($large ? '.webp' : '-card.webp');
        if (is_file(QR_ROOT . str_replace('/', DIRECTORY_SEPARATOR, $variant))) return QR_BASE . $variant;
        $parentFile = dirname(QR_ROOT) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $variant);
        if (is_file($parentFile)) return QR_LOCAL ? dirname(QR_BASE) . '/' . $variant : 'https://lazekofte.com/' . $variant;
    }
    if (preg_match('#^https://menu\.lazekofte\.com/uploads/#i', $path) && QR_LOCAL) {
        return QR_BASE . substr($path, strlen('https://menu.lazekofte.com/'));
    }
    if (preg_match('#^https?://#i', $path)) {
        return filter_var($path, FILTER_VALIDATE_URL) && !preg_match('/[\x00-\x20"\'<>]/', $path) ? $path : QR_BASE . 'assets/placeholder.webp';
    }
    if (!preg_match('#^(?:uploads|assets)/[a-zA-Z0-9_./-]+$#D', $path) || strpos($path, '..') !== false) return QR_BASE . 'assets/placeholder.webp';
    if (QR_LOCAL) return dirname(QR_BASE) . '/' . $path;
    return 'https://lazekofte.com/' . $path;
}
function qr_slug($value) {
    $value = str_replace(array('ı','İ','ğ','Ğ','ü','Ü','ş','Ş','ö','Ö','ç','Ç'), array('i','i','g','g','u','u','s','s','o','o','c','c'), trim($value));
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
    return trim($value, '-') ?: 'urun-' . bin2hex(random_bytes(3));
}
function qr_admin_required() {
    if (empty($_SESSION['qr_admin_id'])) qr_redirect('admin/index.php');
}

function qr_store_image($file) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 12 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Görsel yüklenemedi veya dosya boyutu sınırı aşıyor.');
    }
    if (!function_exists('imagewebp')) throw new RuntimeException('Sunucuda WebP desteği bulunamadı.');
    $info = @getimagesize($file['tmp_name']);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 25000000) throw new RuntimeException('Geçersiz veya çok büyük görsel.');
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $source = @imagecreatefromjpeg($file['tmp_name']); break;
        case IMAGETYPE_PNG: $source = @imagecreatefrompng($file['tmp_name']); break;
        case IMAGETYPE_WEBP: $source = @imagecreatefromwebp($file['tmp_name']); break;
        default: throw new RuntimeException('Yalnızca JPG, PNG ve WebP yüklenebilir.');
    }
    if (!$source) throw new RuntimeException('Görsel açılamadı.');

    // Telefon çekimleri için EXIF yön düzeltmesi
    if (function_exists('exif_read_data') && ($info[2] === IMAGETYPE_JPEG || $info[2] === IMAGETYPE_WEBP)) {
        $exif = @exif_read_data($file['tmp_name']);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3: $source = imagerotate($source, 180, 0); break;
                case 6: $source = imagerotate($source, -90, 0); break;
                case 8: $source = imagerotate($source, 90, 0); break;
            }
        }
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);

    $directory = QR_ROOT . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR;
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) { imagedestroy($source); throw new RuntimeException('Görsel klasörü oluşturulamadı.'); }

    $parentDir = dirname(QR_ROOT) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR;
    if (!is_dir($parentDir)) @mkdir($parentDir, 0755, true);

    $name = 'qr_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5));
    foreach (array(array(1200, 80, '.webp'), array(480, 75, '-card.webp'), array(240, 70, '-thumb.webp')) as $variant) {
        $width = min($srcW, $variant[0]);
        $height = max(1, (int)round($srcH * $width / $srcW));
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $srcW, $srcH);
        
        $destFile = $directory . $name . $variant[2];
        if (!imagewebp($image, $destFile, $variant[1])) { imagedestroy($image); imagedestroy($source); throw new RuntimeException('Görsel kaydedilemedi.'); }
        imagedestroy($image);

        if (is_dir($parentDir)) {
            @copy($destFile, $parentDir . $name . $variant[2]);
        }
    }
    imagedestroy($source);
    return 'uploads/products/' . $name . '.webp';
}
