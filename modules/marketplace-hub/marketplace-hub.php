<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

foreach (['MhMigrator', 'MhCrypt', 'MhOwner', 'MhAdapter', 'MhAbstractAdapter', 'MhRegistry', 'MhRepo', 'MhPayload', 'MhDispatcher', 'MhPanel'] as $mhClass) {
	require_once __DIR__ . '/lib/' . $mhClass . '.php';
}

/**
 * Pazaryeri Hub — bağımsız pazaryeri bağdaştırıcı mimarisi (Trendyol, Hepsiburada, N11, Amazon, Pazarama, Ozon).
 * ÖNEMLİ: Bu sürümde HİÇBİR pazaryeri için gerçek API entegrasyonu yoktur; bağdaştırıcılar yalnızca kimlik alanlarını tanımlar
 * ve gönderim istekleri "blocked" olarak kaydedilir. Sahte başarı üretilmez. Çekirdek dosyalara dokunmaz.
 */
class MarketplaceHubModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'marketplace-hub';
	public string $title = 'Pazaryeri Hub';
	public string $version = self::VERSION;
	public string $description = 'Pazaryeri bağdaştırıcı mimarisi: şifreli API kimlikleri, kategori eşleme, gönderim kuyruğu, hata günlüğü (gerçek API entegrasyonu yok)';
	public string $author = 'Rafımda';

	public array $routes = [
		'pazaryeri' => 'front/hub.php',
		'pazaryeri-cron' => 'front/cron.php',
	];

	public function install(): bool
	{
		if (!$this->runSqlFile('install.sql')) {
			return false;
		}

		$res = MhMigrator::run();

		if (!$res['success']) {
			error_log('marketplace-hub: ' . $res['error']);

			return false;
		}

		if (!preg_match('/^[0-9a-f]{64}$/', (string) Settings::get('MARKETPLACE_HUB_SECRET'))) {
			Settings::set('MARKETPLACE_HUB_SECRET', bin2hex(random_bytes(32)));
		}

		if (Settings::get('MARKETPLACE_HUB_CRON_KEY') === '') {
			Settings::set('MARKETPLACE_HUB_CRON_KEY', bin2hex(random_bytes(24)));
		}

		return true;
	}

	/** Veri silinmez; bkz. uninstall.destructive.sql.manual */
	public function uninstall(): bool
	{
		return $this->runSqlFile('uninstall.sql');
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Pazaryeri Hub', 'catalog', 41);
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken, $domain;

		$demo = Admin::isDemoMode();
		$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
		$tokenOk = $isPost && hash_equals((string) $adminToken, (string) ($_POST['token'] ?? ''));
		$flash = null;
		$pending = MhMigrator::pending();

		if ($isPost && (string) ($_POST['mhAction'] ?? '') === 'migrate') {
			if ($tokenOk && !$demo) {
				$r = MhMigrator::run();
				$_SESSION['mh_flash'] = ['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['success'] ? 'Migration çalıştırıldı.' : $r['error']];
			}

			header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'));
			exit;
		}

		if ($isPost && !$tokenOk) {
			$flash = ['type' => 'danger', 'message' => 'Geçersiz istek.'];
		} elseif ($isPost && $demo) {
			$flash = ['type' => 'warning', 'message' => 'Demo modunda bu işlem yapılamaz.'];
		}

		$vars = ['view' => 'list', 'accounts' => [], 'marketplaces' => []];

		if ($pending === []) {
			$panel = new MhPanel(0, true, strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'));
			[$view, $vars] = $panel->handle($isPost && $tokenOk && !$demo);
			$vars['view'] = $view;
		}

		$key = (string) Settings::get('MARKETPLACE_HUB_CRON_KEY');
		$smarty->assign($vars + [
			'mhFlash' => $flash ?? MhPanel::takeFlash(),
			'mhToken' => $adminToken,
			'mhCanManage' => true,
			'mhBase' => strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'),
			'mhTplDir' => __DIR__ . '/assets/templates/front/',
			'mhCss' => rtrim((string) $domain, '/') . '/modules/marketplace-hub/assets/css/mh.css?v=' . self::VERSION,
			'mhPending' => $pending,
			'mhCronUrl' => $key !== '' ? rtrim((string) $domain, '/') . '/pazaryeri-cron?q=cron&key=' . $key : '',
			'pageTitle' => $this->title,
		]);
	}
}
