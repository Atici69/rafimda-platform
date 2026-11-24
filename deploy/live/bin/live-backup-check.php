<?php

/**
 * Yedek dosyası denetimi (SALT OKUNUR; DB'ye bağlanmaz, dosyaya dokunmaz, içerik basmaz).
 * Kullanım:  php deploy/live/bin/live-backup-check.php /tam/yol/yedek.sql[.gz]  [/tam/yol/siteyedek.zip ...]
 */
require_once dirname(__DIR__) . '/lib/LiveGuard.php';
require_once dirname(__DIR__) . '/lib/LiveBackup.php';

if (!LiveGuard::isCli(PHP_SAPI, $_SERVER)) {
	http_response_code(404);
	exit;
}

$files = array_slice($argv, 1);

if ($files === []) {
	fwrite(STDERR, "Kullanım: php live-backup-check.php /yol/yedek.sql [/yol/site.zip ...]\n");
	exit(2);
}

$bad = 0;

foreach ($files as $f) {
	$r = LiveBackup::inspect($f);
	echo "\n== " . basename($f) . ' (' . ($r['kind'] ?: '?') . ") ==\n";
	echo '  boyut: ' . number_format($r['size']) . ' bayt' . ($r['mtime'] ? ', tarih: ' . date('Y-m-d H:i', $r['mtime']) : '') . "\n";
	echo '  sha256: ' . ($r['sha256'] ?: '-') . "\n";

	if ($r['kind'] === 'zip') {
		echo '  dosya sayısı: ' . $r['entries'] . "\n";
	} elseif ($r['kind'] !== '') {
		echo '  tablo: ' . count($r['tables']) . ', INSERT satırı: ' . $r['entries'] . "\n";
	}

	foreach ($r['errors'] as $e) {
		echo "  [HATA] $e\n";
	}

	foreach ($r['warnings'] as $w) {
		echo "  [UYARI] $w\n";
	}

	foreach ($r['notes'] as $n) {
		echo "  [BİLGİ] $n\n";
	}

	echo '  SONUÇ: ' . ($r['ok'] ? 'kullanılabilir görünüyor (gerçek geri yükleme denemesi değildir)' : 'KULLANILAMAZ/ŞÜPHELİ') . "\n";
	$bad += $r['ok'] ? 0 : 1;
}

exit($bad ? 1 : 0);
