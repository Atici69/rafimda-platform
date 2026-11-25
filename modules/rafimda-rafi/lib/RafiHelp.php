<?php

/**
 * Rafi Akademi: satıcı paneli, XML Merkezi ve Pazaryeri sayfalarının altında "Yardımcı olmamı ister misin?" bloğu
 * ve sayfaya göre seçilmiş kısa eğitim videoları. Videolar modül içinde statik MP4 dosyalarıdır (harici servis yok).
 * Videolar deploy/preview/rafi_videos/ araçlarıyla ÖRNEK VERİLİ ekranlardan yeniden üretilebilir.
 */
final class RafiHelp
{
	public const SETTING = 'RAFI_HELP_VIDEOS';

	/**
	 * key => [başlık, süre (sn), bağlamlar, kısa açıklama, adımlar]
	 * Bağlam: satıcı paneli bölüm anahtarı (products, stock...), 'xml', 'marketplaces', 'import', 'dashboard'.
	 * @return array<string, array{title:string, seconds:int, contexts:string[], summary:string, steps:string[]}>
	 */
	public static function catalog(): array
	{
		return [
			'urun-ekleme' => [
				'title' => 'Tek tek ürün nasıl eklenir?',
				'seconds' => 0,
				'contexts' => ['products', 'dashboard'],
				'summary' => 'Ürün Yönetimi\'nden yeni ürün açın, kategori/marka/fiyat/stok girin, görsel ekleyip kaydedin.',
				'steps' => ['Ürün Yönetimi → "+ Yeni ürün"', 'Ürün adı, kategori ve marka seçin', 'Satış fiyatı, KDV ve stok girin; stok kodu/barkod ekleyin', 'Görselleri seçip "Kaydet"e basın', 'Ürün yönetici onayından sonra yayına girer'],
			],
			'excel-yukleme' => [
				'title' => 'Excel ile toplu ürün yükleme',
				'seconds' => 0,
				'contexts' => ['products', 'import'],
				'summary' => 'Excel şablonunu indirin, kategori ve marka adlarını yazın, dosyayı yükleyin; kategoriler otomatik eşlenir.',
				'steps' => ['Ürün Yönetimi → "Excel / CSV yükle"', '"Excel şablonunu indir"', 'Her satıra ürün adı, kategori (ör. Kadın > Giyim > Tişört), marka, fiyat ve stok yazın', 'Dosyayı seçip "Yükle ve işle"', 'Sonuç: yeni / güncellenen / hatalı satır sayısı'],
			],
			'xml-yukleme' => [
				'title' => 'XML ile ürün yükleme',
				'seconds' => 0,
				'contexts' => ['xml', 'products', 'suppliers', 'dashboard'],
				'summary' => 'Tedarikçinizin XML adresini ekleyin, kâr ve KDV kuralını seçin, alanları eşleştirip içe aktarın.',
				'steps' => ['XML Merkezi → "Yeni XML"', 'Bağlantı adı ve XML adresini yazın', 'Kâr oranı ve "KDV hariç geliyor: KDV ekle" seçimini yapın', '"Oluştur ve test et"', 'Alan eşleştirmeyi kontrol edip kaydedin', 'Önizleme ve içe aktarma → "İçe aktar"'],
			],
			'kategori-eslestirme' => [
				'title' => 'Kategori eşleştirme nasıl yapılır?',
				'seconds' => 0,
				'contexts' => ['xml', 'products', 'import'],
				'summary' => 'Kategoriler otomatik eşlenir; emin olunamayanlar önerisiyle listelenir, kontrol edip tek tıkla kaydedersiniz.',
				'steps' => ['XML Merkezi → bağlantınız → "Marka/Kategori"', 'Eşleşmeyen tedarikçi kategorileri önerisiyle gelir', 'Öneriyi kontrol edin, gerekirse değiştirin', '"Eşleştirmeleri kaydet"', '"Hatalıları yeniden dene" ile ürünleri ekleyin'],
			],
			'gorsel' => [
				'title' => 'Görseller nasıl eklenir ve güncellenir?',
				'seconds' => 0,
				'contexts' => ['products', 'xml'],
				'summary' => 'Ürünü düzenleyin, yeni görsel yükleyin, kapak görselini seçin, istemediğinizi silin.',
				'steps' => ['Ürün Yönetimi → ürünün "Düzenle" düğmesi', 'Görseller alanından yeni dosyaları seçin (JPG/PNG/WEBP, en çok 10)', '"Kaydet"', 'Mevcut görsellerde "Kapak" ile ana görseli seçin, "Sil" ile kaldırın', 'XML ürünlerinde görsel modu: XML Merkezi → Genel → Görsel modu'],
			],
			'fiyat-stok' => [
				'title' => 'Fiyat ve stok nasıl güncellenir?',
				'seconds' => 0,
				'contexts' => ['stock', 'pricing', 'products', 'xml', 'dashboard'],
				'summary' => 'Stok ve Depo\'dan stokları, Fiyat Yönetimi\'nden fiyatları tek tek ya da toplu güncelleyin.',
				'steps' => ['Stok ve Depo: yeni stok adedini yazıp "Kaydet"', 'Fiyat Yönetimi: yeni fiyatı yazın', 'Toplu: ürünleri seçip yüzde artış/indirim uygulayın', 'XML ürünleri tedarikçi akışıyla otomatik güncellenir'],
			],
			'trendyol' => [
				'title' => 'Trendyol ile ürün eşleştirme',
				'seconds' => 0,
				'contexts' => ['marketplaces', 'dashboard', 'products'],
				'summary' => 'Trendyol API bilgilerinizi girin, kategorilerinizi Trendyol kategorileriyle eşleyin, ürünlerinizi sıraya alın.',
				'steps' => ['Pazaryerleri → "Trendyol" hesabı', 'Trendyol satıcı panelinden aldığınız API Key, API Secret ve Satıcı ID\'yi girip kaydedin', '"Kategori eşleme": sizin kategoriniz ↔ Trendyol kategori numarası', '"Ürünler": göndermek istediğiniz ürünleri seçin', 'Bağlantı yönetici tarafından etkinleştirildiğinde ürünler Trendyol\'a aktarılır'],
			],
		];
	}

