<?php
// XML Hub v0.4: fiyat kuralı (KDV ekleme, kâr: maliyet üzerine / satış içinden marj), kategori yolu,
// otomatik kategori eşleştirme (Rafımda Katalog), eksik marka oluşturma. SQLite + stub; MySQL TEST EDİLMEDİ.
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/rafimda-catalog/lib/RcatTaxonomy.php';
require_once dirname(__DIR__, 2) . '/rafimda-catalog/lib/RcatMatcher.php';
require_once dirname(__DIR__, 2) . '/rafimda-catalog/lib/RcatInstaller.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function v4_db(): void
{
	xh_fresh_db();
	$db = $GLOBALS['db'];
	$db->exec('DROP TABLE categories'); $db->exec('DROP TABLE brands');
	$db->exec('CREATE TABLE categories (id_category INTEGER PRIMARY KEY AUTOINCREMENT, id_parent INT DEFAULT 0, category_name TEXT, category_link TEXT, active INTEGER DEFAULT 1, show_on_home INT DEFAULT 0, home_position INT DEFAULT 0)');
	$db->exec('CREATE TABLE brands (id_brand INTEGER PRIMARY KEY AUTOINCREMENT, brand_name TEXT, brand_link TEXT, active INTEGER DEFAULT 1)');
	$db->exec(xh_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . '/rafimda-catalog/install.sql')));
	RcatMatcher::reset(); XhImporter::resetCaches();
}
$map = ['fields' => ['name' => 'ad', 'sku' => 'kod', 'price' => 'fiyat', 'stock' => 'stok', 'brand' => 'marka', 'category' => 'kategori', 'vat' => 'kdv']];
$item = fn(array $o = []) => $o + ['ad' => 'Silikon Telefon Kılıfı', 'kod' => 'BZ-1', 'fiyat' => '100,00', 'stok' => '5', 'marka' => 'Bezos', 'kategori' => 'Telefon Aksesuarları > Kılıf'];

section('Fiyat kuralı');
$f = XhMapper::priceRule(['price_markup_percent' => 40, 'profit_mode' => 'markup', 'vat_mode' => 'add', 'vat_rate' => 20]);
ok($f(100.0) === 168.0, 'KDV hariç 100 ₺ + %40 kâr (maliyet üzerine) + %20 KDV = 168,00 ₺');
$f = XhMapper::priceRule(['price_markup_percent' => 40, 'profit_mode' => 'margin', 'vat_mode' => 'add', 'vat_rate' => 20]);
ok($f(100.0) === 200.0, 'satış içinden %40 marj + %20 KDV = 200,00 ₺');
$f = XhMapper::priceRule(['price_markup_percent' => 40, 'profit_mode' => 'markup', 'vat_mode' => 'add', 'vat_rate' => 20], 10.0);
ok($f(100.0) === 154.0, "XML'deki KDV alanı (%10) varsayılan orandan önceliklidir");
$f = XhMapper::priceRule(['price_markup_percent' => 10, 'price_add' => 5]);
ok($f(100.0) === 115.0, 'eski davranış korunur (KDV ekleme kapalı: 100 × 1,10 + 5)');
$f = XhMapper::priceRule(['price_markup_percent' => 40, 'price_add' => 10, 'vat_mode' => 'add', 'vat_rate' => 20]);
ok($f(100.0) === 180.0, 'sabit ekleme KDV öncesi uygulanır: (140 + 10) × 1,20');
$o = XhRepo::sanitizeOptions(['price_markup_percent' => '95', 'profit_mode' => 'margin', 'vat_mode' => 'evil', 'vat_rate' => '500']);
ok($o['price_markup_percent'] == 90.0 && $o['vat_mode'] === 'none' && $o['vat_rate'] == 100.0 && $o['profit_mode'] === 'margin', 'seçenek temizleme: marj ≤ %90, geçersiz KDV modu → none, oran ≤ 100');
$o = XhRepo::sanitizeOptions([]); ok($o['auto_category'] === 1 && $o['auto_brand'] === 1 && $o['vat_mode'] === 'none' && $o['profit_mode'] === 'markup', 'varsayılanlar: otomatik kategori + marka açık, KDV ekleme kapalı');

