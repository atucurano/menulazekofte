<?php
require_once dirname(__DIR__) . '/inc/app.php';
qr_admin_required();
$db = qr_db();
qr_ensure_service_schema($db);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = $db->prepare('SELECT `label`, `section`, `token`, `is_active` FROM `qr_tables` WHERE `id` = ? LIMIT 1');
$stmt->execute(array($id ?: 0));
$table = $stmt->fetch();
if (!$table || !$table['is_active']) { http_response_code(404); exit('Masa bulunamadı veya kullanım dışı.'); }
$origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
$url = $origin . QR_BASE . '?table=' . $table['token'];
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= qr_e($table['label']) ?> QR kartı</title><style>@page{size:A6 portrait;margin:0}*{box-sizing:border-box}body{margin:0;background:#ddd;font-family:Arial,sans-serif;color:#17352a}.toolbar{text-align:center;padding:15px}.card{width:105mm;height:148mm;margin:auto;background:#faf8f1;border:8mm solid #17352a;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:6mm}.card img{width:23mm}.card h1{font-size:24px;margin:4px 0 6px;}.card .sec-tag{display:inline-block;font-size:11px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;color:#856448;margin-top:6px;}.qr{width:55mm;height:55mm}.qr img,.qr canvas{width:100%;height:100%}.url{font-size:8px;overflow-wrap:anywhere}@media print{body{background:white}.toolbar{display:none}}</style><script src="<?= QR_BASE ?>assets/qrcode.min.js" defer></script><script src="<?= QR_BASE ?>assets/tables.js" defer></script></head><body><div class="toolbar"><button onclick="window.print()">Yazdır / PDF kaydet</button></div><main class="card"><img src="<?= QR_BASE ?>assets/logo.webp" alt="LAZE"><?php if (!empty($table['section'])): ?><span class="sec-tag"><?= qr_e($table['section']) ?></span><?php endif; ?><h1><?= qr_e($table['label']) ?></h1><p>Menüyü görmek ve garson çağırmak için QR kodu okutun.</p><div class="qr" data-qr="<?= qr_e($url) ?>"></div><p class="url"><?= qr_e($url) ?></p></main></body></html>
