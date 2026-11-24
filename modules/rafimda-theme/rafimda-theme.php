<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

/**
 * Rafımda mağaza teması entegrasyonu: FriSay'ın mevcut temasının (theme4) ÜSTÜNE marka renkleri (lacivert/turuncu), ana sayfa hero'su,
 * kategori şeridi, veritabanından dinamik ürün bölümleri, yönetici panelinden yönetilen banner alanları, satıcı ve bayi tanıtım bölümleri ekler.
 * Çekirdeğe ve tema dosyalarına dokunmaz. Veritabanı tablosu YOKTUR (kurulum migration çalıştırmaz); ayarlar yalnızca panelden Kaydet'e
 * basılınca `settings` tablosuna yazılır. Çıktı kullanıcıya göre değişmez (sayfa önbelleği güvenli). Ürün/kategori yoksa bölüm HİÇ çizilmez:
 * örnek/sahte görsel ve ürün gösterilmez.
 */
class RafimdaThemeModule extends ModuleBase
{
	public const VERSION = '0.2.0';
	public const KEYS = ['hero' => 'RAFIMDA_THEME_HERO', 'title' => 'RAFIMDA_THEME_TITLE', 'subtitle' => 'RAFIMDA_THEME_SUBTITLE', 'footer' => 'RAFIMDA_THEME_FOOTER_LINKS', 'sections' => 'RAFIMDA_THEME_SECTIONS', 'banners' => 'RAFIMDA_THEME_BANNERS', 'hero_image' => 'RAFIMDA_THEME_HERO_IMAGE'];
	public const BANNER_SLOTS = 4;

	public string $name = 'rafimda-theme';
	public string $title = 'Rafımda Tema Entegrasyonu';
	public string $version = self::VERSION;
	public string $description = 'Lacivert/turuncu marka teması, ana sayfa hero\'su, kategori şeridi, dinamik ürün bölümleri, banner alanları, satıcı ve bayi tanıtımı (mevcut temanın üstüne; çekirdeğe dokunmaz)';
	public string $author = 'Rafımda';

	/** Mevcut tema (theme4) bu kancaları çizer: home_slider, home_bottom, footer, mobile_menu, auth_register. */
	public array $displayHooks = [
		'home_slider' => 'Rafımda ana sayfa hero\'su ve kategori şeridi',
		'home_bottom' => 'Ana sayfa: banner alanları, ürün bölümleri, satıcı ve bayi tanıtımı',
		'footer' => 'Rafımda alt bilgi: satıcı/bayi bağlantıları',
		'mobile_menu' => 'Mobil menü: satıcı/bayi bağlantıları',
		'auth_register' => 'Kayıt sayfası: satıcı/bayi olma bağlantıları',
	];

