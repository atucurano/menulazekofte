<?php
require_once __DIR__ . '/inc/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
qr_check_csrf();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (!empty($_POST['website'])) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => true, 'message' => 'Geri bildiriminiz için teşekkür ederiz.'));
        exit;
    }
    qr_redirect('');
}

$rating = isset($_POST['rating']) && is_scalar($_POST['rating']) ? filter_var($_POST['rating'], FILTER_VALIDATE_INT) : false;
$comment = isset($_POST['comment']) && is_string($_POST['comment']) ? trim($_POST['comment']) : '';
if ($rating === false || $rating < 1 || $rating > 5 || mb_strlen($comment, 'UTF-8') > 1000) {
    $msg = 'Lütfen 1–5 arasında bir puan seçin ve yorumunuzu kontrol edin.';
    if ($isAjax) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'message' => $msg));
        exit;
    }
    qr_flash($msg, 'error');
    qr_redirect('');
}

$attempts = isset($_SESSION['qr_feedback_times']) && is_array($_SESSION['qr_feedback_times']) ? $_SESSION['qr_feedback_times'] : array();
$attempts = array_values(array_filter($attempts, function ($time) { return is_int($time) && $time > time() - 900; }));
if (count($attempts) >= 3) {
    $msg = 'Kısa sürede çok sayıda geri bildirim gönderildi. Bir süre sonra tekrar deneyin.';
    if ($isAjax) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'message' => $msg));
        exit;
    }
    qr_flash($msg, 'error');
    qr_redirect('');
}

try {
    $db = qr_db();
    qr_ensure_feedback_table($db);
    $stmt = $db->prepare('INSERT INTO `qr_menu_feedback` (`rating`, `comment`) VALUES (?, ?)');
    $stmt->execute(array($rating, $comment));
    $attempts[] = time();
    $_SESSION['qr_feedback_times'] = $attempts;
    $msg = 'Geri bildiriminiz için teşekkür ederiz.';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => true, 'message' => $msg));
        exit;
    }
    qr_flash($msg);
} catch (Exception $e) {
    error_log('QR menu feedback: ' . $e->getMessage());
    $msg = 'Geri bildirim kaydedilemedi. Lütfen daha sonra tekrar deneyin.';
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'message' => $msg));
        exit;
    }
    qr_flash($msg, 'error');
}
qr_redirect('');
