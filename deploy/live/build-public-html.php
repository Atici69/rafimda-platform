<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * public_html'e ELLE yüklenecek TAM Rafımda paketini üretir (iç klasör YOK: zip açılınca index.php doğrudan köktedir).
 * Kaynak: yalnızca GİT'TE İZLENEN dosyalar (`git archive HEAD`) → env.php, .env, yedek, log, .git, önbellek pakete GİREMEZ.
 * Hariç: geliştirme belgeleri/araçları, testler, deploy/ (staging/canlı araçları), demo görselleri ve önbellek kalıntıları.
 * Doğrulama: zip açılır, zorunlu dosyalar var, yasaklı kalıplar yok; SHA-256 ve dosya listesi yazılır.
 * Bu betik HİÇBİR sunucuya bağlanmaz ve dosya göndermez.
 * Kullanım: php deploy/live/build-public-html.php ÇIKTI_DİZİNİ [SÜRÜM]
 */
date_default_timezone_set('Europe/Istanbul');
$root = dirname(__DIR__, 2);
$outDir = rtrim($argv[1] ?? '', '/');
$version = $argv[2] ?? '1.0.0-rc1';

if ($outDir === '' || !is_dir($outDir)) {
	fwrite(STDERR, "Kullanım: php deploy/live/build-public-html.php ÇIKTI_DİZİNİ [SÜRÜM]\n");
	exit(1);
}

$git = static fn(string $c): string => trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && ' . $c . ' 2>/dev/null'));
$commit = $git('git rev-parse HEAD');

if ($commit === '') {
	fwrite(STDERR, "Git deposu bulunamadı.\n");
	exit(2);
}

if ($git('git status --porcelain --untracked-files=no') !== '') {
	fwrite(STDERR, "UYARI: commit edilmemiş değişiklik var; paket yalnızca COMMIT'teki hâli içerir.\n");
}

// deploy/: yalnızca canlı bakım araçları kalır (salt-okunur ön kontrol, dry-run migration, doğrulama; web'e kapalı, yalnızca CLI)
const EXCLUDE = [
	'docs', 'tools', 'deploy/preview', 'deploy/staging/bin', 'deploy/staging/conf', 'deploy/staging/lib/StagingSeed.php', 'deploy/staging/lib/StagingGuard.php',
	'deploy/live/build-release.php', 'deploy/live/build-public-html.php', 'AGENTS.md', 'AI_CONTEXT.md', 'CLAUDE.md', 'README.md', '.gitignore', 'nginx.conf.example',
	'cache/admin_dash_error.html', 'cache/google_shopping_feed.xml',
];
const EXCLUDE_GLOB = [
	'modules/*/tests/**', 'deploy/*/tests/**', 'tmp/pdfs/**', 'img/category/*.png', 'img/category/*.jpg', 'img/personalizations/*.jpg', 'img/theme-builder/*.png', '**/.DS_Store',
];
const REQUIRED = [
	'index.php', '.htaccess', 'admin/index.php', 'api/module.php', 'config/settings.php', 'config/.htaccess', 'config/env.example.php', 'core/Installer.php',
	'install/schema.sql', 'libs/Smarty.class.php', 'templates/theme4/header.tpl', 'img/logo.png', 'img/favicon.ico', 'img/products/index.php',
	'modules/rafimda-theme/rafimda-theme.php', 'modules/rafimda-vendor/rafimda-vendor.php', 'modules/rafimda-rafi/rafimda-rafi.php',
	'modules/rafimda-rafi/assets/img/rafi.svg', 'modules/rafimda-legal/rafimda-legal.php', 'modules/rafimda-showcase/rafimda-showcase.php',
	'modules/rafimda-campaign/rafimda-campaign.php', 'modules/rafimda-invoice/rafimda-invoice.php', 'modules/rafimda-social/rafimda-social.php',
	'modules/rafimda-dealer/rafimda-dealer.php', 'modules/xml-hub/xml-hub.php', 'modules/marketplace-hub/marketplace-hub.php',
	'modules/rafimda-vendor/migrations/007_engagement.sql', 'config/RAFIMDA_SURUM.txt', 'deploy/.htaccess', 'deploy/live/bin/live-preflight.php',
	'deploy/live/bin/live-migrate.php', 'deploy/live/bin/live-verify.php', 'deploy/staging/lib/StagingMigrator.php',
];
const FORBIDDEN = [
	'#(^|/)\.git(/|$)#', '#(^|/)\.env(\.|$)#', '#^config/env\.php$#', '#^config/installed\.lock$#', '#(^|/)tests?/#', '#^deploy/(preview|staging/(bin|conf))/#', '#^deploy/staging/lib/StagingSeed#', '#^docs/#', '#^tools/#',
	'#\.(sql\.gz|bak|zip|tar|tgz|log)$#i', '#^backups/(?!\.htaccess$)#', '#^cache/(force|cache)/#', '#^storage/rafimda/#', '#^img/rafimda/#',
];

