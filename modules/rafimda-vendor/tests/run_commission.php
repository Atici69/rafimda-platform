<?php
/** Komisyon kuralları (ürün/kategori), kalem bazlı hesap, iptal/iade, kazanç dökümü, yönetici raporu ve ücretsiz mağaza modu. */
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0; $failures = [];
function ok(bool $cond, string $name): void { global $pass, $fail, $failures; if ($cond) { $pass++; echo "  ✓ $name\n"; } else { $fail++; $failures[] = $name; echo "  ✗ $name\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function near($a, $b): bool { return abs((float) $a - (float) $b) < 0.001; }

function fresh(): array
{
	rv_fresh_db();
	rv_apply_migrations();
	RvCommission::reset();
	Settings::$d = ['RAFIMDA_DEFAULT_COMMISSION' => '10'];
	$GLOBALS['db']->exec('ALTER TABLE categories ADD COLUMN id_parent INT DEFAULT 0');
	// Ağaç: 1 Giyim > 10 Kadın > 100 Elbise ; 3 Elektronik > 30 Telefon
	DB::execute("INSERT INTO categories (id_category, category_name, active, id_parent) VALUES (10,'Kadın',1,1),(100,'Elbise',1,10),(3,'Elektronik',1,0),(30,'Telefon',1,3)");
	$a = RvVendor::create(1, RvVendor::validateStore(['store_name' => 'Moda Evi'])['clean'])['id_vendor'];
	$b = RvVendor::create(2, RvVendor::validateStore(['store_name' => 'Tekno Dükkan'])['clean'])['id_vendor'];
	RvVendor::setStatus($a, 'active', 99);
	RvVendor::setStatus($b, 'active', 99);
	$p = [];
	foreach ([[1, 'Elbise', 100, $a], [2, 'Tişört', 10, $a], [3, 'Telefon', 30, $b], [4, 'Kablo', 3, $b], [5, 'Kategorisiz', 0, $b]] as [$id, $n, $cat, $v]) {
		DB::execute('INSERT INTO products (id_product, product_name, id_category, price, stock, active) VALUES (?,?,?,100,10,1)', [$id, $n, $cat]);
		DB::insert('rafimda_vendor_products', ['id_vendor' => $v, 'id_product' => $id, 'moderation' => 'approved']);
	}

	return [$a, $b];
}

function order(int $id, array $lines): array
{
	DB::execute("INSERT INTO orders (id_order,reference,status,date_add,customer_name,address_city) VALUES (?,?,1,?,'Müşteri','İzmir')", [$id, 'R' . $id, date('Y-m-d H:i:s')]);
	foreach ($lines as [$pid, $total]) {
		DB::execute('INSERT INTO order_detail (id_order,id_product,product_name,price,total,qty) VALUES (?,?,?,?,?,1)', [$id, $pid, 'Ürün ' . $pid, $total, $total]);
	}
	RvOrders::splitOrder(['id_order' => $id]);

	return ['id_order' => $id];
}

// ---------- 1. Migration ----------
section('Migration 008');
[$vA, $vB] = fresh();
$raw = file_get_contents(dirname(__DIR__) . '/migrations/008_commission_rules.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE)\b/i', $raw), '008 yıkıcı/veri değiştiren komut içermiyor');
ok(strpos($raw, 'CREATE TABLE IF NOT EXISTS `rafimda_commission_rules`') !== false, 'kural tablosu IF NOT EXISTS ile');
ok(substr_count($raw, 'ADD COLUMN') === 3 && stripos($raw, 'MODIFY') === false && stripos($raw, 'CHANGE') === false, 'mevcut tabloya yalnızca 3 yeni kolon eklenir (değiştirme yok)');
ok(RvCommission::tableReady(), 'tablo hazır');
ok(in_array('008_commission_rules', RvMigrator::files(), true), 'migration listede');

// ---------- 2. Öncelik ----------
section('Oran önceliği');
$vendA = RvVendor::get($vA);
$b = RvCommission::baseFor($vendA, 2);
ok(near($b['rate'], 10) && $b['source'] === 'default', 'kural yokken genel oran (%10)');
DB::update('rafimda_vendors', ['commission_rate' => 8], 'id_vendor = :v', ['v' => $vA]);
$vendA = RvVendor::get($vA);
ok(RvCommission::baseFor($vendA, 2)['source'] === 'vendor' && near(RvCommission::baseFor($vendA, 2)['rate'], 8), 'satıcıya özel oran genel orandan önce gelir');
ok(RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '12,5', 'include_children' => 1], 99)['success'], 'kategori kuralı (virgüllü oran) kaydedildi');
$b = RvCommission::baseFor($vendA, 1);
ok($b['source'] === 'category' && near($b['rate'], 12.5) && $b['id_category'] === 1, 'Elbise (100) → Kadın (10) → Giyim (1) üst kategori kuralı uygulanır');
ok(near(RvCommission::baseFor($vendA, 2)['rate'], 12.5), 'doğrudan kategori ürünü (Tişört/10) de Giyim kuralını alır');
RvCommission::saveRule(['scope' => 'category', 'id_target' => 10, 'rate' => '15', 'include_children' => 1], 99);
ok(near(RvCommission::baseFor($vendA, 1)['rate'], 15) && RvCommission::baseFor($vendA, 1)['id_category'] === 10, 'en yakın kategori kuralı kazanır');
ok(RvCommission::saveRule(['scope' => 'product', 'id_target' => 1, 'rate' => '5'], 99)['success'], 'ürün kuralı kaydedildi');
$b = RvCommission::baseFor($vendA, 1);
ok($b['source'] === 'product' && near($b['rate'], 5), 'ürün kuralı kategori kuralından önce gelir');
// alt kategorileri kapsamayan kural
RvCommission::saveRule(['scope' => 'category', 'id_target' => 3, 'rate' => '4'], 99); // include_children yok → 0
$vendB = RvVendor::get($vB);
ok(near(RvCommission::baseFor($vendB, 4)['rate'], 4), 'kapsamasız kural kendi kategorisindeki ürüne uygulanır (Kablo/3)');
ok(RvCommission::baseFor($vendB, 3)['source'] === 'default', 'kapsamasız kural alt kategoriye (Telefon/30) uygulanmaz');
ok(RvCommission::baseFor($vendB, 5)['source'] === 'default', 'kategorisiz ürün genel oranı alır');
// pasifleştirme
$rid = (int) DB::getValue("SELECT id_rule FROM rafimda_commission_rules WHERE scope='product' AND id_target=1");
ok(RvCommission::setActive($rid, false, 99) && RvCommission::baseFor($vendA, 1)['source'] === 'category', 'pasif ürün kuralı yok sayılır (kategori kuralına düşer)');
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_commission_rules WHERE id_rule = ?', [$rid]) === 1, 'pasifleştirme kaydı silmez');
RvCommission::setActive($rid, true, 99);
// döngü koruması
DB::execute('UPDATE categories SET id_parent = 31 WHERE id_category = 30');
DB::execute("INSERT INTO categories (id_category, category_name, active, id_parent) VALUES (31,'Döngü',1,30)");
ok(RvCommission::baseFor($vendB, 3)['source'] === 'default', 'kategori ağacında döngü sonsuz döngüye girmez');