	/** Etkin mi: Rafi modülü etkin ve ayar kapalı değil. */
	public static function enabled(): bool
	{
		if (class_exists('Module') && method_exists('Module', 'isEnabled') && !Module::isEnabled('rafimda-rafi')) {
			return false;
		}

		return !class_exists('Settings') || (string) Settings::get(self::SETTING) !== '0';
	}

	public static function assetDir(): string
	{
		return dirname(__DIR__) . '/assets/';
	}

	/** Yalnızca dosyası mevcut videolar; süre meta dosyasından okunur. @return array<string, array<string,mixed>> */
	public static function available(): array
	{
		$meta = [];
		$mf = self::assetDir() . 'video/videos.json';

		if (is_file($mf)) {
			$meta = json_decode((string) file_get_contents($mf), true);
			$meta = is_array($meta) ? $meta : [];
		}

		$out = [];

		foreach (self::catalog() as $key => $v) {
			if (is_file(self::assetDir() . 'video/' . $key . '.mp4')) {
				$v['seconds'] = (int) ($meta[$key]['seconds'] ?? $v['seconds']);
				$v['v'] = (string) ($meta[$key]['v'] ?? '1');
				$out[$key] = $v;
			}
		}

		return $out;
	}

	/** @return array<string, array<string,mixed>> bağlama göre sıralı (önce bu sayfaya ait olanlar) */
	public static function forContext(string $context): array
	{
		$all = self::available();
		$mine = array_filter($all, static fn(array $v): bool => in_array($context, $v['contexts'], true));
		// bağlamdaki ilk sıradaki videolar (o sayfanın asıl konusu) öne
		uasort($mine, static fn(array $a, array $b): int => array_search($context, $a['contexts'], true) <=> array_search($context, $b['contexts'], true));

		return $mine;
	}

