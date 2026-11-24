<?php
// XML Hub v0.3 testleri: satıcı bazlı XML dışa aktarma (token, izolasyon, moderasyon, akış, XML güvenliği, panel)
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function addp(int $vendor, string $name, array $o = []): int {
	$o += ['price' => 10, 'stock' => 5, 'active' => 1, 'mod' => 'approved', 'desc' => 'açıklama', 'sku' => 'S' . mt_rand(1000, 9999), 'cat' => 1, 'brand' => 1];
	$id = DB::insert('products', ['product_name' => $name, 'id_category' => $o['cat'], 'id_brand' => $o['brand'], 'price' => $o['price'], 'stock' => $o['stock'], 'stock_code' => $o['sku'], 'barcode' => 'B' . $o['sku'], 'description' => $o['desc'], 'active' => $o['active']]);
	if ($vendor > 0) { DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $vendor, 'moderation' => $o['mod']]); }
	return (int) $id;
}
function feedXml(array $feed): string { $p = XhStorage::newPath('x'); XhExport::stream($feed, $p); $s = (string) file_get_contents($p); @unlink($p); return $s; }

section('Migration 003');
xh_fresh_db();
$raw = file_get_contents(dirname(__DIR__) . '/migrations/003_export_feeds.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|ALTER|UPDATE)\b/i', $raw) && !preg_match('/CREATE TABLE(?! IF NOT EXISTS)/', $raw), '003 yıkıcı değil');
ok(DB::execute("SELECT name FROM sqlite_master WHERE name = 'xh_export_feeds'") !== [], 'tablo oluşur');
$GLOBALS['db']->exec(xh_mysql_to_sqlite($raw));
ok(true, '003 idempotent');

section('Besleme oluşturma ve token');
$r = XhExport::create(7, 'Ana besleme', false);
ok($r['success'] && preg_match('/^[a-f0-9]{48}$/', $r['token']), 'token 48 hex');
$row = DB::getRowSafe('xh_export_feeds', 'id_feed = ?', [$r['id']]);
ok($row && strpos(json_encode($row), $r['token']) === false && $row['token_hash'] === hash('sha256', $r['token']), 'veritabanında düz token yok, SHA-256 özeti var');
ok(XhExport::byToken($r['token'])['id_vendor'] == 7, 'token ile besleme bulunur');
ok(XhExport::byToken(str_repeat('a', 48)) === null && XhExport::byToken("' OR 1=1 --") === null && XhExport::byToken('') === null, 'yanlış / enjeksiyon token reddedilir');
ok(!XhExport::create(0, 'Platform', false)['success'], 'platform (satıcı yok) besleme oluşturamaz');
ok(!XhExport::create(7, 'x', false)['success'], 'kısa ad reddedilir');
for ($i = 0; $i < 9; $i++) { XhExport::create(7, "B$i", false); }
ok(!XhExport::create(7, 'Fazla', false)['success'], 'satıcı başına en fazla 10 etkin besleme');
ok(count(XhExport::feeds(7)) === 10 && XhExport::feeds(8) === [], 'liste yalnızca kendi beslemelerini döndürür');
ok(!XhExport::revoke(8, (int) $r['id']), 'başka satıcı beslemeyi iptal edemez');
ok(XhExport::revoke(7, (int) $r['id']) && XhExport::byToken($r['token']) === null, 'iptal edilen token geçersiz');

section('İçerik: izolasyon, moderasyon, aktiflik, stok');
xh_fresh_db();
$a = addp(1, 'Satıcı1 Ürün', ['sku' => 'A1']);
$b = addp(2, 'Satıcı2 Ürün', ['sku' => 'B1']);
$pend = addp(1, 'Bekleyen', ['mod' => 'pending', 'sku' => 'P1']);
$off = addp(1, 'Pasif', ['active' => 0, 'sku' => 'O1']);
$nostock = addp(1, 'Stoksuz', ['stock' => 0, 'sku' => 'N1']);
$plat = addp(0, 'Platform Ürün', ['sku' => 'PL1']);
$f1 = XhExport::create(1, 'feed', false); $feed1 = XhExport::byToken($f1['token']);
$x = feedXml($feed1);
ok(strpos($x, 'Satıcı1 Ürün') !== false, 'kendi onaylı ürünü var');
ok(strpos($x, 'Satıcı2 Ürün') === false && strpos($x, 'Platform Ürün') === false, 'başka satıcı ve platform ürünü YOK (veri izolasyonu)');
ok(strpos($x, 'Bekleyen') === false && strpos($x, 'Pasif') === false, 'onaysız ve pasif ürün yok');
ok(strpos($x, 'Stoksuz') !== false, 'stoksuz ürün varsayılan olarak var');
$f2 = XhExport::create(1, 'stoklu', true); $x2 = feedXml(XhExport::byToken($f2['token']));
ok(strpos($x2, 'Stoksuz') === false && strpos($x2, 'Satıcı1 Ürün') !== false, 'yalnızca stokta olanlar seçeneği');
ok(strpos($x, 'token') === false && strpos($x, $f1['token']) === false, 'çıktıda token sızmaz');

