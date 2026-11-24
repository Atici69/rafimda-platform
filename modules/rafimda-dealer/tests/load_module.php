<?php
/** Modül yükleme testi: gerçek core/ModuleBase.php ile Module::loadInstance kurallarını birebir uygular. */
error_reporting(E_ALL);
define('IN_SCRIPT', true);
class DB { public static function execute() { return true; } }
class Settings { public static function get($k) { return ''; } }
$root = dirname(__DIR__, 3);
require $root . '/core/ModuleBase.php';
$pass = 0; $fail = 0;
function t(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; } echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; }
$name = 'rafimda-dealer';
$class = implode('', array_map('ucfirst', explode('-', $name))) . 'Module';
require dirname(__DIR__) . '/' . $name . '.php';
t(class_exists($class), "sınıf adı kurala uyuyor: $class");
$m = new $class();
t($m instanceof ModuleBase && $m->name === $name, 'ModuleBase türevi, name = klasör adı');
t(method_exists($m, 'install') && method_exists($m, 'uninstall') && method_exists($m, 'adminPage') && method_exists($m, 'boot'), 'install/uninstall/adminPage/boot var');
t(is_file(dirname(__DIR__) . '/assets/templates/admin/admin.tpl'), 'admin şablonu var');
foreach ($m->routes as $slug => $file) {
	t(is_file(dirname(__DIR__) . '/' . $file), "rota dosyası var: $slug → $file");
	t(!is_file($root . '/container/front/' . $slug . '.php'), "çekirdekte aynı adlı sayfa yok: $slug");
}
$others = [];
foreach (glob($root . '/modules/*/*.php') as $f) {
	if (basename(dirname($f)) === $name || basename($f, '.php') !== basename(dirname($f))) { continue; }
	$src = file_get_contents($f);
	if (preg_match('/\$routes\s*=\s*\[(.*?)\];/s', $src, $mm) && preg_match_all("/'([a-z0-9\-]+)'\s*=>/", $mm[1], $slugs)) {
		foreach ($slugs[1] as $s) { $others[$s] = basename(dirname($f)); }
	}
}
foreach (array_keys($m->routes) as $slug) { t(!isset($others[$slug]), "rota başka modülle çakışmıyor: $slug"); }
foreach (['install.sql', 'uninstall.sql'] as $f) {
	$stmts = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', file_get_contents(dirname(__DIR__) . '/' . $f))));
	t(array_filter($stmts, fn($s) => strpos($s, '--') === 0) === [], "$f: hiçbir ifade '--' ile başlamıyor");
}
t(is_file(dirname(__DIR__) . '/uninstall.destructive.sql.manual'), 'yıkıcı kaldırma dosyası otomatik değil (.manual)');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
