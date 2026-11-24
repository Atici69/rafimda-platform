<?php
// rafimda-campaign testleri (SQLite + stub; MySQL/gerçek tarayıcı TEST EDİLMEDİ)
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/rafimda-showcase/lib/RsShowcase.php';
require_once dirname(__DIR__) . '/lib/RcCampaign.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function fresh(): void {
	rv_fresh_db(); rv_apply_migrations();
	foreach (['rafimda-showcase', 'rafimda-campaign'] as $m) {
		foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . "/$m/install.sql"))))) as $st) { $GLOBALS['db']->exec($st); }
	}
	Settings::set('RAFIMDA_SHOWCASE_FEE', '');
	Settings::set('RAFIMDA_DEFAULT_COMMISSION', '10');
}
function vendor(int $user, string $status = 'active', ?string $approved = null, string $plan = 'free', float $rate = 0): int { return (int) DB::insert('rafimda_vendors', ['owner_user_id' => $user, 'store_name' => "V$user", 'store_slug' => "v$user", 'status' => $status, 'plan_code' => $plan, 'commission_rate' => $rate, 'approved_at' => $approved ?? date('Y-m-d H:i:s'), 'date_add' => $approved ?? date('Y-m-d H:i:s')]); }
function prod(int $vendor, string $n): int { $id = (int) DB::insert('products', ['product_name' => $n, 'id_category' => 1, 'id_brand' => 1, 'price' => 10, 'old_price' => 0, 'stock' => 5, 'stock_code' => 'S' . mt_rand(), 'barcode' => '', 'active' => 1]); DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $vendor, 'moderation' => 'approved']); return $id; }
$DAY = 86400;
class RvOrdersProbe {}

section('Kurulum');
fresh();
$inst = file_get_contents(dirname(__DIR__) . '/install.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE|ALTER)\b/i', $inst) && !preg_match('/CREATE TABLE(?! IF NOT EXISTS)/', $inst) && !preg_match('/^\s*--/m', $inst), 'install.sql yıkıcı değil');
ok(strpos(file_get_contents(dirname(__DIR__) . '/uninstall.sql'), 'DROP') === false, 'uninstall.sql veri silmez');
foreach (['rc_campaigns', 'rc_enrollments', 'rc_charges'] as $t) { ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "tablo: $t"); }

section('Kampanya doğrulama ve kayıt');
$base = ['code' => 'ilk-ay', 'title' => 'İlk ay ücretsiz vitrin', 'type' => 'free_showcase', 'active' => 1, 'benefit_days' => 30, 'new_vendor_days' => 30, 'auto_enroll' => 1];
$r = RcCampaign::save($base); ok($r['success'] && $r['id'] > 0, 'vitrin kampanyası oluşturulur');
$idShow = $r['id'];
ok(!RcCampaign::save($base)['success'], 'aynı kod ikinci kez kullanılamaz');
foreach ([['code' => 'A B'], ['title' => 'ab'], ['type' => 'x'], ['starts_at' => 'xx'], ['starts_at' => '2030-01-02', 'ends_at' => '2030-01-01'], ['benefit_days' => 900], ['benefit_days' => 0], ['max_orders' => '0'], ['new_vendor_days' => '0'], ['plan_codes' => 'a b!'], ['vendor_ids' => '1,abc']] as $bad) {
	ok(!RcCampaign::save(array_merge($base, ['code' => 'x' . mt_rand(100, 999)], $bad))['success'], 'geçersiz reddedilir: ' . json_encode($bad));
}
$cm = ['code' => 'dusuk', 'title' => 'Düşük komisyon', 'type' => 'commission_discount', 'active' => 1, 'benefit_days' => 90, 'calc_mode' => 'fixed_rate', 'value' => '5', 'vendor_can_join' => 1];
ok(!RcCampaign::save(array_merge($cm, ['code' => 'k1', 'value' => '101']))['success'] && !RcCampaign::save(array_merge($cm, ['code' => 'k2', 'calc_mode' => 'x']))['success'] && !RcCampaign::save(array_merge($cm, ['code' => 'k3', 'calc_mode' => 'percent_off', 'value' => '0']))['success'], 'komisyon kampanyasında geçersiz oran/kural reddedilir');
$r = RcCampaign::save($cm); ok($r['success'], 'komisyon kampanyası oluşturulur'); $idCom = $r['id'];
ok(RcCampaign::save(array_merge($cm, ['title' => 'Güncel başlık', 'value' => '6']), $idCom)['success'] && RcCampaign::get($idCom)['title'] === 'Güncel başlık' && (float) RcCampaign::get($idCom)['value'] === 6.0, 'kampanya güncellenir');
ok(!RcCampaign::save(array_merge($cm, ['code' => 'ilk-ay']), $idCom)['success'], 'güncellemede başka kampanyanın kodu alınamaz');
ok(RcCampaign::save(array_merge($cm, ['code' => 'xss', 'title' => '<script>x</script>Kampanya']))['success'] && strpos((string) DB::getValue("SELECT title FROM rc_campaigns WHERE code = 'xss'"), '<script>') === false, 'başlıktaki HTML temizlenir');
RcCampaign::save(array_merge($cm, ['value' => '5']), $idCom);

