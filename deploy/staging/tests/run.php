<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
/**
 * Staging paket testleri (SQLite + MySQL→SQLite çevirisi). MySQL'de ÇALIŞTIRILMAMIŞTIR; yalnızca mantığı doğrular.
 * Çalıştırma: php deploy/staging/tests/run.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/StagingGuard.php';
require_once dirname(__DIR__) . '/lib/StagingSql.php';
require_once dirname(__DIR__) . '/lib/StagingModules.php';
require_once dirname(__DIR__) . '/lib/StagingMigrator.php';
require_once dirname(__DIR__) . '/lib/StagingVerifier.php';
require_once __DIR__ . '/sqlite_support.php';

$pass = 0;
$fail = 0;
function t(string $name, bool $ok, string $detail = ''): void
{
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
}

$root = StagingModules::root();
$good = ['RAFIMDA_ENV' => 'staging', 'RAFIMDA_STAGING_CONFIRM' => 'EVET-STAGING-DB', 'RAFIMDA_PROD_DB_NAME' => 'rafimda_live', 'DB_HOST' => 'localhost', 'DB_NAME' => 'rafimda_staging', 'DB_USER' => 'u', 'DB_PASS' => 's3cret!', 'RAFIMDA_SITE_URL' => 'https://test.rafimda.com.tr/'];

echo "== StagingGuard\n";
t('geçerli staging ortamı kabul', StagingGuard::check($good)['ok']);
foreach (['RAFIMDA_ENV', 'RAFIMDA_STAGING_CONFIRM', 'RAFIMDA_PROD_DB_NAME', 'DB_HOST', 'DB_NAME', 'DB_USER'] as $k) {
	$e = $good;
	unset($e[$k]);
	t("$k eksikse ret", !StagingGuard::check($e)['ok']);
}
t('RAFIMDA_ENV=production ret', !StagingGuard::check(['RAFIMDA_ENV' => 'production'] + $good)['ok']);
t('yanlış onay ifadesi ret', !StagingGuard::check(['RAFIMDA_STAGING_CONFIRM' => 'evet'] + $good)['ok']);
t('DB_NAME = canlı DB adı ret', !StagingGuard::check(['DB_NAME' => 'rafimda_live'] + $good)['ok']);
t('DB_NAME büyük/küçük harf farkıyla canlıyla aynıysa ret', !StagingGuard::check(['DB_NAME' => 'Rafimda_Live', 'RAFIMDA_PROD_DB_NAME' => 'rafimda_live'] + $good)['ok']);
t('staging gibi görünmeyen DB adı ret', !StagingGuard::check(['DB_NAME' => 'shop', 'RAFIMDA_PROD_DB_NAME' => 'x'] + $good)['ok']);
t('DB adında özel karakter ret', !StagingGuard::check(['DB_NAME' => 'test`; DROP'] + $good)['ok']);
t('www.rafimda.com.tr site adresi ret', !StagingGuard::check(['RAFIMDA_SITE_URL' => 'https://www.rafimda.com.tr/'] + $good)['ok']);
t('test.* site adresi kabul', StagingGuard::check(['RAFIMDA_SITE_URL' => 'https://staging.rafimda.com.tr'] + $good)['ok']);
t('scrub parolayı maskeler', strpos(StagingGuard::scrub('Access denied using s3cret! at', $good), 's3cret!') === false);
t('scrub boş parolada bozulmaz', StagingGuard::scrub('abc', ['DB_PASS' => '']) === 'abc');

echo "== StagingSql\n";
$st = StagingSql::statements("-- yorum\nCREATE TABLE a (x int);\n\nALTER TABLE a ADD COLUMN y int;\n");
t('yorum atılır, 2 ifade', count($st) === 2 && strpos($st[0], 'CREATE TABLE a') === 0);
t('yorumdan sonraki ifade KAYBOLMAZ (modül çalıştırıcılarındaki -- hatası burada yok)', strpos($st[0], '--') === false);
t('alterAddColumn tanır', StagingSql::alterAddColumn('ALTER TABLE `t` ADD COLUMN `c` int') === ['table' => 't', 'column' => 'c']);
t('alterAddColumn CREATE\'i yok sayar', StagingSql::alterAddColumn('CREATE TABLE t (a int)') === null);
$sch = StagingSql::expectedSchema((string) file_get_contents($root . '/modules/rafimda-vendor/migrations/001_init.sql'));
t('001_init: rafimda_vendors sütunları', in_array('store_slug', $sch['rafimda_vendors']['columns'], true) && in_array('PRIMARY', $sch['rafimda_vendors']['indexes'], true));
$rv = StagingModules::expectedSchema(StagingModules::all()[0]);
t('ALTER ile eklenen sütun şemaya girer (plan_expires_at, moderation)', in_array('plan_expires_at', $rv['rafimda_vendors']['columns'], true) && in_array('moderation', $rv['rafimda_vendor_products']['columns'], true));
t('rafimda-vendor beklenen tablo sayısı >= 10', count($rv) >= 10, (string) count($rv));
foreach (StagingModules::all() as $m) {
	$e = StagingModules::expectedSchema($m);
	t($m['key'] . ': şema çıkarıldı (' . count($e) . ' tablo)', count($e) >= 2);
	foreach ($e as $tbl => $d) {
		if (!in_array('PRIMARY', $d['indexes'], true) && $tbl !== 'xh_settings_dummy') {
			t($m['key'] . '.' . $tbl . ' PRIMARY KEY tanımlı', false, 'ayrıştırılamadı veya yok');
		}
	}
}

echo "== StagingMigrator (SQLite çevirisiyle, gerçek modül dosyaları)\n";
$pdo = new SqliteMysqlPDO();
$colExists = static function (string $t, string $c) use ($pdo): bool {
	foreach ($pdo->query('PRAGMA table_info(`' . $t . '`)')->fetchAll() as $r) {
		if ($r['name'] === $c) {
			return true;
		}
	}

	return false;
};
$mig = new StagingMigrator($pdo, $colExists);
$dry = $mig->runModule(StagingModules::all()[0], true);
t('dry-run başarılı ve tablo oluşturmaz', $dry['success'] && MysqlToSqlite::readTable($pdo, 'rafimda_vendors') === null);

$allOk = true;
foreach (StagingModules::all() as $m) {
	$r = $mig->runModule($m);
	$allOk = $allOk && $r['success'];
	if (!$r['success']) {
		echo '    ' . json_encode($r['steps'], JSON_UNESCAPED_UNICODE) . "\n";
	}
}
t('tüm modüller sırayla kuruldu', $allOk);
t('rafimda_migrations: 001-005 kayıtlı', $mig->applied('rafimda_migrations') === ['001_init', '002_moderation', '003_orders_finance', '004_plans', '005_prefs']);
t('xh_migrations: 001-003 kayıtlı', $mig->applied('xh_migrations') === ['001_init', '002_source_meta_credentials', '003_export_feeds']);
t('mh_migrations kayıtlı', count($mig->applied('mh_migrations')) >= 1);

$again = true;
$skips = 0;
foreach (StagingModules::all() as $m) {
	$r = $mig->runModule($m);
	$again = $again && $r['success'];
	foreach ($r['steps'] as $s) {
		$skips += $s['status'] === 'SKIP' ? 1 : 0;
	}
}
t('ikinci çalıştırma başarılı ve idempotent (hepsi SKIP)', $again && $skips >= 8, (string) $skips);

// Yarım kalmış kurulum: sütun elle eklenmiş ama sürüm kaydı yok → ALTER atlanmalı, hata vermemeli
$pdo->exec('DELETE FROM rafimda_migrations WHERE version = \'004_plans\'');
$r = $mig->runModule(StagingModules::all()[0]);
t('ALTER ADD COLUMN: sütun zaten varsa atlanır (yarım kalmış migration)', $r['success'] && in_array('004_plans', $mig->applied('rafimda_migrations'), true));

echo "== Hata durumunda güvenli durma\n";
$tmp = sys_get_temp_dir() . '/stg_mod_' . bin2hex(random_bytes(4));
mkdir($tmp . '/migrations', 0777, true);
file_put_contents($tmp . '/install.sql', "CREATE TABLE IF NOT EXISTS `zz_migrations` (\n  `version` varchar(32) NOT NULL,\n  PRIMARY KEY (`version`)\n) ENGINE=InnoDB;\n");
file_put_contents($tmp . '/migrations/001_ok.sql', "CREATE TABLE IF NOT EXISTS `zz_a` (\n  `x` int(11) NOT NULL\n) ENGINE=InnoDB;\n");
file_put_contents($tmp . '/migrations/002_bad.sql', "CREATE TABLE IF NOT EXISTS `zz_b` (\n  `y` int(11) NOT NULL\n) ENGINE=InnoDB;\n\nINSERT INTO tablo_yok (a) VALUES (1);\n");
file_put_contents($tmp . '/migrations/003_after.sql', "CREATE TABLE IF NOT EXISTS `zz_c` (\n  `z` int(11) NOT NULL\n) ENGINE=InnoDB;\n");
$bad = ['key' => 'zz', 'dir' => $tmp, 'tracker' => 'zz_migrations', 'install' => $tmp . '/install.sql', 'migrations' => $tmp . '/migrations'];
$r = $mig->runModule($bad);
t('hatalı migration: success=false', !$r['success']);
t('hatalı migration SÜRÜM KAYDI YAZILMAZ, öncekiler yazılır', $mig->applied('zz_migrations') === ['001_ok']);
t('hatadan sonraki migration çalıştırılmaz', MysqlToSqlite::readTable($pdo, 'zz_c') === null);
t('hata mesajı ifadeyi ve nedeni içerir', strpos(json_encode($r['steps']), 'tablo_yok') !== false);
file_put_contents($tmp . '/migrations/002_bad.sql', "CREATE TABLE IF NOT EXISTS `zz_b` (\n  `y` int(11) NOT NULL\n) ENGINE=InnoDB;\n");
$r = $mig->runModule($bad);
t('düzeltilince kaldığı yerden devam eder', $r['success'] && $mig->applied('zz_migrations') === ['001_ok', '002_bad', '003_after']);

echo "== Yıkıcı ifade koruması\n";
foreach (['DROP TABLE x', 'TRUNCATE TABLE x', 'DELETE FROM x', 'ALTER TABLE x DROP COLUMN y', 'RENAME TABLE a TO b'] as $d) {
	t("yıkıcı: $d", StagingMigrator::isDestructive($d));
}
t('CREATE/ALTER ADD yıkıcı değil', !StagingMigrator::isDestructive('CREATE TABLE x (a int)') && !StagingMigrator::isDestructive('ALTER TABLE x ADD COLUMN y int'));
file_put_contents($tmp . '/migrations/004_drop.sql', "DROP TABLE zz_a;\n");
$r = $mig->runModule($bad);
t('yıkıcı migration çalıştırılmaz, tablo korunur', !$r['success'] && MysqlToSqlite::readTable($pdo, 'zz_a') !== null && !in_array('004_drop', $mig->applied('zz_migrations'), true));

echo "== StagingVerifier\n";
foreach (['users', 'settings', 'categories', 'brands', 'products', 'images', 'orders', 'order_detail', 'modules', 'module_display_hooks', 'admins'] as $core) {
	$pdo->exec('CREATE TABLE IF NOT EXISTS `' . $core . "` (\n  `id` int(11) NOT NULL\n) ENGINE=InnoDB;\n");
}
$reader = new StagingSchemaReader(static fn(string $t) => MysqlToSqlite::readTable($pdo, $t));
$applied = static fn(string $tr): array => $mig->applied($tr);
$v = new StagingVerifier($root);
$v->checkSchema($reader, $applied);
t('tam kurulumda şema FAIL yok', $v->failures() === 0, $v->toText());
t('product_variations yoksa yalnızca WARN', $v->warnings() >= 1 && $v->failures() === 0);
$pdo->exec('DROP TABLE rafimda_plans');
$v = new StagingVerifier($root);
$v->checkSchema($reader, $applied);
t('eksik tablo tespit edilir', $v->failures() >= 1 && strpos($v->toText(), 'rafimda_plans') !== false);
$pdo->exec('DELETE FROM xh_migrations WHERE version = \'003_export_feeds\'');
$v = new StagingVerifier($root);
$v->checkSchema($reader, $applied);
t('eksik migration kaydı tespit edilir', strpos($v->toText(), '003_export_feeds') !== false);
$pdo->exec('DROP TABLE rafimda_vendor_products');
$pdo->exec("CREATE TABLE rafimda_vendor_products (id_product INTEGER PRIMARY KEY, id_vendor INT)");
$v = new StagingVerifier($root);
$v->checkSchema($reader, $applied);
t('eksik sütun tespit edilir (date_add / indeks)', strpos($v->toText(), 'eksik sütun') !== false);
$vp = new StagingVerifier($root);
$vp->checkPhp('7.3.0', static fn(string $e): bool => $e !== 'sodium');
t('PHP 7.3 ve sodium eksik → FAIL', $vp->failures() === 2);
$ve = new StagingVerifier($root);
$ve->checkEnvConfig(['APP_ENV' => 'production', 'APP_DEBUG' => false, 'DB_NAME' => 'rafimda_staging'], 'rafimda_staging');
t('env.php doğru → FAIL yok', $ve->failures() === 0);
$ve = new StagingVerifier($root);
$ve->checkEnvConfig(['APP_ENV' => 'local', 'APP_DEBUG' => true, 'DB_NAME' => 'rafimda_live'], 'rafimda_staging');
t('env.php farklı DB → FAIL, debug açık → WARN', $ve->failures() === 1 && $ve->warnings() === 2);
$ve = new StagingVerifier($root);
$ve->checkRobots();
t('robots.staging.txt hazır', $ve->failures() === 0);
t('markdown rapor üretilir', strpos($v->toMarkdown(), '# Rafımda Staging') === 0);

echo "== SQL ayrıştırıcı (yorum, çoklu ifade, tırnak)\n";
$x = StagingSql::statements("-- baş yorum\nCREATE TABLE a (x varchar(9) DEFAULT 'a;b');  /* blok; yorum */ ALTER TABLE a ADD COLUMN y int; # diyez\nINSERT INTO a VALUES ('it''s;--x', \"q;\"); -- sonda yorum\n");
t('çoklu ifade doğru bölünür (3)', count($x) === 3, json_encode($x));
t('tırnak içi ; bölmez', strpos($x[0], "'a;b'") !== false);
t('tırnak içi -- yorum sayılmaz, doubled quote korunur', $x[2] === "INSERT INTO a VALUES ('it''s;--x', \"q;\")");
t('blok/# / satır sonu yorumları atılır', strpos(implode('', $x), 'blok') === false && strpos(implode('', $x), 'diyez') === false && strpos(implode('', $x), 'sonda') === false);
t('yorumdan hemen sonraki ifade kaybolmaz', count(StagingSql::statements("-- a\nSELECT 1;\n-- b\nSELECT 2;")) === 2);
$thrown = static function (callable $f): bool {
	try {
		$f();
	} catch (RuntimeException $e) {
		return true;
	}

	return false;
};
t('kapanmamış tırnak istisna', $thrown(static fn() => StagingSql::statements("SELECT 'a;")));
t('kapanmamış blok yorumu istisna', $thrown(static fn() => StagingSql::statements("SELECT 1; /* a")));
t('DELIMITER desteklenmez (sessizce bölmez)', $thrown(static fn() => StagingSql::statements("DELIMITER //\nSELECT 1//")));
t('CREATE PROCEDURE/TRIGGER desteklenmez', $thrown(static fn() => StagingSql::statements("CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.x=1;")));
$allParse = true;
$dml = [];
foreach (StagingModules::all() as $m) {
	$files = array_merge([$m['install']], glob($m['migrations'] . '/*.sql') ?: []);
	foreach ($files as $f) {
		try {
			foreach (StagingSql::statements((string) file_get_contents($f)) as $stmt) {
				if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $stmt)) {
					$dml[] = basename($f);
				}
			}
		} catch (RuntimeException $e) {
			$allParse = false;
		}
	}
}
t('tüm gerçek install/migration dosyaları ayrıştırılır', $allParse);
t('gerçek migration/install dosyalarında INSERT/UPDATE/DELETE yok (yeniden çalıştırma güvenli)', $dml === [], implode(',', $dml));
$ai = StagingModules::expectedSchema(StagingModules::all()[0]);
t('AUTO_INCREMENT sütunları çıkarılır (rafimda_vendors.id_vendor)', in_array('id_vendor', $ai['rafimda_vendors']['auto'], true));

