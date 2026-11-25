<?php
/**
 * Güncelleme sistemi çevrimdışı testi (veritabanı/ağ gerektirmez).
 * Çalıştırma: php tests/update_installer_test.php
 */
error_reporting(E_ALL);

class Settings
{
	public static array $d = [];
	public static function get($k) { return self::$d[$k] ?? ''; }
	public static function set($k, $v) { self::$d[$k] = (string) $v; }
}
class Performance
{
	public static function clearCaches(): array { return ['message' => 'stub']; }
}
class Schema
{
	public static function forceEnsure(): void {}
}

$repo = dirname(__DIR__);
foreach (['FShop', 'Security', 'Upgrade', 'UpdateChecker', 'UpdateInstaller'] as $c) {
	require_once $repo . '/core/' . $c . '.php';
}

$pass = 0;
$fail = 0;
function ok(bool $cond, string $name): void
{
	global $pass, $fail;
	if ($cond) { $pass++; echo "  OK   $name\n"; } else { $fail++; echo "  FAIL $name\n"; }
}
function rrm(string $d): void
{
	if (!is_dir($d)) { return; }
	foreach (scandir($d) as $i) {
		if ($i === '.' || $i === '..') { continue; }
		$p = "$d/$i";
		is_dir($p) && !is_link($p) ? rrm($p) : @unlink($p);
	}
	@rmdir($d);
}
function w(string $f, string $c): void
{
	@mkdir(dirname($f), 0755, true);
	file_put_contents($f, $c);
}
function fshop(string $ver, string $channel = 'rafimda'): string
{
	return "<?php\nclass FShop\n{\n\tpublic const CHANNEL = '$channel';\n\tpublic const VERSION = '$ver';\n}\n";
}
function makeSite(string $root, string $ver): void
{
	rrm($root);
	w("$root/core/FShop.php", fshop($ver));
	foreach (['Upgrade', 'UpdateInstaller', 'UpdateChecker'] as $c) { w("$root/core/$c.php", "<?php\nclass {$c}Dummy {}\n"); }
	w("$root/config/settings.php", "<?php\n");
	w("$root/config/env.php", "<?php // SECRET-ENV\n");
	w("$root/index.php", "<?php // index v$ver\n");
	w("$root/.htaccess", "# cpanel php handler\n");
	w("$root/img/products/a.jpg", 'IMG');
	w("$root/storage/keep.txt", 'KEEP');
	w("$root/upgrade/migrations/.gitkeep", '');
	w("$root/templates/t.tpl", "tpl v$ver");
}
function makeZip(string $siteDir, string $zip, string $top = 'pkg'): void
{
	@unlink($zip);
	$z = new ZipArchive();
	$z->open($zip, ZipArchive::CREATE);
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteDir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$rel = ltrim(substr($f->getPathname(), strlen($siteDir)), '/');
		$z->addFile($f->getPathname(), "$top/$rel");
	}
	$z->close();
}
function snapshot(string $root): array
{
	$o = [];
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$rel = substr($f->getPathname(), strlen($root));
		if (strpos($rel, '/cache/') === 0 || strpos($rel, '/backups/') === 0 || strpos($rel, '/logs/') === 0) { continue; }
		$o[$rel] = md5_file($f->getPathname());
	}
	ksort($o);
	return $o;
}

$tmp = sys_get_temp_dir() . '/rt_upd_' . getmypid();
rrm($tmp);
mkdir($tmp, 0755, true);
$site = "$tmp/site";
$pkgDir = "$tmp/pkgsrc";
$zip = "$tmp/p.zip";

Upgrade::$rootOverride = $site;
UpdateInstaller::$rootOverride = $site;

function offer(string $ver, string $zip, ?string $sha = null): array
{
	global $zip;
	UpdateInstaller::$downloadOverride = static function ($url, $dest) use ($zip) { return copy($zip, $dest); };
	return ['version' => $ver, 'download_url' => 'https://example.test/p.zip', 'min_php' => '7.4', 'sha256' => $sha ?? ''];
}
function reset_state(string $ver): void
{
	global $site;
	Settings::$d = ['FS_VERSION' => $ver];
	UpdateInstaller::$copyHook = null;
	makeSite($site, $ver);
}

