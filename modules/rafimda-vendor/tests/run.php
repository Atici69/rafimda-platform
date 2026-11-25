<?php
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0; $failures = [];
function ok(bool $cond, string $name): void { global $pass, $fail, $failures; if ($cond) { $pass++; echo "  ✓ $name\n"; } else { $fail++; $failures[] = $name; echo "  ✗ $name\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }

// ---------- 1. Migration ----------
section('Migration');
rv_fresh_db();
$files = RvMigrator::files();
ok($files === ['001_init', '002_moderation', '003_orders_finance', '004_plans', '005_prefs', '006_applications', '007_engagement', '008_commission_rules'], 'sekiz migration dosyası bulundu ve sıralı');
ok(RvMigrator::pending() === $files, 'başlangıçta hepsi bekliyor');
// Çalıştırıcı, MySQL DDL'ini SQLite'a çevirmeden çalışamaz: çeviri sonrası çalıştır
rv_apply_migrations();
ok(RvMigrator::pending() === [], 'uygulandıktan sonra bekleyen yok (idempotent)');
foreach (['rafimda_vendors', 'rafimda_vendor_staff', 'rafimda_vendor_products', 'rafimda_audit_log', 'rafimda_import_log', 'rafimda_vendor_orders', 'rafimda_vendor_order_items', 'rafimda_ledger', 'rafimda_payouts', 'rafimda_vendor_prefs'] as $t) {
	ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "tablo oluştu: $t");
}
foreach ($files as $f) {
	$raw = file_get_contents(dirname(__DIR__) . '/migrations/' . $f . '.sql');
	ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM)\b/i', $raw) && !preg_match('/^\s*--/m', $raw), "$f yıkıcı komut ve '--' ifadesi içermiyor");
	ok(preg_match_all('/CREATE TABLE(?! IF NOT EXISTS)/', $raw) === 0, "$f: tüm CREATE TABLE 'IF NOT EXISTS' kullanıyor");
}
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
Settings::set('RAFIMDA_PRODUCT_MODERATION', '0'); // bu bölüm izolasyonu sınar; onay kuralı ayrı bölümde
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
DB::execute("INSERT INTO orders (id_order,reference,status,date_add,customer_name,address_city) VALUES (1,'R1',1,'2026-01-01','Müşteri 1','İzmir'),(2,'R2',1,'2026-01-02','Müşteri 2','Ankara')");
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


// ---------- 6b. Ürün onayı (moderasyon) ----------
section('Ürün onayı (moderasyon)');
Settings::set('RAFIMDA_PRODUCT_MODERATION', '');
$mp = RvProducts::create($vendA, array_merge($post, ['product_name' => 'Onay Bekleyen', 'active' => 1]));
ok($mp['success'] && (int) DB::getValue('SELECT active FROM products WHERE id_product = ?', [$mp['id']]) === 0, 'yeni ürün onaya düşer ve pasif kalır');
ok(DB::getValue('SELECT moderation FROM rafimda_vendor_products WHERE id_product = ?', [$mp['id']]) === 'pending', 'moderation=pending');
$up = RvProducts::update($vendA, $mp['id'], array_merge($post, ['product_name' => 'Onay Bekleyen', 'active' => 1]));
ok((int) DB::getValue('SELECT active FROM products WHERE id_product = ?', [$mp['id']]) === 0, 'satıcı onaysız ürünü düzenleyip aktif edemez');
RvProducts::bulk($vendA, [$mp['id']], 'activate');
ok((int) DB::getValue('SELECT active FROM products WHERE id_product = ?', [$mp['id']]) === 0, 'toplu aktifleştirme onaysız ürünü yayına almaz');
ok(count(RvProducts::moderationQueue('pending')) === 1, 'onay kuyruğunda 1 ürün var');
ok(!RvProducts::approve(99999, 1) && !RvProducts::reject(99999, 'x', 1), 'olmayan ürün onaylanamaz/reddedilemez');
ok(RvProducts::approve($mp['id'], 99) && (int) DB::getValue('SELECT active FROM products WHERE id_product = ?', [$mp['id']]) === 1, 'onay ürünü yayına alır');
ok(RvProducts::reject($mp['id'], '<b>Uygunsuz</b> görsel', 99) && (int) DB::getValue('SELECT active FROM products WHERE id_product = ?', [$mp['id']]) === 0 && DB::getValue('SELECT moderation_note FROM rafimda_vendor_products WHERE id_product = ?', [$mp['id']]) === 'Uygunsuz görsel', 'red ürünü yayından kaldırır, not temizlenir');
ok(count(array_filter(RvAudit::recent($vendA, 50), fn($r) => in_array($r['action'], ['product.approved', 'product.rejected'], true))) === 2, 'onay/red denetim kaydına yazıldı');
Settings::set('RAFIMDA_PRODUCT_MODERATION', '0');

