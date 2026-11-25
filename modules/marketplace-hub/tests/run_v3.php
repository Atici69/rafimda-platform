<?php
// marketplace-hub v0.3: Trendyol katalog, otomatik kategori/özellik/marka eşleştirme, ürün oluşturma, canlı gönderim kilidi, batch durumu.
// SAHTE Trendyol istemcisiyle; gerçek ağ çağrısı YOK.
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/core/CategoryMatchStore.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }

class FakeTy3
{
	public static array $calls = [];
	public static $batch = ['status' => 'IN_PROGRESS', 'items' => []];
	public static $create = ['batchRequestId' => 'b-777'];
	public function __construct(public $a, public $b, public $c) {}
	public function getProducts($l, $p = 0) { return ['content' => []]; }
	public function getCategories($n = null)
	{
		self::$calls[] = ['getCategories'];
		return ['categories' => [
			['id' => 1, 'name' => 'Giyim', 'subCategories' => [['id' => 10, 'name' => 'Elbise', 'subCategories' => []], ['id' => 11, 'name' => 'Tişört', 'subCategories' => []]]],
			['id' => 2, 'name' => 'Ayakkabı', 'subCategories' => [['id' => 20, 'name' => 'Bot', 'subCategories' => []], ['id' => 21, 'name' => 'Sneaker', 'subCategories' => []]]],
			['id' => 3, 'name' => 'Elektronik', 'subCategories' => [['id' => 30, 'name' => 'Kulaklık', 'subCategories' => []]]],
		]];
	}
	public function getAttirupes($id)
	{
		self::$calls[] = ['getAttirupes', $id];
		return ['categoryAttributes' => [
			['attribute' => ['id' => 47, 'name' => 'Renk'], 'required' => true, 'allowCustom' => false, 'varianter' => true, 'attributeValues' => [['id' => 1001, 'name' => 'Siyah'], ['id' => 1002, 'name' => 'Kahverengi']]],
			['attribute' => ['id' => 60, 'name' => 'Cinsiyet'], 'required' => true, 'allowCustom' => false, 'attributeValues' => [['id' => 2001, 'name' => 'Kadın'], ['id' => 2002, 'name' => 'Erkek']]],
			['attribute' => ['id' => 1192, 'name' => 'Menşei'], 'required' => true, 'allowCustom' => false, 'attributeValues' => [['id' => 3001, 'name' => 'CN'], ['id' => 3002, 'name' => 'TR']]],
			['attribute' => ['id' => 70, 'name' => 'Materyal'], 'required' => false, 'allowCustom' => true, 'attributeValues' => []],
			['attribute' => ['id' => 80, 'name' => 'Beden'], 'required' => false, 'allowCustom' => true, 'varianter' => true, 'attributeValues' => [['id' => 4001, 'name' => '38'], ['id' => 4002, 'name' => '39']]],
		]];
	}
	public function getBrand($name) { self::$calls[] = ['getBrand', $name]; return mb_strtolower($name) === 'markab' ? [['id' => 500, 'name' => 'MarkaB'], ['id' => 501, 'name' => 'MarkaB Kids']] : []; }
	public function createProduct($d) { self::$calls[] = ['createProduct', $d]; return self::$create; }
	public function getBatch($id) { self::$calls[] = ['getBatch', $id]; return self::$batch; }
	public function updateStockPrice($b, $l, $s, $st, $sku = null) { return ['batchRequestId' => 'x']; }
}
MhCoreAdapter::$factories = ['trendyol' => fn($c) => new FakeTy3($c['supplier_id'], $c['api_key'], $c['api_secret'])];
$cache = sys_get_temp_dir() . '/mh-cache-' . bin2hex(random_bytes(4));
MhCatalog::$cacheDir = $cache;
$GLOBALS['domain'] = 'https://rafimda.com.tr/';
$calls = fn(string $m) => count(array_filter(FakeTy3::$calls, fn($c) => $c[0] === $m));

