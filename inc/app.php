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
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if ($isHttps) ini_set('session.cookie_secure', '1');

    // 1-year long-lived session lifetime (31,536,000 seconds) so sessions never expire prematurely
    $sessionLifetime = 31536000;
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => $sessionLifetime,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ));
    } else {
        session_set_cookie_params($sessionLifetime, '/', '', $isHttps, true);
    }
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
function qr_ensure_allergens_schema($db) {
    static $ready = false;
    if ($ready) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_allergens` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(50) NOT NULL UNIQUE,
        `name_tr` VARCHAR(100) NOT NULL,
        `name_en` VARCHAR(100) NOT NULL DEFAULT '',
        `icon` VARCHAR(20) NOT NULL DEFAULT '🛡️',
        `desc_tr` VARCHAR(255) NOT NULL DEFAULT '',
        `desc_en` VARCHAR(255) NOT NULL DEFAULT '',
        `is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $count = (int)$db->query("SELECT COUNT(*) FROM `qr_allergens`")->fetchColumn();
    if ($count === 0) {
        qr_restore_default_allergens($db);
    }
    $ready = true;
}

function qr_restore_default_allergens($db) {
    $defaults = array(
        array('gluten', 'Gluten', 'Gluten', '🌾', 'Gluten içeren tahıllar (buğday, çavdar vb.)', 'Cereals containing gluten (wheat, rye, barley, oats)', 1, 10),
        array('milk', 'Süt ve Laktoz', 'Milk & Lactose', '🥛', 'Süt, peynir, tereyağı, laktoz', 'Milk, cheese, butter, lactose', 1, 20),
        array('eggs', 'Yumurta', 'Eggs', '🥚', 'Yumurta ve yumurta ürünleri', 'Eggs and egg products', 1, 30),
        array('nuts', 'Sert Kabuklular', 'Tree Nuts', '🌰', 'Fındık, ceviz, badem, antep fıstığı', 'Almonds, hazelnuts, walnuts, pistachios', 1, 40),
        array('peanuts', 'Yer Fıstığı', 'Peanuts', '🥜', 'Yer fıstığı ve ürünleri', 'Peanuts and peanut products', 1, 50),
        array('soy', 'Soya', 'Soy', '🫘', 'Soya fasulyesi ve ürünleri', 'Soybeans and soy products', 1, 60),
        array('sesame', 'Susam', 'Sesame', '⚪', 'Susam tohumu ve tahin', 'Sesame seeds and tahini', 1, 70),
        array('celery', 'Kereviz', 'Celery', '🥬', 'Kereviz ve ürünleri', 'Celery and celery products', 1, 80),
        array('mustard', 'Hardal', 'Mustard', '🟡', 'Hardal ve hardal tohumları', 'Mustard and mustard seeds', 1, 90),
        array('fish', 'Balık', 'Fish', '🐟', 'Balık ve ürünleri', 'Fish and fish products', 1, 100),
        array('crustaceans', 'Kabuklular', 'Crustaceans', '🦐', 'Karides, yengeç, ıstakoz vb.', 'Shrimp, crab, lobster', 1, 110),
        array('molluscs', 'Yumuşakçalar', 'Molluscs', '🦪', 'Midye, kalamar, ahtapot vb.', 'Mussels, squid, octopus', 1, 120),
        array('sulphites', 'Kükürt Dioksit / Sülfit', 'Sulphites', '🍷', 'Kükürt dioksit ve sülfitler', 'Sulphur dioxide and sulphites', 1, 130),
        array('lupin', 'Acı Bakla', 'Lupin', '🌸', 'Acı bakla (Lupin) ve ürünleri', 'Lupin and lupin products', 1, 140)
    );
    $stmt = $db->prepare("INSERT INTO `qr_allergens` (`code`, `name_tr`, `name_en`, `icon`, `desc_tr`, `desc_en`, `is_default`, `sort_order`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE `name_tr`=VALUES(`name_tr`), `name_en`=VALUES(`name_en`), `icon`=VALUES(`icon`), `desc_tr`=VALUES(`desc_tr`), `desc_en`=VALUES(`desc_en`)");
    foreach ($defaults as $d) {
        $stmt->execute($d);
    }
}

