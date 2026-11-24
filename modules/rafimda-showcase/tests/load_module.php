<?php
/** Modül yükleme testi: gerçek core/ModuleBase.php ile, Module::loadInstance kurallarını birebir uygular. */
error_reporting(E_ALL);
define('IN_SCRIPT', true);
class DB { public static function execute() { return true; } }
class Settings { public static function get($k) { return ''; } }
require dirname(__DIR__, 3) . '/core/ModuleBase.php';
$fail = 0; $pass = 0;
function t(bool $c, string $n): void { global $fail, $pass; if ($c) { $pass++; } echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) { $fail++; } }

$name = 'rafimda-showcase';
$class = implode('', array_map('ucfirst', explode('-', $name))) . 'Module';
require dirname(__DIR__) . '/' . $name . '.php';
t(class_exists($class), "sınıf adı kurala uyuyor: $class");
$m = new $class();
t($m instanceof ModuleBase && $m->name === $name, 'ModuleBase\'den türüyor, name klasör adıyla aynı');
t(method_exists($m, 'install') && method_exists($m, 'uninstall') && method_exists($m, 'adminPage') && method_exists($m, 'renderDisplayHook'), 'install/uninstall/adminPage/renderDisplayHook var');
t(is_file(dirname(__DIR__) . '/assets/templates/admin/admin.tpl') && is_file(dirname(__DIR__) . '/assets/templates/front/showcase.tpl') && is_file(dirname(__DIR__) . '/assets/css/showcase.css'), 'şablonlar ve CSS var');
t(isset($m->displayHooks['home_showcase']) && isset($m->displayHooks['home_bottom']), 'iki kanca tanımlı'); t($m->defaultDisplayHooks === ['home_bottom', 'product_detail'], 'varsayılan kancalar home_bottom + product_detail (theme4 home_showcase çizmez; ürün sayfası son gezilenleri kaydeder)');
foreach ($m->frontStylesheets as $css) { t(is_file(dirname(__DIR__) . '/assets/css/' . $css), "frontStylesheets dosyası var: $css"); }
foreach (['install.sql', 'uninstall.sql'] as $f) {
	$stmts = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', file_get_contents(dirname(__DIR__) . '/' . $f))));
	t(array_filter($stmts, fn($s) => strpos($s, '--') === 0) === [], "$f: hiçbir ifade '--' ile başlamıyor, ifade sayısı " . count($stmts));
}
t($m->renderDisplayHook('footer') === null && $m->renderDisplayHook('product') === null, 'ilgisiz hook\'larda null döner');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
