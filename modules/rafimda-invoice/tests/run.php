<?php
/** rafimda-invoice testleri (SQLite + gerçek Smarty; GERÇEK ENTEGRATÖR/GİB YOK — test bağdaştırıcısı). Vergisel doğruluk testi DEĞİLDİR. */
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
require_once dirname(__DIR__) . '/rafimda-invoice.php';
$pass = 0; $fail = 0;
if (!function_exists('ok')) { function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } } }
if (!function_exists('section')) { function section(string $t): void { echo "\n== $t ==\n"; } }
$tmp = sys_get_temp_dir() . '/ri_' . getmypid(); @mkdir($tmp, 0777, true);
RiInvoice::$storageOverride = $tmp . '/inv';
$GLOBALS['domain'] = 'https://test.local/';
function world(): array {
	rv_fresh_db(); rv_apply_migrations(); Settings::$d = [];
	foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/install.sql'))))) as $st) { $GLOBALS['db']->exec($st); }
	foreach (['products ADD COLUMN vat REAL', 'orders ADD COLUMN customer_email TEXT', 'orders ADD COLUMN company_name TEXT', 'orders ADD COLUMN tax_office TEXT', 'orders ADD COLUMN tax_number TEXT', 'orders ADD COLUMN invoice_type TEXT', 'orders ADD COLUMN total REAL', 'orders ADD COLUMN shipping REAL DEFAULT 0'] as $a) { $GLOBALS['db']->exec('ALTER TABLE ' . $a); }
	// install() SQL'i MySQL'e çalıştırır (runSqlFile); burada şema yukarıda yüklendi, install()'ın varsayılan adımları aynı sırayla uygulanır
	foreach (['TRIGGER' => 'manual', 'LIVE' => '0', 'AUTO_SEND' => '0'] as $k => $v) { Settings::set('RAFIMDA_INVOICE_' . $k, $v); }
	RiInvoice::saveAccount('platform', 0, ['integrator' => 'test', 'environment' => 'test']);
	$A = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'Moda Evi', 'store_slug' => 'moda', 'status' => 'active', 'legal_name' => 'Moda Evi Ltd.', 'tax_office' => 'Konak', 'tax_number' => '1234567890', 'city' => 'İzmir', 'email' => 'moda@x.com']);
	$B = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 4, 'store_name' => 'Diğer', 'store_slug' => 'diger', 'status' => 'active', 'legal_name' => 'Diğer A.Ş.', 'tax_office' => 'Çankaya', 'tax_number' => '2234567890', 'city' => 'Ankara', 'email' => 'd@x.com']);
	$mk = function (?int $v, string $n, $vat) { $id = (int) DB::insert('products', ['product_name' => $n, 'price' => 120, 'stock' => 5, 'active' => 1, 'vat' => $vat]); if ($v) { DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $v]); } return $id; };
	$pA = $mk($A, 'Tişört', 20); $pB = $mk($B, 'Kitap', 10); $pR = $mk(null, 'Rafımda Hediye Kartı', 20); $pX = $mk($A, 'KDV tanımsız ürün', null);
	DB::execute("INSERT INTO orders (id_order, id_user, reference, status, customer_name, customer_email, address_text, address_district, address_city, total, shipping) VALUES (50, 3, 'R50', 4, 'Ayşe Kaya', 'ayse@x.com', 'Moda Cad. 1', 'Kadıköy', 'İstanbul', 450, 30)");
	DB::execute('INSERT INTO order_detail (id_order, id_product, product_name, price, total, qty) VALUES (50, ?, "Tişört", 120, 240, 2), (50, ?, "Kitap", 110, 110, 1), (50, ?, "Rafımda Hediye Kartı", 100, 100, 1)', [$pA, $pB, $pR]);
	DB::execute("INSERT INTO orders (id_order, id_user, reference, status, customer_name, company_name, tax_office, tax_number, invoice_type, address_text, address_city, total) VALUES (51, 5, 'R51', 2, 'Can', 'Örnek Ticaret A.Ş.', 'Beşiktaş', '0000000000', 'corporate', 'Barbaros Blv. 5', 'İstanbul', 240)");
	DB::execute('INSERT INTO order_detail (id_order, id_product, product_name, price, total, qty) VALUES (51, ?, "Tişört", 120, 240, 2)', [$pA]);
	DB::execute("INSERT INTO orders (id_order, id_user, reference, status, customer_name, address_text, address_city, total) VALUES (52, 3, 'R52', 1, 'Ayşe Kaya', 'Moda Cad. 1', 'İstanbul', 100)");
	DB::execute('INSERT INTO order_detail (id_order, id_product, product_name, price, total, qty) VALUES (52, ?, "KDV tanımsız ürün", 100, 100, 1)', [$pX]);
	return [$A, $B, $pA, $pB, $pR];
}

