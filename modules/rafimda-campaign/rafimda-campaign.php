<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';
require_once __DIR__ . '/lib/RcCampaign.php';

/**
 * Rafımda ticari kampanyalar: "ilk ay ücretsiz vitrin" ve yapılandırılabilir düşük komisyon.
 * Tarih penceresi, uygunluk kuralları (yeni satıcı süresi, paket, satıcı listesi), hesaplama kuralı ve sipariş sınırı yönetici panelinden tanımlanır.
 * ÖDEME ALMAZ: vitrin ücreti yalnızca borç kaydıdır; tahsilat elle işaretlenir. Çekirdeğe dokunmaz; rafimda-vendor/rafimda-showcase ile
 * class_exists koruması üzerinden çalışır (bu modül kapalıyken her şey eski davranışla çalışır).
 */
class RafimdaCampaignModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'rafimda-campaign';
	public string $title = 'Rafımda Kampanyalar';
	public string $version = self::VERSION;
	public string $description = 'İlk ay ücretsiz vitrin ve yapılandırılabilir düşük komisyon kampanyaları: tarih, uygunluk, hesaplama kuralı, sipariş sınırı, yönetici kontrolü';
	public string $author = 'Rafımda';

	public function install(): bool
	{
		return $this->runSqlFile('install.sql');
	}

	public function uninstall(): bool
	{
		return $this->runSqlFile('uninstall.sql'); // veri silinmez
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Kampanyalar (Rafımda)', 'sales', 62);
	}

	/** @return array{type:string,message:string}|null */
	public static function handlePost(int $idAdmin): ?array
	{
		$a = (string) ($_POST['rcAction'] ?? '');
		$id = (int) ($_POST['id_campaign'] ?? 0);

		switch ($a) {
			case 'campaign_save':
				$r = RcCampaign::save($_POST, $id);
				break;
			case 'campaign_toggle':
				$on = (string) ($_POST['to'] ?? '') === '1';
				$ok = RcCampaign::setActive($id, $on);
				$r = ['success' => $ok, 'message' => $ok ? ($on ? 'Kampanya açıldı.' : 'Kampanya kapatıldı (mevcut katılımlar süresince devam eder).') : 'Kampanya bulunamadı.'];
				break;
			case 'enroll':
				$r = RcCampaign::enroll($id, (int) ($_POST['id_vendor'] ?? 0), 'admin');
				break;
			case 'enroll_end':
				$ok = RcCampaign::endEnrollment((int) ($_POST['id_enrollment'] ?? 0), $idAdmin);
				$r = ['success' => $ok, 'message' => $ok ? 'Katılım sonlandırıldı.' : 'Katılım bulunamadı.'];
				break;
			case 'fee_save':
				$fee = (float) str_replace(',', '.', (string) ($_POST['fee'] ?? '0'));
				$ok = RcCampaign::setShowcaseFee($fee);
				$r = ['success' => $ok, 'message' => $ok ? 'Vitrin ücreti kaydedildi (0: ücret yok, herkese ücretsiz).' : 'Geçersiz tutar.'];
				break;
			case 'charge_settle':
				$ok = RcCampaign::markSettled((int) ($_POST['id_charge'] ?? 0), (string) ($_POST['note'] ?? ''), $idAdmin);
				$r = ['success' => $ok, 'message' => $ok ? 'Tahsilat "ödendi" olarak işaretlendi; vitrin talebi artık onaylanabilir.' : 'Kayıt bulunamadı veya zaten kapalı.'];
				break;
			default:
				return null;
		}

		return ['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['message']];
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken;

		$flash = null;
		$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';

		if ($isPost) {
			if (!hash_equals((string) $adminToken, (string) ($_POST['token'] ?? ''))) {
				$flash = ['type' => 'danger', 'message' => 'Geçersiz istek.'];
			} elseif (Admin::isDemoMode()) {
				$flash = ['type' => 'warning', 'message' => 'Demo modunda bu işlem yapılamaz.'];
			} else {
				$flash = self::handlePost((int) Admin::getId());
			}
		}

		$tab = (string) ($_GET['tab'] ?? 'campaigns');

		if (!in_array($tab, ['campaigns', 'enrollments', 'charges', 'simulator'], true)) {
			$tab = 'campaigns';
		}

		$edit = null;

		if ($tab === 'campaigns' && isset($_GET['edit'])) {
			$edit = RcCampaign::get((int) $_GET['edit']);
		}

		$sim = null;

		if ($tab === 'simulator' && isset($_GET['id_vendor'])) {
			$sim = RcCampaign::simulate((int) $_GET['id_vendor'], (float) str_replace(',', '.', (string) ($_GET['amount'] ?? '1000')));
		}

		$smarty->assign([
			'pageTitle' => $this->title, 'rcFlash' => $flash, 'rcToken' => $adminToken, 'rcTab' => $tab,
			'rcCampaigns' => $tab === 'campaigns' ? RcCampaign::all() : [], 'rcEdit' => $edit, 'rcTypes' => RcCampaign::TYPES, 'rcModes' => RcCampaign::MODES,
			'rcEnrollments' => $tab === 'enrollments' ? RcCampaign::enrollments() : [], 'rcAllCampaigns' => $tab === 'enrollments' ? RcCampaign::all() : [],
			'rcFee' => RcCampaign::showcaseFee(), 'rcChargeStatus' => (string) ($_GET['cs'] ?? 'due'),
			'rcCharges' => $tab === 'charges' ? RcCampaign::charges((string) ($_GET['cs'] ?? 'due')) : [],
			'rcSim' => $sim, 'rcSimVendor' => (int) ($_GET['id_vendor'] ?? 0), 'rcSimAmount' => (string) ($_GET['amount'] ?? '1000'),
			'rcDefaultCommission' => class_exists('RvOrders') ? RvOrders::defaultCommission() : 10.0,
			'rcVendorModule' => class_exists('RvOrders'), 'rcShowcaseModule' => class_exists('RsShowcase'),
		]);
	}
}
