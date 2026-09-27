<?php
require_once __DIR__ . '/inc/app.php';
$db = qr_db();
qr_ensure_translation_schema($db);
qr_ensure_product_meta_columns($db);
$table = null;
if (isset($_GET['table'])) {
    qr_ensure_service_schema($db);
    $table = qr_table_from_token($db, $_GET['table']);
}
$language = qr_language();
$isEnglish = $language === 'en';
$settings = qr_settings($db);
$categories = $db->query($isEnglish ? "SELECT c.`id`, COALESCE(NULLIF(t.`name_en`, ''), c.`name`) AS `name`, COALESCE(NULLIF(t.`description_en`, ''), c.`description`) AS `description` FROM `categories` c LEFT JOIN `qr_menu_category_translations` t ON t.`category_id` = c.`id` WHERE c.`is_active` = 1 ORDER BY c.`sort_order`, c.`id`" : 'SELECT `id`, `name`, `description` FROM `categories` WHERE `is_active` = 1 ORDER BY `sort_order`, `id`')->fetchAll();
$products = $db->query($isEnglish ? "SELECT p.`id`, p.`category_id`, COALESCE(NULLIF(t.`name_en`, ''), p.`name`) AS `name`, p.`tag`, COALESCE(NULLIF(t.`description_en`, ''), p.`description`) AS `description`, p.`price`, p.`discount_price`, p.`image`, p.`prep_time`, p.`calories`, p.`weight`, p.`allergens` FROM `products` p LEFT JOIN `qr_menu_product_translations` t ON t.`product_id` = p.`id` LEFT JOIN `qr_menu_hidden_products` h ON h.`product_id` = p.`id` WHERE p.`is_active` = 1 AND h.`product_id` IS NULL ORDER BY p.`sort_order`, p.`id`" : 'SELECT p.`id`, p.`category_id`, p.`name`, p.`tag`, p.`description`, p.`price`, p.`discount_price`, p.`image`, p.`prep_time`, p.`calories`, p.`weight`, p.`allergens` FROM `products` p LEFT JOIN `qr_menu_hidden_products` h ON h.`product_id` = p.`id` WHERE p.`is_active` = 1 AND h.`product_id` IS NULL ORDER BY p.`sort_order`, p.`id`')->fetchAll();
$allergensList = qr_allergens($language);
$byCategory = array();
foreach ($products as $item) $byCategory[$item['category_id']][] = $item;
$showPrices = $settings['show_prices'] === '1';
$allergenFilterEnabled = (!isset($settings['allergen_filter_enabled']) || $settings['allergen_filter_enabled'] === '1');
$headline = $isEnglish && !empty($settings['headline_en']) ? $settings['headline_en'] : ($isEnglish ? qr_t('menu', $language) : $settings['headline']);

