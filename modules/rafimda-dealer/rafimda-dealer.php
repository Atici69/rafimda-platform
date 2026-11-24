<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

foreach (['RdMigrator', 'RdAudit', 'RdView', 'RdPlans', 'RdDealer', 'RdContext', 'RdCommission', 'RdReport', 'RdAuthFlow', 'RdPanel'] as $rdClass) {
	require_once __DIR__ . '/lib/' . $rdClass . '.php';
}

/**
 * Rafımda bayi sistemi: Yönetici → Bayi → Alt bayi/müşteri. Referans kodlu müşteri atama, abonelik KAYDI ve komisyon HESAPLAMA.
 * Ödeme, banka, muhasebe ve fatura özellikleri BİLEREK yoktur. Çekirdek dosyalara dokunmaz; tüm sayfalar modül rotalarıyla çalışır.
 */
class RafimdaDealerModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'rafimda-dealer';
	public string $title = 'Rafımda Bayi Sistemi';
	public string $version = self::VERSION;
	public string $description = 'Bayi başvurusu ve yönetici onayı, bayi paneli, alt bayiler, referanslı müşteri atama, abonelik ve komisyon hesaplama, raporlar';
	public string $author = 'Rafımda';

	public array $routes = [
		'bayi-basvuru' => 'front/apply.php',
		'bayi-giris' => 'front/login.php',
		'bayi-cikis' => 'front/logout.php',
		'bayi-panel' => 'front/panel.php',
		'bayi-ref' => 'front/ref.php',
	];

	public function install(): bool
	{
		if (!$this->runSqlFile('install.sql')) {
			return false;
		}

		$res = RdMigrator::run();

		if (!$res['success']) {
			error_log('rafimda-dealer: ' . $res['error']);

			return false;
		}

		RdPlans::ensureDefault();

		if (Settings::get('RAFIMDA_DEALER_DEFAULT_COMMISSION') === '') {
			Settings::set('RAFIMDA_DEALER_DEFAULT_COMMISSION', '0');
		}

		return true;
	}

	/** Veri silinmez (geri dönüş planı); bkz. uninstall.destructive.sql.manual */
	public function uninstall(): bool
	{
		return true;
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Bayiler (Rafımda)', 'sales', 61);

		Module::registerHook('order.placed', static function (array $order): void {
			RdCommission::safeHook(static fn() => RdCommission::onOrderPlaced($order));
		});
		Module::registerHook('order.updated', static function (array $order, $oldStatus = 0): void {
			RdCommission::safeHook(static fn() => RdCommission::onOrderUpdated($order, (int) $oldStatus));
		});
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken;

		$flash = '';
		$flashType = 'info';
		$post = Tools::isSubmit('rdAction');
		$tab = (string) Tools::getValue('tab', 'applications');

		if (!in_array($tab, ['applications', 'dealers', 'plans', 'subscriptions', 'commissions', 'reports', 'settings'], true)) {
			$tab = 'applications';
		}

		if ($post && !hash_equals((string) $adminToken, (string) Tools::getValue('token'))) {
			$flash = 'Geçersiz istek';
			$flashType = 'danger';
		} elseif ($post && Admin::isDemoMode()) {
			$flash = 'Demo modunda bu işlem yapılamaz.';
			$flashType = 'warning';
		} elseif ($post) {
			[$ok, $flash] = self::handleAdminPost((int) Admin::getId());
			$flashType = $ok ? 'success' : 'danger';
		}

		$status = (string) Tools::getValue('status', '');
		$q = trim((string) Tools::getValue('q', ''));
		$smarty->assign([
			'pageTitle' => $this->title,
			'flash' => $flash,
			'flashType' => $flashType,
			'rdTab' => $tab,
			'rdToken' => $adminToken,
			'rdStatuses' => RdDealer::STATUSES,
			'rdCounts' => RdDealer::countByStatus(),
			'rdFilterStatus' => $status,
			'rdQuery' => $q,
			'rdDealers' => in_array($tab, ['applications', 'dealers'], true) ? RdDealer::adminList($tab === 'applications' ? RdDealer::STATUS_PENDING : $status, $q) : [],
			'rdPlans' => in_array($tab, ['plans', 'subscriptions'], true) ? RdPlans::all() : [],
			'rdRequests' => $tab === 'subscriptions' ? (DB::execute('SELECT s.*, d.company_name FROM rd_subscriptions s INNER JOIN rd_dealers d ON d.id_dealer = s.id_dealer ORDER BY s.id_sub DESC LIMIT 100') ?: []) : [],
			'rdCommissions' => $tab === 'commissions' ? (DB::execute('SELECT k.*, d.company_name FROM rd_commissions k INNER JOIN rd_dealers d ON d.id_dealer = k.id_dealer ORDER BY k.id_commission DESC LIMIT 200') ?: []) : [],
			'rdCommStatuses' => RdCommission::STATUSES,
			'rdPlatform' => $tab === 'reports' ? RdReport::platform() : [],
			'rdDefaultRate' => RdCommission::defaultRate(),
			'rdPending' => RdMigrator::pending(),
			'rdPanelUrl' => RdView::url('bayi-giris'),
		]);
	}

	/** @return array{0:bool,1:string} */
	private static function handleAdminPost(int $adminId): array
	{
		$a = (string) Tools::getValue('rdAction');
		$num = static fn(string $k): float => (float) str_replace(',', '.', (string) Tools::getValue($k));

		switch ($a) {
			case 'migrate':
				$r = RdMigrator::run();

				return [$r['success'], $r['success'] ? 'Migration çalıştırıldı: ' . implode(', ', $r['applied']) : $r['error']];
			case 'dealer_status':
				$ok = RdDealer::setStatus((int) Tools::getValue('id_dealer'), (string) Tools::getValue('to_status'), $adminId, (string) Tools::getValue('reason'));

				return [$ok, $ok ? 'Bayi durumu güncellendi.' : 'Durum güncellenemedi.'];
			case 'dealer_rates':
				$ok = RdDealer::setRates((int) Tools::getValue('id_dealer'), $num('commission_rate'), $num('parent_share_rate'), $adminId);

				return [$ok, $ok ? 'Oranlar güncellendi (yalnızca yeni siparişlere uygulanır).' : 'Geçersiz oran (0-100; toplam 100\'ü aşamaz).'];
			case 'plan_save':
				$r = RdPlans::save($_POST);

				return [$r['success'], $r['message']];
			case 'plan_assign':
				$r = RdPlans::assign((int) Tools::getValue('id_dealer'), (string) Tools::getValue('plan_code'), (string) Tools::getValue('expires'), $adminId, (string) Tools::getValue('note'));

				return [$r['success'], $r['message']];
			case 'commission_set':
				$ok = RdCommission::adminSet((int) Tools::getValue('id_commission'), (string) Tools::getValue('to_status'), $adminId);

				return [$ok, $ok ? 'Komisyon durumu güncellendi.' : 'Kayıt bulunamadı.'];
			case 'attach_customer':
				$email = strtolower(trim((string) Tools::getValue('user_email')));
				$idUser = (int) DB::getValue('SELECT id_user FROM users WHERE email = ? LIMIT 1', [$email]);
				$ok = $idUser > 0 && RdCommission::attach($idUser, (int) Tools::getValue('id_dealer'), 'admin');

				return [$ok, $ok ? 'Müşteri bayiye bağlandı.' : 'Kullanıcı bulunamadı, zaten bir bayiye bağlı veya bayinin kendisi.'];
			case 'settings':
				$c = $num('default_commission');

				if ($c < 0 || $c > 100) {
					return [false, 'Geçersiz oran (0-100).'];
				}

				Settings::set('RAFIMDA_DEALER_DEFAULT_COMMISSION', (string) round($c, 2));

				return [true, 'Ayarlar kaydedildi.'];
		}

		return [false, 'Bilinmeyen işlem.'];
	}
}
