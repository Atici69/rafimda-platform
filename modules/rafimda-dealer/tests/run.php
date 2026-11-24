<?php
require __DIR__ . '/bootstrap.php';

section('Migration ve şema');
rd_fresh_db();
ok(RdMigrator::files() === ['001_init'], 'migration dosyası bulundu');
rd_install();
ok(RdMigrator::pending() === [], 'uygulandıktan sonra bekleyen yok');
foreach (['rd_dealers', 'rd_plans', 'rd_subscriptions', 'rd_customers', 'rd_commissions', 'rd_audit_log', 'rd_migrations'] as $t) {
	ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "tablo: $t");
}
$raw = file_get_contents(dirname(__DIR__) . '/migrations/001_init.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|RENAME)\b/i', $raw) && !preg_match('/^\s*--/m', $raw), 'migration yıkıcı komut ve "--" içermiyor');
ok(preg_match_all('/CREATE TABLE(?! IF NOT EXISTS)/', $raw) === 0, 'tüm CREATE TABLE IF NOT EXISTS');
ok(strpos(file_get_contents(dirname(__DIR__) . '/uninstall.sql'), 'DROP') === false, 'uninstall.sql veri silmiyor');
ok(count(RdPlans::all()) === 1 && RdPlans::all()[0]['code'] === 'baslangic', 'varsayılan paket oluştu');
RdPlans::ensureDefault();
ok(count(RdPlans::all()) === 1, 'ensureDefault idempotent');

section('Başvuru doğrulama');
$v = RdDealer::validate(['company_name' => 'ab', 'contact_name' => 'x', 'phone' => '123', 'email' => 'bozuk', 'city' => '', 'tax_number' => '123']);
ok(isset($v['errors']['company_name'], $v['errors']['contact_name'], $v['errors']['phone'], $v['errors']['email'], $v['errors']['city'], $v['errors']['tax_number']), 'geçersiz alanlar reddedilir');
$good = ['company_name' => '<b>Mega Bayi</b>', 'contact_name' => 'Veli Yılmaz', 'phone' => '0532 111 22 33', 'email' => 'A@B.com', 'city' => 'İzmir', 'tax_number' => '1234567890', 'note' => '<script>x</script>Merhaba'];
$v1 = RdDealer::validate($good);
ok($v1['ok'] && $v1['clean']['company_name'] === 'Mega Bayi' && $v1['clean']['email'] === 'a@b.com', 'HTML temizlenir, e-posta küçülür');
ok(strpos($v1['clean']['note'], '<') === false, 'not alanında etiket yok');

section('Başvuru → onay → bağlam');
$r = RdDealer::create(2, $v1['clean']);
ok($r['success'] && $r['id_dealer'] > 0, 'bayi başvurusu oluştu');
$A = $r['id_dealer'];
ok(RdDealer::get($A)['status'] === 'pending', 'başvuru "değerlendiriliyor" (otomatik onay yok)');
ok(preg_match('/^[A-Z2-9]{8}$/', RdDealer::get($A)['referral_code']) === 1, 'referans kodu 8 karakter, karışmayan alfabe');
ok(!RdDealer::create(2, $v1['clean'])['success'], 'aynı kullanıcı ikinci başvuru yapamaz');
Customer::as(2); RdContext::reset();
ok(RdContext::current() !== null && !RdContext::isOperational(), 'onaylanmadan panel işlevi yok');
ok(RdDealer::byCode(RdDealer::get($A)['referral_code']) === null, 'onaysız bayinin referans kodu geçersiz');
ok(!RdDealer::setStatus($A, 'hacked', 1), 'geçersiz durum reddedilir');
ok(RdDealer::setStatus($A, 'active', 1), 'yönetici onayladı');
RdContext::reset();
ok(RdContext::isOperational() && RdContext::isTopLevel(), 'onaydan sonra panel açık, ana bayi');
$codeA = RdDealer::get($A)['referral_code'];
ok(RdDealer::byCode(strtolower($codeA))['id_dealer'] == $A, 'kod büyük/küçük harf duyarsız');
ok(RdDealer::byCode('') === null && RdDealer::byCode("'; DROP TABLE x;--") === null, 'boş/kötü amaçlı kod bulunamaz');
Customer::logout(); RdContext::reset();
ok(RdContext::current() === null, 'oturumsuz bağlam yok');
Customer::as(3); RdContext::reset();
ok(RdContext::current() === null, 'bayisi olmayan kullanıcıda bağlam yok');