function qr_allergens($lang = 'tr') {
    static $cache = array();
    if (isset($cache[$lang])) return $cache[$lang];

    try {
        $db = qr_db();
        qr_ensure_allergens_schema($db);
        $rows = $db->query("SELECT `code`, `name_tr`, `name_en`, `icon`, `desc_tr`, `desc_en` FROM `qr_allergens` ORDER BY `sort_order` ASC, `id` ASC")->fetchAll();
        $list = array();
        foreach ($rows as $row) {
            $code = $row['code'];
            $name = ($lang === 'en' && !empty($row['name_en'])) ? $row['name_en'] : $row['name_tr'];
            $desc = ($lang === 'en' && !empty($row['desc_en'])) ? $row['desc_en'] : $row['desc_tr'];
            $list[$code] = array(
                'id' => $code,
                'name' => $name,
                'icon' => $row['icon'] ?: '🛡️',
                'desc' => $desc
            );
        }
        $cache[$lang] = $list;
        return $list;
    } catch (Exception $e) {
        error_log('qr_allergens error: ' . $e->getMessage());
        return array();
    }
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
        'tr' => array('menu' => 'Menümüz', 'search' => 'Menüde ara...', 'search_category' => 'Bu kategoride ara...', 'categories' => 'Kategoriler', 'details' => 'İncele', 'back_categories' => 'Tüm kategorilere dön', 'no_results' => 'Aradığınız lezzet bulunamadı', 'feedback' => 'Geri bildirim', 'call' => 'Ara', 'location' => 'Konum', 'call_waiter' => 'Garson Çağır', 'review' => 'Görüş', 'experience' => 'Deneyiminiz nasıldı?', 'select_rating' => 'Puanınızı seçin', 'short_note' => 'Kısa notunuz', 'optional' => 'isteğe bağlı', 'send_feedback' => 'Geri bildirimi gönder', 'privacy' => 'İsminiz ve iletişim bilginiz istenmez.', 'back_menu' => 'Menüye dön', 'fresh' => 'Günlük & Taze', 'carefully_prepared' => 'Özenle Hazırlanır', 'traditional_recipe' => 'Geleneksel Tarif', 'allergens' => 'Alerjenler', 'allergen_filter' => 'Alerjen Filtresi', 'allergen_notice' => 'Alerjen Bildirimi (TGK)', 'allergen_subtitle' => 'Türk Gıda Kodeksi 14 zorunlu alerjen', 'allergen_safe' => 'Seçilen alerjenleri içermeyenleri göster', 'allergen_clear' => 'Filtreyi Temizle', 'allergen_apply' => 'Filtrele', 'prep_time' => 'Hazırlanma Süresi', 'calories' => 'Kalori', 'weight' => 'Porsiyon / Gramaj', 'no_allergens' => '14 ana alerjeni içermez'),
        'en' => array('menu' => 'Our Menu', 'search' => 'Search the menu...', 'search_category' => 'Search this category...', 'categories' => 'Categories', 'details' => 'View', 'back_categories' => 'Back to all categories', 'no_results' => 'No matching dish found', 'feedback' => 'Feedback', 'call' => 'Call', 'location' => 'Location', 'call_waiter' => 'Call Waiter', 'review' => 'Review', 'experience' => 'How was your experience?', 'select_rating' => 'Choose your rating', 'short_note' => 'A short note', 'optional' => 'optional', 'send_feedback' => 'Send feedback', 'privacy' => 'We do not ask for your name or contact details.', 'back_menu' => 'Back to menu', 'fresh' => 'Fresh daily', 'carefully_prepared' => 'Prepared with care', 'traditional_recipe' => 'Traditional recipe', 'allergens' => 'Allergens', 'allergen_filter' => 'Allergen Filter', 'allergen_notice' => 'Allergen Notice (Codex)', 'allergen_subtitle' => '14 mandatory allergens under food regulations', 'allergen_safe' => 'Show dishes without selected allergens', 'allergen_clear' => 'Clear Filter', 'allergen_apply' => 'Apply Filter', 'prep_time' => 'Prep Time', 'calories' => 'Calories', 'weight' => 'Portion / Weight', 'no_allergens' => 'Free of 14 main allergens')
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
    $colReminded = $db->query("SHOW COLUMNS FROM `qr_waiter_calls` LIKE 'reminded_3m_at'")->fetch();
    if (!$colReminded) $db->exec("ALTER TABLE `qr_waiter_calls` ADD COLUMN `reminded_3m_at` DATETIME NULL AFTER `acknowledged_by`");
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_service_users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `display_name` VARCHAR(80) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(12) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `qr_service_tokens` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `token_hash` CHAR(64) NOT NULL UNIQUE,
        `expires_at` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_service_token_lookup` (`token_hash`, `expires_at`),
        KEY `idx_service_token_user` (`user_id`),
        CONSTRAINT `fk_service_token_user` FOREIGN KEY (`user_id`) REFERENCES `qr_service_users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function qr_set_staff_persistent_token($db, $userId) {
    try {
        qr_ensure_service_schema($db);
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $lifetime = 31536000; // 1 year
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);

        $stmt = $db->prepare("INSERT INTO `qr_service_tokens` (`user_id`, `token_hash`, `expires_at`) VALUES (?, ?, ?)");
        $stmt->execute(array((int)$userId, $tokenHash, $expiresAt));

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if (PHP_VERSION_ID >= 70300) {
            setcookie('LAZE_STAFF_TOKEN', $rawToken, array(
                'expires' => time() + $lifetime,
                'path' => '/',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ));
        } else {
            setcookie('LAZE_STAFF_TOKEN', $rawToken, time() + $lifetime, '/', '', $isSecure, true);
        }
    } catch (Exception $e) {
        error_log('qr_set_staff_persistent_token error: ' . $e->getMessage());
    }
}

