<?php

/**
 * Otomatik kategori eşleştirme motoru (Rafımda). Saf PHP: veritabanı veya ağ kullanmaz.
 *
 * Kaynak metin (tedarikçi/Excel kategorisi, ürün adı) ile hedef kategori listesi (site ağacı veya pazaryeri ağacı)
 * arasında Türkçe duyarlı belirteç eşleşmesiyle puan hesaplar:
 *  - Türkçe karakter katlama, küçük harf, noktalama temizliği, basit ek kırpma (-lar/-ler, -ları/-leri, -sı/-si …)
 *  - eş anlamlılar (tshirt → tisort, sneaker → spor ayakkabi …) ve ürün türü → kategori anahtar kelimeleri
 *  - son kategori adı (yaprak) üst kategorilerden daha ağır sayılır
 *  - cinsiyet/yaş çatışması (Kadın ↔ Erkek, Çocuk/Bebek) cezalandırılır
 *
 * Güven seviyesi: high (doğrudan uygula), medium (uygula + kontrol listesine al), low (öneri; varsayılan kullanılır).
 */
class CategoryMatcher
{
	public const HIGH = 0.72;
	public const MEDIUM = 0.42;
	public const MARGIN = 0.08;

	private const STOP = ['ve', 'ile', 'icin', 'the', 'and', 'for', 'of', 'set', 'adet', 'li', 'lu', 'lik', 'luk', 'cm', 'mm', 'ml', 'gr', 'kg', 'lt', 'x', 'yeni', 'model', 'ozel', 'kampanya', 'indirim', 'urun', 'urunler', 'diger', 'genel', 'cesit', 'cesitleri', 'tum', 'kategori', 'kategoriler', 'anasayfa', 'ana', 'sayfa', 'home', 'root', 'products', 'magaza'];

	/** Eş anlamlı → kanonik belirteç (katlanmış ve kırpılmış biçimde). */
	private const SYN = [
		'tshirt' => 'tisort', 't-shirt' => 'tisort', 'tee' => 'tisort', 'sweat' => 'sweatshirt', 'hoodie' => 'sweatshirt', 'kapsonlu' => 'sweatshirt',
		'sneaker' => 'spor ayakkabi', 'sneakers' => 'spor ayakkabi', 'trainers' => 'spor ayakkabi', 'shoe' => 'ayakkabi', 'shoes' => 'ayakkabi',
		'bag' => 'canta', 'bags' => 'canta', 'dress' => 'elbise', 'jean' => 'kot', 'jeans' => 'kot', 'denim' => 'kot', 'pants' => 'pantolon', 'trousers' => 'pantolon',
		'shirt' => 'gomlek', 'jacket' => 'ceket', 'coat' => 'mont', 'skirt' => 'etek', 'shorts' => 'sort', 'socks' => 'corap', 'watch' => 'saat',
		'women' => 'kadin', 'woman' => 'kadin', 'womens' => 'kadin', 'bayan' => 'kadin', 'kiz' => 'kadin', 'men' => 'erkek', 'man' => 'erkek', 'mens' => 'erkek', 'bay' => 'erkek',
		'kids' => 'cocuk', 'kid' => 'cocuk', 'child' => 'cocuk', 'children' => 'cocuk', 'baby' => 'bebek', 'unisex' => 'unisex',
		'phone' => 'telefon', 'smartphone' => 'telefon', 'gsm' => 'telefon', 'notebook' => 'laptop', 'dizustu' => 'laptop', 'headphone' => 'kulaklik', 'earphone' => 'kulaklik',
		'tv' => 'televizyon', 'parfum' => 'parfum', 'perfume' => 'parfum', 'fragrance' => 'parfum', 'cosmetic' => 'kozmetik', 'makeup' => 'makyaj', 'skincare' => 'cilt',
		'toy' => 'oyuncak', 'toys' => 'oyuncak', 'book' => 'kitap', 'books' => 'kitap', 'kitchen' => 'mutfak', 'home' => 'ev', 'garden' => 'bahce', 'pet' => 'evcil',
		'dog' => 'kopek', 'cat' => 'kedi', 'car' => 'oto', 'auto' => 'oto', 'otomobil' => 'oto', 'arac' => 'oto', 'vitamini' => 'vitamin', 'supplement' => 'takviye',
	];

