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

$xhVendor = XhOwner::currentVendorId();

if ($xhVendor <= 0 || !XhOwner::can('xml.view')) {
	http_response_code(403);
	echo 'Bu sayfa için aktif bir satıcı hesabı ve XML yetkisi gerekir.';
	exit;
}

$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
$canManage = XhOwner::can('xml.manage');

if ($isPost) {
	Security::requireCsrf('front');

	if (!$canManage) {
		http_response_code(403);
		echo 'Bu işlem için yetkiniz yok.';
		exit;
	}
}

$panel = new XhPanel($xhVendor, false, rtrim((string) $domain, '/') . '/xml-merkezi');
[$view, $vars] = $panel->handle();
$vars['view'] = $view;
$rafiFile = dirname(__DIR__, 2) . '/rafimda-rafi/lib/RafiHelp.php';
$rafiHelp = '';

if (is_file($rafiFile)) {
	require_once $rafiFile;
	$rafiHelp = RafiHelp::render('xml', (string) $domain, rtrim((string) $domain, '/') . '/satici-panel?s=support');
}

$smarty->setEscapeHtml(true);
$smarty->assign($vars + [
	'xhFlash' => XhPanel::takeFlash(),
	'xhToken' => Security::getCsrfToken('front'),
	'xhCanManage' => $canManage,
	'xhTplDir' => dirname(__DIR__) . '/assets/templates/front/',
	'xhCss' => rtrim((string) $domain, '/') . '/modules/xml-hub/assets/css/xh.css?v=' . XmlHubModule::VERSION,
	'xhJs' => rtrim((string) $domain, '/') . '/modules/xml-hub/assets/js/xh.js?v=' . XmlHubModule::VERSION,
	'panelUrl' => rtrim((string) $domain, '/') . '/satici-panel',
	'xhContent' => 'file:' . dirname(__DIR__) . '/assets/templates/front/xh_content.tpl',
]);
$page = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/front/xh_front.tpl');
// Rafi Akademi yardım bloğu şablon dışında, kendi kaçışlı HTML'iyle sayfa sonuna eklenir (şablonlarda nofilter yok)
echo $rafiHelp !== '' ? str_replace('</body>', '<div class="xh-wrap">' . $rafiHelp . '</div></body>', $page) : $page;