section('Kurulum ve güvenli varsayılanlar');
[$A, $B] = world();
ok(RiInvoice::setting('TRIGGER') === 'manual' && !RiInvoice::liveEnabled() && RiInvoice::setting('AUTO_SEND') === '0', 'varsayılan: elle tetikleme, canlı gönderim KAPALI, otomatik gönderim kapalı');
ok(RiInvoice::account('platform')['integrator'] === 'test' && RiInvoice::account('platform')['environment'] === 'test', 'platform hesabı test bağdaştırıcısıyla başlar');
$sql = file_get_contents(dirname(__DIR__) . '/install.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE|UPDATE|ALTER)\b/i', $sql) && trim(file_get_contents(dirname(__DIR__) . '/uninstall.sql')) === 'SELECT 1;', 'kurulum yıkıcı değil; kaldırma faturaları silmez');
ok(!preg_match('/\b(18|20)\.?0*\s*[,;)]/', preg_replace('/\/\*.*?\*\//s', '', file_get_contents(dirname(__DIR__) . '/lib/RiInvoice.php'))) || !str_contains(file_get_contents(dirname(__DIR__) . '/lib/RiInvoice.php'), "'vat' => 20"), 'KDV oranı kodda sabitlenmemiş');

section('Pazaryeri bölünmesi ve mükerrer engeli');
$r = RiInvoice::prepareForOrder(50);
$inv = DB::execute('SELECT * FROM ri_invoices WHERE id_order = 50 ORDER BY id_invoice') ?: [];
ok($r['created'] === 3 && count($inv) === 3, 'çok satıcılı sipariş: satıcı başına ayrı fatura + Rafımda kendi ürünü için ayrı (3)');
$by = array_column($inv, null, 'idem_key');
ok($by["sale:50:$A"]['issuer_type'] === 'vendor' && $by["sale:50:$B"]['issuer_type'] === 'vendor' && $by['direct:50']['issuer_type'] === 'platform' && $by['direct:50']['kind'] === 'direct', 'satış faturasını satıcı düzenler; Rafımda yalnızca kendi sattığını');
$la = json_decode($by["sale:50:$A"]['lines'], true)[0];
ok(abs($la['net'] - 200.0) < 0.01 && abs($la['vat'] - 40.0) < 0.01 && $la['vat_rate'] == 20 && $la['vat_source'] === 'product', 'KDV ürün alanından: 240 dahil → 200 + 40 (%20)');
$lb = json_decode($by["sale:50:$B"]['lines'], true)[0];
ok(abs($lb['net'] - 100.0) < 0.01 && $lb['vat_rate'] == 10, 'farklı oran (%10) ürün bazında uygulanır');
$r2 = RiInvoice::prepareForOrder(50);
ok($r2['created'] === 0 && $r2['skipped'] === 3 && (int) DB::getValue('SELECT COUNT(*) FROM ri_invoices') === 3, 'ikinci hazırlama mükerrer fatura üretmez');
ok((int) DB::getValue("SELECT COUNT(*) FROM ri_events WHERE event = 'shipping_note'") === 1, 'kargo ücreti için kural tanımsız uyarısı kaydedilir (varsayım yapılmaz)');
$r = RiInvoice::prepareForOrder(52);
$x = DB::getRowSafe('ri_invoices', 'id_order = 52');
ok($x['status'] === 'draft' && str_contains($x['last_error'], 'KDV oranı tanımsız'), 'KDV oranı tanımsızsa fatura TASLAK kalır (sabit oran varsayılmaz)');
Settings::set('RAFIMDA_INVOICE_DEFAULT_VAT', '20');
DB::execute('DELETE FROM ri_invoices WHERE id_order = 52'); RiInvoice::prepareForOrder(52);
ok(DB::getRowSafe('ri_invoices', 'id_order = 52')['status'] === 'ready' && json_decode(DB::getRowSafe('ri_invoices', 'id_order = 52')['lines'], true)[0]['vat_source'] === 'setting', 'yönetici varsayılan KDV ayarlarsa kullanılır ve kaynağı işaretlenir');
DB::execute('UPDATE orders SET status = 5 WHERE id_order = 51');
ok(!RiInvoice::prepareForOrder(51)['success'], 'iptal edilmiş siparişe satış faturası hazırlanmaz');
DB::execute('UPDATE orders SET status = 2 WHERE id_order = 51');