	/**
	 * Ürün türü → ait olduğu kategori adı belirteci. Ürün adı "Kadın Elbise" olsa bile site ağacında "Elbise" yoksa
	 * "Giyim" kategorisine yönlendirir. Anahtarlar ve değerler katlanmış/kırpılmış biçimdedir.
	 *
	 * @var array<string,list<string>>
	 */
	private const KIND = [
		'giyim' => ['elbise', 'bluz', 'gomlek', 'tisort', 'pantolon', 'etek', 'mont', 'ceket', 'kazak', 'hirka', 'sort', 'tayt', 'sweatshirt', 'yelek', 'tunik', 'kot', 'esofman', 'tulum', 'kaban', 'trenckot', 'takim', 'polo', 'atlet', 'bermuda', 'jile', 'kimono', 'pardesu', 'yagmurluk', 'body', 'crop', 'blazer', 'palazzo', 'jogger', 'salvar'],
		'ayakkabi' => ['ayakkabi', 'bot', 'cizme', 'terlik', 'sandalet', 'babet', 'topuklu', 'loafer', 'mokasen', 'patik', 'espadril', 'stiletto', 'krampon', 'converse', 'spor ayakkabi', 'sneaker'],
		'canta' => ['canta', 'sirt cantasi', 'el cantasi', 'omuz cantasi', 'clutch', 'portfoy', 'bel cantasi', 'laptop cantasi', 'valiz', 'bavul'],
		'ic giyim' => ['sutyen', 'kulot', 'boxer', 'pijama', 'gecelik', 'corap', 'atlet', 'fanila', 'korse', 'jartiyer', 'sabahlik', 'slip', 'string', 'ic camasir'],
		'aksesuar' => ['sapka', 'bere', 'atki', 'sal', 'eldiven', 'toka', 'kemer', 'cuzdan', 'gozluk', 'kolye', 'kupe', 'bileklik', 'yuzuk', 'bros', 'fular', 'kravat', 'papyon'],
		'tesettur' => ['ferace', 'tesettur', 'esarp', 'bone', 'ferace', 'pardesu tesettur', 'namaz elbisesi'],
		'kol saati' => ['saat', 'kol saati'],
		'akilli saat' => ['akilli saat', 'smartwatch', 'akilli bileklik'],
		'taki mucevher' => ['kolye', 'kupe', 'bileklik', 'yuzuk', 'bros', 'halhal', 'altin', 'gumus', 'pirlanta', 'mucevher', 'taki'],
		'cep telefonu' => ['telefon', 'iphone', 'samsung galaxy', 'xiaomi', 'redmi', 'cep telefonu'],
		'telefon aksesuarlari' => ['kilif', 'ekran koruyucu', 'sarj', 'kablo', 'powerbank', 'tutucu', 'adaptor', 'sarj aleti'],
		'bilgisayar tablet' => ['laptop', 'bilgisayar', 'tablet', 'ipad', 'macbook', 'monitor', 'klavye', 'mouse', 'yazici'],
		'bilgisayar bilesenleri' => ['ekran karti', 'islemci', 'anakart', 'ram', 'ssd', 'harddisk', 'kasa', 'guc kaynagi'],
		'tv ses sistemleri' => ['televizyon', 'hoparlor', 'soundbar', 'ses sistemi', 'projeksiyon'],
		'kulaklik' => ['kulaklik', 'airpods', 'bluetooth kulaklik'],
		'beyaz esya' => ['buzdolabi', 'camasir makinesi', 'bulasik makinesi', 'firin', 'kurutma makinesi', 'derin dondurucu'],
		'kucuk ev aletleri' => ['supurge', 'blender', 'mikser', 'tost makinesi', 'kahve makinesi', 'cay makinesi', 'utu', 'airfryer', 'fritoz', 'kettle', 'su isitici', 'robot supurge', 'mutfak robotu'],
		'kisisel bakim aletleri' => ['tiras makinesi', 'sac kurutma', 'duzlestirici', 'epilasyon', 'masa', 'sac masasi', 'tartı', 'baskul'],
		'foto kamera' => ['kamera', 'fotograf makinesi', 'lens', 'objektif', 'tripod', 'drone'],
		'oyun konsol' => ['playstation', 'xbox', 'nintendo', 'konsol', 'oyun kolu', 'gamepad'],
		'ev tekstili' => ['nevresim', 'yorgan', 'yastik', 'battaniye', 'havlu', 'perde', 'hali', 'kilim', 'carsaf', 'pike', 'masa ortusu', 'kirlent', 'paspas', 'bornoz'],
		'mutfak gerecleri' => ['tencere', 'tava', 'bicak', 'saklama kabi', 'rende', 'kesme tahtasi', 'caydanlik', 'duduklu', 'cezve', 'termos'],
		'sofra servis' => ['tabak', 'bardak', 'catal', 'kasik', 'fincan', 'kupa', 'servis', 'yemek takimi', 'surahi'],
		'dekorasyon' => ['tablo', 'vazo', 'mum', 'ayna', 'cerceve', 'biblo', 'duvar saati', 'yapay cicek', 'dekor'],
		'aydinlatma' => ['avize', 'lamba', 'abajur', 'aplik', 'led', 'ampul', 'lambader', 'spot'],
		'banyo' => ['dus', 'banyo', 'sabunluk', 'klozet', 'batarya', 'havluluk'],
		'mobilya' => ['koltuk', 'masa', 'sandalye', 'dolap', 'kitaplik', 'yatak', 'baza', 'sehpa', 'komodin', 'gardirop', 'tv unitesi', 'puf'],
		'gida' => ['kahve', 'cay', 'cikolata', 'biskuvi', 'makarna', 'pirinc', 'bakliyat', 'yag', 'bal', 'recel', 'peynir', 'zeytin', 'kuruyemis', 'baharat', 'atistirmalik'],
		'icecek' => ['su', 'maden suyu', 'meyve suyu', 'gazli icecek', 'enerji icecegi'],
		'temizlik' => ['deterjan', 'camasir suyu', 'yumusatici', 'bulasik', 'temizleyici', 'sabun', 'sivi sabun'],
		'kagit urunleri' => ['tuvalet kagidi', 'kagit havlu', 'pecete', 'islak mendil', 'mendil'],
		'kisisel hijyen' => ['ped', 'tampon', 'bez', 'tiras', 'deodorant'],
		'evcil hayvan' => ['kedi', 'kopek', 'kus', 'balik', 'mama', 'kum', 'tasma', 'evcil'],
		'makyaj' => ['ruj', 'maskara', 'fondoten', 'allik', 'far', 'eyeliner', 'kapatici', 'pudra', 'oje', 'makyaj'],
		'cilt bakimi' => ['krem', 'serum', 'tonik', 'maske', 'nemlendirici', 'cilt', 'yuz temizleme', 'peeling'],
		'sac bakim' => ['sampuan', 'sac kremi', 'sac boyasi', 'sac maskesi', 'sac'],
		'parfum deodorant' => ['parfum', 'edp', 'edt', 'deodorant', 'kolonya', 'body mist'],
		'agiz ve dis bakimi' => ['dis macunu', 'dis fircasi', 'agiz', 'dis'],
		'gunes urunleri' => ['gunes kremi', 'gunes', 'bronzlastirici', 'spf'],
		'oyuncak' => ['oyuncak', 'lego', 'puzzle', 'yapboz', 'pelus', 'bebek oyuncak', 'oyun hamuru', 'kutu oyunu', 'rc araba'],
		'bebek bakim' => ['bebek bezi', 'biberon', 'emzik', 'bebek sampuan', 'islak mendil bebek'],
		'bebek arabasi oto koltugu' => ['bebek arabasi', 'puset', 'oto koltugu', 'ana kucagi', 'kanguru'],
		'beslenme emzirme' => ['mama sandalyesi', 'gogus pompasi', 'biberon', 'emzirme'],
		'fitness kondisyon' => ['dambil', 'halter', 'kosu bandi', 'pilates', 'yoga', 'mat', 'kondisyon bisikleti', 'agirlik'],
		'kamp outdoor' => ['cadir', 'uyku tulumu', 'kamp', 'outdoor', 'matara', 'kamp sandalyesi', 'fener'],
		'bisiklet' => ['bisiklet', 'kask', 'scooter', 'patenli'],
		'takim sporlari' => ['top', 'futbol', 'basketbol', 'voleybol', 'forma', 'file'],
		'vitamin' => ['vitamin', 'multivitamin', 'c vitamini', 'd vitamini'],
		'mineral' => ['magnezyum', 'cinko', 'demir', 'kalsiyum', 'mineral'],
		'sporcu besinleri' => ['protein', 'whey', 'kreatin', 'bcaa', 'gainer', 'amino'],
		'bitkisel takviye' => ['omega', 'balik yagi', 'kolajen', 'probiyotik', 'bitkisel'],
		'kitap' => ['kitap', 'roman', 'hikaye', 'ansiklopedi', 'dergi'],
		'kirtasiye' => ['kalem', 'defter', 'silgi', 'kalemtras', 'ajanda', 'boya kalemi', 'cetvel'],
		'ofis malzemeleri' => ['zimba', 'dosya', 'klasor', 'etiket', 'a4 kagit', 'fotokopi'],
		'muzik enstrumanlari' => ['gitar', 'piyano', 'keman', 'baglama', 'ukulele', 'davul', 'org', 'flut'],
		'oto aksesuar' => ['oto paspas', 'koltuk kilifi', 'arac kamerasi', 'oto kokusu', 'direksiyon kilifi', 'arac'],
		'oto bakim' => ['motor yagi', 'antifriz', 'oto sampuan', 'cila', 'silecek'],
		'motosiklet ekipmanlari' => ['motosiklet', 'kask motosiklet', 'motor montu'],
		'lastik jant' => ['lastik', 'jant'],
		'elektrikli el aletleri' => ['matkap', 'vidalama', 'taslama', 'testere', 'kaynak', 'hilti', 'zimpara'],
		'hirdavat' => ['vida', 'civi', 'anahtar takimi', 'pense', 'tornavida', 'cekic', 'metre', 'kilit'],
		'bahce' => ['bahce', 'cim', 'sulama', 'hortum', 'saksi', 'tohum', 'gubre', 'mangal'],
		'tesisat' => ['musluk', 'batarya', 'boru', 'vana', 'sifon'],
		'boya' => ['boya', 'firca', 'rulo', 'astar', 'vernik'],
	];

