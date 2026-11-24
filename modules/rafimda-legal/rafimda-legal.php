<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';
require_once __DIR__ . '/lib/RlSeed.php';
require_once __DIR__ . '/lib/RlLegal.php';

/**
 * Rafımda Hukuki Belgeler ve KVKK: sürümlü belgeler, şirket bilgisi alanları, hukuki inceleme işareti, kabul kayıtları,
 * ayrı izinler (KVKK aydınlatma ≠ açık rıza ≠ ticari ileti), çerez tercihleri. Metinler TASLAKTIR; hukuki görüş değildir.
 */
class RafimdaLegalModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'rafimda-legal';
	public string $title = 'Hukuki Belgeler ve KVKK (Rafımda)';
	public string $version = self::VERSION;
	public string $description = 'Sürümlü hukuki belgeler, kabul kayıtları, KVKK / açık rıza / ticari ileti izinleri, çerez tercihleri';
	public string $author = 'Rafımda';

	public array $routes = [
		'hukuki' => 'front/doc.php',
		'cerez-tercihleri' => 'front/cookies.php',
	];

	public array $displayHooks = [
		'footer' => 'Çerez bildirimi ve hukuki bağlantılar',
		'auth_register' => 'Kayıt formu: üyelik sözleşmesi, KVKK bilgisi ve isteğe bağlı izinler',
	];

	public array $defaultDisplayHooks = ['footer', 'auth_register'];

	public array $frontStylesheets = ['legal.css'];

	public array $frontScripts = ['legal.js'];

	public function install(): bool
	{
		if (!$this->runSqlFile('install.sql')) {
			return false;
		}

		RlLegal::installDefaults();

		if ((string) Settings::get('RAFIMDA_LEGAL_SALT') === '') {
			Settings::set('RAFIMDA_LEGAL_SALT', bin2hex(random_bytes(16)));
		}

		return true;
	}

	public function uninstall(): bool
	{
		return $this->runSqlFile('uninstall.sql'); // kabul/izin kayıtları ispat belgesidir; silinmez
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Hukuki Belgeler ve KVKK', 'settings', 30);

		// Kayıt: üyelik sözleşmesi zorunlu; seçimler hesap oluşunca bağlanır. Çekirdek kayıt kodu değiştirilmez.
		Module::registerHook('form.captcha.validate', static function ($form, &$error = ''): void {
			if ($form !== 'register' || !empty($error)) {
				return;
			}

			if (empty($_POST['rl_membership']) && RlLegal::published('uyelik-sozlesmesi')) {
				$error = 'Üyelik Sözleşmesi ve KVKK Aydınlatma Metni\'ni okuduğunuzu onaylayın.';

				return;
			}

			RlLegal::stashRegistration($_POST);
		});

		Module::registerHook('smarty.assign', static function (): void {
			try {
				if (!empty($_SESSION['rl_pending_reg']) && class_exists('Customer') && Customer::isLoggedIn()) {
					$id = (int) Customer::getId();
					RlLegal::attachPendingRegistration($id, (string) DB::getValue('SELECT email FROM users WHERE id_user = ?', [$id]));
				}
			} catch (Throwable $e) {
				error_log('rafimda-legal: ' . $e->getMessage());
			}
		});

		Module::registerHook('order.placed', static function (array $order): void {
			try {
				RlLegal::onOrderPlaced($order);
			} catch (Throwable $e) {
				error_log('rafimda-legal: ' . $e->getMessage()); // sipariş akışı bozulmaz
			}
		});
	}

	public function renderDisplayHook(string $hook, array $context = []): ?string
	{
		if ($hook === 'footer') {
			$html = $this->renderFrontTemplate('footer', [
				'rlPrefs' => RlLegal::cookiePrefs(),
				'rlLinks' => RlLegal::linksFor(['kvkk-aydinlatma', 'gizlilik-politikasi', 'cerez-politikasi', 'kullanim-kosullari', 'iptal-iade-politikasi', 'teslimat-kargo', 'sikayet-uyusmazlik', 'kvkk-basvuru']),
				'rlCookieUrl' => rtrim((string) ($GLOBALS['domain'] ?? ''), '/') . '/cerez-tercihleri',
				'rlListUrl' => rtrim((string) ($GLOBALS['domain'] ?? ''), '/') . '/hukuki',
				'rlToken' => class_exists('Security') ? Security::getCsrfToken('front') : '',
			]);

			return $html !== '' ? $html : null;
		}

		if ($hook === 'auth_register') {
			$html = $this->renderFrontTemplate('register', [
				'rlLinks' => RlLegal::linksFor(['uyelik-sozlesmesi', 'kvkk-aydinlatma', 'ticari-ileti-izni', 'acik-riza']),
			]);

			return $html !== '' ? $html : null;
		}

		return null;
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken;

		$flash = null;

		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
			if (!hash_equals((string) $adminToken, (string) ($_POST['token'] ?? ''))) {
				$flash = ['type' => 'danger', 'message' => 'Geçersiz istek.'];
			} elseif (Admin::isDemoMode()) {
				$flash = ['type' => 'warning', 'message' => 'Demo modunda bu işlem yapılamaz.'];
			} else {
				$flash = self::handlePost((int) Admin::getId());
			}
		}

		$smarty->assign(['pageTitle' => $this->title, 'rlFlash' => $flash, 'rlToken' => $adminToken] + self::adminVars($_GET));
	}

	/** @return array<string,mixed> */
	public static function adminVars(array $get): array
	{
		$tab = (string) ($get['tab'] ?? 'documents');
		$tab = in_array($tab, ['documents', 'edit', 'company', 'acceptances', 'consents'], true) ? $tab : 'documents';
		$doc = $tab === 'edit' ? DB::getRowSafe('rl_documents', 'id_document = ?', [(int) ($get['id'] ?? 0)]) : null;
		$ver = $doc ? RlLegal::version((int) ($get['v'] ?? 0) ?: (int) $doc['current_version']) : null;

		if ($ver && (int) $ver['id_document'] !== (int) $doc['id_document']) {
			$ver = null;
		}

		if ($doc && !$ver) {
			$ver = DB::getRowSafe('rl_versions', 'id_document = ? ORDER BY id_version DESC', [(int) $doc['id_document']]) ?: null;
		}

		return [
			'rlTab' => $tab,
			'rlDocs' => $tab === 'documents' ? RlLegal::all() : [],
			'rlStatuses' => RlLegal::STATUSES,
			'rlGroups' => RlLegal::GROUPS,
			'rlDoc' => $doc ?: null,
			'rlVer' => $ver,
			'rlVersions' => $doc ? RlLegal::versions((int) $doc['id_document']) : [],
			'rlPreview' => $ver ? RlLegal::render((string) $ver['body'], $ver) : '',
			'rlCompany' => RlLegal::company(),
			'rlMissing' => RlLegal::missingFields(),
			'rlAcceptances' => $tab === 'acceptances' ? RlLegal::acceptances(0, (string) ($get['slug'] ?? '')) : [],
			'rlConsents' => $tab === 'consents' ? RlLegal::consentLog((string) ($get['type'] ?? '')) : [],
			'rlConsentTypes' => RlLegal::CONSENTS,
			'rlReviewCount' => (int) DB::getValue("SELECT COUNT(*) FROM rl_documents WHERE needs_review = 1 AND status <> 'passive'"),
		];
	}

	/** @return array{type:string,message:string} */
	public static function handlePost(int $adminId): array
	{
		$id = (int) ($_POST['id_document'] ?? 0);

		switch ((string) ($_POST['rlAction'] ?? '')) {
			case 'save_version':
				$r = RlLegal::saveVersion($id, (string) ($_POST['body'] ?? ''), (string) ($_POST['change_note'] ?? ''), $adminId, !empty($_POST['publish']));

				return ['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['message']];
			case 'publish_version':
				$ok = RlLegal::publishVersion($id, (int) ($_POST['id_version'] ?? 0));

				return ['type' => $ok ? 'success' : 'danger', 'message' => $ok ? 'Sürüm yayınlandı.' : 'Sürüm bulunamadı.'];
			case 'meta':
				$ok = RlLegal::setMeta($id, (string) ($_POST['status'] ?? ''), !empty($_POST['needs_review']), (string) ($_POST['review_note'] ?? ''));

				return ['type' => $ok ? 'success' : 'danger', 'message' => $ok ? 'Belge durumu güncellendi.' : 'Güncellenemedi (bayilik belgeleri bayilik etkinleşmeden yayınlanamaz).'];
			case 'company':
				$n = RlLegal::saveCompany($_POST);

				return ['type' => 'success', 'message' => "Şirket bilgileri kaydedildi ($n alan)."];
			case 'cms_sync':
				$r = RlLegal::syncToCms();

				return ['type' => $r['success'] ? 'success' : 'warning', 'message' => $r['message']];
		}

		return ['type' => 'danger', 'message' => 'Bilinmeyen işlem.'];
	}
}
