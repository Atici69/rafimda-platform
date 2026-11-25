<?php
// marketplace-hub v0.2: çekirdek istemci sarmalayıcıları (Trendyol/Hepsiburada/N11) — SAHTE istemcilerle; gerçek ağ çağrısı YOK
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }

class FakeTy { public static array $calls = []; public static $probe = ['content' => []]; public static $push = ['batchRequestId' => 'abc'];
	public function __construct(public $a, public $b, public $c) {}
	public function getProducts($l, $p = 0) { self::$calls[] = ['getProducts', $l, $p]; return self::$probe; }
	public function updateStockPrice($barcode, $list, $sale, $stock, $sku = null) { self::$calls[] = ['ty_usp', $barcode, $list, $sale, $stock, $sku]; return self::$push; } }
class FakeHb { public static array $calls = []; public static $probe = ['success' => false, 'httpCode' => 404, 'message' => 'not found']; public static $price = ['success' => true]; public static $stock = ['success' => true];
	public function __construct(public $m, public $u, public $p) {}
	public function getProduct($sku) { self::$calls[] = ['getProduct', $sku]; return self::$probe; }
	public function updatePrice($sku, $price) { self::$calls[] = ['hb_price', $sku, $price]; return self::$price; }
	public function updateStock($sku, $stock) { self::$calls[] = ['hb_stock', $sku, $stock]; return self::$stock; } }
class FakeN11 { public static array $calls = []; public static $probe = ['ok' => false, 'success' => false, 'httpCode' => 404, 'message' => 'x']; public static $push = ['ok' => true, 'id' => 1];
	public function __construct(public $k, public $s) {}
	public function getProduct($sku) { self::$calls[] = ['getProduct', $sku]; return self::$probe; }
	public function updateStockPrice($sku, $list, $sale, $stock) { self::$calls[] = ['n11_usp', $sku, $list, $sale, $stock]; return self::$push; } }
MhCoreAdapter::$factories = [
	'trendyol' => fn($c) => new FakeTy($c['supplier_id'], $c['api_key'], $c['api_secret']),
	'hepsiburada' => fn($c) => new FakeHb($c['merchant_id'], $c['username'], $c['password']),
	'n11' => fn($c) => new FakeN11($c['app_key'], $c['app_secret']),
];
$cred = ['trendyol' => ['supplier_id' => '123', 'api_key' => 'KEY-SECRET-1', 'api_secret' => 'SEC-SECRET-2'], 'hepsiburada' => ['merchant_id' => 'm-1', 'username' => 'u', 'password' => 'PASS-SECRET-3'], 'n11' => ['app_key' => 'k', 'app_secret' => 'APPSECRET-4']];

section('Yetenek bayrakları (sahte iddia yok)');
mh_fresh_db(); MhRegistry::reset();
foreach (['trendyol', 'hepsiburada', 'n11'] as $k) { $a = MhRegistry::get($k); ok($a instanceof MhCoreAdapter && $a->supportsStockPrice() && ($k === 'trendyol' ? $a->implemented() : !$a->implemented()), "$k: stok/fiyat yeteneği var" . ($k === 'trendyol' ? ', ürün oluşturma uygulandı' : ', ürün oluşturma (implemented) YOK')); $s = $a->submit($cred[$k], ['id_product' => 1]); ok(!$s['success'] && $s['remote_id'] === '', "$k: submit hâlâ başarısız (oluşturma yapılmadı)"); }
foreach (['amazon', 'pazarama', 'ozon'] as $k) { ok(!(MhRegistry::get($k) instanceof MhCoreAdapter) && !MhRegistry::get($k)->implemented(), "$k: hiçbir yetenek yok (API belgesi/erişim bekleniyor)"); }

