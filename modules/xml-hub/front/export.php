<?php
/**
 * Satıcı XML beslemesi: /xml-export?t=GİZLİ_TOKEN&q=feed
 * Token yanlış/iptal ise 404 (varlık bilgisi sızdırılmaz). Çıktı akışlıdır, sayfa önbelleğine alınmaz.
 */
if (!defined('IN_SCRIPT')) {
	exit;
}
$skipPageRender = true;
header('Cache-Control: no-store');
$xhFeed = XhMigrator::pending() === [] ? XhExport::byToken((string) ($_GET['t'] ?? '')) : null;

if (!$xhFeed) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Not found';
	exit;
}

header('Content-Type: application/xml; charset=utf-8');
@set_time_limit(120);
XhExport::stream($xhFeed);
XhExport::hit((int) $xhFeed['id_feed']);
