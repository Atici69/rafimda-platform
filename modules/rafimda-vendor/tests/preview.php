<?php
/** ÖNİZLEME ÜRETİCİ (geliştirme): satıcı paneli ekranlarını gerçek şablonlarla, ÖRNEK VERİ ile HTML olarak üretir. Canlı veri DEĞİLDİR. php tests/preview.php <çıktı-dizini> */
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/');
@mkdir($out, 0777, true);
rv_fresh_db(); rv_apply_migrations();
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rv_pv_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true); $GLOBALS['smarty'] = $smarty;
$smarty->registerPlugin('modifier', 'adminT', static fn($s) => $s);
$vendor = ['id_vendor' => 1, 'store_name' => 'Örnek Mağaza', 'store_slug' => 'ornek-magaza', 'status' => 'active', 'plan_code' => 'free'];
$menu = [];
foreach ([['dashboard', 'Genel Bakış', '◧'], ['products', 'Ürünler', '▤'], ['orders', 'Siparişler', '☰'], ['stock', 'Stok', '▥'], ['pricing', 'Fiyat', '₺'], ['customers', 'Müşteriler', '☺'], ['import', 'İçe Aktarma / XML', '⇪'], ['finance', 'Finans', '▨'], ['subscription', 'Abonelik', '★'], ['store', 'Mağaza', '⌂'], ['staff', 'Ekip', '☷']] as [$k, $l, $i]) {
	$menu[] = ['key' => $k, 'label' => $l, 'icon' => $i, 'ready' => true, 'url' => '#' . $k];
}
$base = ['rvTitle' => 'Rafımda Satıcı Paneli', 'pageTitle' => 'Örnek', 'vendor' => $vendor, 'roleLabel' => 'Sahip', 'memberships' => [$vendor], 'flash' => null, 'logoutUrl' => '#', 'storefrontUrl' => '#', 'menu' => $menu, 'can' => ['create' => true, 'edit' => true, 'delete' => true, 'staff' => true, 'store' => true]];
$row = static fn(int $i, string $n, float $p, float $s, float $old = 0) => ['id_product' => $i, 'product_name' => $n, 'stock_code' => 'SKU-' . (1000 + $i), 'barcode' => '869000' . $i, 'price' => $p, 'old_price' => $old, 'stock' => $s, 'active' => 1, 'moderation' => 'approved', 'has_variants' => $i === 3];
$rows = [$row(1, 'Pamuklu Tişört', 149.9, 25), $row(2, 'Kot Pantolon', 299, 3, 449), $row(3, 'Spor Ayakkabı', 899, 0), $row(4, 'Sırt Çantası', 459, 14)];
$eff = ['plan' => ['code' => 'free', 'name' => 'Başlangıç', 'price_monthly' => 0, 'max_products' => null, 'max_xml_connections' => 2, 'max_marketplace_accounts' => null], 'expired' => false, 'assigned' => 'free', 'expires_at' => ''];
$screens = [
	'dashboard' => ['section' => 'dashboard', 'stats' => ['total' => 42, 'active' => 38, 'passive' => 4, 'low_stock' => 3], 'balances' => ['available' => 1250.5, 'pending' => 340, 'paid_out' => 4200], 'recentOrders' => [['id_order' => 1021, 'reference' => 'RFM-1021', 'subtotal' => 349.9, 'date_add' => '2026-11-23 14:20'], ['id_order' => 1019, 'reference' => 'RFM-1019', 'subtotal' => 899, 'date_add' => '2026-11-22 09:05']], 'audit' => [['action' => 'product.create', 'date_add' => '2026-11-23 10:00'], ['action' => 'stock.update', 'date_add' => '2026-11-22 16:40']]],
	'stock' => ['section' => 'stock', 'rows' => $rows, 'total' => 4, 'filter' => '', 'q' => '', 'page' => 1, 'pages' => 1, 'sum' => ['total' => 4, 'out' => 1, 'low' => 1, 'inactive' => 0, 'variant' => 1], 'lowLimit' => 5, 'sec' => 'stock'],
	'pricing' => ['section' => 'pricing', 'rows' => $rows, 'total' => 4, 'filter' => '', 'q' => '', 'page' => 1, 'pages' => 1, 'sum' => [], 'lowLimit' => 5, 'sec' => 'pricing'],
	'customers' => ['section' => 'customers', 'rows' => [['customer_name' => 'Ayşe K.', 'city' => 'İstanbul', 'orders' => 3, 'spent' => 1240.5, 'last_order' => '2026-11-23'], ['customer_name' => 'Mehmet D.', 'city' => 'Ankara', 'orders' => 1, 'spent' => 349.9, 'last_order' => '2026-11-20']], 'total' => 2, 'q' => '', 'page' => 1, 'pages' => 1, 'sum' => ['customers' => 2, 'repeat' => 1, 'orders' => 4, 'revenue' => 1590.4]],
	'subscription' => ['section' => 'subscription', 'eff' => $eff, 'usage' => ['products' => 42, 'xml' => 1, 'marketplaces' => 0], 'plans' => [['name' => 'Başlangıç', 'price_monthly' => 0, 'max_products' => null, 'max_xml_connections' => 2, 'max_marketplace_accounts' => null], ['name' => 'Pro', 'price_monthly' => 299, 'max_products' => 5000, 'max_xml_connections' => 10, 'max_marketplace_accounts' => 3]]],
];
foreach ($screens as $name => $vars) {
	$tpl = 'panel_' . $name;
	$smarty->clearAllAssign();
	ob_start();
	try {
		RvView::display('panel_layout', $base + $vars + ['contentFile' => 'file:' . RvView::tplDir() . $tpl . '.tpl']);
		$html = ob_get_clean();
	} catch (Throwable $e) {
		ob_end_clean(); $html = '<pre>HATA: ' . htmlspecialchars($e->getMessage()) . '</pre>'; fwrite(STDERR, "$name: " . $e->getMessage() . "\n");
	}
	file_put_contents("$out/vendor-$name.html", $html);
	echo "vendor-$name " . strlen($html) . "\n";
}
// satıcı girişi
ob_start();
try { RvView::display('guest_login', ['rvTitle' => 'Rafımda Satıcı Girişi', 'pageTitle' => 'Satıcı Girişi', 'error' => '', 'login' => '', 'registerUrl' => '#', 'rvToken' => 'x', 'rvTplDir' => RvView::tplDir()]); $h = ob_get_clean(); } catch (Throwable $e) { ob_end_clean(); $h = 'HATA ' . $e->getMessage(); fwrite(STDERR, 'login: ' . $e->getMessage() . "\n"); }
file_put_contents("$out/vendor-login.html", $h); echo "vendor-login " . strlen($h) . "\n";