section('Kural doğrulama');
ok(!RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '101'], 99)['success'], '100 üstü oran reddedilir');
ok(!RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '-1'], 99)['success'], 'negatif oran reddedilir');
ok(!RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => 'abc'], 99)['success'], 'sayı olmayan oran reddedilir');
ok(!RvCommission::saveRule(['scope' => 'brand', 'id_target' => 1, 'rate' => '5'], 99)['success'], 'bilinmeyen kapsam reddedilir');
ok(!RvCommission::saveRule(['scope' => 'product', 'id_target' => 999, 'rate' => '5'], 99)['success'], 'olmayan ürün reddedilir');
ok(RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '0', 'include_children' => 1, 'note' => '<script>x</script>Lansman'], 99)['success'], '%0 komisyon kuralı geçerli');
ok(DB::getValue("SELECT note FROM rafimda_commission_rules WHERE scope='category' AND id_target=1") === 'xLansman', 'not HTML\'den temizlenir');
ok((int) DB::getValue("SELECT COUNT(*) FROM rafimda_commission_rules WHERE scope='category' AND id_target=1") === 1, 'aynı hedef için ikinci kayıt açılmaz (güncellenir)');
ok((int) DB::getValue("SELECT COUNT(*) FROM rafimda_audit_log WHERE action = 'commission.rule'") >= 5, 'kural değişiklikleri denetim kaydına yazılır');
ok(count(RvCommission::rules()) === 4 && count(RvCommission::rules('product')) === 1, 'kural listesi ve filtre');
ok(RvCommission::rules('product')[0]['target_name'] === 'Elbise', 'listede hedef adı görünür');

