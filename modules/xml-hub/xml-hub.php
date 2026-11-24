<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

foreach (['XhUrlGuard', 'XhHttp', 'XhXml', 'XhMapper', 'XhMigrator', 'XhOwner', 'XhStorage', 'XhCrypt', 'XhMeta', 'XhRepo', 'XhSink', 'XhImporter', 'XhRunner', 'XhPanel'] as $xhClass) {
	require_once __DIR__ . '/lib/' . $xhClass . '.php';
}

/**
 * XML Hub — bağımsız tedarikçi XML veri kaynağı modülü.
 * Satıcı kavramı yalnızca rafimda-vendor kuruluysa kullanılır (XhOwner); ürün yazımı satıcı bağlantılarında RvProducts,
 * platform bağlantılarında FriSay Product::save üzerinden yapılır. Çekirdek dosyalara dokunmaz.
 * Pazaryeri adaptörleri (Trendyol, Hepsiburada, N11...) ileride xh_items + ürün tablosunu veri kaynağı olarak okuyacaktır
 * (bu sürümde adaptör YOK).
 */
class XmlHubModule extends ModuleBase
{
	public const VERSION = '0.2.0';

	public string $name = 'xml-hub';
	public string $title = 'XML Hub';
	public string $version = self::VERSION;
	public string $description = 'Tedarikçi XML içe aktarma ve senkronizasyon: güvenli alma (SSRF/XXE korumalı), alan eşleştirme, kuyruk, fiyat/stok geçmişi';
	public string $author = 'Rafımda';

	public array $routes = [
		'xml-merkezi' => 'front/hub.php',
		'xml-cron' => 'front/cron.php',
	];

	public function install(): bool
	{
		if (!$this->runSqlFile('install.sql')) {
			return false;
		}

		$res = XhMigrator::run();

		if (!$res['success']) {
			error_log('xml-hub: ' . $res['error']);

			return false;
		}

		if (!preg_match('/^[0-9a-f]{64}$/', (string) Settings::get('XML_HUB_SECRET'))) {
			Settings::set('XML_HUB_SECRET', bin2hex(random_bytes(32)));
		}

		if (Settings::get('XML_HUB_CRON_KEY') === '') {
			Settings::set('XML_HUB_CRON_KEY', bin2hex(random_bytes(24)));
		}

		return true;
	}

	/** Veri silinmez; bkz. uninstall.destructive.sql.manual */
	public function uninstall(): bool
	{
		return true;
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('XML Hub', 'catalog', 40);
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken, $domain;

		$demo = Admin::isDemoMode();
		$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
		$tokenOk = $isPost && hash_equals((string) $adminToken, (string) ($_POST['token'] ?? ''));
		$flash = null;
		$pending = XhMigrator::pending();

		if ($isPost && (string) ($_POST['xhAction'] ?? '') === 'migrate') {
			if ($tokenOk && !$demo) {
				$r = XhMigrator::run();
				$_SESSION['xh_flash'] = ['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['success'] ? 'Migration çalıştırıldı.' : $r['error']];
			}

			header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'));
			exit;
		}

		if ($isPost && !$tokenOk) {
			$flash = ['type' => 'danger', 'message' => 'Geçersiz istek.'];
		} elseif ($isPost && $demo) {
			$flash = ['type' => 'warning', 'message' => 'Demo modunda bu işlem yapılamaz.'];
		}

		$vars = ['view' => 'list'];

		if ($pending === []) {
			$panel = new XhPanel(0, true, '');
			[$view, $vars] = $panel->handle($isPost && $tokenOk && !$demo);
			$vars['view'] = $view;
		}

		$key = (string) Settings::get('XML_HUB_CRON_KEY');
		$smarty->assign($vars + [
			'xhFlash' => $flash ?? XhPanel::takeFlash(),
			'xhToken' => $adminToken,
			'xhCanManage' => true,
			'xhTplDir' => __DIR__ . '/assets/templates/front/',
			'xhCss' => rtrim((string) $domain, '/') . '/modules/xml-hub/assets/css/xh.css?v=' . self::VERSION,
			'xhJs' => rtrim((string) $domain, '/') . '/modules/xml-hub/assets/js/xh.js?v=' . self::VERSION,
			'xhPending' => $pending,
			'xhCronUrl' => $key !== '' ? XhRunner::cronUrl((string) $domain, $key) : '',
			'pageTitle' => $this->title,
		]);
	}
}
