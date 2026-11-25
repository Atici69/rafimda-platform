<?php
/**
 * Rafi Akademi video çekimi: Pazaryeri ekranlarını (mh_front.tpl, SATICI görünümü) gerçek MhPanel + şablonlarla ÖRNEK veriyle üretir.
 * Canlı veri / gerçek API DEĞİLDİR. Kullanım: php video_screens.php <çıktı-dizini>
 */
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
foreach (['RcatTaxonomy', 'RcatMatcher', 'RcatInstaller'] as $c) { require_once dirname(__DIR__, 2) . "/rafimda-catalog/lib/$c.php"; }
$out = rtrim($argv[1] ?? sys_get_temp_dir() . '/mh_video', '/'); @mkdir($out, 0777, true);
Settings::set('MARKETPLACE_HUB_SECRET', '');
mh_fresh_db();
$db = $GLOBALS['db'];
$db->exec('DROP TABLE categories');
$db->exec('CREATE TABLE categories (id_category INTEGER PRIMARY KEY AUTOINCREMENT, id_parent INT DEFAULT 0, category_name TEXT, category_link TEXT, active INTEGER DEFAULT 1, show_on_home INT DEFAULT 0, home_position INT DEFAULT 0)');
RcatInstaller::apply(); RcatMatcher::reset();
DB::execute("INSERT INTO rafimda_vendors (owner_user_id, store_name, store_slug, status) VALUES (1,'Moda Evi','moda-evi','active')");
$vA = 1;
$cat = static function (string $path): int { foreach (RcatMatcher::candidates() as $c) { if ($c['path'] === $path) { return (int) $c['id']; } } return 0; };
foreach ([
	['Şeffaf Silikon Telefon Kılıfı', 'Elektronik > Telefon > Telefon Kılıfı', 'TP-KL-207', 89.90, 230],
	['20W Hızlı Şarj Adaptörü', 'Elektronik > Telefon > Şarj Cihazı', 'TP-SA-020', 249.90, 64],
	['Kablosuz Bluetooth Kulaklık', 'Elektronik > Ses Sistemleri > Bluetooth Kulaklık', 'TP-BT-330', 749.00, 18],
	['Pamuklu Basic Tişört — Beyaz', 'Kadın > Giyim > Tişört', 'RB-TS-001', 149.90, 48],
] as [$n, $path, $sku, $price, $stock]) {
	$id = (int) DB::insert('products', ['product_name' => $n, 'id_category' => $cat($path), 'id_brand' => 1, 'price' => $price, 'stock' => $stock, 'stock_code' => $sku, 'barcode' => '', 'active' => 1]);
	DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $vA, 'moderation' => 'approved']);
}

$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/mh_vs_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true);
$smarty->setEscapeHtml(true); $GLOBALS['smarty'] = $smarty;
$render = static function (string $name, array $get, ?array $flash = null) use ($smarty, $out, $vA): void {
	$_GET = $get; $_SERVER['REQUEST_METHOD'] = 'GET';
	$panel = new MhPanel($vA, false, 'https://test.local/pazaryeri');
	[$view, $vars] = $panel->handle(false);
	$vars['view'] = $view;
	$smarty->clearAllAssign();
	$tpl = dirname(__DIR__) . '/assets/templates/front/';
	$smarty->assign($vars + ['mhFlash' => $flash, 'mhToken' => 'x', 'mhCanManage' => true, 'mhBase' => 'https://test.local/pazaryeri', 'mhTplDir' => $tpl, 'mhCss' => 'https://test.local/modules/marketplace-hub/assets/css/mh.css', 'panelUrl' => 'https://test.local/satici-panel', 'mhContent' => 'file:' . $tpl . 'mh_content.tpl', 'mhPending' => [], 'mhCronUrl' => '']);
	file_put_contents("$out/mh-$name.html", $smarty->fetch('file:' . $tpl . 'mh_front.tpl'));
	echo "mh-$name\n";
};
$render('list-empty', []);
$r = MhRepo::createAccount($vA, 'trendyol', 'Trendyol — Moda Evi', ['supplier_id' => '123456', 'api_key' => 'ornek-anahtar', 'api_secret' => 'ornek-gizli']);
$id = (int) $r['id'];
$render('account', ['view' => 'account', 'id' => $id], ['type' => 'success', 'message' => (string) $r['message']]);
$render('categories', ['view' => 'account', 'id' => $id, 'tab' => 'categories']);
foreach ([['Elektronik > Telefon > Telefon Kılıfı', '1041', 'Telefon Kılıfı'], ['Elektronik > Telefon > Şarj Cihazı', '1047', 'Şarj Cihazları'], ['Elektronik > Ses Sistemleri > Bluetooth Kulaklık', '1083', 'Kulaklık'], ['Kadın > Giyim > Tişört', '411', 'Tişört']] as [$p, $code, $nm]) {
	MhRepo::setCategoryMap($id, $vA, $cat($p), $code, $nm);
}
$render('categories-saved', ['view' => 'account', 'id' => $id, 'tab' => 'categories'], ['type' => 'success', 'message' => 'Kategori eşlemeleri kaydedildi.']);
$render('products', ['view' => 'account', 'id' => $id, 'tab' => 'products']);
$render('list', []);
