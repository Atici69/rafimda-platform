<?php
/** ÖNİZLEME ÜRETİCİ (geliştirme): kampanya yönetici sekmelerini GERÇEK modül + şablonla, ÖRNEK VERİ ile üretir. Canlı veri DEĞİLDİR. php tests/preview.php <çıktı-dizini> */
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/rafimda-showcase/lib/RsShowcase.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
require_once dirname(__DIR__) . '/rafimda-campaign.php';
$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/'); @mkdir($out, 0777, true);
rv_fresh_db(); rv_apply_migrations();
foreach (['rafimda-showcase', 'rafimda-campaign'] as $m) { foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . "/$m/install.sql"))))) as $st) { $GLOBALS['db']->exec($st); } }
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rc_pv_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true); $GLOBALS['smarty'] = $smarty;
$smarty->registerPlugin('modifier', 'adminT', static fn($s) => $s);
$adminToken = 'x'; $GLOBALS['adminToken'] = $adminToken;
foreach ([1 => 'Moda Evi', 2 => 'Yeşil Bahçe', 3 => 'Teknoloji Durağı'] as $u => $n) {
	DB::insert('rafimda_vendors', ['owner_user_id' => $u, 'store_name' => $n, 'store_slug' => 's' . $u, 'status' => 'active', 'approved_at' => date('Y-m-d H:i:s'), 'date_add' => date('Y-m-d H:i:s')]);
}
RcCampaign::setShowcaseFee(250);
$c1 = RcCampaign::save(['code' => 'ilk-ay', 'title' => 'İlk ay ücretsiz vitrin', 'type' => 'free_showcase', 'active' => 1, 'benefit_days' => 30, 'new_vendor_days' => 30, 'auto_enroll' => 1, 'starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d', time() + 90 * 86400)]);
RcCampaign::save(['code' => 'dusuk', 'title' => 'Düşük komisyon (yeni satıcı)', 'type' => 'commission_discount', 'active' => 1, 'benefit_days' => 60, 'calc_mode' => 'fixed_rate', 'value' => 5, 'new_vendor_days' => 30, 'auto_enroll' => 0]);
RcCampaign::enroll(1, 1, 'admin'); RcCampaign::enroll(2, 2, 'vendor');
$_SERVER['REQUEST_METHOD'] = 'GET';
$m = new RafimdaCampaignModule();
$views = ['campaigns' => [], 'campaigns-edit' => ['tab' => 'campaigns', 'edit' => 1], 'enrollments' => [], 'charges' => ['cs' => 'due'], 'simulator' => ['id_vendor' => 1, 'amount' => '1000']];
foreach ($views as $name => $get) {
	$tab = $get['tab'] ?? explode('-', $name)[0];
	$_GET = ['tab' => $tab] + $get;
	$smarty->clearAllAssign();
	try { $m->adminPage(); $html = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl'); }
	catch (Throwable $e) { $html = '<pre>HATA: ' . htmlspecialchars($e->getMessage()) . '</pre>'; fwrite(STDERR, "$name: " . $e->getMessage() . "\n"); }
	file_put_contents("$out/campaign-admin-$name.html", $html); echo "campaign-admin-$name " . strlen($html) . "\n";
}
