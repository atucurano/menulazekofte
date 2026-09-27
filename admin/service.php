<?php
require_once dirname(__DIR__) . '/inc/app.php';
header('Cache-Control: no-store');
qr_admin_required();
$db = qr_db();
qr_ensure_service_schema($db);
$serviceUser = qr_service_identity($db);
require dirname(__DIR__) . '/inc/service-view.php';
