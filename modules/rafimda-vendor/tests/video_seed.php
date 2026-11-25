<?php
/**
 * Rafi Akademi video çekimi için ÖRNEK katalog (SQLite, test ortamı). Canlı veri DEĞİLDİR.
 * Tam kategori ağacı (rafimda-catalog), markalar, görselli ürünler, birkaç sipariş.
 */
// menüde XML Merkezi ve Pazaryerleri bağlantıları görünsün (modüller bu süreçte yüklü değil; yalnızca bağlantı adresi üretilir)
if (!class_exists('XhPanel', false)) { class XhPanel {} }
if (!class_exists('MhPanel', false)) { class MhPanel {} }

function rv_video_seed(int $vA): void
{
	$root = dirname(__DIR__, 2);
	foreach (['RcatTaxonomy', 'RcatMatcher', 'RcatInstaller'] as $c) { require_once "$root/rafimda-catalog/lib/$c.php"; }
	$db = $GLOBALS['db'];
	$db->exec('DROP TABLE categories'); $db->exec('DROP TABLE brands');
	$db->exec('CREATE TABLE categories (id_category INTEGER PRIMARY KEY AUTOINCREMENT, id_parent INT DEFAULT 0, category_name TEXT, category_link TEXT, active INTEGER DEFAULT 1, show_on_home INT DEFAULT 0, home_position INT DEFAULT 0)');
	$db->exec('CREATE TABLE brands (id_brand INTEGER PRIMARY KEY AUTOINCREMENT, brand_name TEXT, brand_link TEXT, active INTEGER DEFAULT 1)');
	$db->exec(rv_mysql_to_sqlite(file_get_contents("$root/rafimda-catalog/install.sql")));
	RcatInstaller::apply(); RcatMatcher::reset();
	foreach (['Rafımda Basic', 'Moda Evi', 'TeknoPlus', 'EvGüzeli', 'Sportif'] as $b) { DB::insert('brands', ['brand_name' => $b, 'brand_link' => RcatInstaller::slug($b), 'active' => 1]); }
	$cat = static function (string $path): int { foreach (RcatMatcher::candidates() as $c) { if ($c['path'] === $path) { return (int) $c['id']; } } return 1; };
	$brand = static fn(string $n): int => (int) DB::getValue('SELECT id_brand FROM brands WHERE brand_name = ?', [$n]);
	DB::execute('DELETE FROM rafimda_vendor_products WHERE id_vendor = ?', [$vA]);
	$items = [
		['Pamuklu Basic Tişört — Beyaz', 'Kadın > Giyim > Tişört', 'Rafımda Basic', 149.90, 48, 'RB-TS-001', 'tshirt'],
		['Yüksek Bel Mom Jean', 'Kadın > Giyim > Kot Pantolon', 'Moda Evi', 399.90, 12, 'ME-JN-014', 'jeans'],
		['Şeffaf Silikon Telefon Kılıfı', 'Elektronik > Telefon > Telefon Kılıfı', 'TeknoPlus', 89.90, 230, 'TP-KL-207', 'case'],
		['Kablosuz Bluetooth Kulaklık', 'Elektronik > Ses Sistemleri > Bluetooth Kulaklık', 'TeknoPlus', 749.00, 3, 'TP-BT-330', 'headphones'],
		['Seramik Kupa 350 ml', 'Ev & Yaşam > Mutfak > Bardak', 'EvGüzeli', 119.50, 64, 'EG-KP-011', 'mug'],
		['Hafif Koşu Ayakkabısı', 'Spor & Outdoor > Spor Ayakkabı > Koşu Ayakkabısı', 'Sportif', 1299.00, 0, 'SP-KA-090', 'sneaker'],
	];
	$ids = [];
	foreach ($items as [$n, $path, $b, $price, $stock, $sku, $img]) {
		$id = (int) DB::insert('products', ['product_name' => $n, 'id_category' => $cat($path), 'id_brand' => $brand($b), 'price' => $price, 'old_price' => 0, 'stock' => $stock, 'stock_code' => $sku, 'barcode' => '869' . substr(str_pad((string) crc32($sku), 10, '0'), 0, 10), 'active' => 1]);
		DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $vA, 'moderation' => 'approved']);
		DB::insert('images', ['id_product' => $id, 'cover' => 1]);
		$GLOBALS['RV_VIDEO_IMAGES'][(int) $GLOBALS['db']->lastInsertId()] = $img;
		$ids[$sku] = $id;
	}
	foreach ((array) ($GLOBALS['RV_VIDEO_EXTRA'] ?? []) as $x) {
		$id = (int) DB::insert('products', ['product_name' => $x[0], 'id_category' => $cat($x[1]), 'id_brand' => $brand($x[2]), 'price' => $x[3], 'old_price' => 0, 'stock' => $x[4], 'stock_code' => $x[5], 'barcode' => '', 'active' => 1]);
		DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $vA, 'moderation' => $x[6] ?? 'pending']);
		if (!empty($x[7])) { DB::insert('images', ['id_product' => $id, 'cover' => 1]); $GLOBALS['RV_VIDEO_IMAGES'][(int) $GLOBALS['db']->lastInsertId()] = $x[7]; }
	}
	// ikinci/üçüncü görsel (görsel videosu için)
	foreach (['tshirt-back', 'tshirt-detail'] as $img) { DB::insert('images', ['id_product' => $ids['RB-TS-001'], 'cover' => 0]); $GLOBALS['RV_VIDEO_IMAGES'][(int) $GLOBALS['db']->lastInsertId()] = $img; }
	foreach ((array) ($GLOBALS['RV_VIDEO_SQL'] ?? []) as $q) { DB::execute($q); }
	file_put_contents(sys_get_temp_dir() . '/rv_video_images.json', json_encode($GLOBALS['RV_VIDEO_IMAGES']));
	$GLOBALS['RV_VIDEO_IDS'] = $ids;
}