$bf = ['key' => 'zq', 'dir' => $tmp, 'tracker' => 'zq_migrations', 'install' => $tmp . '/install.sql', 'migrations' => $tmp . '/migrations'];
$pdo->exec("CREATE TABLE IF NOT EXISTS `zq_migrations` (\n  `version` varchar(32) NOT NULL,\n  PRIMARY KEY (`version`)\n) ENGINE=InnoDB;\n");
@unlink($tmp . '/migrations/004_drop.sql');
file_put_contents($tmp . '/migrations/005_unclosed.sql', "CREATE TABLE zq_x (a int); SELECT 'yarim;\n");
$r = $mig->runModule($bf);
t('ayrıştırılamayan migration FAIL verir ve kaydedilmez', !$r['success'] && !in_array('005_unclosed', $mig->applied('zq_migrations'), true) && strpos(json_encode($r['steps'], JSON_UNESCAPED_UNICODE), 'ayrıştırılamadı') !== false, json_encode($r['steps'], JSON_UNESCAPED_UNICODE));

echo "== İstek bekçisi (staging_guard) kararları\n";
t('env.php yok → kurulum sihirbazı engellenmez', StagingGuard::requestVerdict(null)['allow']);
t('DB_NAME staging → izin', StagingGuard::requestVerdict(['DB_NAME' => 'rafimda_staging'])['allow']);
t('DB_NAME canlı gibi → 503', !StagingGuard::requestVerdict(['DB_NAME' => 'rafimda_live'])['allow']);
t('DB_NAME boş → 503', !StagingGuard::requestVerdict([])['allow']);

