<?php
require_once __DIR__ . '/inc/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
qr_check_csrf();

if (!empty($_POST['website'])) qr_redirect('');
$rating = isset($_POST['rating']) && is_scalar($_POST['rating']) ? filter_var($_POST['rating'], FILTER_VALIDATE_INT) : false;
$comment = isset($_POST['comment']) && is_string($_POST['comment']) ? trim($_POST['comment']) : '';
if ($rating === false || $rating < 1 || $rating > 5 || mb_strlen($comment, 'UTF-8') > 1000) {
    qr_flash('Lütfen 1–5 arasında bir puan seçin ve yorumunuzu kontrol edin.', 'error');
    qr_redirect('');
}

$attempts = isset($_SESSION['qr_feedback_times']) && is_array($_SESSION['qr_feedback_times']) ? $_SESSION['qr_feedback_times'] : array();
$attempts = array_values(array_filter($attempts, function ($time) { return is_int($time) && $time > time() - 900; }));
if (count($attempts) >= 3) {
    qr_flash('Kısa sürede çok sayıda geri bildirim gönderildi. Bir süre sonra tekrar deneyin.', 'error');
    qr_redirect('');
}

try {
    $db = qr_db();
    qr_ensure_feedback_table($db);
    $stmt = $db->prepare('INSERT INTO `qr_menu_feedback` (`rating`, `comment`) VALUES (?, ?)');
    $stmt->execute(array($rating, $comment));
    $attempts[] = time();
    $_SESSION['qr_feedback_times'] = $attempts;
    qr_flash('Geri bildiriminiz için teşekkür ederiz.');
} catch (Exception $e) {
    error_log('QR menu feedback: ' . $e->getMessage());
    qr_flash('Geri bildirim kaydedilemedi. Lütfen daha sonra tekrar deneyin.', 'error');
}
qr_redirect('');