// ---------- 3. Sipariş: kalem bazlı ----------
section('Kalem bazlı sipariş komisyonu');
[$vA, $vB] = fresh();
RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '12', 'include_children' => 1], 99);
RvCommission::saveRule(['scope' => 'product', 'id_target' => 2, 'rate' => '5'], 99);
order(50, [[1, 200.00], [2, 100.00], [3, 333.33], [4, 50.00]]);
$voA = DB::getRowSafe('rafimda_vendor_orders', 'id_order = 50 AND id_vendor = ?', [$vA]);
$voB = DB::getRowSafe('rafimda_vendor_orders', 'id_order = 50 AND id_vendor = ?', [$vB]);
ok($voA && $voB, 'sipariş iki satıcıya bölündü');
// A: Elbise 200*%12=24 + Tişört 100*%5=5 → 29 ; earning 271 ; ort. %9,67
ok(near($voA['subtotal'], 300) && near($voA['commission_amount'], 29) && near($voA['vendor_earning'], 271), 'A: 200×%12 + 100×%5 = 29 ₺ komisyon, 271 ₺ net');
ok(near($voA['commission_rate'], 9.67), 'A: ağırlıklı ortalama oran %9,67 olarak saklanır');
// B: Telefon 333.33*%10 = 33.33 ; Kablo 50*%10 = 5
ok(near($voB['commission_amount'], 38.33) && near($voB['vendor_earning'], 345), 'B: genel oranla kuruş yuvarlaması doğru (33,33 + 5,00)');
$items = DB::execute('SELECT id_product, commission_rate, commission_amount, commission_source FROM rafimda_vendor_order_items ORDER BY id_product') ?: [];
ok(count($items) === 4 && $items[0]['commission_source'] === 'category' && $items[1]['commission_source'] === 'product' && $items[2]['commission_source'] === 'default', 'her kalemde oran kaynağı saklanır');
ok(near($items[0]['commission_rate'], 12) && near($items[1]['commission_amount'], 5), 'kalem oranı/tutarı saklanır');
$sum = (float) DB::getValue('SELECT SUM(commission_amount) FROM rafimda_vendor_order_items WHERE id_vendor_order = ?', [$voA['id_vendor_order']]);
ok(near($sum, $voA['commission_amount']), 'kalem komisyonları toplamı sipariş komisyonuna eşit');
$sale = DB::getRowSafe('rafimda_ledger', 'ref_key = ?', ['sale:vo' . $voA['id_vendor_order']]);
ok($sale && near($sale['amount'], 271) && strpos($sale['note'], '29,00 ₺') !== false, 'deftere net kazanç yazılır; açıklamada komisyon tutarı');
// oran değişikliği eski siparişi değiştirmez
RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '30', 'include_children' => 1], 99);
ok(near(DB::getValue('SELECT commission_amount FROM rafimda_vendor_orders WHERE id_vendor_order = ?', [$voA['id_vendor_order']]), 29), 'kural değişikliği mevcut siparişi etkilemez');
order(51, [[1, 100.00]]);
ok(near(DB::getValue('SELECT commission_amount FROM rafimda_vendor_orders WHERE id_order = 51'), 30), 'yeni sipariş yeni oranla hesaplanır');
// idempotent
RvOrders::splitOrder(['id_order' => 50]);
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_order_items') === 5, 'tekrar bölme kalem çoğaltmaz');