echo "== Doğrulayıcı: AUTO_INCREMENT, izin, dış servis\n";
$readerNoAuto = new StagingSchemaReader(static function (string $t) use ($pdo) {
	$r = MysqlToSqlite::readTable($pdo, $t);

	return $r === null ? null : $r + ['auto' => []];
});
$va = new StagingVerifier($root);
$va->checkSchema($readerNoAuto, null);
t('AUTO_INCREMENT eksikliği FAIL', strpos($va->toText(), 'AUTO_INCREMENT eksik: id_vendor') !== false);
$vp = new StagingVerifier($root);
$vp->checkSecretFilePerms('x', 0644);
t('env.php 0644 → FAIL', $vp->failures() === 1);
$vp = new StagingVerifier($root);
$vp->checkSecretFilePerms('x', 0600);
t('env.php 0600 → OK', $vp->failures() === 0 && $vp->warnings() === 0);
$vp = new StagingVerifier($root);
$vp->checkExternalServices(['XML_HUB_SECRET', 'SMTP_PASSWORD', 'NETGSM_USER'], 'smtp');
t('SMTP sürücüsü ve dolu servis anahtarları WARN; kendi gizli anahtarlarımız hariç', $vp->warnings() === 2 && strpos($vp->toText(), 'SMTP_PASSWORD') !== false && strpos($vp->toText(), 'XML_HUB_SECRET') === false);
$vp = new StagingVerifier($root);
$vp->checkExternalServices(['XML_HUB_SECRET'], 'php');
t('temiz kurulumda dış servis uyarısı yok', $vp->warnings() === 0);
$vv = new StagingVerifier($root);
$readerNoVar = new StagingSchemaReader(static fn(string $t) => $t === 'product_variations' ? null : MysqlToSqlite::readTable($pdo, $t));
$vv->checkSchema($readerNoVar, null, true);
t('--init-lazy sonrası product_variations yoksa FAIL', strpos($vv->toText(), '[FAIL] product_variations') !== false);

