<?php
if (!defined('IN_SCRIPT')) {
	exit;
}

global $smarty, $domain, $pageTitle, $pageDesc, $skipPageRender;

$vendor = RvStorefront::bySlug(preg_replace('/[^a-z0-9-]/', '', (string) Tools::getValue('s', '')) ?? '');

if (!$vendor) {
	http_response_code(404); // yalnızca aktif mağazalar; onaysız/askıdaki mağaza görünmez
	$container = '404';
	$pageTitle = 'Mağaza bulunamadı';

	return;
}

$idVendor = (int) $vendor['id_vendor'];
$idUser = class_exists('Customer') && Customer::isLoggedIn() ? (int) Customer::getId() : 0;
$tab = in_array((string) Tools::getValue('tab', 'new'), ['new', 'best', 'reviews', 'questions', 'about'], true) ? (string) Tools::getValue('tab', 'new') : 'new';
$pageTitle = (string) $vendor['store_name'];
$pageDesc = mb_substr((string) ($vendor['description'] ?? ''), 0, 160) ?: ($pageTitle . ' mağazası — Rafımda');
$skipPageRender = true;
$smarty->assign([
	'sv' => $vendor,
	'svProfile' => RvStorefront::profile($idVendor),
	'svRating' => RvStorefront::rating($idVendor),
	'svFollowers' => RvStorefront::followers($idVendor),
	'svFollowing' => RvStorefront::isFollowing($idUser, $idVendor),
	'svIsStaff' => RvReviews::isStaffOf($idUser, $idVendor),
	'svTab' => $tab,
	'svProducts' => in_array($tab, ['new', 'best'], true) ? RvStorefront::products($idVendor, $tab) : [],
	'svReviews' => $tab === 'reviews' ? RvReviews::publishedStore($idVendor) : [],
	'svQuestions' => $tab === 'questions' ? RvQA::publishedStore($idVendor) : [],
	'svUrl' => RvStorefront::url($vendor),
	'svActionUrl' => RvView::url('magaza-islem'),
	'svLoggedIn' => $idUser > 0,
	'svLoginUrl' => RvView::url('login'),
	'svToken' => Security::getCsrfToken('front'),
	'svFlash' => RvView::takeFlash(),
	'pageName' => 'magaza',
	'pageTitle' => $pageTitle,
	'documentTitle' => $pageTitle . ' · Rafımda',
	'pageDesc' => $pageDesc,
	'breadcrumb' => [['name' => 'Ana Sayfa', 'url' => $domain], ['name' => $pageTitle, 'url' => '']],
]);

$smarty->display(_THEME_BASE_DIR_ . 'header.tpl');
$smarty->display('file:' . dirname(__DIR__) . '/assets/templates/front/store.tpl');
$smarty->display(_THEME_BASE_DIR_ . 'footer.tpl');