section('XML geçerliliği ve güvenlik');
$d = new DOMDocument(); ok(@$d->loadXML($x) !== false, 'çıktı iyi biçimli XML');
xh_fresh_db();
addp(1, 'Ürün <script>&"\'', ['desc' => 'Açıklama ]]> <b>x</b> & ş ğ ü', 'sku' => 'X1']);
$x = feedXml(XhExport::byToken(XhExport::create(1, 'feed', false)['token']));
$d = new DOMDocument(); ok(@$d->loadXML($x) !== false, 'özel karakter ve ]]> içeren veri XML’i bozmaz');
$name = $d->getElementsByTagName('Name')->item(0)->textContent;
ok($name === 'Ürün <script>&"\'', 'ad kayıpsız gidiş-dönüş (kaçışlı)');
ok(strpos($d->getElementsByTagName('Description')->item(0)->textContent, 'Açıklama ]]> <b>x</b> & ş ğ ü') === 0, 'açıklama CDATA içinde kayıpsız');
ok(strpos($x, '<!DOCTYPE') === false && strpos($x, '<!ENTITY') === false, 'DOCTYPE/ENTITY yok');

section('Varyant, görsel, kargo');
xh_fresh_db();
$id = addp(1, 'Varyantlı', ['sku' => 'V1']);
DB::execute('INSERT INTO product_variations (id_product, sku, price, stock) VALUES (?,?,?,?)', [$id, 'V1-S', 12.5, 3]);
DB::execute('INSERT INTO product_variations (id_product, sku, price, stock) VALUES (?,?,?,?)', [$id, 'V1-M', 13, 0]);
DB::execute('INSERT INTO images (id_product, cover) VALUES (?, 1)', [$id]);
XhMeta::save($id, 1, ['source_type' => 'xml', 'cargo_price' => 19.9]);
$d = new DOMDocument(); $d->loadXML(feedXml(XhExport::byToken(XhExport::create(1, 'feed', false)['token'])));
ok($d->getElementsByTagName('Variant')->length === 2 && $d->getElementsByTagName('Variant')->item(0)->getElementsByTagName('Sku')->item(0)->textContent === 'V1-S', 'varyantlar yazılır');
ok($d->getElementsByTagName('Image')->length === 1, 'görsel yazılır');
ok($d->getElementsByTagName('CargoPrice')->item(0)->textContent === '19.90', 'kargo ücreti (xh_product_meta) yazılır');

section('Büyük katalog (sayfalı akış)');
xh_fresh_db();
$GLOBALS['db']->beginTransaction();
for ($i = 1; $i <= 1000; $i++) { addp(1, "Ürün $i", ['sku' => "K$i"]); }
$GLOBALS['db']->commit();
$feed = XhExport::byToken(XhExport::create(1, 'büyük', false)['token']);
$m0 = memory_get_usage(true); $p = XhStorage::newPath('big');
$n = XhExport::stream($feed, $p);
ok($n === 1000, '1000 ürün yazıldı (sayfa boyutu ' . XhExport::BATCH . ')');
$cnt = 0; $r = new XMLReader(); $r->open($p); while ($r->read()) { if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'Product') { $cnt++; } } $r->close();
ok($cnt === 1000, 'XMLReader ile 1000 ürün okunur'); ok(memory_get_usage(true) - $m0 < 8 * 1024 * 1024, 'bellek artışı < 8MB'); @unlink($p);

