<?php
/**
 * Zamanlanmış içerik kontrolü (CLI). Örnek cron (her 5 dk): php /home/KULLANICI/public_html/modules/rafimda-social/cron.php
 * Otomatik yayın kapalıyken yalnızca yayın öncesi kontrol yapar (stok/fiyat/ürün durumu) ve içeriği bekletir; hiçbir hesaba paylaşım yapmaz.
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

// FriSay açılışı (config/settings.php) CLI'da: web değişkenleri güvenli varsayılanlarla doldurulur
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = (string) (getenv('RAFIMDA_SITE_HOST') ?: 'localhost');
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('IN_SCRIPT', true);
require_once dirname(__DIR__, 2) . '/config/settings.php';

if (!class_exists('Module') || !Module::isEnabled('rafimda-social')) {
	fwrite(STDERR, "rafimda-social kurulu/etkin değil.\n");
	exit(1);
}

require_once __DIR__ . '/rafimda-social.php';
$s = RsoPlanner::runDue();
echo date('c') . ' rafimda-social: ' . json_encode($s) . (RsoPlanner::publishEnabled() ? '' : ' (otomatik yayın KAPALI)') . "\n";
