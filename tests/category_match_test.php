<?php
/**
 * Otomatik kategori eşleştirme testi (SQLite, ağ yok).
 * Çalıştırma: php tests/category_match_test.php
 */
error_reporting(E_ALL);
define('IN_SCRIPT', true);

$GLOBALS['db'] = new PDO('sqlite::memory:');
$GLOBALS['db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

class DB
{
	public static function execute($sql, $params = [])
	{
		try { $st = $GLOBALS['db']->prepare($sql); $st->execute($params); return $st->columnCount() > 0 ? $st->fetchAll() : true; } catch (Exception $e) { fwrite(STDERR, 'SQL: ' . $e->getMessage() . "\n"); return false; }
	}
	public static function insert($t, $d)
	{
		$c = array_keys($d);
		try { $GLOBALS['db']->prepare('INSERT INTO `' . $t . '` (`' . implode('`,`', $c) . '`) VALUES (:' . implode(',:', $c) . ')')->execute($d); return (int) $GLOBALS['db']->lastInsertId(); } catch (Exception $e) { fwrite(STDERR, 'SQL: ' . $e->getMessage() . "\n"); return false; }
	}
	public static function update($t, $d, $w, $wp = [])
	{
		$s = []; foreach ($d as $k => $v) { $s[] = "`$k` = :$k"; }
		$st = $GLOBALS['db']->prepare('UPDATE `' . $t . '` SET ' . implode(', ', $s) . ' WHERE ' . $w); $st->execute(array_merge($d, $wp)); return $st->rowCount();
	}
	public static function getValue($sql, $p = []) { $r = self::execute($sql, $p); return $r && isset($r[0]) ? reset($r[0]) : false; }
}
class Settings
{
	public static array $d = [];
	public static function get($k) { return self::$d[$k] ?? ''; }
	public static function set($k, $v) { self::$d[$k] = (string) $v; }
}

require dirname(__DIR__) . '/core/CategoryMatchStore.php';
require dirname(__DIR__) . '/modules/rafimda-theme/lib/RtCategoryTree.php';

$pass = 0;
$fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  OK   $n\n"; } else { $fail++; echo "  FAIL $n\n"; } }

$db = $GLOBALS['db'];
$db->exec('CREATE TABLE categories (id_category INTEGER PRIMARY KEY AUTOINCREMENT, id_parent INT DEFAULT 0, category_name TEXT, active INT DEFAULT 1)');
$db->exec('CREATE TABLE brands (id_brand INTEGER PRIMARY KEY AUTOINCREMENT, brand_name TEXT, brand_link TEXT, active INT DEFAULT 1)');
$db->exec('CREATE TABLE products (id_product INTEGER PRIMARY KEY AUTOINCREMENT, product_name TEXT, id_category INT)');
$db->exec("INSERT INTO categories (id_category, id_parent, category_name) VALUES (1, 0, 'Ana Sayfa')");
$ids = [];
foreach (RtCategoryTree::tree() as $top => $subs) {
	$tid = DB::insert('categories', ['id_parent' => 1, 'category_name' => $top]);
	foreach ($subs as $s) { $ids[$top . ' > ' . $s] = DB::insert('categories', ['id_parent' => $tid, 'category_name' => $s]); }
}
$pasif = DB::insert('categories', ['id_parent' => 1, 'category_name' => 'Eski Kategori', 'active' => 0]);
$pasifAlt = DB::insert('categories', ['id_parent' => $pasif, 'category_name' => 'Elbise']);

echo "== Site ağacı ==\n";
$t = CategoryMatchStore::siteTargets();
$paths = array_map(fn($x) => implode(' > ', $x['path']), $t);
ok(in_array('Kadın > Giyim', $paths, true) && !in_array('Ana Sayfa', $paths, true), 'kök hariç tam yollar üretilir');
ok(!in_array('Eski Kategori > Elbise', $paths, true), 'pasif üst kategorinin altı hedef olmaz');

echo "== Motor ==\n";
$cases = [
	['Giyim > Kadın > Elbise', '', 'Kadın > Giyim'],
	['', 'Kadın Çiçekli Midi Elbise', 'Kadın > Giyim'],
	['Erkek Giyim / Tişört', 'Basic Erkek T-Shirt', 'Erkek > Giyim'],
	['', 'Erkek Deri Bot', 'Erkek > Ayakkabı'],
	['Elektronik > Telefon Kılıfı', 'iPhone 15 Silikon Kılıf', 'Elektronik > Telefon Aksesuarları'],
	['', 'Samsung Galaxy S24 Cep Telefonu', 'Elektronik > Cep Telefonu'],
	['Kozmetik', 'Mat Ruj Kırmızı', 'Kozmetik > Makyaj'],
	['', 'Whey Protein 2 kg Çikolata', 'Vitamin & Takviye > Sporcu Besinleri'],
	['', 'Bosch Akülü Vidalama Matkap', 'Yapı Market & Bahçe > Elektrikli El Aletleri'],
	['Ev Tekstili', 'Çift Kişilik Nevresim Takımı', 'Ev & Yaşam > Ev Tekstili'],
	['', 'Çocuk Spor Ayakkabı', 'Anne & Çocuk > Çocuk Ayakkabı'],
	['', 'Kedi Maması 15 kg', 'Süpermarket > Evcil Hayvan'],
	['Saat', 'Erkek Kol Saati Çelik', 'Saat & Aksesuar > Kol Saati'],
	['', 'Bluetooth Kulaklık Kablosuz', 'Elektronik > Kulaklık'],
	['KADIN AYAKKABILARI', 'Topuklu Stiletto', 'Kadın > Ayakkabı'],
	['Women > Dresses', 'Summer Dress', 'Kadın > Giyim'],
];
foreach ($cases as [$c, $n, $want]) {
	$r = CategoryMatchStore::resolveSite(0, $c, $n);
	ok($r['path'] === $want && $r['id_category'] === $ids[$want], sprintf('"%s" / "%s" → %s (%s %.2f)', $c, $n, $r['path'], $r['confidence'], $r['score']));
}
$none = CategoryMatchStore::resolveSite(0, 'Qwxz', 'Asdfgh');
ok($none['id_category'] === 0 && $none['confidence'] === 'none', 'anlamsız metin eşleşmez, yedek tanımlı değilse 0');
Settings::set('RAFIMDA_FALLBACK_CATEGORY', (string) $ids['Süpermarket > Gıda']);
$fb = CategoryMatchStore::resolveSite(0, 'Qwxz', 'Asdfgh');
ok($fb['id_category'] === $ids['Süpermarket > Gıda'] && $fb['from'] === 'fallback' && $fb['confidence'] === 'low', 'eşleşme yoksa yönetici yedek kategorisi kullanılır');
ok(CategoryMatcher::match('', 'Kadın Elbise', CategoryMatcher::prepare([['id' => 1, 'path' => 'Erkek > Giyim'], ['id' => 2, 'path' => 'Kadın > Giyim']]))['id'] === 2, 'cinsiyet çatışması cezalandırılır');

echo "== Hafıza ve kontrol listesi ==\n";
$r = CategoryMatchStore::resolveSite(7, 'Tedarikçi > Abiye', 'Saten Abiye');
$db->exec("INSERT INTO products (id_product, product_name, id_category) VALUES (100, 'Saten Abiye', " . (int) $r['id_category'] . "), (101, 'Kadife Abiye', " . (int) $r['id_category'] . ")");
CategoryMatchStore::queueReview(100, 7, 'xml', 'Tedarikçi > Abiye', 'Saten Abiye', ['confidence' => 'medium'] + $r);
CategoryMatchStore::queueReview(101, 7, 'xml', 'Tedarikçi > Abiye', 'Kadife Abiye', ['confidence' => 'medium'] + $r);
CategoryMatchStore::queueReview(102, 7, 'xml', 'x', 'y', ['confidence' => 'high'] + $r);
ok(CategoryMatchStore::pendingCount(7) === 2 && CategoryMatchStore::pendingCount(8) === 0, 'orta güvenliler kontrol listesine girer; yüksek güvenli girmez; satıcıya özel');
$fix = CategoryMatchStore::resolveReview(7, 100, $ids['Kadın > Giyim']);
ok($fix['success'] && $fix['updated'] === 2 && (int) DB::getValue('SELECT id_category FROM products WHERE id_product = 101') === $ids['Kadın > Giyim'], 'düzeltme aynı kaynak kategorideki bekleyen ürünlere de uygulanır');
ok(CategoryMatchStore::pendingCount(7) === 0, 'düzeltilenler listeden çıkar');
$again = CategoryMatchStore::resolveSite(7, 'TEDARİKÇİ > abiye', 'Başka ürün');
ok($again['from'] === 'memory' && $again['confidence'] === 'confirmed' && $again['id_category'] === $ids['Kadın > Giyim'], 'onaylı düzeltme hatırlanır (büyük/küçük harf ve Türkçe karakter duyarsız)');
ok(CategoryMatchStore::resolveSite(8, 'Tedarikçi > Abiye', '')['from'] !== 'memory', 'bir satıcının düzeltmesi başka satıcıyı etkilemez');
ok(!CategoryMatchStore::resolveReview(8, 100, $ids['Kadın > Giyim'])['success'], 'başka satıcı kontrol kaydını değiştiremez');
CategoryMatchStore::remember('site', 7, 'Tedarikçi > Abiye', (string) $ids['Erkek > Giyim'], 'Erkek > Giyim', false);
ok(CategoryMatchStore::recall('site', 7, 'Tedarikçi > Abiye')['target_id'] === (string) $ids['Kadın > Giyim'], 'otomatik karar onaylı düzeltmenin üzerine yazmaz');
$auto = CategoryMatchStore::resolveSite(9, 'Ev Tekstili', 'Nevresim');
ok(CategoryMatchStore::recall('site', 9, 'Ev Tekstili')['confirmed'] === false && $auto['confidence'] === 'high', 'yüksek güvenli otomatik karar (onaysız) hatırlanır');

echo "== Marka ==\n";
$db->exec("INSERT INTO brands (brand_name, brand_link) VALUES ('Koton', 'koton')");
$b = CategoryMatchStore::brandId('KOTON');
ok($b['id'] === 1 && !$b['created'], 'mevcut marka büyük/küçük harf duyarsız bulunur');
$n = CategoryMatchStore::brandId('Şık Moda');
ok($n['created'] && $n['id'] > 1 && DB::getValue('SELECT brand_link FROM brands WHERE id_brand = ?', [$n['id']]) === 'sik-moda', 'olmayan marka açılır (bağlantı Türkçe karakterden arındırılır)');
ok(CategoryMatchStore::brandId('şık moda')['id'] === $n['id'], 'aynı marka ikinci kez açılmaz');
Settings::set('RAFIMDA_IMPORT_CREATE_BRANDS', '0');
ok(CategoryMatchStore::brandId('Yeni Marka X')['id'] === 0, 'marka açma kapatılabilir');
Settings::set('RAFIMDA_FALLBACK_BRAND', '1');
ok(CategoryMatchStore::brandId('')['id'] === 1, 'marka boşsa yedek marka kullanılır');

echo "== XML Hub entegrasyonu ==\n";
Settings::set('RAFIMDA_IMPORT_CREATE_BRANDS', '1');
require dirname(__DIR__) . '/modules/xml-hub/lib/XhMapper.php';
require dirname(__DIR__) . '/modules/xml-hub/lib/XhImporter.php';
$map = ['fields' => ['name' => 'Ad', 'price' => 'Fiyat', 'brand' => 'Marka', 'category' => 'Kategori'], 'category_map' => [], 'brand_map' => []];
$row = XhMapper::apply(['Ad' => 'Kadın Keten Gömlek', 'Fiyat' => '450', 'Marka' => 'Yeni Tedarikçi Markası', 'Kategori' => 'Bayan Giyim > Gömlek'], $map, [], XhImporter::lookupFor(3, []));
ok($row['errors'] === [] && $row['id_category'] === $ids['Kadın > Giyim'] && $row['id_brand'] > 0, 'XML: eşleme tablosunda olmayan tedarikçi kategorisi otomatik bulunur, marka açılır');
ok(XhImporter::$lastAuto !== null && XhImporter::$lastAuto['source_text'] === 'Bayan Giyim > Gömlek', 'XML: otomatik karar kontrol listesi için saklanır');
$row = XhMapper::apply(['Ad' => 'Paslanmaz Çelik Tencere Seti', 'Fiyat' => '900', 'Marka' => 'Koton'], $map, [], XhImporter::lookupFor(3, []));
ok($row['errors'] === [] && $row['id_category'] === $ids['Ev & Yaşam > Mutfak Gereçleri'], 'XML: kategori alanı olmayan beslemede ürün adından bulunur');
$row = XhMapper::apply(['Ad' => 'Kadın Keten Gömlek', 'Fiyat' => '450', 'Marka' => 'Koton', 'Kategori' => 'Bayan Giyim > Gömlek'], $map, ['default_category' => $ids['Erkek > Giyim']], XhImporter::lookupFor(3, []));
ok($row['id_category'] === $ids['Kadın > Giyim'], 'XML: tedarikçi kategorisi varsa otomatik eşleşme varsayılan kategoriden önce gelir');
$row = XhMapper::apply(['Ad' => 'Paslanmaz Çelik Tencere Seti', 'Fiyat' => '900', 'Marka' => 'Koton'], $map, ['default_category' => $ids['Erkek > Giyim']], XhImporter::lookupFor(3, []));
ok($row['id_category'] === $ids['Erkek > Giyim'], 'XML: kategori alanı yoksa kullanıcının seçtiği varsayılan kategori korunur');
$row = XhMapper::apply(['Ad' => 'Kadın Keten Gömlek', 'Fiyat' => '450', 'Marka' => 'Bilinmeyen', 'Kategori' => 'Bayan Giyim'], $map, ['auto_category' => 0], XhImporter::lookupFor(3, ['auto_category' => 0]));
ok($row['errors'] !== [], 'XML: otomatik eşleştirme kapatılırsa eski davranış (eşleşmeyen hata verir)');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
