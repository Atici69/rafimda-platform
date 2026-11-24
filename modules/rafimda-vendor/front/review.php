<?php
if (!defined('IN_SCRIPT')) {
	exit;
}

// Değerlendirme: yalnızca giriş yapmış müşterinin TESLİM EDİLMİŞ siparişi için (doğrulanmış satın alma).
global $smarty, $domain, $pageTitle, $pageDesc, $skipPageRender;
App::sendNoStoreHeaders();

if (!Customer::isLoggedIn()) {
	RvView::redirect(RvView::url('login'));
}

$idUser = (int) Customer::getId();
$idOrder = (int) Tools::getValue('order', 0);

if (RvView::isPost()) {
	Security::requireCsrf('front');
	$name = (string) DB::getValue('SELECT user_full_name FROM users WHERE id_user = ?', [$idUser]);

	if (RvView::post('kind') === 'store') {
		$r = RvReviews::submitStore($idUser, $name, $idOrder, (int) RvView::post('id_vendor'), (int) RvView::post('rating'), RvView::post('comment'));
	} else {
		$photos = [];
		$f = $_FILES['photos'] ?? null;

		if (is_array($f) && is_array($f['name'] ?? null)) {
			foreach (array_keys($f['name']) as $i) {
				$photos[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
			}
		}

		$r = RvReviews::submitProduct($idUser, $name, $idOrder, (int) RvView::post('id_product'), (int) RvView::post('rating'), RvView::post('comment'), $photos);
	}

	RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
	RvView::redirect(RvView::url('degerlendir', ['order' => $idOrder]));
}

$items = $idOrder > 0 ? RvReviews::reviewableItems($idUser, $idOrder) : [];
$stores = [];

foreach ($items as $it) {
	if ((int) $it['id_vendor'] > 0) {
		$stores[(int) $it['id_vendor']] = ['id_vendor' => (int) $it['id_vendor'], 'store_name' => (string) $it['store_name'], 'reviewed' => $it['store_reviewed']];
	}
}

$pageTitle = 'Siparişimi değerlendir';
$skipPageRender = true;
$smarty->assign([
	'rvItems' => $items, 'rvStores' => array_values($stores), 'rvOrder' => $idOrder, 'rvFlash' => RvView::takeFlash(), 'rvToken' => Security::getCsrfToken('front'),
	'rvAvailable' => RvReviews::available(), 'rvAccountUrl' => RvView::url('hesap-merkezi'),
	'pageName' => 'degerlendir', 'pageTitle' => $pageTitle, 'documentTitle' => $pageTitle, 'pageDesc' => $pageTitle,
	'breadcrumb' => [['name' => 'Ana Sayfa', 'url' => $domain], ['name' => 'Hesap merkezi', 'url' => RvView::url('hesap-merkezi')], ['name' => $pageTitle, 'url' => '']],
]);
$smarty->display(_THEME_BASE_DIR_ . 'header.tpl');
$smarty->display('file:' . dirname(__DIR__) . '/assets/templates/front/review.tpl');
$smarty->display(_THEME_BASE_DIR_ . 'footer.tpl');