section('Bağlantı testi');
$ty = MhRegistry::get('trendyol');
ok($ty->testConnection($cred['trendyol'])['success'], 'Trendyol: salt-okunur getProducts başarılı => bağlantı OK');
ok(FakeTy::$calls[0][0] === 'getProducts' && FakeTy::$calls[0][1] === 1, 'yalnızca salt-okunur çağrı yapıldı');
FakeTy::$probe = ['success' => false, 'httpCode' => 401, 'message' => 'Unauthorized <b>x</b>']; $r = $ty->testConnection($cred['trendyol']);
ok(!$r['success'] && strpos($r['message'], 'reddedildi') !== false && strpos($r['message'], '<b>') === false, 'Trendyol 401: kimlik reddedildi mesajı, HTML temizlenir');
FakeTy::$probe = null; ok(!$ty->testConnection($cred['trendyol'])['success'], 'null yanıt => başarısız');
ok(!$ty->testConnection(['supplier_id' => '1'])['success'] && count(FakeTy::$calls) === 3, 'eksik kimlik bilgisi => istemci çağrılmaz');
$hb = MhRegistry::get('hepsiburada'); $r = $hb->testConnection($cred['hepsiburada']);
ok($r['success'] && strpos($r['message'], 'Canlı doğrulama') !== false, 'HB 404 => kimlik reddedilmedi (dürüst mesajla)');
FakeHb::$probe = ['success' => false, 'httpCode' => 401, 'message' => 'no']; ok(!$hb->testConnection($cred['hepsiburada'])['success'], 'HB 401 => başarısız');
FakeHb::$probe = ['success' => false, 'httpCode' => 500, 'message' => 'err']; ok(!$hb->testConnection($cred['hepsiburada'])['success'], 'HB 500 => başarısız');
$n = MhRegistry::get('n11'); ok($n->testConnection($cred['n11'])['success'], 'N11 404 => kimlik reddedilmedi');
FakeN11::$probe = ['ok' => false, 'success' => false, 'httpCode' => 403, 'message' => 'x']; ok(!$n->testConnection($cred['n11'])['success'], 'N11 403 => başarısız');
MhCoreAdapter::$factories['trendyol'] = fn($c) => throw new RuntimeException('curl hatası ' . $c['api_key']);
$r = $ty->testConnection($cred['trendyol']); ok(!$r['success'] && strpos($r['message'], 'KEY-SECRET-1') === false && strpos($r['message'], '***') !== false, 'istemci üretim istisnası yakalanır ve mesajdaki anahtar maskelenir');
MhCoreAdapter::$factories['trendyol'] = fn($c) => new class($c) extends FakeTy { public function __construct($c) {} public function getProducts($l, $p = 0) { throw new RuntimeException('hata ' . 'SEC-SECRET-2'); } };
$r = $ty->testConnection($cred['trendyol']); ok(!$r['success'] && strpos($r['message'], 'SEC-SECRET-2') === false, 'istek sırasındaki istisna yakalanır, sır maskelenir');
MhCoreAdapter::$factories['trendyol'] = fn($c) => new FakeTy($c['supplier_id'], $c['api_key'], $c['api_secret']);
FakeTy::$probe = ['content' => []];

