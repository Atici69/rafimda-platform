<?php
// Rafi sohbet uç noktası: yalnızca POST + CSRF; oturum başına hız sınırı; yanıt yalnızca katalog verisi.
if (!defined('IN_SCRIPT')) {
	exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$send = static function (array $data, int $code = 200): void {
	http_response_code($code);
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
	exit;
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
	$send(['success' => false, 'message' => 'Yalnızca POST'], 405);
}

if ((string) Settings::get('RAFI_ENABLED') === '0') {
	$send(['success' => false, 'message' => 'Rafi şu anda kapalı.'], 503);
}

if (!class_exists('Security') || !Security::validateCsrf('front')) {
	$send(['success' => false, 'message' => 'Oturum süresi doldu; sayfayı yenileyin.'], 419);
}

// Hız sınırı: oturum başına dakikada 20 mesaj
$now = time();
$hits = array_values(array_filter((array) ($_SESSION['rafi_hits'] ?? []), static fn($t) => (int) $t > $now - 60));

if (count($hits) >= 20) {
	$send(['success' => false, 'message' => 'Çok hızlı mesaj gönderiyorsunuz; lütfen biraz bekleyin.'], 429);
}

$hits[] = $now;
$_SESSION['rafi_hits'] = $hits;

$message = (string) ($_POST['message'] ?? '');
$last = array_values(array_filter(array_map('intval', (array) ($_SESSION['rafi_last'] ?? []))));

try {
	$r = RafiBot::reply($message, $last);
} catch (Throwable $e) {
	error_log('[rafimda-rafi] ' . $e->getMessage());
	$send(['success' => false, 'message' => 'Şu anda yanıt veremiyorum; lütfen tekrar deneyin.'], 500);
}

$_SESSION['rafi_last'] = array_slice(array_map('intval', $r['ids']), 0, 10);
unset($r['ids']);
$send(['success' => true] + $r);