mh_fresh_db(); MhRegistry::reset(); CategoryMatchStore::reset(); MhCatalog::resetPrepared();
$db = $GLOBALS['db'];
$db->exec('ALTER TABLE categories ADD COLUMN id_parent INT DEFAULT 0');
$db->exec("DELETE FROM categories");
$db->exec("INSERT INTO categories (id_category, category_name, active, id_parent) VALUES (40,'Ana Sayfa',1,0),(50,'Kadın',1,40),(51,'Giyim',1,50),(52,'Ayakkabı',1,50),(60,'Elektronik',1,40),(61,'Kulaklık',1,60),(70,'Outdoor',1,40),(71,'Bot & Çizme',1,70)");
DB::execute("INSERT INTO rafimda_vendors (owner_user_id, store_name, store_slug, status) VALUES (1,'A','a','active'),(2,'B','b','active')");
$vA = 1; $vB = 2;
$acc = MhRepo::createAccount($vA, 'trendyol', 'TY', ['supplier_id' => '42', 'api_key' => 'KEY-TOP-SECRET', 'api_secret' => 'SEC-TOP-SECRET'])['id'];
$mk = function (int $v, string $name, int $cat, string $barcode, int $brand = 2, string $desc = '') use ($db) {
	$db->prepare('INSERT INTO products (product_name, id_category, id_brand, price, old_price, stock, stock_code, barcode, description, desi, cargo_day, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)')->execute([$name, $cat, $brand, 899.9, 1199.9, 7, 'SKU-' . $barcode, $barcode, $desc, 2, 2]);
	$id = (int) $db->lastInsertId();
	$db->prepare('INSERT INTO rafimda_vendor_products (id_product, id_vendor, moderation) VALUES (?,?,?)')->execute([$id, $v, 'approved']);
	$db->prepare('INSERT INTO images (id_product, cover) VALUES (?,1)')->execute([$id]);
	return $id;
};

section('Katalog: kategori ağacı, arama, önbellek');
$c = MhCatalog::categories($acc, $vA);
ok($c['ok'] && count($c['items']) === 8 && $c['items'][1]['path'] === 'Giyim > Elbise' && $c['items'][1]['leaf'] === true && $c['items'][0]['leaf'] === false, 'ağaç düzleştirildi: tam yol + en alt kategori bilgisi');
MhCatalog::categories($acc, $vA);
ok($calls('getCategories') === 1, 'ağaç önbellekten okunur (ikinci çağrıda ağ yok)');
$s = MhCatalog::searchCategories($acc, $vA, 'ayakkabi');
ok($s['ok'] && $s['items'][0]['leaf'] && strpos($s['items'][0]['path'], 'Ayakkabı') === 0, 'Türkçe karakter duyarsız arama, önce en alt kategoriler');
ok(!MhCatalog::searchCategories($acc, $vA, 'a')['ok'] && !MhCatalog::categories($acc, $vB)['ok'], 'kısa arama reddedilir; başka satıcı hesabın kataloğunu kullanamaz');

section('Tek tıkla kategori eşleme');
$am = MhCatalog::autoMapCategories($acc, $vA);
$map = MhRepo::categoryMap($acc);
ok($am['ok'] && ($map[61]['remote_category_id'] ?? '') === '30' && ($map[52]['remote_category_id'] ?? '') === '' , "Elektronik › Kulaklık → Kulaklık eşlendi; belirsiz 'Kadın › Ayakkabı' tahminle eşlenmedi => {$am['message']}");
MhRepo::setCategoryMap($acc, $vA, 52, '21', 'Ayakkabı > Sneaker');
MhCatalog::rememberManual($acc, $vA, 52, '21', 'Ayakkabı > Sneaker');
$am2 = MhCatalog::autoMapCategories($acc, $vA, true);
ok((MhRepo::categoryMap($acc)[52]['remote_category_id'] ?? '') === '21', 'elle yapılan (onaylı) eşleme "tümünü yeniden eşle"de korunur');