	/** @return array<string,list<string>> belirteçleştirilmiş tür anahtarı => belirteçleştirilmiş anahtar kelimeler */
	private static function kinds(): array
	{
		static $k = null;

		if ($k !== null) {
			return $k;
		}

		$k = [];

		foreach (self::KIND as $kind => $words) {
			$key = implode(' ', array_values(array_filter(self::tokens($kind), static fn(string $x): bool => strpos($x, ' ') === false)));
			$list = [];

			foreach ($words as $w) {
				$single = array_values(array_filter(self::tokens($w), static fn(string $x): bool => strpos($x, ' ') === false));

				if ($single !== []) {
					$list[] = implode(' ', $single);
				}
			}

			$k[$key] = array_values(array_unique(array_merge($k[$key] ?? [], $list)));
		}

		return $k;
	}

	private const GENDER = ['kadin' => 'f', 'erkek' => 'm', 'cocuk' => 'k', 'bebek' => 'k', 'kiz cocuk' => 'k', 'erkek cocuk' => 'k'];

	public static function fold(string $s): string
	{
		$s = str_replace(['İ', 'I'], ['i', 'ı'], $s);
		$s = mb_strtolower($s, 'UTF-8');
		$s = strtr($s, ['ı' => 'i', 'i̇' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u', '&' => ' ', '+' => ' ']);
		$s = preg_replace('/[^a-z0-9\s>\/|,;-]+/', ' ', $s) ?? '';

		return trim(preg_replace('/\s+/', ' ', $s) ?? '');
	}

	/** Basit Türkçe ek kırpma (çoğul ve iyelik). Kök en az 3 harf kalır. */
	public static function stem(string $w): string
	{
		foreach (['larimiz', 'lerimiz', 'lari', 'leri', 'lar', 'ler', 'sini', 'sunu', 'si', 'su', 'lu', 'li'] as $suf) {
			$n = strlen($suf);

			if (strlen($w) - $n >= 4 && substr($w, -$n) === $suf) {
				return substr($w, 0, -$n);
			}
		}

		return $w;
	}

	/**
	 * Metni belirteçlere ayırır; iki kelimelik eş anlamlı/tür ifadelerini de (ör. "spor ayakkabi") belirteç olarak ekler.
	 *
	 * @return list<string>
	 */
	public static function tokens(string $text): array
	{
		$t = self::fold($text);
		$t = str_replace(['t-shirt'], ['tisort'], $t);
		$words = preg_split('/[\s>\/|,;-]+/', $t) ?: [];
		$out = [];

		foreach ($words as $w) {
			if ($w === '' || in_array($w, self::STOP, true) || ctype_digit($w)) {
				continue;
			}

			$w = self::SYN[$w] ?? $w;

			foreach (explode(' ', $w) as $p) {
				$p = self::stem($p);

				if (strlen($p) >= 2 && !in_array($p, self::STOP, true)) {
					$out[] = $p;
				}
			}
		}

		$n = count($out);

		for ($i = 0; $i + 1 < $n; $i++) {
			$out[] = $out[$i] . ' ' . $out[$i + 1];
		}

		return array_values(array_unique($out));
	}

	/** İki belirteç eşleşir mi? Tam eşitlik ya da (≥5 harf) önek eşleşmesi: "ayakkabi" ~ "ayakkabilar". */
	private static function same(string $a, string $b): bool
	{
		if ($a === $b) {
			return true;
		}

		$la = strlen($a);
		$lb = strlen($b);

		return min($la, $lb) >= 5 && strpos($a, ' ') === false && strpos($b, ' ') === false && (strpos($a, $b) === 0 || strpos($b, $a) === 0);
	}

	/** @param list<string> $have */
	private static function has(array $have, string $tok): bool
	{
		foreach ($have as $h) {
			if (self::same($h, $tok)) {
				return true;
			}
		}

		return false;
	}

	/** @param list<string> $toks */
	private static function gender(array $toks): string
	{
		$g = '';

		foreach ($toks as $t) {
			if (isset(self::GENDER[$t])) {
				$v = self::GENDER[$t];

				if ($v === 'k') {
					return 'k';
				}

				$g = $g === '' || $g === $v ? $v : 'x';
			}
		}

		return $g;
	}

	/**
	 * Hedef listesini eşleştirmeye hazırlar.
	 *
	 * @param list<array{id:int|string, path:list<string>|string}> $targets path: ["Kadın","Giyim"] ya da "Kadın > Giyim"
	 * @return list<array<string,mixed>>
	 */
	public static function prepare(array $targets): array
	{
		$out = [];

		foreach ($targets as $t) {
			$path = is_array($t['path']) ? $t['path'] : (preg_split('/\s*(?:>|\/|›|»)\s*/u', (string) $t['path']) ?: []);
			$path = array_values(array_filter(array_map('strval', $path), static fn(string $s): bool => trim($s) !== ''));

			if ($path === []) {
				continue;
			}

			$leaf = (string) end($path);
			$leafTok = array_values(array_filter(self::tokens($leaf), static fn(string $x): bool => strpos($x, ' ') === false));
			$parentTok = [];

			foreach (array_slice($path, 0, -1) as $seg) {
				foreach (self::tokens($seg) as $x) {
					if (strpos($x, ' ') === false) {
						$parentTok[] = $x;
					}
				}
			}

			$kw = [];
			$lk = implode(' ', $leafTok);

			foreach (self::kinds() as $kindKey => $words) {
				if ($lk === $kindKey) {
					$kw = array_merge($kw, $words);
				}
			}

			$out[] = [
				'id' => $t['id'],
				'path' => implode(' > ', $path),
				'leaf' => $leafTok,
				'parent' => array_values(array_unique($parentTok)),
				'kw' => array_values(array_unique($kw)),
				'gender' => self::gender(array_merge($leafTok, $parentTok)),
				'depth' => count($path),
				'selectable' => !isset($t['selectable']) || $t['selectable'],
			];
		}

		$paths = [];

		foreach ($out as $o) {
			$paths[$o['path']] = true;
		}

		foreach ($out as &$o) {
			$o['has_children'] = false;
			$prefix = $o['path'] . ' > ';

			foreach ($paths as $p => $_) {
				if (strpos($p, $prefix) === 0) {
					$o['has_children'] = true;
					break;
				}
			}
		}

		unset($o);

		return $out;
	}

	/**
	 * @param list<string> $src kaynak belirteçleri
	 * @param array<string,mixed> $c hazırlanmış hedef
	 */
	private static function score(array $src, array $c, string $srcGender): float
	{
		if ($c['leaf'] === []) {
			return 0.0;
		}

		$leafHit = 0;

		foreach ($c['leaf'] as $t) {
			$leafHit += self::has($src, $t) ? 1 : 0;
		}

		$leafFrac = $leafHit / count($c['leaf']);

		// Yalnızca "Kadın", "Erkek", "Çocuk" gibi kitle adından oluşan kategori: kitle kelimesi tek başına ürün türü belirtmez.
		if (array_diff($c['leaf'], array_keys(self::GENDER)) === []) {
			$leafFrac *= 0.45;
		}
		$kwHit = 0;
		$kwPos = 0.0;
		$singles = array_values(array_filter($src, static fn(string $x): bool => strpos($x, ' ') === false));
		$ns = max(1, count($singles));

		foreach ($c['kw'] as $k) {
			if (strpos($k, ' ') !== false ? in_array($k, $src, true) : self::has($src, $k)) {
				$kwHit++;
				$last = explode(' ', $k);
				$last = (string) end($last);

				// Türkçe ürün adlarında ürün türü genelde sondadır ("iPhone 15 Silikon Kılıf" → kılıf).
				foreach ($singles as $i => $w) {
					if (self::same($w, $last)) {
						$kwPos = max($kwPos, ($i + 1) / $ns);
					}
				}
			}
		}

		$parentFrac = 0.0;

		if ($c['parent'] !== []) {
			$ph = 0;

			foreach ($c['parent'] as $t) {
				$ph += self::has($src, $t) ? 1 : 0;
			}

			$parentFrac = $ph / count($c['parent']);
		}

		$base = max($leafFrac, $kwHit > 0 ? min(0.97, 0.8 + 0.03 * min(3, $kwHit) + 0.08 * $kwPos) : 0.0);

		if ($base <= 0) {
			return 0.0;
		}

		$s = $c['parent'] === [] ? $base * 0.9 + 0.1 * $base : $base * 0.75 + 0.25 * $parentFrac;

		// cinsiyet/yaş: kaynak belirtiyorsa ve hedef farklıysa ağır ceza; hedef belirtiyor, kaynak belirtmiyorsa hafif ceza
		if ($srcGender !== '' && $srcGender !== 'x' && $c['gender'] !== '' && $c['gender'] !== 'x' && $c['gender'] !== $srcGender) {
			$s *= 0.35;
		} elseif ($srcGender === '' && $c['gender'] !== '') {
			$s *= 0.92;
		}

		// Alt kategorisi olan (üst) kategori: ürünler genelde en alt kategoriye girer.
		if (!empty($c['has_children'])) {
			$s *= 0.6;
		}

		// tam yol eşleşmesi (kaynak = hedef yol) küçük bonus
		if ($leafFrac === 1.0 && ($c['parent'] === [] || $parentFrac === 1.0)) {
			$s = min(1.0, $s + 0.08);
		}

		return round($s, 4);
	}

	/**
	 * En iyi hedefi bulur.
	 *
	 * @param string $sourceCategory tedarikçi/Excel kategori metni (boş olabilir)
	 * @param string $productName ürün adı (yardımcı ipucu)
	 * @param list<array<string,mixed>> $prepared prepare() çıktısı
	 * @return array{id:int|string|null, path:string, score:float, confidence:string, alternatives:list<array{id:int|string,path:string,score:float}>}
	 */
	public static function match(string $sourceCategory, string $productName, array $prepared): array
	{
		$catTok = self::tokens($sourceCategory);
		$nameTok = self::tokens($productName);
		$srcGender = self::gender(array_merge($catTok, $nameTok));
		$rows = [];

		foreach ($prepared as $c) {
			if (!$c['selectable']) {
				continue;
			}

			$sc = $catTok !== [] ? self::score($catTok, $c, $srcGender) : 0.0;
			$sn = $nameTok !== [] ? self::score($nameTok, $c, $srcGender) : 0.0;
			$both = $catTok !== [] && $nameTok !== [] ? self::score(array_values(array_unique(array_merge($catTok, $nameTok))), $c, $srcGender) : 0.0;
			$s = max($sc, $sn * 0.9, $both * 0.97);

			if ($s > 0) {
				// derin (daha özel) kategoriyi eşit puanda tercih et
				$rows[] = ['id' => $c['id'], 'path' => $c['path'], 'score' => min(1.0, $s + 0.005 * min(4, $c['depth']))];
			}
		}

		usort($rows, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['path'], $b['path']));
		$best = $rows[0] ?? null;

		if (!$best) {
			return ['id' => null, 'path' => '', 'score' => 0.0, 'confidence' => 'none', 'alternatives' => []];
		}

		$second = $rows[1]['score'] ?? 0.0;
		$conf = 'low';

		if (($best['score'] >= self::HIGH && $best['score'] - $second >= self::MARGIN) || ($best['score'] >= 0.55 && $best['score'] - $second >= 0.2)) {
			$conf = 'high';
		} elseif ($best['score'] >= self::MEDIUM) {
			$conf = 'medium';
		}

		return ['id' => $best['id'], 'path' => $best['path'], 'score' => round($best['score'], 3), 'confidence' => $conf, 'alternatives' => array_slice($rows, 1, 4)];
	}
}
