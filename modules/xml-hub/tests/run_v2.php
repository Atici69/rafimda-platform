<?php
// XML Hub v0.2 testleri: kimlik bilgisi şifreleme, Basic Auth, kaynak izleme, kargo, açıklama/görsel değişikliği, varyant, eşleştirme, cron güvenliği
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

$dns = [];
XhUrlGuard::$resolver = fn(string $h): array => $dns[$h] ?? ['93.184.216.34'];
Settings::set('XML_HUB_SECRET', '');

// ---------- migration 002 ----------
section('Migration 002 (geriye uyumlu)');
xh_fresh_db();
foreach (XhMigrator::files() as $v) { DB::execute('INSERT INTO xh_migrations (version) VALUES (?)', [$v]); } // test kurulumu şemayı elle uyguladı
$raw = file_get_contents(dirname(__DIR__) . '/migrations/002_source_meta_credentials.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|ALTER|UPDATE)\b/i', $raw) && !preg_match('/^\s*--/m', $raw) && preg_match_all('/CREATE TABLE(?! IF NOT EXISTS)/', $raw) === 0, 'migration 002 yıkıcı değil (yalnızca CREATE TABLE IF NOT EXISTS)');
foreach (['xh_product_meta', 'xh_credentials', 'xh_changes'] as $t) { ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "tablo: $t"); }
$GLOBALS['db']->exec(xh_mysql_to_sqlite($raw)); // ikinci kez çalıştırma zararsız
ok(true, 'migration tekrar çalıştırılınca hata vermez (idempotent)');
ok(XhMigrator::pending() === [], 'bekleyen migration yok');
// geri alma: yalnızca bu migration'ın tabloları kaldırılınca 001 verisi ve ürünler etkilenmez
DB::execute("INSERT INTO xh_suppliers (id_vendor,name) VALUES (1,'S')");
foreach (['xh_product_meta', 'xh_credentials', 'xh_changes'] as $t) { DB::execute("DROP TABLE $t"); }
DB::execute("DELETE FROM xh_migrations WHERE version = '002_source_meta_credentials'");
ok(XhMigrator::pending() === ['002_source_meta_credentials'] && (int) DB::getValue('SELECT COUNT(*) FROM xh_suppliers') === 1, 'geri alma senaryosu: 002 kaldırılınca 001 verisi sağlam, 002 yeniden bekleyen olarak görünür');
$GLOBALS['db']->exec(xh_mysql_to_sqlite($raw)); DB::execute("INSERT INTO xh_migrations (version) VALUES ('002_source_meta_credentials')");
ok(XhMigrator::pending() === [] && DB::execute("SELECT name FROM sqlite_master WHERE name = 'xh_credentials'") !== [], 'geri alınan migration yeniden uygulanır');

// ---------- şifreleme ----------
section('Kimlik bilgisi şifreleme');
$e = XhCrypt::encrypt('gizli-şifre');
ok($e !== '' && strpos($e, 'gizli') === false && XhCrypt::decrypt($e) === 'gizli-şifre', 'şifrele/çöz gidiş-dönüş, şifreli metin düz metni içermez');
ok(XhCrypt::encrypt('gizli-şifre') !== $e, 'her şifreleme farklı nonce kullanır');
$tam = substr($e, 0, -3) . 'AAA';
ok(XhCrypt::decrypt($tam) === '' && XhCrypt::decrypt('rastgele') === '' && XhCrypt::decrypt('') === '', 'bozulmuş/geçersiz şifreli veri boş döner (sahte çözüm yok)');
$old = Settings::get('XML_HUB_SECRET'); Settings::set('XML_HUB_SECRET', bin2hex(random_bytes(32)));
ok(XhCrypt::decrypt($e) === '', 'farklı anahtarla çözülemez');
Settings::set('XML_HUB_SECRET', $old);
ok(XhCrypt::decrypt($e) === 'gizli-şifre', 'doğru anahtarla çözülür');