section('Mapper: KDV, indirimli fiyat ve kategori yolu');
v4_db();
DB::insert('brands', ['brand_name' => 'Bezos', 'brand_link' => 'bezos']);
$opt = ['price_markup_percent' => 40, 'vat_mode' => 'add', 'vat_rate' => 20, 'default_category' => 0, 'default_brand' => 0];
$row = XhMapper::apply($item(['fiyat' => '100', 'kdv' => '']), $map, $opt, [XhImporter::class, 'lookup']);
ok($row['price'] === 168.0 && $row['vat'] === 20.0, 'satır: fiyat 168,00 ve ürün KDV oranı %20 kaydedilir');
$row = XhMapper::apply($item(['fiyat' => '100', 'kdv' => '10']), $map, $opt, [XhImporter::class, 'lookup']);
ok($row['price'] === 154.0 && $row['vat'] === 10.0, 'satırdaki KDV %10 ise ona göre');
$arr = $item(); $arr['kategori'] = ['Elektronik', 'Telefon', 'Telefon Kılıfı'];
$row = XhMapper::apply($arr, $map, $opt, null); ok($row['category_text'] === 'Elektronik > Telefon > Telefon Kılıfı', 'liste hâlindeki kategori seviyeleri yola çevrilir');

section('Otomatik kategori eşleştirme');
RcatInstaller::apply(); RcatMatcher::reset();
$tk = (int) DB::getValue("SELECT c.id_category FROM categories c JOIN categories p ON p.id_category = c.id_parent WHERE c.category_name = 'Telefon Kılıfı' AND p.category_name = 'Telefon'");
$row = XhMapper::apply($item(), $map, $opt, [XhImporter::class, 'lookup']);
ok($row['errors'] === [] && $row['id_category'] === $tk, '"Telefon Aksesuarları > Kılıf" → Elektronik > Telefon > Telefon Kılıfı (tek tık, eşleme tablosu gerekmez)');
ok((int) DB::getValue("SELECT id_category FROM rafimda_category_map WHERE origin = 'auto'") === $tk, 'otomatik eşleme öğrenilir');
$row = XhMapper::apply($item(['kategori' => 'Mont']), $map, array_merge($opt, ['default_category' => $tk]), [XhImporter::class, 'lookup']);
ok($row['id_category'] === $tk, 'belirsiz kaynak ("Mont") otomatik atanmaz; varsayılan kategoriye düşer');
ok(DB::getValue("SELECT origin FROM rafimda_category_map WHERE source_text = 'Mont'") === 'pending', '...ve yönetici onayı için bekleyen öneri olur');
$row = XhMapper::apply($item(), ['category_map' => ['Telefon Aksesuarları > Kılıf' => 3]] + $map, $opt, [XhImporter::class, 'lookup']);
ok($row['id_category'] === 3, 'bağlantıya özel elle eşleme her zaman önceliklidir');

section('Eksik marka oluşturma (yalnızca içe aktarmada)');
$conn = ['id_connection' => 1, 'id_vendor' => 0, 'id_supplier' => 0, 'mapping' => json_encode($map), 'options' => json_encode(['auto_brand' => 1, 'default_category' => $tk])];
$prev = XhImporter::preview($conn, $item(['marka' => 'YeniMarka']));
ok((int) DB::getValue("SELECT COUNT(*) FROM brands WHERE brand_name = 'YeniMarka'") === 0 && $prev['errors'] === [], 'önizleme marka oluşturmaz ama hatayı göstermez');
ok(XhImporter::lookup('brand', 'YeniMarka') === 0, 'process dışında lookup marka oluşturmaz');
ok(XhImporter::lookup('brand', 'BEZOS') === (int) DB::getValue("SELECT id_brand FROM brands WHERE brand_name = 'Bezos'"), 'marka adı büyük/küçük harf ve Türkçe karakter duyarsız bulunur');
$rc = new ReflectionClass(XhImporter::class); $p = $rc->getProperty('createBrands'); $p->setAccessible(true); $p->setValue(null, true);
$id = XhImporter::lookup('brand', 'Yeni Marka <b>X</b>'); $id2 = XhImporter::lookup('brand', 'yeni marka x');
$p->setValue(null, false);
ok($id > 0 && $id === $id2 && DB::getValue('SELECT brand_link FROM brands WHERE id_brand = ?', [$id]) === 'yeni-marka-x' && strpos((string) DB::getValue('SELECT brand_name FROM brands WHERE id_brand = ?', [$id]), '<') === false, 'eksik marka bir kez, temiz adla ve benzersiz slugla oluşturulur');

section('Şablon');
$tpl = file_get_contents(dirname(__DIR__) . '/assets/templates/front/xh_options.tpl');
ok(strpos($tpl, 'opt[vat_mode]') !== false && strpos($tpl, 'opt[profit_mode]') !== false && strpos($tpl, 'opt[auto_category]') === false && strpos($tpl, "'auto_category'") !== false, 'ayar formunda KDV, kâr hesabı ve otomatik eşleştirme seçenekleri');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail > 0 ? 1 : 0);
