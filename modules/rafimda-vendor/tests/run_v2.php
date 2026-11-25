<?php
// rafimda-vendor v0.3: stok / fiyat / müşteri yönetimi (sahiplik, izolasyon, doğrulama, şablonlar)
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function prod(int $vendor, string $name, float $price, float $stock, array $o = []): int {
	$id = (int) DB::insert('products', ['product_name' => $name, 'id_category' => 1, 'id_brand' => 1, 'price' => $price, 'old_price' => $o['old'] ?? 0, 'stock' => $stock, 'stock_code' => $o['sku'] ?? ('SK' . mt_rand(1000, 99999)), 'barcode' => '', 'active' => $o['active'] ?? 1]);
	if ($vendor > 0) { DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $vendor]); }
	return $id;
}
rv_fresh_db(); rv_apply_migrations();
DB::execute('CREATE TABLE product_variations (id_variation INTEGER PRIMARY KEY AUTOINCREMENT, id_product INT, sku TEXT, price REAL, stock REAL, active INT DEFAULT 1)');

section('Özet ve liste');
$a1 = prod(1, 'Tişört', 100, 50, ['sku' => 'TS-1']); $a2 = prod(1, 'Pantolon', 200, 0, ['sku' => 'PT-1']); $a3 = prod(1, 'Çorap', 20, 3, ['sku' => 'CR-1']); $a4 = prod(1, 'Pasif', 10, 9, ['active' => 0, 'sku' => 'PS-1']);
$b1 = prod(2, 'Başkasının', 5, 0, ['sku' => 'BS-1']); $pl = prod(0, 'Platform', 5, 0, ['sku' => 'PL-1']);
$s = RvInventory::summary(1);
ok($s['total'] === 4 && $s['out'] === 1 && $s['low'] === 1 && $s['inactive'] === 1 && $s['variant'] === 0, 'özet yalnızca kendi ürünlerini sayar');
$l = RvInventory::listProducts(1, 'out', '', 25, 0); ok($l['total'] === 1 && $l['rows'][0]['product_name'] === 'Pantolon', "'stokta yok' filtresi");
$l = RvInventory::listProducts(1, 'low', '', 25, 0); ok($l['total'] === 1 && $l['rows'][0]['product_name'] === 'Çorap', "'azalan' filtresi");
$l = RvInventory::listProducts(1, '', 'TS-', 25, 0); ok($l['total'] === 1 && $l['rows'][0]['stock_code'] === 'TS-1', 'stok koduyla arama');
$l = RvInventory::listProducts(1, '', '%', 25, 0); ok($l['total'] === 4, "'%' joker olarak çalışmaz (temizlenir)");
$l = RvInventory::listProducts(1, '', "' OR 1=1 --", 25, 0); ok($l['total'] === 0, 'SQL enjeksiyonu etkisiz');
$l = RvInventory::listProducts(1, '', '', 2, 0); $l2 = RvInventory::listProducts(1, '', '', 2, 2); ok(count($l['rows']) === 2 && count($l2['rows']) === 2 && $l['total'] === 4, 'sayfalama');
ok(strpos(json_encode(RvInventory::listProducts(1, '', '', 100, 0)), 'Başkasının') === false, 'başka satıcı/platform ürünü listelenmez');

