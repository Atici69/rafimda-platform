<?php
if (!defined('IN_SCRIPT')) {
	exit;
}

// Mağaza sayfası işlemleri: takip et/bırak, mağazaya soru, "faydalı buldum". Yalnızca POST + CSRF (çekirdek de zorunlu kılar) + giriş.
App::sendNoStoreHeaders();

if (!RvView::isPost()) {
	RvView::redirect(RvView::url(''));
}

Security::requireCsrf('front');
$vendor = DB::getRowSafe('rafimda_vendors', 'id_vendor = ? AND status = ?', [(int) RvView::post('id_vendor'), RvVendor::STATUS_ACTIVE]);
$ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$base = RvView::url('');
$back = $vendor ? RvStorefront::url($vendor) : ($ref !== '' && str_starts_with($ref, $base) ? $ref : $base); // yalnızca kendi sitemize geri dön (açık yönlendirme yok)
$idUser = Customer::isLoggedIn() ? (int) Customer::getId() : 0;

if ($idUser <= 0) {
	RvView::flash('warning', 'Bu işlem için giriş yapın.');
	RvView::redirect(RvView::url('login'));
}

switch (RvView::post('action')) {
	case 'follow':
	case 'unfollow':
		$ok = $vendor && RvStorefront::follow($idUser, (int) $vendor['id_vendor'], RvView::post('action') === 'follow');
		RvView::flash($ok ? 'success' : 'danger', $ok ? (RvView::post('action') === 'follow' ? 'Mağaza takip ediliyor.' : 'Takip bırakıldı.') : 'İşlem yapılamadı.');
		break;
	case 'ask':
		$name = (string) DB::getValue('SELECT user_full_name FROM users WHERE id_user = ?', [$idUser]);
		$r = $vendor ? RvQA::askStore((int) $vendor['id_vendor'], $idUser, $name, RvView::post('question')) : ['success' => false, 'message' => 'Mağaza bulunamadı.'];
		RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
		$back .= '&tab=questions';
		break;
	case 'helpful':
		$r = RvReviews::voteHelpful(RvView::post('kind') === 'store' ? 'store' : 'product', (int) RvView::post('id_review'), $idUser);
		RvView::flash($r['success'] ? 'success' : 'warning', $r['message']);
		$back .= $vendor ? '&tab=reviews' : '';
		break;
	default:
		RvView::flash('danger', 'Bilinmeyen işlem.');
}

RvView::redirect($back);