echo "1) Başarılı güncelleme\n";
reset_state('3.0.2');
makeSite($pkgDir, '3.0.3');
w("$pkgDir/core/NewFile.php", "<?php // new\n");
w("$pkgDir/config/env.php", "<?php // OVERWRITE-ATTEMPT\n");
w("$pkgDir/.htaccess", "# package htaccess\n");
w("$pkgDir/img/products/a.jpg", 'PKG-IMG');
w("$pkgDir/storage/keep.txt", 'PKG');
makeZip($pkgDir, $zip);
$sha = hash_file('sha256', $zip);
$r = UpdateInstaller::runFullUpdate(offer('3.0.3', $zip, $sha));
ok($r['success'], 'güncelleme başarılı: ' . $r['message']);
ok(Upgrade::readCodeVersionFromDisk() === '3.0.3', 'disk sürümü 3.0.3');
ok(Settings::get('FS_VERSION') === '3.0.3', 'şema sürümü 3.0.3');
ok(is_file("$site/core/NewFile.php"), 'yeni dosya eklendi');
ok(trim(file_get_contents("$site/templates/t.tpl")) === 'tpl v3.0.3', 'şablon güncellendi');
ok(strpos(file_get_contents("$site/config/env.php"), 'SECRET-ENV') !== false, 'env.php korundu');
ok(file_get_contents("$site/.htaccess") === "# cpanel php handler\n", 'kök .htaccess korundu');
ok(file_get_contents("$site/img/products/a.jpg") === 'IMG', 'img/ korundu');
ok(file_get_contents("$site/storage/keep.txt") === 'KEEP', 'storage/ korundu');
ok(Settings::get('FS_MAINTENANCE') === '0', 'bakım modu kapandı');
ok(count(glob("$site/backups/update-*")) === 1, 'yedek oluştu');
ok(is_file("$site/logs/update.log"), 'update.log yazıldı');
ok(!is_dir("$site/cache/updates/" . basename(glob("$site/cache/updates/*")[0] ?? 'x')) || count(array_diff(scandir("$site/cache/updates"), ['.', '..', 'update.lock'])) === 0, 'staging temizlendi');

echo "2) Yanlış kanal (FriSay paketi) reddedilir\n";
reset_state('3.0.2');
makeSite($pkgDir, '3.1.0');
w("$pkgDir/core/FShop.php", fshop('3.1.0', 'frisay'));
makeZip($pkgDir, $zip);
$before = snapshot($site);
$r = UpdateInstaller::runFullUpdate(offer('3.1.0', $zip));
ok(!$r['success'], 'reddedildi: ' . $r['message']);
ok(snapshot($site) === $before, 'hiçbir dosya değişmedi');
ok(Settings::get('FS_MAINTENANCE') === '0', 'bakım modu kapalı');
ok(Upgrade::readCodeVersionFromDisk() === '3.0.2', 'sürüm 3.0.2 kaldı');

echo "3) SHA-256 uyuşmazlığı reddedilir\n";
reset_state('3.0.2');
makeSite($pkgDir, '3.0.3');
makeZip($pkgDir, $zip);
$before = snapshot($site);
$r = UpdateInstaller::runFullUpdate(offer('3.0.3', $zip, str_repeat('a', 64)));
ok(!$r['success'] && strpos($r['message'], 'SHA') !== false, 'sha reddi: ' . $r['message']);
ok(snapshot($site) === $before, 'dosyalar değişmedi');

echo "4) Şema ileride ise güncelleme engellenir (3.1.0 şema / 3.0.2 kod)\n";
reset_state('3.1.0');
w("$site/core/FShop.php", fshop('3.0.2')); // şema 3.1.0, dosyalar 3.0.2 (canlıdaki durum)
makeSite($pkgDir, '3.0.3');
makeZip($pkgDir, $zip);
$before = snapshot($site);
$r = UpdateInstaller::runFullUpdate(offer('3.0.3', $zip));
ok(!$r['success'] && Upgrade::isSchemaAhead(), 'engellendi: ' . substr($r['message'], 0, 80));
ok(snapshot($site) === $before, 'dosyalar değişmedi');
$p = Upgrade::runPending();
ok(!$p['success'], 'runPending de engelliyor');
$rc = Upgrade::reconcileSchemaVersion();
ok($rc['success'] && Settings::get('FS_VERSION') === '3.0.2' && !Upgrade::isSchemaAhead(), 'eşitleme yalnızca FS_VERSION değerini çekti');
$rc2 = Upgrade::reconcileSchemaVersion();
ok(!$rc2['success'], 'ikinci eşitleme işlem yapmadı');

echo "5) Paket sürümü tekliften farklı reddedilir\n";
reset_state('3.0.2');
makeSite($pkgDir, '3.0.9');
makeZip($pkgDir, $zip);
$r = UpdateInstaller::runFullUpdate(offer('3.0.3', $zip));
ok(!$r['success'] && strpos($r['message'], 'uyuşmuyor') !== false, 'sürüm uyuşmazlığı: ' . substr($r['message'], 0, 90));

