<?php
// rafimda-vendor v0.4: ayarlar (tercihler), raporlar, menü bütünlüğü (her menü öğesi gerçek bir ekrana gider)
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/RvPanel.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function prod(int $vendor, string $name, float $stock): int { $id = (int) DB::insert('products', ['product_name' => $name, 'id_category' => 1, 'id_brand' => 1, 'price' => 10, 'old_price' => 0, 'stock' => $stock, 'stock_code' => 'S' . mt_rand(1000, 99999), 'barcode' => '', 'active' => 1]); DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $vendor]); return $id; }
rv_fresh_db(); rv_apply_migrations();
DB::execute('CREATE TABLE product_variations (id_variation INTEGER PRIMARY KEY AUTOINCREMENT, id_product INT, sku TEXT, price REAL, stock REAL, active INT DEFAULT 1)');

section('Tercihler');
ok(RvPrefs::get(1) === ['low_stock' => 5, 'default_cargo' => ''], 'kayıt yokken varsayılanlar');
ok(RvPrefs::save(1, ['low_stock' => '12', 'default_cargo' => '<b>Yurtiçi</b> Kargo'])['success'] && RvPrefs::get(1) === ['low_stock' => 12, 'default_cargo' => 'Yurtiçi Kargo'], 'kaydedilir, HTML temizlenir');
ok(RvPrefs::save(1, ['low_stock' => '3', 'default_cargo' => ''])['success'] && RvPrefs::get(1)['low_stock'] === 3 && (int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_prefs') === 1, 'güncelleme tek satırda (mükerrer yok)');
foreach (['', '-1', 'abc', '1001', '1.5', ' '] as $bad) { ok(!RvPrefs::save(1, ['low_stock' => $bad])['success'], 'geçersiz eşik reddedilir: ' . json_encode($bad)); }
ok(RvPrefs::get(2)['low_stock'] === 5, 'tercihler satıcıya özeldir (izolasyon)');
$GLOBALS['db']->exec('ALTER TABLE rafimda_vendor_prefs RENAME TO x_prefs');
ok(RvPrefs::get(1)['low_stock'] === 5 && !RvPrefs::save(1, ['low_stock' => '4'])['success'], 'tablo yoksa varsayılan döner, kayıt güvenle hata verir');
$GLOBALS['db']->exec('ALTER TABLE x_prefs RENAME TO rafimda_vendor_prefs');

section('Düşük stok eşiği envantere uygulanır');
$p1 = prod(1, 'A', 2); $p2 = prod(1, 'B', 8); $p3 = prod(1, 'C', 0);
RvPrefs::save(1, ['low_stock' => '5', 'default_cargo' => '']);
ok(RvInventory::summary(1)['low'] === 1 && RvInventory::listProducts(1, 'low', '', 25, 0)['total'] === 1, 'eşik 5: yalnızca A azalan');
RvPrefs::save(1, ['low_stock' => '10', 'default_cargo' => '']);
ok(RvInventory::summary(1)['low'] === 2 && RvInventory::listProducts(1, 'low', '', 25, 0)['total'] === 2, 'eşik 10: A ve B azalan');
ok(RvProducts::stats(1)['low_stock'] === 3, 'Genel Bakış sayacı eşiği kullanır (stoksuz dahil)');
$q = prod(2, 'Z', 1); ok(RvInventory::summary(2)['low'] === 1 && RvInventory::summary(1)['low'] === 2, 'eşik yalnızca kendi satıcısını etkiler');

section('Raporlar');
$vid = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'R', 'store_slug' => 'r', 'status' => 'active']);
$vid2 = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 2, 'store_name' => 'R2', 'store_slug' => 'r2', 'status' => 'active']);
$mk = function (int $v, string $st, float $sub, string $date, string $name, int $qty) use ($vid) { $o = (int) DB::insert('orders', ['id_user' => 1, 'status' => 1]); $vo = (int) DB::insert('rafimda_vendor_orders', ['id_order' => $o, 'id_vendor' => $v, 'subtotal' => $sub, 'commission_rate' => 10, 'commission_amount' => $sub / 10, 'vendor_earning' => $sub * 0.9, 'status' => $st, 'date_add' => $date]); DB::insert('rafimda_vendor_order_items', ['id_vendor_order' => $vo, 'id_order_detail' => $o, 'id_product' => crc32($name) % 1000, 'product_name' => $name, 'qty' => $qty, 'unit_price' => $sub / $qty, 'total' => $sub]); };
$today = date('Y-m-d H:i:s'); $old = date('Y-m-d H:i:s', time() - 40 * 86400);
$mk($vid, 'delivered', 100, $today, 'Tişört', 2); $mk($vid, 'pending', 300, $today, 'Pantolon', 1); $mk($vid, 'cancelled', 999, $today, 'İptal ürün', 1); $mk($vid, 'returned', 888, $today, 'İade ürün', 1); $mk($vid, 'delivered', 50, $old, 'Eski', 1); $mk($vid2, 'delivered', 7777, $today, 'Başkası', 1);
$r = RvReports::summary($vid, 30);
ok($r['orders'] === 2 && $r['sales'] == 400.0 && $r['commission'] == 40.0 && $r['earning'] == 360.0 && $r['avg'] == 200.0, 'iptal/iade ve 30 günden eski sipariş hariç; toplamlar doğru');
ok(count($r['daily']) === 30 && $r['daily'][29]['day'] === date('Y-m-d') && $r['daily'][29]['sales'] == 400.0 && $r['daily'][0]['sales'] == 0.0, 'günlük seri 30 gün, bugün 400 ₺');
ok(($r['status']['cancelled'] ?? 0) === 1 && ($r['status']['delivered'] ?? 0) === 1 && ($r['status']['pending'] ?? 0) === 1, 'durum dağılımı iptal/iadeyi de sayar');
ok(count($r['top']) === 2 && $r['top'][0]['product_name'] === 'Pantolon', 'en çok satan: iptal/iade kalemleri yok, tutara göre sıralı');
ok(strpos(json_encode(RvReports::summary($vid, 30)), 'Başkası') === false && RvReports::summary($vid2, 30)['sales'] == 7777.0, 'raporlar satıcılar arasında izoledir');
ok(RvReports::summary($vid, 90)['orders'] === 3 && RvReports::summary($vid, 7)['orders'] === 2 && count(RvReports::summary($vid, 7)['daily']) === 7, '7/30/90 gün aralıkları');
ok(count(RvReports::summary($vid, 9999)['daily']) === 365 && count(RvReports::summary($vid, -5)['daily']) === 1, 'gün sayısı sınırlandırılır');
$e = RvReports::summary(999, 30); ok($e['orders'] === 0 && $e['top'] === [] && $e['avg'] == 0.0, 'verisi olmayan satıcıda boş rapor');

