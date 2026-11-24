<?php
/** Akış sürücüsü: tek senaryoyu ayrı süreçte çalıştırır (exit/redirect nedeniyle). Çıktı: sayfa HTML'i + "REDIRECT:<url>" satırları. */
require __DIR__ . '/bootstrap.php';
rd_fresh_db();
rd_install();
RdView::$onRedirect = static function (string $url): void { echo "\nREDIRECT:" . $url . "\n"; exit(0); };
$scenario = $argv[1] ?? '';
$mk = static function (int $uid, string $name, string $status = 'active', int $parent = 0): int {
	$id = RdDealer::create($uid, RdDealer::validate(['company_name' => $name, 'contact_name' => 'Yetkili Kişi', 'phone' => '05321112233', 'email' => "b$uid@x.com", 'city' => 'İzmir'])['clean'])['id_dealer'];
	if ($parent) { DB::update('rd_dealers', ['id_parent' => $parent], 'id_dealer = :d', ['d' => $id]); }
	RdDealer::setStatus($id, $status, 1);
	return $id;
};
Customer::$users = ['bayi@x.com' => ['id' => 2, 'pass' => 'dogru123'], 'musteri@x.com' => ['id' => 3, 'pass' => 'dogru123'], 'alt@x.com' => ['id' => 4, 'pass' => 'dogru123']];
$_SERVER['REQUEST_METHOD'] = 'GET';
$post = static function (array $p): void { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $p + ['token' => 'csrf-test']; };