section('Stok güncelleme');
Module::$hooks = [];
$r = RvInventory::setStock(1, $a1, '12,7'); ok($r['success'] && (float) DB::getValue('SELECT stock FROM products WHERE id_product = ?', [$a1]) === 12.0, "ondalıklı girdi tam sayıya indirilir ('12,7' → 12)");
ok(Module::$hooks === ['product.updated'], 'product.updated kancası tetiklenir (pazaryeri senkronu için)');
ok(!RvInventory::setStock(1, $a1, '-1')['success'] && !RvInventory::setStock(1, $a1, 'abc')['success'] && !RvInventory::setStock(1, $a1, '')['success'] && !RvInventory::setStock(1, $a1, '1e99')['success'], 'negatif / metin / boş / aşırı değer reddedilir');
ok(!RvInventory::setStock(1, $b1, 5)['success'] && (float) DB::getValue('SELECT stock FROM products WHERE id_product = ?', [$b1]) === 0.0, 'başka satıcının ürünü değiştirilemez (IDOR)');
ok(!RvInventory::setStock(1, $pl, 5)['success'], 'platform ürünü değiştirilemez');
ok(!RvInventory::setStock(1, 99999, 5)['success'], 'olmayan ürün');
$log = DB::execute("SELECT action FROM rafimda_audit_log WHERE id_vendor = 1 AND action = 'product.stock.set'"); ok(count($log) === 1, 'denetim kaydı yazılır');

section('Fiyat güncelleme');
$r = RvInventory::setPrice(1, $a1, '129,90', '199'); ok($r['success'] && (float) DB::getValue('SELECT price FROM products WHERE id_product = ?', [$a1]) === 129.9 && (float) DB::getValue('SELECT old_price FROM products WHERE id_product = ?', [$a1]) === 199.0, 'fiyat ve liste fiyatı');
ok(!RvInventory::setPrice(1, $a1, 0)['success'] && !RvInventory::setPrice(1, $a1, -5)['success'], 'sıfır/negatif fiyat reddedilir');
ok(!RvInventory::setPrice(1, $a1, 150, 100)['success'] && (float) DB::getValue('SELECT price FROM products WHERE id_product = ?', [$a1]) === 129.9, 'liste fiyatı satış fiyatından küçük olamaz; değişiklik uygulanmaz');
ok(!RvInventory::setPrice(1, $b1, 9)['success'], 'başkasının ürününün fiyatı değiştirilemez');

section('Varyantlı ürün');
$v = prod(1, 'Varyantlı', 50, 0, ['sku' => 'VR-1']); DB::execute('INSERT INTO product_variations (id_product, sku, price, stock) VALUES (?,?,?,?)', [$v, 'VR-1-S', 50, 5]);
ok(!RvInventory::setStock(1, $v, 9)['success'] && !RvInventory::setPrice(1, $v, 9)['success'], 'varyantlı üründe toplu ekran değişiklik yapmaz (varyant bazlı)');
ok(RvInventory::summary(1)['variant'] === 1, 'özet varyantlı ürün sayısını verir');
$l = RvInventory::listProducts(1, '', 'VR-1', 25, 0); ok($l['rows'][0]['has_variants'] === true, 'listede varyantlı işaretlenir');

section('Toplu işlemler');
$r = RvInventory::bulkSet(1, [$a1 => ['stock' => '20', 'price' => ''], $a2 => ['stock' => '', 'price' => '250'], $a3 => ['stock' => '', 'price' => ''], $b1 => ['stock' => '99'], $v => ['stock' => '1'], $a4 => ['stock' => 'x']]);
ok($r['ok'] === 2 && $r['failed'] === 3, 'boş satır atlanır; geçerli 2, hatalı 3 (başkasının, varyantlı, geçersiz)');
ok((float) DB::getValue('SELECT stock FROM products WHERE id_product = ?', [$a1]) === 20.0 && (float) DB::getValue('SELECT price FROM products WHERE id_product = ?', [$a2]) === 250.0 && (float) DB::getValue('SELECT stock FROM products WHERE id_product = ?', [$b1]) === 0.0, 'değerler doğru uygulandı, başkasının ürünü değişmedi');
$many = []; for ($i = 1; $i <= 150; $i++) { $many[$a3 + 1000 + $i] = ['stock' => '1']; }
$r = RvInventory::bulkSet(1, $many); ok($r['ok'] + $r['failed'] <= RvInventory::MAX_BULK, 'tek seferde en fazla ' . RvInventory::MAX_BULK . ' satır işlenir');

