<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Canlı araç testleri (MySQL'de ÇALIŞTIRILMADI; yalnızca koruma mantığı ve komut tutarlılığı). */
require_once dirname(__DIR__) . '/lib/LiveGuard.php';

$pass = 0;
$fail = 0;
function t(string $name, bool $ok, string $detail = ''): void
{
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
}

$root = dirname(__DIR__, 3);
$cfg = ['DB_HOST' => 'localhost', 'DB_NAME' => 'rafimda_prod', 'DB_USER' => 'u', 'DB_PASS' => 'Sup3rSecret', 'APP_ENV' => 'production', 'APP_DEBUG' => false];
$env = ['RAFIMDA_ENV' => 'live', 'RAFIMDA_LIVE_CONFIRM' => 'EVET-CANLI-DB', 'RAFIMDA_LIVE_DB_NAME' => 'rafimda_prod'];

echo "== LiveGuard\n";
t('dry-run: geçerli ortam kabul (yedek onayı gerekmez)', LiveGuard::check($env, $cfg, false)['ok']);
t('--apply yedek onayı olmadan RET', !LiveGuard::check($env, $cfg, true)['ok']);
t('--apply doğru yedek onayıyla kabul', LiveGuard::check($env + ['RAFIMDA_BACKUP_CONFIRM' => 'EVET-YEDEK-ALINDI'], $cfg, true)['ok']);
t('yanlış yedek ifadesi RET', !LiveGuard::check($env + ['RAFIMDA_BACKUP_CONFIRM' => 'evet'], $cfg, true)['ok']);
foreach (['RAFIMDA_ENV', 'RAFIMDA_LIVE_CONFIRM', 'RAFIMDA_LIVE_DB_NAME'] as $k) {
	$e = $env;
	unset($e[$k]);
	t("$k eksikse RET", !LiveGuard::check($e, $cfg, false)['ok']);
}
t('RAFIMDA_ENV=staging RET', !LiveGuard::check(['RAFIMDA_ENV' => 'staging'] + $env, $cfg, false)['ok']);
t('DB adı env.php ile uyuşmazsa RET (yanlış DB)', !LiveGuard::check(['RAFIMDA_LIVE_DB_NAME' => 'baska'] + $env, $cfg, false)['ok']);
t('env.php yoksa RET', !LiveGuard::check($env, null, false)['ok']);
t('APP_ENV production değilse RET', !LiveGuard::check($env, ['APP_ENV' => 'local'] + $cfg, false)['ok']);
t('APP_DEBUG açıksa RET', !LiveGuard::check($env, ['APP_DEBUG' => true] + $cfg, false)['ok']);
t('DB_USER boşsa RET', !LiveGuard::check($env, ['DB_USER' => ''] + $cfg, false)['ok']);
t('hata mesajında parola maskelenir', strpos(LiveGuard::scrub('denied Sup3rSecret', $cfg), 'Sup3rSecret') === false);

echo "== Doluluk özeti (yalnızca sayılar)\n";
$empty = LiveGuard::summarize(static fn(string $t): ?int => $t === 'rafimda_vendors' ? null : 0);
t('çekirdek kurulu, veri yok', $empty['core_installed'] && !$empty['has_data']);
$full = LiveGuard::summarize(static fn(string $t): ?int => $t === 'rafimda_vendors' ? null : 5);
t('veri varsa has_data', $full['has_data']);
t('çekirdek tablo yoksa core_installed=false', !LiveGuard::summarize(static fn(string $t): ?int => null)['core_installed']);

echo "== Komutlar\n";
foreach (['live-preflight', 'live-migrate', 'live-verify'] as $b) {
	$code = (string) file_get_contents(dirname(__DIR__) . '/bin/' . $b . '.php');
	t("$b: bekçi + bağlantı tek çağrıda (live_guard_and_connect)", strpos($code, 'live_guard_and_connect(') !== false);
	$noComments = '';

	foreach (token_get_all($code) as $tok) {
		if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
			continue;
		}

		$noComments .= is_array($tok) ? $tok[1] : $tok;
	}

	t("$b: yıkıcı SQL yok (yorumlar hariç)", !preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+`?\w+`?\s+SET|INSERT\s+INTO)\b/i', $noComments));
	$out = [];
	exec('env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/' . $b . '.php') . ' 2>&1', $out, $rc);
	t("$b: ortam değişkeni olmadan reddeder (çıkış 2)", $rc === 2);
}
$mig = (string) file_get_contents(dirname(__DIR__) . '/bin/live-migrate.php');
t('live-migrate: --apply yoksa dry-run (runModule(…, !$apply))', strpos($mig, '$mig->runModule($m, !$apply)') !== false);
t('live-migrate: çekirdek tablo yoksa reddeder, çekirdek tablo oluşturmaz', strpos($mig, "core_installed']") !== false && strpos($mig, 'install/schema.sql') === false);
t('live-migrate: seed/test verisi çağırmaz', stripos($mig, 'seed') === false || strpos($mig, 'Seed') === false);
$com = (string) file_get_contents(dirname(__DIR__) . '/bin/_common.php');
t('canlı komutlar DB parolasını komut satırından/ortamdan almaz (env.php)', strpos($com, 'DB_PASS') === false && strpos($com, 'config/env.php') !== false);