section('Ürün bazında otomatik kategori + özellik + marka');
MhRepo::setCategoryMap($acc, $vA, 52, '', '');
DB::execute('DELETE FROM rafimda_category_memory');
CategoryMatchStore::reset(); MhCatalog::resetPrepared();
$p1 = $mk($vA, 'Kadın Siyah Deri Bot', 52, '8690001');
$pl = MhPayload::build($p1, $acc);
ok($pl['ok'] && $pl['payload']['category']['remote_category_id'] === '20' && !empty($pl['payload']['category']['auto']), 'kategori eşlemesi olmayan ürün, adından Ayakkabı › Bot kategorisine eşlendi');
$q = MhDispatcher::queue($acc, $vA, [$p1]);
ok($q['queued'] === 1, 'ürün kuyruğa alındı');
$t = MhDispatcher::tick(10);
$row = DB::execute('SELECT * FROM mh_listings WHERE id_product = ?', [$p1])[0];
$prev = json_decode((string) $row['preview'], true);
ok($row['state'] === 'ready' && $calls('createProduct') === 0 && strpos($row['error'], 'kilidi kapalı') !== false, 'canlı gönderim kilidi kapalı: durum "Gönderime hazır", Trendyol\'a istek GÖNDERİLMEDİ');
$attrs = []; foreach ($prev[0]['attributes'] ?? [] as $a) { $attrs[$a['attributeId']] = $a['attributeValueId'] ?? $a['customAttributeValue']; }
ok(($attrs[47] ?? 0) === 1001 && ($attrs[60] ?? 0) === 2001 && ($attrs[1192] ?? 0) === 3002, 'zorunlu özellikler ürün adından otomatik doldu: Renk=Siyah, Cinsiyet=Kadın, Menşei=TR');
ok(($prev[0]['brandId'] ?? 0) === 500 && (MhCatalog::brandMap($acc)[2]['remote_brand_id'] ?? '') === '500', 'marka Trendyol listesinde birebir adla bulundu (MarkaB Kids seçilmedi) ve eşleme kaydedildi');
ok($prev[0]['categoryId'] === 20 && $prev[0]['barcode'] === '8690001' && $prev[0]['salePrice'] === 899.9 && $prev[0]['listPrice'] === 1199.9 && $prev[0]['vatRate'] === 20 && $prev[0]['images'][0]['url'] === 'https://rafimda.com.tr/img/products/1.jpg', 'kalem: kategori, barkod, fiyatlar, KDV, herkese açık görsel adresi');
ok(strpos((string) $row['preview'], 'KEY-TOP-SECRET') === false && strpos((string) $row['preview'], 'SEC-TOP-SECRET') === false, 'önizlemede API anahtarı yok');

section('Canlı gönderim ve onay takibi');
Settings::set('MARKETPLACE_HUB_LIVE_SUBMIT', '1');
ok(MhDispatcher::retryFailed($acc, $vA) === 1, 'hazır kayıt yeniden kuyruğa alındı');
MhDispatcher::tick(10);
$row = DB::execute('SELECT * FROM mh_listings WHERE id_product = ?', [$p1])[0];
$sent = array_values(array_filter(FakeTy3::$calls, fn($c) => $c[0] === 'createProduct'));
ok($row['state'] === 'sent' && $row['remote_id'] === 'batch:b-777' && count($sent) === 1 && count($sent[0][1]['items']) === 1, 'kilit açıkken createProduct çağrıldı; toplu istek numarası saklandı');
MhDispatcher::checkBatches();
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_product = ?', [$p1]) === 'sent' && strtotime((string) DB::getValue('SELECT next_attempt_at FROM mh_listings WHERE id_product = ?', [$p1])) > time() + 200, 'onay sürüyor: kayıt bekler, 5 dk sonra tekrar sorulur');
DB::execute('UPDATE mh_listings SET next_attempt_at = ?', [date('Y-m-d H:i:s', time() - 5)]);
FakeTy3::$batch = ['status' => 'COMPLETED', 'items' => [['status' => 'SUCCESS', 'requestItem' => ['product' => ['barcode' => '8690001']]]]];
MhDispatcher::checkBatches();
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_product = ?', [$p1]) === 'live' && DB::getValue('SELECT remote_id FROM mh_listings WHERE id_product = ?', [$p1]) === '8690001', 'Trendyol kabul etti: durum "Yayında", uzak kimlik barkod');
$p2 = $mk($vA, 'Erkek Kahverengi Bot', 52, '8690002');
MhDispatcher::queue($acc, $vA, [$p2]); MhDispatcher::tick(10);
FakeTy3::$batch = ['status' => 'COMPLETED', 'items' => [['status' => 'FAILED', 'failureReasons' => ['Görsel çözünürlüğü yetersiz']]]];
DB::execute('UPDATE mh_listings SET next_attempt_at = ?', [date('Y-m-d H:i:s', time() - 5)]);
MhDispatcher::checkBatches();
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_product = ?', [$p2]) === 'failed' && strpos((string) DB::getValue('SELECT error FROM mh_listings WHERE id_product = ?', [$p2]), 'çözünürlüğü') !== false, 'Trendyol reddetti: durum "Başarısız", nedeni kullanıcıya görünür');