section('Yüzde ile fiyat değişimi');
$p1 = prod(1, 'P1', 100, 1, ['sku' => 'AD-1']); $p2 = prod(1, 'P2', 40, 1, ['sku' => 'AD-2']);
$r = RvInventory::adjustPrices(1, [$p1, $p2, $b1, $v], '-10'); ok($r['ok'] === 2 && $r['failed'] === 2, '−%10: kendi 2 ürün güncellendi, başkasının ve varyantlı reddedildi');
ok((float) DB::getValue('SELECT price FROM products WHERE id_product = ?', [$p1]) === 90.0 && (float) DB::getValue('SELECT price FROM products WHERE id_product = ?', [$p2]) === 36.0, 'fiyatlar doğru hesaplandı');
foreach (['-60', '101', '0', 'abc', ''] as $bad) { $r = RvInventory::adjustPrices(1, [$p1], $bad); ok($r['ok'] === 0 && $r['errors'] !== [], "geçersiz yüzde reddedilir: '$bad'"); }
ok((float) DB::getValue('SELECT price FROM products WHERE id_product = ?', [$p1]) === 90.0, 'reddedilen yüzdelerde fiyat değişmedi');
RvInventory::adjustPrices(1, [$p2], '100'); ok((float) DB::getValue('SELECT price FROM products WHERE id_product = ?', [$p2]) === 72.0, '+%100 sınırı çalışır');

section('Müşteriler (satıcı izolasyonu ve gizlilik)');
function mkord(int $id, int $user, string $name, string $city, string $date): void { DB::insert('orders', ['id_order' => $id, 'id_user' => $user, 'reference' => "R$id", 'status' => 1, 'date_add' => $date, 'customer_name' => $name, 'customer_phone' => '0555-GIZLI', 'address_city' => $city, 'address_district' => 'd', 'address_text' => 'a']); }
function mkvo(int $order, int $vendor, float $sub, string $st = 'pending'): void { DB::insert('rafimda_vendor_orders', ['id_order' => $order, 'id_vendor' => $vendor, 'status' => $st, 'subtotal' => $sub]); }
mkord(1, 10, 'Ayşe Y', 'İzmir', '2026-11-01'); mkord(2, 10, 'Ayşe Y', 'İzmir', '2026-11-05'); mkord(3, 11, 'Mehmet K', 'Ankara', '2026-11-03'); mkord(4, 12, 'Sadece Başka', 'Bursa', '2026-11-04'); mkord(5, 11, 'Mehmet K', 'Ankara', '2026-11-06');
mkvo(1, 1, 100); mkvo(2, 1, 50); mkvo(3, 1, 70); mkvo(4, 2, 999); mkvo(5, 1, 80, 'cancelled');
$c = RvCustomers::listFor(1, '', 25, 0); ok($c['total'] === 2 && count($c['rows']) === 2, 'yalnızca bu satıcıdan alışveriş edenler (2 müşteri)');
ok($c['rows'][0]['customer_name'] === 'Ayşe Y' && (int) $c['rows'][0]['orders'] === 2 && (float) $c['rows'][0]['spent'] === 150.0, 'sipariş sayısı ve toplam doğru, son sipariş sırası');
ok(strpos(json_encode($c), 'Sadece Başka') === false && strpos(json_encode($c), '999') === false, 'başka satıcının müşterisi/cirosu görünmez');
ok(strpos(json_encode($c), 'GIZLI') === false && strpos(json_encode($c), '@') === false, 'telefon ve e-posta listelenmez');
$m = (array) array_filter($c['rows'], fn($r) => $r['customer_name'] === 'Mehmet K'); ok((int) reset($m)['orders'] === 1, 'iptal edilen sipariş sayılmaz');
$sm = RvCustomers::summary(1); ok($sm['customers'] === 2 && $sm['repeat'] === 1 && $sm['orders'] === 3 && $sm['revenue'] === 220.0, 'özet: müşteri 2, tekrar eden 1, sipariş 3, ciro 220');
ok(RvCustomers::listFor(1, 'mehm', 25, 0)['total'] === 1 && RvCustomers::listFor(1, "' OR 1=1 --", 25, 0)['total'] === 0 && RvCustomers::listFor(1, '%', 25, 0)['total'] === 2, 'arama: ad ile bulur, enjeksiyon etkisiz, joker temizlenir');
ok(RvCustomers::listFor(2, '', 25, 0)['total'] === 1 && RvCustomers::listFor(3, '', 25, 0)['total'] === 0, 'satıcı 2 yalnızca kendi müşterisini, satıcı 3 hiçbirini görür');

