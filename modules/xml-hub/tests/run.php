<?php
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0; $failures = [];
function ok(bool $c, string $n): void { global $pass, $fail, $failures; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; $failures[] = $n; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function feed(array $items, string $extra = ''): string
{
	$x = '<?xml version="1.0" encoding="UTF-8"?><Catalog>' . $extra . '<Products>';
	foreach ($items as $i) {
		$x .= '<Product>';
		foreach ($i as $k => $v) {
			if (is_array($v)) { $x .= "<$k>"; foreach ($v as $vv) { $x .= is_array($vv) ? '<Item>' . implode('', array_map(fn($kk, $vvv) => "<$kk>$vvv</$kk>", array_keys($vv), $vv)) . '</Item>' : '<Img>' . $vv . '</Img>'; } $x .= "</$k>"; }
			else { $x .= "<$k>" . htmlspecialchars((string) $v, ENT_XML1) . "</$k>"; }
		}
		$x .= '</Product>';
	}
	return $x . '</Products></Catalog>';
}
function put(string $xml): string { $p = XhStorage::newPath('t'); file_put_contents($p, $xml); return $p; }
function fresh_rows(): array { return DB::execute('SELECT * FROM products ORDER BY id_product') ?: []; }

// ---------- 1. Migration ----------
section('Migration');
xh_fresh_db();
ok(XhMigrator::files() === ['001_init', '002_source_meta_credentials', '003_export_feeds'], 'migration dosyası bulundu');
$raw = file_get_contents(dirname(__DIR__) . '/migrations/001_init.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|ALTER)\b/i', $raw) && !preg_match('/^\s*--/m', $raw) && preg_match_all('/CREATE TABLE(?! IF NOT EXISTS)/', $raw) === 0, 'migration yıkıcı değil, IF NOT EXISTS, "--" ifadesi yok');
foreach (['xh_suppliers', 'xh_connections', 'xh_items', 'xh_runs', 'xh_errors', 'xh_price_history', 'xh_stock_history'] as $t) {
	ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "tablo: $t");
}
ok(strpos(file_get_contents(dirname(__DIR__) . '/uninstall.sql'), 'DROP') === false, 'uninstall.sql veri silmiyor');

// ---------- 2. SSRF ----------
section('SSRF koruması');
$dns = ['pub.example.com' => ['93.184.216.34'], 'evil.example.com' => ['93.184.216.34', '10.0.0.5'], 'meta.example.com' => ['169.254.169.254'], 'lo.example.com' => ['127.0.0.1'], 'v6lo.example.com' => ['::1'], 'v6pub.example.com' => ['2606:4700:4700::1111'], 'mapped.example.com' => ['::ffff:127.0.0.1'], 'nxdomain.example.com' => []];
XhUrlGuard::$resolver = fn(string $h): array => $dns[$h] ?? [];
$blocked = [
	'http://127.0.0.1/x.xml' => 'loopback IP', 'http://localhost/x.xml' => 'localhost', 'http://sub.localhost/x' => '.localhost', 'http://10.1.2.3/x' => '10/8', 'http://172.16.0.1/x' => '172.16/12',
	'http://192.168.1.1/x' => '192.168/16', 'http://169.254.169.254/latest/meta-data' => 'bulut metadata', 'http://[::1]/x' => 'IPv6 loopback', 'http://[fd00::1]/x' => 'IPv6 ULA', 'http://[fe80::1]/x' => 'IPv6 link-local',
	'http://[::ffff:127.0.0.1]/x' => 'IPv4-mapped loopback', 'http://[2002:7f00:1::]/x' => '6to4 loopback', 'http://2130706433/x' => 'ondalık IP', 'http://0x7f.0.0.1/x' => 'onaltılık IP', 'http://017700000001/x' => 'sekizlik IP',
	'http://0.0.0.0/x' => '0.0.0.0', 'http://100.64.0.1/x' => 'CGNAT', 'http://224.0.0.1/x' => 'multicast', 'http://pub.example.com:22/x' => 'port 22', 'http://pub.example.com:6379/x' => 'port 6379',
	'ftp://pub.example.com/x' => 'ftp', 'file:///etc/passwd' => 'file://', 'gopher://pub.example.com/x' => 'gopher', 'http://user:pw@pub.example.com/x' => 'kullanıcı bilgisi', 'http://evil.example.com/x' => 'DNS çoklu kayıt (biri özel)',
	'http://meta.example.com/x' => 'DNS→metadata', 'http://lo.example.com/x' => 'DNS→127', 'http://v6lo.example.com/x' => 'DNS→::1', 'http://mapped.example.com/x' => 'DNS→mapped', 'http://nxdomain.example.com/x' => 'çözümlenemeyen', 'http://intranet/x' => 'noktasız ad', 'http://a.internal/x' => '.internal', '' => 'boş', "http://pub.example.com/\nHost: x" => 'satır sonu',
];
foreach ($blocked as $u => $why) { ok(!XhUrlGuard::validate($u)['ok'], "engellendi: $why"); }
$g = XhUrlGuard::validate('https://pub.example.com/feed.xml');
ok($g['ok'] && $g['ip'] === '93.184.216.34' && $g['port'] === 443, 'genel adres kabul edilir, IP sabitlenir');
ok(XhUrlGuard::validate('http://pub.example.com:8080/f.xml')['ok'] && XhUrlGuard::validate('http://v6pub.example.com/f')['ok'] && XhUrlGuard::validate('http://93.184.216.34/f')['ok'], 'izinli port, genel IPv6 ve genel IP kabul');

section('Güvenli HTTP (yönlendirme, boyut)');
$script = [];
XhHttp::$transport = function (array $req) use (&$script): array {
	$s = (array_shift($script) ?? ['status' => 500]) + ['location' => '', 'error' => '', 'body' => ''];
	if ($s['body'] !== '') { ($req['sink'])($s['body']); }
	$GLOBALS['lastReq'] = $req;
	return ['status' => $s['status'], 'location' => $s['location'] ?? '', 'error' => $s['error'] ?? ''];
};
$dest = XhStorage::newPath('d');
$script = [['status' => 200, 'body' => '<a/>']];
$r = XhHttp::fetchToFile('https://pub.example.com/f.xml', $dest);
ok($r['ok'] && file_get_contents($dest) === '<a/>' && $GLOBALS['lastReq']['ip'] === '93.184.216.34', 'normal indirme + IP sabitleme (transport sabit IP alır)');
$script = [['status' => 302, 'location' => 'http://127.0.0.1/admin', 'body' => 'redirect-body'], ['status' => 200, 'body' => 'SECRET']];
$r = XhHttp::fetchToFile('https://pub.example.com/f.xml', $dest);
ok(!$r['ok'] && strpos($r['error'], 'Yönlendirme engellendi') !== false && !is_file($dest), 'özel IP\'ye yönlendirme engellenir, dosya kalmaz');
$script = [['status' => 302, 'location' => '/b.xml', 'body' => 'REDIRECT-PAGE'], ['status' => 200, 'body' => '<ok/>']];
$r = XhHttp::fetchToFile('https://pub.example.com/a.xml', $dest);
ok($r['ok'] && file_get_contents($dest) === '<ok/>', 'güvenli göreli yönlendirme izlenir, yönlendirme gövdesi dosyaya karışmaz');
$script = [['status' => 302, 'location' => 'http://pub.example.com/b'], ['status' => 302, 'location' => 'http://pub.example.com/c'], ['status' => 302, 'location' => 'http://pub.example.com/d'], ['status' => 302, 'location' => 'http://pub.example.com/e'], ['status' => 200, 'body' => 'x']];
ok(!XhHttp::fetchToFile('https://pub.example.com/a', $dest)['ok'], 'çok fazla yönlendirme reddedilir');
$script = [['status' => 302, 'location' => 'http://meta.example.com/x']];
ok(!XhHttp::fetchToFile('https://pub.example.com/a', $dest)['ok'], 'DNS ile metadata\'ya yönlendirme engellenir');
$script = [['status' => 200, 'body' => str_repeat('A', 1000)]];
$r = XhHttp::fetchToFile('https://pub.example.com/a', $dest, 500);
ok(!$r['ok'] && strpos($r['error'], 'boyut') !== false && !is_file($dest), 'boyut sınırı aşılınca kesilir ve dosya silinir');
$script = [['status' => 404]];
ok(!XhHttp::fetchToFile('https://pub.example.com/a', $dest)['ok'], 'HTTP 404 hata sayılır');
$script = [['status' => 0, 'error' => 'Bağlantı hatası: timeout']];
ok(strpos(XhHttp::fetchToFile('https://pub.example.com/a', $dest)['error'], 'timeout') !== false, 'zaman aşımı hatası iletilir');
ok(!XhHttp::fetchToFile('http://127.0.0.1/a', $dest)['ok'] && !XhHttp::fetchToString('http://10.0.0.1/a.jpg', 1000)['ok'], 'doğrudan özel adres istekleri (dosya ve görsel) engellenir');
XhHttp::$transport = null;

