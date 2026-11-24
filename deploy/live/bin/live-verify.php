<?php

/**
 * Canlı kurulum doğrulaması — YALNIZCA OKUR. Modül tablolarını/sütunlarını/indekslerini/AUTO_INCREMENT'i ve migration kayıtlarını denetler.
 * Kullanım: php deploy/live/bin/live-verify.php [--out=logs/live-verify.md]
 */
require __DIR__ . '/_common.php';

[$cfg, $pdo] = live_guard_and_connect(false);
$root = dirname(__DIR__, 3);
$v = new StagingVerifier($root);
$v->checkSecretFilePerms($root . '/config/env.php', (int) (fileperms($root . '/config/env.php') & 0777));
$v->checkEnvConfig($cfg, (string) $cfg['DB_NAME']);
$titles = [];
$mail = '';

try {
	$titles = array_map('strval', $pdo->query("SELECT title FROM settings WHERE value <> '' AND (title LIKE '%SMTP%' OR title LIKE '%SMS%' OR title LIKE '%NETGSM%' OR title LIKE '%IYZICO%' OR title LIKE '%PAYTR%' OR title LIKE '%PAYPAL%' OR title LIKE '%API_KEY%' OR title LIKE '%SECRET%' OR title LIKE '%PASSWORD%' OR title LIKE '%TOKEN%')")->fetchAll(PDO::FETCH_COLUMN));
	$m = $pdo->query("SELECT value FROM settings WHERE title = 'MAIL_DRIVER'")->fetchColumn();
	$mail = $m === false ? '' : (string) $m;
} catch (PDOException $e) {
}

live_out("Not: Canlıda dolu servis anahtarı UYARISI beklenir (ödeme/SMTP gerçekten yapılandırıldıysa); değerler asla yazdırılmaz.");
$v->checkExternalServices($titles, $mail);
$v->checkSchema(StagingSchemaReader::mysql($pdo), static function (string $tracker) use ($pdo): array {
	return (new StagingMigrator($pdo))->applied($tracker);
});
live_out($v->toText());

foreach ($argv as $a) {
	if (strpos($a, '--out=') === 0) {
		$out = substr($a, 6);
		$path = $out[0] === '/' ? $out : $root . '/' . $out;
		file_put_contents($path, $v->toMarkdown());
		live_out('Rapor yazıldı: ' . $path);
	}
}

exit($v->failures() > 0 ? 1 : 0);
