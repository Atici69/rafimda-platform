<?php
/** Gönderim kuyruğu: /pazaryeri-cron?q=cron&key=ANAHTAR (q=cron sayfa önbelleğini devre dışı bırakır). Yanlış anahtar 403. */
if (!defined('IN_SCRIPT')) {
	exit;
}
$skipPageRender = true;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$mhKey = (string) Settings::get('MARKETPLACE_HUB_CRON_KEY');

if ($mhKey === '' || !hash_equals($mhKey, (string) ($_GET['key'] ?? ''))) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'forbidden']);
	exit;
}

if (MhMigrator::pending() !== []) {
	http_response_code(503);
	echo json_encode(['ok' => false, 'error' => 'migration pending']);
	exit;
}

@set_time_limit(60);
echo json_encode(['ok' => true] + MhDispatcher::tick(20));
