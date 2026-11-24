<?php
/**
 * Gerçek RvContext/RvPerm ile (yalnızca oturum açan kullanıcı simüle edilir) satıcı sayfalarının yetki kapılarını ölçer.
 * Kullanım: php rbac_probe.php <xh|mh> <userId> <GET|POST> [view]  → "<kod>|<işaret>"
 * Kullanıcılar: 1=sahip(owner) 2=görüntüleyici(viewer) 3=sipariş(orders) 4=katalog(catalog) 5=yönetici(manager) 6=satıcı değil 7=B satıcısının sahibi 0=oturum yok
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$mod = $argv[1] ?? 'xh';
$uid = (int) ($argv[2] ?? 0);
$method = $argv[3] ?? 'GET';
$view = $argv[4] ?? '';
require __DIR__ . '/bootstrap.php';
if ($mod === 'mh') {
	foreach (['MhMigrator', 'MhCrypt', 'MhOwner', 'MhAdapter', 'MhAbstractAdapter', 'MhRegistry', 'MhRepo', 'MhPayload', 'MhDispatcher', 'MhPanel'] as $c) { require_once dirname(__DIR__, 2) . '/marketplace-hub/lib/' . $c . '.php'; }
	require_once dirname(__DIR__, 2) . '/marketplace-hub/marketplace-hub.php';
}
xh_fresh_db();
if ($mod === 'mh') {
	$GLOBALS['db']->exec('CREATE TABLE mh_migrations (version TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
	foreach (glob(dirname(__DIR__, 2) . '/marketplace-hub/migrations/*.sql') as $f) { $GLOBALS['db']->exec(xh_mysql_to_sqlite(file_get_contents($f))); }
}
$d = $GLOBALS['db'];
$d->exec("INSERT INTO users (id_user, user_full_name, email) VALUES (3,'C','c@x'),(4,'D','d@x'),(5,'E','e@x'),(6,'F','f@x'),(7,'G','g@x')");
$d->exec("INSERT INTO rafimda_vendors (id_vendor, owner_user_id, store_name, store_slug, status) VALUES (1,1,'A','a','active'),(2,7,'B','b','active')");
foreach ([[1, 1, 'owner'], [1, 2, 'viewer'], [1, 3, 'orders'], [1, 4, 'catalog'], [1, 5, 'manager'], [2, 7, 'owner']] as [$v, $u, $r]) {
	$d->exec("INSERT INTO rafimda_vendor_staff (id_vendor, id_user, role, status) VALUES ($v, $u, '$r', 'active')");
}
$_SESSION = $uid > 0 ? ['id_user' => $uid] : [];
$_SERVER['REQUEST_METHOD'] = $method;
if ($view !== '') { $_GET['view'] = $view; }
if ($method === 'POST') { $_POST = ['token' => 'x', ($mod === 'xh' ? 'xhAction' : 'mhAction') => 'queue_tick']; }
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rbac_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true);
$domain = 'https://t.local';
defined('IN_SCRIPT') || define('IN_SCRIPT', 1);
if ($mod === 'xh') { require_once dirname(__DIR__) . '/xml-hub.php'; }
register_shutdown_function(function () { $o = (string) ob_get_contents(); ob_end_clean(); $code = http_response_code() ?: 200; echo $code . '|' . (strpos($o, 'class="xh-top"') !== false ? 'PAGE' : substr(trim(strip_tags($o)), 0, 70)) . '|' . (strpos($o, 'name="token"') !== false ? 'FORMS' : 'NOFORM'); });
ob_start();
include dirname(__DIR__, 2) . '/' . ($mod === 'xh' ? 'xml-hub' : 'marketplace-hub') . '/front/hub.php';
