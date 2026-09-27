<?php
require_once __DIR__ . '/inc/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(array('error' => 'Yöntem geçersiz.'))); }
qr_check_csrf();
$db = qr_db();
qr_ensure_service_schema($db);
$settings = qr_settings($db);
if ($settings['waiter_call_enabled'] !== '1') { http_response_code(403); exit(json_encode(array('error' => 'Garson çağırma şu anda kapalı.'), JSON_UNESCAPED_UNICODE)); }

// Server-side GPS location verification
if (isset($settings['location_check_enabled']) && $settings['location_check_enabled'] === '1') {
    $userLat = isset($_POST['lat']) ? filter_var($_POST['lat'], FILTER_VALIDATE_FLOAT) : false;
    $userLng = isset($_POST['lng']) ? filter_var($_POST['lng'], FILTER_VALIDATE_FLOAT) : false;
    if ($userLat === false || $userLng === false) {
        http_response_code(403);
        exit(json_encode(array('error' => 'Garson çağırmak için konum doğrulaması gereklidir.'), JSON_UNESCAPED_UNICODE));
    }
    $restLat = isset($settings['restaurant_lat']) && is_numeric($settings['restaurant_lat']) ? (float)$settings['restaurant_lat'] : 40.9252987;
    $restLng = isset($settings['restaurant_lng']) && is_numeric($settings['restaurant_lng']) ? (float)$settings['restaurant_lng'] : 29.3113258;
    $maxDist = isset($settings['location_max_distance']) && is_numeric($settings['location_max_distance']) ? (float)$settings['location_max_distance'] : 150.0;

    $dist = qr_haversine_distance($userLat, $userLng, $restLat, $restLng);
    if ($dist > $maxDist) {
        http_response_code(403);
        exit(json_encode(array('error' => 'Garson çağırmak için restoranda olmalısınız. (Mesafe: ' . round($dist) . 'm)'), JSON_UNESCAPED_UNICODE));
    }
}
$table = qr_table_from_token($db, isset($_POST['table']) ? $_POST['table'] : '');
if (!$table) { http_response_code(404); exit(json_encode(array('error' => 'Masa bulunamadı.'))); }
try {
    $db->beginTransaction();
    // Lock the table row so simultaneous taps cannot create duplicate open calls.
    $lock = $db->prepare('SELECT `id` FROM `qr_tables` WHERE `id` = ? AND `is_active` = 1 FOR UPDATE');
    $lock->execute(array($table['id']));
    if (!$lock->fetch()) throw new RuntimeException('Masa kullanım dışı.');
    $open = $db->prepare("SELECT `id`, `status` FROM `qr_waiter_calls` WHERE `table_id` = ? AND `status` IN ('new','seen') ORDER BY `id` DESC LIMIT 1");
    $open->execute(array($table['id']));
    $existing = $open->fetch();
    if ($existing) {
        $result = array('ok' => true, 'already_open' => true, 'message' => 'Çağrınız görevliye iletildi.');
    } else {
        $insert = $db->prepare('INSERT INTO `qr_waiter_calls` (`table_id`) VALUES (?)');
        $insert->execute(array($table['id']));
        $result = array('ok' => true, 'already_open' => false, 'message' => 'Garson çağrıldı. Lütfen bekleyin.');
    }
    $db->commit();

    if (!$existing) {
        $tableLabel = !empty($table['label']) ? $table['label'] : 'Masa #' . $table['id'];
        $secName = !empty($table['section']) ? $table['section'] : 'Salon';
        $heading = '🚨 Garson Çağrısı: ' . $tableLabel;
        $content = $tableLabel . ' (' . $secName . ') personel bekliyor.';
        qr_send_onesignal_notification($heading, $content, 'https://menu.lazekofte.com/staff/', array(
            'table_id' => $table['id'],
            'table_label' => $tableLabel,
            'section' => $secName
        ));
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('QR waiter call: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(array('error' => 'Çağrı iletilemedi. Lütfen tekrar deneyin.'), JSON_UNESCAPED_UNICODE);
}