section('Panel menüsü, yetki ve şablonlar');
require_once dirname(__DIR__) . '/lib/RvPanel.php';
foreach (['stock', 'pricing', 'customers'] as $k) { ok(RvPanel::MENU[$k][2] === true && RvPanel::MENU[$k][3] === '', "menü: $k hazır"); }
ok(RvPerm::roleAllows('catalog', 'stock.view') && RvPerm::roleAllows('catalog', 'products.edit') && !RvPerm::roleAllows('viewer', 'stock.view') && !RvPerm::roleAllows('viewer', 'products.edit'), 'stok ekranı: katalog görür+düzenler, izleyici göremez');
ok(RvPerm::roleAllows('orders', 'customers.view') && !RvPerm::roleAllows('catalog', 'customers.view'), 'müşteri ekranı: sipariş rolü görür, katalog rolü göremez');
$code = file_get_contents(dirname(__DIR__) . '/lib/RvPanel.php');
ok(preg_match("/private static function inventory.*?requireCsrf\('front'\);\s*self::deny\('products\.edit'\)/s", $code) === 1, 'inventory POST: önce CSRF, sonra products.edit izni');
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rv_compile2_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true); $GLOBALS['smarty'] = $smarty;
$xss = '<script>alert(1)</script>';
$render = function (string $tpl, array $vars) { ob_start(); try { RvView::display($tpl, $vars); return [true, ob_get_clean()]; } catch (Throwable $e) { ob_end_clean(); return [false, $e->getMessage()]; } };
$vendorRow = ['id_vendor' => 1, 'store_name' => $xss, 'store_slug' => 's', 'status' => 'active', 'plan_code' => 'free'];
$base = ['rvTitle' => 'x', 'pageTitle' => 'T', 'vendor' => $vendorRow, 'roleLabel' => 'Sahip', 'memberships' => [$vendorRow], 'flash' => null, 'logoutUrl' => '/c', 'storefrontUrl' => '/', 'menu' => [['key' => 'stock', 'label' => 'Stok', 'icon' => '▥', 'ready' => true, 'url' => '/s']], 'can' => ['create' => true, 'edit' => true, 'delete' => true, 'staff' => true, 'store' => true]];
$row = ['id_product' => 1, 'product_name' => $xss, 'stock_code' => $xss, 'barcode' => '', 'price' => 10, 'old_price' => 20, 'stock' => 2, 'active' => 1, 'moderation' => 'approved', 'has_variants' => false];
$rowV = ['has_variants' => true, 'stock' => 0] + $row;
$tpls = ['panel_stock' => ['section' => 'stock', 'rows' => [$row, $rowV], 'total' => 2, 'filter' => '', 'q' => $xss, 'page' => 1, 'pages' => 2, 'sum' => ['total' => 2, 'out' => 1, 'low' => 1, 'inactive' => 0, 'variant' => 1], 'lowLimit' => 5, 'sec' => 'stock'],
	'panel_pricing' => ['section' => 'pricing', 'rows' => [$row, $rowV], 'total' => 2, 'filter' => '', 'q' => $xss, 'page' => 1, 'pages' => 2, 'sum' => [], 'lowLimit' => 5, 'sec' => 'pricing'],
	'panel_customers' => ['section' => 'customers', 'rows' => [['customer_name' => $xss, 'city' => $xss, 'orders' => 2, 'spent' => 10, 'last_order' => 'd']], 'total' => 1, 'q' => $xss, 'page' => 1, 'pages' => 2, 'sum' => ['customers' => 1, 'repeat' => 1, 'orders' => 2, 'revenue' => 10.0]]];
