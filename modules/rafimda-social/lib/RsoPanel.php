<?php

/** Yönetici ve satıcı ekranları için ortak işlem/değişken katmanı (satıcı yalnızca kendi içerik ve hesapları). */
final class RsoPanel
{
	public static function action(string $owner, int $idVendor, array $p, string $actor, string $redirectUri): array
	{
		$id = (int) ($p['id_post'] ?? 0);

		return match ((string) ($p['rsoAction'] ?? '')) {
			'create' => self::createMany($owner, $idVendor, $p, $actor),
			'auto' => RsoPlanner::autoPlan($owner, $idVendor, (string) ($p['rule'] ?? ''), (string) ($p['format'] ?? 'ig_post'), (string) ($p['start_day'] ?? ''), (string) ($p['time'] ?? '12:00'), (int) ($p['count'] ?? 3), $actor),
			'edit' => RsoPlanner::edit($id, $owner, $idVendor, (string) ($p['caption'] ?? ''), (string) ($p['hashtags'] ?? ''), $actor),
			'schedule' => RsoPlanner::reschedule($id, $owner, $idVendor, (string) ($p['when'] ?? ''), $actor),
			'cancel' => RsoPlanner::cancel($id, $owner, $idVendor, $actor),
			'approve' => $owner === 'platform' ? RsoPlanner::approve($id, true, (int) ($p['admin_id'] ?? 0)) : ['success' => false, 'message' => 'Yetkisiz.'],
			'reject' => $owner === 'platform' ? RsoPlanner::approve($id, false, (int) ($p['admin_id'] ?? 0), (string) ($p['note'] ?? '')) : ['success' => false, 'message' => 'Yetkisiz.'],
			'connect' => RsoPlanner::connectUrl($owner, $idVendor, (string) ($p['platform'] ?? ''), $redirectUri),
			'disconnect' => ['success' => RsoPlanner::disconnect($owner, $idVendor, (string) ($p['platform'] ?? '')), 'message' => 'Bağlantı kaldırıldı (token silindi).'],
			default => ['success' => false, 'message' => 'Bilinmeyen işlem.'],
		};
	}

	private static function createMany(string $owner, int $idVendor, array $p, string $actor): array
	{
		$formats = array_values(array_intersect(array_keys(RsoContent::FORMATS), (array) ($p['formats'] ?? [])));

		if ($formats === []) {
			return ['success' => false, 'message' => 'En az bir içerik biçimi seçin.'];
		}

		$ok = 0;
		$msgs = [];

		foreach ($formats as $f) {
			$r = RsoPlanner::create($owner, $idVendor, (int) ($p['id_product'] ?? 0), $f, (string) ($p['when'] ?? ''), $actor);
			$ok += $r['success'] ? 1 : 0;
			$msgs[] = RsoContent::FORMATS[$f][0] . ': ' . $r['message'];
		}

		return ['success' => $ok > 0, 'message' => implode(' | ', $msgs)];
	}

	public static function vars(string $owner, int $idVendor, array $get): array
	{
		$view = in_array($get['view'] ?? 'week', ['day', 'week', 'month'], true) ? (string) ($get['view'] ?? 'week') : 'week';
		$anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($get['d'] ?? '')) ? (string) $get['d'] : RsoPlanner::now()->format('Y-m-d');
		$id = (int) ($get['post'] ?? 0);
		$post = $id > 0 ? RsoPlanner::get($id) : null;

		if ($post && $owner === 'vendor' && !($post['owner_type'] === 'vendor' && (int) $post['id_vendor'] === $idVendor)) {
			$post = null;
		}

		$sql = 'SELECT p.id_product, p.product_name, p.price, p.stock FROM products p ' . ($owner === 'vendor' ? 'INNER JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product AND vp.id_vendor = ?' : '') . ' WHERE p.active = 1 ORDER BY p.id_product DESC LIMIT 200';

		return [
			'rsoView' => $view, 'rsoAnchor' => $anchor,
			'rsoPrev' => (new DateTimeImmutable($anchor))->modify($view === 'month' ? '-1 month' : ($view === 'day' ? '-1 day' : '-7 days'))->format('Y-m-d'),
			'rsoNext' => (new DateTimeImmutable($anchor))->modify($view === 'month' ? '+1 month' : ($view === 'day' ? '+1 day' : '+7 days'))->format('Y-m-d'),
			'rsoCalendar' => RsoPlanner::calendar($owner, $idVendor, $view, $anchor),
			'rsoList' => RsoPlanner::listFor($owner, $idVendor, (string) ($get['status'] ?? '')),
			'rsoPost' => $post,
			'rsoPostEvents' => $post ? (DB::execute('SELECT * FROM rso_events WHERE id_post = ? ORDER BY id_event DESC LIMIT 50', [$id]) ?: []) : [],
			'rsoProducts' => DB::execute($sql, $owner === 'vendor' ? [$idVendor] : []) ?: [],
			'rsoFormats' => RsoContent::FORMATS, 'rsoStatuses' => RsoPlanner::STATUSES, 'rsoPlatforms' => RsoPlanner::PLATFORMS, 'rsoSelections' => RsoPlanner::SELECTIONS,
			'rsoAccounts' => RsoPlanner::accounts($owner, $idVendor),
			'rsoReport' => RsoPlanner::report($owner, $idVendor),
			'rsoPublish' => RsoPlanner::publishEnabled(),
			'rsoFfmpeg' => RsoContent::ffmpeg() !== '',
			'rsoLimit' => $owner === 'vendor' ? ['used' => RsoPlanner::monthlyCount($idVendor), 'max' => RsoPlanner::vendorLimit()] : null,
			'rsoNowTr' => RsoPlanner::now()->format('Y-m-d H:i'),
			'rsoTomorrow' => RsoPlanner::now()->modify('+1 day')->format('Y-m-d'),
		];
	}

	/** Satıcı paneli bölümü (RvPanel üzerinden). */
	public static function vendor(array $vendor, array $ctx): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');

			if (!RvContext::can('social.manage')) {
				RvView::flash('danger', 'Bu işlem için yetkiniz yok.');
				RvView::redirect(RvView::panelUrl('social'));
			}

			$r = self::action('vendor', $idVendor, $_POST, 'vendor', RvView::url('sosyal-geri-donus'));

			if (!empty($r['url'])) {
				header('Location: ' . $r['url'], true, 302);
				exit;
			}

			RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
			RvView::redirect(RvView::panelUrl('social', array_filter(['post' => (int) ($_POST['id_post'] ?? 0)])));
		}

		return ['panel_social', self::vars('vendor', $idVendor, $_GET) + ['rsoCanManage' => RvContext::can('social.manage')]];
	}
}
