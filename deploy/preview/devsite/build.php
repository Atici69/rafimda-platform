<?php
/**
 * YALNIZCA GELİŞTİRME ÖNİZLEMESİ — gerçek Rafımda uygulamasını (index.php, theme4 + Rafımda modülleri) MySQL olmadan SQLite ile
 * geçici bir KOPYADA çalıştırır. Depodaki dosyalara yazmaz; canlıya/staging'e bağlanmaz. Veriler FriSay'in kendi demo verisi + açıkça
 * "ÖRNEK" etiketli Rafımda örnekleridir (gerçek mağaza/ürün değildir).
 * Kullanım: php deploy/preview/devsite/build.php /tmp/rfsite [paket.zip]   (zip verilirse kurulum provası: kaynak yayın paketidir) → ardından: php -S 127.0.0.1:8090 -t /tmp/rfsite /tmp/rfsite/devsite/router.php
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$repo = dirname(__DIR__, 3);
$dst = rtrim($argv[1] ?? '', '/');

if ($dst === '' || str_starts_with(realpath(dirname($dst)) ?: $dst, $repo)) {
	fwrite(STDERR, "Hedef dizin depo dışında olmalı (ör. /tmp/rfsite)\n");
	exit(1);
}

$fromZip = $argv[2] ?? '';

if ($fromZip !== '') {
	// KURULUM PROVASI: kaynak olarak depo yerine yayın paketi (public_html zip) kullanılır
	exec('rm -rf ' . escapeshellarg($dst) . ' && mkdir -p ' . escapeshellarg($dst) . ' && unzip -q ' . escapeshellarg($fromZip) . ' -d ' . escapeshellarg($dst), $o, $rc);
} else {
	exec('rm -rf ' . escapeshellarg($dst) . ' && mkdir -p ' . escapeshellarg($dst) . ' && cd ' . escapeshellarg($repo) . " && git ls-files -z --cached --others --exclude-standard | xargs -0 -I{} cp --parents '{}' " . escapeshellarg($dst), $o, $rc);
}

if ($rc !== 0) {
	fwrite(STDERR, "Kopyalama başarısız\n");
	exit(1);
}

@mkdir("$dst/devsite", 0777, true);
copy(__DIR__ . '/DevPDO.php', "$dst/devsite/DevPDO.php");
file_put_contents("$dst/devsite/sqlite_support.php", str_replace("if (PHP_SAPI !== 'cli') {\n\thttp_response_code(404);\n\texit;\n}", '', (string) file_get_contents($repo . '/deploy/staging/tests/sqlite_support.php')));
$log = "$dst/devsite/sql-errors.log";
file_put_contents("$dst/config/env.php", "<?php\nreturn " . var_export(['APP_ENV' => 'local', 'APP_DEBUG' => false, 'DB_HOST' => 'sqlite', 'DB_NAME' => 'devsite', 'DB_USER' => '', 'DB_PASS' => '', 'REWRITE_BASE' => '/', 'ADMIN_URI' => 'admin'], true) . ";\n");
file_put_contents("$dst/config/connection.php", <<<PHP
<?php
// GELİŞTİRME ÖNİZLEMESİ: MySQL yerine SQLite (deploy/preview/devsite). Bu dosya release paketinde YOKTUR.
require_once __DIR__ . '/app.php';
App::boot();
define('_DB_SERVER_', 'sqlite');
define('_DB_NAME_', 'devsite');
define('_DB_USER_', '');
define('_DB_PASSWD_', '');
define('_MYSQL_ENGINE_', 'InnoDB');
require_once dirname(__DIR__) . '/devsite/sqlite_support.php';
require_once dirname(__DIR__) . '/devsite/DevPDO.php';
DevPDO::\$log = dirname(__DIR__) . '/devsite/sql-errors.log';
\$db = new DevPDO(dirname(__DIR__) . '/devsite/site.sqlite');
PHP);
file_put_contents("$dst/devsite/router.php", <<<'PHP'
<?php
// php -S yönlendirici: GERÇEK .htaccess RewriteRule kurallarını sırayla uygular (Apache mod_rewrite'ın sadeleştirilmiş taklidi)
$root = dirname(__DIR__);
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) parse_url($uri, PHP_URL_PATH);
$rel = ltrim(rawurldecode($path), '/');
if (preg_match('#^(devsite)(/|$)#', $rel)) { http_response_code(404); exit; }
if (preg_match('#(^|/)\.ht#', $rel)) { http_response_code(403); echo 'Forbidden'; exit; } // Apache varsayılanı: .ht* dosyaları sunulmaz
// Alt klasör .htaccess "Require all denied" (Apache) taklidi: config/, storage/ vb. doğrudan erişime kapalı
for ($d = ($rel !== '' && is_dir($root . '/' . $rel)) ? rtrim($rel, '/') : dirname($rel); $d !== '.' && $d !== '' && $d !== '/'; $d = dirname($d)) {
	$h = $root . '/' . $d . '/.htaccess';
	if (is_file($h) && preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i', (string) file_get_contents($h))) {
		$body = (string) file_get_contents($h);
		// <FilesMatch "DESEN"> … Require all denied … </FilesMatch> blokları: yalnızca desene uyan dosyalar kapalı
		if (preg_match_all('/<FilesMatch\s+"([^"]+)">(.*?)<\/FilesMatch>/is', $body, $fms, PREG_SET_ORDER)) {
			foreach ($fms as $fm) {
				if (preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i', $fm[2]) && @preg_match('#' . str_replace('#', '\\#', $fm[1]) . '#i', basename($rel))) { http_response_code(403); echo 'Forbidden'; exit; }
			}
		}
		$outside = (string) preg_replace('/<FilesMatch.*?<\/FilesMatch>/is', '', $body);
		if (preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i', $outside)) { http_response_code(403); echo 'Forbidden'; exit; }
	}
}
static $rules = null;
if ($rules === null) {
	$rules = [];
	foreach (file($root . '/.htaccess') ?: [] as $line) {
		if (preg_match('/^\s*RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/', $line, $m)) { $rules[] = [$m[1], $m[2], strtoupper($m[3] ?? '')]; }
	}
}
foreach ($rules as [$pat, $target, $flags]) {
	$re = '#' . str_replace('#', '\\#', $pat) . '#' . (str_contains($flags, 'NC') ? 'i' : '');
	if (!@preg_match($re, $rel, $mm)) { continue; }
	if ($target === '-') {
		if (preg_match('/(^|,)F(,|$)/', $flags)) { http_response_code(403); exit; }
		if (str_contains($flags, 'E=')) { continue; } // yalnızca ortam değişkeni ataması (RewriteCond'lu); geçiş kuralı DEĞİL
		if ($pat === '^' && (is_file($root . '/' . $rel) || ($rel !== '' && is_dir($root . '/' . $rel)))) {
			if (is_file($root . '/' . $rel) && !str_ends_with($rel, '.php')) { return false; }
			if (is_file($root . '/' . $rel)) { $_SERVER['SCRIPT_NAME'] = '/' . $rel; chdir(dirname($root . '/' . $rel)); require $root . '/' . $rel; return true; }
		}
		continue;
	}
	$t = preg_replace_callback('/\$(\d)/', static fn($x) => $mm[(int) $x[1]] ?? '', $target);
	[$script, $qs] = array_pad(explode('?', $t, 2), 2, '');
	parse_str($qs, $q);
	$_GET = $q + (str_contains($flags, 'QSA') ? $_GET : []);
	$_REQUEST = $_GET + $_POST;
	$_SERVER['SCRIPT_NAME'] = '/' . $script;
	$_SERVER['QUERY_STRING'] = http_build_query($_GET);
	chdir(dirname($root . '/' . $script));
	require $root . '/' . $script;
	return true;
}
if ($rel === '' || $rel === 'index.php') { $_SERVER['SCRIPT_NAME'] = '/index.php'; chdir($root); require $root . '/index.php'; return true; }
if (is_file($root . '/' . $rel)) { return false; }
http_response_code(404);
echo 'Bulunamadı';
PHP);
// Kurulum: FriSay Installer'ın kendi adımları (şema + demo + başlangıç modülleri) SQLite üzerinde
require "$dst/devsite/sqlite_support.php";
require "$dst/devsite/DevPDO.php";
DevPDO::$log = $log;
@unlink("$dst/devsite/site.sqlite");
$pdo = new DevPDO("$dst/devsite/site.sqlite");
require "$dst/core/Installer.php";
$ref = new ReflectionClass('Installer');
$call = static function (string $m, ...$a) use ($ref) { $f = $ref->getMethod($m); $f->setAccessible(true); return $f->invoke(null, ...$a); };
$call('runSqlFile', $pdo, "$dst/install/schema.sql");
try { $call('runSqlFileIfExists', $pdo, "$dst/install/patch_taxes.sql"); } catch (Throwable $e) { fwrite(STDERR, 'patch_taxes: ' . $e->getMessage() . "\n"); }
try { $call('runSqlFile', $pdo, "$dst/install/seed_demo.sql"); } catch (Throwable $e) { fwrite(STDERR, 'seed_demo: ' . $e->getMessage() . "\n"); }
try { $call('bootstrapStorefront', $pdo, true); } catch (Throwable $e) { fwrite(STDERR, 'bootstrapStorefront: ' . $e->getMessage() . "\n"); }
// seed_demo.sql bazı modülleri (question, reviews…) "kurulu" işaretliyor ama tablolarını oluşturmuyor (FriSay demo verisi bulgusu);
// önizlemede bu modüllerin kendi install.sql dosyaları çalıştırılır.
foreach ($pdo->query('SELECT name FROM modules WHERE installed = 1')->fetchAll(PDO::FETCH_COLUMN) as $mn) {
	if (is_file("$dst/modules/$mn/install.sql")) { try { $call('runSqlFileIfExists', $pdo, "$dst/modules/$mn/install.sql"); } catch (Throwable $e) { fwrite(STDERR, "$mn install.sql: " . $e->getMessage() . "\n"); } }
}
$set = static function (string $k, string $v) use ($call, $pdo) { $call('setSetting', $pdo, $k, $v); };
foreach (['DOMAIN' => 'http://127.0.0.1:8090/', 'FOLDER' => '/', 'SITE_NAME' => 'Rafımda', 'THEME' => 'theme4', 'DEFAULT_LANG' => 'tr', 'SHOP_LANGUAGES' => 'tr', 'ADMIN_DEFAULT_LANG' => 'tr',
	'SHOP_TOKEN' => bin2hex(random_bytes(8)), 'FREE_SHIPPING_MIN' => '500', 'SHIPPING_FEE' => '79.90', 'CARGO_DAY' => '3', 'CONTACT_EMAIL' => 'ornek@rafimda.test', 'MAIL_DRIVER' => 'php'] as $k => $v) {
	$set($k, $v);
}
$pdo->prepare('UPDATE admins SET full_name = ?, email = ?, password = ?, active = 1 WHERE id_admin = 1')->execute(['Önizleme Yönetici', 'admin@rafimda.test', password_hash('Onizleme123!', PASSWORD_DEFAULT)]);
echo "Şema ve demo veri hazır. Hata günlüğü: $log\n";
// Rafımda modülleri: gerçek Module::install() ile (FriSay'in kendi kurulum yolu), staging'deki sırayla
$code = <<<'PHP'
<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8090'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['SCRIPT_NAME'] = '/index.php';
define('IN_SCRIPT', true);
require dirname(__DIR__) . '/config/settings.php';
foreach (array_slice($argv, 1) as $m) {
	$r = Module::install($m);
	if (!empty($r['success'])) { Module::setActive($m, true); }
	echo str_pad($m, 22) . (!empty($r['success']) ? 'kuruldu' : 'HATA: ' . ($r['message'] ?? '')) . "\n";
}
PHP;
file_put_contents("$dst/devsite/install_modules.php", $code);
$mods = ['question', 'reviews', 'rafimda-theme', 'rafimda-vendor', 'xml-hub', 'marketplace-hub', 'rafimda-showcase', 'rafimda-campaign', 'rafimda-legal', 'rafimda-invoice', 'rafimda-social', 'rafimda-dealer', 'rafimda-rafi'];
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dst/devsite/install_modules.php") . ' ' . implode(' ', array_map('escapeshellarg', $mods)));
// Açıkça TEST etiketli örnek mağaza (vitrin/satıcı kutusu/değerlendirme ekranlarını görebilmek için); gerçek satıcı DEĞİLDİR
$pdo = new DevPDO("$dst/devsite/site.sqlite");
$pdo->exec("INSERT INTO users (user_full_name, phone, email, password, active) VALUES ('Örnek Satıcı (TEST)', '05000000001', 'satici@rafimda.test', '" . password_hash('Onizleme123!', PASSWORD_DEFAULT) . "', 1)");
$uid = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO rafimda_vendors (owner_user_id, store_name, store_slug, legal_name, tax_office, tax_number, phone, email, city, description, status, commission_rate, date_add) VALUES ($uid, 'ÖRNEK Mağaza (TEST)', 'ornek-magaza-test', 'Örnek Ticaret (TEST)', 'Kadıköy', '1234567890', '05000000001', 'satici@rafimda.test', 'İstanbul', 'Bu mağaza geliştirme önizlemesi için oluşturulmuş TEST kaydıdır; gerçek bir satıcı değildir.', 'active', 10, datetime('now'))");
$vid = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO rafimda_vendor_staff (id_vendor, id_user, role, status) VALUES ($vid, $uid, 'owner', 'active')");
foreach ($pdo->query('SELECT id_product FROM products ORDER BY id_product LIMIT 5')->fetchAll(PDO::FETCH_COLUMN) as $p) { $pdo->exec("INSERT INTO rafimda_vendor_products (id_product, id_vendor) VALUES ($p, $vid)"); }
$pdo->exec("INSERT INTO rafimda_store_profile (id_vendor, about, shipping_note, return_note) VALUES ($vid, 'TEST mağazası: geliştirme önizlemesi.', 'Hafta içi 14:00''e kadar verilen siparişler aynı gün kargoda (örnek)', '14 gün içinde ücretsiz iade (örnek)')");
echo "Örnek TEST mağazası: /magaza?s=ornek-magaza-test (giriş: satici@rafimda.test / Onizleme123!)\n";