// ---------- 6c. CSV içe aktarma ----------
section('CSV içe aktarma');
Customer::as(1);
$csv = function (string $content): string { $f = tempnam(sys_get_temp_dir(), 'rvcsv'); file_put_contents($f, $content); return $f; };
$h = "urun_adi;kategori_id;marka_id;fiyat;stok;stok_kodu;barkod;aciklama\r\n";
$r = RvProducts::importCsv($vendA, $csv($h . "CSV Ürün 1;1;1;50,5;4;CSV-1;;açık\r\nCSV Ürün 2;1;1;60;5;CSV-2;;\r\nKötü;99;1;10;1;CSV-3;;\r\n;1;1;10;1;;;\r\n"), 'a.csv');
ok($r['success'] && $r['created'] === 2 && $r['failed'] === 2 && $r['total'] === 4, "CSV: 2 yeni, 2 hatalı (beklenen) => {$r['message']}");
ok(DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_products WHERE id_vendor = ? AND id_product IN (SELECT id_product FROM products WHERE stock_code LIKE ?)', [$vendA, 'CSV-%']) == 2, 'CSV ürünleri satıcı A\'ya bağlandı');
$r = RvProducts::importCsv($vendA, $csv($h . "Ad;1;1;75;9;CSV-1;;\r\n"), 'b.csv');
ok($r['updated'] === 1 && $r['created'] === 0 && abs((float) DB::getValue("SELECT price FROM products WHERE stock_code = 'CSV-1'") - 75) < 0.001, 'aynı stok kodu yeni ürün açmaz, fiyat/stok günceller');
$r = RvProducts::importCsv($vendB, $csv($h . "B Ürün;1;1;5;1;CSV-1;;\r\n"), 'c.csv');
ok($r['created'] === 1 && abs((float) DB::getValue("SELECT price FROM products WHERE stock_code = 'CSV-1' AND id_product IN (SELECT id_product FROM rafimda_vendor_products WHERE id_vendor = ?)", [$vendA]) - 75) < 0.001, 'B aynı stok koduyla A\'nın ürününü ezemez (yeni ürün olur)');
$r = RvProducts::importCsv($vendA, $csv("urun_adi;fiyat\r\nX;1\r\n"), 'd.csv');
ok(!$r['success'], 'eksik sütunlu dosya reddedilir');
$r = RvProducts::importCsv($vendA, $csv("\xEF\xBB\xBF" . $h . "\xFF\xFE;1;1;1;1;;;\r\n"), 'e.csv');
ok(!$r['success'], 'UTF-8 olmayan dosya reddedilir');
$big = $h; for ($i = 0; $i < 510; $i++) { $big .= "Toplu $i;1;1;1;1;BIG-$i;;\r\n"; }
$r = RvProducts::importCsv($vendA, $csv($big), 'f.csv');
ok($r['created'] === RvProducts::CSV_MAX_ROWS && $r['total'] === RvProducts::CSV_MAX_ROWS, 'satır sınırı uygulanır (500)');
$r = RvProducts::importCsv($vendA, $csv($h . "=cmd|' /C calc'!A0;1;1;1;1;INJ;;\r\n"), 'g.csv');
ok($r['created'] === 1, 'formül benzeri hücre metin olarak kaydedilir (çalıştırılmaz)');
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_import_log WHERE id_vendor = ?', [$vendA]) >= 3, 'içe aktarma günlüğü yazıldı');
ok(strpos(RvProducts::csvTemplate(), 'urun_adi;kategori_id') !== false, 'CSV şablonu üretilir');