function qr_clear_staff_persistent_token($db) {
    try {
        if (!empty($_COOKIE['LAZE_STAFF_TOKEN'])) {
            $rawToken = (string)$_COOKIE['LAZE_STAFF_TOKEN'];
            if (preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
                $tokenHash = hash('sha256', $rawToken);
                $stmt = $db->prepare("DELETE FROM `qr_service_tokens` WHERE `token_hash` = ?");
                $stmt->execute(array($tokenHash));
            }
        }
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if (PHP_VERSION_ID >= 70300) {
            setcookie('LAZE_STAFF_TOKEN', '', array(
                'expires' => time() - 86400,
                'path' => '/',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ));
        } else {
            setcookie('LAZE_STAFF_TOKEN', '', time() - 86400, '/', '', $isSecure, true);
        }
    } catch (Exception $e) {
        error_log('qr_clear_staff_persistent_token error: ' . $e->getMessage());
    }
}

function qr_service_identity($db) {
    if (!empty($_SESSION['qr_admin_id'])) {
        return array('id' => (int)$_SESSION['qr_admin_id'], 'display_name' => isset($_SESSION['qr_admin_name']) ? $_SESSION['qr_admin_name'] : 'Yönetici', 'role' => 'admin');
    }

    qr_ensure_service_schema($db);

    // If session is lost or expired, auto-restore from persistent token cookie
    if (empty($_SESSION['qr_service_user_id']) && !empty($_COOKIE['LAZE_STAFF_TOKEN'])) {
        $rawToken = (string)$_COOKIE['LAZE_STAFF_TOKEN'];
        if (preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
            $tokenHash = hash('sha256', $rawToken);
            $tStmt = $db->prepare("SELECT `user_id` FROM `qr_service_tokens` WHERE `token_hash` = ? AND `expires_at` > NOW() LIMIT 1");
            $tStmt->execute(array($tokenHash));
            $tokenRow = $tStmt->fetch();
            if ($tokenRow) {
                $uStmt = $db->prepare('SELECT `id`, `display_name`, `role`, `password` FROM `qr_service_users` WHERE `id` = ? AND `is_active` = 1 LIMIT 1');
                $uStmt->execute(array((int)$tokenRow['user_id']));
                $userCandidate = $uStmt->fetch();
                if ($userCandidate && in_array($userCandidate['role'], array('cashier', 'waiter'), true)) {
                    $_SESSION['qr_service_user_id'] = (int)$userCandidate['id'];
                    $_SESSION['qr_service_password_hash'] = $userCandidate['password'];
                }
            }
        }
    }

    if (empty($_SESSION['qr_service_user_id'])) return null;

    $stmt = $db->prepare('SELECT `id`, `display_name`, `role`, `password` FROM `qr_service_users` WHERE `id` = ? AND `is_active` = 1 LIMIT 1');
    $stmt->execute(array((int)$_SESSION['qr_service_user_id']));
    $user = $stmt->fetch();
    if (!$user || !in_array($user['role'], array('cashier','waiter'), true) || empty($_SESSION['qr_service_password_hash']) || !hash_equals($user['password'], $_SESSION['qr_service_password_hash'])) {
        unset($_SESSION['qr_service_user_id'], $_SESSION['qr_service_password_hash']);
        return null;
    }

    // If logged in but cookie token is missing, issue 1-year persistent token now
    if (empty($_COOKIE['LAZE_STAFF_TOKEN'])) {
        qr_set_staff_persistent_token($db, (int)$user['id']);
    }

    unset($user['password']);
    return $user;
}
function qr_table_from_token($db, $token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) return null;
    $stmt = $db->prepare('SELECT `id`, `label`, `section`, `token` FROM `qr_tables` WHERE `token` = ? AND `is_active` = 1 LIMIT 1');
    $stmt->execute(array($token));
    return $stmt->fetch() ?: null;
}
function qr_settings($db) {
    $settings = array(
        'headline' => 'Menümüz',
        'headline_en' => 'Our Menu',
        'show_prices' => '1',
        'show_descriptions' => '1',
        'waiter_call_enabled' => '1',
        'allergen_filter_enabled' => '1',
        'onesignal_enabled' => '1',
        'onesignal_app_id' => '8644534f-601d-45d8-a474-c40b76fed897',
        'onesignal_rest_key' => '',
        'location_check_enabled' => '1',
        'location_max_distance' => '150',
        'restaurant_lat' => '40.9252987',
        'restaurant_lng' => '29.3113258'
    );
    foreach ($db->query('SELECT `key_name`, `key_value` FROM `qr_menu_settings`') as $row) $settings[$row['key_name']] = $row['key_value'];
    return $settings;
}