echo "== Komutlar arası canlı-DB koruması tutarlılığı\n";
$bins = glob(dirname(__DIR__) . '/bin/staging-*.php');
t('5 komut betiği var (env, migrate, preflight, seed, verify)', count($bins) === 5);
foreach ($bins as $b) {
	$c = (string) file_get_contents($b);
	$g = strpos($c, 'staging_guard_or_exit()');
	$conn = strpos($c, 'staging_connect_or_exit(');
	$boot = strpos($c, 'staging_boot_core_or_exit(');
	t(basename($b) . ': bekçi çağrılıyor', $g !== false);
	t(basename($b) . ': bekçi bağlantı/açılıştan ÖNCE', $g !== false && ($conn === false || $g < $conn) && ($boot === false || $g < $boot));
	t(basename($b) . ': bypass bayrağı yok', stripos($c, 'allow-existing') === false && stripos($c, '--force') === false);
}
$cc = (string) file_get_contents(dirname(__DIR__) . '/bin/_common.php');
t('açılış, env.php ile ortam DB uyuşmazsa reddeder', strpos($cc, "(\$cfg['DB_NAME'] ?? '') !== \$env['DB_NAME']") !== false && strpos($cc, '_DB_NAME_ !== $env') !== false);
$seedBin = (string) file_get_contents(dirname(__DIR__) . '/bin/staging-seed.php');
t('seed: gerçek sipariş kontrolü açılıştan önce', strpos($seedBin, 'realOrderCount') < strpos($seedBin, 'staging_boot_core_or_exit('));

