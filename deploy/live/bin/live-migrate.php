<?php

/**
 * Rafımda modül tablolarını CANLI veritabanına ekler. VARSAYILAN DRY-RUN: --apply olmadan hiçbir şey yazılmaz.
 * Yalnızca CREATE TABLE IF NOT EXISTS ve korumalı ADD COLUMN; DROP/TRUNCATE/DELETE/RENAME reddedilir. FriSay çekirdek tablolarını OLUŞTURMAZ/DEĞİŞTİRMEZ.
 * --apply için: RAFIMDA_BACKUP_CONFIRM=EVET-YEDEK-ALINDI (önce veritabanı yedeği alın).
 * Kullanım:  php deploy/live/bin/live-migrate.php            (plan)
 *            php deploy/live/bin/live-migrate.php --apply    (uygular; ilk hatada durur)
 */
require __DIR__ . '/_common.php';

$apply = in_array('--apply', $argv, true);
[$cfg, $pdo] = live_guard_and_connect($apply);
$root = dirname(__DIR__, 3);
$sum = LiveGuard::summarize(static fn(string $t): ?int => live_count($pdo, $t));

if (!$sum['core_installed']) {
	live_err('REDDEDİLDİ — FriSay çekirdek tabloları yok. Önce kurulum sihirbazını çalıştırın; bu araç çekirdek tablo oluşturmaz.');
	exit(2);
}

live_out('Veritabanı: ' . $cfg['DB_NAME'] . ($apply ? '  [UYGULANIYOR]' : '  [DRY-RUN: değişiklik yapılmaz]'));
live_out('Mevcut veri sayıları: ' . json_encode($sum['counts']));

$mig = new StagingMigrator($pdo);
$fail = false;

foreach (StagingModules::all($root) as $m) {
	live_out("\n== " . $m['key']);
	$r = $mig->runModule($m, !$apply);

	foreach ($r['steps'] as $s) {
		live_out(sprintf('  [%-4s] %s — %s', $s['status'], $s['step'], $s['detail']));
	}

	if (!$r['success']) {
		$fail = true;
		live_err('  >> ' . $m['key'] . ' başarısız; durduruldu. Etkilenen: bu modülün tabloları (kısmen oluşmuş olabilir; komut yeniden çalıştırılabilir).');
		break;
	}
}

live_out($fail ? "\nSONUÇ: BAŞARISIZ" : "\nSONUÇ: " . ($apply ? 'uygulandı' : 'dry-run tamam (hiçbir şey yazılmadı)'));
exit($fail ? 1 : 0);