section('Stok/fiyat gönderimi (adaptör)');
FakeTy::$calls = [];
$r = $ty->updateStockPrice($cred['trendyol'], [['sku' => 'S1', 'barcode' => 'B1', 'price' => 90.5, 'list_price' => 100, 'stock' => 7]]);
ok($r['success'] && $r['done'] === 1 && FakeTy::$calls[0] === ['ty_usp', 'B1', 100.0, 90.5, 7, 'S1'], 'Trendyol: barkod, liste/satış fiyatı, stok doğru taşınır');
FakeTy::$push = ['success' => false, 'httpCode' => 400, 'message' => 'Barkod bulunamadı'];
$r = $ty->updateStockPrice($cred['trendyol'], [['sku' => 'S1', 'barcode' => 'B1', 'price' => 90, 'list_price' => 90, 'stock' => 1]]);
ok(!$r['success'] && $r['failed'] === 1 && strpos($r['errors'][0], 'Barkod bulunamadı') !== false, 'pazaryeri hatası başarısız sayılır ve mesajı taşınır');
FakeTy::$push = ['batchRequestId' => 'abc'];
$r = $ty->updateStockPrice($cred['trendyol'], [['sku' => '', 'barcode' => '', 'price' => 10, 'list_price' => 10, 'stock' => 1], ['sku' => 'X', 'barcode' => '', 'price' => 0, 'list_price' => 0, 'stock' => 1]]);
ok($r['failed'] === 2 && $r['done'] === 0 && !$r['success'], 'SKU/barkodsuz veya fiyatsız satır GÖNDERİLMEZ');
FakeHb::$calls = []; $r = $hb->updateStockPrice($cred['hepsiburada'], [['sku' => 'H1', 'barcode' => '', 'price' => 50, 'list_price' => 60, 'stock' => 4]]);
ok($r['success'] && [FakeHb::$calls[0][0], FakeHb::$calls[1][0]] === ['hb_price', 'hb_stock'] && FakeHb::$calls[0][2] === 50.0 && FakeHb::$calls[1][2] === 4, 'HB: fiyat ve stok ayrı uç noktalara');
FakeHb::$stock = ['success' => false, 'message' => 'stok reddedildi']; $r = $hb->updateStockPrice($cred['hepsiburada'], [['sku' => 'H1', 'barcode' => '', 'price' => 50, 'list_price' => 60, 'stock' => 4]]);
ok(!$r['success'] && $r['failed'] === 1, 'HB: yalnızca stok çağrısı başarısızsa satır başarısız'); FakeHb::$stock = ['success' => true];
FakeN11::$calls = []; $r = $n->updateStockPrice($cred['n11'], [['sku' => 'N1', 'barcode' => '', 'price' => 20, 'list_price' => 25, 'stock' => 9]]);
ok($r['success'] && FakeN11::$calls[0] === ['n11_usp', 'N1', 25.0, 20.0, 9], 'N11: liste/satış fiyatı ve stok');
ok(!$n->updateStockPrice(['app_key' => ''], [['sku' => 'N1', 'price' => 1, 'list_price' => 1, 'stock' => 1]])['success'], 'N11: eksik kimlik => gönderim yok');