section('Alt bayi (3 seviye)');
$codeBad = RdDealer::create(4, RdDealer::validate(['company_name' => 'Alt Bayi 1', 'contact_name' => 'Fatma Alt', 'phone' => '05321112233', 'email' => 'f@x.com', 'city' => 'Bursa'])['clean'], $codeA);
ok(!$codeBad['success'], 'paket alt bayiye izin vermiyorsa (kontenjan 0) başvuru reddedilir');
RdPlans::save(['code' => 'pro', 'name' => 'Pro', 'monthly_price' => '499', 'commission_rate' => '5', 'max_sub_dealers' => '1', 'active' => '1']);
ok(RdPlans::assign($A, 'pro', '', 1)['success'], 'yönetici Pro paket atadı');
$sub = RdDealer::create(4, RdDealer::validate(['company_name' => 'Alt Bayi 1', 'contact_name' => 'Fatma Alt', 'phone' => '05321112233', 'email' => 'f@x.com', 'city' => 'Bursa'])['clean'], $codeA);
ok($sub['success'], 'kontenjan varken alt bayi başvurusu alındı');
$S = $sub['id_dealer'];
ok((int) RdDealer::get($S)['id_parent'] === $A && RdDealer::level(RdDealer::get($S)) === 2, 'alt bayi seviyesi 2');
$sub2 = RdDealer::create(5, RdDealer::validate(['company_name' => 'Alt Bayi 2', 'contact_name' => 'Can', 'phone' => '05321112233', 'email' => 'c@x.com', 'city' => 'Van'])['clean'], $codeA);
ok(!$sub2['success'], 'kontenjan dolunca ikinci alt bayi reddedilir');
RdDealer::setStatus($S, 'active', 1);
$codeS = RdDealer::get($S)['referral_code'];
RdPlans::assign($S, 'pro', '', 1);
$deep = RdDealer::create(5, RdDealer::validate(['company_name' => 'Alt Alt Bayi', 'contact_name' => 'Can', 'phone' => '05321112233', 'email' => 'c@x.com', 'city' => 'Van'])['clean'], $codeS);
ok(!$deep['success'], 'alt bayinin altına bayi eklenemez (en fazla 3 seviye)');
ok(!RdDealer::create(2, RdDealer::validate($good)['clean'], $codeA)['success'], 'kendi koduyla başvuru yapılamaz');
ok(RdDealer::create(5, RdDealer::validate($good)['clean'], 'YOKKOD99')['success'] === false, 'geçersiz üst bayi kodu reddedilir');
ok(RdDealer::setPausedByParent($A, $S, true) && !RdDealer::isOperational(RdDealer::get($S)), 'üst bayi alt bayisini durdurdu');
ok(RdDealer::byCode($codeS) === null, 'durdurulan alt bayinin kodu geçersiz');
ok(RdDealer::setPausedByParent($A, $S, false) && RdDealer::isOperational(RdDealer::get($S)), 'devam ettirildi');
ok(RdDealer::setStatus($A, 'suspended', 1) && !RdDealer::isOperational(RdDealer::get($S)), 'üst bayi askıya alınınca alt bayi de işlevsiz');
RdDealer::setStatus($A, 'active', 1);

