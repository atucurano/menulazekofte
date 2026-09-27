<?php
require_once __DIR__ . '/inc/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$db = qr_db();
$count = qr_check_overdue_waiter_calls($db);
echo json_encode(array(
    'ok' => true,
    'overdue_reminded_count' => $count,
    'timestamp' => date('Y-m-d H:i:s')
), JSON_UNESCAPED_UNICODE);