section('Tetikleyici (sipariş oluşması tek başına kesim zamanı değil)');
[$A, $B] = world();
RiInvoice::onOrderEvent(['id_order' => 50, 'status' => 4]);
ok((int) DB::getValue('SELECT COUNT(*) FROM ri_invoices') === 0, 'tetikleyici "elle" iken sipariş olayları fatura hazırlamaz');
Settings::set('RAFIMDA_INVOICE_TRIGGER', 'delivered');
RiInvoice::onOrderEvent(['id_order' => 51, 'status' => 2]);
ok((int) DB::getValue('SELECT COUNT(*) FROM ri_invoices') === 0, '"teslimde" ayarında hazırlanıyor durumundaki sipariş faturalanmaz');
RiInvoice::onOrderEvent(['id_order' => 50, 'status' => 4]);
ok((int) DB::getValue('SELECT COUNT(*) FROM ri_invoices') === 3 && (int) DB::getValue("SELECT COUNT(*) FROM ri_invoices WHERE ettn <> ''") === 0, 'teslim olayı faturaları HAZIRLAR, otomatik gönderim kapalıyken göndermez');

section('Gönderim (test bağdaştırıcısı)');
$idR = (int) DB::getValue("SELECT id_invoice FROM ri_invoices WHERE idem_key = 'direct:50'");
$idA = (int) DB::getValue("SELECT id_invoice FROM ri_invoices WHERE idem_key = 'sale:50:$A'");
$r = RiInvoice::send($idA);
ok(!$r['success'] && str_contains($r['message'], 'entegratör hesabı'), 'satıcının entegratör hesabı yoksa satıcı faturası gönderilmez (Rafımda adına kesilmez)');
$r = RiInvoice::send($idR);
$i = RiInvoice::get($idR);
ok($r['success'] && $i['status'] === 'accepted' && str_starts_with($i['invoice_no'], 'TST') && preg_match('/^[0-9a-f-]{36}$/', $i['ettn']) === 1 && $i['doc_type'] === 'earsiv' && $i['environment'] === 'test', 'Rafımda faturası test bağdaştırıcısıyla: TST no, ETTN, bireysel → e-Arşiv, ortam TEST');
ok(!RiInvoice::send($idR)['success'] && (int) DB::getValue("SELECT COUNT(*) FROM ri_events WHERE id_invoice = ? AND event = 'sent'", [$idR]) === 1, 'gönderilmiş fatura ikinci kez gönderilmez');
$pdf = RiInvoice::pdfFor($idR, 'admin');
ok($pdf !== null && str_starts_with((string) file_get_contents($pdf), '%PDF-1.4') && is_file(dirname($pdf) . '/.htaccess'), 'PDF üretilir; dizin web erişimine kapalı');
$txt = (string) shell_exec('pdftotext ' . escapeshellarg((string) $pdf) . ' - 2>/dev/null');
ok($txt === '' || (str_contains($txt, 'TEST') && str_contains($txt, 'MALİ DEĞERİ YOKTUR') && str_contains($txt, 'Ayşe Kaya') && str_contains($txt, 'Hediye')), 'PDF metni: TEST uyarısı, Türkçe karakterler ve alıcı (pdftotext)' . ($txt === '' ? ' [pdftotext yok]' : ''));
ok(RiInvoice::pdfFor($idR, 'customer', 3) !== null && RiInvoice::pdfFor($idR, 'customer', 4) === null && RiInvoice::pdfFor($idR, 'vendor', 0, $A) === null, 'PDF erişimi: sahibi müşteri görür; başka müşteri ve ilgisiz satıcı göremez');
ok((int) DB::getValue("SELECT COUNT(*) FROM rafimda_notifications WHERE id_user = 3 AND type = 'invoice'") === 1, 'müşteriye fatura bildirimi');
ok(count(RiInvoice::forUser(3)) === 1 && RiInvoice::forUser(4) === [], 'müşteri yalnızca kendi gönderilmiş faturalarını görür');