// ---------- 3. XML güvenliği ----------
section('XML güvenliği');
$xxe = put('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r><p><name>&x;</name></p><p><name>b</name></p></r>');
ok(!XhXml::inspect($xxe)['ok'] && !XhXml::validate($xxe)['ok'], 'XXE (SYSTEM entity) reddedilir');
$bomb = put('<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]><lolz>&lol2;</lolz>');
ok(!XhXml::validate($bomb)['ok'], 'Billion-laughs (entity genişletme) reddedilir');
$dt = put('<?xml version="1.0"?><!DOCTYPE r SYSTEM "http://127.0.0.1/evil.dtd"><r><p>1</p><p>2</p></r>');
ok(!XhXml::validate($dt)['ok'], 'harici DTD reddedilir');
$u16 = put(mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ENTITY x "y">]><r><p>1</p></r>', 'UTF-16LE', 'UTF-8'));
ok(!XhXml::inspect($u16)['ok'], 'UTF-16 ile gizlenmiş DOCTYPE reddedilir');
ok(!XhXml::validate(put('<r><p>1</p></r'))['ok'], 'bozuk XML reddedilir');
ok(!XhXml::validate(put('plain text'))['ok'] && !XhXml::validate(put(''))['ok'], 'XML olmayan/boş dosya reddedilir');
$deep = put('<?xml version="1.0"?>' . str_repeat('<a>', 60) . 'x' . str_repeat('</a>', 60));
ok(!XhXml::validate($deep)['ok'], 'aşırı derin XML reddedilir (derinlik bombası)');
$noent = put('<?xml version="1.0"?><r><p>&undefined;</p><p>2</p></r>');
$threw = false; try { iterator_to_array(XhXml::stream($noent, 'r/p')); } catch (Throwable $e) { $threw = true; }
ok(!XhXml::validate($noent)['ok'] || $threw, 'tanımsız entity başvurusu çözülmez/reddedilir');
$big = XhStorage::newPath('big'); $fh = fopen($big, 'wb'); fseek($fh, XhXml::MAX_BYTES + 10); fwrite($fh, 'x'); fclose($fh);
ok(!XhXml::inspect($big)['ok'], '50 MB üstü dosya içerik okunmadan reddedilir');
$wide = put('<?xml version="1.0"?><r><p>' . str_repeat('<c>1</c>', XhXml::MAX_ITEM_NODES + 50) . '</p><p><c>2</c></p></r>');
$threw = false; try { iterator_to_array(XhXml::stream($wide, 'r/p')); } catch (Throwable $e) { $threw = true; }
ok($threw, 'tek üründe aşırı düğüm sayısı reddedilir');
$cd = put('<?xml version="1.0"?><r><p><name><![CDATA[<b>Kalın</b> & ürün]]></name></p><p><name>2</name></p></r>');
ok(iterator_to_array(XhXml::stream($cd, 'r/p'))[0]['name'] === '<b>Kalın</b> & ürün', 'CDATA düz metin olarak okunur');

// ---------- 4. Ayrıştırma ve alan çıkarımı ----------
section('Ayrıştırma ve öğe tespiti');
$p = put(feed([
	['Name' => 'Tişört', 'Sku' => 'T1', 'Price' => '99,90', 'Images' => ['http://a.com/1.jpg', 'http://a.com/2.jpg'], 'Variants' => [['Sku' => 'T1-S', 'Size' => 'S', 'Qty' => 3], ['Sku' => 'T1-M', 'Size' => 'M', 'Qty' => 4]]],
	['Name' => 'Pantolon', 'Sku' => 'P1', 'Price' => '1.299,00', 'Images' => ['http://a.com/3.jpg']],
]));
ok(XhXml::validate($p)['ok'], 'geçerli XML doğrulanır');
ok(XhXml::detectItemPath(put('<?xml version="1.0"?><c><ps><p><Name>Tek</Name><Sku>1</Sku><Price>5</Price></p></ps></c>')) === 'c/ps/p', 'tek ürünlü dosyada kayıt düğümü bulunur');
ok(XhXml::detectItemPath($p) === 'Catalog/Products/Product', 'ürün düğümü otomatik bulundu (varyantlar daha derin olduğundan seçilmedi)');
ok(XhXml::countItems($p, 'Catalog/Products/Product') === 2, 'ürün sayısı 2');
$it = iterator_to_array(XhXml::stream($p, 'Catalog/Products/Product'));
ok($it[0]['Name'] === 'Tişört' && $it[0]['Images/Img'] === ['http://a.com/1.jpg', 'http://a.com/2.jpg'] && is_array($it[0]['Variants/Item']) && count($it[0]['Variants/Item']) === 2 && $it[0]['Variants/Item'][1]['Size'] === 'M', 'düz alanlar, çoklu görsel listesi ve varyant kayıtları çıkarıldı');
ok(array_keys(iterator_to_array(XhXml::stream($p, 'Catalog/Products/Product', 1))) === [1], 'offset ile kaldığı yerden devam eder');
$rowsSnapshot = XhStorage::newPath('rows');
$indexSnapshot = $rowsSnapshot . '.idx';
$snapshotCount = XhXml::buildSnapshot($p, 'Catalog/Products/Product', $rowsSnapshot, $indexSnapshot);
$snapshotRows = iterator_to_array(XhXml::streamSnapshot($rowsSnapshot, $indexSnapshot, 1));
ok($snapshotCount === 2 && array_keys($snapshotRows) === [1] && $snapshotRows[1]['Name'] === 'Pantolon', 'indeksli ürün anlık görüntüsü offset 1 kaydına doğrudan gider');
XhStorage::remove($rowsSnapshot);
XhStorage::remove($indexSnapshot);
$at = put('<r><items><it id="7" cur="TRY"><Price currency="USD">10</Price><n>A</n></it><it id="8"><Price>5</Price><n>B</n></it></items></r>');
$ai = iterator_to_array(XhXml::stream($at, 'r/items/it'));
ok($ai[0]['@id'] === '7' && $ai[0]['Price@currency'] === 'USD' && $ai[0]['Price'] === '10', 'öznitelikler (@id, Price@currency) okunur');