// ---------- 6d. Sipariş bölme, komisyon, defter ----------
section('Sipariş bölme ve komisyon');
Settings::set('RAFIMDA_DEFAULT_COMMISSION', '10');
Settings::set('RAFIMDA_PAYOUT_HOLD_DAYS', '7');
DB::execute("INSERT INTO products (id_product, product_name, price, stock, active) VALUES (900,'Çekirdek Ürün',10,5,1)"); // satıcısı olmayan (platform) ürün
DB::execute("INSERT INTO orders (id_order,reference,status,date_add,customer_name,customer_phone,address_city,address_district,address_text) VALUES (10,'R10',1,'2026-02-01','Mehmet','555','Bursa','Nilüfer','Adres 1')");
DB::execute('INSERT INTO order_detail (id_order,id_product,product_name,price,total,qty) VALUES (10,?,?,100,200,2),(10,?,?,50,50,1),(10,900,?,10,10,1)', [$pa['id'], 'Tişört', $pb['id'], 'Pantolon', 'Çekirdek Ürün']);
$order10 = ['id_order' => 10, 'status' => 1];
ok(RvOrders::splitOrder($order10) === 2, 'çok satıcılı sipariş 2 satıcıya bölündü (platform ürünü hariç)');
ok(RvOrders::splitOrder($order10) === 0 && (int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_orders WHERE id_order = 10') === 2, 'tekrar bölme idempotent');
$voA = DB::getRowSafe('rafimda_vendor_orders', 'id_order = 10 AND id_vendor = ?', [$vendA]);
$voB = DB::getRowSafe('rafimda_vendor_orders', 'id_order = 10 AND id_vendor = ?', [$vendB]);
ok((float) $voA['subtotal'] == 200 && (float) $voA['commission_amount'] == 20 && (float) $voA['vendor_earning'] == 180, 'A: 200 ₺ satış, %10 komisyon 20 ₺, kazanç 180 ₺');
ok((float) $voB['vendor_earning'] == 45 && (float) $voB['subtotal'] == 50, 'B: kazanç 45 ₺ (yabancı kalemler dahil değil)');
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_order_items WHERE id_vendor_order = ?', [$voA['id_vendor_order']]) === 1, 'A yalnızca kendi kalemini içerir');
$bal = RvLedger::balances($vendA);
ok($bal['pending'] == 180 && $bal['available'] == 0, 'teslimat öncesi kazanç bekleyen bakiyede');
ok(!RvLedger::recordPayout($vendA, 10, 'T1', '', 99)['success'], 'ödenebilir bakiye yokken ödeme reddedilir');
RvVendor::setStatus($vendA, 'active', 99);
DB::execute('UPDATE rafimda_vendors SET commission_rate = 20 WHERE id_vendor = ?', [$vendB]);
DB::execute("INSERT INTO orders (id_order,reference,status,date_add,customer_name,address_city) VALUES (11,'R11',1,'2026-02-02','Zeynep','Van')");
DB::execute('INSERT INTO order_detail (id_order,id_product,product_name,price,total,qty) VALUES (11,?,?,50,100,2)', [$pb['id'], 'Pantolon']);
RvOrders::splitOrder(['id_order' => 11]);
$voB2 = DB::getRowSafe('rafimda_vendor_orders', 'id_order = 11 AND id_vendor = ?', [$vendB]);
ok($voB2 && (float) $voB2['commission_rate'] == 20 && (float) $voB2['vendor_earning'] == 80, 'satıcıya özel komisyon oranı uygulanır (%20)');
RvOrders::safeHook(function () { throw new RuntimeException('patla'); });
ok(true, 'safeHook istisnayı yutar (sipariş akışı bozulmaz)');

section('Teslimat, iptal/iade ve ödeme');
RvOrders::onCoreOrderUpdated(['id_order' => 10, 'status' => 4], 1);
ok(RvLedger::balances($vendA)['pending'] == 180, 'teslim edilen kazanç bekleme süresi dolana kadar bekleyen');
DB::execute("UPDATE rafimda_ledger SET available_at = '2000-01-01 00:00:00' WHERE ref_key = ?", ['sale:vo' . $voA['id_vendor_order']]);
$bal = RvLedger::balances($vendA);
ok($bal['available'] == 180 && $bal['pending'] == 0, 'bekleme süresi geçince ödenebilir bakiyeye geçti');
ok(DB::getValue('SELECT status FROM rafimda_vendor_orders WHERE id_vendor_order = ?', [$voA['id_vendor_order']]) === 'delivered', 'satıcı siparişi teslim edildi');
RvOrders::onCoreOrderUpdated(['id_order' => 10, 'status' => 4], 1);
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_ledger WHERE id_vendor = ?', [$vendA]) === 1, 'aynı durum olayı tekrarlanınca yeni kayıt oluşmaz');
$p = RvLedger::recordPayout($vendA, 500, 'T2', '', 99);
ok(!$p['success'], 'bakiyeyi aşan ödeme reddedilir');
$p = RvLedger::recordPayout($vendA, 100, 'EFT-1', 'Ocak hakedişi', 99);
ok($p['success'] && RvLedger::balances($vendA)['available'] == 80 && RvLedger::balances($vendA)['paid_out'] == 100, 'ödeme bakiyeden düşer (180→80)');
ok(!RvLedger::recordPayout($vendA, 0, 'x', '', 99)['success'] && !RvLedger::recordPayout($vendA, -5, 'x', '', 99)['success'], 'sıfır/negatif ödeme reddedilir');
ok(!RvLedger::recordPayout(9999, 5, 'x', '', 99)['success'], 'olmayan satıcıya ödeme reddedilir');
RvOrders::onCoreOrderUpdated(['id_order' => 10, 'status' => 6], 4);
ok(RvLedger::balances($vendA)['available'] == -100, 'teslim sonrası iade: kazanç geri alınır, bakiye eksiye düşebilir (-100, ödenmiş kısım için alacak)');
ok(DB::getValue('SELECT status FROM rafimda_vendor_orders WHERE id_vendor_order = ?', [$voA['id_vendor_order']]) === 'returned', 'durum returned');
RvOrders::onCoreOrderUpdated(['id_order' => 10, 'status' => 6], 4);
ok((int) DB::getValue("SELECT COUNT(*) FROM rafimda_ledger WHERE id_vendor = ? AND entry_type = 'refund'", [$vendA]) === 1, 'iade düzeltmesi yalnızca bir kez yazılır');
RvOrders::onCoreOrderUpdated(['id_order' => 11, 'status' => 5], 1);
ok(RvLedger::balances($vendB)['pending'] == 45 && DB::getValue('SELECT status FROM rafimda_vendor_orders WHERE id_order = 11') === 'cancelled', 'teslim öncesi iptal: yalnız ilgili sipariş kazancı geri alınır (B: 45 kalır)');
ok(!RvLedger::adjustment($vendA, 5, 'kısa', 99)['success'] && RvLedger::adjustment($vendA, 25.5, 'Kargo hasarı telafisi', 99)['success'], 'düzeltme için açıklama zorunlu');
ok(!RvLedger::add($vendA, null, 'sale', 5, null, 'sale:vo' . $voA['id_vendor_order']), 'aynı ref_key ile defter kaydı tekrarlanamaz');
ok(!method_exists('RvLedger', 'update') && !method_exists('RvLedger', 'delete'), 'defterde güncelleme/silme metodu yok (append-only)');
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_audit_log WHERE action = ?', ['finance.payout']) === 1, 'ödeme denetim kaydı yazıldı');
$sum = (float) DB::getValue('SELECT SUM(amount) FROM rafimda_ledger WHERE id_vendor = ?', [$vendA]);
ok(abs($sum - (180 - 100 - 180 + 25.5)) < 0.001, 'defter toplamı = satış 180 − ödeme 100 − iade 180 + düzeltme 25,5');
ok(abs(RvLedger::balances($vendA)['available'] + RvLedger::balances($vendA)['pending'] - $sum) < 0.001, 'bakiye (bekleyen+ödenebilir) defter toplamıyla tutarlı');

section('Satıcı sipariş izolasyonu (IDOR) ve kargo');
ok(RvOrders::getFor($vendB, (int) $voA['id_vendor_order']) === null, 'B, A\'nın siparişini okuyamaz');
ok(count(RvOrders::listFor($vendB)) === 2 && count(array_filter(RvOrders::listFor($vendB), fn($o) => (int) $o['id_vendor'] !== $vendB)) === 0, 'liste yalnız kendi siparişleri');
ok(!RvOrders::updateFulfillment($vendB, (int) $voA['id_vendor_order'], 'shipped', 'Yurtiçi', 'ABC12345')['success'], 'B, A\'nın siparişini kargolayamaz');
DB::execute("INSERT INTO orders (id_order,reference,status,date_add,customer_name,address_city) VALUES (12,'R12',1,'2026-02-03','Can','Muğla')");
DB::execute('INSERT INTO order_detail (id_order,id_product,product_name,price,total,qty) VALUES (12,?,?,40,40,1)', [$pb['id'], 'Pantolon']);
RvOrders::splitOrder(['id_order' => 12]);
$voB10 = (int) DB::getValue('SELECT id_vendor_order FROM rafimda_vendor_orders WHERE id_order = 12 AND id_vendor = ?', [$vendB]);
ok(!RvOrders::updateFulfillment($vendB, $voB10, 'delivered', 'Y', 'ABC12345')['success'], 'satıcı "teslim edildi" durumunu kendisi veremez');
ok(!RvOrders::updateFulfillment($vendB, $voB10, 'shipped', '', '1')['success'], 'takip numarasız/geçersiz kargolama reddedilir');
ok(!RvOrders::updateFulfillment($vendB, $voB10, 'shipped', 'Yurtiçi', '<script>')['success'], 'takip numarasında HTML reddedilir');
ok(RvOrders::updateFulfillment($vendB, $voB10, 'preparing', '', '')['success'], 'pending→preparing');
ok(RvOrders::updateFulfillment($vendB, $voB10, 'shipped', 'Yurtiçi', 'YK-123456')['success'], 'preparing→shipped, takip kaydedildi');
ok(!RvOrders::updateFulfillment($vendB, $voB10, 'preparing', '', '')['success'], 'shipped geri preparing yapılamaz');
ok(!RvOrders::updateFulfillment($vendA, (int) $voA['id_vendor_order'], 'shipped', 'Yurtiçi', 'ABC12345')['success'], 'iade edilmiş sipariş kargolanamaz');
ok(RvOrders::adminList()[0]['store_name'] !== '' && RvOrders::platformTotals()['orders'] >= 1, 'yönetici sipariş listesi ve platform toplamı çalışır');

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
	'panel_dashboard' => ['stats' => ['total' => 1, 'active' => 1, 'passive' => 0, 'low_stock' => 0], 'balances' => ['pending' => 1, 'available' => 2, 'earned' => 3, 'paid_out' => 0], 'recentOrders' => [['reference' => 'R1', 'id_order' => 1, 'subtotal' => 10, 'date_add' => 'd']], 'audit' => [['action' => 'a', 'date_add' => 'd']]],
	'panel_products' => ['products' => [['id_product' => 1, 'product_name' => $xss, 'price' => 5, 'stock' => 3, 'active' => 1, 'stock_code' => '', 'moderation' => 'pending', 'moderation_note' => '', 'category_name' => '', 'brand_name' => '', 'image_url' => '']], 'total' => 1, 'page' => 1, 'pages' => 2, 'q' => $xss, 'state' => '', 'newUrl' => '/n', 'listUrl' => '/l'],
	'panel_product_form' => ['form' => ['product_name' => $xss, 'active' => 1], 'isEdit' => true, 'idProduct' => 1, 'images' => [['id_image' => 1, 'url' => '/i.jpg', 'cover' => 1]], 'variations' => [['option1_name' => 'Renk', 'option1_value' => 'Kırmızı', 'option2_name' => 'Beden', 'option2_value' => 'M', 'sku' => 'S1', 'barcode' => '', 'price' => '', 'stock' => 3]], 'categories' => [['id_category' => 1, 'category_name' => 'Giyim']], 'brands' => [['id_brand' => 1, 'brand_name' => 'M']], 'errors' => ['price' => 'hata'], 'formMessage' => $xss, 'listUrl' => '/l'],
	'panel_orders' => ['orders' => [['id_vendor_order' => 1, 'reference' => 'R', 'id_order' => 1, 'customer_name' => $xss, 'address_city' => 'X', 'line_count' => 1, 'subtotal' => 5, 'commission_amount' => 1, 'vendor_earning' => 4, 'status' => 'pending', 'date_add' => 'd']], 'statuses' => RvOrders::STATUSES, 'state' => '', 'page' => 1],
	'panel_order_view' => ['vo' => ['id_vendor_order' => 1, 'reference' => 'R', 'id_order' => 1, 'status' => 'pending', 'order_date' => 'd', 'customer_name' => $xss, 'customer_phone' => '1', 'address_text' => $xss, 'address_district' => 'a', 'address_city' => 'b', 'subtotal' => 5, 'commission_rate' => 10, 'commission_amount' => 1, 'vendor_earning' => 4, 'cargo_company' => $xss, 'tracking_number' => '', 'items' => [['product_name' => $xss, 'qty' => 2, 'unit_price' => 2.5, 'total' => 5]]], 'statuses' => RvOrders::STATUSES, 'listUrl' => '/o', 'canManage' => true],
	'panel_finance' => ['balances' => ['pending' => 1, 'available' => 2, 'earned' => 3, 'paid_out' => 0], 'entries' => [['date_add' => 'd', 'entry_type' => 'sale', 'note' => $xss, 'amount' => -5, 'available_at' => null]], 'payouts' => [['date_add' => 'd', 'amount' => 1, 'reference' => $xss]], 'types' => RvLedger::TYPES, 'page' => 1, 'commission' => 10, 'holdDays' => 7],
	'panel_import' => ['templateUrl' => '/t', 'listUrl' => '/l', 'maxRows' => 500, 'categories' => [['id_category' => 1, 'category_name' => $xss]], 'brands' => [['id_brand' => 1, 'brand_name' => $xss]], 'history' => [['date_add' => 'd', 'file_name' => $xss, 'rows_created' => 1, 'rows_updated' => 0, 'rows_failed' => 0]]],
	'panel_staff' => ['staff' => [['id_staff' => 2, 'id_user' => 2, 'user_full_name' => $xss, 'email' => 'e', 'role' => 'catalog', 'status' => 'active'], ['id_staff' => 1, 'id_user' => 1, 'user_full_name' => 'O', 'email' => 'o', 'role' => 'owner', 'status' => 'active']], 'roles' => ['catalog' => 'K'], 'allRoles' => RvPerm::ROLES, 'myUserId' => 1],
	'panel_store' => ['form' => $vendorRow, 'errors' => []],
	'panel_planned' => ['planned' => RvPanel_menu_stub()],
];
function RvPanel_menu_stub() { return ['XML Merkezi', '⇄', false, 'Aşama 7']; }
foreach ($contents as $tpl => $vars) {
	[$o, $html] = $renderOk('panel_layout', $layoutBase + $vars + ['contentFile' => 'file:' . RvView::tplDir() . $tpl . '.tpl']);
	ok($o && strpos($html, '<script>alert(1)</script>') === false && strlen($html) > 800, "$tpl render + XSS kaçışı" . ($o ? '' : " ($html)"));
}

// Yönetici şablonu: motor düzeyinde otomatik kaçış KAPALI iken bile XSS payload'ı ham çıkmamalı (şablon kendi kaçışını yapar)
$vrow = ['id_vendor' => 1, 'store_name' => $xss, 'store_slug' => 's', 'status' => 'active', 'email' => $xss, 'phone' => '', 'city' => $xss, 'commission_rate' => 10, 'product_count' => 2, 'plan_code' => 'free', 'owner_name' => $xss, 'owner_email' => $xss, 'date_add' => 'd', 'rejection_reason' => $xss];
foreach (['vendors', 'products', 'orders', 'finance', 'commission', 'plans', 'settings'] as $tab) {
	$smarty->setEscapeHtml(false);
	$smarty->assign(['pageTitle' => 'Satıcılar', 'flash' => $xss, 'flashType' => 'success', 'rvTab' => $tab, 'rvVendors' => [$vrow], 'rvCounts' => ['active' => 1, 'pending' => 0], 'rvStatuses' => RvVendor::STATUSES, 'rvFilterStatus' => '', 'rvQuery' => $xss, 'rvPending' => ['003_orders_finance'],
		'rvModerationQueue' => [['id_product' => 1, 'product_name' => $xss, 'price' => 1, 'stock' => 1, 'id_vendor' => 1, 'store_name' => $xss, 'moderation' => 'pending', 'moderation_note' => $xss, 'date_add' => 'd']], 'rvModState' => 'pending',
		'rvVendorOrders' => [['id_vendor_order' => 1, 'reference' => $xss, 'id_order' => 1, 'store_name' => $xss, 'status' => 'pending', 'subtotal' => 5, 'commission_rate' => 10, 'commission_amount' => 1, 'vendor_earning' => 4, 'cargo_company' => '', 'tracking_number' => '', 'date_add' => 'd']], 'rvOrderStatuses' => RvOrders::STATUSES, 'rvTotals' => ['orders' => 1, 'gross' => 5, 'commission' => 1, 'earning' => 4],
		'rvBalances' => [1 => ['pending' => 1, 'available' => 2, 'earned' => 3, 'paid_out' => 0]], 'rvPayouts' => [['id_payout' => 1, 'date_add' => 'd', 'store_name' => $xss, 'amount' => 1, 'reference' => $xss]],
		'rvAutoApprove' => false, 'rvModeration' => true, 'rvDefaultCommission' => 10, 'rvHoldDays' => 7, 'rvPanelUrl' => '/p', 'adminToken' => 'tok',
		'rvFreeMode' => true, 'rvPlans' => [['code' => 'free', 'name' => $xss, 'price_monthly' => 0, 'max_products' => null, 'max_xml_connections' => null, 'max_marketplace_accounts' => null, 'active' => 1, 'sort_order' => 0]],
		'rvCommDays' => 30, 'rvCommReady' => true, 'rvCommSources' => RvCommission::SOURCES, 'rvCategories' => [['id_category' => 1, 'category_name' => $xss]],
		'rvCommRules' => [['id_rule' => 1, 'scope' => 'category', 'id_target' => 1, 'target_name' => $xss, 'rate' => 12.5, 'include_children' => 1, 'active' => 1, 'note' => $xss, 'date_upd' => 'd']],
		'rvCommReport' => ['days' => 30, 'orders' => 1, 'gross' => 10, 'commission' => 1, 'vendor_earning' => 9, 'effective_rate' => 10, 'cancelled_orders' => 0, 'cancelled_commission' => 0,
			'by_vendor' => [['store_name' => $xss, 'orders' => 1, 'gross' => 10, 'commission' => 1]], 'by_category' => [['category_name' => $xss, 'gross' => 10, 'commission' => 1]], 'by_source' => [['label' => $xss, 'commission' => 1]]]]);
	try { $html = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl'); $okR = true; } catch (Throwable $e) { $html = $e->getMessage(); $okR = false; }
	ok($okR && strpos($html, '<script>alert(1)</script>') === false && strlen($html) > 300, "admin sekmesi '$tab' render + XSS kaçışı" . ($okR ? '' : " ($html)"));
}
[$o, $html] = $renderOk('panel_layout', array_merge($layoutBase, ['denied' => true, 'contentFile' => 'file:x']));
ok($o && strpos($html, 'Erişim yok') !== false, 'yetkisiz bölüm "Erişim yok" gösterir');
[$o, $html] = $renderOk('panel_status', ['rvTitle' => 's', 'vendor' => $vendorRow, 'statusLabel' => 'Onay Bekliyor', 'logoutUrl' => '/c']);
ok($o && strpos($html, '<script>alert(1)</script>') === false, 'durum sayfası render' . ($o ? '' : " ($html)"));
ok(strpos(file_get_contents(dirname(__DIR__) . '/assets/css/panel.css'), '@media (max-width:900px)') !== false, 'CSS mobil kırılım noktası içeriyor (görsel olarak test EDİLMEDİ)');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
foreach ($failures as $f) echo " BAŞARISIZ: $f\n";
exit($fail ? 1 : 0);
