<?php
/**
 * Rafi Akademi video çekimi: XML Merkezi'nin SATICI ekranlarını (xh_front.tpl) gerçek XhPanel + şablonlarla,
 * ÖRNEK tedarikçi XML'iyle üretir. Canlı veri DEĞİLDİR. Kullanım: php video_screens.php <çıktı-dizini>
 */
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
foreach (['RcatTaxonomy', 'RcatMatcher', 'RcatInstaller'] as $c) { require_once dirname(__DIR__, 2) . "/rafimda-catalog/lib/$c.php"; }
$out = rtrim($argv[1] ?? sys_get_temp_dir() . '/xh_video', '/'); @mkdir($out, 0777, true);
XhUrlGuard::$resolver = fn(string $h): array => ['93.184.216.34'];
Settings::set('XML_HUB_SECRET', '');
xh_fresh_db();
$db = $GLOBALS['db'];
$db->exec('DROP TABLE categories'); $db->exec('DROP TABLE brands');
$db->exec('CREATE TABLE categories (id_category INTEGER PRIMARY KEY AUTOINCREMENT, id_parent INT DEFAULT 0, category_name TEXT, category_link TEXT, active INTEGER DEFAULT 1, show_on_home INT DEFAULT 0, home_position INT DEFAULT 0)');
$db->exec('CREATE TABLE brands (id_brand INTEGER PRIMARY KEY AUTOINCREMENT, brand_name TEXT, brand_link TEXT, active INTEGER DEFAULT 1)');
$db->exec(xh_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . '/rafimda-catalog/install.sql')));
RcatInstaller::apply(); RcatMatcher::reset(); XhImporter::resetCaches();
DB::execute("INSERT INTO rafimda_vendors (owner_user_id, store_name, store_slug, status) VALUES (1,'Moda Evi','moda-evi','active')");
$vA = 1;

// örnek tedarikçi XML'i (KDV hariç fiyatlar)
$prod = [
	['BZ-1001', 'Şeffaf Silikon iPhone 15 Kılıfı', '41.67', '120', 'TeknoPlus', 'Telefon Aksesuarları > Kılıf'],
	['BZ-1002', '20W Hızlı Şarj Adaptörü', '125.00', '64', 'TeknoPlus', 'Telefon Aksesuarları > Şarj Aleti'],
	['BZ-1003', '10000 mAh Powerbank', '291.67', '35', 'VoltMax', 'Telefon Aksesuarları > Powerbank'],
	['BZ-1004', 'Kablosuz Bluetooth Kulaklık', '416.67', '18', 'VoltMax', 'Elektronik > Kulaklık > Bluetooth Kulaklık'],
	['BZ-1005', 'Akıllı Saat Spor Kordon', '58.33', '210', 'TeknoPlus', 'Akıllı Saat Aksesuarları > Kordon'],
	['BZ-1006', 'Kadın Oversize Tişört', '125.00', '44', 'Moda Evi', 'Kadın > Tişört'],
	['BZ-1007', 'Unisex Mont', '833.33', '9', 'Moda Evi', 'Mont'],
	['BZ-1008', 'Seramik Kupa 350 ml', '62.50', '80', 'EvGüzeli', 'Ev & Yaşam > Mutfak > Bardak'],
];
$x = '<?xml version="1.0" encoding="UTF-8"?><urunler>';
foreach ($prod as [$sku, $name, $price, $stock, $brand, $cat]) {
	$x .= '<urun><stok_kodu>' . $sku . '</stok_kodu><urun_adi>' . htmlspecialchars($name, ENT_XML1) . '</urun_adi><fiyat>' . $price . '</fiyat><kdv>20</kdv><stok>' . $stock . '</stok><marka>' . htmlspecialchars($brand, ENT_XML1) . '</marka><kategori>' . htmlspecialchars($cat, ENT_XML1) . '</kategori><barkod>8690' . substr($sku, 3) . '000001</barkod><resim>https://cdn.ornek-tedarikci.com/' . strtolower($sku) . '.jpg</resim><aciklama>' . htmlspecialchars($name, ENT_XML1) . ' — orijinal ürün, hızlı kargo.</aciklama></urun>';
}
$x .= '</urunler>';
$path = XhStorage::newPath('video'); file_put_contents($path, $x);

