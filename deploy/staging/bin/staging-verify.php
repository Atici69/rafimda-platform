<?php

/**
 * Kurulum doğrulama: şema, migration durumu, çekirdek bileşenler, PHP, yapılandırma.
 * Kullanım: php deploy/staging/bin/staging-verify.php [--out=logs/staging-verify.md]
 * Çıkış kodu: 0 = başarısız kontrol yok, 1 = en az bir FAIL.
 */
require __DIR__ . '/_common.php';

$env = staging_guard_or_exit();
$pdo = staging_connect_or_exit($env);
$root = StagingModules::root();
$v = new StagingVerifier($root);

$v->checkPhp();
$v->checkCoreFiles();
$cfgFile = $root . '/config/env.php';
$cfg = is_file($cfgFile) ? (static function (string $f) {
	$r = require $f;

	return is_array($r) ? $r : null;
})($cfgFile) : null;
$v->checkEnvConfig($cfg, $env['DB_NAME']);
$v->checkRobots();
$v->checkSchema(StagingSchemaReader::mysql($pdo), static function (string $tracker) use ($pdo): array {
	return (new StagingMigrator($pdo))->applied($tracker);
});

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