// ---------- 5. Alan eşleştirme ----------
section('Alan eşleştirme motoru');
foreach (['1.234,56' => 1234.56, '1,234.56' => 1234.56, '99,90' => 99.9, '12.5' => 12.5, '1.234.567' => 1234567.0, '₺ 45 TL' => 45.0, '0' => 0.0] as $in => $exp) { ok(XhMapper::parseNumber($in) === $exp, "sayı çözümleme: $in → $exp"); }
ok(XhMapper::parseNumber('abc') === null && XhMapper::parseNumber('') === null, 'sayı olmayan değer null');
$sg = XhMapper::suggest($it[0]);
ok(($sg['fields']['name'] ?? '') === 'Name' && ($sg['fields']['sku'] ?? '') === 'Sku' && ($sg['fields']['price'] ?? '') === 'Price' && ($sg['fields']['images'] ?? '') === 'Images/Img', 'alan önerisi (İngilizce adlar)');
ok(($sg['variant_source'] ?? '') === 'Variants/Item' && ($sg['variant_fields']['sku'] ?? '') === 'Sku' && ($sg['variant_fields']['size'] ?? '') === 'Size' && ($sg['variant_fields']['stock'] ?? '') === 'Qty', 'varyant kaynağı ve alanları önerildi');
$tr = XhMapper::suggest(['ÜrünAdı' => 'x', 'Stok_Kodu' => 'y', 'Satış Fiyatı' => '1', 'Barkod' => 'z', 'Marka' => 'm', 'Kategori' => 'k']);
ok(($tr['fields']['name'] ?? '') === 'ÜrünAdı' && ($tr['fields']['sku'] ?? '') === 'Stok_Kodu' && ($tr['fields']['price'] ?? '') === 'Satış Fiyatı' && ($tr['fields']['barcode'] ?? '') === 'Barkod' && ($tr['fields']['brand'] ?? '') === 'Marka', 'Türkçe alan adları önerilir');
$map = ['fields' => ['name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'sale_price' => 'Sale', 'stock' => 'Qty', 'brand' => 'Brand', 'category' => 'Cat', 'images' => 'Images/Img', 'desi' => 'Desi'], 'category_map' => ['Tedarikçi Giyim' => 1], 'brand_map' => []];
$lookup = fn($k, $t) => $k === 'brand' && $t === 'MarkaB' ? 2 : 0;
$row = XhMapper::apply(['Name' => ' <b>Tişört</b> ', 'Sku' => 'S1', 'Price' => '100,00', 'Sale' => '80,00', 'Qty' => '12', 'Brand' => 'MarkaB', 'Cat' => 'Tedarikçi Giyim', 'Images/Img' => ['http://x.com/a.jpg', 'javascript:alert(1)', 'http://x.com/a.jpg'], 'Desi' => '2,4'], $map, ['price_markup_percent' => 10, 'price_add' => 5], $lookup);
ok($row['errors'] === [] && $row['name'] === 'Tişört' && $row['price'] === 93.0 && $row['old_price'] === 115.0 && $row['stock'] === 12.0 && $row['id_brand'] === 2 && $row['id_category'] === 1, 'indirimli fiyat → fiyat/eski fiyat, markup+ekleme, marka/kategori eşleme');
ok($row['images'] === ['http://x.com/a.jpg'] && $row['desi'] === 2, 'görsellerde yalnızca http(s), tekrarlar elenir; desi tamsayı');
$bad = XhMapper::apply(['Name' => 'A', 'Price' => '-5', 'Qty' => 'çok', 'Brand' => 'Yok', 'Cat' => 'Yok'], $map, [], $lookup);
ok(count($bad['errors']) >= 4, 'eksik/kısa ad, geçersiz fiyat, sayısal olmayan stok, eşleşmeyen marka/kategori hata verir (' . count($bad['errors']) . ')');
$def = XhMapper::apply(['Name' => 'Test Ürünü', 'Price' => '10', 'Brand' => 'Yok', 'Cat' => 'Yok'], $map, ['default_brand' => 1, 'default_category' => 2], $lookup);
ok($def['errors'] === [] && $def['id_brand'] === 1 && $def['id_category'] === 2, 'varsayılan marka/kategori devreye girer');
$vm = ['fields' => ['name' => 'Name', 'price' => 'Price', 'brand' => '=MarkaA', 'category' => '=Giyim'], 'variant_source' => 'Variants/Item', 'variant_fields' => ['sku' => 'Sku', 'size' => 'Size', 'stock' => 'Qty']];
$vr = XhMapper::apply($it[0], $vm, ['default_brand' => 1, 'default_category' => 1]);
ok(count($vr['variants']) === 2 && $vr['variants'][0]['option1_name'] === 'Beden' && $vr['variants'][1]['option1_value'] === 'M' && $vr['stock'] === 7.0 && $vr['brand_text'] === 'MarkaA', 'varyantlar normalleşir, ürün stoku varyant toplamı, sabit değer (=) çalışır');
ok(XhMapper::apply($it[0], $vm, ['default_brand' => 1, 'default_category' => 1])['hash'] === $vr['hash'] && XhMapper::apply(array_merge($it[0], ['Price' => '1']), $vm, ['default_brand' => 1, 'default_category' => 1])['hash'] !== $vr['hash'], 'satır özeti (hash) değişikliği algılar');
$alt = XhMapper::apply(['A' => '', 'B' => 'Yedek Ad', 'Price' => '5'], ['fields' => ['name' => 'A|B', 'price' => 'Price']], ['default_brand' => 1, 'default_category' => 1]);
ok($alt['name'] === 'Yedek Ad', '"a|b" ilk dolu alanı seçer');
$sm = XhMapper::sanitizeMapping(['fields' => ['name' => 'N', 'hack' => 'x', 'price' => ''], 'category_map' => ['K' => '3', 'Z' => '0'], 'variant_fields' => ['sku' => 'S', 'evil' => 'e']]);
ok($sm['fields'] === ['name' => 'N'] && $sm['category_map'] === ['K' => 3] && $sm['variant_fields'] === ['sku' => 'S'], 'eşleştirme girdisi temizlenir (bilinmeyen hedefler atılır)');
$sm2 = XhMapper::sanitizeMapping(['product_map' => ['sku:ABC' => '17', 'bad' => '0']]);
ok(($sm2['product_map']['sku:ABC'] ?? 0) === 17 && count($sm2['product_map']) === 1, 'manuel ürün eşleştirmesi güvenli biçimde saklanır');
ok(XhMapper::mappedId(['Çanta & Cüzdan' => 5], 'canta-cuzdan') === 5, 'marka/kategori eşleştirmesi Türkçe karakter ve noktalama farklarını tolere eder');
$short = XhMapper::apply(['Name' => 'Açıklamalı Ürün', 'Price' => '10', 'Desc' => '<p>Uzun ürün açıklaması &amp; detay.</p>'], ['fields' => ['name' => 'Name', 'price' => 'Price', 'description' => 'Desc']], ['default_brand' => 1, 'default_category' => 1]);
ok($short['errors'] === [] && $short['short_description'] === 'Uzun ürün açıklaması & detay.', 'kısa açıklama XML\'de yoksa açıklamadan otomatik üretilir');

// ---------- 6. İçe aktarma, kuyruk, sahiplik ----------
section('Satıcı bağlantısı: içe aktarma ve sahiplik');
xh_fresh_db();
XhUrlGuard::$resolver = fn(string $h): array => $dns[$h] ?? ['93.184.216.34']; // test DNS: bilinmeyen adlar genel IP'ye çözülür
Settings::set('RAFIMDA_PRODUCT_MODERATION', '0');
DB::execute("INSERT INTO rafimda_vendors (owner_user_id, store_name, store_slug, status) VALUES (1,'A','a','active'),(2,'B','b','active')");
$vA = 1; $vB = 2;
XhImporter::resetTaxonomyCache();
ok(XhImporter::lookup('category', 'Giyim (XML)') === 1, 'kategori adı (XML ekiyle) otomatik ve normalize eşleşir');
DB::execute("INSERT INTO categories (id_category, category_name, active) VALUES (99, 'Giyim', 1)");
XhImporter::resetTaxonomyCache();
ok(XhImporter::lookup('category', 'Giyim') === 0, 'aynı adlı birden fazla kategori varsa otomatik seçim yapılmaz');
DB::execute('DELETE FROM categories WHERE id_category = 99');
XhImporter::resetTaxonomyCache();
$mapping = ['fields' => ['name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'stock' => 'Qty', 'brand' => '=MarkaA', 'category' => '=Giyim', 'images' => 'Images/Img', 'description' => 'Desc']];
$mkConn = function (int $vendor, string $xml, array $opt = [], string $name = 'Tedarikçi') use ($mapping): int {
	$path = put($xml);
	$r = XhRepo::createConnection($vendor, ['name' => $name, 'source_type' => 'file', 'file_path' => $path, 'options' => array_merge(['create_new' => 1, 'update_existing' => 1, 'sync_price' => 1, 'sync_stock' => 1, 'default_category' => 1, 'default_brand' => 1], $opt)]);
	XhRepo::setConn($r['id'], ['mapping' => json_encode($mapping)]);
	return (int) $r['id'];
};
$items = [['Name' => 'Ürün Bir', 'Sku' => 'K1', 'Price' => '100', 'Qty' => 5, 'Desc' => '<p>Açık</p><script>x</script>'], ['Name' => 'Ürün İki', 'Sku' => 'K2', 'Price' => '200', 'Qty' => 0], ['Name' => 'Ürün Üç', 'Sku' => 'K3', 'Price' => '300', 'Qty' => 9]];
$cA = $mkConn($vA, feed($items));
ok($cA > 0 && XhRepo::conn($cA, $vB) === null && XhRepo::conn($cA, $vA) !== null, 'B, A\'nın bağlantısını göremez (IDOR)');
$e = XhRunner::enqueue($cA, 'import', null, $vB);
ok(!$e['success'], 'B, A\'nın bağlantısı için işlem başlatamaz');
$e = XhRunner::enqueue($cA, 'import', null, $vA);
ok($e['success'] && !XhRunner::enqueue($cA, 'import', null, $vA)['success'], 'işlem kuyruğa alındı; aynı bağlantıya ikinci eşzamanlı işlem reddedilir');
$res = XhRunner::tick(30, 1000);
ok($res['runs'] === 1 && $res['items'] === 3, 'kuyruk işlendi: 3 ürün');
$run = XhRepo::run($e['id'], $vA);
ok($run['status'] === 'done' && (int) $run['created'] === 3 && (int) $run['failed'] === 0 && (int) $run['total'] === 3, "çalıştırma tamamlandı ({$run['message']})");
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_products WHERE id_vendor = ?', [$vA]) === 3 && (int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_products WHERE id_vendor = ?', [$vB]) === 0, 'XML ürünleri doğru satıcıya (A) atandı, B\'ye hiç atanmadı');

$autoConn = $mkConn($vA, feed([['Name' => 'Ürün Bir', 'Sku' => 'SUPPLIER-CODE-1', 'Price' => '77', 'Qty' => 6]]), [], 'Otomatik ad eşleştirme');
$autoTarget = (int) DB::getValue("SELECT id_product FROM products WHERE stock_code = 'K1'");
$autoPreview = XhImporter::preview(XhRepo::conn($autoConn, $vA), ['Name' => ' ÜRÜN-BİR ', 'Sku' => 'SUPPLIER-CODE-1', 'Price' => '77', 'Qty' => 6]);
ok($autoPreview['action'] === 'update' && $autoPreview['id_product'] === $autoTarget, 'SKU farklı olsa bile tekil ürün adı otomatik eşleşir (Türkçe karakter/noktalama farkı dahil)');
$autoRun = XhRunner::enqueue($autoConn, 'import', null, $vA);
XhRunner::tick(30, 1000);
$autoResult = XhRepo::run($autoRun['id'], $vA);
ok((int) $autoResult['updated'] === 1 && (int) $autoResult['created'] === 0 && DB::getValue('SELECT price FROM products WHERE id_product = ?', [$autoTarget]) == 77, 'otomatik eşleşme mevcut ürünü günceller; manuel 10.000 satırlık işlem gerekmez');
ok(DB::getValue("SELECT description FROM products WHERE stock_code = 'K1'") === '<p>Açık</p>x' || strpos((string) DB::getValue("SELECT description FROM products WHERE stock_code = 'K1'"), '<script>') === false, 'açıklamadaki betik Product::save temizliğine bırakılır (taklitte etiket sıyrıldı)');
ok(XhRepo::runs($vB) === [] && count(XhRepo::runs($vA)) === 2 && XhRepo::run($e['id'], $vB) === null, 'çalıştırma geçmişi satıcıya göre izole');
$cB = $mkConn($vB, feed([['Name' => 'B Ürünü', 'Sku' => 'K1', 'Price' => '55', 'Qty' => 1]]));
XhRunner::enqueue($cB, 'import', null, $vB);
XhRunner::tick(30, 1000);

ok((int) DB::getValue('SELECT COUNT(*) FROM products') === 4 && DB::getValue("SELECT price FROM products p JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE vp.id_vendor = ? AND p.stock_code = 'K1'", [$vA]) == 100, 'B aynı SKU ile içe aktarınca A\'nın ürününü ezmez (B için yeni ürün)');
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_products WHERE id_vendor = ?', [$vB]) === 1, 'B\'ye ait 1 ürün');

section('Senkronizasyon, geçmiş, değişmeyen ürünler');
file_put_contents(DB::getValue('SELECT file_path FROM xh_connections WHERE id_connection = ?', [$cA]), feed([['Name' => 'Ürün Bir', 'Sku' => 'K1', 'Price' => '120', 'Qty' => 4], ['Name' => 'Ürün İki', 'Sku' => 'K2', 'Price' => '200', 'Qty' => 0], ['Name' => 'Ürün Üç', 'Sku' => 'K3', 'Price' => '300', 'Qty' => 9], ['Name' => 'Yeni Ürün', 'Sku' => 'K9', 'Price' => '50', 'Qty' => 2]]));
$sy = XhRunner::enqueue($cA, 'sync', null, $vA); XhRunner::tick(30, 1000);
$sr = XhRepo::run($sy['id'], $vA);
ok((int) $sr['updated'] === 1 && (int) $sr['unchanged'] === 2 && (int) $sr['skipped'] === 1 && (int) $sr['created'] === 0, "sync: 1 güncel, 2 değişmedi, yeni ürün eklenmedi ({$sr['message']})");
ok(DB::getValue("SELECT price FROM products WHERE stock_code = 'K1' AND id_product IN (SELECT id_product FROM rafimda_vendor_products WHERE id_vendor = 1)") == 120 && DB::getValue("SELECT stock FROM products WHERE stock_code = 'K1' AND id_product IN (SELECT id_product FROM rafimda_vendor_products WHERE id_vendor = 1)") == 4, 'fiyat ve stok güncellendi');
$pid = (int) DB::getValue("SELECT p.id_product FROM products p JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE vp.id_vendor = 1 AND p.stock_code = 'K1'");
$ph = XhRepo::history('price', $pid); $sh = XhRepo::history('stock', $pid);
ok(count($ph) === 1 && (float) $ph[0]['old_value'] == 100 && (float) $ph[0]['new_value'] == 120 && count($sh) === 1 && (float) $sh[0]['old_value'] == 5 && (float) $sh[0]['new_value'] == 4, 'fiyat ve stok değişim geçmişi yazıldı');
$sy2 = XhRunner::enqueue($cA, 'sync', null, $vA); XhRunner::tick(30, 1000);
ok((int) XhRepo::run($sy2['id'], $vA)['updated'] === 0 && count(XhRepo::history('price', $pid)) === 1, 'değişmeyen XML tekrar işlenince geçmiş/ürün değişmez');
$im = XhRunner::enqueue($cA, 'import', null, $vA); XhRunner::tick(30, 1000);
ok((int) XhRepo::run($im['id'], $vA)['created'] === 1, 'import türü yeni ürünü (K9) ekler');
ok(!XhSink::updatePriceStock($vB, $pid, 1.0, 1.0)['success'] && DB::getValue('SELECT price FROM products WHERE id_product = ?', [$pid]) == 120, 'B, A\'nın ürününün fiyat/stoğunu güncelleyemez (sahiplik kapısı)');
ok(!XhSink::updateContent($vB, $pid, 'ele geçirildi', '')['success'], 'B, A\'nın ürününün açıklamasını güncelleyemez');
ok(!XhOwner::owns($vB, $pid) && XhOwner::owns($vA, $pid) && !XhOwner::owns(0, $pid), 'sahiplik: yalnızca A; platform satıcı ürününe sahip değil');

section('Seçili içe aktarma, yinelenen, hatalı ürün, yeniden deneme');
$items2 = [['Name' => 'Seç Bir', 'Sku' => 'S1', 'Price' => '10'], ['Name' => 'Seç İki', 'Sku' => 'S2', 'Price' => '20'], ['Name' => 'Seç Bir Kopya', 'Sku' => 'S1', 'Price' => '11'], ['Name' => 'X', 'Sku' => 'S4', 'Price' => '5'], ['Name' => 'Fiyatsız Ürün', 'Sku' => 'S5', 'Price' => 'yok']];
$cS = $mkConn($vA, feed($items2), [], 'Seçmeli');
$sel = XhRunner::enqueue($cS, 'import', ['sku:S2'], $vA); XhRunner::tick(30, 1000);
ok((int) XhRepo::run($sel['id'], $vA)['created'] === 1 && DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code = 'S2'") == 1 && DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code = 'S1'") == 0, 'seçili ürün içe aktarma yalnızca seçileni ekler');
$all = XhRunner::enqueue($cS, 'import', null, $vA); XhRunner::tick(30, 1000);
$ar = XhRepo::run($all['id'], $vA);
ok((int) $ar['created'] === 1 && (int) $ar['failed'] === 2 && (int) $ar['skipped'] >= 1, "toplu: 1 yeni (S1), 2 hatalı (kısa ad, geçersiz fiyat), yinelenen atlandı ({$ar['message']})");
ok((int) DB::getValue("SELECT price FROM products WHERE stock_code = 'S1'") === 10, 'yinelenen SKU: ilk kayıt korundu');
$errs = XhRepo::errors($vA, $cS);
ok(count(array_filter($errs, fn($e) => $e['level'] === 'warning' && strpos($e['message'], 'Yinelenen') !== false)) >= 1 && count(array_filter($errs, fn($e) => $e['level'] === 'error')) >= 2, 'hatalı ürünler ve yinelenen uyarısı hata raporunda ayrı kayıtlı');
ok(XhRepo::errors($vB, $cS) === [] && XhRepo::errors($vB) !== null && count(XhRepo::errors($vB)) === 0, 'B hata raporlarında A\'nın kayıtlarını görmez');
ok(XhRepo::itemCounts($cS)['failed'] === 2, 'başarısız satırlar xh_items içinde saklı');
// X adlı ürünün adı düzeltilemez ama eşleştirme/varsayılan düzeltmesi gerektiren bir hata ekleyelim: kategori varsayılanını kaldırıp yeniden dene senaryosu
$cR = $mkConn($vA, feed([['Name' => 'Kategorisiz Ürün', 'Sku' => 'R1', 'Price' => '9']]), ['default_category' => 0], 'Yeniden');
$bm = json_decode(DB::getValue('SELECT mapping FROM xh_connections WHERE id_connection = ?', [$cR]), true); unset($bm['fields']['category']); XhRepo::setConn($cR, ['mapping' => json_encode($bm)]);
$r1 = XhRunner::enqueue($cR, 'import', null, $vA); XhRunner::tick(30, 1000);
ok((int) XhRepo::run($r1['id'], $vA)['failed'] === 1 && DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code = 'R1'") == 0, 'kategorisiz ürün hatalı (yazılmadı)');
$o = XhRepo::options(XhRepo::conn($cR, $vA)); $o['default_category'] = 1; XhRepo::setConn($cR, ['options' => json_encode($o)]);
$r2 = XhRunner::enqueue($cR, 'retry', null, $vA); XhRunner::tick(30, 1000);
$rr = XhRepo::run($r2['id'], $vA);
ok($rr['status'] === 'done' && (int) $rr['created'] === 1 && DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code = 'R1'") == 1, 'ayar düzeltildikten sonra "hatalıları yeniden dene" ürünü ekler (kaynak yeniden indirilmeden)');
ok(XhRepo::itemCounts($cR)['imported'] === 1 && empty(XhRepo::itemCounts($cR)['failed']), 'başarılı yeniden denemeden sonra hatalı satır kalmaz');
$cX = $mkConn($vA, feed([['Name' => 'Sürekli Hatalı', 'Sku' => 'Z1', 'Price' => '9']]), ['default_category' => 0], 'Hep hata');
$bm['fields']['sku'] = 'Sku'; XhRepo::setConn($cX, ['mapping' => json_encode($bm)]);
XhRunner::enqueue($cX, 'import', null, $vA); XhRunner::tick(30, 1000);
for ($i = 0; $i < 4; $i++) { $q = XhRunner::enqueue($cX, 'retry', null, $vA); if ($q['success']) { XhRunner::tick(30, 1000); } }
ok((int) DB::getValue("SELECT attempts FROM xh_items WHERE id_connection = ?", [$cX]) === XhRunner::RETRY_ITEM_MAX_ATTEMPTS, 'satır başına en fazla ' . XhRunner::RETRY_ITEM_MAX_ATTEMPTS . ' deneme (sonsuz döngü yok)');

section('Parçalı işleme ve süreç kesintisi');
$many = []; for ($i = 1; $i <= 25; $i++) { $many[] = ['Name' => "Toplu Ürün $i", 'Sku' => "M$i", 'Price' => (string) (10 + $i), 'Qty' => $i]; }
$cM = $mkConn($vA, feed($many), [], 'Büyük');
$q = XhRunner::enqueue($cM, 'import', null, $vA);
$ticks = 0; $tot = 0;
do { $r = XhRunner::tick(30, 10); $tot += $r['items']; $ticks++; $st = XhRepo::run($q['id'], $vA)['status']; } while ($st !== 'done' && $ticks < 10);
ok($st === 'done' && $ticks === 3 && $tot === 25 && DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code LIKE 'M%'") == 25, "25 ürün 10'arlı parçalarla 3 turda işlendi (tur=$ticks)");
ok((int) XhRepo::run($q['id'], $vA)['created'] === 25 && DB::getValue("SELECT COUNT(*) FROM (SELECT stock_code FROM products WHERE stock_code LIKE 'M%' GROUP BY stock_code HAVING COUNT(*) > 1)") == 0, 'parçalar arası tekrar/kayıp yok');
$many2 = $many; $many2[0]['Price'] = '999';
file_put_contents(DB::getValue('SELECT file_path FROM xh_connections WHERE id_connection = ?', [$cM]), feed($many2));
$q2 = XhRunner::enqueue($cM, 'sync', null, $vA);
XhRunner::tick(30, 7); // yarıda kes (7 ürün)
$mid = XhRepo::run($q2['id'], $vA);
ok($mid['status'] === 'running' && (int) $mid['item_offset'] === 7 && $mid['lock_token'] === '', 'süreç yarıda kesildi: ilerleme kaydedildi, kilit bırakıldı');
$k = XhRunner::tick(30, 1000);
ok(XhRepo::run($q2['id'], $vA)['status'] === 'done' && $k['items'] === 18, 'kesilen iş kaldığı yerden devam etti (kalan 18)');
// kilit
$q3 = XhRunner::enqueue($cM, 'sync', null, $vA);
DB::execute("UPDATE xh_runs SET lock_token = 'abc', locked_at = ? WHERE id_run = ?", [date('Y-m-d H:i:s'), $q3['id']]);
ok(XhRunner::tick(5, 100)['runs'] === 0, 'başka işçinin kilitlediği çalıştırma alınmaz');
DB::execute("UPDATE xh_runs SET locked_at = ? WHERE id_run = ?", [date('Y-m-d H:i:s', time() - 3600), $q3['id']]);
ok(XhRunner::tick(5, 100)['runs'] === 1, 'bayat kilit (çöken işçi) devralınır');

section('URL kaynağı: yeniden deneme / geri çekilme');
$cU = XhRepo::createConnection($vA, ['name' => 'Uzak', 'source_type' => 'url', 'url' => 'https://pub.example.com/feed.xml', 'options' => ['default_category' => 1, 'default_brand' => 1]]);
XhRepo::setConn($cU['id'], ['mapping' => json_encode($mapping)]);
ok(!XhRepo::createConnection($vA, ['name' => 'Kötü', 'source_type' => 'url', 'url' => 'http://127.0.0.1/x.xml'])['success'], 'özel adresli URL bağlantısı oluşturulamaz');
$calls = 0;
XhHttp::$transport = function (array $req) use (&$calls): array { $calls++; return ['status' => 503, 'location' => '', 'error' => '']; };
$u1 = XhRunner::enqueue($cU['id'], 'import', null, $vA);
XhRunner::tick(5, 100);
$ur = XhRepo::run($u1['id'], $vA);
ok($ur['status'] === 'queued' && (int) $ur['attempts'] === 1 && strtotime((string) $ur['next_attempt_at']) > time() + 30, '1. hata: yeniden denemeye alındı, ileri tarihli (geri çekilme)');
ok(XhRunner::tick(5, 100)['runs'] === 0 && $calls === 1, 'bekleme süresi dolmadan tekrar denenmez');
DB::execute('UPDATE xh_runs SET next_attempt_at = ? WHERE id_run = ?', [date('Y-m-d H:i:s', time() - 1), $u1['id']]); XhRunner::tick(5, 100);
$wait2 = strtotime((string) XhRepo::run($u1['id'], $vA)['next_attempt_at']) - time();
ok($wait2 > 180 && (int) XhRepo::run($u1['id'], $vA)['attempts'] === 2, "2. hata: bekleme büyüdü (~{$wait2} sn)");
DB::execute('UPDATE xh_runs SET next_attempt_at = ? WHERE id_run = ?', [date('Y-m-d H:i:s', time() - 1), $u1['id']]); XhRunner::tick(5, 100);
$ur = XhRepo::run($u1['id'], $vA); $cc = XhRepo::conn($cU['id'], $vA);
ok($ur['status'] === 'failed' && $cc['status'] === 'error' && (int) $cc['fail_count'] === 1 && strpos((string) $cc['last_error'], '503') !== false, 'üçüncü hatadan sonra kalıcı başarısız; bağlantı hata durumu ve son hata kaydı');
ok(count(XhRepo::errors($vA, $cU['id'])) >= 3, 'her deneme bağlantı hata kaydına yazıldı');
$xmlBody = feed([['Name' => 'Uzak Ürün', 'Sku' => 'U1', 'Price' => '77', 'Qty' => 3]]);
XhHttp::$transport = function (array $req) use ($xmlBody): array { ($req['sink'])($xmlBody); return ['status' => 200, 'location' => '', 'error' => '']; };
XhRepo::setConn($cU['id'], ['status' => 'draft']);
$u2 = XhRunner::enqueue($cU['id'], 'import', null, $vA); XhRunner::tick(10, 100);
ok(XhRepo::run($u2['id'], $vA)['status'] === 'done' && DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code = 'U1'") == 1 && XhRepo::conn($cU['id'], $vA)['status'] === 'active' && XhRepo::conn($cU['id'], $vA)['last_error'] === '', 'URL kaynağı başarılı: ürün eklendi, bağlantı active, hata temizlendi');
ok(glob(XhStorage::dir() . '/snap-*.xml') === [], 'anlık görüntü dosyası iş bitince silindi');
XhHttp::$transport = fn(array $req): array => ['status' => 200, 'location' => '', 'error' => ''] + (($req['sink'])('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r><p>&x;</p><p>2</p></r>') ? [] : []);
$u3 = XhRunner::enqueue($cU['id'], 'import', null, $vA); XhRunner::tick(10, 100);
$ur = XhRepo::run($u3['id'], $vA);
ok($ur['status'] === 'failed' && strpos($ur['message'], 'reddedildi') !== false && (int) $ur['attempts'] === 0, 'saldırgan XML indirildiğinde kalıcı reddedilir (yeniden denenmez)');
XhHttp::$transport = null;

section('Zamanlama');
$cT = $mkConn($vA, feed([['Name' => 'Zamanlı', 'Sku' => 'ZT', 'Price' => '5']]), [], 'Zamanlı');
XhRepo::setConn($cT, ['status' => 'active', 'interval_minutes' => 60, 'next_run_at' => date('Y-m-d H:i:s', time() - 5)]);
ok(XhRunner::scheduleDue() >= 1 && DB::getValue("SELECT COUNT(*) FROM xh_runs WHERE id_connection = ? AND kind = 'sync'", [$cT]) == 1, 'zamanı gelen bağlantı sync olarak kuyruğa alınır');
ok(XhRunner::scheduleDue() === 0, 'açık işlemi olan bağlantı tekrar kuyruğa alınmaz');
XhRunner::tick(10, 100);
$ct = XhRepo::conn($cT, $vA);
ok(strtotime((string) $ct['next_run_at']) > time() + 3000 && $ct['last_run_at'] !== null, 'bitişte bir sonraki çalışma zamanı ayarlanır (+60 dk)');
XhRepo::setConn($cT, ['status' => 'paused', 'next_run_at' => date('Y-m-d H:i:s', time() - 5)]);
ok(XhRunner::scheduleDue() === 0, 'duraklatılmış bağlantı zamanlanmaz');
ok(XhRepo::sanitizeInterval(7) === 0 && XhRepo::sanitizeInterval(360) === 360, 'yalnızca izinli aralıklar');

section('İptal, silme ve izolasyon');
$cC = $mkConn($vA, feed($many), [], 'İptal');
$cq = XhRunner::enqueue($cC, 'import', null, $vA);
ok(!XhRunner::cancel($cq['id'], $vB) && XhRunner::cancel($cq['id'], $vA), 'başka satıcı iptal edemez; sahip edebilir');
ok(!XhRepo::deleteConnection($cC, $vB) && XhRepo::deleteConnection($cC, $vA) && XhRepo::conn($cC, null) === null, 'başka satıcı silemez; sahip silebilir');
ok((int) DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code LIKE 'M%'") === 25, 'bağlantı silinince ürünler SİLİNMEZ');
$open = XhRunner::enqueue($cM, 'sync', null, $vA);
ok(!XhRepo::deleteConnection($cM, $vA), 'çalışan/bekleyen işlemi olan bağlantı silinemez');
ok(count(XhRepo::connections($vB)) === 1 && count(XhRepo::connections($vA)) >= 5, 'bağlantı listesi satıcıya göre izole');
ok(XhRepo::saveSupplier($vA, 0, 'Tedarikçi X', 'https://x.com', 'not')['success'] && XhRepo::suppliers($vB) === [] && !XhRepo::saveSupplier($vB, 1, 'Ele geçirme', '', '')['success'], 'tedarikçi kayıtları satıcıya göre izole, başkası düzenleyemez');
ok(!XhRepo::createConnection($vB, ['name' => 'x', 'source_type' => 'url', 'url' => 'https://pub.example.com/f.xml', 'id_supplier' => 1])['success'], 'başka satıcının tedarikçisine bağlantı kurulamaz');

section('Platform (yönetici) bağlantısı ve satıcı ürünlerinin korunması');
$cP = $mkConn(0, feed([['Name' => 'Ürün Bir', 'Sku' => 'K1', 'Price' => '1', 'Qty' => 1], ['Name' => 'Platform Ürünü', 'Sku' => 'PL1', 'Price' => '40', 'Qty' => 6]]), [], 'Platform');
$before = DB::execute("SELECT id_product, price FROM products WHERE stock_code = 'K1' ORDER BY id_product");
XhRunner::enqueue($cP, 'import', null, null); XhRunner::tick(10, 100);
$after = DB::execute("SELECT id_product, price FROM products WHERE stock_code = 'K1' ORDER BY id_product");
ok(count($after) === count($before) + 1 && array_slice($after, 0, count($before)) === $before, 'platform içe aktarması satıcı ürünlerini (aynı SKU) değiştirmez, yeni platform ürünü açar');
ok((int) DB::getValue("SELECT COUNT(*) FROM rafimda_vendor_products vp JOIN products p ON p.id_product = vp.id_product WHERE p.stock_code = 'PL1'") === 0, 'platform ürünü hiçbir satıcıya atanmaz');
ok(XhRepo::conn($cP, $vA) === null, 'satıcı platform bağlantısını göremez');

section('Onay (moderasyon) uyumu ve varyant/görsel');
Settings::set('RAFIMDA_PRODUCT_MODERATION', '');
$vx = [['Name' => 'Varyantlı Ürün', 'Sku' => 'V1', 'Price' => '100', 'Images' => ['http://img.example.com/a.jpg'], 'Variants' => [['Sku' => 'V1-S', 'Size' => 'S', 'Qty' => 3], ['Sku' => 'V1-M', 'Size' => 'M', 'Qty' => 4]]], ['Name' => 'Düz Ürün', 'Sku' => 'V0', 'Price' => '9']];
$cV = $mkConn($vB, feed($vx), []);
$vmap = ['fields' => $mapping['fields'] + [], 'variant_source' => 'Variants/Item', 'variant_fields' => ['sku' => 'Sku', 'size' => 'Size', 'stock' => 'Qty']];
unset($vmap['fields']['stock']); XhRepo::setConn($cV, ['mapping' => json_encode($vmap)]);
XhHttp::$transport = fn(array $req): array => ['status' => 200, 'location' => '', 'error' => ''] + (($req['sink'])('IMGDATA') ? [] : []);
$dnsBackup = XhUrlGuard::$resolver;
XhRunner::enqueue($cV, 'import', null, $vB); XhRunner::tick(10, 100);
$vp = DB::execute("SELECT p.*, vp.moderation FROM products p JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE p.stock_code = 'V1'")[0] ?? null;
ok($vp && $vp['moderation'] === 'pending' && (int) $vp['active'] === 0, 'XML ile gelen satıcı ürünü onaya düşer ve pasif başlar (RvProducts kuralı)');
ok($vp && (float) $vp['stock'] === 7.0 && (int) DB::getValue('SELECT COUNT(*) FROM product_variations WHERE id_product = ?', [$vp['id_product']]) === 2, 'varyantlar ürünle birlikte kaydedildi, ürün stoku varyant toplamı');
ok($vp && (int) DB::getValue('SELECT COUNT(*) FROM images WHERE id_product = ?', [$vp['id_product']]) === 1, 'görsel güvenli indirildi ve ürüne eklendi');
XhHttp::$transport = fn(array $req): array => ['status' => 200, 'location' => '', 'error' => ''] + (($req['sink'])('html not image') ? [] : []);
$cV2 = $mkConn($vB, feed([['Name' => 'Bozuk Görselli', 'Sku' => 'V2', 'Price' => '10', 'Images' => ['http://img.example.com/b.jpg']]]), [], 'Görsel');
XhRepo::setConn($cV2, ['mapping' => json_encode(['fields' => ['name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'images' => 'Images/Img']])]);
XhRunner::enqueue($cV2, 'import', null, $vB); XhRunner::tick(10, 100);
ok(DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code = 'V2'") == 1 && count(array_filter(XhRepo::errors($vB, $cV2), fn($e) => $e['level'] === 'warning' && strpos($e['message'], 'Görsel') !== false)) === 1, 'görsel indirilemese de ürün eklenir, uyarı olarak raporlanır');
XhUrlGuard::$resolver = $dnsBackup; XhHttp::$transport = null;
$pidV = (int) $vp['id_product'];
XhRepo::setConn($cV, ['options' => json_encode(array_merge(XhRepo::options(XhRepo::conn($cV, null)), ['sync_stock' => 1]))]);
$fv = put(feed([['Name' => 'Varyantlı Ürün', 'Sku' => 'V1', 'Price' => '100', 'Variants' => [['Sku' => 'V1-S', 'Size' => 'S', 'Qty' => 10], ['Sku' => 'V1-M', 'Size' => 'M', 'Qty' => 0]]], ['Name' => 'Düz Ürün', 'Sku' => 'V0', 'Price' => '9']]));
DB::execute('UPDATE xh_connections SET file_path = ? WHERE id_connection = ?', [$fv, $cV]);
XhRunner::enqueue($cV, 'sync', null, $vB); XhRunner::tick(10, 100);
ok((float) DB::getValue("SELECT stock FROM product_variations WHERE sku = 'V1-S'") === 10.0 && (float) DB::getValue("SELECT stock FROM product_variations WHERE sku = 'V1-M'") === 0.0, 'varyant stokları SKU ile eşleşerek senkronize edildi');


// ---------- 7. Ekran denetleyicisi: satıcı izolasyonu (IDOR) ----------
section('Ekran denetleyicisi ve izolasyon');
xh_fresh_db();
XhUrlGuard::$resolver = fn(string $h): array => $dns[$h] ?? ['93.184.216.34'];
Settings::set('RAFIMDA_PRODUCT_MODERATION', '0');
DB::execute("INSERT INTO rafimda_vendors (owner_user_id, store_name, store_slug, status) VALUES (1,'A','a','active'),(2,'B','b','active')");
$vA = 1; $vB = 2;
$cA = $mkConn($vA, feed($items), [], 'A bağlantısı');
$cB = $mkConn($vB, feed($items), [], 'B bağlantısı');
$call = function (int $vendor, string $method, array $get, array $post = []) {
	$_SERVER['REQUEST_METHOD'] = $method; $_GET = $get; $_POST = $post; $_SESSION = [];
	try { $p = new XhPanel($vendor, false, 'https://t.local/xml-merkezi'); $r = $p->handle(); return ['view' => $r[0], 'vars' => $r[1], 'redirect' => null, 'flash' => null]; }
	catch (RuntimeException $e) { return ['view' => null, 'vars' => [], 'redirect' => substr($e->getMessage(), 9), 'flash' => $_SESSION['xh_flash'] ?? null]; }
};
$r = $call($vB, 'GET', ['view' => 'conn', 'id' => $cA]);
ok($r['redirect'] !== null && $r['flash']['type'] === 'danger', 'B, A\'nın bağlantı sayfasını açamaz (listeye yönlendirilir)');
$r = $call($vA, 'GET', ['view' => 'conn', 'id' => $cA, 'tab' => 'general']);
ok($r['view'] === 'conn' && (int) $r['vars']['conn']['id_connection'] === $cA, 'A kendi bağlantısını açar');
$before = DB::getValue('SELECT mapping FROM xh_connections WHERE id_connection = ?', [$cA]);
$r = $call($vB, 'POST', [], ['xhAction' => 'conn_mapping_save', 'id_connection' => $cA, 'fields' => ['name' => 'EvilField', 'price' => 'x'], 'item_path' => 'a/b']);
ok($r['flash']['type'] === 'danger' && DB::getValue('SELECT mapping FROM xh_connections WHERE id_connection = ?', [$cA]) === $before, 'B, A\'nın eşleştirmesini değiştiremez');
$r = $call($vB, 'POST', [], ['xhAction' => 'conn_options_save', 'id_connection' => $cA, 'name' => 'Hacked', 'opt' => ['create_new' => 1]]);
ok(DB::getValue('SELECT name FROM xh_connections WHERE id_connection = ?', [$cA]) === 'A bağlantısı', 'B, A\'nın ayarlarını değiştiremez');
$r = $call($vB, 'POST', [], ['xhAction' => 'run_enqueue', 'id_connection' => $cA, 'kind' => 'import']);
ok($r['flash']['type'] === 'danger' && (int) DB::getValue('SELECT COUNT(*) FROM xh_runs WHERE id_connection = ?', [$cA]) === 0, 'B, A\'nın bağlantısında işlem başlatamaz');
$r = $call($vB, 'POST', [], ['xhAction' => 'conn_test', 'id_connection' => $cA]);
ok($r['flash']['type'] === 'danger', 'B, A\'nın bağlantısını test edemez');
$r = $call($vB, 'POST', [], ['xhAction' => 'conn_delete', 'id_connection' => $cA]);
ok(XhRepo::conn($cA, null) !== null, 'B, A\'nın bağlantısını silemez');
$r = $call($vA, 'POST', [], ['xhAction' => 'conn_test', 'id_connection' => $cA]);
ok($r['flash']['type'] === 'success' && strpos($r['flash']['message'], '3 ürün') !== false && XhRepo::conn($cA, $vA)['item_path'] === 'Catalog/Products/Product', 'A bağlantı testi: 3 ürün bulundu, ürün düğümü otomatik belirlendi');
$mp = ['xhAction' => 'conn_mapping_save', 'id_connection' => $cA, 'item_path' => 'Catalog/Products/Product', 'fields' => ['name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'stock' => 'Qty', 'brand' => '=MarkaA', 'category' => '=Giyim'], 'cat_text' => ['Giyim'], 'cat_id' => ['1']];
$r = $call($vA, 'POST', [], $mp);
ok($r['flash']['type'] === 'success' && (XhRepo::mapping(XhRepo::conn($cA, $vA))['fields']['name'] ?? '') === 'Name' && (XhRepo::mapping(XhRepo::conn($cA, $vA))['category_map']['Giyim'] ?? 0) === 1, 'A eşleştirmesini kaydeder (kategori eşlemesi dahil)');
$r = $call($vA, 'POST', [], ['xhAction' => 'conn_mapping_save', 'id_connection' => $cA, 'fields' => ['name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'stock' => 'Qty', 'brand' => '=MarkaA', 'category' => '=Giyim']]);
$reloadedMap = XhRepo::mapping(XhRepo::conn($cA, $vA));
ok($r['flash']['type'] === 'success' && ($reloadedMap['category_map']['Giyim'] ?? 0) === 1, 'alan eşleştirmesi yeniden kaydedilince önceki kategori eşlemesi silinmez');
$r = $call($vA, 'GET', ['view' => 'conn', 'id' => $cA, 'tab' => 'preview']);
ok(count($r['vars']['rows']) === 3 && $r['vars']['rows'][0]['action'] === 'create', 'önizleme 3 ürünü "eklenecek" olarak gösterir (yazmadan)');
ok((int) DB::getValue('SELECT COUNT(*) FROM products') === 0, 'önizleme veritabanına ürün yazmadı');
$r = $call($vA, 'GET', ['view' => 'conn', 'id' => $cA, 'tab' => 'mapping']);
ok(isset($r['vars']['suggest']) && in_array('Name', $r['vars']['keys'], true) && $r['vars']['distinctCats'] === [] , 'eşleştirme ekranı kaynak alanları ve önerileri listeler');
$r = $call($vA, 'POST', [], ['xhAction' => 'run_enqueue', 'id_connection' => $cA, 'kind' => 'import', 'selected_only' => '1', 'sel' => ['sku:K1']]);
ok($r['flash']['type'] === 'success' && DB::getValue('SELECT selected FROM xh_runs WHERE id_connection = ?', [$cA]) === '["sku:K1"]', 'seçili ürün içe aktarma kuyruğa alındı');
$r = $call($vB, 'POST', [], ['xhAction' => 'queue_tick']);
ok((int) DB::getValue('SELECT COUNT(*) FROM products') === 0, 'B\'nin "kuyruğu işle" düğmesi A\'nın işlemini çalıştırmaz');
$r = $call($vA, 'POST', [], ['xhAction' => 'queue_tick']);
ok((int) DB::getValue('SELECT COUNT(*) FROM products') === 1 && DB::getValue('SELECT stock_code FROM products') === 'K1', 'A\'nın "kuyruğu işle" düğmesi yalnızca seçili ürünü ekledi');
$r = $call($vB, 'GET', ['view' => 'runs']);
ok($r['vars']['runs'] === [] && $call($vB, 'GET', ['view' => 'errors'])['vars']['errors'] === [], 'B geçmiş ve hata ekranlarında A\'nın kayıtlarını görmez');
$runA = (int) DB::getValue('SELECT id_run FROM xh_runs WHERE id_connection = ?', [$cA]);
$r = $call($vB, 'POST', [], ['xhAction' => 'run_cancel', 'id_run' => $runA]);
ok($r['flash']['type'] === 'danger', 'B, A\'nın işlemini iptal edemez (bitmiş zaten; yetkisiz de)');
$r = $call($vA, 'POST', [], ['xhAction' => 'conn_create', 'name' => 'Metadata', 'source_type' => 'url', 'url' => 'http://169.254.169.254/latest/meta-data/']);
ok($r['flash']['type'] === 'danger' && (strpos($r['flash']['message'], 'özel') !== false || strpos($r['flash']['message'], 'engellen') !== false) && (int) DB::getValue("SELECT COUNT(*) FROM xh_connections WHERE name = 'Metadata'") === 0, 'bulut metadata adresiyle bağlantı oluşturma engellenir (kayıt açılmaz)');
$r = $call($vA, 'POST', [], ['xhAction' => 'conn_create', 'name' => 'Uzak XML', 'source_type' => 'url', 'url' => 'https://pub.example.com/feed.xml', 'interval_minutes' => '60', 'opt' => ['create_new' => '0', 'sync_stock' => '1']]);
$new = XhRepo::connections($vA)[0];
$o = XhRepo::options($new);
ok($new['name'] === 'Uzak XML' && (int) $new['interval_minutes'] === 60 && $o['create_new'] === 0 && $o['sync_stock'] === 1 && $o['update_existing'] === 1, 'form seçenekleri kaydedilir (işaretsiz kutu = kapalı, belirtilmeyen = varsayılan)');
$sv = $call($vA, 'POST', [], ['xhAction' => 'supplier_save', 'name' => 'Tedarikçi Z', 'website' => 'https://z.com']);
$sid = (int) DB::getValue('SELECT id_supplier FROM xh_suppliers WHERE id_vendor = 1');
$r = $call($vB, 'POST', [], ['xhAction' => 'supplier_save', 'id_supplier' => $sid, 'name' => 'Ele geçirildi']);
ok($sid > 0 && DB::getValue('SELECT name FROM xh_suppliers WHERE id_supplier = ?', [$sid]) === 'Tedarikçi Z' && $call($vB, 'GET', ['view' => 'suppliers'])['vars']['suppliers'] === [], 'tedarikçi kaydı izole; B değiştiremez/göremez');
$adm = function (string $method, array $get, array $post = []) { $_SERVER['REQUEST_METHOD'] = $method; $_GET = $get; $_POST = $post; $_SESSION = []; try { $p = new XhPanel(0, true, ''); $r = $p->handle(); return $r; } catch (RuntimeException $e) { return [null, ['flash' => $_SESSION['xh_flash'] ?? null]]; } };
$ar = $adm('GET', ['view' => 'list']);
ok(count($ar[1]['conns']) === count(DB::execute('SELECT 1 FROM xh_connections')), 'yönetici tüm bağlantıları görür');
$ar = $adm('POST', [], ['xhAction' => 'conn_create', 'name' => 'Platform XML', 'source_type' => 'url', 'url' => 'https://pub.example.com/p.xml']);
ok((int) DB::getValue("SELECT id_vendor FROM xh_connections WHERE name = 'Platform XML'") === 0, 'yönetici bağlantısı platforma (id_vendor=0) ait oluşur');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = []; $_POST = []; $_SESSION = [];

// ---------- 8. Şablonlar ----------
section('Şablonlar (gerçek Smarty 5)');
$tplDir = dirname(__DIR__) . '/assets/templates/';
foreach (['front/xh_content.tpl', 'front/xh_options.tpl', 'front/xh_front.tpl', 'admin/admin.tpl'] as $t) {
	$src = file_get_contents($tplDir . $t);
	preg_match_all('/\{\$[^{}]*\}/', $src, $m);
	$bad = array_filter($m[0], fn($o) => !preg_match('/\|\s*(escape|string_format|count)\b/', $o) && !preg_match('/\{\$xh(Tpl)?Dir\}/', $o));
	ok($bad === [], "$t: her {\$değişken} çıktısı |escape/sayısal biçimli" . ($bad ? ' → ' . implode(' ', array_slice($bad, 0, 4)) : ''));
	ok(strpos($src, 'nofilter') === false, "$t: nofilter kullanılmıyor");
}
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$smarty = new Smarty\Smarty();
$smarty->setCompileDir(sys_get_temp_dir() . '/xh_compile_' . getmypid());
@mkdir($smarty->getCompileDir(), 0777, true);
$xss = '<script>alert(1)</script>';
$conn = ['id_connection' => 5, 'id_vendor' => 1, 'name' => $xss, 'source_type' => 'url', 'url' => $xss, 'item_path' => $xss, 'status' => 'active', 'interval_minutes' => 60, 'last_error' => $xss, 'last_run_at' => 'd', 'id_supplier' => 0, 'supplier_name' => $xss];
$base = ['xhFlash' => ['type' => 'danger', 'message' => $xss], 'xhToken' => $xss, 'xhCanManage' => true, 'xhTplDir' => $tplDir . 'front/', 'xhCss' => '/x.css', 'xhJs' => '/x.js', 'xhAdmin' => true, 'xhBase' => '/b', 'xhTitles' => ['list' => 'Bağlantılarım', 'new' => 'Yeni'], 'categories' => [['id_category' => 1, 'category_name' => $xss]], 'brands' => [['id_brand' => 1, 'brand_name' => $xss]], 'intervals' => [0 => 'Elle', 60 => 'Saat'], 'opt' => XhRepo::DEFAULT_OPTIONS, 'suppliers' => [['id_supplier' => 1, 'name' => $xss, 'website' => $xss, 'notes' => $xss]], 'adminToken' => 't', 'xhPending' => [], 'xhCronUrl' => 'https://x/xml-cron?key=' . $xss, 'panelUrl' => '/p'];
$runRow = ['id_run' => 1, 'conn_name' => $xss, 'kind' => 'import', 'status' => 'queued', 'attempts' => 1, 'total' => 10, 'item_offset' => 5, 'message' => $xss, 'date_add' => 'd'];
$prev = ['key' => 'sku:1', 'name' => $xss, 'sku' => $xss, 'price' => 10.0, 'old_price' => 12.0, 'stock' => 3.0, 'variants' => [1], 'images' => [1, 2], 'errors' => [$xss], 'action' => 'create'];
$views = [
'changes' => ['changes' => [['date_add' => 'd', 'kind' => $xss, 'id_product' => 1, 'product_name' => $xss, 'conn_name' => $xss, 'detail' => $xss]]],
	'list' => ['conns' => [$conn], 'queue' => 1], 'new' => [], 'suppliers' => ['edit' => ['id_supplier' => 1, 'name' => $xss, 'website' => '', 'notes' => '']], 'runs' => ['runs' => [$runRow]], 'errors' => ['errors' => [['date_add' => 'd', 'conn_name' => $xss, 'item_key' => $xss, 'level' => 'error', 'message' => $xss]]],
	'conn' => ['conn' => $conn, 'tab' => 'general', 'mapping' => [], 'counts' => ['failed' => 2], 'targets' => XhMapper::TARGETS, 'vtargets' => XhMapper::VARIANT_TARGETS, 'sampleOk' => true, 'keys' => [$xss], 'rows' => [], 'distinctCats' => [], 'distinctBrands' => [], 'hasAuth' => true, 'unmapped' => ['brand' => [['text' => $xss, 'enc' => 'x', 'n' => 2]], 'category' => [['text' => $xss, 'enc' => 'y', 'n' => 1]]], 'mapBrand' => [$xss => 1], 'mapCategory' => [$xss => 2], 'opt' => XhRepo::DEFAULT_OPTIONS],
];
foreach (['mapping', 'maps', 'preview', 'history', 'errors'] as $tab) { $views["conn:$tab"] = $views['conn']; $views["conn:$tab"]['tab'] = $tab; }
$views['conn:mapping'] += ['suggest' => ['fields' => ['name' => $xss], 'variant_source' => $xss, 'variant_fields' => ['sku' => $xss]], 'recordKeys' => [$xss], 'distinctCats' => [$xss], 'distinctBrands' => [$xss]];
$views['conn:mapping']['mapping'] = ['fields' => ['name' => $xss], 'category_map' => [$xss => 1]];
$views['conn:preview']['rows'] = [$prev];
$views['conn:history']['runs'] = [$runRow];
$views['conn:errors']['errors'] = [['date_add' => 'd', 'item_key' => $xss, 'level' => 'warning', 'message' => $xss]];
foreach ([false, true] as $auto) {
	foreach ($views as $name => $vars) {
		$smarty->setEscapeHtml($auto);
		$v = explode(':', $name)[0];
		$smarty->assign(array_merge($base, $vars, ['view' => $v]));
		try { $html = $smarty->fetch('file:' . $tplDir . 'front/xh_content.tpl'); $okR = true; } catch (Throwable $e) { $html = $e->getMessage(); $okR = false; }
		ok($okR && strpos($html, '<script>alert(1)</script>') === false && strlen($html) > 150 && strpos($html, '&amp;lt;') === false, "görünüm '$name' render, XSS kaçışı, çift kaçış yok (otomatik kaçış " . ($auto ? 'açık' : 'kapalı') . ')' . ($okR ? '' : " ($html)"));
	}
}
$smarty->setEscapeHtml(false);
$smarty->assign(array_merge($base, $views['list'], ['view' => 'list']));
$html = $smarty->fetch('file:' . $tplDir . 'admin/admin.tpl');
ok(strpos($html, '<script>alert(1)</script>') === false && strpos($html, 'xml-cron') !== false, 'admin şablonu render (cron adresi gösterilir, XSS kaçışı)');
$smarty->assign(array_merge($base, ['xhPending' => ['001_init']]));
ok(strpos($smarty->fetch('file:' . $tplDir . 'admin/admin.tpl'), 'Bekleyen migration') !== false, 'bekleyen migration uyarısı gösterilir');
$smarty->assign(array_merge($base, $views['list'], ['view' => 'list', 'xhCanManage' => false]));
ok(strpos($smarty->fetch('file:' . $tplDir . 'front/xh_content.tpl'), 'name="xhAction"') === false, 'yetkisiz (salt okunur) kullanıcıya işlem formları gösterilmez');
ok(strpos(file_get_contents(dirname(__DIR__) . '/assets/css/xh.css'), '@media (max-width:760px)') !== false, 'CSS mobil kırılım noktası içeriyor (görsel olarak test EDİLMEDİ)');

section('Büyük dosya (akış, bellek)');
$bigPath = XhStorage::newPath('large'); $fh = fopen($bigPath, 'wb'); fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?><Catalog><Products>');
for ($i = 1; $i <= 30000; $i++) { fwrite($fh, "<Product><Name>Büyük Ürün $i</Name><Sku>BG$i</Sku><Price>" . ($i % 500 + 1) . ",50</Price><Qty>" . ($i % 20) . "</Qty><Desc>Açıklama metni $i lorem ipsum dolor sit amet</Desc><Images><Img>http://a.com/$i.jpg</Img></Images></Product>"); }
fwrite($fh, '</Products></Catalog>'); fclose($fh);
$mb = round(filesize($bigPath) / 1048576, 1);
$m0 = memory_get_usage(); $t0 = microtime(true);
$vv = XhXml::validate($bigPath); $cnt = XhXml::countItems($bigPath, 'Catalog/Products/Product');
$last = null; $n = 0; foreach (XhXml::stream($bigPath, 'Catalog/Products/Product', 29990) as $i => $r) { $last = $r; $n++; }
$peak = (memory_get_peak_usage() - $m0) / 1048576; $dt = round(microtime(true) - $t0, 2);
ok($vv['ok'] && $cnt === 30000 && $n === 10 && $last['Sku'] === 'BG30000', "30.000 ürünlü {$mb} MB XML: doğrulama+sayım+ofsetli okuma doğru ({$dt} sn)");
ok($peak < 40, 'bellek kullanımı dosya boyutundan bağımsız, sabit (+' . round($peak, 1) . ' MB)');
$t0 = microtime(true);
$cBig = XhRepo::createConnection($vA, ['name' => 'Dev feed', 'source_type' => 'file', 'file_path' => $bigPath, 'options' => ['default_category' => 1, 'default_brand' => 1]]);
XhRepo::setConn($cBig['id'], ['mapping' => json_encode(['fields' => ['name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'stock' => 'Qty']])]);
$qb = XhRunner::enqueue($cBig['id'], 'import', null, $vA);
XhRunner::tick(60, 1500); XhRunner::tick(60, 1500);
$rb = XhRepo::run($qb['id'], $vA);
$dt = round(microtime(true) - $t0, 1);
ok($rb['status'] === 'running' && (int) $rb['item_offset'] === 3000 && (int) $rb['total'] === 30000 && (int) $rb['created'] === 3000 && (int) DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code LIKE 'BG%'") === 3000, "30.000 ürünlü kuyruk 1.500'lük turlarla ilerliyor: 2 turda 3.000 ürün ($dt sn, SQLite+taklit Product; toplam tahmini " . round($dt * 10 / 60, 1) . " dk)");
XhRepo::setConn($cBig['id'], ['status' => 'paused']);
XhRunner::tick(60, 1500);
$pausedRun = XhRepo::run($qb['id'], $vA);
ok((int) $pausedRun['item_offset'] === 3000 && $pausedRun['status'] === 'running', 'bağlantı duraklatılınca cron yeni ürün işlemeyi durdurur ve ilerleme korunur');
XhRepo::setConn($cBig['id'], ['status' => 'active']);
XhRunner::tick(60, 1500);
$resumedRun = XhRepo::run($qb['id'], $vA);
ok((int) $resumedRun['item_offset'] === 4500, 'bağlantı yeniden aktifleştirilince kaldığı yerden devam eder');
ok(XhRunner::cancel($qb['id'], $vA) && XhRepo::run($qb['id'], $vA)['status'] === 'cancelled', 'uzun işlem iptal edilebilir');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
foreach ($failures as $f) { echo " BAŞARISIZ: $f\n"; }
exit($fail ? 1 : 0);