section('Uygunluk kuralları');
$vNew = vendor(1); $vOld = vendor(2, 'active', date('Y-m-d H:i:s', time() - 90 * $DAY)); $vPend = vendor(3, 'pending');
$c = RcCampaign::get($idShow);
ok(RcCampaign::eligibility($c, $vNew)['ok'], 'yeni satıcı uygun');
ok(!RcCampaign::eligibility($c, $vOld)['ok'] && str_contains(RcCampaign::eligibility($c, $vOld)['reason'], '30 gün'), '90 günlük satıcı "yeni satıcı" kampanyasına uygun değil');
ok(!RcCampaign::eligibility($c, $vPend)['ok'] && !RcCampaign::eligibility($c, 9999)['ok'], 'onaysız/olmayan satıcı uygun değil');
$cp = RcCampaign::save(array_merge($cm, ['code' => 'pro-only', 'plan_codes' => 'pro', 'vendor_can_join' => 0]))['id'];
ok(!RcCampaign::eligibility(RcCampaign::get($cp), $vNew)['ok'], 'paket kısıtı: free paketi uygun değil');
$vPro = vendor(4, 'active', null, 'pro'); DB::insert('rafimda_plans', ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => 100, 'sort_order' => 1]);
ok(RcCampaign::eligibility(RcCampaign::get($cp), $vPro)['ok'], 'paket kısıtı: pro paketi uygun');
$cv = RcCampaign::save(array_merge($cm, ['code' => 'liste', 'vendor_ids' => "$vOld, 999"]))['id'];
ok(RcCampaign::eligibility(RcCampaign::get($cv), $vOld)['ok'] && !RcCampaign::eligibility(RcCampaign::get($cv), $vNew)['ok'], 'satıcı listesi kısıtı');
$cw = RcCampaign::save(array_merge($cm, ['code' => 'pencere', 'starts_at' => date('Y-m-d', time() + 5 * $DAY), 'ends_at' => date('Y-m-d', time() + 9 * $DAY)]))['id'];
ok(!RcCampaign::eligibility(RcCampaign::get($cw), $vNew)['ok'] && RcCampaign::eligibility(RcCampaign::get($cw), $vNew, time() + 6 * $DAY)['ok'] && !RcCampaign::eligibility(RcCampaign::get($cw), $vNew, time() + 12 * $DAY)['ok'], 'başlangıç/bitiş penceresi (önce/içinde/sonra)');
RcCampaign::setActive($cw, false); ok(!RcCampaign::eligibility(RcCampaign::get($cw), $vNew, time() + 6 * $DAY)['ok'], 'kapalı kampanya uygun değil');