// Sosyal medya ve site ayarları
$siteInfo = array(
    'instagram_url' => 'https://www.instagram.com/lazekoftevecorba/',
    'facebook_url' => 'https://www.facebook.com/share/1VCtAHtfun/',
    'site_title' => 'LAZE Köfte & Çorba',
    'site_description' => 'Kemik suyuna şifalı çorbalar, kömür ızgarasından köfteler ve geleneksel tatlar.',
    'logo_path' => 'assets/logo.webp',
    'phone' => '0216 384 52 93',
    'phone_display' => '0 (216) 384 52 93',
    'address_short' => 'Bostancı, Kadıköy / İstanbul',
    'hours_weekday' => '11:00 - 23:00',
    'footer_signoff' => 'Afiyet olsun.',
    'maps_directions' => 'https://www.google.com/maps/dir/?api=1&destination=40.9252987%2C29.3113258'
);
$settingsQuery = $db->query("SELECT `key_name`, `key_value` FROM `settings` WHERE `key_name` IN ('instagram_url', 'facebook_url', 'site_title', 'site_description', 'logo_path', 'phone', 'phone_display', 'address_short', 'hours_weekday', 'footer_signoff')");
foreach ($settingsQuery as $row) {
    if (!empty($row['key_value'])) {
        $siteInfo[$row['key_name']] = $row['key_value'];
    }
}
$footerTitle = preg_replace('/^\s*LAZE\s*/iu', '', preg_split('/\s*\|\s*/u', $siteInfo['site_title'])[0]);
if ($footerTitle === '') $footerTitle = 'Köfte & Çorba';
$flash = qr_take_flash();
?>
<!doctype html>
<html lang="<?= qr_e($language) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <meta name="theme-color" content="#192a24">
  <meta name="description" content="LAZE Köfte & Çorba güncel lezzet menüsü. İlikli kemik suyuna şifalı çorbalar ve kömür ızgarasından köfteler.">
  <link rel="canonical" href="https://menu.lazekofte.com/">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Menü | LAZE Köfte &amp; Çorba">
  <meta property="og:description" content="LAZE Köfte &amp; Çorba güncel menüsü.">
  <meta property="og:url" content="https://menu.lazekofte.com/">
  <meta property="og:image" content="https://lazekofte.com/assets/social-preview.jpg">
  <title>Menü | LAZE Köfte &amp; Çorba</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..900;1,6..96,400..900&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400;1,9..40,500;1,9..40,700&display=swap" rel="stylesheet">

  <link rel="icon" href="<?= QR_BASE ?>assets/favicon.png" type="image/png">
  <link rel="preload" href="<?= QR_BASE ?>assets/logo.webp" as="image" type="image/webp">
  <link rel="preload" href="<?= QR_BASE ?>assets/hero-laze-mobile.webp" as="image" type="image/webp" media="(max-width: 640px)">
  <link rel="preload" href="<?= QR_BASE ?>assets/hero-laze-opt.webp" as="image" type="image/webp" media="(min-width: 641px)">
  <link rel="stylesheet" href="<?= QR_BASE ?>assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
  <script>
    window.QR_ALLERGENS = <?= json_encode($allergensList, JSON_UNESCAPED_UNICODE) ?>;
    window.QR_IS_ENGLISH = <?= $isEnglish ? 'true' : 'false' ?>;
  </script>
  <script src="<?= QR_BASE ?>assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>" defer></script>
  <?php if ($settings['waiter_call_enabled'] === '1'): ?><link rel="stylesheet" href="<?= QR_BASE ?>assets/call.css?v=<?= filemtime(__DIR__ . '/assets/call.css') ?>"><script src="<?= QR_BASE ?>assets/call.js?v=<?= filemtime(__DIR__ . '/assets/call.js') ?>" defer></script><?php endif; ?>
