<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

/**
 * Rafımda mağaza teması entegrasyonu: FriSay'ın mevcut temasının (theme4) ÜSTÜNE marka renkleri (lacivert/turuncu), ana sayfa hero'su
 * ve satıcı bağlantıları ekler. Çekirdeğe ve tema dosyalarına dokunmaz; yalnızca modül CSS'i ve görüntü kancaları kullanır.
 * Veritabanı tablosu YOKTUR (kurulum migration çalıştırmaz); ayarlar yalnızca yönetici panelinden kaydedilince `settings` tablosuna yazılır.
 * Sayfa önbelleği güvenli: çıktı kullanıcıya göre değişmez.
 */
class RafimdaThemeModule extends ModuleBase
{
	public const VERSION = '0.1.0';
	public const KEYS = ['hero' => 'RAFIMDA_THEME_HERO', 'title' => 'RAFIMDA_THEME_TITLE', 'subtitle' => 'RAFIMDA_THEME_SUBTITLE', 'footer' => 'RAFIMDA_THEME_FOOTER_LINKS'];

	public string $name = 'rafimda-theme';
	public string $title = 'Rafımda Tema Entegrasyonu';
	public string $version = self::VERSION;
	public string $description = 'Lacivert/turuncu marka teması, ana sayfa hero\'su ve satıcı bağlantıları (mevcut temanın üstüne; çekirdeğe dokunmaz)';
	public string $author = 'Rafımda';

	/** Mevcut tema (theme4) bu kancaları çizer: home_slider, footer, mobile_menu, auth_register. */
	public array $displayHooks = [
		'home_slider' => 'Rafımda ana sayfa hero\'su',
		'footer' => 'Rafımda alt bilgi: satıcı bağlantıları',
		'mobile_menu' => 'Mobil menü: satıcı bağlantıları',
		'auth_register' => 'Kayıt sayfası: satıcı olma bağlantısı',
	];

	public array $defaultDisplayHooks = ['home_slider', 'footer', 'mobile_menu', 'auth_register'];

	public array $frontStylesheets = ['rafimda-theme.css'];

	public function install(): bool
	{
		return true; // tablo/migration yok
	}

	public function uninstall(): bool
	{
		return true; // ayar satırları korunur
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Rafımda Tema', 'design', 43);
	}

	public static function setting(string $key, string $default = ''): string
	{
		$v = class_exists('Settings') ? (string) Settings::get(self::KEYS[$key]) : '';

		return $v !== '' ? $v : $default;
	}

	/** Satıcı modülü etkin mi (ölü bağlantı üretmemek için). */
	public static function vendorEnabled(): bool
	{
		return class_exists('Module') && Module::isEnabled('rafimda-vendor');
	}

	/** @return array<string,mixed> */
	public static function viewVars(): array
	{
		global $domain;
		$base = rtrim((string) ($domain ?? ''), '/') . '/';

		return [
			'rtBase' => $base,
			'rtVendor' => self::vendorEnabled(),
			'rtLoginUrl' => $base . 'satici-giris',
			'rtRegisterUrl' => $base . 'satici-kayit',
			'rtTitle' => self::setting('title', 'Aradığınız Her Şey, Rafımda!'),
			'rtSubtitle' => self::setting('subtitle', 'Binlerce satıcıdan güvenle alışveriş yapın; kendi mağazanızı açın.'),
		];
	}

	public function renderDisplayHook(string $hook, array $context = []): ?string
	{
		if (!in_array($hook, array_keys($this->displayHooks), true)) {
			return null;
		}

		if ($hook === 'home_slider' && self::setting('hero', '1') !== '1') {
			return null;
		}

		if (in_array($hook, ['footer', 'mobile_menu', 'auth_register'], true) && (self::setting('footer', '1') !== '1' || !self::vendorEnabled())) {
			return null;
		}

		$tpl = ['home_slider' => 'hero', 'footer' => 'links_footer', 'mobile_menu' => 'links_mobile', 'auth_register' => 'auth_hint'][$hook];
		$html = $this->renderFrontTemplate($tpl, self::viewVars());

		return $html !== '' ? $html : null;
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
				self::saveSettings($_POST);
				$flash = ['type' => 'success', 'message' => 'Kaydedildi.'];
			}
		}

		$mods = [];

		foreach (['rafimda-vendor' => 'Satıcı sistemi', 'xml-hub' => 'XML Hub', 'marketplace-hub' => 'Pazaryeri Hub', 'rafimda-showcase' => 'Vitrin'] as $k => $label) {
			$mods[] = ['name' => $k, 'label' => $label, 'installed' => Module::isInstalled($k), 'enabled' => Module::isEnabled($k)];
		}

		$smarty->assign(['pageTitle' => $this->title, 'rtFlash' => $flash, 'rtToken' => $adminToken, 'rtMods' => $mods, 'rtSet' => [
			'hero' => self::setting('hero', '1'), 'footer' => self::setting('footer', '1'), 'title' => self::setting('title', ''), 'subtitle' => self::setting('subtitle', ''),
		]]);
	}

	/** @param array<string,mixed> $in */
	public static function saveSettings(array $in): void
	{
		Settings::set(self::KEYS['hero'], !empty($in['hero']) ? '1' : '0');
		Settings::set(self::KEYS['footer'], !empty($in['footer']) ? '1' : '0');
		Settings::set(self::KEYS['title'], mb_substr(trim(strip_tags((string) ($in['title'] ?? ''))), 0, 120));
		Settings::set(self::KEYS['subtitle'], mb_substr(trim(strip_tags((string) ($in['subtitle'] ?? ''))), 0, 220));
	}
}
