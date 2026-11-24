<?php
/**
 * Zamanlanmış görev uç noktası. Sunucu cron'u şu adresi çağırmalıdır: /xml-cron?q=cron&key=GİZLİ_ANAHTAR (q parametresi çekirdek sayfa önbelleğini devre dışı bırakır)
 * Anahtar yönetim → XML Hub ekranında gösterilir. Yanlış/eksik anahtar 403 verir ve hiçbir şey çalıştırmaz.
 */
if (!defined('IN_SCRIPT')) {
	exit;
}
$skipPageRender = true;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$xhKey = (string) Settings::get('XML_HUB_CRON_KEY');
$xhGiven = (string) ($_GET['key'] ?? '');

if ($xhKey === '' || !hash_equals($xhKey, $xhGiven)) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'forbidden']);
	exit;
}

if (XhMigrator::pending() !== []) {
	http_response_code(503);
	echo json_encode(['ok' => false, 'error' => 'migration pending']);
	exit;
}

@set_time_limit(60);
$xhRes = XhRunner::tick(25, 2000);
echo json_encode(['ok' => true] + $xhRes);
