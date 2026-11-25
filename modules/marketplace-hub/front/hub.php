<?php
if (!defined('IN_SCRIPT')) {
	exit;
}
$skipPageRender = true;

App::sendNoStoreHeaders();

if (!Customer::isLoggedIn()) {
	header('Location: ' . rtrim((string) $domain, '/') . '/satici-giris');
	exit;
}

$mhVendor = MhOwner::currentVendorId();

if ($mhVendor <= 0 || !MhOwner::can('marketplaces.view')) {
	http_response_code(403);
	echo 'Bu sayfa için aktif bir satıcı hesabı ve pazaryeri yetkisi gerekir.';
	exit;
}

$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
$canManage = MhOwner::can('marketplaces.manage');

if ($isPost) {
	Security::requireCsrf('front');

	if (!$canManage) {
		http_response_code(403);
		echo 'Bu işlem için yetkiniz yok.';
		exit;
	}
}

$panel = new MhPanel($mhVendor, false, rtrim((string) $domain, '/') . '/pazaryeri');
[$view, $vars] = $panel->handle();
$vars['view'] = $view;
$smarty->setEscapeHtml(true);
$rafiFile = dirname(__DIR__, 2) . '/rafimda-rafi/lib/RafiHelp.php';
$rafiHelp = '';

if (is_file($rafiFile)) {
	require_once $rafiFile;
	$rafiHelp = RafiHelp::render('marketplaces', (string) $domain, rtrim((string) $domain, '/') . '/satici-panel?s=support');
}

$smarty->assign($vars + [
	'mhFlash' => MhPanel::takeFlash(),
	'mhToken' => Security::getCsrfToken('front'),
	'mhCanManage' => $canManage,
	'mhBase' => rtrim((string) $domain, '/') . '/pazaryeri',
	'mhTplDir' => dirname(__DIR__) . '/assets/templates/front/',
	'mhCss' => rtrim((string) $domain, '/') . '/modules/marketplace-hub/assets/css/mh.css?v=' . MarketplaceHubModule::VERSION,
	'panelUrl' => rtrim((string) $domain, '/') . '/satici-panel',
	'mhContent' => 'file:' . dirname(__DIR__) . '/assets/templates/front/mh_content.tpl',
]);
$page = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/front/mh_front.tpl');
// Rafi Akademi yardım bloğu şablon dışında, kendi kaçışlı HTML'iyle sayfa sonuna eklenir (şablonlarda nofilter yok)
echo $rafiHelp !== '' ? str_replace('</body>', '<div class="xh-wrap">' . $rafiHelp . '</div></body>', $page) : $page;