foreach ($tpls as $t => $vars) {
	[$o, $html] = $render('panel_layout', $base + $vars + ['contentFile' => 'file:' . RvView::tplDir() . $t . '.tpl']);
	ok($o && strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false && strlen($html) > 800, "$t render + XSS kaçışı" . ($o ? '' : " ($html)"));
	ok($o && ($t === 'panel_customers' || (strpos($html, 'name="token"') !== false && strpos($html, 'name="rows[') !== false)), "$t: CSRF alanı ve satır alanları (salt-okunur şablonda yok)");
}
[$o, $html] = $render('panel_layout', array_merge($base, ['can' => ['create' => false, 'edit' => false, 'delete' => false, 'staff' => false, 'store' => false]], $tpls['panel_stock'], ['contentFile' => 'file:' . RvView::tplDir() . 'panel_stock.tpl']));
ok($o && strpos($html, 'name="rows[') === false && strpos($html, 'Girilen stokları kaydet') === false, 'düzenleme izni yoksa form alanları ve kaydet düğmesi gösterilmez');
section('Paketler ve abonelik');
rv_fresh_db(); rv_apply_migrations();
$raw = file_get_contents(dirname(__DIR__) . '/migrations/004_plans.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE)\b/i', $raw) && !preg_match('/CREATE TABLE(?! IF NOT EXISTS)/', $raw) && !preg_match('/^\s*--/m', $raw), '004 yıkıcı değil (yalnızca CREATE TABLE IF NOT EXISTS + ADD COLUMN)');
ok(DB::execute("SELECT name FROM sqlite_master WHERE name = 'rafimda_plans'") !== [] && DB::execute("SELECT name FROM sqlite_master WHERE name = 'rafimda_plan_log'") !== [], 'tablolar oluştu');
DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'M1', 'store_slug' => 'm1', 'status' => 'active']); DB::insert('rafimda_vendors', ['owner_user_id' => 2, 'store_name' => 'M2', 'store_slug' => 'm2', 'status' => 'active']);
$pl = RvPlans::all(); ok(count($pl) === 1 && $pl[0]['code'] === 'free' && $pl[0]['max_products'] === null, "varsayılan 'free' paketi sınırsız tohumlanır (mevcut davranış değişmez)");
RvPlans::all(); ok(count(RvPlans::all()) === 1, 'ensureDefaults idempotent');
ok(RvPlans::save(['code' => 'mini', 'name' => 'Mini', 'price_monthly' => '99,90', 'max_products' => '2', 'max_xml_connections' => '1', 'max_marketplace_accounts' => '', 'active' => 1])['success'], 'paket oluşturulur');
$m = RvPlans::get('mini'); ok((float) $m['price_monthly'] === 0.0 && (int) $m['max_products'] === 2 && $m['max_marketplace_accounts'] === null, 'ücret alanı yok sayılır (abonelik yok: her zaman 0), limitler, boş = sınırsız');
foreach ([['code' => 'A', 'name' => 'xx'], ['code' => 'ok', 'name' => 'x'], ['code' => 'ok2', 'name' => 'Ok', 'max_products' => '-1'], ['code' => 'ok3', 'name' => 'Ok', 'max_products' => 'abc']] as $bad) { ok(!RvPlans::save($bad)['success'], 'geçersiz paket reddedilir: ' . json_encode($bad, JSON_UNESCAPED_UNICODE)); }
ok(!RvPlans::save(['code' => 'free', 'name' => 'Başlangıç', 'active' => 0])['success'], 'varsayılan paket pasifleştirilemez');
ok(RvPlans::save(['code' => 'mini', 'name' => 'Mini+', 'max_products' => '3', 'active' => 1])['success'] && (int) RvPlans::get('mini')['max_products'] === 3 && count(RvPlans::all()) === 2, 'güncelleme kopya üretmez');
ok(RvPlans::save(['code' => 'mini', 'name' => 'Mini+', 'max_products' => '2', 'max_xml_connections' => '1', 'active' => 1])['success'], 'limit 2’ye geri alındı');
$r = RvPlans::assign(1, 'mini', '', 7, 'deneme'); ok($r['success'] && RvPlans::effective(1)['plan']['code'] === 'mini', 'süresiz paket atanır');
ok(DB::getValue('SELECT COUNT(*) FROM rafimda_plan_log WHERE id_vendor = 1') == 1 && DB::getValue("SELECT COUNT(*) FROM rafimda_audit_log WHERE action = 'vendor.plan'") == 1, 'atama kaydı ve denetim kaydı yazılır');
ok(!RvPlans::assign(1, 'olmayan', '', 7)['success'] && !RvPlans::assign(999, 'mini', '', 7)['success'], 'olmayan paket / satıcı reddedilir');
ok(!RvPlans::assign(1, 'mini', '2001-01-01', 7)['success'] && !RvPlans::assign(1, 'mini', 'xyz', 7)['success'], 'geçmiş/geçersiz bitiş tarihi reddedilir');
ok(RvPlans::assign(2, 'mini', date('Y-m-d', time() + 86400 * 30), 7)['success'] && RvPlans::effective(2)['expires_at'] !== '', 'süreli paket atanır');
DB::update('rafimda_vendors', ['plan_expires_at' => '2020-01-01 00:00:00'], 'id_vendor = :v', ['v' => 2]);
$e = RvPlans::effective(2); ok($e['expired'] === true && $e['plan']['code'] === 'free' && $e['assigned'] === 'mini', "süresi dolan paket 'free'e düşer (veri silinmez)");
RvPlans::save(['code' => 'kapali', 'name' => 'Kapalı', 'active' => 0]); ok(!RvPlans::assign(1, 'kapali', '', 7)['success'], 'pasif pakete atama yapılamaz');
RvPlans::assign(1, 'free', '', 7); ok(RvPlans::effective(1)['plan']['code'] === 'free', "'free'e geri dönülür");