	private static function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
	}

	private static function duration(int $s): string
	{
		return $s > 0 ? sprintf('%d:%02d', intdiv($s, 60), $s % 60) : '';
	}

	/**
	 * Sayfa altı yardım bloğu + yüzen Rafi düğmesi + video penceresi (kendi CSS/JS'i ile; şablon değiştirmeden eklenir).
	 * @param string $context sayfa bağlamı
	 * @param string $baseUrl site kökü (ör. https://rafimda.com.tr/)
	 * @param string $supportUrl "Destek talebi aç" bağlantısı (boşsa gösterilmez)
	 */
	public static function render(string $context, string $baseUrl, string $supportUrl = ''): string
	{
		if (!self::enabled()) {
			return '';
		}

		$all = self::available();

		if ($all === []) {
			return '';
		}

		$mine = self::forContext($context);
		$top = array_slice($mine !== [] ? $mine : $all, 0, 3, true);
		$asset = rtrim($baseUrl, '/') . '/modules/rafimda-rafi/assets/';
		$ver = defined('RafimdaRafiModule::VERSION') ? RafimdaRafiModule::VERSION : '1';
		$card = static function (string $key, array $v) use ($asset): string {
			$q = '?v=' . rawurlencode((string) $v['v']);

			return '<button type="button" class="rafih-card" data-rafih-video="' . self::e($key) . '" data-src="' . self::e($asset . 'video/' . $key . '.mp4' . $q) . '" data-poster="' . self::e($asset . 'video/' . $key . '.jpg' . $q) . '" data-title="' . self::e($v['title']) . '" data-steps="' . self::e((string) json_encode($v['steps'], JSON_UNESCAPED_UNICODE)) . '">'
				. '<span class="rafih-thumb"><img src="' . self::e($asset . 'video/' . $key . '.jpg' . $q) . '" alt="" loading="lazy"><span class="rafih-play" aria-hidden="true"></span>'
				. ($v['seconds'] > 0 ? '<span class="rafih-dur">' . self::duration((int) $v['seconds']) . '</span>' : '') . '</span>'
				. '<span class="rafih-ct"><strong>' . self::e($v['title']) . '</strong><small>' . self::e($v['summary']) . '</small></span></button>';
		};

		$h = '<link rel="stylesheet" href="' . self::e($asset . 'css/help.css?v=' . $ver) . '">';
		$h .= '<section class="rafih" id="rafi-yardim" aria-label="Rafi yardım videoları" data-context="' . self::e($context) . '">';
		$h .= '<div class="rafih-head"><img class="rafih-avatar" src="' . self::e($asset . 'img/rafi.svg') . '" alt="Rafi" width="56" height="56">';
		$h .= '<div><h3>Sorun mu yaşıyorsunuz? Hemen yardımcı olayım!</h3><p>Ben Rafi. Bu sayfayla ilgili kısa videoları sizin için seçtim; adım adım gösteriyorum.</p></div></div>';
		$h .= '<div class="rafih-grid">';

		foreach ($top as $k => $v) {
			$h .= $card($k, $v);
		}

		$h .= '</div>';

		if (count($all) > count($top)) {
			$h .= '<details class="rafih-more"><summary>Tüm yardım videoları (' . count($all) . ')</summary><div class="rafih-grid">';

			foreach ($all as $k => $v) {
				if (!isset($top[$k])) {
					$h .= $card($k, $v);
				}
			}

			$h .= '</div></details>';
		}

		if ($supportUrl !== '') {
			$h .= '<p class="rafih-support">Videolar yetmedi mi? <a href="' . self::e($supportUrl) . '">Destek talebi açın</a>, ekibimiz yardımcı olsun.</p>';
		}

		$h .= '</section>';
		// yüzen Rafi düğmesi ve konuşma balonu
		$h .= '<div class="rafih-fab" hidden><div class="rafih-bubble" role="status"><button type="button" class="rafih-x" aria-label="Kapat">×</button>Yardımcı olmamı ister misin? <a href="#rafi-yardim">Kısa videolar burada</a></div>'
			. '<button type="button" class="rafih-fab-btn" aria-label="Rafi yardım"><img src="' . self::e($asset . 'img/rafi.svg') . '" alt="" width="44" height="44"></button></div>';
		// video penceresi
		$h .= '<div class="rafih-modal" hidden role="dialog" aria-modal="true" aria-labelledby="rafih-mt"><div class="rafih-box"><button type="button" class="rafih-close" aria-label="Kapat">×</button>'
			. '<h3 id="rafih-mt"></h3><video controls playsinline preload="none"></video><ol class="rafih-steps"></ol>'
			. ($supportUrl !== '' ? '<p class="rafih-support">Hâlâ takıldınız mı? <a href="' . self::e($supportUrl) . '">Destek talebi açın</a>.</p>' : '')
			. '</div></div>';
		$h .= '<script src="' . self::e($asset . 'js/help.js?v=' . $ver) . '" defer></script>';

		return $h;
	}
}