section('Doğrulama: eksik veride gönderim yok');
$p3 = $mk($vA, 'Kadın Siyah Bot Barkodsuz', 52, '');
MhDispatcher::queue($acc, $vA, [$p3]); $before = $calls('createProduct'); MhDispatcher::tick(10);
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_product = ?', [$p3]) === 'failed' && strpos((string) DB::getValue('SELECT error FROM mh_listings WHERE id_product = ?', [$p3]), 'barkod') !== false && $calls('createProduct') === $before, 'barkodsuz ürün: uydurma barkod üretilmez, gönderilmez');
$p4 = $mk($vA, 'Siyah Bot', 71, '8690004');
MhDispatcher::queue($acc, $vA, [$p4]); MhDispatcher::tick(10);
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_product = ?', [$p4]) === 'failed' && strpos((string) DB::getValue('SELECT error FROM mh_listings WHERE id_product = ?', [$p4]), 'Cinsiyet') !== false, 'zorunlu özellik bulunamazsa (Cinsiyet) gönderilmez, hangi özelliğin eksik olduğu yazılır');
ok(MhCatalog::saveAttributeMap($acc, $vA, '20', ['60' => '2002'], [])['success'] && MhDispatcher::retryFailed($acc, $vA) >= 1, 'özellik elle sabitlendi');
MhDispatcher::tick(10);
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_product = ?', [$p4]) === 'sent', 'elle sabitlenen özellikle gönderildi');
ok(!MhCatalog::saveAttributeMap($acc, $vA, '20', ['60' => '9999'], [])['success'] && !MhCatalog::saveAttributeMap($acc, $vA, '20', [], ['60' => 'Serbest'])['success'], 'listede olmayan değer ve serbest değer kabul etmeyen özelliğe metin reddedilir');
$p5 = $mk($vA, 'Kadın Bot', 52, '8690005', 1);
MhDispatcher::queue($acc, $vA, [$p5]); MhDispatcher::tick(10);
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_product = ?', [$p5]) === 'failed' && strpos((string) DB::getValue('SELECT error FROM mh_listings WHERE id_product = ?', [$p5]), 'MarkaA') !== false, 'marka Trendyol\'da bulunamazsa gönderilmez, elle eşleme istenir');

section('Varyantlar ve görseller (kalem kurucu)');
$attrsList = MhCatalog::attributes($acc, $vA, '20')['items'];
$b = MhTrendyolBuilder::build(['id_product' => 9, 'name' => 'Kadın Bot', 'sku' => 'B9', 'price' => 100, 'old_price' => 0, 'vat' => 10, 'stock' => 3, 'images' => ['/img/products/9.jpg', 'http://localhost/x.jpg', 'https://10.0.0.5/a.jpg'], 'variants' => [
	['sku' => 'B9-38', 'barcode' => 'V38', 'price' => null, 'stock' => 2, 'options' => ['Renk' => 'Siyah', 'Beden' => '38']],
	['sku' => 'B9-40', 'barcode' => 'V40', 'price' => 120, 'stock' => 1, 'options' => ['Renk' => 'Kahverengi', 'Beden' => '40']],
]], ['brand_id' => 500, 'category_id' => 20, 'attrs' => $attrsList, 'attr_map' => ['60' => ['value_id' => '2001', 'value_text' => 'Kadın'], '1192' => ['value_id' => '3002', 'value_text' => 'TR']], 'base_url' => 'https://rafimda.com.tr']);
$va = []; foreach ($b['items'] ?? [] as $it) { foreach ($it['attributes'] as $a) { $va[$it['barcode']][$a['attributeId']] = $a['attributeValueId'] ?? $a['customAttributeValue']; } }
ok($b['ok'] && count($b['items']) === 2 && $b['items'][0]['productMainId'] === 'B9' && $b['items'][1]['salePrice'] === 120.0 && $b['items'][0]['vatRate'] === 10, 'her varyant ayrı kalem, ortak ana ürün kodu, varyant fiyatı');
ok(($va['V38'][47] ?? 0) === 1001 && ($va['V38'][80] ?? 0) === 4001 && ($va['V40'][47] ?? 0) === 1002 && ($va['V40'][80] ?? '') === '40', 'varyant Renk/Beden listeden kodla, listede olmayan beden serbest değerle');
ok(count($b['items'][0]['images']) === 1, 'localhost ve özel ağ IP görselleri gönderilmez');

section('Güvenlik');
$err = (string) DB::getValue("SELECT GROUP_CONCAT(error) FROM mh_listings") . json_encode(MhRepo::errors($vA, $acc, 200));
ok(strpos($err, 'KEY-TOP-SECRET') === false && strpos($err, 'SEC-TOP-SECRET') === false, 'hata kayıtlarında API anahtarı yok');
ok(!MhCatalog::setBrandMap($acc, $vB, 2, '500')['success'] && !MhCatalog::saveAttributeMap($acc, $vB, '20', ['60' => '2001'], [])['success'], 'başka satıcı marka/özellik eşlemesini değiştiremez');
Settings::set('MARKETPLACE_HUB_LIVE_SUBMIT', '0');

array_map('unlink', glob($cache . '/*') ?: []); @rmdir($cache);
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