/**
 * Calculate distance between two GPS coordinates in meters using Haversine formula
 */
function qr_haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // in meters
    $latFrom = deg2rad((float)$lat1);
    $lonFrom = deg2rad((float)$lon1);
    $latTo = deg2rad((float)$lat2);
    $lonTo = deg2rad((float)$lon2);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
}

function qr_send_onesignal_notification($heading, $content, $url = null, $data = null) {
    $db = qr_db();
    $settings = qr_settings($db);
    if (empty($settings['onesignal_enabled']) || $settings['onesignal_enabled'] === '0') {
        return false;
    }
    $appId = !empty($settings['onesignal_app_id']) ? trim($settings['onesignal_app_id']) : '';
    $restKey = !empty($settings['onesignal_rest_key']) ? trim($settings['onesignal_rest_key']) : '';
    if (!$appId || !$restKey) {
        return false;
    }

    if ($url === null) {
        $url = 'https://menu.lazekofte.com/staff/';
    }

    $payload = array(
        'app_id' => $appId,
        'included_segments' => array('Total Subscriptions'),
        'headings' => array('en' => $heading, 'tr' => $heading),
        'contents' => array('en' => $content, 'tr' => $content),
        'url' => $url,
        'chrome_web_icon' => 'https://menu.lazekofte.com/assets/logo.webp',
        'chrome_web_badge' => 'https://menu.lazekofte.com/assets/logo.webp',
        'firefox_icon' => 'https://menu.lazekofte.com/assets/logo.webp',
        'priority' => 10,
        'ttl' => 3600,
        'web_buttons' => array(
            array(
                'id' => 'open-service',
                'text' => 'Çağrıyı Aç ↗',
                'url' => $url
            )
        )
    );
    if (!empty($data) && is_array($data)) {
        $payload['data'] = $data;
    }

    $ch = curl_init('https://api.onesignal.com/notifications');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Key ' . $restKey
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('OneSignal Push cURL error: ' . $curlErr);
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        error_log('OneSignal Push HTTP ' . $httpCode . ': ' . $response);
        return false;
    }
}

