<?php
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0; $failures = [];
function ok(bool $cond, string $name): void { global $pass, $fail, $failures; if ($cond) { $pass++; echo "  ✓ $name\n"; } else { $fail++; $failures[] = $name; echo "  ✗ $name\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }

// ---------- 1. Migration ----------
section('Migration');
rv_fresh_db();
$files = RvMigrator::files();
ok($files === ['001_init'], 'migrations/001_init.sql bulundu');
ok(RvMigrator::pending() === ['001_init'], 'başlangıçta 001 bekliyor');
// Çalıştırıcı, MySQL DDL'ini SQLite'a çevirmeden çalışamaz: çeviri sonrası çalıştır
$sql = rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/migrations/001_init.sql'));
$GLOBALS['db']->exec($sql);
DB::execute('INSERT INTO rafimda_migrations (version) VALUES (?)', ['001_init']);
ok(RvMigrator::pending() === [], 'uygulandıktan sonra bekleyen yok (idempotent)');
foreach (['rafimda_vendors', 'rafimda_vendor_staff', 'rafimda_vendor_products', 'rafimda_audit_log'] as $t) {
	ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "tablo oluştu: $t");
}
$raw = file_get_contents(dirname(__DIR__) . '/migrations/001_init.sql');
ok(substr_count($raw, 'CREATE TABLE IF NOT EXISTS') === 4 && stripos($raw, 'DROP ') === false, 'migration yıkıcı komut içermiyor, IF NOT EXISTS kullanıyor');
ok(strpos(file_get_contents(dirname(__DIR__) . '/uninstall.sql'), 'DROP') === false, 'uninstall.sql veri silmiyor');

// ---------- 2. Kayıt / mağaza ----------
section('Mağaza kaydı ve doğrulama');
$v = RvVendor::validateStore(['store_name' => 'ab', 'email' => 'bozuk', 'tax_number' => '123']);
ok(isset($v['errors']['store_name'], $v['errors']['email'], $v['errors']['tax_number']), 'geçersiz alanlar reddedilir');
ok(RvVendor::slugify('Çiçek Dünyası Şişli') === 'cicek-dunyasi-sisli', 'Türkçe slug');
$v1 = RvVendor::validateStore(['store_name' => '<b>Moda Evi</b>', 'tax_number' => '1234567890', 'email' => 'A@B.com']);
ok($v1['ok'] && $v1['clean']['store_name'] === 'Moda Evi' && $v1['clean']['email'] === 'a@b.com', 'HTML temizlenir, e-posta küçültülür');
$r1 = RvVendor::create(1, $v1['clean']);
ok($r1['success'] && $r1['id_vendor'] > 0, 'satıcı A oluşturuldu');
$vendA = $r1['id_vendor'];
ok(RvVendor::get($vendA)['status'] === 'pending', 'yeni satıcı onay bekliyor (otomatik onay kapalı)');
$dup = RvVendor::create(1, $v1['clean']);
ok(!$dup['success'], 'aynı kullanıcı ikinci mağaza açamaz (mükerrer yok)');
$r2 = RvVendor::create(2, RvVendor::validateStore(['store_name' => 'Moda Evi'])['clean']);
ok($r2['success'] && RvVendor::get($r2['id_vendor'])['store_slug'] === 'moda-evi-2', 'slug çakışmasında benzersizleştirilir');
$vendB = $r2['id_vendor'];
ok((int) DB::getValue('SELECT COUNT(*) FROM users') === 4, 'users tablosuna yeni hesap eklenmedi (kimlik tekil)');
ok(DB::getValue('SELECT role FROM rafimda_vendor_staff WHERE id_vendor = ? AND id_user = 1', [$vendA]) === 'owner', 'sahip personel kaydı oluştu');

// ---------- 3. Durum ve bağlam ----------
section('Durum yönetimi ve oturum bağlamı');
Customer::as(1); RvContext::reset();
ok(RvContext::current() !== null && RvContext::vendorId() === $vendA, 'giriş yapan sahip kendi mağazasını çözer');
ok(!RvContext::can('products.view'), 'onay bekleyen mağazada hiçbir izin yok');
RvVendor::setStatus($vendA, 'active', 99); RvVendor::setStatus($vendB, 'active', 99);
RvContext::reset();
ok(RvContext::can('products.create') && RvContext::can('staff.manage'), 'onaydan sonra sahip tam yetkili');
ok(!RvVendor::setStatus($vendA, 'hacked', 99), 'geçersiz durum reddedilir');
RvVendor::setStatus($vendB, 'suspended', 99); Customer::as(2); RvContext::reset();
ok(!RvContext::can('products.view') && !RvContext::isActiveVendor(), 'askıya alınan mağaza yetkisiz');
RvVendor::setStatus($vendB, 'active', 99);
Customer::as(3); RvContext::reset();
ok(RvContext::current() === null, 'mağazası olmayan kullanıcıda bağlam yok');
Customer::logout(); RvContext::reset();
ok(RvContext::current() === null, 'oturumsuz kullanıcıda bağlam yok');

// ---------- 4. Rol matrisi ----------
section('Rol/izin matrisi');
ok(RvPerm::roleAllows('owner', 'staff.manage') && !RvPerm::roleAllows('manager', 'staff.manage'), 'yalnızca sahip personel yönetir');
ok(RvPerm::roleAllows('catalog', 'products.edit') && !RvPerm::roleAllows('catalog', 'orders.view'), 'katalog rolü siparişi göremez');
ok(!RvPerm::roleAllows('viewer', 'products.edit') && !RvPerm::roleAllows('viewer', 'products.delete'), 'görüntüleyici yazamaz');
ok(!RvPerm::roleAllows('orders', 'products.edit'), 'sipariş rolü ürün düzenleyemez');
ok(RvPerm::forRole('bilinmeyen') === [], 'bilinmeyen rol hiçbir izin almaz');

// ---------- 5. Personel ----------
section('Personel yönetimi');
$a = RvVendor::addStaffByEmail($vendA, 'veli@x.com', 'catalog');
ok($a['success'], 'mevcut hesap personel olarak eklendi (yeni hesap açılmadı)');
ok((int) DB::getValue('SELECT COUNT(*) FROM users') === 4, 'personel eklemek users tablosunu büyütmedi');
ok(!RvVendor::addStaffByEmail($vendA, 'yok@x.com', 'viewer')['success'], 'kayıtsız e-posta reddedilir');
ok(!RvVendor::addStaffByEmail($vendA, 'veli@x.com', 'viewer')['success'], 'aynı kişi iki kez eklenemez');
ok(!RvVendor::addStaffByEmail($vendA, 'ayse@x.com', 'owner')['success'], 'owner rolü atanamaz');
$staffRow = DB::getRowSafe('rafimda_vendor_staff', 'id_vendor = ? AND id_user = 2', [$vendA]);
ok(!RvVendor::updateStaff($vendB, (int) $staffRow['id_staff'], 'viewer', 'active', 2)['success'], 'başka satıcının personel kaydı değiştirilemez');
ok(!RvVendor::updateStaff($vendA, (int) DB::getValue("SELECT id_staff FROM rafimda_vendor_staff WHERE id_vendor = $vendA AND role='owner'"), 'viewer', 'disabled', 2)['success'], 'sahip değiştirilemez');
ok(RvVendor::updateStaff($vendA, (int) $staffRow['id_staff'], 'viewer', 'disabled', 1)['success'], 'sahip personeli devre dışı bırakabilir');
Customer::as(2); RvContext::reset();
ok(RvContext::vendorId() === $vendB, 'devre dışı personel A mağazasına erişemez (yalnızca kendi B mağazası kalır)');

// ---------- 6. Veri izolasyonu ----------
section('Satıcılar arası veri izolasyonu');
$post = ['product_name' => 'Tişört', 'id_category' => 1, 'id_brand' => 1, 'price' => '99,90', 'stock' => 10, 'active' => 1];
$pa = RvProducts::create($vendA, $post);
$pb = RvProducts::create($vendB, array_merge($post, ['product_name' => 'Pantolon']));
ok($pa['success'] && $pb['success'], 'iki satıcı kendi ürününü ekledi');
ok(abs((float) DB::getValue('SELECT price FROM products WHERE id_product = ?', [$pa['id']]) - 99.9) < 0.001, 'virgüllü fiyat doğru çözülür');
ok(RvProducts::owns($vendA, $pa['id']) && !RvProducts::owns($vendA, $pb['id']), 'sahiplik doğru');
ok(RvProducts::getOwned($vendA, $pb['id']) === null, 'A, B\'nin ürününü okuyamaz');
ok(!RvProducts::update($vendA, $pb['id'], array_merge($post, ['product_name' => 'ELE GEÇİRİLDİ']))['success'], 'A, B\'nin ürününü güncelleyemez');
ok(DB::getValue('SELECT product_name FROM products WHERE id_product = ?', [$pb['id']]) === 'Pantolon', 'B\'nin ürünü değişmedi');
ok(!RvProducts::delete($vendA, $pb['id'])['success'] && RvProducts::owns($vendB, $pb['id']), 'A, B\'nin ürününü silemez');
ok(!RvProducts::quickUpdate($vendA, $pb['id'], 1, 1)['success'], 'A, B\'nin fiyat/stoğunu değiştiremez');
ok(RvProducts::filterOwned($vendA, [$pa['id'], $pb['id'], 9999, 'x']) === [$pa['id']], 'filterOwned yabancı ID\'leri eler');
$bulk = RvProducts::bulk($vendA, [$pa['id'], $pb['id']], 'deactivate');
ok($bulk['count'] === 1 && (int) DB::getValue('SELECT active FROM products WHERE id_product = ?', [$pb['id']]) === 1, 'toplu işlem yalnızca kendi ürünlerine uygulanır');
$bulkDel = RvProducts::bulk($vendA, [$pb['id']], 'delete');
ok(!$bulkDel['success'] && DB::getRowSafe('products', 'id_product = ?', [$pb['id']]) !== false, 'toplu silme yabancı ürünü silemez');
ok(RvProducts::listFor($vendA)['total'] === 1 && RvProducts::listFor($vendA)['rows'][0]['product_name'] === 'Tişört', 'liste yalnızca kendi ürünlerini döndürür');
DB::execute('INSERT INTO images (id_image, id_product, cover) VALUES (50, ?, 1)', [$pb['id']]);
ok(!RvProducts::deleteImage($vendA, 50)['success'] && !RvProducts::setCover($vendA, 50)['success'], 'A, B\'nin görselini silemez/kapak yapamaz');
ok(RvProducts::uploadImages($vendA, $pb['id'], ['name' => 'x.jpg', 'tmp_name' => '/tmp/x', 'size' => 1, 'error' => 0])['uploaded'] === 0, 'A, B\'nin ürününe görsel yükleyemez');
// Sipariş izolasyonu
DB::execute("INSERT INTO orders VALUES (1,'R1',1,'2026-01-01','Müşteri 1','İzmir'),(2,'R2',1,'2026-01-02','Müşteri 2','Ankara')");
DB::execute('INSERT INTO order_detail (id_order,id_product,total,qty) VALUES (1,?,100,1),(2,?,200,2)', [$pa['id'], $pb['id']]);
$oa = RvProducts::ordersFor($vendA);
ok(count($oa) === 1 && (int) $oa[0]['id_order'] === 1, 'A yalnızca kendi ürününü içeren siparişi görür');
ok(count(RvProducts::ordersFor($vendB)) === 1 && (int) RvProducts::ordersFor($vendB)[0]['id_order'] === 2, 'B yalnızca kendi siparişini görür');
// Girdi
$bad = RvProducts::create($vendA, array_merge($post, ['id_category' => 2]));
ok(!$bad['success'] && isset($bad['errors']['id_category']), 'pasif/yok kategori reddedilir');
$bad2 = RvProducts::create($vendA, array_merge($post, ['price' => '-5', 'stock' => -1]));
ok(!$bad2['success'] && isset($bad2['errors']['price'], $bad2['errors']['stock']), 'negatif fiyat/stok reddedilir');
$inj = RvProducts::sanitizeInput(array_merge($post, ['id_vendor' => 999, 'id_product' => 1]));
ok(!isset($inj['data']['id_vendor']) && !isset($inj['data']['id_product']), 'formdan id_vendor/id_product kabul edilmez (mass-assignment)');
$sqli = RvProducts::listFor($vendA, "' OR 1=1 --");
ok($sqli['total'] === 0, 'arama kutusunda SQL enjeksiyonu etkisiz (parametreli sorgu)');
ok(!in_array('product.updated', Module::$hooks, true), 'yabancı ürüne yapılan reddedilen güncellemeler product.updated tetiklemedi');
$q = RvProducts::quickUpdate($vendA, $pa['id'], '120,50', 7);
ok($q['success'] && in_array('product.updated', Module::$hooks, true), 'hızlı güncelleme çalışır ve product.updated tetiklenir');
$st = RvProducts::stats($vendA);
ok($st['total'] === 1 && $st['passive'] === 1, 'istatistikler satıcı kapsamlı');
ok(count(RvAudit::recent($vendA)) > 0 && count(array_filter(RvAudit::recent($vendA), fn($r) => (int) $r['id_vendor'] !== $vendA)) === 0, 'işlem kayıtları satıcı kapsamlı');

// ---------- 7. Yönetici ekranı ----------
section('Ana yönetici satıcı listesi');
ok(count(RvVendor::adminList()) === 2, 'admin tüm satıcıları görür');
ok(count(RvVendor::adminList('active')) === 2 && count(RvVendor::adminList('pending')) === 0, 'durum filtresi çalışır');
ok(RvVendor::countByStatus()['active'] === 2, 'durum sayaçları');
RvVendor::setStatus($vendA, 'rejected', 7, 'Eksik belge');
ok(RvVendor::get($vendA)['rejection_reason'] === 'Eksik belge', 'red nedeni saklanır');
ok(DB::getValue("SELECT COUNT(*) FROM rafimda_audit_log WHERE action = 'vendor.status' AND id_admin = 7") >= 1, 'admin işlemi denetim kaydına yazıldı');

// ---------- 8. Şablonlar (gerçek Smarty 5) ----------
section('Smarty şablonları');
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$smarty = new Smarty\Smarty();
$smarty->setCompileDir(sys_get_temp_dir() . '/rv_compile_' . getmypid());
@mkdir($smarty->getCompileDir(), 0777, true);
$GLOBALS['smarty'] = $smarty;
$xss = '<script>alert(1)</script>';
$renderOk = function (string $tpl, array $vars) {
	ob_start();
	try { RvView::display($tpl, $vars); $out = ob_get_clean(); return [true, $out]; }
	catch (Throwable $e) { ob_end_clean(); return [false, $e->getMessage()]; }
};
[$o, $html] = $renderOk('guest_login', ['rvTitle' => 'T', 'error' => $xss, 'login' => $xss, 'registerUrl' => '/k', 'customerLoginUrl' => '/l']);
ok($o && strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false, 'giriş sayfası render + XSS kaçışı' . ($o ? '' : " ($html)"));
ok($o && strpos($html, 'name="token"') !== false, 'giriş formunda CSRF alanı var');
[$o, $html] = $renderOk('guest_register', ['rvTitle' => 'K', 'errors' => ['store_name' => $xss], 'form' => ['store_name' => $xss], 'loggedIn' => false, 'done' => false, 'notice' => '', 'loginUrl' => '/g']);
ok($o && strpos($html, '<script>alert(1)</script>') === false && strpos($html, 'name="store_name"') !== false, 'kayıt sayfası render + XSS kaçışı' . ($o ? '' : " ($html)"));
[$o, $html] = $renderOk('guest_register', ['rvTitle' => 'K', 'errors' => [], 'form' => [], 'loggedIn' => true, 'done' => false, 'notice' => '', 'loginUrl' => '/g']);
ok($o && strpos($html, 'account_email') === false, 'oturumlu kullanıcıya hesap alanları gösterilmez' . ($o ? '' : " ($html)"));
$vendorRow = ['id_vendor' => 1, 'store_name' => $xss, 'store_slug' => 's', 'status' => 'active', 'plan_code' => 'free', 'city' => '', 'legal_name' => '', 'tax_office' => '', 'tax_number' => '', 'phone' => '', 'email' => '', 'description' => '', 'rejection_reason' => ''];
$layoutBase = ['rvTitle' => 'x', 'pageTitle' => 'Ürün Yönetimi', 'section' => 'products', 'vendor' => $vendorRow, 'roleLabel' => 'Mağaza Sahibi', 'memberships' => [$vendorRow, $vendorRow], 'flash' => ['type' => 'success', 'message' => $xss], 'logoutUrl' => '/c', 'storefrontUrl' => '/', 'denied' => false,
	'can' => ['create' => true, 'edit' => true, 'delete' => true, 'staff' => true, 'store' => true],
	'menu' => [['key' => 'products', 'label' => 'Ürün Yönetimi', 'icon' => '▦', 'ready' => true, 'url' => '/p'], ['key' => 'xml', 'label' => 'XML Merkezi', 'icon' => '⇄', 'ready' => false, 'url' => '/x']]];
$contents = [
	'panel_dashboard' => ['stats' => ['total' => 1, 'active' => 1, 'passive' => 0, 'low_stock' => 0], 'recentOrders' => [['reference' => 'R1', 'id_order' => 1, 'vendor_total' => 10, 'date_add' => 'd']], 'audit' => [['action' => 'a', 'date_add' => 'd']]],
	'panel_products' => ['products' => [['id_product' => 1, 'product_name' => $xss, 'price' => 5, 'stock' => 3, 'active' => 1, 'stock_code' => '', 'category_name' => '', 'brand_name' => '', 'image_url' => '']], 'total' => 1, 'page' => 1, 'pages' => 2, 'q' => $xss, 'state' => '', 'newUrl' => '/n', 'listUrl' => '/l'],
	'panel_product_form' => ['form' => ['product_name' => $xss, 'active' => 1], 'isEdit' => true, 'idProduct' => 1, 'images' => [['id_image' => 1, 'url' => '/i.jpg', 'cover' => 1]], 'variations' => [['option1_name' => 'Renk', 'option1_value' => 'Kırmızı', 'option2_name' => 'Beden', 'option2_value' => 'M', 'sku' => 'S1', 'barcode' => '', 'price' => '', 'stock' => 3]], 'categories' => [['id_category' => 1, 'category_name' => 'Giyim']], 'brands' => [['id_brand' => 1, 'brand_name' => 'M']], 'errors' => ['price' => 'hata'], 'formMessage' => $xss, 'listUrl' => '/l'],
	'panel_orders' => ['orders' => [['reference' => 'R', 'id_order' => 1, 'customer_name' => $xss, 'address_city' => 'X', 'line_count' => 1, 'vendor_total' => 5, 'date_add' => 'd']], 'page' => 1],
	'panel_staff' => ['staff' => [['id_staff' => 2, 'id_user' => 2, 'user_full_name' => $xss, 'email' => 'e', 'role' => 'catalog', 'status' => 'active'], ['id_staff' => 1, 'id_user' => 1, 'user_full_name' => 'O', 'email' => 'o', 'role' => 'owner', 'status' => 'active']], 'roles' => ['catalog' => 'K'], 'allRoles' => RvPerm::ROLES, 'myUserId' => 1],
	'panel_store' => ['form' => $vendorRow, 'errors' => []],
	'panel_planned' => ['planned' => RvPanel_menu_stub()],
];
function RvPanel_menu_stub() { return ['XML Merkezi', '⇄', false, 'Aşama 7']; }
foreach ($contents as $tpl => $vars) {
	[$o, $html] = $renderOk('panel_layout', $layoutBase + $vars + ['contentFile' => 'file:' . RvView::tplDir() . $tpl . '.tpl']);
	ok($o && strpos($html, '<script>alert(1)</script>') === false && strlen($html) > 800, "$tpl render + XSS kaçışı" . ($o ? '' : " ($html)"));
}
[$o, $html] = $renderOk('panel_layout', array_merge($layoutBase, ['denied' => true, 'contentFile' => 'file:x']));
ok($o && strpos($html, 'Erişim yok') !== false, 'yetkisiz bölüm "Erişim yok" gösterir');
[$o, $html] = $renderOk('panel_status', ['rvTitle' => 's', 'vendor' => $vendorRow, 'statusLabel' => 'Onay Bekliyor', 'logoutUrl' => '/c']);
ok($o && strpos($html, '<script>alert(1)</script>') === false, 'durum sayfası render' . ($o ? '' : " ($html)"));
ok(strpos(file_get_contents(dirname(__DIR__) . '/assets/css/panel.css'), '@media (max-width:900px)') !== false, 'CSS mobil kırılım noktası içeriyor (görsel olarak test EDİLMEDİ)');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
foreach ($failures as $f) echo " BAŞARISIZ: $f\n";
exit($fail ? 1 : 0);