echo "== Web'den erişim engeli (deploy/ dizini)\n";
$exposed = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $f) {
	if ($f->isFile() && substr($f->getFilename(), -4) === '.php' && !in_array($f->getFilename(), ['StagingGuard.php', 'staging_guard.php'], true)) {
		$head = implode("\n", array_slice(file($f->getPathname()), 0, 12));

		if (strpos($head, 'PHP_SAPI') === false && strpos($head, "/_common.php'") === false) {
			$exposed[] = substr($f->getPathname(), strlen($root) + 1);
		}
	}
}
t('tüm PHP dosyaları (bekçi hariç) CLI dışında 404 verir', $exposed === [], implode(',', $exposed));
t('nginx parçası /deploy/ engelliyor', strpos((string) file_get_contents(dirname(__DIR__) . '/conf/nginx.staging.conf.snippet'), 'location ^~ /deploy/') !== false);
t('Apache parçası deploy/ engelliyor (robots.staging.txt hariç)', strpos((string) file_get_contents(dirname(__DIR__) . '/conf/apache.staging.htaccess.snippet'), '^deploy(/|$) - [F,L]') !== false);
t('nginx ve Apache X-Robots-Tag içeriyor', strpos((string) file_get_contents(dirname(__DIR__) . '/conf/nginx.staging.conf.snippet'), 'X-Robots-Tag') !== false && strpos((string) file_get_contents(dirname(__DIR__) . '/conf/apache.staging.htaccess.snippet'), 'X-Robots-Tag') !== false);
t('robots.staging.txt tam engel', trim((string) file_get_contents(dirname(__DIR__) . '/conf/robots.staging.txt')) !== '' && strpos((string) file_get_contents(dirname(__DIR__) . '/conf/robots.staging.txt'), "Disallow: /\n") !== false);
$ign = (string) file_get_contents($root . '/.gitignore');
t('.gitignore yedek/döküm/anahtar dosyalarını kapsar', strpos($ign, 'backups/*') !== false && strpos($ign, '*.sql.gz') !== false && strpos($ign, '*.dump') !== false && strpos($ign, '*.key') !== false);
$tracked = (string) @shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files 2>/dev/null');
if ($tracked !== '') {
	t('git\'te izlenen döküm/anahtar/env dosyası yok', !preg_match('/(\.sql\.gz|\.dump|\.sqlite|\.key|(^|\/)env\.php|(^|\/)\.env)$/m', $tracked));
}

