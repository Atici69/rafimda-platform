<?php
/**
 * Panel sürücüsü: tek HTTP isteğini ayrı süreçte RvPanel::handle() ile çalıştırır (exit/redirect nedeniyle).
 * Gerçek Smarty şablonları + SQLite; gerçek FriSay/MySQL/tarayıcı DEĞİL.
 * Kullanım: drive.php <JSON: {user, get, post, probes:[sql...], as}>
 */
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/RvPanel.php';
require_once dirname(__DIR__) . '/lib/RvAuthFlow.php';
require_once dirname(__DIR__, 2) . '/rafimda-showcase/lib/RsShowcase.php';
require_once dirname(__DIR__, 2) . '/rafimda-campaign/lib/RcCampaign.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$cfg = json_decode((string) ($argv[1] ?? '{}'), true) ?: [];
rv_fresh_db(); rv_apply_migrations();
foreach (['rafimda-showcase', 'rafimda-campaign'] as $m) { foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . "/$m/install.sql"))))) as $st) { $GLOBALS['db']->exec($st); } }
DB::execute('CREATE TABLE product_variations (id_variation INTEGER PRIMARY KEY AUTOINCREMENT, id_product INT, sku TEXT, price REAL, stock REAL, active INT DEFAULT 1)');
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rvd_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true); $GLOBALS['smarty'] = $smarty;
$domain = 'https://test.local/'; $GLOBALS['domain'] = $domain;
// --- tohum veri ---
foreach ([1 => 'sahip@x.com', 2 => 'personel@x.com', 3 => 'musteri@x.com', 4 => 'diger@x.com'] as $id => $mail) { DB::execute('UPDATE users SET user_full_name = ?, email = ?, active = 1 WHERE id_user = ?', ['Kişi ' . $id, $mail, $id]); }
DB::insert('categories', ['category_name' => 'Giyim', 'active' => 1]); DB::insert('brands', ['brand_name' => 'Marka', 'active' => 1]);
$vA = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'Moda Evi', 'store_slug' => 'moda-evi', 'status' => 'active', 'email' => 'sahip@x.com', 'approved_at' => date('Y-m-d H:i:s'), 'date_add' => date('Y-m-d H:i:s')]);
$vB = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 4, 'store_name' => 'Diğer Mağaza', 'store_slug' => 'diger', 'status' => 'active']);
DB::insert('rafimda_vendor_staff', ['id_vendor' => $vA, 'id_user' => 1, 'role' => 'owner', 'status' => 'active']);
DB::insert('rafimda_vendor_staff', ['id_vendor' => $vA, 'id_user' => 2, 'role' => 'viewer', 'status' => 'active']);
DB::insert('rafimda_vendor_staff', ['id_vendor' => $vB, 'id_user' => 4, 'role' => 'owner', 'status' => 'active']);
$mkp = function (int $v, string $n, float $price, float $stock): int { $id = (int) DB::insert('products', ['product_name' => $n, 'id_category' => 1, 'id_brand' => 1, 'price' => $price, 'old_price' => 0, 'stock' => $stock, 'stock_code' => 'SK' . crc32($n) % 9999, 'barcode' => '', 'active' => 1]); DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $v, 'moderation' => 'approved']); return $id; };
$p1 = $mkp($vA, 'Pamuklu Tişört', 100, 50); $p2 = $mkp($vA, 'Kot Pantolon', 300, 2); $pB = $mkp($vB, 'Başkasının Ürünü', 55, 9);
foreach ([[$p1, 2, 1], [$p2, 1, 1]] as [$pid, $qty, $uid]) { $o = (int) DB::insert('orders', ['id_user' => 3, 'reference' => 'R' . $pid, 'status' => 1, 'date_add' => date('Y-m-d H:i:s'), 'customer_name' => 'Ayşe Müşteri', 'customer_phone' => '05321112233', 'address_city' => 'İzmir', 'address_district' => 'Konak', 'address_text' => 'Örnek Sk. 1']); $pr = DB::getValue('SELECT price FROM products WHERE id_product = ?', [$pid]); DB::insert('order_detail', ['id_order' => $o, 'id_product' => $pid, 'product_name' => DB::getValue('SELECT product_name FROM products WHERE id_product = ?', [$pid]), 'qty' => $qty, 'price' => $pr, 'total' => $pr * $qty]); RvOrders::splitOrder(['id_order' => $o]); }
$oB = (int) DB::insert('orders', ['id_user' => 3, 'reference' => 'RB', 'status' => 1, 'date_add' => date('Y-m-d H:i:s'), 'customer_name' => 'Gizli Müşteri']); DB::insert('order_detail', ['id_order' => $oB, 'id_product' => $pB, 'product_name' => 'Başkasının Ürünü', 'qty' => 1, 'price' => 55, 'total' => 55]); RvOrders::splitOrder(['id_order' => $oB]);
if (!empty($cfg['campaign'])) { RcCampaign::save(['code' => 'dusuk', 'title' => 'Düşük komisyon kampanyası', 'type' => 'commission_discount', 'active' => 1, 'benefit_days' => 30, 'calc_mode' => 'fixed_rate', 'value' => '5', 'vendor_can_join' => 1, 'description' => 'İlk 30 gün %5']); }
if (!empty($cfg['fee'])) { RcCampaign::setShowcaseFee(250); RcCampaign::save(['code' => 'ilk-ay', 'title' => 'İlk ay ücretsiz vitrin', 'type' => 'free_showcase', 'active' => 1, 'benefit_days' => 30, 'new_vendor_days' => 30, 'auto_enroll' => 1]); }
if (!empty($cfg['legal'])) {
	require_once dirname(__DIR__, 2) . '/rafimda-legal/lib/RlSeed.php'; require_once dirname(__DIR__, 2) . '/rafimda-legal/lib/RlLegal.php';
	foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . '/rafimda-legal/install.sql'))))) as $st) { $GLOBALS['db']->exec($st); }
	RlLegal::installDefaults();
}
if (!empty($cfg['engage'])) {
	foreach (['question', 'reviews'] as $m) {
		$sql = (string) preg_replace('/ CHARACTER SET \w+ COLLATE \w+/', '', file_get_contents(dirname(__DIR__, 2) . "/$m/install.sql"));
		$sql = (string) preg_replace('/^\s*PRIMARY KEY \(`(id_question|id_review|id_queue)`\),?\s*$/m', '', rv_mysql_to_sqlite($sql));
		foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', (string) preg_replace('/,(\s*\))/', '$1', $sql)))) as $st) { $GLOBALS['db']->exec($st); }
	}
	DB::insert('rafimda_store_questions', ['id_vendor' => $vA, 'id_user' => 3, 'author_name' => 'Ayşe', 'question' => 'Kargo ne zaman çıkar?', 'answer' => '', 'status' => 'pending', 'date_add' => date('Y-m-d H:i:s')]);
	RvNotify::send(['id_vendor' => $vA, 'audience' => 'vendor'], 'order', 'Yeni sipariş', 'Test bildirimi', '');
	RvTickets::create(['opener' => 'customer', 'id_user' => 3, 'category' => 'order', 'subject' => 'Sipariş sorusu', 'body' => 'Siparişim ne zaman gelir?']);
}
if (!empty($cfg['invoice'])) {
	require_once dirname(__DIR__, 2) . '/rafimda-invoice/rafimda-invoice.php';
	foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . '/rafimda-invoice/install.sql'))))) as $st) { $GLOBALS['db']->exec($st); }
	RiInvoice::$storageOverride = sys_get_temp_dir() . '/ri_drive';
}
if (!empty($cfg['social'])) {
	require_once dirname(__DIR__, 2) . '/rafimda-social/rafimda-social.php';
	foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . '/rafimda-social/install.sql'))))) as $st) { $GLOBALS['db']->exec($st); }
	RsoContent::$mediaDirOverride = sys_get_temp_dir() . '/rso_drive';
}
if (!empty($cfg['pending_app'])) {
	DB::execute("UPDATE rafimda_vendors SET status = 'pending' WHERE id_vendor = ?", [$vA]);
	$ap = RvApplication::submit(1, $vA, ['person_type' => 'company', 'contact_name' => 'Kişi 1', 'address' => 'Moda Cad. No:1', 'district' => 'Kadıköy', 'categories' => [1 => 'Giyim']], true);
	RvApplication::transition($ap['id_application'], 'docs_required', 1, 'Vergi levhası eksik');
}
if (!empty($cfg['activate'])) { $GLOBALS['RV_TEST_ACTIVATE'] = true; }
Customer::as((int) ($cfg['user'] ?? 1));
$_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'GET'; $_GET = $cfg['get'] ?? []; $_POST = ($cfg['post'] ?? []) + (($cfg['method'] ?? 'GET') === 'POST' ? ['token' => 'csrf-test'] : []);
RvView::$onRedirect = static function (string $url): void { echo "\nREDIRECT:" . $url . "\n"; exit(0); };
register_shutdown_function(static function () use ($cfg) {
	echo "\nFLASH:" . json_encode($_SESSION['rv_flash'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
	foreach (($cfg['probes'] ?? []) as $i => $sql) { echo 'PROBE' . $i . ':' . json_encode(DB::getValue($sql), JSON_UNESCAPED_UNICODE) . "\n"; }
});
if (!empty($cfg['register'])) { RvAuthFlow::register(); } elseif (!empty($cfg['admin'])) {
	// Not: bootstrap gerçek modül sınıfını taklit ettiği için yönetici sekmesi, adminPage'in kullandığı AYNI RvApplication::adminVars ile çizilir.
	$atab = (string) ($cfg['atab'] ?? 'applications');
	$smarty->setEscapeHtml(false); $smarty->assign(RvApplication::adminVars($atab === 'applications', $_GET) + RvEngageAdmin::vars($atab === 'engage', $_GET) + ['adminToken' => 'admintok', 'flash' => '', 'flashType' => 'info', 'rvTab' => $atab, 'rvPending' => [], 'rvQuery' => (string) ($_GET['q'] ?? '')]);
	echo $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl');
} else { RvPanel::handle(); }
