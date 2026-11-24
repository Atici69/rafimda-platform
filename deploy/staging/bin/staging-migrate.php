<?php

/**
 * Rafımda modül migration'larını sırayla çalıştırır. Hata durumunda o noktada durur (çıkış kodu 1).
 * Seçenekler: --dry-run (hiçbir şey yazmaz), --status (bekleyenleri listeler).
 * Kullanım: php deploy/staging/bin/staging-migrate.php [--dry-run|--status]
 */
require __DIR__ . '/_common.php';

$dry = in_array('--dry-run', $argv, true) || in_array('--status', $argv, true);
$env = staging_guard_or_exit();
$pdo = staging_connect_or_exit($env);
$mig = new StagingMigrator($pdo);
$fail = false;

staging_out('Veritabanı: ' . $env['DB_NAME'] . ' @ ' . $env['DB_HOST'] . ($dry ? '  [DRY-RUN: değişiklik yapılmaz]' : ''));

foreach (StagingModules::all() as $m) {
	staging_out("\n== " . $m['key']);
	$r = $mig->runModule($m, $dry);

	foreach ($r['steps'] as $s) {
		staging_out(sprintf('  [%-4s] %s — %s', $s['status'], $s['step'], $s['detail']));
	}

	if (!$r['success']) {
		$fail = true;
		staging_err('  >> ' . $m['key'] . ' başarısız; sonraki modüller çalıştırılmadı. Hatayı giderip komutu tekrar çalıştırın (idempotenttir).');
		break;
	}
}

staging_out($fail ? "\nSONUÇ: BAŞARISIZ" : "\nSONUÇ: " . ($dry ? 'DRY-RUN tamam' : 'tüm migration\'lar uygulandı'));
exit($fail ? 1 : 0);
