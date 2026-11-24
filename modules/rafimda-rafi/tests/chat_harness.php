<?php
// Test yardımcı: api/chat.php'yi ayrı süreçte çalıştırır (exit içerdiği için). Yalnızca CLI.
if (PHP_SAPI !== 'cli') { exit; }
$in = json_decode($argv[1], true);
ob_start();
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/rafimda-vendor/lib/RvStorefront.php';
require_once dirname(__DIR__) . '/lib/RafiEngine.php';
require_once dirname(__DIR__) . '/lib/RafiBot.php';
require __DIR__ . '/run_seed.php';
seed();
ob_end_clean();
defined('IN_SCRIPT') || define('IN_SCRIPT', true);
$_SERVER['REQUEST_METHOD'] = $in['m'];
$_POST = $in['p'];
if ($in['t']) { $_POST['token'] = Security::getCsrfToken('front'); }
$_SESSION['rafi_hits'] = array_fill(0, (int) $in['h'], time());
register_shutdown_function(function () { $raw = ob_get_clean(); echo json_encode(['code' => http_response_code(), 'raw' => $raw, 'body' => json_decode((string) $raw, true), 'session_last' => $_SESSION['rafi_last'] ?? []]); });
ob_start();
require dirname(__DIR__) . '/api/chat.php';