section('Satıcı hesabı ve şifre güvenliği');
ok(!RiInvoice::saveAccount('vendor', $A, ['integrator' => 'qnb', 'environment' => 'live'])['success'], 'hazır olmayan bağdaştırıcıda canlı ortam seçilemez');
ok(!RiInvoice::saveAccount('vendor', $A, ['integrator' => 'test', 'environment' => 'live'])['success'], 'test bağdaştırıcısı canlı ortamda kullanılamaz');
RiInvoice::saveAccount('vendor', $A, ['integrator' => 'qnb', 'environment' => 'test', 'username' => 'moda', 'secret' => 'GizliSifre123']);
$acc = DB::getRowSafe('ri_accounts', 'owner_type = ? AND id_vendor = ?', ['vendor', $A]);
ok(!str_contains(json_encode($acc), 'GizliSifre123') && str_starts_with((string) $acc['secret_enc'], 'v1.') && RiInvoice::accountView($acc)['has_secret'] === true && !isset(RiInvoice::accountView($acc)['secret_enc']), 'şifre şifreli saklanır, görünümde yer almaz');
$t = RiInvoice::testAccount('vendor', $A);
ok(!$t['success'] && str_contains($t['message'], 'henüz uygulanmadı') && DB::getRowSafe('ri_accounts', 'id_vendor = ?', [$A])['status'] === 'failed', 'QNB bağdaştırıcısı hazır değil: bağlantı testi açıkça BAŞARISIZ (çalışıyormuş gibi görünmez)');
$r = RiInvoice::send($idA);
ok(!$r['success'] && RiInvoice::get($idA)['ettn'] === '' && str_contains(RiInvoice::get($idA)['last_error'], 'uygulanmadı'), 'hazır olmayan entegratörle gönderim yapılmaz ve hata kaydı düşer');
ok(!str_contains(json_encode(RiInvoice::events()), 'GizliSifre123'), 'şifre hiçbir olay kaydında yok');
RiInvoice::saveAccount('vendor', $A, ['integrator' => 'test', 'environment' => 'test']);
ok(RiInvoice::send($idA, 'vendor', $B)['success'] === false, 'başka satıcı bu faturayı gönderemez');
ok(RiInvoice::send($idA, 'vendor', $A)['success'] && RiInvoice::pdfFor($idA, 'vendor', 0, $A) !== null && RiInvoice::pdfFor($idA, 'vendor', 0, $B) === null, 'satıcı kendi faturasını gönderir ve PDF\'ini görür; diğer satıcı göremez');
ok(count(RiInvoice::forVendor($A)) === 1 && count(RiInvoice::forVendor($B)) === 1, 'satıcı yalnızca kendi faturalarını listeler');