section('Menü bütünlüğü: her bölüm gerçek bir ekran, izin ve şablona sahip');
$src = (string) file_get_contents(dirname(__DIR__) . '/lib/RvPanel.php');
$ready = array_keys(array_filter(RvPanel::MENU, fn($m) => $m[2] === true));
foreach ($ready as $k) {
	ok(isset(RvPerm::SECTION_PERMS[$k]), "bölüm '$k' için izin tanımlı");
	ok(preg_match("/case '$k':/", $src) === 1, "bölüm '$k' için denetleyici dalı var");
}
foreach (['dashboard', 'products', 'orders', 'stock', 'pricing', 'customers', 'finance', 'staff', 'subscription', 'store', 'reports', 'settings'] as $k) { ok(in_array($k, $ready, true), "kullanıcının istediği ekran hazır: $k"); }
foreach (['panel_dashboard', 'panel_products', 'panel_product_form', 'panel_orders', 'panel_order_view', 'panel_stock', 'panel_pricing', 'panel_finance', 'panel_store', 'panel_customers', 'panel_staff', 'panel_subscription', 'panel_reports', 'panel_settings', 'panel_import', 'panel_layout'] as $t) { ok(is_file(dirname(__DIR__) . "/assets/templates/front/$t.tpl"), "şablon var: $t"); }
foreach (['viewer', 'orders', 'catalog', 'manager', 'owner'] as $role) { ok(count(RvPerm::forRole($role)) > 0, "rol '$role' izin listesi"); }
ok(RvPerm::roleAllows('owner', 'settings.view') && RvPerm::roleAllows('manager', 'settings.view') && !RvPerm::roleAllows('viewer', 'settings.view') && !RvPerm::roleAllows('manager', 'staff.manage'), 'ayarlar: sahip/yönetici görür; personel yönetimi yalnızca sahipte');
ok(preg_match("/menu\[\] = \[/", $src) === 1 && str_contains($src, 'if (!$ready) {'), 'hazır olmayan bölümler menüde gösterilmez');