	public array $defaultDisplayHooks = ['home_slider', 'home_bottom', 'footer', 'mobile_menu', 'auth_register'];

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
		$this->registerAdminMenuLink('Rafımda Tema', 'system', 43);
	}

	public static function setting(string $key, string $default = ''): string
	{
		$v = class_exists('Settings') ? (string) Settings::get(self::KEYS[$key]) : '';

		return $v !== '' ? $v : $default;
	}

	public static function vendorEnabled(): bool
	{
		return class_exists('Module') && Module::isEnabled('rafimda-vendor');
	}

	public static function dealerEnabled(): bool
	{
		return class_exists('Module') && Module::isEnabled('rafimda-dealer');
	}

	/** Ana sayfa mı? (kancalar her sayfada çizildiği için ağır sorgular yalnızca burada çalışır) */
	public static function isHome(): bool
	{
		$c = class_exists('Tools') ? (string) Tools::getValue('container', '') : '';

		return $c === '' || $c === 'home';
	}

	/** @return array<string,mixed> */
	public static function viewVars(): array
	{
		global $domain;
		$base = rtrim((string) ($domain ?? ''), '/') . '/';

		return [
			'rtBase' => $base,
			'rtVendor' => self::vendorEnabled(),
			'rtDealer' => self::dealerEnabled(),
			'rtLoginUrl' => $base . 'satici-giris',
			'rtRegisterUrl' => $base . 'satici-kayit',
			'rtDealerLoginUrl' => $base . 'bayi-giris',
			'rtDealerApplyUrl' => $base . 'bayi-basvuru',
			'rtTitle' => self::setting('title', 'Aradığınız Her Şey, Rafımda!'),
			'rtSubtitle' => self::setting('subtitle', 'Binlerce satıcıdan güvenle alışveriş yapın; kendi mağazanızı açın.'),
		];
	}

	/**
	 * Yönetici banner tanımları: yalnızca başlığı dolu olanlar. Bağlantı yalnızca '/' ile başlayan yol veya http(s) olabilir.
	 *
	 * @return list<array{title:string,text:string,link:string,image:string,cta:string}>
	 */
	public static function banners(): array
	{
		$raw = json_decode(self::setting('banners', '[]'), true);
		$out = [];

		if (!is_array($raw)) {
			return [];
		}

		foreach (array_slice($raw, 0, self::BANNER_SLOTS) as $b) {
			$n = self::cleanBanner(is_array($b) ? $b : []);

			if ($n['title'] !== '') {
				$out[] = $n;
			}
		}

		return $out;
	}

	/** @param array<string,mixed> $b @return array{title:string,text:string,link:string,image:string,cta:string} */
	public static function cleanBanner(array $b): array
	{
		$s = static fn(string $k, int $max): string => mb_substr(trim(strip_tags((string) ($b[$k] ?? ''))), 0, $max);
		$url = static function (string $v): string {
			$v = trim($v);

			return (preg_match('#^(https?://[^\s"\'<>]+|/[^\s"\'<>/][^\s"\'<>]*|/)$#i', $v) === 1) ? $v : '';
		};

		return ['title' => $s('title', 80), 'text' => $s('text', 160), 'link' => $url((string) ($b['link'] ?? '')), 'image' => $url((string) ($b['image'] ?? '')), 'cta' => $s('cta', 30)];
	}

	/**
	 * Ana sayfa verisi: yalnızca veritabanından. Hata/boş sonuçta ilgili bölüm atlanır; ana sayfayı asla bozmaz.
	 *
	 * @return array{categories:list<array<string,mixed>>,sections:list<array{key:string,title:string,url:string,products:list<array<string,mixed>>}>}
	 */
	public static function homeData(): array
	{
		$out = ['categories' => [], 'sections' => []];

		try {
			if (class_exists('Category')) {
				foreach (array_slice(Category::getHomeList(), 0, 12) as $cat) {
					$out['categories'][] = [
						'name' => (string) ($cat['category_name'] ?? ''),
						'url' => Category::getUrl($cat),
						'image' => (string) ($cat['image_url'] ?? ''),
						'initial' => mb_strtoupper(mb_substr((string) ($cat['category_name'] ?? ''), 0, 1)),
					];
				}
			}

			if (!class_exists('Product') || self::setting('sections', '1') !== '1') {
				return $out;
			}

			$sets = [
				['new', 'Yeni Ürünler', Product::getActiveList(null, 8, 0, 'newest')],
				['deals', 'Fırsat Ürünleri', Product::getDiscountedList(8)],
				['best', 'Çok Satanlar', self::bestSellers(8)],
				['top', 'En Beğenilenler', Product::getTopRatedList(8)],
			];

			foreach ($sets as [$key, $title, $products]) {
				if (is_array($products) && $products !== []) {
					$out['sections'][] = ['key' => $key, 'title' => $title, 'url' => '', 'products' => array_values($products)];
				}
			}
		} catch (\Throwable $e) {
			error_log('rafimda-theme: ' . $e->getMessage());
		}

		return $out;
	}

	/** Gerçek satış adedine göre (iptal/iade hariç). Satış yoksa boş döner; sahte "çok satan" üretilmez. @return list<array<string,mixed>> */
	public static function bestSellers(int $limit = 8): array
	{
		$limit = max(1, min(24, $limit));
		$ids = DB::execute(
			'SELECT od.id_product, SUM(od.qty) AS sold FROM order_detail od INNER JOIN orders o ON o.id_order = od.id_order
			WHERE o.status NOT IN (5, 6) GROUP BY od.id_product ORDER BY sold DESC LIMIT ' . $limit
		) ?: [];

		if ($ids === []) {
			return [];
		}

		$idList = array_map(static fn(array $r): int => (int) $r['id_product'], $ids);
		$rows = DB::execute(
			'SELECT p.*, b.brand_name, b.brand_link, c.category_name, c.category_link, i.id_image
			FROM products p INNER JOIN brands b ON p.id_brand = b.id_brand INNER JOIN categories c ON p.id_category = c.id_category
			LEFT JOIN images i ON p.id_product = i.id_product AND i.cover = 1
			WHERE p.active = 1 AND p.id_product IN (' . implode(',', $idList) . ')'
		) ?: [];

		if ($rows === []) {
			return [];
		}

		$byId = [];

		foreach (Product::enrichList($rows) as $r) {
			$byId[(int) $r['id_product']] = $r;
		}

		$ordered = [];

		foreach ($idList as $id) {
			if (isset($byId[$id])) {
				$ordered[] = $byId[$id];
			}
		}

		return $ordered;
	}

	public function renderDisplayHook(string $hook, array $context = []): ?string
	{
		if (!isset($this->displayHooks[$hook])) {
			return null;
		}

		$vars = self::viewVars();

		if ($hook === 'home_slider') {
			if (!self::isHome() || self::setting('hero', '1') !== '1') {
				return null;
			}

			$vars['rtCategories'] = self::homeData()['categories'];
			$vars['rtHeroImage'] = self::cleanBanner(['image' => self::setting('hero_image', '')])['image'];
			$html = $this->renderFrontTemplate('hero', $vars);

			return $html !== '' ? $html : null;
		}

		if ($hook === 'home_bottom') {
			if (!self::isHome()) {
				return null;
			}

			$data = self::homeData();
			$vars['rtSections'] = $data['sections'];
			$vars['rtBanners'] = self::banners();
			$vars['rtShowPromo'] = self::setting('footer', '1') === '1';
			$html = trim($this->renderFrontTemplate('home_bottom', $vars));

			return $html !== '' ? $html : null;
		}

		// footer / mobile_menu / auth_register: yalnızca etkin modüllerin bağlantıları (ölü bağlantı üretilmez)
		if (self::setting('footer', '1') !== '1' || (!$vars['rtVendor'] && !$vars['rtDealer'])) {
			return null;
		}

		$tpl = ['footer' => 'links_footer', 'mobile_menu' => 'links_mobile', 'auth_register' => 'auth_hint'][$hook];
		$html = $this->renderFrontTemplate($tpl, $vars);

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

		foreach (['rafimda-vendor' => 'Satıcı sistemi', 'rafimda-dealer' => 'Bayi sistemi', 'xml-hub' => 'XML Hub', 'marketplace-hub' => 'Pazaryeri Hub', 'rafimda-showcase' => 'Vitrin'] as $k => $label) {
			$mods[] = ['name' => $k, 'label' => $label, 'installed' => Module::isInstalled($k), 'enabled' => Module::isEnabled($k)];
		}

		$banners = [];

		for ($i = 0; $i < self::BANNER_SLOTS; $i++) {
			$banners[$i] = self::banners()[$i] ?? ['title' => '', 'text' => '', 'link' => '', 'image' => '', 'cta' => ''];
		}

		$smarty->assign(['pageTitle' => $this->title, 'rtFlash' => $flash, 'rtToken' => $adminToken, 'rtMods' => $mods, 'rtBannerSlots' => $banners, 'rtSet' => [
			'hero_image' => self::setting('hero_image', ''), 'hero' => self::setting('hero', '1'), 'footer' => self::setting('footer', '1'), 'sections' => self::setting('sections', '1'), 'title' => self::setting('title', ''), 'subtitle' => self::setting('subtitle', ''),
		]]);
	}

	/** @param array<string,mixed> $in */
	public static function saveSettings(array $in): void
	{
		Settings::set(self::KEYS['hero'], !empty($in['hero']) ? '1' : '0');
		Settings::set(self::KEYS['footer'], !empty($in['footer']) ? '1' : '0');
		Settings::set(self::KEYS['sections'], !empty($in['sections']) ? '1' : '0');
		Settings::set(self::KEYS['title'], mb_substr(trim(strip_tags((string) ($in['title'] ?? ''))), 0, 120));
		Settings::set(self::KEYS['hero_image'], self::cleanBanner(['image' => (string) ($in['hero_image'] ?? '')])['image']);
		Settings::set(self::KEYS['subtitle'], mb_substr(trim(strip_tags((string) ($in['subtitle'] ?? ''))), 0, 220));
		$list = [];

		for ($i = 0; $i < self::BANNER_SLOTS; $i++) {
			$b = self::cleanBanner(is_array($in['banner'][$i] ?? null) ? $in['banner'][$i] : []);

			if ($b['title'] !== '') {
				$list[] = $b;
			}
		}

		Settings::set(self::KEYS['banners'], json_encode($list, JSON_UNESCAPED_UNICODE) ?: '[]');
	}
}
