<?php

/**
 * 3 TEST satıcısı, ürünleri, TEST müşterileri ve siparişleriyle veri izolasyonu / komisyon / kota kontrolleri.
 * Yalnızca EKLER; hiçbir kaydı silmez veya değiştirmez. Gerçek siparişler olan bir veritabanına karşı çalışmayı reddeder.
 * Kullanım: php deploy/staging/bin/staging-seed.php
 * Önkoşul: staging-migrate.php başarılı, config/env.php hazır.
 */
require __DIR__ . '/_common.php';

$env = staging_guard_or_exit();
$pdo = staging_connect_or_exit($env);
$root = StagingModules::root();

// TEST olmayan sipariş/kullanıcı varsa (gerçek veri olabilir) reddet.
$real = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE reference NOT LIKE 'TEST-%'")->fetchColumn();

if ($real > 0 && !in_array('--allow-existing-orders', $argv, true)) {
	staging_err("REDDEDİLDİ — veritabanında TEST olmayan $real sipariş var; gerçek veri olabilir. Staging veritabanının canlıdan bağımsız ve boş olduğundan emin olun.");
	exit(2);
}

// config/env.php başka bir veritabanını gösteriyorsa FriSay açılışı (ensureDefaults ayarları yazar) çalıştırılmaz.
$cfgFile = $root . '/config/env.php';
$cfg = is_file($cfgFile) ? require $cfgFile : null;

if (!is_array($cfg) || ($cfg['DB_NAME'] ?? '') !== $env['DB_NAME'] || ($cfg['DB_HOST'] ?? '') !== $env['DB_HOST']) {
	staging_err('REDDEDİLDİ — config/env.php (DB_HOST/DB_NAME) ortam değişkenleriyle uyuşmuyor veya yok. Önce staging-env.php çalıştırın.');
	exit(2);
}

// Gerçek FriSay açılışı (oturum, ayarlar, DB sınıfı, Settings/Tools/Customer vb.). CLI için gerekli sunucu değişkenleri verilir.
$siteHost = (string) parse_url((string) (getenv('RAFIMDA_SITE_URL') ?: 'https://test.rafimda.com.tr/'), PHP_URL_HOST);
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = $siteHost;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('IN_SCRIPT', true);
require_once $root . '/config/settings.php';

if (!defined('_DB_NAME_') || _DB_NAME_ !== $env['DB_NAME']) {
	staging_err('REDDEDİLDİ — FriSay açılışı beklenen veritabanına bağlanmadı.');
	exit(2);
}

foreach (['RvMigrator', 'RvPerm', 'RvAudit', 'RvVendor', 'RvContext', 'RvProducts', 'RvInventory', 'RvCustomers', 'RvPlans', 'RvLedger', 'RvOrders'] as $c) {
	require_once $root . '/modules/rafimda-vendor/lib/' . $c . '.php';
}

if (class_exists('ProductVariation')) {
	ProductVariation::ensureSchema();
}

$r = StagingSeed::run();

staging_out('Satıcılar: ' . json_encode($r['vendors']));

foreach ($r['log'] as $l) {
	staging_out('  ' . $l);
}

$fail = 0;
staging_out("\nİzolasyon / komisyon / kota kontrolleri:");

foreach ($r['checks'] as $c) {
	staging_out(sprintf('  [%s] %s', $c['ok'] ? 'OK  ' : 'FAIL', $c['name']));
	$fail += $c['ok'] ? 0 : 1;
}

staging_out("\nSONUÇ: " . count($r['checks']) . ' kontrol, ' . $fail . ' başarısız.');
exit($fail > 0 ? 1 : 0);
