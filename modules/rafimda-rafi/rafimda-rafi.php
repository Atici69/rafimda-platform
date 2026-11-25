<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';
require_once __DIR__ . '/lib/RafiEngine.php';
require_once __DIR__ . '/lib/RafiBot.php';
require_once __DIR__ . '/lib/RafiHelp.php';

/**
 * Rafi – Rafımda alışveriş asistanı. Kural tabanlı, ÜCRETSİZ; harici yapay zekâ API'si / abonelik / ek sunucu KULLANMAZ.
 * Arama, filtre ve karşılaştırma yalnızca gerçek katalog kayıtlarıyla yapılır. Tablo oluşturmaz (canlı şemayı değiştirmez).
 */
class RafimdaRafiModule extends ModuleBase
{
	public const VERSION = '0.2.0';

	public string $name = 'rafimda-rafi';
	public string $title = 'Rafi Alışveriş Asistanı (Rafımda)';
	public string $version = self::VERSION;
	public string $description = 'Katalog tabanlı sohbet asistanı: ürün arama, fiyat/stok/satıcı/özellik karşılaştırması (ücretsiz, harici API yok)';
	public string $author = 'Rafımda';

	public array $displayHooks = ['footer' => 'Rafi sohbet balonu (tüm sayfalar; ana sayfada karşılama)'];

	public array $defaultDisplayHooks = ['footer'];

	public array $frontStylesheets = ['rafi.css'];

	public array $frontScripts = ['rafi.js'];

	public array $apiActions = ['chat' => 'api/chat.php'];

	public const SETTINGS = ['RAFI_ENABLED' => '1', 'RAFI_GREET_HOME' => '1', 'RAFI_HELP_VIDEOS' => '1'];

	public function install(): bool
	{
		foreach (self::SETTINGS as $k => $v) {
			if ((string) Settings::get($k) === '') {
				Settings::set($k, $v);
			}
		}

		return true;
	}

	public function uninstall(): bool
	{
		return true;
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Rafi Asistanı', 'settings', 40);
	}

	public function renderDisplayHook(string $hook, array $context = []): ?string
	{
		if ($hook !== 'footer' || (string) Settings::get('RAFI_ENABLED') === '0') {
			return null;
		}

		// Görüntü kancaları config/settings.php içinde, index.php $container'ı belirlemeden önce çizilir; aynı kuralla istekten okunur
		$c = trim((string) Tools::getValue('container', ''));
		$isHome = $c === '' || $c === 'home';
		$html = $this->renderFrontTemplate('widget', [
			'rafiApi' => $this->apiUrl(),
			'rafiAvatar' => $this->getAssetUrl('img/rafi.svg'),
			'rafiGreet' => $isHome && (string) Settings::get('RAFI_GREET_HOME') !== '0',
			'rafiGreeting' => RafiBot::GREETING,
		]);

		return $html !== '' ? $html : null;
	}

	private function apiUrl(): string
	{
		$folder = class_exists('Settings', false) ? trim((string) Settings::get('FOLDER'), '/') : '';

		return ($folder !== '' ? '/' . $folder : '') . '/api/module.php?m=' . $this->name . '&action=chat';
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
				foreach (array_keys(self::SETTINGS) as $k) {
					Settings::set($k, !empty($_POST[$k]) ? '1' : '0');
				}

				$flash = ['type' => 'success', 'message' => 'Kaydedildi.'];
			}
		}

		$preview = null;
		$q = trim((string) ($_GET['deneme'] ?? ''));

		if ($q !== '') {
			$preview = RafiBot::reply($q);
		}

		$smarty->assign([
			'pageTitle' => $this->title, 'rafiFlash' => $flash, 'rafiToken' => $adminToken, 'rafiQuery' => $q, 'rafiPreview' => $preview,
			'rafiSettings' => ['RAFI_ENABLED' => (string) Settings::get('RAFI_ENABLED') !== '0', 'RAFI_GREET_HOME' => (string) Settings::get('RAFI_GREET_HOME') !== '0', 'RAFI_HELP_VIDEOS' => (string) Settings::get('RAFI_HELP_VIDEOS') !== '0'],
			'rafiVideos' => RafiHelp::available(),
			'rafiVideoBase' => $this->getAssetUrl('video/'),
			'rafiAvatar' => $this->getAssetUrl('img/rafi.svg'),
		]);
	}
}