</head>
<body>
  <div class="page-loader" id="page-loader" aria-hidden="true">
    <div class="page-loader-inner">
      <img src="<?= QR_BASE ?>assets/logo.webp" alt="" class="page-loader-logo" width="86" height="86" decoding="async">
    </div>
  </div>
  <header class="topbar">
    <div class="topbar-inner">
      <button class="feedback-trigger" type="button" data-open-feedback aria-haspopup="dialog" aria-label="<?= qr_e(qr_t('feedback', $language)) ?>">
        <svg class="feedback-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 11.5a7 7 0 0 1-7 7H8l-4 3v-10a7 7 0 0 1 7-7h2a7 7 0 0 1 7 7Z"></path>
          <path d="M9 11.5h.01M12 11.5h.01M15 11.5h.01"></path>
        </svg>
        <span class="feedback-trigger-label"><?= qr_e(qr_t('review', $language)) ?></span>
      </button>

      <a class="brand-mark" href="<?= QR_BASE ?><?= $table ? '?table=' . qr_e($table['token']) : '' ?>" aria-label="LAZE <?= qr_e(qr_t('menu', $language)) ?>">
        <span class="brand-name"><b>LAZE</b><span>Köfte &amp; Çorba</span></span>
      </a>

      <div class="top-actions">
        <details class="language-select">
          <summary aria-label="<?= $isEnglish ? 'Select language' : 'Dil seçin' ?>">
            <img class="language-flag" src="<?= QR_BASE ?>assets/icons/<?= $isEnglish ? 'flag-gb.svg' : 'flag-tr.svg' ?>" alt="">
            <span><?= $isEnglish ? 'EN' : 'TR' ?></span>
            <svg viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"></path></svg>
          </summary>
          <div class="language-options" role="group" aria-label="<?= $isEnglish ? 'Languages' : 'Diller' ?>">
            <a href="<?= QR_BASE ?>?lang=tr<?= $table ? '&amp;table=' . qr_e($table['token']) : '' ?>" class="<?= $language === 'tr' ? 'is-active' : '' ?>" lang="tr"><img src="<?= QR_BASE ?>assets/icons/flag-tr.svg" alt=""><span>Türkçe</span></a>
            <a href="<?= QR_BASE ?>?lang=en<?= $table ? '&amp;table=' . qr_e($table['token']) : '' ?>" class="<?= $language === 'en' ? 'is-active' : '' ?>" lang="en"><img src="<?= QR_BASE ?>assets/icons/flag-gb.svg" alt=""><span>English</span></a>
          </div>
        </details>
      <div class="social-links" aria-label="Sosyal medya">
        <a href="<?= qr_e($siteInfo['instagram_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
          <img src="<?= QR_BASE ?>assets/icons/instagram.svg" width="18" height="18" alt="">
        </a>
        <a href="<?= qr_e($siteInfo['facebook_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
          <img src="<?= QR_BASE ?>assets/icons/facebook.svg" width="18" height="18" alt="">
        </a>
      </div>
      </div>
      <div class="top-follow" aria-label="<?= $isEnglish ? 'Follow us' : 'Bizi takip et' ?>">
        <span><?= $isEnglish ? 'Follow us' : 'Bizi takip et' ?></span>
        <a href="<?= qr_e($siteInfo['instagram_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
          <img src="<?= QR_BASE ?>assets/icons/instagram.svg" width="17" height="17" alt="">
        </a>
        <a href="<?= qr_e($siteInfo['facebook_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
          <img src="<?= QR_BASE ?>assets/icons/facebook.svg" width="17" height="17" alt="">
        </a>
      </div>
    </div>
  </header>
  <?php if ($settings['waiter_call_enabled'] === '1'): ?><div class="waiter-call" data-call-url="<?= QR_BASE ?>call.php" data-table="<?= $table ? qr_e($table['token']) : '' ?>" data-csrf="<?= $table ? qr_e(qr_csrf()) : '' ?>" data-location-check="<?= (!isset($settings['location_check_enabled']) || $settings['location_check_enabled'] === '1') ? '1' : '0' ?>" data-rest-lat="<?= qr_e(isset($settings['restaurant_lat']) ? $settings['restaurant_lat'] : '40.9252987') ?>" data-rest-lng="<?= qr_e(isset($settings['restaurant_lng']) ? $settings['restaurant_lng'] : '29.3113258') ?>" data-max-dist="<?= qr_e(isset($settings['location_max_distance']) ? $settings['location_max_distance'] : '150') ?>"><button type="button" id="waiter-call-button"><?= $isEnglish ? '🔔 Call a waiter' : '🔔 Garson çağır' ?></button><p id="waiter-call-status" role="status" aria-live="polite"></p></div><?php endif; ?>

  <main id="menu-list" class="menu-section">
    <div class="wrap">
      <?php if ($flash): ?>
      <div class="menu-alert <?= $flash[1] === 'error' ? 'error' : 'success' ?>" role="status">
        <?= $flash[1] === 'error' ? '✕ ' : '✓ ' ?><?= qr_e($flash[0]) ?>
      </div>
      <?php endif; ?>

      <!-- 1. KATEGORİ SEÇİM EKRANI (Açılış) -->
      <section class="category-home" id="categories" aria-labelledby="menu-heading">
        <div class="welcome-visual" aria-label="LAZE Köfte ve Çorba">
          <picture>
            <source media="(max-width: 640px)" srcset="<?= QR_BASE ?>assets/hero-laze-mobile.webp" type="image/webp">
            <img src="<?= QR_BASE ?>assets/hero-laze-opt.webp" width="1200" height="675" alt="LAZE mutfağından sıcak çorba sunumu" fetchpriority="high" decoding="async">
          </picture>
          <span class="welcome-visual-overlay" aria-hidden="true"></span>
          <div class="welcome-logo">
            <img src="<?= QR_BASE ?>assets/logo.webp" width="112" height="112" alt="LAZE Köfte &amp; Çorba" decoding="async">
          </div>
        </div>
        <div class="menu-hero">
          <h1 class="page-title" id="menu-heading"><?= qr_e($headline) ?></h1>
        </div>

        <div class="search-container">
          <div class="search-filter-row">
            <label class="search-box">
              <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m16.5 16.5 4.5 4.5" stroke-linecap="round"></path></svg>
              <span class="sr-only"><?= qr_e(qr_t('search', $language)) ?></span>
              <input class="search-input global-search-input" type="search" placeholder="<?= qr_e(qr_t('search', $language)) ?>" autocomplete="off">
              <button class="search-clear" type="button" aria-label="<?= $isEnglish ? 'Clear search' : 'Aramayı temizle' ?>" hidden>×</button>
            </label>
            <?php if ($allergenFilterEnabled): ?>
            <button type="button" class="btn-allergen-filter" data-open-allergen-filter aria-label="<?= qr_e(qr_t('allergen_filter', $language)) ?>" title="<?= qr_e(qr_t('allergen_filter', $language)) ?>">
              <svg class="allergen-shield-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
              <span class="allergen-btn-label"><?= qr_e(qr_t('allergens', $language)) ?></span>
              <span class="allergen-badge-count" hidden>0</span>
            </button>
            <?php endif; ?>
          </div>
        </div>

        <div class="category-tiles">
          <?php foreach ($categories as $category): 
            if (empty($byCategory[$category['id']])) continue; 
            $catDishes = $byCategory[$category['id']];
            $cover = $catDishes[0]; 
          ?>
          <a class="category-tile" href="#category-<?= (int)$category['id'] ?>" data-category-link>
            <img src="<?= qr_e(qr_image_url($cover['image'])) ?>" alt="<?= qr_e($category['name']) ?>" loading="eager" decoding="async" width="280" height="158">
            <div class="category-tile-content">
              <span class="category-tile-title"><?= qr_e($category['name']) ?></span>
              <span class="category-tile-action"><?= qr_e(qr_t('details', $language)) ?> →</span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- 2. ÜRÜN LİSTESİ GÖRÜNÜMÜ -->
      <div class="product-view" id="product-view">
        <div class="category-bar-wrapper">
          <div class="category-bar-inner">
            <a class="back-to-categories" href="#categories" data-category-back aria-label="<?= qr_e(qr_t('back_menu', $language)) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span><?= $isEnglish ? 'Menu' : 'Menü' ?></span>
            </a>
            <nav class="category-nav-scroll" aria-label="<?= qr_e(qr_t('categories', $language)) ?>">
              <?php foreach ($categories as $category): if (empty($byCategory[$category['id']])) continue; ?>
              <a href="#category-<?= (int)$category['id'] ?>" data-category-link><?= qr_e($category['name']) ?></a>
              <?php endforeach; ?>
            </nav>
          </div>
        </div>

        <div class="search-container product-view-search">
          <div class="search-filter-row">
            <label class="search-box">
              <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m16.5 16.5 4.5 4.5" stroke-linecap="round"></path></svg>
              <span class="sr-only"><?= qr_e(qr_t('search_category', $language)) ?></span>
              <input id="menu-search" class="search-input" type="search" placeholder="<?= qr_e(qr_t('search_category', $language)) ?>" autocomplete="off">
              <button class="search-clear" type="button" aria-label="<?= $isEnglish ? 'Clear search' : 'Aramayı temizle' ?>" hidden>×</button>
            </label>
            <?php if ($allergenFilterEnabled): ?>
            <button type="button" class="btn-allergen-filter" data-open-allergen-filter aria-label="<?= qr_e(qr_t('allergen_filter', $language)) ?>" title="<?= qr_e(qr_t('allergen_filter', $language)) ?>">
              <svg class="allergen-shield-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
              <span class="allergen-btn-label"><?= qr_e(qr_t('allergens', $language)) ?></span>
              <span class="allergen-badge-count" hidden>0</span>
            </button>
            <?php endif; ?>
          </div>
          <div class="active-allergen-bar" id="active-allergen-bar" hidden>
            <div class="active-allergen-info">
              <span class="active-allergen-title">🛡️ <?= $isEnglish ? 'Filter:' : 'Filtre:' ?></span>
              <div class="active-allergen-chips" id="active-allergen-chips"></div>
            </div>
            <button type="button" class="active-allergen-clear" id="active-allergen-clear"><?= qr_e(qr_t('allergen_clear', $language)) ?> ×</button>
          </div>
        </div>

        <div id="empty-state" class="empty-state" hidden>
          <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            <line x1="8" y1="11" x2="14" y2="11"></line>
          </svg>
          <h2 class="empty-state-title"><?= qr_e(qr_t('no_results', $language)) ?></h2>
          <p class="empty-state-text"><?= $isEnglish ? 'Try another search term or browse the full menu.' : 'Lütfen farklı bir arama terimi deneyin veya tüm menüye göz atın.' ?></p>
          <button class="empty-state-btn" type="button" id="clear-search-btn"><?= $isEnglish ? 'Clear search' : 'Aramayı Temizle' ?></button>
        </div>

        <?php foreach ($categories as $category): if (empty($byCategory[$category['id']])) continue; ?>
        <section class="category-section" id="category-<?= (int)$category['id'] ?>" data-category>
          <div class="category-header">
            <h2 class="category-title"><?= qr_e($category['name']) ?></h2>
            <?php if (!empty($category['description'])): ?>
            <p class="category-desc"><?= qr_e($category['description']) ?></p>
            <?php endif; ?>
          </div>

          <div class="product-grid">
            <?php foreach ($byCategory[$category['id']] as $item): 
              $rawPrice = $item['price'] !== null ? (float)$item['price'] : null;
              $rawDiscount = !empty($item['discount_price']) && (float)$item['discount_price'] > 0 ? (float)$item['discount_price'] : null;
              $hasDiscount = ($showPrices && $rawPrice !== null && $rawDiscount !== null && $rawDiscount < $rawPrice);
              $price = ($showPrices && $rawPrice !== null) ? number_format($rawPrice, 2, ',', '.') . ' ₺' : ''; 
              $discountPrice = ($showPrices && $rawDiscount !== null) ? number_format($rawDiscount, 2, ',', '.') . ' ₺' : '';
              
              $calVal = trim((string)($item['calories'] ?? ''));
              $hasCal = ($calVal !== '' && $calVal !== '0' && strtolower($calVal) !== '0 kcal' && strtolower($calVal) !== '0kcal');
              $prepVal = trim((string)($item['prep_time'] ?? ''));
              $hasPrep = ($prepVal !== '');
              $weightVal = trim((string)($item['weight'] ?? ''));
              $hasWeight = ($weightVal !== '');
              $hasMeta = $hasPrep || $hasCal || $hasWeight;
              $itemAllergens = !empty($item['allergens']) ? array_filter(array_map('trim', explode(',', $item['allergens']))) : array();
            ?>
            <button class="product-card open-product" type="button" data-product 
                    data-search="<?= qr_e($item['name'] . ' ' . $item['description'] . ' ' . $item['tag']) ?>" 
                    data-name="<?= qr_e($item['name']) ?>" 
                    data-description="<?= qr_e($item['description']) ?>" 
                    data-tag="<?= qr_e($item['tag']) ?>" 
                    data-image="<?= qr_e(qr_image_url($item['image'], true)) ?>" 
                    data-thumb="<?= qr_e(qr_image_url($item['image'], false)) ?>" 
                    data-price="<?= qr_e($price) ?>" 
                    data-discount-price="<?= qr_e($discountPrice) ?>" 
                    data-has-discount="<?= $hasDiscount ? '1' : '0' ?>" 
                    data-prep="<?= $hasPrep ? qr_e($prepVal) : '' ?>" 
                    data-calories="<?= $hasCal ? qr_e($calVal) : '' ?>" 
                    data-weight="<?= $hasWeight ? qr_e($weightVal) : '' ?>" 
                    data-allergens="<?= qr_e($item['allergens'] ?? '') ?>" 
                    aria-label="<?= qr_e($item['name']) ?> <?= $isEnglish ? 'view details' : 'detayını görüntüle' ?>">
              
              <span class="product-photo">
                <img src="<?= qr_e(qr_image_url($item['image'])) ?>" alt="<?= qr_e($item['name']) ?>" loading="lazy" decoding="async" width="96" height="96">
              </span>

              <span class="product-info">
                <span class="product-info-top">
                  <?php if (!empty($item['tag'])): ?>
                  <span class="product-tag"><?= qr_e($item['tag']) ?></span>
                  <?php endif; ?>
                  <strong class="product-name"><?= qr_e($item['name']) ?></strong>
                  <?php if (!empty($item['description'])): ?>
                  <span class="product-description"><?= qr_e($item['description']) ?></span>
                  <?php endif; ?>
                  <?php if ($hasMeta || !empty($itemAllergens)): ?>
                  <span class="product-card-meta">
                    <?php if ($hasPrep): ?>
                    <span class="card-meta-pill" title="<?= qr_e(qr_t('prep_time', $language)) ?>">⏱ <?= qr_e($prepVal) ?></span>
                    <?php endif; ?>
                    <?php if ($hasCal): ?>
                    <span class="card-meta-pill" title="<?= qr_e(qr_t('calories', $language)) ?>">🔥 <?= qr_e($calVal) ?></span>
                    <?php endif; ?>
                    <?php if ($hasWeight): ?>
                    <span class="card-meta-pill" title="<?= qr_e(qr_t('weight', $language)) ?>">⚖️ <?= qr_e($weightVal) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($itemAllergens)): ?>
                    <span class="card-allergen-icons" title="<?= qr_e(qr_t('allergens', $language)) ?>">
                      <?php 
                      $showCount = 0;
                      foreach ($itemAllergens as $aCode):
                        if (isset($allergensList[$aCode]) && $showCount < 3):
                          echo '<span class="mini-allergen" title="' . qr_e($allergensList[$aCode]['name']) . '">' . $allergensList[$aCode]['icon'] . '</span>';
                          $showCount++;
                        endif;
                      endforeach;
                      if (count($itemAllergens) > 3) echo '<span class="mini-allergen-more">+' . (count($itemAllergens) - 3) . '</span>';
                      ?>
                    </span>
                    <?php endif; ?>
                  </span>
                  <?php endif; ?>
                </span>
                
                <span class="product-info-bottom">
                  <?php if ($showPrices && $price !== ''): ?>
                    <?php if ($hasDiscount): ?>
                    <span class="product-price has-discount">
                      <s class="price-old"><?= qr_e($price) ?></s>
                      <b class="price-current"><?= qr_e($discountPrice) ?></b>
                    </span>
                    <?php else: ?>
                    <b class="product-price"><?= qr_e($price) ?></b>
                    <?php endif; ?>
                  <?php else: ?>
                  <span class="product-price"></span>
                  <?php endif; ?>
                  <span class="product-view-btn" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                  </span>
                </span>
              </span>
            </button>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endforeach; ?>
      </div>
    </div>
  </main>

  <!-- Ürün Detay Dialog (Modal / Bottom Sheet) -->
  <dialog id="product-dialog" class="product-dialog" aria-labelledby="dialog-name">
    <div class="drawer-handle" aria-hidden="true"><span></span></div>
    <div class="dialog-content">
      <div class="dialog-image-wrap">
        <button class="dialog-close" type="button" aria-label="<?= $isEnglish ? 'Close' : 'Kapat' ?>">×</button>
        <img class="dialog-image" src="" alt="">
      </div>
      <div class="dialog-body">
        <h2 id="dialog-name" class="dialog-title"></h2>
        <span id="dialog-tag" class="dialog-tag" hidden></span>
        
        <!-- Besin ve Hazırlık Bilgileri -->
        <div id="dialog-meta-chips" class="dialog-meta-chips" hidden>
          <span id="dialog-prep-chip" class="dialog-meta-chip" hidden>
            <span class="meta-chip-icon">⏱</span>
            <span class="meta-chip-label"><?= qr_e(qr_t('prep_time', $language)) ?>:</span>
            <strong id="dialog-prep-val" class="meta-chip-val"></strong>
          </span>
          <span id="dialog-calories-chip" class="dialog-meta-chip" hidden>
            <span class="meta-chip-icon">🔥</span>
            <span class="meta-chip-label"><?= qr_e(qr_t('calories', $language)) ?>:</span>
            <strong id="dialog-calories-val" class="meta-chip-val"></strong>
          </span>
          <span id="dialog-weight-chip" class="dialog-meta-chip" hidden>
            <span class="meta-chip-icon">⚖️</span>
            <span class="meta-chip-label"><?= qr_e(qr_t('weight', $language)) ?>:</span>
            <strong id="dialog-weight-val" class="meta-chip-val"></strong>
          </span>
        </div>

        <div id="dialog-price-box" class="dialog-price-box">
          <s id="dialog-old-price" class="dialog-old-price" hidden></s>
          <strong id="dialog-price" class="dialog-price"></strong>
        </div>

        <!-- Alerjen Etiketleri (Fiyat alanının hemen altında) -->
        <div id="dialog-allergen-tags" class="dialog-allergen-tags" hidden></div>

        <p id="dialog-description" class="dialog-description" hidden></p>

        <button class="dialog-close-btn" type="button"><?= qr_e(qr_t('back_menu', $language)) ?></button>
      </div>
    </div>
  </dialog>

  <!-- Alerjen Filtre Dialog (Modal / Bottom Sheet) -->
  <?php if ($allergenFilterEnabled): ?>
  <dialog id="allergen-filter-dialog" class="allergen-dialog" aria-labelledby="allergen-dialog-title">
    <div class="drawer-handle" aria-hidden="true"><span></span></div>
    <div class="allergen-dialog-content">
      <div class="allergen-dialog-header">
        <div class="allergen-dialog-header-text">
          <span class="allergen-badge-tag"><?= qr_e(qr_t('allergen_notice', $language)) ?></span>
          <h2 id="allergen-dialog-title" class="allergen-dialog-title"><?= qr_e(qr_t('allergen_filter', $language)) ?></h2>
          <p class="allergen-dialog-desc"><?= $isEnglish ? 'Select the allergens you want to avoid. Dishes containing them will be hidden from the menu.' : 'Tüketemediğiniz veya alerjiniz olan maddeleri seçin; bu maddeleri içeren lezzetler menüde gizlenir.' ?></p>
        </div>
        <button class="dialog-close" type="button" data-close-allergen aria-label="<?= $isEnglish ? 'Close' : 'Kapat' ?>">×</button>
      </div>

      <div class="allergen-filter-grid">
        <?php foreach ($allergensList as $aId => $aItem): ?>
        <button type="button" class="allergen-select-chip" data-allergen-id="<?= qr_e($aId) ?>">
          <span class="allergen-chip-icon"><?= $aItem['icon'] ?></span>
          <span class="allergen-chip-info">
            <strong class="allergen-chip-name"><?= qr_e($aItem['name']) ?></strong>
            <small class="allergen-chip-desc"><?= qr_e($aItem['desc']) ?></small>
          </span>
          <span class="allergen-chip-check" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
        </button>
        <?php endforeach; ?>
      </div>

      <div class="allergen-dialog-footer">
        <button type="button" class="btn-allergen-reset" id="btn-reset-allergens"><?= qr_e(qr_t('allergen_clear', $language)) ?></button>
        <button type="button" class="btn-allergen-apply" id="btn-apply-allergens"><?= qr_e(qr_t('allergen_apply', $language)) ?> (<span id="allergen-apply-count">0</span>)</button>
      </div>
    </div>
  </dialog>
  <?php endif; ?>

  <!-- Geri Bildirim Dialog (Modal) -->
  <dialog id="feedback-dialog" class="feedback-dialog" aria-labelledby="feedback-title">
    <div class="feedback-head">
      <button class="dialog-close" type="button" data-close-feedback aria-label="<?= $isEnglish ? 'Close' : 'Kapat' ?>">×</button>
      <span class="feedback-head-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 11.5a7 7 0 0 1-7 7H8l-4 3v-10a7 7 0 0 1 7-7h2a7 7 0 0 1 7 7Z"></path><path d="M9 11.5h.01M12 11.5h.01M15 11.5h.01"></path></svg></span>
      <span class="feedback-kicker"><?= $isEnglish ? 'WE ARE LISTENING' : 'SİZİ DİNLİYORUZ' ?></span>
      <h2 id="feedback-title"><?= qr_e(qr_t('experience', $language)) ?></h2>
      <p><?= $isEnglish ? 'Choose a rating and, if you wish, leave a short note.' : 'Bir puan seçin, dilerseniz kısa bir not bırakın.' ?></p>
    </div>
    <form method="post" action="<?= QR_BASE ?>feedback.php" class="feedback-form">
      <input type="hidden" name="csrf" value="<?= qr_e(qr_csrf()) ?>">
      <label class="sr-only" for="feedback-website">Web sitesi</label>
      <input class="feedback-honeypot" type="text" name="website" id="feedback-website" tabindex="-1" autocomplete="off">
      
      <fieldset>
        <legend class="feedback-legend"><?= qr_e(qr_t('select_rating', $language)) ?></legend>
        <div class="rating-group" role="radiogroup" aria-label="<?= $isEnglish ? 'Your rating (1-5)' : 'Puanınız (1-5)' ?>">
          <?php 
          $ratingNames = $isEnglish ? array(1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent') : array(1 => 'Kötü', 2 => 'Eh', 3 => 'İyi', 4 => 'Çok iyi', 5 => 'Harika');
          for ($rating = 1; $rating <= 5; $rating++): 
          ?>
          <label class="rating-star-btn" data-rating-label="<?= qr_e($ratingNames[$rating]) ?>">
            <input type="radio" name="rating" value="<?= $rating ?>" required>
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
            </svg>
            <span class="star-val"><?= qr_e($ratingNames[$rating]) ?></span>
          </label>
          <?php endfor; ?>
        </div>
        <div class="rating-status-text" id="rating-status" aria-live="polite"><?= qr_e(qr_t('select_rating', $language)) ?></div>
      </fieldset>

      <div>
        <div class="feedback-label-row"><label class="feedback-label" for="feedback-comment"><?= qr_e(qr_t('short_note', $language)) ?> <span>(<?= qr_e(qr_t('optional', $language)) ?>)</span></label><span id="feedback-count" class="feedback-count" aria-live="polite">0 / 1000</span></div>
        <textarea id="feedback-comment" class="feedback-textarea" name="comment" rows="3" maxlength="1000" placeholder="<?= $isEnglish ? 'About the food, service or atmosphere...' : 'Lezzet, servis veya ortam hakkında...' ?>"></textarea>
        <p class="feedback-privacy"><?= qr_e(qr_t('privacy', $language)) ?></p>
      </div>

      <button class="feedback-submit" type="submit">
        <span><?= qr_e(qr_t('send_feedback', $language)) ?></span>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </button>
    </form>
  </dialog>

  <nav class="mobile-nav" aria-label="<?= $isEnglish ? 'Quick menu' : 'Hızlı menü' ?>">
    <a class="mobile-nav-item" href="tel:<?= preg_replace('/\s+/', '', $siteInfo['phone']) ?>" aria-label="<?= qr_e(qr_t('call', $language)) ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h3l2 5-2 1.5a14 14 0 0 0 5.5 5.5L15 14l5 2v3c0 .6-.4 1-1 1C10.7 20 4 13.3 4 5c0-.6.4-1 1-1Z"></path></svg><span><?= qr_e(qr_t('call', $language)) ?></span>
    </a>
    <a class="mobile-nav-item" href="<?= qr_e($siteInfo['maps_directions']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Yol tarifi">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 10-8 10S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg><span><?= qr_e(qr_t('location', $language)) ?></span>
    </a>
    <a class="mobile-nav-menu" href="#categories" data-category-back aria-label="<?= qr_e(qr_t('back_menu', $language)) ?>">
      <span class="mobile-nav-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v7M5 3v4M9 3v4M7 10v11M16 3v18M13 3v7a3 3 0 0 0 6 0V3"></path></svg></span><span><?= $isEnglish ? 'Menu' : 'Menü' ?></span>
    </a>
    <a class="mobile-nav-item" href="<?= qr_e($siteInfo['instagram_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"></rect><circle cx="12" cy="12" r="4"></circle><path d="M17.5 6.5h.01"></path></svg><span>Instagram</span>
    </a>
    <button class="mobile-nav-item" type="button" data-open-feedback aria-haspopup="dialog" aria-label="<?= qr_e(qr_t('feedback', $language)) ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a7 7 0 0 1-7 7H8l-4 3v-10a7 7 0 0 1 7 7Z"></path><path d="M9 11.5h.01M12 11.5h.01M15 11.5h.01"></path></svg><span><?= qr_e(qr_t('review', $language)) ?></span>
    </button>
  </nav>

  <!-- Alt Bilgi / Footer -->
  <footer class="menu-footer">
    <div class="footer-inner">
      <div class="footer-brand">
        <img class="footer-logo" src="<?= qr_e(qr_image_url($siteInfo['logo_path'])) ?>" width="42" height="42" alt="LAZE">
        <div><span class="footer-kicker">LAZE</span><h3 class="footer-title"><?= qr_e($footerTitle) ?></h3></div>
      </div>
      <p class="footer-desc"><?= qr_e($siteInfo['site_description']) ?></p>
      
      <div class="footer-info-row">
        <?php if (!empty($siteInfo['hours_weekday'])): ?>
        <span class="footer-info-item"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M12 7v5l3 2"></path></svg><?= qr_e($siteInfo['hours_weekday']) ?></span>
        <?php endif; ?>
        <?php if (!empty($siteInfo['phone_display'])): ?>
        <span class="footer-info-item"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h3l2 5-2 1.5a14 14 0 0 0 5.5 5.5L15 14l5 2v3c0 .6-.4 1-1 1C10.7 20 4 13.3 4 5c0-.6.4-1 1-1Z"></path></svg><a href="tel:<?= preg_replace('/\s+/', '', $siteInfo['phone']) ?>"><?= qr_e($siteInfo['phone_display']) ?></a></span>
        <?php endif; ?>
        <?php if (!empty($siteInfo['address_short'])): ?>
        <span class="footer-info-item footer-address"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 10-8 10S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg><?= qr_e($siteInfo['address_short']) ?></span>
        <?php endif; ?>
      </div>

      <span class="footer-signoff"><?= qr_e($siteInfo['footer_signoff']) ?></span>
      <p class="footer-copy">© <?= date('Y') ?> LAZE <?= $isEnglish ? 'Meatballs &amp; Soup. All rights reserved.' : 'Köfte &amp; Çorba. Tüm hakları saklıdır.' ?></p>
    </div>
  </footer>
</body>
</html>
