<?php
if (!defined('IN_SCRIPT')) {
	exit;
}

// Müşteri hesap merkezi: bildirimler, destek talepleri, takip edilen mağazalar, değerlendirilecek siparişler, ileti/çerez izinleri.
global $smarty, $domain, $pageTitle, $pageDesc, $skipPageRender;
App::sendNoStoreHeaders();

if (!Customer::isLoggedIn()) {
	RvView::redirect(RvView::url('login'));
}

$idUser = (int) Customer::getId();

if (RvView::isPost()) {
	Security::requireCsrf('front');
	$a = RvView::post('action');
	$to = RvView::url('hesap-merkezi');

	if ($a === 'ticket_create') {
		$r = RvTickets::create(['opener' => 'customer', 'id_user' => $idUser, 'category' => RvView::post('category'), 'subject' => RvView::post('subject'), 'body' => RvView::post('body'), 'id_order' => (int) RvView::post('id_order')]);
		$to = $r['success'] ? RvView::url('hesap-merkezi', ['t' => (int) $r['id_ticket']]) : $to;
	} elseif ($a === 'ticket_reply') {
		$r = RvTickets::reply((int) RvView::post('id_ticket'), 'customer', $idUser, RvView::post('body'), $idUser);
		$to = RvView::url('hesap-merkezi', ['t' => (int) RvView::post('id_ticket')]);
	} elseif ($a === 'unfollow') {
		$ok = RvStorefront::follow($idUser, (int) RvView::post('id_vendor'), false);
		$r = ['success' => $ok, 'message' => $ok ? 'Takip bırakıldı.' : 'İşlem yapılamadı.'];
	} elseif ($a === 'read_all') {
		RvNotify::markReadForUser($idUser);
		$r = ['success' => true, 'message' => 'Bildirimler okundu.'];
	} elseif ($a === 'consents' && class_exists('RlLegal', false)) {
		$cur = RlLegal::consentsFor($idUser);

		foreach (['commercial_email', 'commercial_sms', 'commercial_phone', 'explicit_consent'] as $t) {
			$want = RvView::post($t) === '1';

			if ($want !== $cur[$t]) {
				RlLegal::setConsent($idUser, '', $t, $want, 'account');
			}
		}

		$r = ['success' => true, 'message' => 'İzin tercihleriniz kaydedildi.'];
	} else {
		$r = ['success' => false, 'message' => 'Bilinmeyen işlem.'];
	}

	RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
	RvView::redirect($to);
}

$t = (int) Tools::getValue('t', 0);
$pageTitle = 'Hesap merkezi';
$skipPageRender = true;
$smarty->assign([
	'acNotifications' => RvNotify::forUser($idUser, 30),
	'acTickets' => RvTickets::listFor('customer', $idUser),
	'acTicket' => $t > 0 ? RvTickets::get($t, 'customer', $idUser) : null,
	'acFollowed' => RvStorefront::followedBy($idUser),
	'acDelivered' => DB::execute('SELECT id_order, reference, date_add FROM orders WHERE id_user = ? AND status = ? ORDER BY id_order DESC LIMIT 20', [$idUser, RvReviews::DELIVERED]) ?: [],
	'acOrders' => DB::execute('SELECT id_order, reference FROM orders WHERE id_user = ? ORDER BY id_order DESC LIMIT 30', [$idUser]) ?: [],
	'acConsents' => class_exists('RlLegal', false) ? RlLegal::consentsFor($idUser) : null,
	'acConsentLabels' => class_exists('RlLegal', false) ? RlLegal::CONSENTS : [],
	'acStatuses' => RvTickets::STATUSES, 'acCategories' => RvTickets::CATEGORIES, 'acToken' => Security::getCsrfToken('front'),
	'acFlash' => RvView::takeFlash(), 'acReviewUrl' => RvView::url('degerlendir'), 'acSelf' => RvView::url('hesap-merkezi'), 'acStoreBase' => RvView::url('magaza'),
	'acCookieUrl' => RvView::url('cerez-tercihleri'), 'acEmailOff' => RvNotify::channelStatus()['email'] !== 'configured',
	'pageName' => 'hesap-merkezi', 'pageTitle' => $pageTitle, 'documentTitle' => $pageTitle, 'pageDesc' => $pageTitle,
	'breadcrumb' => [['name' => 'Ana Sayfa', 'url' => $domain], ['name' => $pageTitle, 'url' => '']],
]);
$smarty->display(_THEME_BASE_DIR_ . 'header.tpl');
$smarty->display('file:' . dirname(__DIR__) . '/assets/templates/front/account.tpl');
$smarty->display(_THEME_BASE_DIR_ . 'footer.tpl');