echo "== StagingGuard sertleştirme (0.2)\n";
$thr = static function (callable $f): ?string {
	try {
		$f();
	} catch (Throwable $e) {
		return $e->getMessage();
	}

	return null;
};
t('staging: canlı DB adı (RAFIMDA_PROD_DB_NAME) reddedilir', !StagingGuard::check(['DB_NAME' => 'rafimda_live_staging', 'RAFIMDA_PROD_DB_NAME' => 'rafimda_live_staging'] + $good)['ok']);
t('web kararı: canlı alan adı (www.rafimda.com.tr) env.php olmasa da RET', !StagingGuard::requestVerdict(null, 'www.rafimda.com.tr')['allow'] && !StagingGuard::requestVerdict(null, 'rafimda.com.tr:443')['allow']);
t('web kararı: test.rafimda.com.tr kurulum sihirbazına izin', StagingGuard::requestVerdict(null, 'test.rafimda.com.tr')['allow']);
t('web kararı: canlı alan adı + staging DB adı yine RET', !StagingGuard::requestVerdict(['DB_NAME' => 'rafimda_staging'], 'www.rafimda.com.tr')['allow']);
t('web kararı: DB_NAME bilinen canlı adla aynıysa RET', !StagingGuard::requestVerdict(['DB_NAME' => 'rafimda_test'], 'test.rafimda.com.tr', 'rafimda_test')['allow']);
t('web kararı: geçersiz karakterli DB_NAME RET', !StagingGuard::requestVerdict(['DB_NAME' => 'test`x'], 'test.rafimda.com.tr')['allow']);
t('CLI/web ayrımı: cli kabul, web SAPI ve HTTP değişkenleri RET', StagingGuard::isCli('cli', []) && !StagingGuard::isCli('fpm-fcgi', []) && !StagingGuard::isCli('cli', ['REQUEST_METHOD' => 'GET']));
t('connect: web SAPI\'sinde açılmaz', ($r = $thr(static fn() => StagingGuard::connect($good, 'apache2handler'))) !== null && strpos($r, 'komut satırı') !== false);
t('connect: guard geçmezse (canlı DB adı) açılmaz', ($r = $thr(static fn() => StagingGuard::connect(['DB_NAME' => 'rafimda_live', 'RAFIMDA_PROD_DB_NAME' => 'rafimda_live'] + $good, 'cli'))) !== null && strpos($r, 'kontrolü geçmedi') !== false);
t('connect: env eksikse açılmaz', $thr(static fn() => StagingGuard::connect([], 'cli')) !== null);
t('connect: yanlış onay ifadesiyle açılmaz', $thr(static fn() => StagingGuard::connect(['RAFIMDA_STAGING_CONFIRM' => 'evet'] + $good, 'cli')) !== null);
t('StagingGuard\'ta tek new PDO ve connect içinde check() çağrısı var', substr_count((string) file_get_contents(dirname(__DIR__) . '/lib/StagingGuard.php'), 'new PDO(') === 1 && strpos((string) file_get_contents(dirname(__DIR__) . '/lib/StagingGuard.php'), 'self::check($env)') !== false);
t('scrub kullanıcı adını da maskeler', strpos(StagingGuard::scrub("denied for 'stguser1'", ['DB_USER' => 'stguser1']), 'stguser1') === false);
$guardPhp = (string) file_get_contents(dirname(__DIR__) . '/conf/staging_guard.php');
t('staging_guard.php istek adresini ve canlı DB adını bekçiye iletir', strpos($guardPhp, "HTTP_HOST") !== false && strpos($guardPhp, 'RAFIMDA_PROD_DB_NAME') !== false);

