<?php
/** Modül yükleme testi: gerçek core/ModuleBase.php ile, Module::loadInstance kurallarını birebir uygular. */
error_reporting(E_ALL);
define('IN_SCRIPT', true);
class DB { public static function execute() { return true; } }
class Settings { public static function get($k) { return ''; } }
require dirname(__DIR__, 3) . '/core/ModuleBase.php';
$fail = 0;
function t(bool $c, string $n): void { global $fail; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) { $fail++; } }

$name = 'xml-hub';
$class = implode('', array_map('ucfirst', explode('-', $name))) . 'Module'; // core/Module.php:1172 ile aynı kural
require dirname(__DIR__) . '/' . $name . '.php';
t(class_exists($class), "sınıf adı kurala uyuyor: $class");
$m = new $class();
t($m instanceof ModuleBase, 'ModuleBase\'den türüyor');
t($m->name === $name, 'name özelliği klasör adıyla aynı');
t(count($m->routes) === 2, '2 rota tanımlı');
foreach ($m->routes as $slug => $file) {
	t(preg_match('/^[a-z0-9_-]+$/', $slug) === 1, "rota slug'ı tek segment ve güvenli: $slug");
	t(is_file(dirname(__DIR__) . '/' . $file), "rota dosyası var: $file");
}
t(method_exists($m, 'install') && method_exists($m, 'uninstall') && method_exists($m, 'adminPage'), 'install/uninstall/adminPage var');
t(is_file(dirname(__DIR__) . '/assets/templates/admin/admin.tpl'), 'admin şablonu var');
// runSqlFile her ifadeyi atlamadan çalıştırmalı: '--' ile başlayan ifade ATLANIR, bu yüzden kurulum dosyalarında olmamalı
foreach (['install.sql', 'uninstall.sql', 'migrations/001_init.sql', 'migrations/002_source_meta_credentials.sql'] as $f) {
	$sql = file_get_contents(dirname(__DIR__) . '/' . $f);
	$stmts = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)));
	$skipped = array_filter($stmts, fn($s) => strpos($s, '--') === 0);
	t($skipped === [], "$f: hiçbir ifade '--' ile başlamıyor (runSqlFile atlamaz), ifade sayısı " . count($stmts));
}
exit($fail ? 1 : 0);
