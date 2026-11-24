<?php
// Cron uç noktasını ayrı süreçte çalıştırır: php cron_probe.php <anahtar|-> ; çıktı: "<http kodu>|<gövde>"
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';
xh_fresh_db(); foreach (XhMigrator::files() as $v) { DB::execute("INSERT INTO xh_migrations (version) VALUES (?)", [$v]); }
Settings::set('XML_HUB_CRON_KEY', 'GIZLI-ANAHTAR-123');
if (($argv[1] ?? '-') !== '-') { $_GET['key'] = $argv[1]; }
if (($argv[2] ?? '') === 'pending') { $GLOBALS['db']->exec('DELETE FROM xh_migrations'); }
defined('IN_SCRIPT') || define('IN_SCRIPT', 1);
register_shutdown_function(function () { echo '|' . (http_response_code() ?: 200); });
ob_start();
include dirname(__DIR__) . '/front/cron.php';