section('Veri izolasyonu (sahiplik)');
$B = RdDealer::create(5, RdDealer::validate(['company_name' => 'Başka Bayi', 'contact_name' => 'Can Bayi', 'phone' => '05321112233', 'email' => 'c@x.com', 'city' => 'Van'])['clean'])['id_dealer'];
RdDealer::setStatus($B, 'active', 1);
ok(!RdDealer::setPausedByParent($B, $S, true), 'başka bayi, benim alt bayimi durduramaz');
ok(!RdDealer::setPausedByParent($A, $B, true), 'üst bayi, kendine ait olmayan bayiyi durduramaz');
ok(!RdDealer::setPausedByParent(0, $B, true), 'parent=0 ile ana bayi durdurulamaz');
ok(RdReport::subPerformance($B) === [], 'B bayisi A\'nın alt bayilerini görmez');
ok(count(RdReport::subPerformance($A)) === 1 && (int) RdReport::subPerformance($A)[0]['id_dealer'] === $S, 'A yalnızca kendi alt bayisini görür');

section('Referans ataması ve komisyon');
Settings::set('RAFIMDA_DEALER_DEFAULT_COMMISSION', '2');
ok(abs(RdCommission::rateFor(RdDealer::get($B)) - 2.0) < 0.001, 'plan/özel oran yoksa varsayılan oran');
RdDealer::setRates($A, 10, 0, 1);
ok(abs(RdCommission::rateFor(RdDealer::get($A)) - 10.0) < 0.001, 'bayiye özel oran önceliklidir');
ok(!RdDealer::setRates($A, 90, 20, 1) && !RdDealer::setRates($A, -1, 0, 1) && !RdDealer::setRates($A, 5, 101, 1), 'geçersiz oranlar reddedilir (toplam 100\'ü aşamaz)');
RdDealer::setRates($S, 8, 3, 1);
// Müşteri 3 → ref A
$_SESSION['rd_ref'] = $codeA;
Customer::as(3);
$o1 = DB::insert('orders', ['id_user' => 3, 'status' => 1, 'total' => 1000]);
Module::fire('order.placed', ['id_order' => $o1]);
$row = DB::getRowSafe('rd_commissions', 'id_order = ?', [$o1]);
ok($row && (int) $row['id_dealer'] === $A && abs((float) $row['amount'] - 100.0) < 0.001 && $row['status'] === 'pending', 'ref bayiye %10 komisyon (beklemede)');
ok(DB::getValue('SELECT id_dealer FROM rd_customers WHERE id_user = 3') == $A, 'müşteri bayiye bağlandı');
Module::fire('order.placed', ['id_order' => $o1]);
ok((int) DB::getValue('SELECT COUNT(*) FROM rd_commissions WHERE id_order = ?', [$o1]) === 1, 'aynı sipariş ikinci kez işlenmez (idempotent)');
unset($_SESSION['rd_ref']);
$o2 = DB::insert('orders', ['id_user' => 3, 'status' => 1, 'total' => 500]);
Module::fire('order.placed', ['id_order' => $o2]);
ok(DB::getRowSafe('rd_commissions', 'id_order = ?', [$o2]) !== false, 'sonraki sipariş referans olmadan da bağlı bayiye yazılır');
// İlk dokunuş: başka kod sonradan atamayı değiştirmez
$_SESSION['rd_ref'] = $codeS;
$o3 = DB::insert('orders', ['id_user' => 3, 'status' => 1, 'total' => 100]);
Module::fire('order.placed', ['id_order' => $o3]);
ok((int) DB::getRowSafe('rd_commissions', 'id_order = ?', [$o3])['id_dealer'] === $A, 'ilk dokunuş korunur: müşteri başka kodla bayi değiştirmez');
// Alt bayi satışı: doğrudan %8 + üst bayi payı %3
Customer::as(6); $_SESSION['rd_ref'] = $codeS;
$o4 = DB::insert('orders', ['id_user' => 6, 'status' => 1, 'total' => 2000]);
Module::fire('order.placed', ['id_order' => $o4]);
$d4 = DB::getRowSafe('rd_commissions', 'id_order = ? AND type = ?', [$o4, 'direct']);
$ov4 = DB::getRowSafe('rd_commissions', 'id_order = ? AND type = ?', [$o4, 'override']);
ok($d4 && (int) $d4['id_dealer'] === $S && abs((float) $d4['amount'] - 160.0) < 0.001, 'alt bayiye doğrudan %8 = 160');
ok($ov4 && (int) $ov4['id_dealer'] === $A && (int) $ov4['id_source_dealer'] === $S && abs((float) $ov4['amount'] - 60.0) < 0.001, 'üst bayiye alt bayi payı %3 = 60');
// Kendi kendine referans
Customer::as(2); $_SESSION['rd_ref'] = $codeA;
$o5 = DB::insert('orders', ['id_user' => 2, 'status' => 1, 'total' => 999]);
Module::fire('order.placed', ['id_order' => $o5]);
ok(DB::getRowSafe('rd_commissions', 'id_order = ?', [$o5]) === false, 'bayi kendi kodu ile alışverişte komisyon almaz');
// Onaysız/askıya alınmış bayi komisyon almaz
RdDealer::setStatus($B, 'suspended', 1);
$codeB = RdDealer::get($B)['referral_code'];
$_SESSION['rd_ref'] = $codeB; Customer::as(4);
$o6 = DB::insert('orders', ['id_user' => 4, 'status' => 1, 'total' => 300]);
Module::fire('order.placed', ['id_order' => $o6]);
ok(DB::getRowSafe('rd_commissions', 'id_order = ?', [$o6]) === false && DB::getRowSafe('rd_customers', 'id_user = 4') === false, 'askıdaki bayiye atama/komisyon yok');
// Misafir (id_user 0)
$o7 = DB::insert('orders', ['id_user' => 0, 'status' => 1, 'total' => 50]);
Module::fire('order.placed', ['id_order' => $o7]);
ok(DB::getRowSafe('rd_commissions', 'id_order = ?', [$o7]) === false, 'üyesiz sipariş işlenmez');