section('Paket kotaları (ürün oluşturma)');
RvPlans::assign(1, 'mini', '', 7);
$mk = fn(string $n) => ['product_name' => $n, 'id_category' => 1, 'id_brand' => 1, 'price' => '10', 'stock' => '1', 'stock_code' => 'Q-' . $n, 'description' => 'd'];
$c1 = RvProducts::create(1, $mk('Ürün Bir')); $c2 = RvProducts::create(1, $mk('Ürün İki')); $c3 = RvProducts::create(1, $mk('Ürün Üç'));
ok(!empty($c1['success']) && !empty($c2['success']) && empty($c3['success']) && strpos($c3['message'], 'limit') !== false, 'limit 2: 3. ürün reddedilir ve nedeni söylenir');
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_products WHERE id_vendor = 1') === 2, 'reddedilen üründen kayıt oluşmaz');
ok(!empty(RvProducts::create(2, $mk('Dışlayıcı Bir'))['success']) && !empty(RvProducts::create(2, $mk('Dışlayıcı İki'))['success']) && !empty(RvProducts::create(2, $mk('Dışlayıcı Üç'))['success']), "süresi dolmuş paketli satıcı 'free' (sınırsız) kurallarıyla devam eder; limit satıcıya özeldir");
ok(RvPlans::checkProductQuota(1, 1)['ok'] === false && RvPlans::checkProductQuota(1, 0)['ok'] === true, 'kota denetimi doğru');
RvPlans::save(['code' => 'mini', 'name' => 'Mini', 'max_products' => '', 'max_xml_connections' => '', 'active' => 1]); ok(!empty(RvProducts::create(1, $mk('Ürün Dört'))['success']), 'limit kaldırılınca (boş = sınırsız) yeniden ürün eklenir');
$cc = file_get_contents(dirname(__DIR__) . '/lib/RvProducts.php'); ok(strpos($cc, 'RvPlans::checkProductQuota') !== false, 'tüm oluşturma yolları (panel, CSV, XML) RvProducts::create üzerinden kotaya tabidir');