/**
 * Checks for pending waiter calls that have been waiting >= 3 minutes (180 seconds)
 * without acknowledgment, and triggers a 3-minute overdue reminder notification.
 */
function qr_check_overdue_waiter_calls($db) {
    try {
        qr_ensure_service_schema($db);
        // Find calls that are still 'new' (no staff has attended yet), waiting >= 180 seconds,
        // and have not received the 3-minute reminder notification yet.
        $stmt = $db->query("
            SELECT c.`id`, c.`table_id`, TIMESTAMPDIFF(SECOND, c.`created_at`, NOW()) AS `wait_seconds`,
                   t.`label`, COALESCE(NULLIF(t.`section`, ''), 'Salon') AS `section`
            FROM `qr_waiter_calls` c
            JOIN `qr_tables` t ON t.`id` = c.`table_id`
            WHERE c.`status` = 'new'
              AND c.`reminded_3m_at` IS NULL
              AND TIMESTAMPDIFF(SECOND, c.`created_at`, NOW()) >= 180
            ORDER BY c.`id` ASC
            LIMIT 5
        ");
        $overdue = $stmt ? $stmt->fetchAll() : array();
        if (empty($overdue)) {
            return 0;
        }

        $sentCount = 0;
        foreach ($overdue as $call) {
            // Update atomically to ensure concurrent polling requests don't duplicate notifications
            $upd = $db->prepare("UPDATE `qr_waiter_calls` SET `reminded_3m_at` = NOW() WHERE `id` = ? AND `reminded_3m_at` IS NULL");
            $upd->execute(array($call['id']));
            if ($upd->rowCount() > 0) {
                $tableLabel = !empty($call['label']) ? $call['label'] : 'Masa #' . $call['table_id'];
                $secName = !empty($call['section']) ? $call['section'] : 'Salon';
                $heading = '⚠️ 3 Dkdır Bekliyor: ' . $tableLabel;
                $content = $tableLabel . ' (' . $secName . ') 3 dakikadır bekliyor! Lütfen ilgilenin.';
                qr_send_onesignal_notification($heading, $content, 'https://menu.lazekofte.com/staff/', array(
                    'table_id' => $call['table_id'],
                    'call_id' => $call['id'],
                    'table_label' => $tableLabel,
                    'section' => $secName,
                    'type' => 'call_overdue_3m'
                ));
                $sentCount++;
            }
        }
        return $sentCount;
    } catch (Exception $e) {
        error_log('Error checking overdue waiter calls: ' . $e->getMessage());
        return 0;
    }
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