section('Dispatcher: sahiplik, hata günlüğü, gizlilik');
mh_fresh_db(); MhRegistry::reset(); $_SESSION = [];
DB::execute("INSERT INTO rafimda_vendors (id_vendor, owner_user_id, store_name, store_slug, status) VALUES (1, 1, 'S1', 's1', 'active'), (2, 2, 'S2', 's2', 'active')");
$p1 = (int) DB::insert('products', ['product_name' => 'A', 'id_category' => 1, 'id_brand' => 1, 'price' => 80, 'old_price' => 100, 'stock' => 5, 'stock_code' => 'A-1', 'barcode' => 'BA1', 'active' => 1]);
$p2 = (int) DB::insert('products', ['product_name' => 'B', 'id_category' => 1, 'id_brand' => 1, 'price' => 30, 'stock' => 2, 'stock_code' => 'B-1', 'barcode' => 'BB1', 'active' => 1]);
DB::insert('rafimda_vendor_products', ['id_product' => $p1, 'id_vendor' => 1]); DB::insert('rafimda_vendor_products', ['id_product' => $p2, 'id_vendor' => 2]);
$acc = MhRepo::createAccount(1, 'trendyol', 'Trendyol hesabım', $cred['trendyol']);
ok($acc['success'], 'hesap oluşturuldu (şifreli)'); $idAcc = (int) $acc['id'];
FakeTy::$calls = [];
$r = MhDispatcher::pushStockPrice($idAcc, 1, [$p1, $p2, 99999]);
ok($r['ok'] && $r['done'] === 1 && $r['rejected'] === 2, 'yalnızca kendi ürünü gönderilir; başkasının ve olmayan ürün reddedilir');
ok(count(FakeTy::$calls) === 1 && FakeTy::$calls[0][1] === 'BA1' && FakeTy::$calls[0][2] === 100.0 && FakeTy::$calls[0][3] === 80.0, 'liste fiyatı = max(fiyat, eski fiyat), satış fiyatı = fiyat');
$r = MhDispatcher::pushStockPrice($idAcc, 2, [$p2]); ok(!$r['ok'] && $r['message'] === 'Hesap bulunamadı.' && count(FakeTy::$calls) === 1, 'başka satıcı bu hesapla gönderemez (IDOR)');
FakeTy::$push = ['success' => false, 'httpCode' => 429, 'message' => 'Too many requests'];
$r = MhDispatcher::pushStockPrice($idAcc, 1, [$p1]); $errs = DB::execute('SELECT * FROM mh_errors WHERE id_account = ?', [$idAcc]);
ok(!$r['ok'] && $r['failed'] === 1 && count($errs) === 1 && $errs[0]['code'] === 'stock_price', 'hata günlüğe yazılır');
$all = json_encode([$errs, $r, DB::execute('SELECT * FROM mh_accounts'), DB::execute('SELECT * FROM mh_listings')]);
ok(strpos($all, 'KEY-SECRET-1') === false && strpos($all, 'SEC-SECRET-2') === false, 'hata/hesap/listeleme kayıtlarında API anahtarı sızmaz');
$af = array_fill_keys(array_keys(MhRegistry::get('amazon')->credentialFields()), 'deger-1234');
$ama = MhRepo::createAccount(1, 'amazon', 'Amazon', $af);
ok($ama['success'], 'Amazon hesabı oluşturulabilir (yalnızca kimlik saklama)');
$idAma = (int) ($ama['id'] ?? 0); $r = $idAma ? MhDispatcher::pushStockPrice($idAma, 1, [$p1]) : ['ok' => false, 'message' => 'x'];
ok(!$r['ok'] && strpos($r['message'], 'henüz yapılmadı') !== false, 'Amazon: stok/fiyat gönderimi yok (sahte başarı yok)');
FakeTy::$push = ['batchRequestId' => 'abc'];
$pv = (int) DB::insert('products', ['product_name' => 'V', 'id_category' => 1, 'id_brand' => 1, 'price' => 10, 'stock' => 1, 'stock_code' => 'V-1', 'active' => 1]); DB::insert('rafimda_vendor_products', ['id_product' => $pv, 'id_vendor' => 1]);
DB::execute('INSERT INTO product_variations (id_product, sku, price, stock) VALUES (?,?,?,?)', [$pv, 'V-1-S', 12, 3]); DB::execute('INSERT INTO product_variations (id_product, sku, price, stock) VALUES (?,?,?,?)', [$pv, 'V-1-M', null, 0]);
ok(count(MhPayload::stockPriceItems($pv)) === 2 && MhPayload::stockPriceItems($pv)[1]['price'] === 10.0 && MhPayload::stockPriceItems($pv)[0]['price'] === 12.0, 'varyantlı ürün: her varyant ayrı satır, fiyatsız varyant ana fiyatı alır');