section('e-Fatura / e-Arşiv belirleme');
RiInvoice::prepareForOrder(51);
$id51 = (int) DB::getValue('SELECT id_invoice FROM ri_invoices WHERE id_order = 51');
$i51 = RiInvoice::get($id51);
ok($i51['buyer_type'] === 'company' && $i51['buyer_name'] === 'Örnek Ticaret A.Ş.' && $i51['buyer_tax_office'] === 'Beşiktaş', 'kurumsal alıcı bilgileri siparişten alınır');
RiInvoice::send($id51, 'vendor', $A);
ok(RiInvoice::get($id51)['doc_type'] === 'efatura', 'kayıtlı e-Fatura kullanıcısı alıcıya e-Fatura (mükellef sorgusu bağdaştırıcıdan)');
DB::execute("UPDATE orders SET tax_office = '' WHERE id_order = 51"); DB::execute("DELETE FROM ri_invoices WHERE id_order = 51");
RiInvoice::prepareForOrder(51);
ok(DB::getRowSafe('ri_invoices', 'id_order = 51')['status'] === 'draft' && str_contains(DB::getRowSafe('ri_invoices', 'id_order = 51')['last_error'], 'vergi dairesi'), 'eksik alıcı bilgisi → taslak (gönderilemez)');

section('İptal ve iade (silme yok)');
ok(!RiInvoice::cancel($idA, 'kısa')['success'], 'iptal gerekçe ister');
ok(RiInvoice::cancel($idA, 'Müşteri talebiyle iptal', 'vendor', $A)['success'] && RiInvoice::get($idA)['status'] === 'cancelled' && RiInvoice::get($idA)['ettn'] !== '', 'iptal: kayıt ve ETTN korunur, durum "İptal edildi"');
ok(RiInvoice::registerReturn($idR, 'Ürün iade edildi')['success'] && !RiInvoice::registerReturn($idR, 'tekrar')['success'], 'iade kaydı ilişkili ayrı kayıt; ikincisi açılmaz');
$ret = DB::getRowSafe('ri_invoices', 'idem_key = ?', ['return:' . $idR]);
ok($ret['status'] === 'draft' && (int) $ret['related_invoice'] === $idR && str_contains($ret['last_error'], 'mali müşavir') && RiInvoice::get($idR)['status'] === 'accepted', 'iade belgesi türü mali müşavire bırakılır; orijinal fatura değişmez');

section('Canlı gönderim kilidi');
DB::execute("UPDATE ri_accounts SET environment = 'live' WHERE owner_type = 'platform'");
RiInvoice::prepareForOrder(52); $id52 = (int) DB::getValue('SELECT id_invoice FROM ri_invoices WHERE id_order = 52');
Settings::set('RAFIMDA_INVOICE_DEFAULT_VAT', '20'); DB::execute("UPDATE ri_invoices SET status = 'ready', last_error = '' WHERE id_invoice = ?", [$id52]);
DB::execute("UPDATE ri_invoices SET issuer_type = 'platform', kind = 'direct' WHERE id_invoice = ?", [$id52]);
$r = RiInvoice::send($id52);
ok(!$r['success'] && str_contains($r['message'], 'Canlı fatura gönderimi kapalı'), 'canlı hesap olsa bile RAFIMDA_INVOICE_LIVE kapalıyken gönderilmez');
$_POST = []; $f = RafimdaInvoiceModule::handlePost(['riAction' => 'settings', 'trigger' => 'paid', 'live' => '1', 'live_confirm' => 'evet', 'default_vat' => '20', 'service_vat' => '20']);
ok($f['type'] === 'danger' && !RiInvoice::liveEnabled() && RiInvoice::setting('TRIGGER') === 'paid', 'onay ifadesi olmadan canlı gönderim açılmaz (diğer ayarlar kaydedilir)');
$f = RafimdaInvoiceModule::handlePost(['riAction' => 'settings', 'trigger' => 'paid', 'default_vat' => 'abc', 'service_vat' => '150']);
ok(RiInvoice::setting('DEFAULT_VAT') === '' && RiInvoice::setting('SERVICE_VAT') === '', 'geçersiz KDV oranları kaydedilmez');

