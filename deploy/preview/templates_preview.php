<?php
/** ÖNİZLEME ÜRETİCİ (geliştirme): Pazaryeri Hub, Vitrin ve Satıcı yönetici sekmelerini gerçek şablonlarla ÖRNEK VERİ ile HTML parçası olarak üretir. Canlı veri DEĞİLDİR. php templates_preview.php <çıktı-dizini> */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL & ~E_WARNING);
$root = dirname(__DIR__, 2);
require_once $root . '/libs/Smarty.class.php';
$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/'); @mkdir($out, 0777, true);
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/pv_t_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true);
$smarty->registerPlugin('modifier', 'adminT', static fn($s) => $s);
$smarty->setEscapeHtml(false);
$emit = static function (string $name, string $tpl, array $vars) use ($smarty, $out): void {
	$smarty->clearAllAssign(); $smarty->assign($vars);
	try { $h = $smarty->fetch('file:' . $tpl); } catch (Throwable $e) { $h = '<pre>HATA ' . htmlspecialchars($e->getMessage()) . '</pre>'; fwrite(STDERR, "$name: " . $e->getMessage() . "\n"); }
	file_put_contents("$out/$name.html", $h); echo "$name " . strlen($h) . "\n";
};
// ---- Pazaryeri Hub
$tpl = $root . '/modules/marketplace-hub/assets/templates/';
$fields = ['api_key' => ['label' => 'API Key', 'secret' => true, 'required' => true, 'max' => 100], 'api_secret' => ['label' => 'API Secret', 'secret' => true, 'required' => true, 'max' => 100], 'supplier_id' => ['label' => 'Satıcı ID', 'secret' => false, 'required' => true, 'max' => 20]];
$mk = ['trendyol' => ['key' => 'trendyol', 'title' => 'Trendyol', 'implemented' => false, 'fields' => $fields], 'hepsiburada' => ['key' => 'hepsiburada', 'title' => 'Hepsiburada', 'implemented' => false, 'fields' => $fields], 'n11' => ['key' => 'n11', 'title' => 'N11', 'implemented' => false, 'fields' => $fields]];
$acc = ['id_account' => 1, 'marketplace' => 'trendyol', 'label' => 'Trendyol — Ana mağaza', 'has_credentials' => 0, 'last_test_at' => '', 'last_error' => '', 'status' => 'draft', 'id_vendor' => 0];
$base = ['mhFlash' => null, 'mhToken' => 'x', 'mhCanManage' => true, 'mhBase' => '#', 'mhTplDir' => $tpl . 'front/', 'mhCss' => 'https://test.local/modules/marketplace-hub/assets/css/mh.css', 'panelUrl' => '#', 'mhContent' => 'file:' . $tpl . 'front/mh_content.tpl', 'mhPending' => [], 'mhCronUrl' => 'https://rafimda.com.tr/pazaryeri-cron?q=cron&key=••••••••', 'adminToken' => 'x'];
$lst = [['id_product' => 12, 'marketplace' => 'trendyol', 'state' => 'blocked', 'attempts' => 0, 'error' => 'Gerçek API entegrasyonu yok', 'date_add' => '2026-11-23 10:00', 'code' => 'NOT_IMPLEMENTED', 'message' => 'Bu pazaryeri için canlı gönderim henüz yok']];
$emit('mh-list', $tpl . 'admin/admin.tpl', $base + ['view' => 'list', 'accounts' => [$acc], 'marketplaces' => $mk]);
$emit('mh-account', $tpl . 'admin/admin.tpl', $base + ['view' => 'account', 'tab' => 'general', 'acc' => $acc, 'adapter' => ['title' => 'Trendyol', 'implemented' => false, 'fields' => $fields], 'credStatus' => [], 'counts' => ['blocked' => 1], 'catMap' => [], 'categories' => [['id_category' => 1, 'category_name' => 'Giyim']], 'products' => [['id_product' => 12, 'product_name' => 'Pamuklu Tişört', 'stock_code' => 'SKU-1012', 'id_category' => 1]], 'listings' => $lst, 'errors' => $lst]);
$emit('mh-errors', $tpl . 'admin/admin.tpl', $base + ['view' => 'errors', 'errors' => $lst]);
// ---- Vitrin
$slots = [['code' => 'home_featured', 'title' => 'Ana sayfa — öne çıkanlar', 'kind' => 'product', 'max_items' => 8, 'active' => 1, 'vendor_requestable' => 1], ['code' => 'home_ad', 'title' => 'Ana sayfa — reklam bandı', 'kind' => 'banner', 'max_items' => 4, 'active' => 1, 'vendor_requestable' => 0]];
$emit('showcase-admin', $root . '/modules/rafimda-showcase/assets/templates/admin/admin.tpl', ['rsFlash' => null, 'rsToken' => 'x', 'rsSlots' => $slots, 'rsStatus' => 'pending', 'rsItems' => [['id_item' => 1, 'slot_title' => 'Ana sayfa — öne çıkanlar', 'id_product' => 12, 'product_name' => 'Pamuklu Tişört', 'title' => '', 'id_vendor' => 1, 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-31', 'note' => 'Yılbaşı kampanyası', 'kind' => 'product']]]);
// ---- Satıcı yönetici sekmeleri
$av = ['pageTitle' => 'Satıcılar', 'flash' => '', 'flashType' => 'info', 'rvCounts' => ['active' => 12, 'pending' => 2], 'rvFilterStatus' => '', 'rvQuery' => '', 'rvModerationQueue' => [], 'rvModState' => 'pending', 'rvVendorOrders' => [], 'rvOrderStatuses' => [], 'rvTotals' => [], 'rvBalances' => [], 'rvPayouts' => [], 'rvAutoApprove' => false, 'rvModeration' => true, 'rvDefaultCommission' => 10, 'rvHoldDays' => 7, 'rvPanelUrl' => '#', 'adminToken' => 'x', 'rvToken' => 'x', 'rvPending' => [], 'rvStatuses' => ['pending' => 'Beklemede', 'active' => 'Aktif', 'suspended' => 'Askıda', 'rejected' => 'Reddedildi'],
	'rvVendors' => [['id_vendor' => 1, 'store_name' => 'Örnek Mağaza', 'store_slug' => 'ornek-magaza', 'plan_code' => 'pro', 'plan_expires_at' => '2026-12-31', 'owner_name' => 'Ayşe Yılmaz', 'owner_email' => 'ay***@ornek.test', 'status' => 'active'], ['id_vendor' => 2, 'store_name' => 'Demo Butik', 'store_slug' => 'demo-butik', 'plan_code' => 'free', 'plan_expires_at' => '', 'owner_name' => 'Mehmet Demir', 'owner_email' => 'me***@ornek.test', 'status' => 'pending']],
	'rvPlans' => [['code' => 'free', 'name' => 'Başlangıç', 'price_monthly' => 0, 'max_products' => null, 'max_xml_connections' => 2, 'max_marketplace_accounts' => null, 'active' => 1, 'sort_order' => 0], ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => 299, 'max_products' => 5000, 'max_xml_connections' => 10, 'max_marketplace_accounts' => 3, 'active' => 1, 'sort_order' => 1]]];
$emit('vendor-admin-vendors', $root . '/modules/rafimda-vendor/assets/templates/admin/admin.tpl', $av + ['rvTab' => 'vendors']);
$emit('vendor-admin-plans', $root . '/modules/rafimda-vendor/assets/templates/admin/admin.tpl', $av + ['rvTab' => 'plans']);
// ---- Tema yönetimi / bayi yönetici sekmeleri bu dosyada değil: ilgili modülün tests/preview.php ve drive.php dosyalarından üretilir.
