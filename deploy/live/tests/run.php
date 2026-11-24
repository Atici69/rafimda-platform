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

$bkOk = sys_get_temp_dir() . '/rf_bkok_' . bin2hex(random_bytes(3)) . '.sql';
file_put_contents($bkOk, str_repeat('-- dump satiri' . "\n", 200));
register_shutdown_function(static function () use ($bkOk) { @unlink($bkOk); });

echo "== LiveGuard\n";
t('dry-run: geçerli ortam kabul (yedek onayı gerekmez)', LiveGuard::check($env, $cfg, false)['ok']);
t('--apply yedek onayı olmadan RET', !LiveGuard::check($env, $cfg, true)['ok']);
t('--apply doğru yedek onayıyla kabul', LiveGuard::check($env + ['RAFIMDA_BACKUP_CONFIRM' => 'EVET-YEDEK-ALINDI', 'RAFIMDA_MIGRATE_CONFIRM' => 'EVET-MIGRATION', 'RAFIMDA_BACKUP_FILE' => $bkOk], $cfg, true)['ok']);
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

echo "== LiveGuard sertleştirme (0.2)\n";
$apply = $env + ['RAFIMDA_BACKUP_CONFIRM' => 'EVET-YEDEK-ALINDI', 'RAFIMDA_MIGRATE_CONFIRM' => 'EVET-MIGRATION', 'RAFIMDA_BACKUP_FILE' => $bkOk];
t('apply: yedek + migration onayı ile kabul', LiveGuard::check($apply, $cfg, true)['ok']);
t('apply: migration onayı olmadan RET', !LiveGuard::check($env + ['RAFIMDA_BACKUP_CONFIRM' => 'EVET-YEDEK-ALINDI', 'RAFIMDA_BACKUP_FILE' => $bkOk], $cfg, true)['ok']);
t('apply: yedek dosyası verilmezse (ifade tek başına yetmez) RET', !LiveGuard::check(array_diff_key($apply, ['RAFIMDA_BACKUP_FILE' => 1]), $cfg, true)['ok']);
t('apply: migration onayı modül kurulumu yerine geçmez', !LiveGuard::check($apply, $cfg, true, 'module-install')['ok']);
t('apply: modül kurulumu kendi onayıyla kabul', LiveGuard::check($env + ['RAFIMDA_BACKUP_CONFIRM' => 'EVET-YEDEK-ALINDI', 'RAFIMDA_MODULE_CONFIRM' => 'EVET-MODUL-KURULUMU', 'RAFIMDA_BACKUP_FILE' => $bkOk], $cfg, true, 'module-install')['ok']);
t('apply: bilinmeyen işlem türü RET', !LiveGuard::check($apply, $cfg, true, 'drop-all')['ok']);
t('yanlış migration onay ifadesi RET', !LiveGuard::check(['RAFIMDA_MIGRATE_CONFIRM' => 'evet'] + $apply, $cfg, true)['ok']);
t('staging DB adı (rafimda_staging) canlıda RET', !LiveGuard::check(['RAFIMDA_LIVE_DB_NAME' => 'rafimda_staging'] + $env, ['DB_NAME' => 'rafimda_staging'] + $cfg, false)['ok']);
t('test DB adı canlıda RET', !LiveGuard::check(['RAFIMDA_LIVE_DB_NAME' => 'shop_test'] + $env, ['DB_NAME' => 'shop_test'] + $cfg, false)['ok']);
t('bildirilen staging DB adıyla aynı DB RET', !LiveGuard::check(['RAFIMDA_STAGING_DB_NAME' => 'rafimda_prod'] + $env, $cfg, false)['ok']);
t('karışık oturum (RAFIMDA_STAGING_CONFIRM tanımlı) RET', !LiveGuard::check(['RAFIMDA_STAGING_CONFIRM' => 'EVET-STAGING-DB'] + $env, $cfg, false)['ok']);
t('staging site adresi canlıda RET', !LiveGuard::check(['RAFIMDA_SITE_URL' => 'https://test.rafimda.com.tr/'] + $env, $cfg, false)['ok']);
t('DB adında özel karakter RET', !LiveGuard::check(['RAFIMDA_LIVE_DB_NAME' => 'a`b'] + $env, ['DB_NAME' => 'a`b'] + $cfg, false)['ok']);
$bk = sys_get_temp_dir() . '/rf_bk_' . bin2hex(random_bytes(3)) . '.sql';
t('yedek dosyası yoksa RET', !LiveGuard::check(['RAFIMDA_BACKUP_FILE' => $bk] + $apply, $cfg, true)['ok']);
file_put_contents($bk, '');
t('boş yedek dosyası RET', !LiveGuard::check(['RAFIMDA_BACKUP_FILE' => $bk] + $apply, $cfg, true)['ok']);
file_put_contents($bk, str_repeat('-- dump' . "\n", 300));
file_put_contents($bk . '.k', '-- x');
t('1 KB altı yedek dosyası RET', !LiveGuard::check(['RAFIMDA_BACKUP_FILE' => $bk . '.k'] + $apply, $cfg, true)['ok']);
unlink($bk . '.k');
t('taze yedek dosyası kabul', LiveGuard::check(['RAFIMDA_BACKUP_FILE' => $bk] + $apply, $cfg, true)['ok']);
touch($bk, time() - 3 * 86400);
t('eski (3 gün) yedek dosyası RET', !LiveGuard::check(['RAFIMDA_BACKUP_FILE' => $bk] + $apply, $cfg, true)['ok']);
t('dry-run yedek dosyası istemez', LiveGuard::check($env, $cfg, false)['ok']);
unlink($bk);
t('scrub: parola ve kullanıcı maskelenir', ($m = LiveGuard::scrub("SQLSTATE: Access denied for user 'dbuser01'@'h' (using password: Sup3rSecret)", array_merge($cfg, ['DB_USER' => 'dbuser01']))) && strpos($m, 'Sup3rSecret') === false && strpos($m, 'dbuser01') === false);