section('Katılım');
$e1 = RcCampaign::enroll($idCom, $vNew, 'vendor'); ok($e1['success'], 'satıcı kampanyaya başvurur');
ok(!RcCampaign::enroll($idCom, $vNew, 'vendor')['success'], 'aynı kampanyaya ikinci katılım yok (tek kullanımlık)');
ok(!RcCampaign::enroll($cp, $vNew, 'vendor')['success'], 'satıcı başvurusuna kapalı kampanyaya satıcı katılamaz');
ok(RcCampaign::enroll($cp, $vNew, 'admin')['success'], 'yönetici kuralları atlayarak ekleyebilir');
ok(!RcCampaign::enroll(999, $vNew, 'admin')['success'] && !RcCampaign::enroll($idCom, 9999, 'admin')['success'] && !RcCampaign::enroll($idCom, $vOld, 'hack')['success'], 'olmayan kampanya/satıcı/kaynak reddedilir');
$en = DB::getRowSafe('rc_enrollments', 'id_enrollment = ?', [$e1['id']]);
ok(abs(strtotime($en['expires_at']) - (time() + 90 * $DAY)) < 5, 'bitiş = başlangıç + avantaj süresi (90 gün)');
$cz = RcCampaign::save(array_merge($cm, ['code' => 'bitise-kadar', 'benefit_days' => 0, 'ends_at' => date('Y-m-d', time() + 20 * $DAY), 'vendor_can_join' => 1]))['id'];
$ez = RcCampaign::enroll($cz, $vOld, 'vendor'); ok($ez['success'] && str_starts_with((string) DB::getValue('SELECT expires_at FROM rc_enrollments WHERE id_enrollment = ?', [$ez['id']]), date('Y-m-d', time() + 20 * $DAY)), 'avantaj süresi 0: kampanya bitişine kadar');

section('Komisyon hesabı');
fresh(); $a = vendor(1); $b = vendor(2, 'active', null, 'free', 15); $old = vendor(3, 'active', date('Y-m-d H:i:s', time() - 200 * $DAY));
$vrow = fn(int $id) => DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$id]);
ok(abs(RvOrders::commissionRateFor($vrow($a), true) - 10.0) < 0.001, 'kampanya yokken platform varsayılanı (%10)');
$k = RcCampaign::save(['code' => 'sabit5', 'title' => 'Sabit %5', 'type' => 'commission_discount', 'active' => 1, 'benefit_days' => 60, 'calc_mode' => 'fixed_rate', 'value' => '5', 'auto_enroll' => 1, 'new_vendor_days' => 30])['id'];
ok(abs(RvOrders::commissionRateFor($vrow($a), true) - 5.0) < 0.001, 'otomatik katılımlı sabit oran uygulanır (%5)');
ok((int) DB::getValue('SELECT COUNT(*) FROM rc_enrollments WHERE id_vendor = ?', [$a]) === 1, 'ilk siparişte otomatik katılım oluştu');
ok(abs(RvOrders::commissionRateFor($vrow($old), true) - 10.0) < 0.001, 'uygun olmayan (eski) satıcıya uygulanmaz');
ok(abs(RvOrders::commissionRateFor($vrow($b), true) - 5.0) < 0.001, 'satıcıya özel %15 oranı olsa da kampanya (%5) düşürür');
RcCampaign::save(['code' => 'idx', 'title' => 'İndirim %50', 'type' => 'commission_discount', 'active' => 1, 'benefit_days' => 60, 'calc_mode' => 'percent_off', 'value' => '50', 'min_rate' => '6', 'auto_enroll' => 1]);
ok(abs(RvOrders::commissionRateFor($vrow($b), true) - 5.0) < 0.001, 'iki kampanya etkin: sabit %5 ve indirim (%15→%7,5; alt sınır %6) → en düşük oran (%5) kazanır');
RcCampaign::setActive($k, false);
DB::execute("UPDATE rc_enrollments SET status = 'cancelled' WHERE id_campaign = $k");
ok(abs(RvOrders::commissionRateFor($vrow($b), true) - 7.5) < 0.001 || abs(RvOrders::commissionRateFor($vrow($b), true) - 7.5) < 0.01, 'yalnızca yüzde indirim kaldı: %15 → %7,5');
$x = RcCampaign::commissionDetail($b, 4.0);
ok($x['rate'] <= 4.0 && abs($x['rate'] - 4.0) < 0.001, 'oran asla taban orandan yüksek olamaz (taban %4 → %4)');
ok(abs(RvOrders::commissionRateFor($vrow($a), false) - RvOrders::commissionRateFor($vrow($a), false)) < 0.001, 'gösterim modu (forOrder=false) yan etkisiz');
$cnt0 = (int) DB::getValue('SELECT COUNT(*) FROM rc_enrollments'); RvOrders::commissionRateFor($vrow($old), false); ok((int) DB::getValue('SELECT COUNT(*) FROM rc_enrollments') === $cnt0, 'gösterim modunda otomatik katılım oluşmaz');
RcCampaign::setActive((int) DB::getValue("SELECT id_campaign FROM rc_campaigns WHERE code = 'idx'"), false);
ok(RcCampaign::commissionDetail($a, 10.0, time() + 120 * $DAY)['id_enrollment'] === 0, 'avantaj süresi bitince standart orana dönülür');

