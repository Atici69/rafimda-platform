<?php

/** Bayi paneli denetleyicisi: oturum → bayi durumu → (POST: CSRF + sahiplik) → görünüm. Her sorgu oturumdaki bayi kimliğiyle sınırlıdır. */
class RdPanel
{
	/** key => [etiket, simge, yalnızca ana bayi] */
	public const MENU = [
		'dashboard' => ['Genel Bakış', '◧', false],
		'customers' => ['Müşteriler', '☺', false],
		'subdealers' => ['Alt Bayiler', '☷', true],
		'earnings' => ['Kazançlar', '₺', false],
		'subscription' => ['Abonelik', '★', false],
		'reports' => ['Performans Raporları', '▨', false],
		'profile' => ['Profil ve Referans', '⚑', false],
	];

	public static function handle(): void
	{
		App::sendNoStoreHeaders();

		if (!Customer::isLoggedIn()) {
			RdView::redirect(RdView::url('bayi-giris'));
		}

		$dealer = RdContext::current();

		if (!$dealer) {
			RdView::flash('warning', 'Bu hesapla kayıtlı bir bayi başvurusu yok.');
			RdView::redirect(RdView::url('bayi-basvuru'));
		}

		if (!RdDealer::isOperational($dealer)) {
			$paused = !empty($dealer['paused_by_parent']);
			RdView::display('panel_status', [
				'rdTitle' => 'Bayi Durumu',
				'dealer' => $dealer,
				'statusLabel' => $paused ? 'Üst bayiniz tarafından durduruldu' : (RdDealer::STATUSES[$dealer['status']] ?? (string) $dealer['status']),
				'logoutUrl' => RdView::url('bayi-cikis'),
			]);
			exit;
		}

		$top = (int) $dealer['id_parent'] === 0;
		$section = (string) Tools::getValue('s', 'dashboard');

		if (!isset(self::MENU[$section]) || (self::MENU[$section][2] && !$top)) {
			$section = 'dashboard';
		}

		$id = (int) $dealer['id_dealer'];

		if (RdView::isPost()) {
			Security::requireCsrf('front');
			self::post($dealer, $top, $section);
		}

		if ($section === 'earnings' && (string) Tools::getValue('export') === 'csv') {
			self::exportCsv($id);
		}

		$vars = ['section' => $section];
		$plan = RdPlans::effective($dealer);

		switch ($section) {
			case 'customers':
				$vars += ['customers' => RdReport::customers($id)];
				break;
			case 'subdealers':
				$vars += ['subs' => RdReport::subPerformance($id), 'inviteUrl' => RdView::url('bayi-basvuru', ['ust' => $dealer['referral_code']]), 'maxSub' => (int) ($plan['max_sub_dealers'] ?? 0), 'subCount' => RdDealer::countSub($id, true)];
				break;
			case 'earnings':
				$st = (string) Tools::getValue('st', '');
				$vars += ['rows' => RdCommission::listFor($id, $st), 'st' => $st, 'totals' => RdCommission::totals($id), 'statuses' => RdCommission::STATUSES];
				break;
			case 'subscription':
				$vars += ['plan' => $plan, 'plans' => RdPlans::all(true), 'history' => RdPlans::history($id), 'subStatuses' => RdPlans::SUB_STATUSES];
				break;
			case 'reports':
				$m = RdReport::monthly($id, 12);
				$max = 0.0;

				foreach ($m as $r) {
					$max = max($max, $r['sales']);
				}

				$vars += ['months' => $m, 'maxSales' => $max, 'topCustomers' => array_slice(RdReport::customers($id, 10), 0, 10), 'subs' => $top ? RdReport::subPerformance($id) : [], 'summary' => RdReport::summary($id)];
				break;
			case 'profile':
				$vars += ['refUrl' => RdView::url('bayi-ref', ['kod' => $dealer['referral_code']]), 'inviteUrl' => RdView::url('bayi-basvuru', ['ust' => $dealer['referral_code']])];
				break;
			default:
				$vars += ['summary' => RdReport::summary($id), 'totals' => RdCommission::totals($id), 'plan' => $plan, 'rate' => RdCommission::rateFor($dealer), 'recent' => RdCommission::listFor($id, '', 5)];
		}

		self::render($dealer, $top, $section, 'panel_' . $section, $vars);
	}

	/** @param array<string,mixed> $dealer */
	private static function post(array $dealer, bool $top, string $section): void
	{
		$id = (int) $dealer['id_dealer'];
		$action = RdView::post('action');

		if ($action === 'plan_request' && $section === 'subscription') {
			$r = RdPlans::request($id, RdView::post('plan_code'));
			RdView::flash($r['success'] ? 'success' : 'danger', $r['message']);
		} elseif (in_array($action, ['sub_pause', 'sub_resume'], true) && $top && $section === 'subdealers') {
			$ok = RdDealer::setPausedByParent($id, (int) RdView::post('id_dealer'), $action === 'sub_pause');
			RdView::flash($ok ? 'success' : 'danger', $ok ? 'Alt bayi durumu güncellendi.' : 'Bu alt bayi size ait değil.');
		}

		RdView::redirect(RdView::panelUrl($section));
	}

	private static function exportCsv(int $idDealer): void
	{
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="bayi-kazanc-' . date('Ymd') . '.csv"');
		$out = fopen('php://output', 'w');
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, ['Tarih', 'Siparis No', 'Tur', 'Siparis Tutari', 'Oran %', 'Komisyon', 'Durum'], ';');

		foreach (RdCommission::listFor($idDealer, '', 1000) as $r) {
			fputcsv($out, [(string) $r['date_add'], (int) $r['id_order'], $r['type'] === 'override' ? 'Alt bayi payı' : 'Doğrudan', number_format((float) $r['order_total'], 2, ',', ''), number_format((float) $r['rate'], 2, ',', ''), number_format((float) $r['amount'], 2, ',', ''), RdCommission::STATUSES[$r['status']] ?? (string) $r['status']], ';');
		}

		fclose($out);
		exit;
	}

	/** @param array<string,mixed> $dealer @param array<string,mixed> $vars */
	private static function render(array $dealer, bool $top, string $section, string $tpl, array $vars): void
	{
		$menu = [];

		foreach (self::MENU as $k => $m) {
			if ($m[2] && !$top) {
				continue;
			}

			$menu[] = ['key' => $k, 'label' => $m[0], 'icon' => $m[1], 'url' => RdView::panelUrl($k)];
		}

		RdView::display('panel_layout', $vars + [
			'rdTitle' => self::MENU[$section][0],
			'pageTitle' => self::MENU[$section][0],
			'dealer' => $dealer,
			'levelLabel' => $top ? 'Bayi' : 'Alt Bayi',
			'menu' => $menu,
			'contentFile' => RdView::tplDir() . $tpl . '.tpl',
			'flash' => RdView::takeFlash(),
			'logoutUrl' => RdView::url('bayi-cikis'),
			'storefrontUrl' => RdView::url(''),
		]);
		exit;
	}
}