section('Sipariş durum değişimi');
DB::update('orders', ['status' => 4], 'id_order = :i', ['i' => $o1]);
Module::fire('order.updated', ['id_order' => $o1, 'status' => 4], 1);
ok(DB::getRowSafe('rd_commissions', 'id_order = ?', [$o1])['status'] === 'approved', 'teslimde komisyon onaylanır');
Module::fire('order.updated', ['id_order' => $o4, 'status' => 5], 1);
ok(DB::getValue('SELECT COUNT(*) FROM rd_commissions WHERE id_order = ? AND status = ?', [$o4, 'cancelled']) == 2, 'iptalde hem doğrudan hem üst bayi payı iptal edilir');
Module::fire('order.updated', ['id_order' => $o1, 'status' => 6], 4);
ok(DB::getRowSafe('rd_commissions', 'id_order = ?', [$o1])['status'] === 'cancelled', 'iadede onaylı komisyon iptal edilir');
$t = RdCommission::totals($A);
ok($t['pending'] > 0 && $t['cancelled'] > 0, 'toplamlar duruma göre ayrılır');
ok(RdCommission::adminSet(DB::getRowSafe('rd_commissions', 'id_order = ?', [$o2])['id_commission'], 'approved', 1) && !RdCommission::adminSet(999999, 'approved', 1) && !RdCommission::adminSet(1, 'bozuk', 1), 'yönetici elle durum değiştirir; geçersiz girdi reddedilir');
// Hook hataları siparişi bozmaz
$threw = false;
try { RdCommission::safeHook(function () { throw new RuntimeException('x'); }); } catch (Throwable $e) { $threw = true; }
ok(!$threw, 'kanca hataları yutulur (sipariş akışı bozulmaz)');