section('Sipariş sınırı ve sayaç');
fresh(); $a = vendor(1);
$kk = RcCampaign::save(['code' => 'ilk2', 'title' => 'İlk 2 sipariş %3', 'type' => 'commission_discount', 'active' => 1, 'benefit_days' => 30, 'calc_mode' => 'fixed_rate', 'value' => '3', 'max_orders' => '2', 'auto_enroll' => 1])['id'];
$prodA = prod($a, 'Ürün');
$mk = function (int $n) use ($prodA): array { $o = (int) DB::insert('orders', ['id_user' => 1, 'status' => 1]); DB::insert('order_detail', ['id_order' => $o, 'id_product' => $prodA, 'product_name' => 'Ürün', 'qty' => 1, 'price' => 100, 'total' => 100]); return ['id_order' => $o]; };
$rates = [];
for ($i = 0; $i < 4; $i++) { RvOrders::splitOrder($mk($i)); }
$rates = array_map('floatval', array_column(DB::execute('SELECT commission_rate FROM rafimda_vendor_orders ORDER BY id_vendor_order') ?: [], 'commission_rate'));
ok($rates === [3.0, 3.0, 10.0, 10.0], 'sınır 2 sipariş: oranlar ' . json_encode($rates));
ok((int) DB::getValue('SELECT orders_used FROM rc_enrollments') === 2, 'sipariş sayacı 2');
$amts = array_map('floatval', array_column(DB::execute('SELECT commission_amount FROM rafimda_vendor_orders ORDER BY id_vendor_order') ?: [], 'commission_amount'));
ok($amts === [3.0, 3.0, 10.0, 10.0], 'komisyon tutarları doğru hesaplanır');
$o = $mk(0); RvOrders::splitOrder($o); RvOrders::splitOrder($o);
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_orders WHERE id_order = ?', [$o['id_order']]) === 1, 'aynı sipariş iki kez bölünmez (idempotent)');
$e = DB::getRowSafe('rc_enrollments', 'id_vendor = ?', [$a]); ok(!RcCampaign::isLive($e + ['max_orders' => 2]), 'sınıra ulaşan katılım etkin sayılmaz');
$sp = DB::getValue('SELECT SUM(vendor_earning) FROM rafimda_vendor_orders'); ok(abs((float) $sp - (97 + 97 + 90 + 90 + 90)) < 0.01, 'satıcı kazancı = tutar − komisyon');