echo "== Mevcut veri korunur (izole SQLite; gerçek MySQL değil)\n";
$dp = new SqliteMysqlPDO();
$dp->exec('CREATE TABLE users (id_user INTEGER PRIMARY KEY, email TEXT)');
$dp->exec('CREATE TABLE products (id_product INTEGER PRIMARY KEY, product_name TEXT)');
$dp->exec('CREATE TABLE orders (id_order INTEGER PRIMARY KEY, total REAL)');
$dp->exec("INSERT INTO users VALUES (1,'a@x.test'),(2,'b@x.test')");
$dp->exec("INSERT INTO products VALUES (1,'Ürün A'),(2,'Ürün B'),(3,'Ürün C')");
$dp->exec('INSERT INTO orders VALUES (1, 100.5), (2, 20)');
$snap = static fn(): string => md5(json_encode([$dp->query('SELECT * FROM users ORDER BY 1')->fetchAll(), $dp->query('SELECT * FROM products ORDER BY 1')->fetchAll(), $dp->query('SELECT * FROM orders ORDER BY 1')->fetchAll()]));
$before = $snap();
$dcol = static function (string $t, string $c) use ($dp): bool {
	foreach ($dp->query('PRAGMA table_info(`' . $t . '`)')->fetchAll() as $r) {
		if ($r['name'] === $c) {
			return true;
		}
	}

	return false;
};
$dm = new StagingMigrator($dp, $dcol);
$okAll = true;
foreach (StagingModules::all() as $m) {
	$okAll = $dm->runModule($m, true)['success'] && $okAll;
}
t('dry-run: tüm modüller planlanır, veri değişmez', $okAll && $snap() === $before);
foreach (StagingModules::all() as $m) {
	$okAll = $dm->runModule($m)['success'] && $okAll;
}
t('apply: modül tabloları eklenir, kullanıcı/ürün/sipariş verisi birebir aynı', $okAll && $snap() === $before && (int) $dp->query('SELECT COUNT(*) FROM users')->fetchColumn() === 2 && (int) $dp->query('SELECT COUNT(*) FROM products')->fetchColumn() === 3);
foreach (['DROP TABLE users', 'TRUNCATE TABLE orders', 'DELETE FROM products', 'RENAME TABLE users TO u2', 'ALTER TABLE products DROP COLUMN product_name'] as $bad) {
	t('yıkıcı ifade reddedilir: ' . $bad, StagingMigrator::isDestructive($bad));
}

