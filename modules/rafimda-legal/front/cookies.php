<?php
if (!defined('IN_SCRIPT')) {
	exit;
}

global $smarty, $domain, $pageTitle, $pageDesc, $skipPageRender;

$saved = false;
$error = '';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
	if (!class_exists('Security') || !Security::validateCsrf('front')) {
		$error = 'Oturum süresi doldu; sayfayı yenileyip tekrar deneyin.';
	} else {
		$all = Tools::getValue('choice') === 'all';
		$none = Tools::getValue('choice') === 'none';
		RlLegal::saveCookiePrefs($all || (!$none && Tools::getValue('analytics') === '1'), $all || (!$none && Tools::getValue('marketing') === '1'), class_exists('Customer') && Customer::isLoggedIn() ? (int) Customer::getId() : 0);
		$saved = true;
		$back = (string) Tools::getValue('back', '');

		if ($back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//')) {
			header('Location: ' . rtrim((string) $domain, '/') . $back, true, 303);
			exit;
		}
	}
}

$pageTitle = 'Çerez Tercihleri';
$pageDesc = 'Zorunlu olmayan çerezler için tercihlerinizi yönetin';
$skipPageRender = true;
$smarty->assign([
	'rlPrefs' => RlLegal::cookiePrefs(),
	'rlSaved' => $saved,
	'rlError' => $error,
	'rlItem' => RlLegal::published('cerez-tercihleri'),
	'rlPolicyUrl' => RlLegal::url('cerez-politikasi'),
	'rlToken' => class_exists('Security') ? Security::getCsrfToken('front') : '',
	'pageName' => 'cerez-tercihleri',
	'pageTitle' => $pageTitle,
	'documentTitle' => $pageTitle,
	'pageDesc' => $pageDesc,
	'breadcrumb' => [['name' => 'Ana Sayfa', 'url' => $domain], ['name' => $pageTitle, 'url' => '']],
]);

$smarty->display(_THEME_BASE_DIR_ . 'header.tpl');
$smarty->display('file:' . dirname(__DIR__) . '/assets/templates/front/cookies.tpl');
$smarty->display(_THEME_BASE_DIR_ . 'footer.tpl');