$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/xh_vs_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true);
$smarty->setEscapeHtml(true); $GLOBALS['smarty'] = $smarty;
$render = static function (string $name, array $get, ?array $flash = null) use ($smarty, $out, $vA): void {
	$_GET = $get; $_SERVER['REQUEST_METHOD'] = 'GET';
	DB::execute("UPDATE xh_connections SET source_type = 'url', url = 'https://xml.ornek-tedarikci.com/urunler.xml'"); // ekranda tedarikçi adresi görünsün
	$panel = new XhPanel($vA, false, 'https://test.local/xml-merkezi');
	[$view, $vars] = $panel->handle(false);
	$vars['view'] = $view;
	$smarty->clearAllAssign();
	$smarty->assign($vars + ['xhFlash' => $flash, 'xhToken' => 'x', 'xhCanManage' => true, 'xhTplDir' => dirname(__DIR__) . '/assets/templates/front/', 'xhCss' => 'https://test.local/modules/xml-hub/assets/css/xh.css', 'xhJs' => 'https://test.local/modules/xml-hub/assets/js/xh.js', 'panelUrl' => 'https://test.local/satici-panel', 'xhContent' => 'file:' . dirname(__DIR__) . '/assets/templates/front/xh_content.tpl', 'rafiHelp' => '']);
	file_put_contents("$out/xml-$name.html", $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/front/xh_front.tpl'));
	DB::execute("UPDATE xh_connections SET source_type = 'file'"); // içe aktarma örnek dosyadan çalışır (ağ yok)
	echo "xml-$name\n";
};

$render('list-empty', []);
$render('new', ['view' => 'new']);
// bağlantı: kullanıcı formda %40 kâr + KDV ekle seçti
$r = XhRepo::createConnection($vA, ['name' => 'Örnek Tedarikçi — Ürün XML', 'source_type' => 'file', 'file_path' => $path, 'interval_minutes' => 360, 'options' => ['price_markup_percent' => 40, 'vat_mode' => 'add', 'vat_rate' => 20, 'profit_mode' => 'markup', 'auto_category' => 1, 'auto_brand' => 1]]);
$id = (int) $r['id'];
XhRepo::setConn($id, ['item_path' => 'urunler/urun']);
$render('mapping-suggest', ['view' => 'conn', 'id' => $id, 'tab' => 'mapping'], ['type' => 'success', 'message' => 'Bağlantı başarılı: 8 ürün bulundu (düğüm: urunler/urun).']);
$sample = XhXml::sample($path, 'urunler/urun', 5);
$mapping = XhMapper::suggest($sample[0]);
XhRepo::setConn($id, ['mapping' => json_encode(XhMapper::sanitizeMapping($mapping))]);
$render('mapping', ['view' => 'conn', 'id' => $id, 'tab' => 'mapping'], ['type' => 'success', 'message' => 'Eşleştirme kaydedildi.']);
$render('preview', ['view' => 'conn', 'id' => $id, 'tab' => 'preview']);
$q = XhRunner::enqueue($id, 'import', null, $vA); XhRunner::tick(60, 5000);
$render('history', ['view' => 'conn', 'id' => $id, 'tab' => 'history'], ['type' => 'success', 'message' => 'İşlem kuyruğa alındı. Zamanlanmış görev (cron) çalışmıyorsa "Kuyruğu şimdi işle" düğmesini kullanın.']);
$render('maps', ['view' => 'conn', 'id' => $id, 'tab' => 'maps']);
$render('general', ['view' => 'conn', 'id' => $id]);
$monts = array_values(array_filter(RcatMatcher::candidates(), static fn(array $c): bool => $c['path'] === 'Erkek > Giyim > Mont'));
$mr = XhRepo::setMapEntry(XhRepo::conn($id, $vA), 'category', 'Mont', (int) $monts[0]['id']);
$render('maps-saved', ['view' => 'conn', 'id' => $id, 'tab' => 'maps'], ['type' => 'success', 'message' => '1 eşleştirme kaydedildi. Hatalı satırlar için "Hatalıları yeniden dene" kullanabilirsiniz.']);
$render('general-after', ['view' => 'conn', 'id' => $id]);
$q2 = XhRunner::enqueue($id, 'retry', null, $vA); XhRunner::tick(60, 5000);
$render('history-retry', ['view' => 'conn', 'id' => $id, 'tab' => 'history'], ['type' => 'success', 'message' => 'İşlem kuyruğa alındı. Zamanlanmış görev (cron) çalışmıyorsa "Kuyruğu şimdi işle" düğmesini kullanın.']);
$render('list', []);
echo json_encode(XhRepo::run((int) $q['id'], $vA), JSON_UNESCAPED_UNICODE), "\n";