section('008 uygulanmamış kurulumda geriye uyum');
rv_fresh_db();
foreach (RvMigrator::files() as $f) {
	if ($f === '008_commission_rules') { continue; }
	$GLOBALS['db']->exec(rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/migrations/' . $f . '.sql')));
	DB::execute('INSERT OR IGNORE INTO rafimda_migrations (version) VALUES (?)', [$f]);
}
RvCommission::reset();
Settings::$d = ['RAFIMDA_DEFAULT_COMMISSION' => '10'];
$v = RvVendor::create(1, RvVendor::validateStore(['store_name' => 'Eski Kurulum'])['clean'])['id_vendor'];
RvVendor::setStatus($v, 'active', 99);
DB::execute("INSERT INTO products (id_product, product_name, id_category, price, stock, active) VALUES (1,'X',1,100,1,1)");
DB::insert('rafimda_vendor_products', ['id_vendor' => $v, 'id_product' => 1, 'moderation' => 'approved']);
ok(!RvCommission::tableReady(), '008 yokken tablo hazır görünmez');
order(80, [[1, 100.00]]);
$vo = DB::getRowSafe('rafimda_vendor_orders', 'id_order = 80');
ok($vo && near($vo['commission_amount'], 10) && near($vo['vendor_earning'], 90), 'migration çalıştırılmadan önce de sipariş bölünür (genel oran, eski şema)');
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_order_items') === 1, 'kalem eski kolonlarla yazılır');
ok(!RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '5'], 99)['success'], 'migration yokken kural kaydı açık hata verir');
ok(RvCommission::rules() === [] && RvCommission::publicCategoryRules() === [], 'migration yokken listeler boş (hata yok)');
$GLOBALS['db']->exec(rv_mysql_to_sqlite($raw));
RvCommission::reset();
ok(RvCommission::tableReady(), '008 sonradan uygulanınca aynı süreçte görülür');
order(81, [[1, 100.00]]);
ok(DB::getValue('SELECT commission_source FROM rafimda_vendor_order_items WHERE id_order_detail = (SELECT MAX(id_order_detail) FROM order_detail)') === 'default', 'migration sonrası kalem kaynağı yazılır');
$st = RvCommission::vendorStatement($v, 30);
ok($st['orders'] === 2 && near($st['commission'], 20) && count($st['by_source']) === 1, 'eski (kaynaksız) kalemler kırılımda sayılmaz, toplamlarda sayılır');

// ---------- 4. İptal / iade ----------
section('İptal ve iade');
[$vA, $vB] = fresh();
RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '20', 'include_children' => 1], 99);
order(60, [[1, 100.00]]);
order(61, [[2, 50.00]]);
$vo60 = DB::getRowSafe('rafimda_vendor_orders', 'id_order = 60');
RvOrders::onCoreOrderUpdated(['id_order' => 60, 'status' => 5], 1);
$bal = RvLedger::balances($vA);
ok(DB::getValue('SELECT status FROM rafimda_vendor_orders WHERE id_order = 60') === 'cancelled', 'iptal edilen satıcı siparişi işaretlenir');
ok(near(DB::getValue("SELECT SUM(amount) FROM rafimda_ledger WHERE id_vendor_order = ?", [$vo60['id_vendor_order']]), 0), 'iptalde net kazanç tamamen geri alınır (komisyon tahsil edilmez)');
RvOrders::onCoreOrderUpdated(['id_order' => 60, 'status' => 6], 5);
ok((int) DB::getValue("SELECT COUNT(*) FROM rafimda_ledger WHERE ref_key LIKE 'refund:vo%'") === 1, 'iptal sonrası iade ikinci düzeltme yazmaz');
$st = RvCommission::vendorStatement($vA, 30);
ok($st['orders'] === 1 && near($st['gross'], 50) && near($st['commission'], 10), 'döküm: iptal edilen sipariş brüt/komisyona dahil değil');
ok($st['cancelled_orders'] === 1 && near($st['cancelled_gross'], 100) && near($st['cancelled_commission'], 20), 'döküm: iptal/iade ayrıca gösterilir');
RvLedger::adjustment($vA, -7.5, 'Kargo farkı', 99);
$st = RvCommission::vendorStatement($vA, 30);
ok(near($st['adjustments'], -7.5) && near($st['deductions'], 17.5) && near($st['net'], 32.5), 'döküm: kesinti = komisyon + eksi düzeltme; net = brüt − komisyon + düzeltme');
ok(near($st['effective_rate'], 20), 'döküm: etkin oran');
ok(isset($st['by_source'][0]) && $st['by_source'][0]['source'] === 'category' && $st['by_source'][0]['label'] === 'Kategori kuralı', 'döküm: oran kaynağına göre kırılım');
$bal = RvLedger::balances($vA);
ok(near($bal['pending'] + $bal['available'], $st['net']), 'defter bakiyesi (bekleyen + ödenebilir) döküm net kazancına eşit');

