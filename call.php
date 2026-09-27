<?php
require_once __DIR__ . '/inc/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(array('error' => 'Yöntem geçersiz.'))); }
qr_check_csrf();
$db = qr_db();
qr_ensure_service_schema($db);
if (qr_settings($db)['waiter_call_enabled'] !== '1') { http_response_code(403); exit(json_encode(array('error' => 'Garson çağırma şu anda kapalı.'), JSON_UNESCAPED_UNICODE)); }
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
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('QR waiter call: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(array('error' => 'Çağrı iletilemedi. Lütfen tekrar deneyin.'), JSON_UNESCAPED_UNICODE);
}