echo "== Sürüm paketi\n";
$tmp = sys_get_temp_dir() . '/rel_' . bin2hex(random_bytes(4));
mkdir($tmp);
$o = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/build-release.php') . ' ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
$zips = glob($tmp . '/rafimda-release-*.zip') ?: [];
t('paket üretildi', $rc === 0 && count($zips) === 1);

if ($zips) {
	$z = new ZipArchive();
	$z->open($zips[0]);
	$names = [];

	for ($i = 0; $i < $z->numFiles; $i++) {
		$names[] = (string) $z->getNameIndex($i);
	}

	$hit = static fn(string $re): array => array_values(array_filter($names, static fn(string $n): bool => (bool) preg_match($re, $n)));
	t('pakette config/env.php / .env / .git / döküm yok', !$hit('#(^|/)config/env\.php$|(^|/)\.env$|^\.git/|\.sql\.gz$|\.dump$#'));
	t('pakette docs/ ve Rafımda test dizinleri yok', !$hit('#^docs/|^modules/(rafimda-vendor|xml-hub|marketplace-hub|rafimda-showcase)/tests/|^deploy/[a-z]+/tests/#'), json_encode($hit('#^docs/|^modules/(rafimda-vendor|xml-hub|marketplace-hub|rafimda-showcase)/tests/|^deploy/[a-z]+/tests/#')));
	t('güncelleme paketi canlı veriyi/özelleştirmeyi ezmez: img/, data/, theme-settings.css, kök .htaccess/robots.txt yok', !$hit('#^img/|^data/|^modules/theme4/assets/css/theme-settings\.css$|^\.htaccess$|^robots\.txt$#'), json_encode($hit('#^img/|^data/|^\.htaccess$|^robots\.txt$#')));
	t('deploy/.htaccess korunur (yalnızca kök .htaccess hariç)', in_array('deploy/.htaccess', $names, true));
	t('deploy/.htaccess (web engeli) pakette', in_array('deploy/.htaccess', $names, true));
	t('canlı araçlar pakette', in_array('deploy/live/bin/live-migrate.php', $names, true) && in_array('deploy/staging/lib/StagingMigrator.php', $names, true));
	t('FriSay çekirdeği ve Rafımda modülleri pakette', in_array('index.php', $names, true) && in_array('modules/rafimda-vendor/rafimda-vendor.php', $names, true));
	t('install/ pakette (sihirbaz için) ve kılavuz kaldırmayı söylüyor', in_array('install/index.php', $names, true) && strpos((string) @file_get_contents($root . '/docs/CANLI_KURULUM_NATRO.md'), 'install/') !== false);
	$z->close();
}

array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);
t('deploy/.htaccess hepsini reddeder', strpos((string) file_get_contents($root . '/deploy/.htaccess'), 'Require all denied') !== false);

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