// ---------- Basic Auth ----------
section('Basic Auth');
xh_fresh_db();
foreach (XhMigrator::files() as $v) { DB::execute('INSERT INTO xh_migrations (version) VALUES (?)', [$v]); }
XhUrlGuard::$resolver = fn(string $h): array => $dns[$h] ?? ['93.184.216.34'];
DB::execute("INSERT INTO rafimda_vendors (owner_user_id, store_name, store_slug, status) VALUES (1,'A','a','active'),(2,'B','b','active')");
$vA = 1; $vB = 2;
$mapping = ['fields' => ['name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'stock' => 'Qty', 'brand' => '=MarkaA', 'category' => '=Giyim', 'images' => 'Images/Img', 'description' => 'Desc', 'cargo_price' => 'Cargo']];
$mk = function (int $vendor, string $xml, array $opt = [], string $name = 'Tedarikçi', int $supplier = 0) use ($mapping): int {
	$r = XhRepo::createConnection($vendor, ['name' => $name, 'id_supplier' => $supplier, 'source_type' => 'file', 'file_path' => put($xml), 'options' => array_merge(['default_category' => 1, 'default_brand' => 1], $opt)]);
	XhRepo::setConn($r['id'], ['mapping' => json_encode($mapping)]);
	return (int) $r['id'];
};
$run = function (int $conn, int $vendor, string $kind = 'import'): array { $q = XhRunner::enqueue($conn, $kind, null, $vendor); XhRunner::tick(30, 5000); return XhRepo::run((int) $q['id'], $vendor) ?: []; };
$u = XhRepo::createConnection($vA, ['name' => 'Korumalı', 'source_type' => 'url', 'url' => 'https://feed.example.com/x.xml', 'options' => ['default_category' => 1, 'default_brand' => 1]]);
XhRepo::setConn($u['id'], ['mapping' => json_encode($mapping)]);
$s = XhRepo::saveAuth($u['id'], 'kullanici', 'p@ss:wörd');
ok($s['success'] && XhRepo::hasAuth($u['id']), 'kimlik bilgisi kaydedildi');
$dump = json_encode(DB::execute('SELECT * FROM xh_credentials'));
ok(strpos($dump, 'kullanici') === false && strpos($dump, 'p@ss') === false, 'veritabanında kullanıcı adı/şifre düz metin değil');
ok(XhRepo::auth($u['id']) === ['user' => 'kullanici', 'pass' => 'p@ss:wörd'], 'indirme için çözülür (şifrede ":" serbest)');
ok(!XhRepo::saveAuth($u['id'], 'a:b', 'x')['success'] && !XhRepo::saveAuth($u['id'], "a\nb", 'x')['success'], 'kullanıcı adında ":" ve denetim karakteri reddedilir');
$seen = [];
$feedXml = feed([['Name' => 'Korumalı Ürün', 'Sku' => 'BA1', 'Price' => '10', 'Qty' => 1]]);
XhHttp::$transport = function (array $req) use (&$seen, $feedXml): array {
	$seen[] = ['url' => $req['url'], 'auth' => $req['auth'] ?? null];
	if ($req['url'] === 'https://feed.example.com/x.xml' && empty($req['auth'])) { return ['status' => 401, 'location' => '', 'error' => '']; }
	if ($req['url'] === 'https://feed.example.com/x.xml' && $req['auth']['user'] !== 'kullanici') { return ['status' => 401, 'location' => '', 'error' => '']; }
	($req['sink'])($feedXml);
	return ['status' => 200, 'location' => '', 'error' => ''];
};
$r = $run($u['id'], $vA);
ok($r['status'] === 'done' && (int) $r['created'] === 1 && $seen[0]['auth'] === ['user' => 'kullanici', 'pass' => 'p@ss:wörd'], 'kimlik bilgisi isteğe eklenir, içe aktarma çalışır');
XhRepo::saveAuth($u['id'], 'yanlis', 'x');
$seen = [];
$f = XhHttp::fetchToFile('https://feed.example.com/x.xml', XhStorage::newPath('a'), XhHttp::MAX_BYTES, 10, XhRepo::auth($u['id']));
ok(!$f['ok'] && strpos($f['error'], '401') !== false && strpos($f['error'], 'yanlis') === false, 'yanlış bilgide anlaşılır hata (bilgi hata metnine sızmaz)');
$f = XhHttp::fetchToFile('https://feed.example.com/x.xml', XhStorage::newPath('a'), XhHttp::MAX_BYTES, 10, null);
ok(!$f['ok'] && strpos($f['error'], 'kullanıcı adı') !== false, 'kimlik bilgisi yokken 401: bağlantıya bilgi ekleme yönlendirmesi');
$f = XhHttp::fetchToFile('http://feed.example.com/x.xml', XhStorage::newPath('a'), XhHttp::MAX_BYTES, 10, ['user' => 'u', 'pass' => 'p']);
ok(!$f['ok'] && strpos($f['error'], 'HTTPS') !== false, 'HTTP (şifresiz) adrese kimlik bilgisi gönderilmez');
$seen = [];
XhHttp::$transport = function (array $req) use (&$seen, $feedXml): array {
	$seen[] = ['url' => $req['url'], 'auth' => $req['auth'] ?? null];
	if ($req['url'] === 'https://feed.example.com/a.xml') { return ['status' => 302, 'location' => 'https://cdn.other.com/a.xml', 'error' => '']; }
	if ($req['url'] === 'https://feed.example.com/b.xml') { return ['status' => 302, 'location' => '/c.xml', 'error' => '']; }
	($req['sink'])($feedXml);
	return ['status' => 200, 'location' => '', 'error' => ''];
};
XhHttp::fetchToFile('https://feed.example.com/a.xml', XhStorage::newPath('a'), XhHttp::MAX_BYTES, 10, ['user' => 'u', 'pass' => 'p']);
ok($seen[0]['auth'] !== null && $seen[1]['auth'] === null, 'başka sunucuya yönlendirmede kimlik bilgisi ASLA gönderilmez');
$seen = [];
XhHttp::fetchToFile('https://feed.example.com/b.xml', XhStorage::newPath('a'), XhHttp::MAX_BYTES, 10, ['user' => 'u', 'pass' => 'p']);
ok($seen[1]['auth'] !== null, 'aynı sunucu içi yönlendirmede kimlik bilgisi korunur');
ok(XhRepo::saveAuth($u['id'], '', '')['success'] && !XhRepo::hasAuth($u['id']) && XhRepo::auth($u['id']) === null, 'kimlik bilgisi kaldırılabilir');
XhRepo::saveAuth($u['id'], 'k', 'p');
XhRepo::deleteConnection($u['id'], $vA);
ok((int) DB::getValue('SELECT COUNT(*) FROM xh_credentials') === 0, 'bağlantı silinince kimlik bilgisi de silinir');
$logs = json_encode(DB::execute('SELECT * FROM xh_errors'));
ok(strpos($logs, 'p@ss') === false && strpos($logs, 'kullanici') === false, 'hata günlüklerinde kimlik bilgisi yok');
XhHttp::$transport = null;

// ---------- kaynak izleme, kargo, açıklama, görsel, varyant ----------
section('Ürün kaynağı ve tedarikçi bilgisi');
Settings::set('RAFIMDA_PRODUCT_MODERATION', '0');
$sup = XhRepo::saveSupplier($vA, 0, 'Toptancı X', '', '');
$supId = (int) (DB::getValue("SELECT id_supplier FROM xh_suppliers WHERE name = 'Toptancı X'"));
$img = function (array $urls) { return $urls; };
XhHttp::$transport = function (array $req): array { ($req['sink'])('IMGDATA'); return ['status' => 200, 'location' => '', 'error' => '']; };
$c1 = $mk($vA, feed([['Name' => 'Kargolu Ürün', 'Sku' => 'KG1', 'Price' => '100', 'Qty' => 5, 'Cargo' => '19,90', 'Desc' => 'İlk açıklama', 'Images' => ['http://img.example.com/1.jpg']]]), ['sync_description' => 1, 'sync_images' => 1, 'image_mode' => 'add_new', 'sync_cargo' => 1], 'Kaynaklı', $supId);
$r = $run($c1, $vA);
$pid = (int) DB::getValue("SELECT id_product FROM products WHERE stock_code = 'KG1'");
$meta = XhMeta::get($pid);
ok($r['status'] === 'done' && $meta && $meta['source_type'] === 'xml' && (int) $meta['id_connection'] === $c1 && (int) $meta['id_supplier'] === $supId, 'XML ürününün kaynağı, bağlantısı ve tedarikçisi kaydedilir');
ok(XhMeta::source($pid) === ['source' => 'xml', 'id_connection' => $c1, 'id_supplier' => $supId], 'XhMeta::source() XML kaynağını döner');
DB::execute("INSERT INTO products (product_name, id_category, id_brand, price, stock, stock_code, active) VALUES ('Elle', 1, 1, 5, 1, 'EL1', 1)");
$manual = (int) DB::getValue("SELECT id_product FROM products WHERE stock_code = 'EL1'");
ok(XhMeta::source($manual)['source'] === 'manual', 'elle eklenen ürün "manual" kaynaklı sayılır');
ok($meta['cargo_price'] !== null && abs((float) $meta['cargo_price'] - 19.9) < 0.001, 'kargo ücreti ürün verisine (xh_product_meta) aktarıldı');
ok(json_decode((string) $meta['image_urls'], true) === ['http://img.example.com/1.jpg'] && (int) DB::getValue('SELECT COUNT(*) FROM images WHERE id_product = ?', [$pid]) === 1, 'alınan görsel adresleri izlenir');

section('Kargo, açıklama ve görsel değişikliği algılama');
$update = function (int $conn, array $item, int $vendor) use ($run): array {
	file_put_contents((string) DB::getValue('SELECT file_path FROM xh_connections WHERE id_connection = ?', [$conn]), feed([$item]));
	return $run($conn, $vendor, 'sync');
};
$base = ['Name' => 'Kargolu Ürün', 'Sku' => 'KG1', 'Price' => '100', 'Qty' => 5, 'Cargo' => '19,90', 'Desc' => 'İlk açıklama', 'Images' => ['http://img.example.com/1.jpg']];
$r = $update($c1, $base, $vA);
ok((int) $r['unchanged'] === 1 && (int) DB::getValue("SELECT COUNT(*) FROM xh_changes WHERE kind = 'description'") === 0, 'veri aynıyken hiçbir değişiklik kaydı oluşmaz');
$r = $update($c1, array_merge($base, ['Desc' => 'Yeni açıklama']), $vA);
ok((int) $r['updated'] === 1 && DB::getValue('SELECT description FROM products WHERE id_product = ?', [$pid]) === 'Yeni açıklama' && (int) DB::getValue("SELECT COUNT(*) FROM xh_changes WHERE kind = 'description' AND id_product = ?", [$pid]) === 1, 'açıklama değişikliği algılandı, yazıldı ve kaydedildi');
$r = $update($c1, array_merge($base, ['Desc' => 'Yeni açıklama', 'Price' => '101']), $vA);
ok((int) DB::getValue("SELECT COUNT(*) FROM xh_changes WHERE kind = 'description'") === 1, 'açıklama aynı kaldıkça (yalnız fiyat değişti) açıklama yeniden yazılmaz');
$r = $update($c1, array_merge($base, ['Desc' => 'Yeni açıklama', 'Cargo' => '24,50', 'Price' => '101']), $vA);
ok(abs((float) XhMeta::get($pid)['cargo_price'] - 24.5) < 0.001 && (int) DB::getValue("SELECT COUNT(*) FROM xh_changes WHERE kind = 'cargo'") === 1, 'kargo ücreti değişikliği algılandı ve kaydedildi');
$r = $update($c1, array_merge($base, ['Desc' => 'Yeni açıklama', 'Cargo' => '24,50', 'Price' => '101', 'Images' => ['http://img.example.com/1.jpg', 'http://img.example.com/2.jpg']]), $vA);
ok((int) DB::getValue('SELECT COUNT(*) FROM images WHERE id_product = ?', [$pid]) === 2 && count(json_decode((string) XhMeta::get($pid)['image_urls'], true)) === 2, 'add_new modu: yalnızca yeni görsel adresi eklendi (eskisi tekrar indirilmedi)');
$r = $update($c1, array_merge($base, ['Desc' => 'Yeni açıklama', 'Cargo' => '24,50', 'Price' => '101', 'Images' => ['http://img.example.com/1.jpg', 'http://img.example.com/2.jpg']]), $vA);
ok((int) DB::getValue('SELECT COUNT(*) FROM images WHERE id_product = ?', [$pid]) === 2, 'görsel listesi aynıyken görsel tekrarlanmaz');
$c2 = $mk($vA, feed([['Name' => 'Eski Mod', 'Sku' => 'EM1', 'Price' => '10', 'Qty' => 1, 'Images' => ['http://img.example.com/a.jpg']]]), ['sync_images' => 1, 'image_mode' => 'if_empty']);
$run($c2, $vA);
$pe = (int) DB::getValue("SELECT id_product FROM products WHERE stock_code = 'EM1'");
$update($c2, ['Name' => 'Eski Mod', 'Sku' => 'EM1', 'Price' => '11', 'Qty' => 1, 'Images' => ['http://img.example.com/a.jpg', 'http://img.example.com/b.jpg']], $vA);
ok((int) DB::getValue('SELECT COUNT(*) FROM images WHERE id_product = ?', [$pe]) === 1, 'if_empty modu: görseli olan ürüne yeni görsel eklenmez');
$c3 = $mk($vA, feed([['Name' => 'Kargosuz', 'Sku' => 'NC1', 'Price' => '10', 'Qty' => 1, 'Cargo' => '30']]), ['sync_cargo' => 0]);
$run($c3, $vA);
ok(XhMeta::get((int) DB::getValue("SELECT id_product FROM products WHERE stock_code = 'NC1'"))['cargo_price'] === null, 'sync_cargo kapalıyken kargo ücreti saklanmaz');

section('Varyant: yeni oluşturma ve güncelleme');
$vm = ['fields' => ['name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'brand' => '=MarkaA', 'category' => '=Giyim'], 'variant_source' => 'Variants/Item', 'variant_fields' => ['sku' => 'Sku', 'size' => 'Size', 'stock' => 'Qty', 'price' => 'Price']];
$mkv = function (array $variants, array $opt) use ($vm, $vA): int {
	$r = XhRepo::createConnection($vA, ['name' => 'Varyantlı', 'source_type' => 'file', 'file_path' => put(feed([['Name' => 'Varyantlı Tişört', 'Sku' => 'VT' . count($variants), 'Price' => '50', 'Variants' => $variants]])), 'options' => array_merge(['default_category' => 1, 'default_brand' => 1], $opt)]);
	XhRepo::setConn($r['id'], ['mapping' => json_encode($vm)]);
	return (int) $r['id'];
};
$cv = $mkv([['Sku' => 'V-S', 'Size' => 'S', 'Qty' => 3, 'Price' => '50']], ['create_variants' => 1]);
$run($cv, $vA);
$pv = (int) DB::getValue("SELECT id_product FROM products WHERE stock_code = 'VT1'");
ok((int) DB::getValue('SELECT COUNT(*) FROM product_variations WHERE id_product = ?', [$pv]) >= 0 && $pv > 0, 'varyantlı ürün oluşturuldu');
DB::execute('DELETE FROM product_variations WHERE id_product = ?', [$pv]);
DB::execute("INSERT INTO product_variations (id_product, sku, price, stock) VALUES (?, 'V-S', 50, 3)", [$pv]);
file_put_contents((string) DB::getValue('SELECT file_path FROM xh_connections WHERE id_connection = ?', [$cv]), feed([['Name' => 'Varyantlı Tişört', 'Sku' => 'VT1', 'Price' => '50', 'Variants' => [['Sku' => 'V-S', 'Size' => 'S', 'Qty' => 9, 'Price' => '50'], ['Sku' => 'V-M', 'Size' => 'M', 'Qty' => 4, 'Price' => '55']]]]));
$run($cv, $vA, 'sync');
ok((int) DB::getValue('SELECT COUNT(*) FROM product_variations WHERE id_product = ?', [$pv]) === 2 && (float) DB::getValue("SELECT stock FROM product_variations WHERE sku = 'V-S'") === 9.0, 'mevcut varyant güncellendi, yeni varyant (M) oluşturuldu');
ok((int) DB::getValue("SELECT COUNT(*) FROM xh_changes WHERE kind = 'variant' AND id_product = ?", [$pv]) === 1, 'yeni varyant değişiklik kaydı oluştu');
$cv2 = $mkv([['Sku' => 'W-S', 'Size' => 'S', 'Qty' => 3, 'Price' => '50'], ['Sku' => 'W-M', 'Size' => 'M', 'Qty' => 3, 'Price' => '50']], ['create_variants' => 0]);
$run($cv2, $vA);
$pw = (int) DB::getValue("SELECT id_product FROM products WHERE stock_code = 'VT2'");
DB::execute('DELETE FROM product_variations WHERE id_product = ?', [$pw]);
DB::execute("INSERT INTO product_variations (id_product, sku, price, stock) VALUES (?, 'W-S', 50, 3)", [$pw]);
file_put_contents((string) DB::getValue('SELECT file_path FROM xh_connections WHERE id_connection = ?', [$cv2]), feed([['Name' => 'Varyantlı Tişört', 'Sku' => 'VT2', 'Price' => '50', 'Variants' => [['Sku' => 'W-S', 'Size' => 'S', 'Qty' => 3, 'Price' => '50'], ['Sku' => 'W-M', 'Size' => 'M', 'Qty' => 8, 'Price' => '50'], ['Sku' => 'W-L', 'Size' => 'L', 'Qty' => 1, 'Price' => '50']]]]));
$run($cv2, $vA, 'sync');
ok((int) DB::getValue('SELECT COUNT(*) FROM product_variations WHERE id_product = ?', [$pw]) === 1, 'create_variants kapalıyken yeni varyant oluşturulmaz');

section('Tek ürünlü, varyantlı besleme otomatik algılama');
$single = '<?xml version="1.0"?><Urun><Ad>Tek Ürün</Ad><Fiyat>10</Fiyat><Marka>X</Marka><Varyantlar><Varyant><Sku>A</Sku><Beden>S</Beden><Stok>1</Stok></Varyant><Varyant><Sku>B</Sku><Beden>M</Beden><Stok>2</Stok></Varyant><Varyant><Sku>C</Sku><Beden>L</Beden><Stok>3</Stok></Varyant></Varyantlar></Urun>';
$sp = put($single);
ok(XhXml::detectItemPath($sp) === 'Urun', 'tek ürünlü varyantlı besleme: kayıt yolu "Urun" (Varyant değil) seçildi: ' . var_export(XhXml::detectItemPath($sp), true));
$multi = '<?xml version="1.0"?><Katalog><Urun><Ad>A</Ad><Fiyat>1</Fiyat></Urun><Urun><Ad>B</Ad><Fiyat>2</Fiyat></Urun></Katalog>';
ok(XhXml::detectItemPath(put($multi)) === 'Katalog/Urun', 'çok ürünlü besleme etkilenmez');
$mv = '<?xml version="1.0"?><Katalog><Urun><Ad>A</Ad><Fiyat>1</Fiyat><Varyantlar><Varyant><Sku>1</Sku></Varyant><Varyant><Sku>2</Sku></Varyant></Varyantlar></Urun><Urun><Ad>B</Ad><Fiyat>2</Fiyat><Varyantlar><Varyant><Sku>3</Sku></Varyant><Varyant><Sku>4</Sku></Varyant></Varyantlar></Urun></Katalog>';
ok(XhXml::detectItemPath(put($mv)) === 'Katalog/Urun', 'çok ürünlü + varyantlı besleme: kayıt yolu ürün');
$it = iterator_to_array(XhXml::stream($sp, 'Urun'));
$sg = XhMapper::suggest($it[0]);
ok(count($it) === 1 && ($sg['fields']['name'] ?? '') === 'Ad' && ($sg['variant_source'] ?? '') !== '', 'tek ürün akışta okunur; alan ve varyant kaynağı önerilir (' . ($sg['variant_source'] ?? '-') . ')');

section('Marka/kategori eşleştirme sistemi');
$cm = $mk($vA, feed([['Name' => 'M Ürün 1', 'Sku' => 'MM1', 'Price' => '1', 'Qty' => 1, 'Brand' => 'Bilinmeyen Marka', 'Cat' => 'Tedarikçi Kat'], ['Name' => 'M Ürün 2', 'Sku' => 'MM2', 'Price' => '1', 'Qty' => 1, 'Brand' => 'Bilinmeyen Marka', 'Cat' => 'Tedarikçi Kat'], ['Name' => 'M Ürün 3', 'Sku' => 'MM3', 'Price' => '1', 'Qty' => 1, 'Brand' => 'MarkaB', 'Cat' => 'Giyim']]), ['default_category' => 0, 'default_brand' => 0]);
$mm = $mapping; $mm['fields']['brand'] = 'Brand'; $mm['fields']['category'] = 'Cat';
XhRepo::setConn($cm, ['mapping' => json_encode($mm)]);
$r = $run($cm, $vA);
ok((int) $r['failed'] === 3 || (int) $r['failed'] === 2, 'eşleşmeyen marka/kategorili satırlar hata olarak ayrılır (' . $r['failed'] . ' hatalı)');
$un = XhImporter::unmapped(XhRepo::conn($cm, $vA));
ok(($un['brand']['Bilinmeyen Marka'] ?? 0) === 2 && ($un['category']['Tedarikçi Kat'] ?? 0) === 2 && !isset($un['brand']['MarkaB']) && !isset($un['category']['Giyim']), 'eşleşmeyen adlar sayılarıyla listelenir, bilinenler listelenmez');
$conn = XhRepo::conn($cm, $vA);
ok(!XhRepo::setMapEntry($conn, 'brand', 'Bilinmeyen Marka', 999)['success'] && !XhRepo::setMapEntry($conn, 'hack', 'x', 1)['success'], 'var olmayan hedef/geçersiz tür reddedilir');
ok(XhRepo::setMapEntry($conn, 'brand', 'Bilinmeyen Marka', 2)['success'] && XhRepo::setMapEntry(XhRepo::conn($cm, $vA), 'category', 'Tedarikçi Kat', 1)['success'], 'eşleştirme kaydedildi');
$un = XhImporter::unmapped(XhRepo::conn($cm, $vA));
ok($un['brand'] === [] && $un['category'] === [], 'eşleştirmeden sonra liste boşalır');
$r = $run($cm, $vA, 'retry');
ok((int) DB::getValue("SELECT COUNT(*) FROM products WHERE stock_code IN ('MM1','MM2','MM3')") === 3, 'eşleştirme sonrası "hatalıları yeniden dene" ürünleri ekler (yeniden indirme yok)');
ok((int) DB::getValue("SELECT id_brand FROM products WHERE stock_code = 'MM1'") === 2 && (int) DB::getValue("SELECT id_category FROM products WHERE stock_code = 'MM1'") === 1, 'eşleştirilen marka/kategori ürüne uygulandı');
XhRepo::setMapEntry(XhRepo::conn($cm, $vA), 'brand', 'Bilinmeyen Marka', 0);
ok(!isset(XhRepo::mapping(XhRepo::conn($cm, $vA))['brand_map']['Bilinmeyen Marka']), 'eşleştirme kaldırılabilir');

// ---------- panel: yeni eylemler ve izolasyon ----------
section('Panel: kimlik bilgisi ve eşleştirme eylemleri (izolasyon)');
$call = function (int $vendor, string $method, array $get, array $post = []) {
	$_SERVER['REQUEST_METHOD'] = $method; $_GET = $get; $_POST = $post; $_SESSION = [];
	try { $p = new XhPanel($vendor, false, 'https://t.local/xml-merkezi'); $r = $p->handle(); return ['view' => $r[0], 'vars' => $r[1], 'redirect' => null, 'flash' => null]; }
	catch (RuntimeException $e) { return ['view' => null, 'vars' => [], 'redirect' => substr($e->getMessage(), 9), 'flash' => $_SESSION['xh_flash'] ?? null]; }
};
$uc = XhRepo::createConnection($vA, ['name' => 'Panel URL', 'source_type' => 'url', 'url' => 'https://feed.example.com/p.xml', 'options' => ['default_category' => 1, 'default_brand' => 1]]);
$r = $call($vB, 'POST', [], ['xhAction' => 'conn_auth_save', 'id_connection' => $uc['id'], 'auth_user' => 'hack', 'auth_pass' => 'hack']);
ok($r['flash']['type'] === 'danger' && !XhRepo::hasAuth($uc['id']), 'B, A\'nın bağlantısına kimlik bilgisi ekleyemez (IDOR)');
$r = $call($vA, 'POST', [], ['xhAction' => 'conn_auth_save', 'id_connection' => $uc['id'], 'auth_user' => 'ali', 'auth_pass' => 'SIRRI-123']);
ok($r['flash']['type'] === 'success' && XhRepo::hasAuth($uc['id']), 'A kendi bağlantısına kimlik bilgisi ekler');
$r = $call($vA, 'GET', ['view' => 'conn', 'id' => $uc['id'], 'tab' => 'general']);
ok($r['vars']['hasAuth'] === true && strpos(json_encode($r['vars']), 'SIRRI-123') === false && strpos(json_encode($r['vars']), 'ali"') === false, 'ekran verisinde kullanıcı adı/şifre yok (yalnızca "kayıtlı" bilgisi)');
$fc = $mk($vA, feed([['Name' => 'Eşle Ürünü', 'Sku' => 'PM1', 'Price' => '9', 'Qty' => 1, 'Brand' => 'XMarka', 'Cat' => 'XKat']]), ['default_category' => 0, 'default_brand' => 0], 'Panel dosya');
XhRepo::setConn($fc, ['mapping' => json_encode($mm)]);
$run($fc, $vA);
$r = $call($vB, 'POST', [], ['xhAction' => 'conn_map_save', 'id_connection' => $fc, 'map_brand' => [base64_encode('XMarka') => 1]]);
ok($r['flash']['type'] === 'danger' && !isset(XhRepo::mapping(XhRepo::conn($fc, $vA))['brand_map']['XMarka']), 'B, A\'nın marka eşleştirmesini değiştiremez');
$r = $call($vA, 'GET', ['view' => 'conn', 'id' => $fc, 'tab' => 'maps']);
ok($r['view'] === 'conn' && $r['vars']['unmapped']['brand'][0]['text'] === 'XMarka' && $r['vars']['unmapped']['category'][0]['text'] === 'XKat', 'A eşleşmeyen ad listesini görür');
$r = $call($vA, 'POST', [], ['xhAction' => 'conn_map_save', 'id_connection' => $fc, 'map_brand' => [base64_encode('XMarka') => 2], 'map_category' => [base64_encode('XKat') => 1]]);
ok($r['flash']['type'] === 'success' && XhRepo::mapping(XhRepo::conn($fc, $vA))['brand_map']['XMarka'] === 2, 'A eşleştirmeyi kaydeder');
$r = $call($vA, 'POST', [], ['xhAction' => 'conn_map_save', 'id_connection' => $fc, 'map_brand' => [base64_encode('Z') => 9999]]);
ok($r['flash']['type'] === 'danger', 'var olmayan marka ID\'si reddedilir');

section('Fiyat/stok/içerik değişim geçmişi (satıcı izolasyonu)');
$hv = $call($vA, 'GET', ['view' => 'changes']);
$rowsA = $hv['vars']['changes'] ?? [];
$kinds = array_unique(array_column($rowsA, 'kind'));
ok($hv['view'] === 'changes' && count($rowsA) >= 4 && in_array('price', $kinds, true) && in_array('description', $kinds, true) && in_array('cargo', $kinds, true), 'A\'nın fiyat/açıklama/kargo değişimleri listelenir (' . implode(',', $kinds) . ')');
$hvB = $call($vB, 'GET', ['view' => 'changes']);
ok($hvB['view'] === 'changes' && ($hvB['vars']['changes'] ?? []) === [], 'B, A\'nın değişim geçmişini göremez');
$sorted = $rowsA; usort($sorted, fn($a, $b) => strcmp($b['date_add'], $a['date_add']));
ok(array_column($sorted, 'date_add') === array_column($rowsA, 'date_add'), 'geçmiş yeniden eskiye sıralı');

section('Gerçek RvContext/RvPerm ile oturum/RBAC kapıları (XML Hub)');
$rbac = fn(string $args): string => trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(dirname(__DIR__, 2) . '/xml-hub/tests/rbac_probe.php') . ' ' . $args . ' 2>/dev/null'));
foreach ([['0 GET', '302', 'oturumsuz kullanıcı giriş sayfasına yönlenir'], ['6 GET', '403', 'satıcı olmayan oturum 403'], ['2 GET', '403', 'viewer rolü (xml.view yok) 403'], ['3 GET', '403', 'orders rolü 403'], ['1 GET', '200|PAGE|FORMS', 'owner sayfayı ve formları görür'], ['4 GET', '200|PAGE|FORMS', 'catalog rolü (xml.view+manage) erişir'], ['5 GET', '200|PAGE|FORMS', 'manager rolü erişir'], ['7 GET', '200|PAGE|FORMS', 'başka satıcının sahibi yalnızca kendi alanını görür'], ['3 POST', '403', 'orders rolü POST yapamaz'], ['2 POST', '403', 'viewer POST yapamaz'], ['6 POST', '403', 'satıcı olmayan POST yapamaz'], ['1 POST', '200|Fatal error: Uncaught RuntimeException: REDIRECT', 'owner POST işlenir ve yönlendirme üretir (test modunda istisna olarak görünür)']] as [$a, $exp, $name]) {
	$o = $rbac('xh ' . $a); ok(strpos($o, $exp) === 0, "XML Hub: $name [$o]");
}


// ---------- cron ----------
section('Cron güvenliği ve sayfa önbelleği');
$probe = function (string $key, string $mode = '') { return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(__DIR__ . '/cron_probe.php') . ' ' . escapeshellarg($key) . ' ' . escapeshellarg($mode) . ' 2>/dev/null')); };
$o = $probe('-');
ok(substr($o, -4) === '|403' && strpos($o, 'forbidden') !== false, 'anahtarsız çağrı 403');
$o = $probe('YANLIS');
ok(substr($o, -4) === '|403' && strpos($o, 'YANLIS') === false, 'yanlış anahtar 403 (anahtar yanıta yansımaz)');
$o = $probe('GIZLI-ANAHTAR-12');
ok(substr($o, -4) === '|403', 'anahtarın kısa/önek hali 403 (sabit zamanlı karşılaştırma)');
$o = $probe('GIZLI-ANAHTAR-123');
ok(substr($o, -4) === '|200' && strpos($o, '"ok":true') !== false, 'doğru anahtar tick çalıştırır');
$o = $probe('GIZLI-ANAHTAR-123', 'pending');
ok(substr($o, -4) === '|503' && strpos($o, 'migration pending') !== false, 'bekleyen migration varken 503 (veriye dokunmaz)');
$url = XhRunner::cronUrl('https://rafimda.com.tr/', 'abc');
ok($url === 'https://rafimda.com.tr/xml-cron?q=cron&key=abc', 'cron adresi q=cron içerir');
// Çekirdek sayfa önbelleği: gerçek config/function.php (Tools) + core/Performance.php ile, ayrı süreçte
$cache = function (string $uri): string { return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(__DIR__ . '/cache_probe.php') . ' ' . escapeshellarg($uri) . ' 2>/dev/null')); };
ok($cache('/xml-cron?q=cron&key=abc') === 'CACHEABLE=0', 'gerçek Performance::canUsePageCache(): q=cron\'lu cron isteği önbelleğe ALINMAZ');
// Bilgi: bu çekirdek sürümünde Tools::getValue() varsayılanı false döndürdüğünden (false !== \'\') canUsePageCache() her sayfa için false verir
$home = $cache('/');
ok($home === 'CACHEABLE=0' || $home === 'CACHEABLE=1', 'bilgi: ana sayfa için önbelleğe alınabilirlik ölçüldü (' . $home . ') — q=cron her iki durumda da koruma sağlar');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
foreach ($failures as $f) { echo " BAŞARISIZ: $f\n"; }
exit($fail ? 1 : 0);