$date = date('Y-m-d H:i');
$stamp = date('Ymd');
$name = 'rafimda-public_html-' . $version . '-' . $stamp . '-' . substr($commit, 0, 7) . '.zip';
$zip = $outDir . '/' . $name;
@unlink($zip);

$spec = '';

foreach (EXCLUDE as $e) {
	$spec .= ' ' . escapeshellarg(':(exclude)' . $e);
}

foreach (EXCLUDE_GLOB as $e) {
	$spec .= ' ' . escapeshellarg(':(exclude,glob)' . $e);
}

$tmp = sys_get_temp_dir() . '/rf-pub-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp) . ' && mkdir -p ' . escapeshellarg($tmp) . ' && cd ' . escapeshellarg($root) . ' && git archive --format=tar HEAD -- .' . $spec . ' | tar -x -C ' . escapeshellarg($tmp), $o, $rc);

if ($rc !== 0) {
	fwrite(STDERR, "git archive başarısız\n");
	exit(3);
}

$modules = [];

foreach (glob($tmp . '/modules/rafimda-*/rafimda-*.php') ?: [] as $f) {
	if (preg_match("/VERSION = '([^']+)'|public string \\\$version = '([^']+)'/", (string) file_get_contents($f), $m)) {
		$modules[basename(dirname($f))] = $m[1] !== '' ? $m[1] : $m[2];
	}
}

ksort($modules);
$info = "RAFIMDA – public_html KURULUM PAKETİ\n"
	. "Sürüm        : $version\n"
	. "Hazırlanma   : $date (" . date_default_timezone_get() . ")\n"
	. "Git commit   : $commit\n"
	. "Altyapı      : FriSay V3.0.2 tabanlı\n"
	. "Rafımda modülleri:\n" . implode('', array_map(static fn($k, $v) => "  - $k $v\n", array_keys($modules), $modules))
	. "\nBu paket GİZLİ BİLGİ İÇERMEZ (config/env.php, .env, şifre, API anahtarı, yedek, .git yoktur).\n"
	. "Kurulum ve güncelleme adımları: RAFIMDA_A_DAN_Z_YE_KULLANIM_KILAVUZU.txt (paketle birlikte ayrı teslim edilir).\n"
	. "ÖNEMLİ: Paketi yüklemeden önce mevcut sitenin TAM yedeğini (dosyalar + veritabanı) alın.\n";
file_put_contents($tmp . '/config/RAFIMDA_SURUM.txt', $info); // config/ web erişimine kapalıdır (commit/sürüm bilgisi dışarı sızmaz)

exec('cd ' . escapeshellarg($tmp) . ' && zip -q -r -X -9 ' . escapeshellarg($zip) . ' . -x "*.DS_Store"', $o2, $rc2);
exec('rm -rf ' . escapeshellarg($tmp));

if ($rc2 !== 0 || !is_file($zip)) {
	fwrite(STDERR, "zip oluşturulamadı\n");
	exit(4);
}

// Doğrulama
exec('unzip -tq ' . escapeshellarg($zip), $t, $rcT);
exec('unzip -Z1 ' . escapeshellarg($zip), $list);
$list = array_values(array_filter($list, static fn($l) => substr($l, -1) !== '/'));
$set = array_flip($list);
$missing = array_values(array_filter(REQUIRED, static fn($r) => !isset($set[$r])));
$bad = array_values(array_filter($list, static function ($l) {
	foreach (FORBIDDEN as $re) {
		if (preg_match($re, $l)) {
			return true;
		}
	}

	return false;
}));
$topDirs = array_unique(array_map(static fn($l) => explode('/', $l)[0], $list));
$nested = isset($set['index.php']) ? false : true;
file_put_contents($zip . '.files.txt', implode("\n", $list) . "\n");
$sha = hash_file('sha256', $zip);
file_put_contents($zip . '.sha256', $sha . '  ' . $name . "\n");

echo "Paket   : $zip\nSürüm   : $version\nTarih   : $date\nCommit  : $commit\nSHA-256 : $sha\nBoyut   : " . number_format(filesize($zip) / 1048576, 2) . " MB\nDosya   : " . count($list) . "\n";
echo 'Arşiv testi (unzip -t): ' . ($rcT === 0 ? 'GEÇTİ' : 'BAŞARISIZ') . "\n";
echo 'İç klasör yok (index.php kökte): ' . ($nested ? 'HAYIR' : 'EVET') . "\n";
echo 'Zorunlu dosyalar: ' . ($missing === [] ? 'tamam (' . count(REQUIRED) . ')' : 'EKSİK: ' . implode(', ', $missing)) . "\n";
echo 'Yasaklı içerik: ' . ($bad === [] ? 'yok' : 'VAR: ' . implode(', ', array_slice($bad, 0, 10))) . "\n";
echo 'Kök klasörler: ' . implode(' ', $topDirs) . "\n";
exit($rcT === 0 && !$nested && $missing === [] && $bad === [] ? 0 : 5);