section('Müşteri listesi gizliliği ve raporlar');
$cust = RdReport::customers($A);
ok(count($cust) === 1 && (int) $cust[0]['id_user'] === 3, 'A yalnızca kendi müşterisini görür');
ok(!isset($cust[0]['email']) && $cust[0]['email_masked'] === 'a***@x.com', 'e-posta maskelenir, ham e-posta dönmez');
ok(RdReport::customers($B) === [], 'B\'nin müşterisi yok (izolasyon)');
ok(RdReport::maskEmail('bozuk') === '', 'geçersiz e-posta maskesi boş');
$m = RdReport::monthly($A, 12);
ok(count($m) === 12 && $m[11]['ym'] === date('Y-m'), '12 aylık seri, son ay bu ay');
ok(array_sum(array_column($m, 'orders')) >= 1, 'seride iptal edilmeyen siparişler sayılır');
$sm = RdReport::summary($A);
ok($sm['customers'] === 1 && $sm['sub_dealers'] === 1, 'özet: müşteri ve alt bayi sayısı');
ok(RdReport::platform()['customers'] >= 2, 'yönetici özeti');
ok(RdCommission::listFor($B) === [] && count(RdCommission::listFor($S)) >= 1, 'komisyon listeleri bayiye özel');

section('Yönetici: müşteri bağlama');
DB::execute('DELETE FROM rd_customers WHERE id_user = 5');
ok(RdCommission::attach(5, $A, 'admin') === true && RdCommission::attach(5, $B, 'admin') === false, 'bir kullanıcı yalnızca tek bayiye bağlanır');
ok(RdCommission::attach(2, $A, 'admin') === false, 'bayi kendisine müşteri olarak bağlanamaz');

section('Abonelik kaydı');
ok(!RdPlans::request($A, 'yok')['success'], 'olmayan paket talep edilemez');
ok(RdPlans::request($A, 'baslangic')['success'] && !RdPlans::request($A, 'baslangic')['success'], 'tek bekleyen talep');
ok(!RdPlans::assign($A, 'pro', '2001-01-01', 1)['success'], 'geçmiş bitiş tarihi reddedilir');
ok(RdPlans::assign($A, 'pro', date('Y-m-d', strtotime('+30 days')), 1)['success'], 'paket atanır');
ok(RdPlans::effective(RdDealer::get($A))['code'] === 'pro', 'geçerli paket');
DB::update('rd_dealers', ['plan_expires_at' => '2001-01-01 00:00:00'], 'id_dealer = :d', ['d' => $A]);
ok(RdPlans::effective(RdDealer::get($A)) === null, 'süresi dolan paket geçersiz');
ok(!RdPlans::save(['code' => '', 'name' => 'x'])['success'] && !RdPlans::save(['code' => 'a', 'name' => 'x', 'commission_rate' => '120'])['success'], 'geçersiz paket reddedilir');
ok(count(RdPlans::history($A)) >= 2, 'abonelik geçmişi tutulur');

section('Güvenlik: kaynak taraması');
$src = '';
foreach (glob(dirname(__DIR__) . '/lib/*.php') as $f) { $src .= file_get_contents($f); }
ok(!preg_match('/\b(eval|shell_exec|passthru|proc_open|popen)\s*\(/', $src), 'tehlikeli fonksiyon yok');
ok(!preg_match('/\$_(GET|POST|REQUEST)\[[^\]]+\]\s*\./', $src), 'ham girdi SQL/HTML birleştirilmiyor (kaba tarama)');
ok(preg_match_all('/Security::requireCsrf\(/', file_get_contents(dirname(__DIR__) . '/lib/RdAuthFlow.php')) === 3 && preg_match_all('/Security::requireCsrf\(/', file_get_contents(dirname(__DIR__) . '/lib/RdPanel.php')) === 1, 'giriş, başvuru, çıkış ve panel POST yolları CSRF kontrolü çağırıyor');
foreach (['apply', 'login', 'logout', 'panel', 'ref'] as $r) {
	ok(strpos(file_get_contents(dirname(__DIR__) . "/front/$r.php"), "defined('IN_SCRIPT')") !== false, "front/$r.php doğrudan erişime kapalı");
}
finish();