switch ($scenario) {
	case 'login_get': RdAuthFlow::login(); break;
	case 'login_bad': $post(['login' => 'bayi@x.com', 'password' => 'yanlis']); RdAuthFlow::login(); break;
	case 'login_dealer': $mk(2, 'Mega Bayi'); $post(['login' => 'bayi@x.com', 'password' => 'dogru123']); RdAuthFlow::login(); break;
	case 'login_nondealer': $post(['login' => 'musteri@x.com', 'password' => 'dogru123']); RdAuthFlow::login(); break;
	case 'apply_get': RdAuthFlow::apply(); break;
	case 'apply_invite': $_GET['ust'] = 'ABCD2345'; RdAuthFlow::apply(); break;
	case 'apply_invalid': $post(['account_email' => 'yeni@x.com', 'password' => 'abc12345', 'password2' => 'abc12345', 'company_name' => 'a']); RdAuthFlow::apply(); break;
	case 'apply_new':
		$post(['account_email' => 'yeni@x.com', 'password' => 'abc12345', 'password2' => 'abc12345', 'full_name' => 'Yeni Kişi', 'account_phone' => '05320000000', 'company_name' => 'Yeni Bayilik', 'contact_name' => 'Yeni Kişi', 'phone' => '05321112233', 'email' => 'y@x.com', 'city' => 'Ankara', 'accept_terms' => '1']);
		register_shutdown_function(static function () { echo "\nDEALERS:" . DB::getValue('SELECT COUNT(*) FROM rd_dealers') . ' STATUS:' . DB::getValue('SELECT status FROM rd_dealers LIMIT 1') . ' USERS:' . DB::getValue('SELECT COUNT(*) FROM users') . "\n"; });
		RdAuthFlow::apply(); break;
	case 'apply_noterms': $post(['account_email' => 'yeni@x.com', 'password' => 'abc12345', 'password2' => 'abc12345', 'company_name' => 'Yeni Bayilik', 'contact_name' => 'Yeni Kişi', 'phone' => '05321112233', 'email' => 'y@x.com', 'city' => 'Ankara']); RdAuthFlow::apply(); break;
	case 'apply_wrongpass': $post(['account_email' => 'bayi@x.com', 'password' => 'yanlis', 'company_name' => 'Yeni Bayilik', 'contact_name' => 'Yeni Kişi', 'phone' => '05321112233', 'email' => 'y@x.com', 'city' => 'Ankara', 'accept_terms' => '1']); RdAuthFlow::apply(); break;
	case 'panel_guest': RdPanel::handle(); break;
	case 'panel_nondealer': Customer::as(3); RdPanel::handle(); break;
	case 'panel_pending': $mk(2, 'Mega Bayi', 'pending'); Customer::as(2); RdPanel::handle(); break;
	case 'panel_rejected': $id = $mk(2, 'Mega Bayi', 'pending'); RdDealer::setStatus($id, 'rejected', 1, 'Eksik evrak'); Customer::as(2); RdPanel::handle(); break;
	case 'panel_paused':
		$p = $mk(2, 'Ana Bayi'); RdPlans::save(['code' => 'pro', 'name' => 'Pro', 'monthly_price' => '1', 'commission_rate' => '5', 'max_sub_dealers' => '2', 'active' => '1']); RdPlans::assign($p, 'pro', '', 1);
		$s = $mk(4, 'Alt Bayi', 'active', $p); RdDealer::setPausedByParent($p, $s, true); Customer::as(4); RdPanel::handle(); break;
	case 'panel_section':
		$p = $mk(2, 'Ana Bayi'); RdPlans::save(['code' => 'pro', 'name' => 'Pro', 'monthly_price' => '1', 'commission_rate' => '5', 'max_sub_dealers' => '2', 'active' => '1']); RdPlans::assign($p, 'pro', '', 1);
		$s = $mk(4, 'Alt Bayi', 'active', $p); DB::insert('orders', ['id_user' => 3, 'status' => 1, 'total' => 1000]);
		$_SESSION['rd_ref'] = RdDealer::get($p)['referral_code']; Customer::as(3); Module::fire('order.placed', ['id_order' => 1]);
		$who = $argv[3] ?? 'top'; Customer::as($who === 'sub' ? 4 : 2); $_GET['s'] = $argv[2] ?? 'dashboard'; RdPanel::handle(); break;
	case 'panel_csv': $p = $mk(2, 'Ana Bayi'); RdDealer::setRates($p, 10, 0, 1); DB::insert('orders', ['id_user' => 3, 'status' => 1, 'total' => 1000]); $_SESSION['rd_ref'] = RdDealer::get($p)['referral_code']; Customer::as(3); Module::fire('order.placed', ['id_order' => 1]); Customer::as(2); $_GET['s'] = 'earnings'; $_GET['export'] = 'csv'; RdPanel::handle(); break;
	case 'panel_plan_request': $p = $mk(2, 'Ana Bayi'); Customer::as(2); $_GET['s'] = 'subscription'; $post(['action' => 'plan_request', 'plan_code' => 'baslangic']); register_shutdown_function(static function () { echo "\nREQ:" . DB::getValue("SELECT COUNT(*) FROM rd_subscriptions WHERE status = 'requested'") . "\n"; }); RdPanel::handle(); break;
	case 'panel_foreign_pause':
		$p = $mk(2, 'Ana Bayi'); $q = $mk(5, 'Diger Bayi'); RdPlans::save(['code' => 'pro', 'name' => 'Pro', 'monthly_price' => '1', 'commission_rate' => '5', 'max_sub_dealers' => '2', 'active' => '1']); RdPlans::assign($q, 'pro', '', 1);
		$s = $mk(4, 'Alt Bayi', 'active', $q); Customer::as(2); $_GET['s'] = 'subdealers'; $post(['action' => 'sub_pause', 'id_dealer' => (string) $s]);
		register_shutdown_function(static function () { echo "\nPAUSED:" . DB::getValue('SELECT paused_by_parent FROM rd_dealers WHERE id_dealer = 3') . ' FLASH:' . ($_SESSION['rd_flash']['type'] ?? '') . "\n"; }); RdPanel::handle(); break;
	case 'logout_get': Customer::as(2); RdAuthFlow::logout(); break;
	case 'logout_post': Customer::as(2); $post([]); RdAuthFlow::logout(); break;
	case 'ref_valid': $p = $mk(2, 'Ana Bayi'); $_GET['kod'] = RdDealer::get($p)['referral_code']; register_shutdown_function(static function () { echo "\nSESSION:" . ($_SESSION['rd_ref'] ?? '') . "\n"; }); RdAuthFlow::ref(); break;
	case 'ref_invalid': $_GET['kod'] = 'YOKYOK12'; register_shutdown_function(static function () { echo "\nSESSION:" . ($_SESSION['rd_ref'] ?? '-') . "\n"; }); RdAuthFlow::ref(); break;
	case 'admin':
		$p = $mk(2, 'Ana Bayi', 'pending'); $s = $mk(4, 'Alt', 'pending', $p);
		$_GET['tab'] = $argv[2] ?? 'applications'; $_SERVER['REQUEST_METHOD'] = 'GET';
		$m = new RafimdaDealerModule(); $m->adminPage();
		echo $GLOBALS['smarty']->fetch('file:' . $m->getAdminTemplatePath('admin')); break;
	case 'admin_post':
		$p = $mk(2, 'Ana Bayi', 'pending');
		$_POST = ['token' => 'admintok', 'rdAction' => $argv[2] ?? '', 'id_dealer' => (string) $p, 'to_status' => 'active', 'reason' => '', 'commission_rate' => '7', 'parent_share_rate' => '1', 'default_commission' => '4.5', 'code' => 'x1', 'name' => 'X', 'monthly_price' => '10', 'max_sub_dealers' => '1', 'active' => '1'];
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_GET['tab'] = 'dealers';
		$m = new RafimdaDealerModule(); $m->adminPage();
		echo "\nFLASH:" . $GLOBALS['smarty']->getTemplateVars('flashType') . ':' . $GLOBALS['smarty']->getTemplateVars('flash') . ' STATUS:' . RdDealer::get($p)['status'] . ' RATE:' . RdDealer::get($p)['commission_rate'] . ' DEF:' . Settings::get('RAFIMDA_DEALER_DEFAULT_COMMISSION') . ' PLAN:' . (RdPlans::get('x1') ? 'var' : 'yok') . "\n"; break;
	case 'admin_badtoken': $p = $mk(2, 'Ana Bayi', 'pending'); $_POST = ['token' => 'kotu', 'rdAction' => 'dealer_status', 'id_dealer' => (string) $p, 'to_status' => 'active']; $_SERVER['REQUEST_METHOD'] = 'POST'; $m = new RafimdaDealerModule(); $m->adminPage(); echo "\nSTATUS:" . RdDealer::get($p)['status'] . "\n"; break;
	default: echo "bilinmeyen senaryo\n";
}