// ---------- 5. Kampanya üstüne ----------
section('Kampanya ile birleşim');
// Koşullu tanım: PHP koşulsuz sınıf bildirimlerini dosya başına taşır; kampanya yalnızca bu bölümden itibaren var olmalı
if (!class_exists('RcCampaign', false)) {
	class RcCampaign {
		public static int $calls = 0; public static array $flags = [];
		public static function rateFor(array $v, float $base, bool $forOrder = true): float { self::$calls++; self::$flags[] = $forOrder; return min($base, 3.0); }
		public static function commitUse(int $id): void {}
		public static function discardUse(int $id): void {}
	}
}
[$vA, $vB] = fresh();
RvCommission::saveRule(['scope' => 'category', 'id_target' => 1, 'rate' => '12', 'include_children' => 1], 99);
order(70, [[1, 100.00], [2, 100.00]]);
$vo = DB::getRowSafe('rafimda_vendor_orders', 'id_order = 70');
ok(near($vo['commission_amount'], 6), 'kampanya oranı (%3) kural oranının üstüne uygulanır');
ok(RcCampaign::$flags === [true, false], 'kampanya katılım/sayaç yalnızca ilk kalemde işaretlenir');
ok(DB::getValue('SELECT commission_source FROM rafimda_vendor_order_items LIMIT 1') === 'campaign', 'kaynak: kampanya');
RcCampaign::$flags = [];
$calc = RvCommission::forLines(RvVendor::get($vA), [['id_product' => 1, 'total' => 10]], false);
ok(RcCampaign::$flags === [false], 'gösterim hesabı kampanyaya katılım yapmaz');

// ---------- 6. Yönetici raporu ----------
section('Yönetici komisyon raporu');
$r = RvCommission::platformReport(30);
ok($r['orders'] === 1 && near($r['gross'], 200) && near($r['commission'], 6) && near($r['vendor_earning'], 194), 'toplamlar');
ok(near($r['effective_rate'], 3), 'etkin oran');
ok(count($r['by_vendor']) === 1 && $r['by_vendor'][0]['store_name'] === 'Moda Evi', 'satıcıya göre');
ok(count($r['by_category']) === 2, 'kategoriye göre (Kadın, Giyim)');
ok($r['by_source'][0]['source'] === 'campaign', 'kaynağa göre');
DB::execute("UPDATE rafimda_vendor_orders SET date_add = '2020-01-01 00:00:00'");
ok(RvCommission::platformReport(30)['orders'] === 0, 'dönem filtresi eski siparişleri dışlar');

// ---------- 7. Ücretsiz mağaza modu ----------
section('Ücretsiz mağaza (paket ücreti yok)');
[$vA, $vB] = fresh();
ok(RvPlans::freeMode(), 'varsayılan: ücretsiz mağaza modu açık');
$r = RvPlans::save(['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '499', 'active' => 1]);
ok(!$r['success'] && mb_stripos($r['message'], 'ücretsiz') !== false, 'ücretsiz modda aylık ücretli paket kaydedilemez');
ok(RvPlans::save(['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '0', 'max_products' => '', 'active' => 1])['success'], 'ücretsiz modda 0 ₺ paket (limit grubu) kaydedilebilir');
DB::execute("UPDATE rafimda_plans SET price_monthly = 250 WHERE code = 'pro'");
$all = RvPlans::all(true);
$pro = array_values(array_filter($all, static fn($p) => $p['code'] === 'pro'))[0] ?? null;
ok($pro && near($pro['price_monthly'], 0), 'ücretsiz modda eski ücretli kayıtlar 0 ₺ gösterilir (veri silinmez)');
ok(near(DB::getValue("SELECT price_monthly FROM rafimda_plans WHERE code = 'pro'"), 250), 'veritabanındaki eski değer korunur');
Settings::set('RAFIMDA_PAID_PLANS', '1');
ok(!RvPlans::freeMode() && RvPlans::save(['code' => 'pro2', 'name' => 'Pro 2', 'price_monthly' => '99', 'active' => 1])['success'], 'anahtar açılırsa (yazılı onayla) ücretli paket yeniden tanımlanabilir');
Settings::set('RAFIMDA_PAID_PLANS', '0');
ok(strpos(RvPlans::checkProductQuota($vA, 1)['message'], 'paket') === false, 'limit mesajı ücretli paket önermez');
DB::execute("UPDATE rafimda_plans SET max_products = 0 WHERE code = 'free'");
$q = RvPlans::checkProductQuota($vA, 1);
ok(!$q['ok'] && strpos($q['message'], 'ücretsiz') !== false, 'limit dolunca: limit artırımı ücretsiz olarak yönetimden istenir');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
if ($fail) { echo "Başarısız:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