section('Hizmet / komisyon faturası (Rafımda → satıcı)');
[$A, $B] = world();
DB::execute("INSERT INTO rafimda_vendor_orders (id_order, id_vendor, status, subtotal, commission_amount, date_add) VALUES (50, ?, 'delivered', 240, 24, ?), (51, ?, 'delivered', 240, 12, ?), (52, ?, 'shipped', 100, 50, ?)", [$A, date('Y-m-05 10:00:00'), $A, date('Y-m-06 10:00:00'), $B, date('Y-m-07 10:00:00')]);
ok(!RiInvoice::prepareServiceInvoices(date('Y-m'))['success'], 'hizmet KDV oranı ayarlanmadan komisyon faturası hazırlanmaz');
Settings::set('RAFIMDA_INVOICE_SERVICE_VAT', '20');
$r = RiInvoice::prepareServiceInvoices(date('Y-m'));
$s = DB::getRowSafe('ri_invoices', "kind = 'service'");
ok($r['created'] === 1 && (int) $s['id_vendor'] === $A && abs((float) $s['net_total'] - 36.0) < 0.01 && abs((float) $s['vat_total'] - 7.2) < 0.01 && $s['issuer_type'] === 'platform' && $s['buyer_name'] === 'Moda Evi Ltd.', 'teslim edilmiş satıcı siparişlerinin komisyonu: satıcı başına tek fatura (36 + %20)');
ok(RiInvoice::prepareServiceInvoices(date('Y-m'))['created'] === 0 && !RiInvoice::prepareServiceInvoices('2026-13')['success'], 'aynı dönem için tekrar üretilmez; geçersiz dönem reddedilir');
ok(RiInvoice::send((int) $s['id_invoice'])['success'] && count(RiInvoice::forVendor($A)) === 1 && RiInvoice::pdfFor((int) $s['id_invoice'], 'vendor', 0, $A) !== null, 'satıcı kendisine kesilen hizmet faturasını görür');

section('Manuel fatura');
ok(!RiInvoice::createManual('platform', 0, ['buyer_name' => 'X', 'description' => 'Danışmanlık', 'gross' => '100', 'vat_rate' => ''], 'admin')['success'], 'KDV oranı olmadan manuel fatura yok');
ok(RiInvoice::createManual('platform', 0, ['buyer_name' => 'Deneme Müşteri', 'buyer_address' => 'Adres satırı 1', 'description' => 'Reklam hizmeti', 'gross' => '1200', 'vat_rate' => '20'], 'admin')['success'], 'manuel fatura hazırlanır');

section('Ekranlar');
$sm = new Smarty\Smarty(); $sm->setCompileDir($tmp . '/c'); @mkdir($tmp . '/c'); $sm->registerPlugin('modifier', 'adminT', static fn($s) => $s);
foreach (['invoices', 'prepare', 'integrator', 'settings', 'errors'] as $t) {
	$sm->clearAllAssign(); $sm->assign(['riFlash' => null, 'riToken' => 'tk'] + RafimdaInvoiceModule::adminVars(['tab' => $t]));
	$h = $sm->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl');
	ok(str_contains($h, 'Canlı gönderim kapalı') && str_contains($h, 'nav-tabs'), "yönetici sekmesi: $t");
}
$sm->clearAllAssign(); $sm->assign(['riFlash' => null, 'riToken' => 'tk'] + RafimdaInvoiceModule::adminVars(['tab' => 'invoices', 'id' => (int) $s['id_invoice']]));
$h = $sm->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl');
ok(str_contains($h, 'Rafımda hizmet/komisyon faturası') && str_contains($h, 'PDF görüntüle') && str_contains($h, 'Geçmiş'), 'fatura detayı: tür, PDF, geçmiş');
ok(str_contains(file_get_contents(dirname(__DIR__, 2) . '/rafimda-vendor/lib/RvPanel.php'), "class_exists('RiPanel', false)"), 'satıcı paneli "Faturalarım" yalnızca modül kuruluysa açılır');