section('Vitrin: ilk ay ücretsiz + ücret borcu');
fresh(); $a = vendor(1); $old = vendor(2, 'active', date('Y-m-d H:i:s', time() - 200 * $DAY));
$pa = prod($a, 'A ürünü'); $po = prod($old, 'Eski ürün');
RcCampaign::save(['code' => 'ilk-ay', 'title' => 'İlk ay ücretsiz vitrin', 'type' => 'free_showcase', 'active' => 1, 'benefit_days' => 30, 'new_vendor_days' => 30, 'auto_enroll' => 1]);
ok(RcCampaign::showcaseTerms($a)['free'] && RcCampaign::showcaseFee() == 0 && RcCampaign::showcaseTerms($a)['id_campaign'] === 0, 'ücret 0 iken herkese ücretsiz (kampanya gerekmez)');
$r = RsShowcase::request($a, ['slot_code' => 'home_featured', 'id_product' => $pa]); ok($r['success'] && (int) DB::getValue('SELECT COUNT(*) FROM rc_charges') === 0, 'ücret 0: borç kaydı oluşmaz');
ok(RcCampaign::setShowcaseFee(250) && RcCampaign::showcaseFee() == 250.0 && !RcCampaign::setShowcaseFee(-1), 'vitrin ücreti ayarlanır, negatif reddedilir');
$t = RcCampaign::showcaseTerms($a); ok($t['free'] && $t['id_campaign'] > 0 && $t['expires_at'] !== '', 'yeni satıcı: ilk ay ücretsiz (otomatik katılım)');
$pa2 = prod($a, 'A2'); $r = RsShowcase::request($a, ['slot_code' => 'home_featured', 'id_product' => $pa2]);
$ch = DB::getRowSafe('rc_charges', 'id_item = ?', [$r['id']]); ok($ch && $ch['status'] === 'waived' && (float) $ch['amount'] == 0.0, 'ilk ay talebi "muaf" (0 ₺) kaydedilir');
ok(RsShowcase::moderate($r['id'], 'approved'), 'muaf talep onaylanabilir');
$t = RcCampaign::showcaseTerms($old); ok(!$t['free'] && $t['fee'] == 250.0, 'eski satıcı ücretsiz dönemden yararlanamaz (ücret 250 ₺)');
$r2 = RsShowcase::request($old, ['slot_code' => 'home_featured', 'id_product' => $po]); $ch2 = DB::getRowSafe('rc_charges', 'id_item = ?', [$r2['id']]);
ok($ch2 && $ch2['status'] === 'due' && (float) $ch2['amount'] == 250.0, 'ücretli talep için borç (due 250 ₺) açılır');
ok(!RsShowcase::moderate($r2['id'], 'approved') && DB::getValue('SELECT status FROM rs_items WHERE id_item = ?', [$r2['id']]) === 'pending', 'ücreti ödenmemiş talep ONAYLANAMAZ');
ok(!RcCampaign::markSettled(9999) && RcCampaign::markSettled((int) $ch2['id_charge'], 'Dekont 123'), 'yönetici tahsilatı "ödendi" işaretler');
ok(!RcCampaign::markSettled((int) $ch2['id_charge']), 'kapalı borç tekrar işaretlenemez');
ok(RsShowcase::moderate($r2['id'], 'approved'), 'ödendi işaretlendikten sonra onaylanır');
$po2 = prod($old, 'Eski2'); $r3 = RsShowcase::request($old, ['slot_code' => 'home_featured', 'id_product' => $po2]);
RsShowcase::moderate($r3['id'], 'rejected'); ok(DB::getValue('SELECT status FROM rc_charges WHERE id_item = ?', [$r3['id']]) === 'void', 'reddedilen talebin borcu iptal olur');
$po3 = prod($old, 'Eski3'); $r4 = RsShowcase::request($old, ['slot_code' => 'home_featured', 'id_product' => $po3]); RsShowcase::withdraw($old, $r4['id']);
ok(DB::getValue('SELECT status FROM rc_charges WHERE id_item = ?', [$r4['id']]) === 'void', 'geri çekilen talebin borcu iptal olur');
ok(count(RcCampaign::charges('due')) === 0 && count(RcCampaign::charges('')) >= 4, 'ücret listesi duruma göre süzülür');
$tt = RcCampaign::showcaseTerms($a, time() + 40 * $DAY); ok(!$tt['free'], '30 gün sonra satıcı için ücretsiz dönem biter');
ok(RcCampaign::endEnrollment((int) DB::getValue('SELECT id_enrollment FROM rc_enrollments WHERE id_vendor = ?', [$a])) && !RcCampaign::showcaseTerms($a)['free'], 'yönetici katılımı sonlandırınca muafiyet biter');
$ec = (int) DB::getValue('SELECT COUNT(*) FROM rc_enrollments'); RcCampaign::showcaseTerms($a); ok((int) DB::getValue('SELECT COUNT(*) FROM rc_enrollments') === $ec, 'sonlandırılan katılım otomatik yeniden oluşmaz (tek kullanımlık)');

section('Simülatör ve satıcı görünümü');
fresh(); $a = vendor(1); RcCampaign::save(['code' => 'sabit5', 'title' => 'Sabit %5', 'type' => 'commission_discount', 'active' => 1, 'benefit_days' => 30, 'calc_mode' => 'fixed_rate', 'value' => '5', 'vendor_can_join' => 1]);
$s = RcCampaign::simulate($a, 1000); ok($s['ok'] && count($s['rows']) === 2 && $s['rows'][1]['rate'] == 10.0 && $s['rows'][1]['commission'] == 100.0, 'katılım yokken simülasyon standart oranı gösterir');
ok((int) DB::getValue('SELECT COUNT(*) FROM rc_enrollments') === 0, 'simülasyon kayıt yazmaz');
$fv = RcCampaign::forVendor($a); ok(count($fv['joinable']) === 1 && $fv['mine'] === [], 'satıcıya başvurulabilir kampanya listelenir');
RcCampaign::enroll($fv['joinable'][0]['id_campaign'], $a, 'vendor');
$s = RcCampaign::simulate($a, 1000); ok(count($s['rows']) === 3 && $s['rows'][2]['rate'] == 5.0 && $s['rows'][2]['commission'] == 50.0 && $s['rows'][2]['earning'] == 950.0, 'katılımdan sonra: %5 → 50 ₺ komisyon, 950 ₺ kazanç');
ok(!RcCampaign::simulate(9999, 100)['ok'] && !RcCampaign::simulate($a, -5)['ok'], 'simülatör geçersiz girdiyi reddeder');
$fv = RcCampaign::forVendor($a); ok(count($fv['joinable']) === 0 && count($fv['mine']) === 1 && $fv['mine'][0]['live'], 'katıldıktan sonra katılınabilir listesinden düşer, "benim" listesinde etkin görünür');

