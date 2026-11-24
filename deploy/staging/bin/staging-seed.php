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

// TEST olmayan sipariş varsa (gerçek veri olabilir) reddet. Bunu aşan bir bayrak YOKTUR.
$real = StagingSeed::realOrderCount($pdo);

if ($real > 0) {
	staging_err("REDDEDİLDİ — veritabanında TEST olmayan $real sipariş var; gerçek veri olabilir. Staging veritabanının canlıdan bağımsız olduğundan emin olun.");
	exit(2);
}

staging_boot_core_or_exit($env);

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