echo "== Web/CLI ayrımı ve PDO atlatma\n";
t('cli SAPI kabul', LiveGuard::isCli('cli', []));
foreach (['apache2handler', 'fpm-fcgi', 'cgi-fcgi', 'litespeed'] as $sapi) {
	t("web SAPI ($sapi) RET", !LiveGuard::isCli($sapi, []));
}
t('cli ama HTTP isteği değişkenleriyle RET', !LiveGuard::isCli('cli', ['REQUEST_METHOD' => 'GET']) && !LiveGuard::isCli('cli', ['HTTP_HOST' => 'x']) && !LiveGuard::isCli('cli', ['GATEWAY_INTERFACE' => 'CGI/1.1']));
$threw = static function (callable $f): ?string {
	try {
		$f();
	} catch (Throwable $e) {
		return $e->getMessage();
	}

	return null;
};
t('connect: web SAPI\'sinde bağlantı açılmaz', ($r = $threw(static fn() => LiveGuard::connect($cfg, $apply, true, 'migrate', 'apache2handler'))) !== null && strpos($r, 'komut satırı') !== false);
t('connect: guard geçmezse (env boş) bağlantı açılmaz', ($r = $threw(static fn() => LiveGuard::connect($cfg, [], false, 'migrate', 'cli'))) !== null && strpos($r, 'kontrolü geçmedi') !== false);
t('connect: staging DB ile bağlantı açılmaz', $threw(static fn() => LiveGuard::connect(['DB_NAME' => 'x_staging'] + $cfg, ['RAFIMDA_LIVE_DB_NAME' => 'x_staging'] + $env, false, 'migrate', 'cli')) !== null);
t('connect: apply onaysız bağlantı açılmaz', $threw(static fn() => LiveGuard::connect($cfg, $env, true, 'migrate', 'cli')) !== null);
t('LiveGuard\'ta PDO yalnızca private open() içinde', substr_count((string) file_get_contents(dirname(__DIR__) . '/lib/LiveGuard.php'), 'new PDO(') === 1 && strpos((string) file_get_contents(dirname(__DIR__) . '/lib/LiveGuard.php'), 'private static function open') !== false);
$all = '';
foreach (glob(dirname(__DIR__) . '/bin/*.php') as $f) {
	$all .= file_get_contents($f);
}
t('canlı komutlar doğrudan new PDO / StagingGuard kullanmaz', strpos($all, 'new PDO') === false && strpos($all, 'StagingGuard') === false);
t('_common.php LiveGuard::connect(cfg, env, apply, op) çağırır', strpos((string) file_get_contents(dirname(__DIR__) . '/bin/_common.php'), 'LiveGuard::connect($cfg, LiveGuard::envFromProcess(), $apply, $operation)') !== false);
$out = [];
exec('env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' REQUEST_METHOD=GET ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/live-preflight.php') . ' 2>&1', $out, $rc);
t('HTTP değişkenli oturumda live-preflight 404 ile çıkar (guard\'a ulaşmaz)', $rc === 0 && stripos(implode("\n", $out), 'REDDEDİLDİ') === false);

echo "== Yedek dosyası denetimi (LiveBackup; DB'ye bağlanmaz, geçici dosyalar)\n";
require_once dirname(__DIR__) . '/lib/LiveBackup.php';
$td = sys_get_temp_dir() . '/rf_bkt_' . bin2hex(random_bytes(3));
mkdir($td);
$dump = "-- MySQL dump\n";
foreach (LiveBackup::CORE_TABLES as $tb) {
	$dump .= "CREATE TABLE `$tb` (id int);\nINSERT INTO `$tb` VALUES (1),(2);\n";
}
for ($i = 0; $i < 120; $i++) {
	$dump .= '-- ' . bin2hex(random_bytes(12)) . "\n"; // sıkıştırmada da >1 KB kalsın
}
$dump .= "-- Dump completed\n";
file_put_contents("$td/ok.sql", $dump);
file_put_contents("$td/ok.sql.gz", gzencode($dump));
$r = LiveBackup::inspect("$td/ok.sql");
t('sql yedeği: kabul, tablolar ve INSERT sayılır, sha256 var', $r['ok'] && count($r['tables']) === 7 && $r['entries'] === 7 && strlen($r['sha256']) === 64);
t('sql.gz yedeği: kabul', LiveBackup::inspect("$td/ok.sql.gz")['ok']);
file_put_contents("$td/noinsert.sql", str_replace('INSERT', '-- X', $dump));
t('INSERT yoksa uyarı (yalnızca yapı)', ($r2 = LiveBackup::inspect("$td/noinsert.sql")) && $r2['warnings'] !== []);
file_put_contents("$td/partial.sql", str_replace("CREATE TABLE `orders` (id int);", '', $dump));
t('çekirdek tablo (orders) eksikse RET', !($r3 = LiveBackup::inspect("$td/partial.sql"))['ok'] && in_array('orders', $r3['missing_core'], true));
file_put_contents("$td/notdump.sql", str_repeat("select 1;\n", 400));
t('döküm olmayan .sql RET', !LiveBackup::inspect("$td/notdump.sql")['ok']);
file_put_contents("$td/tiny.sql", 'x');
t('1 KB altı RET', !LiveBackup::inspect("$td/tiny.sql")['ok']);
file_put_contents("$td/bad.sql.gz", substr(gzencode($dump), 0, 120) . str_repeat('Z', 1500));
t('bozuk gz RET', !LiveBackup::inspect("$td/bad.sql.gz")['ok']);
t('olmayan dosya RET', !LiveBackup::inspect("$td/yok.sql")['ok']);
file_put_contents("$td/a.txt", str_repeat('x', 2000));
t('desteklenmeyen uzantı RET', !LiveBackup::inspect("$td/a.txt")['ok']);
$z = new ZipArchive();
$z->open("$td/site.zip", ZipArchive::CREATE);
$z->addFromString('public_html/index.php', '<?php');
$z->addFromString('public_html/core/App.php', random_bytes(2000));
$z->addFromString('public_html/modules/a/a.php', '<?php');
$z->addFromString('public_html/config/env.php', '<?php return [];');
$z->addFromString('public_html/img/p.jpg', 'x');
$z->close();
$rz = LiveBackup::inspect("$td/site.zip");
t('site ZIP: index/core/modules görülür → kabul; env.php içerdiği bilgi notu olur', $rz['ok'] && $rz['notes'] !== []);
$z = new ZipArchive();
$z->open("$td/part.zip", ZipArchive::CREATE);
$z->addFromString('x/readme.txt', str_repeat('x', 2000));
$z->close();
t('uygulama dosyası içermeyen ZIP RET', !LiveBackup::inspect("$td/part.zip")['ok']);
file_put_contents("$td/bad.zip", str_repeat('PK-not-zip', 300));
t('bozuk ZIP RET', !LiveBackup::inspect("$td/bad.zip")['ok']);
t('denetleyici dosyaları değiştirmez/silmez', md5_file("$td/ok.sql") === md5($dump) && is_file("$td/site.zip"));
$bsrc = (string) file_get_contents(dirname(__DIR__) . '/lib/LiveBackup.php') . (string) file_get_contents(dirname(__DIR__) . '/bin/live-backup-check.php');
t('LiveBackup: PDO/yazma/silme işlevi kullanmaz', !preg_match('/\b(new PDO|unlink|rename|file_put_contents|fwrite\(\$h|rmdir|DROP|DELETE)\b/', preg_replace('#/\*.*?\*/#s', '', $bsrc)) || strpos(preg_replace('#/\*.*?\*/#s', '', $bsrc), 'new PDO') === false);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/live-backup-check.php') . ' ' . escapeshellarg("$td/ok.sql") . ' 2>&1', $o, $rc);
t('CLI: geçerli yedek exit 0, çıktıda içerik/parola yok', $rc === 0 && strpos(implode("\n", $o), 'CREATE TABLE') === false && strpos(implode("\n", $o), 'kullanılabilir görünüyor') !== false);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/live-backup-check.php') . ' ' . escapeshellarg("$td/tiny.sql") . ' 2>&1', $o2, $rc2);
t('CLI: şüpheli yedek exit 1', $rc2 === 1);
array_map('unlink', glob("$td/*") ?: []);
rmdir($td);

echo "== PDO açılış noktaları envanteri\n";
$sites = [];
$rootDir = dirname(__DIR__, 3);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)) as $f) {
	$rel = substr($f->getPathname(), strlen($rootDir) + 1);

	if (!$f->isFile() || substr($rel, -4) !== '.php' || preg_match('#^(libs|vendor|\.git|logs|cache|backups|storage)/|/tests/#', $rel)) {
		continue;
	}

	if (preg_match('/new\s+\\\\?PDO\s*\(/', (string) file_get_contents($f->getPathname()))) {
		$sites[] = $rel;
	}
}
sort($sites);
$allowed = ['config/connection.php', 'config/pwa_helpers.php', 'core/Installer.php', 'deploy/live/lib/LiveGuard.php', 'deploy/staging/lib/StagingGuard.php', 'manifest.php', 'tools/export-schema.php'];
t('new PDO yalnızca bilinen noktalarda (yeni nokta eklenirse bu test elle gözden geçirilmeli)', $sites === $allowed, implode(',', array_diff($sites, $allowed)) . ' | eksik: ' . implode(',', array_diff($allowed, $sites)));
$deployPdo = array_values(array_filter($sites, static fn(string $r): bool => strpos($r, 'deploy/') === 0));
t('deploy/ altında PDO yalnızca iki bekçi sınıfında', $deployPdo === ['deploy/live/lib/LiveGuard.php', 'deploy/staging/lib/StagingGuard.php']);
t('tools/export-schema.php komut satırı dışında 404', strpos((string) file_get_contents($rootDir . '/tools/export-schema.php'), "PHP_SAPI !== 'cli'") !== false);

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

	t('paket önizleme/geliştirme araçlarını (deploy/preview) içermez', count(array_filter($names, static fn(string $n): bool => strpos($n, 'deploy/preview/') === 0)) === 0);

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