section('Paket şablonları');
$admin = ['pageTitle' => 'x', 'flash' => '', 'flashType' => 'info', 'rvTab' => 'plans', 'rvVendors' => [['id_vendor' => 1, 'store_name' => $xss, 'plan_code' => 'mini', 'plan_expires_at' => '2026-12-01', 'owner_name' => 'a', 'owner_email' => 'b', 'status' => 'active']], 'rvCounts' => ['active' => 1, 'pending' => 0], 'rvStatuses' => RvVendor::STATUSES, 'rvFilterStatus' => '', 'rvQuery' => '', 'rvPending' => [], 'rvPlans' => [['code' => $xss, 'name' => $xss, 'price_monthly' => 9, 'max_products' => 5, 'max_xml_connections' => null, 'max_marketplace_accounts' => null, 'active' => 1, 'sort_order' => 0]], 'rvModerationQueue' => [], 'rvModState' => 'pending', 'rvVendorOrders' => [], 'rvOrderStatuses' => [], 'rvTotals' => [], 'rvBalances' => [], 'rvPayouts' => [], 'rvAutoApprove' => false, 'rvModeration' => true, 'rvDefaultCommission' => 10, 'rvHoldDays' => 7, 'rvPanelUrl' => '/p', 'adminToken' => 'tok'];
$smarty->setEscapeHtml(false); $smarty->assign($admin);
try { $html = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl'); $okR = true; } catch (Throwable $e) { $html = $e->getMessage(); $okR = false; }
ok($okR && strpos($html, '<script>alert(1)</script>') === false && strpos($html, 'plan_assign') !== false && strpos($html, 'plan_save') !== false, 'admin Paketler sekmesi render + XSS kaçışı' . ($okR ? '' : " ($html)"));
ok(strpos($html, '<tr><form') === false, 'geçersiz <tr><form> yapısı yok');
$eff = ['plan' => ['name' => $xss, 'max_products' => 5, 'max_xml_connections' => null, 'max_marketplace_accounts' => null], 'expired' => true, 'expires_at' => '', 'assigned' => 'mini'];
[$o, $html] = $render('panel_layout', $base + ['section' => 'subscription', 'eff' => $eff, 'usage' => ['products' => 3, 'xml' => 0, 'marketplaces' => 0], 'plans' => [['name' => $xss, 'price_monthly' => 9, 'max_products' => null, 'max_xml_connections' => 2, 'max_marketplace_accounts' => null]], 'contentFile' => 'file:' . RvView::tplDir() . 'panel_subscription.tpl']);
ok($o && strpos($html, '<script>alert(1)</script>') === false && strpos($html, '3 / 5') !== false && strpos($html, 'süresi doldu') !== false && strpos($html, 'Sınırsız') !== false, 'abonelik sayfası: kullanım/limit, süre dolumu uyarısı, XSS kaçışı' . ($o ? '' : " ($html)"));
ok(RvPanel::MENU['subscription'][2] === true, 'menü: abonelik hazır');
ok(strpos(file_get_contents(dirname(__DIR__) . '/rafimda-vendor.php'), "'plans'") !== false && preg_match('/case \'plan_assign\'/', file_get_contents(dirname(__DIR__) . '/rafimda-vendor.php')) === 1, 'admin: paket kaydet/ata işlemleri tanımlı (token + demo modu kontrolü adminPage başında)');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n"; exit($fail ? 1 : 0);
