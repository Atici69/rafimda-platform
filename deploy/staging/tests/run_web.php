<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * GERÇEK HTTP testi (PHP yerleşik web sunucusu, 127.0.0.1, geçici dizin; MySQL YOK, canlı sunucu YOK).
 * Kanıtladığı: (a) deploy/ araçlarına web isteği 404 döner ve bekçi/DB koduna ulaşmaz, (b) staging_guard.php auto_prepend_file ile
 * gerçekten yüklendiğinde başlık ekler, canlı DB adı / canlı alan adı isteklerini 503 ile durdurur.
 * Kanıtlamadığı: Apache .htaccess / nginx location kuralları (bu makinede Apache/nginx yok).
 */
$root = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;
function t(string $name, bool $ok, string $detail = ''): void
{
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
}

/** @return array{0:int,1:string,2:array<int,string>} */
function http(int $port, string $path, string $host = ''): array
{
	$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'header' => $host !== '' ? "Host: $host\r\n" : '']]);
	$body = (string) @file_get_contents('http://127.0.0.1:' . $port . $path, false, $ctx);
	$hdr = $http_response_header ?? [];
	$code = isset($hdr[0]) && preg_match('/\s(\d{3})\s/', $hdr[0], $m) ? (int) $m[1] : 0;

	return [$code, $body, $hdr];
}

function serve(string $docroot, array $ini = []): array
{
	$port = random_int(20000, 40000);
	$args = '';

	foreach ($ini as $k => $v) {
		$args .= ' -d ' . escapeshellarg($k . '=' . $v);
	}

	$proc = proc_open(escapeshellarg(PHP_BINARY) . $args . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $docroot);

	for ($i = 0; $i < 50; $i++) {
		if (@fsockopen('127.0.0.1', $port, $en, $es, 0.1)) {
			break;
		}

		usleep(100000);
	}

	return [$port, $proc];
}

echo "== Deploy araçlarına web isteği (php -S, depo kökü)\n";
[$port, $proc] = serve($root);

foreach (['/deploy/live/bin/live-preflight.php', '/deploy/live/bin/live-migrate.php?--apply', '/deploy/live/bin/live-verify.php', '/deploy/staging/bin/staging-migrate.php', '/deploy/staging/bin/staging-seed.php', '/deploy/staging/bin/staging-env.php', '/tools/export-schema.php'] as $p) {
	[$code, $body] = http($port, $p);
	t("web: $p → 404, çıktı yok", $code === 404 && stripos($body, 'REDDEDİLDİ') === false && stripos($body, 'RAFIMDA_') === false, "kod=$code");
}

proc_terminate($proc);

echo "== staging_guard.php auto_prepend_file ile (geçici ağaç)\n";
$tmp = sys_get_temp_dir() . '/rf_web_' . bin2hex(random_bytes(4));
foreach (['/deploy/staging/conf', '/deploy/staging/lib', '/config'] as $d) {
	mkdir($tmp . $d, 0777, true);
}

copy($root . '/deploy/staging/conf/staging_guard.php', $tmp . '/deploy/staging/conf/staging_guard.php');
copy($root . '/deploy/staging/lib/StagingGuard.php', $tmp . '/deploy/staging/lib/StagingGuard.php');
file_put_contents($tmp . '/index.php', "<?php echo 'SITE-OK';");
$guard = $tmp . '/deploy/staging/conf/staging_guard.php';

$setCfg = static function (string $db) use ($tmp): void {
	file_put_contents($tmp . '/config/env.php', "<?php return ['DB_HOST' => 'localhost', 'DB_NAME' => '$db', 'DB_USER' => 'u', 'DB_PASS' => 'x'];");
};

// 1) staging DB adı → izin + noindex başlığı
$setCfg('rafimda_staging');
[$port, $proc] = serve($tmp, ['auto_prepend_file' => $guard]);
[$code, $body, $hdr] = http($port, '/', 'test.rafimda.com.tr');
t('staging DB + test.* adresi: 200 ve içerik', $code === 200 && $body === 'SITE-OK', "kod=$code");
t('X-Robots-Tag: noindex başlığı eklenir', (bool) preg_grep('/^X-Robots-Tag:.*noindex/i', $hdr));
[$code, $body] = http($port, '/', 'www.rafimda.com.tr');
t('canlı alan adı (www.rafimda.com.tr) → 503, içerik sızmaz', $code === 503 && strpos($body, 'SITE-OK') === false, "kod=$code");
proc_terminate($proc);

// 2) env.php canlı DB adı → 503
$setCfg('rafimda_live');
[$port, $proc] = serve($tmp, ['auto_prepend_file' => $guard]);
[$code, $body] = http($port, '/', 'test.rafimda.com.tr');
t('env.php canlı DB adı → 503, içerik sızmaz', $code === 503 && strpos($body, 'SITE-OK') === false, "kod=$code");
proc_terminate($proc);

// 3) env.php yok (kurulum sihirbazı) + staging adresi → izin; canlı adres → 503
unlink($tmp . '/config/env.php');
[$port, $proc] = serve($tmp, ['auto_prepend_file' => $guard]);
[$code] = http($port, '/', 'test.rafimda.com.tr');
t('env.php yok + staging adresi: kurulum sihirbazına izin', $code === 200, "kod=$code");
[$code] = http($port, '/', 'rafimda.com.tr');
t('env.php yok + canlı adres: 503', $code === 503, "kod=$code");
proc_terminate($proc);

// 4) auto_prepend OLMADAN koruma yok (belgelenen sınır)
$setCfg('rafimda_live');
[$port, $proc] = serve($tmp);
[$code, $body] = http($port, '/', 'test.rafimda.com.tr');
t('SINIR: auto_prepend_file bağlanmadıysa web koruması YOKTUR (200)', $code === 200 && $body === 'SITE-OK');
proc_terminate($proc);

array_map('unlink', array_merge(glob($tmp . '/deploy/staging/*/*') ?: [], glob($tmp . '/config/*') ?: [], [$tmp . '/index.php']));
foreach (['/deploy/staging/conf', '/deploy/staging/lib', '/deploy/staging', '/deploy', '/config', ''] as $d) {
	@rmdir($tmp . $d);
}

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