section('Panel: yetki ve akış');
define('XH_TEST_PANEL', 1);
xh_fresh_db(); $_SESSION = []; $_GET = []; $_POST = [];
$panel = new XhPanel(3, false, 'https://test.local/xml-merkezi');
$_GET['view'] = 'export'; [$view, $vars] = $panel->handle(false);
ok($view === 'export' && $vars['feeds'] === [] && isset($vars['xhTitles']['export']), 'satıcı dışa aktarma ekranını görür');
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['xhAction' => 'export_create', 'name' => 'Panel', 'only_in_stock' => '1'];
try { $panel->handle(true); ok(false, 'yönlendirme beklenir'); } catch (RuntimeException $e) { ok(strpos($e->getMessage(), 'REDIRECT') === 0, 'oluşturma sonrası yönlendirir'); }
ok(count(XhExport::feeds(3)) === 1 && isset($_SESSION['xh_new_token']), 'besleme kaydedildi, token oturumda');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; [$view, $vars] = $panel->handle(false);
ok(strpos($vars['newUrl'], 'https://test.local/xml-export?t=') === 0 && !isset($_SESSION['xh_new_token']), 'adres bir kez gösterilir, sonra silinir');
[$view, $vars] = $panel->handle(false); ok($vars['newUrl'] === '', 'ikinci görüntülemede adres yok');
$adm = new XhPanel(0, true, 'https://test.local/xml-merkezi'); $_GET['view'] = 'export'; [$view] = $adm->handle(false);
ok($view === 'list', 'yönetici (satıcı değil) dışa aktarma ekranına düşmez');
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['xhAction' => 'export_create', 'name' => 'Admin']; $before = (int) DB::getValue('SELECT COUNT(*) FROM xh_export_feeds');
try { $adm->handle(true); } catch (RuntimeException $e) {}
ok((int) DB::getValue('SELECT COUNT(*) FROM xh_export_feeds') === $before, 'yönetici POST ile besleme oluşturamaz');
$id3 = (int) XhExport::feeds(3)[0]['id_feed']; $_POST = ['xhAction' => 'export_revoke', 'id_feed' => (string) $id3];
$other = new XhPanel(4, false, 'https://test.local/xml-merkezi'); try { $other->handle(true); } catch (RuntimeException $e) {}
ok((int) XhExport::feeds(3)[0]['active'] === 1, 'başka satıcı beslemeyi iptal edemez (IDOR)');
try { $panel->handle(true); } catch (RuntimeException $e) {}
ok((int) XhExport::feeds(3)[0]['active'] === 0, 'sahibi iptal edebilir');

section('Ön uç ve rota');
$code = file_get_contents(dirname(__DIR__) . '/front/export.php');
ok(strpos($code, '404') !== false && strpos($code, 'no-store') !== false && strpos($code, 'XhMigrator::pending() === []') !== false, 'rota: yanlış token 404, önbellek yok, migration bekliyorsa servis yok');
ok(strpos(file_get_contents(dirname(__DIR__) . '/xml-hub.php'), "'xml-export' => 'front/export.php'") !== false, 'rota kayıtlı');
section('Paket kotası (XML bağlantısı)');
xh_fresh_db(); XhUrlGuard::$resolver = fn(string $h): array => ['93.184.216.34']; RvPlans::save(['code' => 'dar', 'name' => 'Dar', 'max_xml_connections' => '1', 'active' => 1]);
DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'Kota', 'store_slug' => 'kota', 'status' => 'active', 'plan_code' => 'dar']);
$idv = (int) DB::getValue("SELECT id_vendor FROM rafimda_vendors WHERE store_slug = 'kota'");
$mkc = fn(string $n) => XhRepo::createConnection($idv, ['name' => $n, 'source_type' => 'url', 'url' => 'https://pub.example.com/feed.xml', 'options' => ['default_category' => 1, 'default_brand' => 1]]);
$q1 = $mkc('Bağlantı bir'); $q2 = $mkc('Bağlantı iki');
ok($q1['success'] && !$q2['success'] && strpos($q2['message'], 'limit') !== false, 'paket limiti 1: ikinci XML bağlantısı reddedilir');
ok(XhRepo::createConnection(0, ['name' => 'Platform bağlantı', 'source_type' => 'url', 'url' => 'https://pub.example.com/f.xml', 'options' => ['default_category' => 1, 'default_brand' => 1]])['success'], 'platform (id_vendor=0) bağlantıları kotaya tabi değildir');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n"; exit($fail ? 1 : 0);
