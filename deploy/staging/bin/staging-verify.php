<?php

/**
 * Kurulum doğrulama: şema, migration durumu, çekirdek bileşenler, PHP, yapılandırma.
 * Kullanım: php deploy/staging/bin/staging-verify.php [--init-lazy] [--out=logs/staging-verify.md]
 * --init-lazy: çekirdeğin tembel tablolarını (product_variations) oluşturur ve yoksa FAIL verir.
 * Çıkış kodu: 0 = başarısız kontrol yok, 1 = en az bir FAIL.
 */
require __DIR__ . '/_common.php';

$env = staging_guard_or_exit();
$pdo = staging_connect_or_exit($env);
$root = StagingModules::root();
$v = new StagingVerifier($root);
$initLazy = in_array('--init-lazy', $argv, true);

if ($initLazy) {
	// Çekirdeğin tembel tablolarını (ProductVariation vb.) oluşturur: CREATE TABLE IF NOT EXISTS; veri silmez.
	staging_boot_core_or_exit($env);

	if (class_exists('ProductVariation')) {
		ProductVariation::ensureSchema();
	}
}


$v->checkPhp();
$v->checkCoreFiles();
$cfgFile = $root . '/config/env.php';
$cfg = is_file($cfgFile) ? (static function (string $f) {
	$r = require $f;

	return is_array($r) ? $r : null;
})($cfgFile) : null;
$v->checkEnvConfig($cfg, $env['DB_NAME']);
$v->checkSecretFilePerms($cfgFile, is_file($cfgFile) ? (int) (fileperms($cfgFile) & 0777) : null);
$titles = [];
$mail = '';

try {
	$q = $pdo->query("SELECT title FROM settings WHERE value <> '' AND (title LIKE '%SMTP%' OR title LIKE '%SMS%' OR title LIKE '%NETGSM%' OR title LIKE '%IYZICO%' OR title LIKE '%PAYTR%' OR title LIKE '%PAYPAL%' OR title LIKE '%API_KEY%' OR title LIKE '%SECRET%' OR title LIKE '%PASSWORD%' OR title LIKE '%TOKEN%')");
	$titles = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
	$m = $pdo->query("SELECT value FROM settings WHERE title = 'MAIL_DRIVER'")->fetchColumn();
	$mail = $m === false ? '' : (string) $m;
} catch (PDOException $e) {
	// settings tablosu yoksa checkSchema zaten FAIL verir
}

$v->checkExternalServices($titles, $mail);
$v->checkRobots();
$v->checkSchema(StagingSchemaReader::mysql($pdo), static function (string $tracker) use ($pdo): array {
	return (new StagingMigrator($pdo))->applied($tracker);
}, $initLazy);

staging_out($v->toText());

foreach ($argv as $a) {
	if (strpos($a, '--out=') === 0) {
		$out = substr($a, 6);
		$path = $out[0] === '/' ? $out : $root . '/' . $out;
		file_put_contents($path, $v->toMarkdown());
		staging_out('Rapor yazıldı: ' . $path);
	}
}

exit($v->failures() > 0 ? 1 : 0);