section('Çekirdek istemci imzaları (gerçek sınıf, ağ çağrısı yok)');
$core = dirname(__DIR__, 3) . '/core/marketplace/';
$ns = ['TrendyolApi' => '\\Trendyol\\TrendyolApi', 'HepsiburadaApi' => '\\Hepsiburada\\HepsiburadaApi', 'N11Api' => '\\N11\\N11Api'];
$sigs = ['TrendyolApi' => ['getProducts' => 2, 'updateStockPrice' => 5], 'HepsiburadaApi' => ['getProduct' => 1, 'updatePrice' => 3, 'updateStock' => 3], 'N11Api' => ['getProduct' => 1, 'updateStockPrice' => 4]];
foreach ($sigs as $cls => $methods) {
	$f = $core . $cls . '.php'; ok(is_file($f), "$cls dosyası var"); $fq = ltrim($ns[$cls], '\\');
	if (!class_exists($fq, false)) { require_once $f; }
	foreach ($methods as $m => $n) { $ok = class_exists($fq, false) && method_exists($fq, $m) && (new ReflectionMethod($fq, $m))->getNumberOfParameters() === $n; ok($ok, "$fq::$m($n parametre) çekirdekte mevcut ve imza uyumlu"); }
}
ok((new ReflectionClass('Trendyol\\TrendyolApi'))->getConstructor()->getNumberOfParameters() === 3 && (new ReflectionClass('Hepsiburada\\HepsiburadaApi'))->getConstructor()->getNumberOfParameters() === 3 && (new ReflectionClass('N11\\N11Api'))->getConstructor()->getNumberOfParameters() === 2, 'yapıcı imzaları (supplier/key/secret) uyumlu');
ok(!preg_match('/Settings::get\\(\'(TRENDYOL|HEPSIBURADA|N11)/', file_get_contents(dirname(__DIR__) . '/lib/MhCoreAdapter.php') . file_get_contents(dirname(__DIR__) . '/adapters/MhAdapterTrendyol.php')), 'sarmalayıcı çekirdeğin Settings tabanlı tek mağaza ayarlarını KULLANMAZ (satıcı kimlikleriyle çalışır)');

section('Gerçek sınıf bağlama (fabrikasız, ağ çağrısı yok)');
$saved = MhCoreAdapter::$factories; MhCoreAdapter::$factories = [];
foreach (['trendyol' => 'Trendyol\\TrendyolApi', 'hepsiburada' => 'Hepsiburada\\HepsiburadaApi', 'n11' => 'N11\\N11Api'] as $k => $cls) {
	$rm = new ReflectionMethod(MhRegistry::get($k), 'makeClient'); $rm->setAccessible(true);
	$obj = $rm->invoke(MhRegistry::get($k), $cred[$k]); ok($obj instanceof $cls && $obj->isConfigured(), "$k: makeClient çekirdekteki $cls örneğini kimliklerle oluşturur");
}
MhCoreAdapter::$factories = $saved;

section('Panel');
$_SESSION = []; $_GET = []; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'POST';
$panel = new MhPanel(1, false, 'https://t.local/pazaryeri');
$_POST = ['mhAction' => 'push_stock_price', 'id_account' => (string) $idAcc, 'ids' => [(string) $p1]]; FakeTy::$calls = [];
try { $panel->handle(true); } catch (RuntimeException $e) { ok(strpos($e->getMessage(), 'REDIRECT') === 0 && count(FakeTy::$calls) === 1, 'panel: stok/fiyat gönderimi yönlendirir ve tek çağrı yapar'); }
section('Paket kotası (pazaryeri hesabı)');
mh_fresh_db(); MhRegistry::reset(); RvPlans::save(['code' => 'dar', 'name' => 'Dar', 'max_marketplace_accounts' => '1', 'active' => 1]);
DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'Kota', 'store_slug' => 'kota', 'status' => 'active', 'plan_code' => 'dar']);
$idk = (int) DB::getValue("SELECT id_vendor FROM rafimda_vendors WHERE store_slug = 'kota'");
$k1 = MhRepo::createAccount($idk, 'trendyol', 'Hesap bir', $cred['trendyol']); $k2 = MhRepo::createAccount($idk, 'n11', 'Hesap iki', $cred['n11']);
ok($k1['success'] && !$k2['success'] && strpos($k2['message'], 'limit') !== false, 'paket limiti 1: ikinci pazaryeri hesabı reddedilir');
ok(MhRepo::createAccount(0, 'n11', 'Platform hesabı', $cred['n11'])['success'], 'platform hesapları kotaya tabi değildir');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n"; exit($fail ? 1 : 0);