echo "== Staging DB izolasyonu (isolationFindings; MySQL'e bağlanmaz)\n";
$okGrants = ["GRANT USAGE ON *.* TO `u`@`localhost`", "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON `rafimda\\_staging`.* TO `u`@`localhost`"];
t('yalnızca staging DB yetkisi + canlı görünmüyor → bulgu yok', StagingGuard::isolationFindings($okGrants, ['information_schema', 'rafimda_staging'], 'rafimda_staging', 'rafimda_live') === []);
t('ON *.* ALL PRIVILEGES → bulgu', StagingGuard::isolationFindings(["GRANT ALL PRIVILEGES ON *.* TO `u`@`localhost`"], ['rafimda_staging'], 'rafimda_staging', 'rafimda_live') !== []);
t('canlı DB\'ye yetki → bulgu', StagingGuard::isolationFindings(array_merge($okGrants, ["GRANT SELECT ON `rafimda\\_live`.* TO `u`@`localhost`"]), ['rafimda_staging'], 'rafimda_staging', 'rafimda_live') !== []);
t('SHOW DATABASES canlı DB\'yi gösteriyorsa → bulgu', StagingGuard::isolationFindings($okGrants, ['rafimda_staging', 'Rafimda_Live'], 'rafimda_staging', 'rafimda_live') !== []);
t('joker (%) veritabanı yetkisi → bulgu', StagingGuard::isolationFindings(["GRANT SELECT ON `rafimda\\_%`.* TO `u`@`localhost`"], ['rafimda_staging'], 'rafimda_staging', 'rafimda_live') !== []);
t('bağlı DB staging gibi değilse → bulgu', StagingGuard::isolationFindings($okGrants, [], 'rafimda_live', '') !== []);
$pre = (string) file_get_contents(dirname(__DIR__) . '/bin/staging-preflight.php');
t('staging-preflight: bekçi + salt okunur (yalnızca SELECT/SHOW)', strpos($pre, 'staging_guard_or_exit()') !== false && !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER|CREATE)\b\s/i', preg_replace('#/\*.*?\*/#s', '', $pre)));
t('staging-db.sql.example: tüm satırlar yorum (çalıştırılmaz) ve DROP/GRANT OPTION/*.* içermez', !preg_match('/^(?!--)\s*\S/m', (string) file_get_contents(dirname(__DIR__) . '/conf/staging-db.sql.example')) && !preg_match('/^-- GRANT[^\n]*(\*\.\*|WITH GRANT OPTION)/im', (string) file_get_contents(dirname(__DIR__) . '/conf/staging-db.sql.example')));
t('.user.ini örneği mutlak yol ve açıklama içerir', strpos((string) file_get_contents(dirname(__DIR__) . '/conf/staging.user.ini.example'), 'auto_prepend_file = /') !== false);
t('deploy/staging/conf/.htaccess yalnızca robots.staging.txt\'e izin verir', preg_match('/<Files "robots\.staging\.txt">/', (string) file_get_contents(dirname(__DIR__) . '/conf/.htaccess')) === 1 && substr_count((string) file_get_contents(dirname(__DIR__) . '/conf/.htaccess'), '<Files') === 1);
t('nginx.conf.example deploy/ bloğunu içerir', strpos((string) file_get_contents($root . '/nginx.conf.example'), 'location ^~ /deploy/') !== false);

echo "== Güvenlik / depo hijyeni\n";
$bad = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $f) {
	if ($f->isFile() && preg_match('/\.(php|md|snippet|txt|example)$/', $f->getFilename()) && !strpos($f->getPathname(), '/tests/')) {
		$c = (string) file_get_contents($f->getPathname());
		if (preg_match('/(DB_PASS\s*=\s*[^\s#]+|BEGIN (RSA |EC )?PRIVATE KEY|api[_-]?key\s*=\s*\S{12,})/i', $c)) {
			$bad[] = $f->getPathname();
		}
	}
}
t('pakette gömülü parola/anahtar yok', $bad === [], implode(',', $bad));
$ign = (string) file_get_contents($root . '/.gitignore');
t('config/env.php .gitignore\'da', strpos($ign, 'config/env.php') !== false);
t('bin/ betikleri CLI dışında çalışmaz (_common.php kontrolü)', strpos((string) file_get_contents(dirname(__DIR__) . '/bin/_common.php'), 'StagingGuard::isCli(PHP_SAPI, $_SERVER)') !== false);

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