section('Şablonlar render');
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php'; $smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rv3_compile_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true); $GLOBALS['smarty'] = $smarty;
$xss = '<script>alert(1)</script>';
$vendorRow = ['id_vendor' => 1, 'store_name' => $xss, 'store_slug' => 's', 'status' => 'active'];
$base = ['rvTitle' => 'x', 'pageTitle' => 'T', 'section' => 'reports', 'vendor' => $vendorRow, 'roleLabel' => 'Sahip', 'memberships' => [], 'flash' => null, 'logoutUrl' => '/c', 'storefrontUrl' => '/', 'can' => ['store' => true, 'edit' => true, 'create' => true, 'delete' => true, 'staff' => true], 'menu' => [['key' => 'reports', 'label' => 'Raporlar', 'icon' => '▨', 'ready' => true, 'url' => '/r']]];
$render = function (string $tpl, array $vars) use ($base) { ob_start(); try { RvView::display('panel_layout', array_merge($base, $vars) + ['contentFile' => 'file:' . RvView::tplDir() . $tpl . '.tpl']); return [true, ob_get_clean()]; } catch (Throwable $e) { ob_end_clean(); return [false, $e->getMessage()]; } };
$rep = RvReports::summary($vid, 30); $max = max(array_column($rep['daily'], 'sales'));
[$o, $h] = $render('panel_reports', ['rep' => $rep, 'maxDaily' => $max, 'days' => 30, 'statuses' => RvOrders::STATUSES]);
ok($o && str_contains($h, '400.00 ₺') && str_contains($h, 'Pantolon') && str_contains($h, 'Son 90 gün') && !str_contains($h, '999.00') && !str_contains($h, '<script>alert'), 'rapor ekranı: değerler, aralık bağlantıları' . ($o ? '' : " ($h)"));
$rep['top'][0]['product_name'] = $xss; [$o, $h] = $render('panel_reports', ['rep' => $rep, 'maxDaily' => $max, 'days' => 30, 'statuses' => RvOrders::STATUSES]);
ok($o && !str_contains($h, '<script>alert(1)</script>'), 'rapor: ürün adı kaçışlanır');
[$o, $h] = $render('panel_reports', ['rep' => RvReports::summary(999, 7), 'maxDaily' => 0, 'days' => 7, 'statuses' => RvOrders::STATUSES]);
ok($o && str_contains($h, 'Bu dönemde satış yok'), 'rapor: boş durum');
[$o, $h] = $render('panel_settings', ['prefs' => ['low_stock' => 7, 'default_cargo' => $xss]]);
ok($o && str_contains($h, 'name="low_stock"') && str_contains($h, 'value="7"') && !str_contains($h, '<script>alert(1)</script>') && str_contains($h, 'name="token"'), 'ayarlar formu: alanlar, CSRF, XSS kaçışı' . ($o ? '' : " ($h)"));
[$o, $h] = $render('panel_settings', ['prefs' => ['low_stock' => 7, 'default_cargo' => ''], 'can' => ['store' => false, 'edit' => true, 'create' => true, 'delete' => true, 'staff' => true]]);
ok($o && str_contains($h, 'disabled') && !str_contains($h, 'type="submit">Kaydet'), 'sahip olmayana form salt okunur');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