section('Hata dayanıklılığı');
fresh(); $a = vendor(1);
$GLOBALS['db']->exec('DROP TABLE rc_enrollments');
ok(abs(RcCampaign::rateFor(DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$a]), 10.0, true) - 10.0) < 0.001, 'kampanya tablosu bozuksa taban oran döner (sipariş akışı bozulmaz)');
RcCampaign::onShowcaseRequest($a, 1); ok(true, 'vitrin kancası hata yutar');


section('Yönetici işlemleri (handlePost)');
fresh(); require_once dirname(__DIR__) . '/rafimda-campaign.php';
$a = vendor(1); $_POST = [];
$post = function (array $d) { $_POST = $d; return RafimdaCampaignModule::handlePost(7); };
$r = $post(['rcAction' => 'campaign_save', 'id_campaign' => 0, 'code' => 'adm', 'title' => 'Yönetici kampanyası', 'type' => 'commission_discount', 'calc_mode' => 'percent_off', 'value' => '20', 'benefit_days' => 30, 'active' => 1]);
ok($r['type'] === 'success', 'yönetici kampanya kaydeder');
$id = (int) DB::getValue("SELECT id_campaign FROM rc_campaigns WHERE code = 'adm'");
ok($post(['rcAction' => 'enroll', 'id_campaign' => $id, 'id_vendor' => $a])['type'] === 'success', 'yönetici satıcıyı ekler');
ok($post(['rcAction' => 'enroll', 'id_campaign' => $id, 'id_vendor' => $a])['type'] === 'danger', 'aynı katılım tekrar eklenemez');
$s = RcCampaign::simulate($a, 200); ok($s['rows'][2]['rate'] == 8.0 && $s['rows'][2]['commission'] == 16.0, 'indirim %20: %10 → %8 (200 ₺ → 16 ₺)');
ok($post(['rcAction' => 'campaign_toggle', 'id_campaign' => $id, 'to' => '0'])['type'] === 'success' && !(int) RcCampaign::get($id)['active'], 'kampanya kapatılır');
ok(abs(RvOrders::commissionRateFor(DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$a]), true) - 8.0) < 0.001, 'kampanya kapatılsa da mevcut katılım süresince devam eder');
$eid = (int) DB::getValue('SELECT id_enrollment FROM rc_enrollments');
ok($post(['rcAction' => 'enroll_end', 'id_enrollment' => $eid])['type'] === 'success' && abs(RvOrders::commissionRateFor(DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$a]), true) - 10.0) < 0.001, 'katılım sonlandırılınca standart oran');
ok($post(['rcAction' => 'fee_save', 'fee' => '199,90'])['type'] === 'success' && RcCampaign::showcaseFee() == 199.9 && $post(['rcAction' => 'fee_save', 'fee' => '-5'])['type'] === 'danger', 'vitrin ücreti (virgüllü) kaydedilir, negatif reddedilir');
ok($post(['rcAction' => 'bilinmeyen']) === null, 'bilinmeyen işlem yok sayılır');
ok($post(['rcAction' => 'campaign_toggle', 'id_campaign' => 999, 'to' => '1'])['type'] === 'danger', 'olmayan kampanya hatası');

section('Modül yükleme ve yönetim sayfası');
fresh();
$src = (string) file_get_contents(dirname(__DIR__) . '/rafimda-campaign.php');
ok(str_contains($src, "registerAdminMenuLink('Kampanyalar (Rafımda)', 'sales'") && str_contains($src, 'runSqlFile(\'install.sql\')'), 'menü bağlantısı geçerli grupta, install.sql çalıştırır');
ok(is_file(dirname(__DIR__) . '/assets/templates/admin/admin.tpl'), 'yönetim şablonu var');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