echo "6) Kopyalama ortasında hata → tam geri alma\n";
reset_state('3.0.2');
makeSite($pkgDir, '3.0.3');
w("$pkgDir/core/NewFile.php", "<?php // new\n");
makeZip($pkgDir, $zip);
$before = snapshot($site);
$n = 0;
UpdateInstaller::$copyHook = static function ($rel) use (&$n) {
	if (++$n === 3) { throw new RuntimeException('disk dolu (simüle)'); }
};
$r = UpdateInstaller::runFullUpdate(offer('3.0.3', $zip));
ok(!$r['success'], 'başarısız raporlandı: ' . substr($r['message'], 0, 70));
ok(snapshot($site) === $before, 'tüm dosyalar eski haline döndü (karışık sürüm yok)');
ok(Upgrade::readCodeVersionFromDisk() === '3.0.2', 'sürüm 3.0.2');
ok(Settings::get('FS_MAINTENANCE') === '0', 'bakım modu kapandı');
ok(!is_file("$site/core/NewFile.php"), 'yeni oluşturulan dosya silindi');

echo "7) Path traversal içeren ZIP reddedilir\n";
reset_state('3.0.2');
makeSite($pkgDir, '3.0.3');
makeZip($pkgDir, $zip);
$z = new ZipArchive();
$z->open($zip);
$z->addFromString('pkg/../../evil.php', '<?php echo 1;');
$z->close();
$before = snapshot($site);
$r = UpdateInstaller::runFullUpdate(offer('3.0.3', $zip));
ok(!$r['success'], 'traversal reddi: ' . substr($r['message'], 0, 70));
ok(!is_file("$tmp/evil.php") && !is_file("$site/evil.php"), 'dışarı dosya yazılmadı');
ok(snapshot($site) === $before, 'dosyalar değişmedi');

echo "8) Eşzamanlı güncelleme kilidi\n";
reset_state('3.0.2');
makeSite($pkgDir, '3.0.3');
makeZip($pkgDir, $zip);
@mkdir("$site/cache/updates", 0755, true);
$lockFp = fopen("$site/cache/updates/update.lock", 'c');
flock($lockFp, LOCK_EX | LOCK_NB);
$r = UpdateInstaller::runFullUpdate(offer('3.0.3', $zip));
ok(!$r['success'] && strpos($r['message'], 'Başka bir güncelleme') !== false, 'kilit reddi');
flock($lockFp, LOCK_UN);
fclose($lockFp);

echo "9) Takılı kalmış bakım modu otomatik açılır\n";
reset_state('3.0.2');
Settings::set('FS_MAINTENANCE', '1');
Settings::set('FS_MAINTENANCE_SINCE', (string) (time() - 60));
ok(UpdateInstaller::isMaintenance() === true, 'taze bakım açık');
Settings::set('FS_MAINTENANCE_SINCE', (string) (time() - UpdateInstaller::MAINTENANCE_TTL - 5));
ok(UpdateInstaller::isMaintenance() === false && Settings::get('FS_MAINTENANCE') === '0', 'süresi dolan bakım temizlendi');
Settings::set('FS_MAINTENANCE', '1');
Settings::set('FS_MAINTENANCE_SINCE', '');
ok(UpdateInstaller::isMaintenance() === false, 'zaman damgasız eski bayrak temizlendi');

echo "10) Başarılı güncelleme sonrası ikinci çalıştırma güncel bulur\n";
reset_state('3.0.2');
makeSite($pkgDir, '3.0.3');
makeZip($pkgDir, $zip);
UpdateInstaller::runFullUpdate(offer('3.0.3', $zip));
$r = UpdateInstaller::runFullUpdate(offer('3.0.3', $zip));
ok($r['success'] && strpos($r['message'], 'yeni değil') !== false, 'yinelenen çalıştırma zararsız');

echo "11) Korumalı yol denetimi\n";
foreach (['config/env.php', '.htaccess', 'img/a.jpg', 'storage/x', 'backups/y', 'logs/update.log'] as $p) { ok(UpdateInstaller::isProtectedPath($p), "korumalı: $p"); }
foreach (['core/FShop.php', 'admin/.htaccess', 'templates/a.tpl', 'modules/x/img/a.png'] as $p) { ok(!UpdateInstaller::isProtectedPath($p), "korumasız: $p"); }

rrm($tmp);
echo "\nSonuç: $pass geçti, $fail başarısız\n";
exit($fail > 0 ? 1 : 0);
