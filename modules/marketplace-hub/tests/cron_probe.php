<?php
// php cron_probe.php <anahtar|-> [pending] → "<json>|<http kodu>"
require __DIR__ . '/bootstrap.php';
mh_fresh_db();
Settings::set('MARKETPLACE_HUB_CRON_KEY', 'MH-GIZLI-KEY-1');
if (($argv[1] ?? '-') !== '-') { $_GET['key'] = $argv[1]; }
if (($argv[2] ?? '') === 'pending') { $GLOBALS['db']->exec('DELETE FROM mh_migrations'); }
defined('IN_SCRIPT') || define('IN_SCRIPT', 1);
register_shutdown_function(function () { echo '|' . (http_response_code() ?: 200); });
include dirname(__DIR__) . '/front/cron.php';
