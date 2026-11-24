<?php

/**
 * Satıcı paneli/formları için ince görünüm yardımcısı.
 * Mağaza temasından BAĞIMSIZDIR: kendi layout'unu kullanır, tema header/footer'ı yüklenmez.
 * Smarty HTML kaçışı bu istekte AÇILIR (setEscapeHtml); ham HTML için `nofilter` gerekir.
 */
class RvView
{
	public static function tplDir(): string
	{
		return dirname(__DIR__) . '/assets/templates/front/';
	}

	public static function assetUrl(string $file): string
	{
		global $domain;

		return rtrim((string) $domain, '/') . '/modules/rafimda-vendor/assets/' . ltrim($file, '/');
	}

	public static function url(string $route, array $query = []): string
	{
		global $domain;

		$url = rtrim((string) $domain, '/') . '/' . $route;

		return $query === [] ? $url : $url . '?' . http_build_query($query);
	}

	public static function panelUrl(string $section = 'dashboard', array $query = []): string
	{
		return self::url('satici-panel', array_merge(['s' => $section], $query));
	}

	/** Yalnızca testler için: yönlendirmeyi yakalar (üretimde null). @var callable|null */
	public static $onRedirect = null;

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
		$_SESSION['rv_flash'] = ['type' => $type, 'message' => $message];
	}

	/** @return array{type:string,message:string}|null */
	public static function takeFlash(): ?array
	{
		$f = $_SESSION['rv_flash'] ?? null;
		unset($_SESSION['rv_flash']);

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

	/**
	 * @param array<string,mixed> $vars
	 */
	public static function display(string $template, array $vars = []): void
	{
		global $smarty, $domain;

		$smarty->setEscapeHtml(true);
		$smarty->assign($vars + [
			'rvDomain' => rtrim((string) $domain, '/') . '/',
			'rvTplDir' => self::tplDir(),
			'rvCss' => self::assetUrl('css/panel.css') . '?v=' . RafimdaVendorModule::VERSION,
			'rvJs' => self::assetUrl('js/panel.js') . '?v=' . RafimdaVendorModule::VERSION,
			'rvToken' => Security::getCsrfToken('front'),
			'rvSiteName' => (string) Settings::get('SITE_NAME'),
		]);
		$smarty->display('file:' . self::tplDir() . $template . '.tpl');
	}
}
