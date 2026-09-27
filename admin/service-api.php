<?php
require_once dirname(__DIR__) . '/inc/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$db = qr_db();
qr_ensure_service_schema($db);
$identity = qr_service_identity($db);
if (!$identity) { http_response_code(401); exit(json_encode(array('error' => 'Oturum sona erdi.'))); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qr_check_csrf();
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if (!$id || !in_array($action, array('seen', 'done'), true)) { http_response_code(400); exit(json_encode(array('error' => 'İstek geçersiz.'))); }
    if ($action === 'seen') {
        $stmt = $db->prepare("UPDATE `qr_waiter_calls` SET `status` = 'seen', `acknowledged_at` = NOW(), `acknowledged_by` = ? WHERE `id` = ? AND `status` = 'new'");
        $stmt->execute(array($identity['display_name'], $id));
    } else {
        $stmt = $db->prepare("UPDATE `qr_waiter_calls` SET `status` = 'done', `resolved_at` = NOW() WHERE `id` = ? AND `status` IN ('new','seen')");
        $stmt->execute(array($id));
    }
    echo json_encode(array('ok' => true));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
$rows = $db->query("SELECT c.`id`, c.`status`, c.`created_at`, c.`acknowledged_by`, GREATEST(TIMESTAMPDIFF(SECOND, c.`created_at`, NOW()), 0) AS `wait_seconds`, t.`label` FROM `qr_waiter_calls` c JOIN `qr_tables` t ON t.`id` = c.`table_id` WHERE c.`status` IN ('new','seen') ORDER BY c.`id` ASC LIMIT 100")->fetchAll();
$latest = (int)$db->query('SELECT COALESCE(MAX(`id`), 0) FROM `qr_waiter_calls`')->fetchColumn();
echo json_encode(array('calls' => $rows, 'latest_id' => $latest), JSON_UNESCAPED_UNICODE);