section('Satıcı paneli (gerçek denetleyici) ve müşteri şablonları');
function vdrive(array $cfg): string { return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/rafimda-vendor/tests/drive.php') . ' ' . escapeshellarg(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . ' 2>&1'); }
$o = vdrive(['get' => ['s' => 'invoices'], 'invoice' => 1]);
ok(str_contains($o, 'Faturalarım') && str_contains($o, 'Canlı gönderim kapalı') && str_contains($o, 'name="secret"') && !str_contains($o, 'Warning') && !str_contains($o, 'Fatal'), 'satıcı "Faturalarım": vergi bilgisi, entegratör formu, durum');
ok(str_contains(vdrive(['get' => ['s' => 'dashboard'], 'invoice' => 1]), 'satici-panel?s=invoices') && !str_contains(vdrive(['get' => ['s' => 'dashboard']]), 'satici-panel?s=invoices'), 'menüde "Faturalar" yalnızca modül kuruluysa');
$o = vdrive(['get' => ['s' => 'invoices'], 'invoice' => 1, 'method' => 'POST', 'post' => ['action' => 'account', 'integrator' => 'test', 'environment' => 'test', 'username' => 'u', 'secret' => 'S3cret!'], 'probes' => ["SELECT integrator || '|' || (secret_enc LIKE 'v1.%') FROM ri_accounts WHERE owner_type = 'vendor'"]]);
ok(str_contains($o, 'PROBE0:"test|1"'), 'satıcı entegratör hesabını kaydeder (şifre şifreli)');
$o = vdrive(['user' => 2, 'get' => ['s' => 'invoices'], 'invoice' => 1, 'method' => 'POST', 'post' => ['action' => 'account', 'integrator' => 'test'], 'probes' => ['SELECT COUNT(*) FROM ri_accounts']]);
ok(str_contains($o, 'PROBE0:0'), 'yetkisiz personel fatura ayarı değiştiremez');
$sm2 = new Smarty\Smarty(); $sm2->setCompileDir($tmp . '/c2'); @mkdir($tmp . '/c2'); $sm2->setEscapeHtml(true);
$er = error_reporting(E_ERROR); // boş değişkenlerle yalnızca derleme/çizim hatası aranır
foreach (['account', 'review', 'store', 'product_seller'] as $t) {
	try { $sm2->fetch('file:' . dirname(__DIR__, 2) . "/rafimda-vendor/assets/templates/front/$t.tpl"); ok(true, "şablon derlenir ve çizilir: $t.tpl"); } catch (Throwable $e) { ok(false, "şablon: $t.tpl — " . $e->getMessage()); }
}
error_reporting($er);
$sm2->assign(['acNotifications' => [], 'acTickets' => [], 'acTicket' => null, 'acFollowed' => [], 'acDelivered' => [], 'acOrders' => [], 'acConsents' => null, 'acConsentLabels' => [], 'acStatuses' => [], 'acCategories' => ['order' => 'Sipariş'],
	'acToken' => 't', 'acFlash' => null, 'acReviewUrl' => '/d', 'acSelf' => '/h', 'acStoreBase' => '/m', 'acCookieUrl' => '/c', 'acEmailOff' => true, 'acInvoices' => RiInvoice::forUser(3), 'acInvoiceStatuses' => RiInvoice::STATUSES, 'acPdfBase' => '/fatura-pdf']);
$h = $sm2->fetch('file:' . dirname(__DIR__, 2) . '/rafimda-vendor/assets/templates/front/account.tpl');
ok(str_contains($h, 'Faturalarım') && (RiInvoice::forUser(3) === [] || (str_contains($h, '/fatura-pdf?id=') && str_contains($h, 'TEST'))), 'müşteri hesap merkezi: Faturalarım, PDF bağlantısı, TEST etiketi');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
