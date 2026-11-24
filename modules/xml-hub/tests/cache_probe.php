<?php
// Gerçek çekirdek Tools + Performance ile cron isteğinin sayfa önbelleğine girip girmediğini ölçer (ayrı süreç).
// Kullanım: php cache_probe.php <REQUEST_URI> ; çıktı: CACHEABLE=0|1
class Customer { public static function isLoggedIn(): bool { return false; } }
class Settings { public static function get($k) { return ''; } }
require dirname(__DIR__, 3) . '/config/function.php';
require dirname(__DIR__, 3) . '/core/Performance.php';
$uri = $argv[1] ?? '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $uri;
parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
$m = new ReflectionMethod('Performance', 'canUsePageCache');
$m->setAccessible(true);
echo 'CACHEABLE=' . ($m->invoke(null) ? '1' : '0') . "\n";
