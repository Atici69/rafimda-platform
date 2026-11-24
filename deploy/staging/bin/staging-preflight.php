<?php

/**
 * Staging veritabanı izolasyon kontrolü (SALT OKUNUR: yalnızca SELECT/SHOW). Hiçbir tablo/veri oluşturmaz veya değiştirmez.
 * Sorar: bu staging kullanıcısı yalnızca staging DB'ye mi yetkili? Canlı DB'yi görebiliyor mu? Bağlı DB gerçekten beklenen mi?
 * Kullanım:  (staging ortam değişkenleri yüklüyken)  php deploy/staging/bin/staging-preflight.php
 */
require __DIR__ . '/_common.php';

$env = staging_guard_or_exit();
$pdo = staging_connect_or_exit($env);

$db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$grants = array_map('strval', $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
$dbs = array_map('strval', $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN));

staging_out('Bağlı veritabanı: ' . $db . ' (beklenen: ' . $env['DB_NAME'] . ')');
staging_out('Görülebilen veritabanı sayısı: ' . count($dbs));

$f = StagingGuard::isolationFindings($grants, $dbs, $db, $env['RAFIMDA_PROD_DB_NAME']);

if (strtolower($db) !== strtolower($env['DB_NAME'])) {
	$f[] = 'Bağlanılan veritabanı DB_NAME ile uyuşmuyor.';
}

foreach (['users', 'orders', 'products'] as $t) {
	try {
		staging_out('  ' . $t . ': ' . (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn() . ' kayıt');
	} catch (PDOException $e) {
		staging_out('  ' . $t . ': tablo yok (çekirdek henüz kurulmamış olabilir)');
	}
}

if ($f === []) {
	staging_out("\nSONUÇ: izolasyon bulgusu yok (yalnızca bu veritabanına yetkili; canlı DB görünmüyor).");
	exit(0);
}

staging_err("\nSONUÇ: İZOLASYON SORUNU — staging araçlarını (migrate/seed) çalıştırmayın:");

foreach ($f as $x) {
	staging_err('  - ' . StagingGuard::scrub($x, $env));
}

exit(1);
