<?php
/** İlk lansman: bayilik PASİF davranışı (SQLite + gerçek Smarty; gerçek FriSay/MySQL DEĞİL). */
ob_start(); // CLI'da http_response_code() için başlıklar gönderilmemiş kalsın
define('RD_TEST_PASSIVE', true);
require __DIR__ . '/bootstrap.php';

section('Varsayılan durum');
rd_fresh_db(); rd_install();
Settings::$d = [];
ok(RdSwitch::enabled() === false, 'ayar yokken bayilik PASİF (güvenli varsayılan)');
Settings::set('RAFIMDA_DEALER_ENABLED', '0');
ok(RdSwitch::enabled() === false, "'0' → pasif");
foreach (['rd_dealers', 'rd_plans', 'rd_subscriptions', 'rd_customers', 'rd_commissions', 'rd_audit_log'] as $t) {
	ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "altyapı korunur: $t");
}

section('Rotalar ziyaretçiye kapalı (404)');
foreach (['bayi-basvuru', 'bayi-giris', 'bayi-cikis', 'bayi-panel', 'bayi-ref'] as $slug) {
	$file = dirname(__DIR__) . '/' . (new RafimdaDealerModule())->routes[$slug];
	$container = $slug; $skipPageRender = false; $pageTitle = '';
	http_response_code(200);
	ob_start(); include $file; $out = ob_get_clean();
	ok(http_response_code() === 404 && $container === '404' && $skipPageRender === false && $out === '' && $pageTitle === 'Sayfa Bulunamadı', "$slug → çekirdek 404 sayfası, bayi akışı çalışmaz");
}

section('Mevcut kayıtlar korunur, yeni komisyon üretilmez');
$id = RdDealer::create(2, RdDealer::validate(['company_name' => 'Eski Bayi', 'contact_name' => 'Yetkili', 'phone' => '05321112233', 'email' => 'b2@x.com', 'city' => 'İzmir'])['clean'])['id_dealer'];
RdDealer::setStatus($id, 'active', 1); RdDealer::setRates($id, 10, 0, 1);
$_SESSION['rd_ref'] = RdDealer::get($id)['referral_code'];
Customer::as(3);
$o = DB::insert('orders', ['id_user' => 3, 'status' => 1, 'total' => 1000]);
Module::fire('order.placed', ['id_order' => $o]);
Module::fire('order.updated', ['id_order' => $o, 'current_state' => 4], 1);
ok((int) DB::getValue('SELECT COUNT(*) FROM rd_commissions') === 0, 'pasifken sipariş kancası komisyon kaydı oluşturmaz');
ok((int) DB::getValue('SELECT COUNT(*) FROM rd_customers') === 0, 'pasifken müşteri bayiye bağlanmaz');
ok(RdDealer::get($id)['status'] === 'active', 'eski bayi kaydı silinmez/değişmez');

section('Yönetici paneli: yalnızca "Şimdilik Pasif"');
$m = new RafimdaDealerModule();
$render = function () use ($m): string { $m->adminPage(); return $GLOBALS['smarty']->fetch('file:' . $m->getAdminTemplatePath('admin')); };
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = ['tab' => 'dealers'];
$h = $render();
ok(str_contains($h, 'Şimdilik Pasif – Gelecekte Etkinleştirilecek') && str_contains($h, 'PASİF'), 'pasif etiketi görünür');
ok(!str_contains($h, 'nav-tabs') && !str_contains($h, 'name="rdAction" value="dealer_status"'), 'yönetim sekmeleri/işlem formları gizli');
ok(str_contains($h, '1 aktif'), 'korunan kayıt sayısı gösterilir');
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['token' => 'admintok', 'rdAction' => 'dealer_status', 'id_dealer' => (string) $id, 'to_status' => 'suspended'];
$h = $render();
ok(RdDealer::get($id)['status'] === 'active' && str_contains($h, 'işlem yapılamaz'), 'pasifken doğrudan POST ile bayi işlemi yapılamaz');
$_POST = ['token' => 'admintok', 'rdAction' => 'system_switch', 'to' => '1', 'confirm_phrase' => 'evet'];
$h = $render();
ok(!RdSwitch::enabled() && str_contains($h, 'BAYILIGI ETKINLESTIR'), 'yanlış onay ifadesiyle etkinleştirilemez');
$_POST = ['token' => 'kotu', 'rdAction' => 'system_switch', 'to' => '1', 'confirm_phrase' => RdSwitch::CONFIRM_PHRASE];
$render();
ok(!RdSwitch::enabled(), 'geçersiz CSRF ile etkinleştirilemez');
$_POST = ['token' => 'admintok', 'rdAction' => 'system_switch', 'to' => '1', 'confirm_phrase' => RdSwitch::CONFIRM_PHRASE];
$render();
ok(RdSwitch::enabled() && (int) DB::getValue("SELECT COUNT(*) FROM rd_audit_log WHERE action = 'dealer_system_enabled'") === 1, 'doğru ifade + CSRF ile etkinleşir ve denetim kaydı düşer');
$_POST = ['token' => 'admintok', 'rdAction' => 'system_switch', 'to' => '0'];
$render();
ok(!RdSwitch::enabled() && RdDealer::get($id) !== null, 'yeniden pasife alınır; veriler korunur');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
