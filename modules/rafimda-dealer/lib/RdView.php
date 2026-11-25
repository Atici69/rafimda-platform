<?php

/** Bayi sayfaları için ince görünüm yardımcısı; mağaza temasından bağımsız kendi layout'unu kullanır (Smarty HTML kaçışı açık). */
class RdView
{
	/** Yalnızca testler için: yönlendirmeyi yakalar (üretimde null). */
	public static $onRedirect = null;

	public static function tplDir(): string
	{
		return dirname(__DIR__) . '/assets/templates/front/';
	}

	public static function assetUrl(string $file): string
	{
		global $domain;

		return rtrim((string) $domain, '/') . '/modules/rafimda-dealer/assets/' . ltrim($file, '/');
	}

	/** @param array<string,scalar> $query */
	public static function url(string $route, array $query = []): string
	{
		global $domain;

		$url = rtrim((string) $domain, '/') . '/' . $route;

		return $query === [] ? $url : $url . '?' . http_build_query($query);
	}

	/** @param array<string,scalar> $query */
	public static function panelUrl(string $section = 'dashboard', array $query = []): string
	{
		return self::url('bayi-panel', array_merge(['s' => $section], $query));
	}

	public static function redirect(string $url): void
	{
		if (self::$onRedirect !== null) {
			(self::$onRedirect)($url);
		}

		header('Location: ' . $url);
		exit;
	}

	public static function flash(string $type, string $message): void
	{
		$_SESSION['rd_flash'] = ['type' => $type, 'message' => $message];
	}

	/** @return array{type:string,message:string}|null */
	public static function takeFlash(): ?array
	{
		$f = $_SESSION['rd_flash'] ?? null;
		unset($_SESSION['rd_flash']);

		return is_array($f) ? $f : null;
	}

	public static function post(string $key, string $default = ''): string
	{
		return isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
	}

	public static function isPost(): bool
	{
		return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
	}

	public static function money($v): string
	{
		return number_format((float) $v, 2, ',', '.') . ' ₺';
	}

	/** @param array<string,mixed> $vars */
	public static function logoUrl(): string
	{
		global $domain;

		if (class_exists('SiteAssets') && method_exists('SiteAssets', 'resolveLogoUrl')) {
			return SiteAssets::resolveLogoUrl('header') . (method_exists('SiteAssets', 'logoVersion') ? '?v=' . SiteAssets::logoVersion('header') : '');
		}

		return rtrim((string) $domain, '/') . '/img/logo.png';
	}

	public static function display(string $template, array $vars = []): void
	{
		global $smarty, $domain;

		$smarty->setEscapeHtml(true);
		$smarty->assign($vars + [
			'rdDomain' => rtrim((string) $domain, '/') . '/',
			'rdTplDir' => self::tplDir(),
			'rdCss' => self::assetUrl('css/panel.css') . '?v=' . RafimdaDealerModule::VERSION,
			'rdJs' => self::assetUrl('js/panel.js') . '?v=' . RafimdaDealerModule::VERSION,
			'rdToken' => Security::getCsrfToken('front'),
			'rdSiteName' => (string) Settings::get('SITE_NAME'),
			'rdLogoUrl' => self::logoUrl(),
		]);
		$smarty->display('file:' . self::tplDir() . $template . '.tpl');
	}
}
