<?php

/**
 * Otomatik kategori eşleştirici (XML, Excel/CSV, pazaryeri). Tedarikçinin kategori metnini ya da yolunu
 * ("Elektronik > Telefon > Kılıf", "Kadın Tişört", "Cep Telefonu Kılıfları") site kategorilerine eşler.
 *
 * Sıra: 1) yönetici/satıcı tarafından kaydedilmiş eşleme (rafimda_category_map, origin=manual)
 *       2) daha önce otomatik bulunmuş eşleme (origin=auto; kategori hâlâ aktifse)
 *       3) tam ad eşleşmesi  4) benzerlik puanı (Türkçe normalleştirme, eş anlamlılar, ek atma,
 *          yol bağlamı, cinsiyet/yaş çakışma cezası). Eşik altındaki sonuç KULLANILMAZ (0 döner) ve
 *          yalnızca öneri olarak gösterilir; yanlış kategoriye ürün atmak yerine varsayılan kategori/hata tercih edilir.
 */
final class RcatMatcher
{
	public const AUTO_THRESHOLD = 0.62;
	public const TABLE = 'rafimda_category_map';

	/** @var array<int, array<string,mixed>>|null */
	private static ?array $cands = null;
	/** @var array<string, array<string,mixed>> */
	private static array $memo = [];
	private static ?bool $tableOk = null;

	private const STOP = ['ve', 'ile', 'icin', 'diger', 'cesitleri', 'cesit', 'modelleri', 'model', 'urunleri', 'urun', 'genel', 'tum', 'her', 'sey', 'adet', 'yeni', 'set', 'the', 'and', 'for', 'of'];

	/** Normalleştirilmiş metin üzerinde ifade → kanonik ifade (iki taraf da aynı dönüşümden geçer). */
	private const PHRASES = [
		't shirt' => 'tisort', 'tshirt' => 'tisort', 'tisortu' => 'tisort',
		'cep telefonu' => 'cep telefon', 'gsm' => 'cep telefon', 'akilli telefon' => 'cep telefon', 'smartphone' => 'cep telefon', 'mobil telefon' => 'cep telefon',
		'laptop' => 'dizustu bilgisayar', 'notebook' => 'dizustu bilgisayar', 'diz ustu' => 'dizustu',
		'smart watch' => 'akilli saat', 'smartwatch' => 'akilli saat', 'smart band' => 'akilli bileklik',
		'tv' => 'televizyon', 'led tv' => 'televizyon', 'smart tv' => 'televizyon',
		'headphone' => 'kulaklik', 'earphone' => 'kulaklik', 'kulakici' => 'kulaklik', 'kulak ustu' => 'kulaklik', 'airpods' => 'bluetooth kulaklik', 'tws' => 'bluetooth kulaklik',
		'speaker' => 'hoparlor', 'power bank' => 'powerbank', 'tasinabilir sarj' => 'powerbank', 'harici batarya' => 'powerbank',
		'sarj aleti' => 'sarj cihazi', 'sarj adaptoru' => 'sarj cihazi', 'adaptor' => 'sarj cihazi', 'charger' => 'sarj cihazi',
		'kapak' => 'kilif', 'case' => 'kilif', 'cover' => 'kilif',
		'cam koruyucu' => 'ekran koruyucu', 'temperli cam' => 'ekran koruyucu', 'kirilmaz cam' => 'ekran koruyucu',
		'flash bellek' => 'usb bellek', 'flash disk' => 'usb bellek', 'micro sd' => 'hafiza karti', 'sd kart' => 'hafiza karti',
		'mouse' => 'mouse', 'fare' => 'mouse', 'keyboard' => 'klavye',
		'air fryer' => 'airfryer', 'fritoz' => 'airfryer', 'sicak hava fritozu' => 'airfryer',
		'sneakers' => 'sneaker', 'spor ayakkabi' => 'spor ayakkabi', 'bayan' => 'kadin', 'bay' => 'erkek', 'women' => 'kadin', 'woman' => 'kadin', 'men' => 'erkek', 'man' => 'erkek',
		'kids' => 'cocuk', 'kiz cocuk' => 'kiz cocuk', 'baby' => 'bebek', 'unisex' => 'unisex',
		'jean' => 'kot', 'jeans' => 'kot', 'denim' => 'kot', 'sweat' => 'sweatshirt', 'hoodie' => 'sweatshirt', 'kapsonlu' => 'sweatshirt',
		'parfum' => 'parfum', 'edp' => 'parfum', 'edt' => 'parfum', 'makeup' => 'makyaj', 'skincare' => 'cilt bakim',
		'robot supurge' => 'robot supurge', 'elektrik supurgesi' => 'supurge', 'supurgesi' => 'supurge',
		'beyaz esya' => 'beyaz esya', 'buzdolap' => 'buzdolabi', 'camasir mak' => 'camasir makinesi', 'bulasik mak' => 'bulasik makinesi',
		'oyuncak araba' => 'oyuncak araba', 'lego' => 'yapi lego', 'puzzle' => 'yapboz puzzle', 'yap boz' => 'yapboz',
		'evcil hayvan' => 'pet', 'kedi mamasi' => 'kedi mama', 'kopek mamasi' => 'kopek mama',
		'nevresim' => 'nevresim', 'yatak ortusu' => 'pike', 'bath' => 'banyo', 'mutfak gerecleri' => 'mutfak gerec',
	];

	private const GROUPS = ['kadin' => 'f', 'erkek' => 'm', 'cocuk' => 'c', 'bebek' => 'c', 'kiz' => 'c'];

	public static function reset(): void
	{
		self::$cands = null;
		self::$memo = [];
		self::$tableOk = null;
	}

	/** Türkçe harf katlama + küçük harf + noktalama temizliği. */
	public static function norm(string $s): string
	{
		$s = strtr($s, ['İ' => 'i', 'I' => 'ı', 'Ç' => 'ç', 'Ğ' => 'ğ', 'Ö' => 'ö', 'Ş' => 'ş', 'Ü' => 'ü', 'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Û' => 'u', 'û' => 'u']);
		$s = mb_strtolower(html_entity_decode($s, ENT_QUOTES, 'UTF-8'), 'UTF-8');
		$s = strtr($s, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'ä' => 'a', 'ë' => 'e']);
		$s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';

		return trim(preg_replace('/\s+/', ' ', $s) ?? '');
	}

	/** Yol ayraçları: > / \ | » › ; ve " - " @return string[] */
	public static function splitPath(string $s): array
	{
		$parts = preg_split('/\s*(?:>|»|›|\||\\\\|\/|;|::|\s-\s|→)\s*/u', trim($s)) ?: [];

		return array_values(array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== ''));
	}

	/** @return string[] kanonik belirteçler */
	public static function tokens(string $s): array
	{
		$n = ' ' . self::norm($s) . ' ';

		static $phrases = null;

		if ($phrases === null) {
			$phrases = self::PHRASES;
			uksort($phrases, static fn(string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b)); // uzun ifade önce
		}

		foreach ($phrases as $from => $to) {
			$n = str_replace(' ' . $from . ' ', ' ' . $to . ' ', $n);
		}

		$out = [];

		foreach (explode(' ', trim($n)) as $t) {
			if ($t === '' || in_array($t, self::STOP, true)) {
				continue;
			}

			$t = self::stem($t);

			if ($t !== '' && (strlen($t) > 1 || ctype_digit($t))) {
				$out[$t] = true;
			}
		}

		return array_keys($out);
	}

	private static function stem(string $t): string
	{
		if (strlen($t) <= 4) {
			return $t;
		}

		foreach (['lari', 'leri', 'lar', 'ler', 'si', 'su', 'larin', 'lerin'] as $suf) {
			$l = strlen($suf);

			if (strlen($t) - $l >= 4 && substr($t, -$l) === $suf) {
				return substr($t, 0, -$l);
			}
		}

		return $t;
	}

	private static function tokEq(string $a, string $b): bool
	{
		if ($a === $b) {
			return true;
		}

		$la = strlen($a);
		$lb = strlen($b);

		if (min($la, $lb) < 4) {
			return false;
		}

		// "kilif" ~ "kilifi", "telefon" ~ "telefonu"
		if (($la < $lb ? strpos($b, $a) === 0 : strpos($a, $b) === 0) && min($la, $lb) / max($la, $lb) >= 0.7) {
			return true;
		}

		// yazım hatası toleransı yalnızca uzun sözcüklerde ("kilif" ≠ "kilim")
		return min($la, $lb) >= 7 && abs($la - $lb) <= 1 && levenshtein($a, $b) <= 1;
	}

	/** @param string[] $a @param string[] $b @return int a'daki öğelerden b'de karşılığı olanların sayısı */
	private static function hits(array $a, array $b): int
	{
		$n = 0;

		foreach ($a as $x) {
			foreach ($b as $y) {
				if (self::tokEq($x, $y)) {
					$n++;
					break;
				}
			}
		}

		return $n;
	}

	/** @param string[] $toks @return array<string,bool> */
	private static function groups(array $toks): array
	{
		$g = [];

		foreach ($toks as $t) {
			if (isset(self::GROUPS[$t])) {
				$g[self::GROUPS[$t]] = true;
			}
		}

		return $g;
	}

	/** @return array<int, array<string,mixed>> aktif kategoriler, kök hariç yol ile */
	public static function candidates(): array
	{
		if (self::$cands !== null) {
			return self::$cands;
		}

		$rows = DB::execute('SELECT id_category, id_parent, category_name FROM categories WHERE active = 1') ?: [];
		$byId = [];

		foreach ($rows as $r) {
			$byId[(int) $r['id_category']] = ['p' => (int) $r['id_parent'], 'n' => (string) $r['category_name']];
		}

		$out = [];

		foreach ($byId as $id => $c) {
			if ($c['p'] === 0) {
				continue; // kök (Katalog)
			}

			$path = [$c['n']];
			$p = $c['p'];
			$guard = 0;

			while ($p > 0 && isset($byId[$p]) && $guard++ < 10) {
				if ($byId[$p]['p'] === 0) {
					break; // kökü yola katma
				}

				array_unshift($path, $byId[$p]['n']);
				$p = $byId[$p]['p'];
			}

			$ctx = [];

			foreach (array_slice($path, 0, -1) as $seg) {
				$ctx = array_merge($ctx, self::tokens($seg));
			}

			$leaf = self::tokens($c['n']);

			if ($leaf === []) {
				continue;
			}

			$out[] = ['id' => $id, 'name' => $c['n'], 'path' => implode(' > ', $path), 'depth' => count($path), 'leaf' => $leaf, 'ctx' => array_values(array_unique($ctx)), 'leafNorm' => self::norm($c['n'])];
		}

		return self::$cands = $out;
	}

	/**
	 * En iyi aday ve puan (eşikten bağımsız). @return array{id:int, score:float, path:string, alternatives: array<int, array{id:int, score:float, path:string}>}
	 */
	public static function match(string $source): array
	{
		$source = trim(mb_substr($source, 0, 255));
		$none = ['id' => 0, 'score' => 0.0, 'path' => '', 'alternatives' => []];

		if ($source === '') {
			return $none;
		}

		if (isset(self::$memo[$source])) {
			return self::$memo[$source];
		}

		$levels = self::splitPath($source);

		if ($levels === []) {
			return $none;
		}

		$levelToks = array_map([self::class, 'tokens'], $levels);
		$all = array_values(array_unique(array_merge(...$levelToks)));
		$srcGroups = self::groups($all);
		$last = count($levels) - 1;
		$fullNorm = self::norm(implode(' ', $levels));
		$scored = [];

		foreach (self::candidates() as $c) {
			$best = 0.0;

			for ($i = $last; $i >= 0 && $i >= $last - 2; $i--) {
				$lv = $levelToks[$i];

				if ($lv === []) {
					continue;
				}

				if (self::hits($c['leaf'], $lv) === 0) {
					continue;
				}

				// yaprak kapsaması: adayın adındaki sözcükler bu seviyede ya da üst seviyelerde geçiyor mu ("Telefon > Kılıf" → "Telefon Kılıfı")
				$above = $i > 0 ? array_merge(...array_slice($levelToks, 0, $i)) : [];
				$cover = self::hits($c['leaf'], array_merge($lv, $above)) / count($c['leaf']);
				$prec = self::hits($lv, $c['leaf']) / count($lv);
				$leafSim = 0.7 * $cover + 0.3 * $prec;

				// Türkçe tamlamada asıl ad sondadır: "Telefon Kılıfı" bir kılıftır, "Kadın Elbise" bir elbisedir
				if (self::hits([(string) end($lv)], $c['leaf']) === 0) {
					$leafSim *= 0.6;
				}

				if (self::norm($levels[$i]) === $c['leafNorm']) {
					$leafSim = 1.0;
				}

				// bağlam: kaynak yolun geri kalanı + aynı seviyedeki eşleşmeyen belirteçler ↔ adayın üst kategorileri
				$rest = [];

				foreach ($levelToks as $j => $tk) {
					foreach ($tk as $t) {
						if ($j !== $i || !in_array($t, $c['leaf'], true)) {
							$rest[] = $t;
						}
					}
				}

				$rest = array_values(array_filter($rest, static fn(string $t): bool => self::hits([$t], $c['leaf']) === 0));

				if ($c['ctx'] === []) {
					$ctxSim = 0.6;
				} elseif ($rest === []) {
					$ctxSim = 0.6;
				} else {
					$ctxSim = min(1.0, max(self::hits($c['ctx'], $rest) / min(count($c['ctx']), 2), self::hits($rest, $c['ctx']) / count($rest)));
				}
				$s = (0.72 * $leafSim + 0.28 * $ctxSim) * (0.88 ** ($last - $i));

				// cinsiyet / yaş grubu çakışması
				$cg = self::groups(array_merge($c['ctx'], $c['leaf']));

				if ($srcGroups !== [] && $cg !== []) {
					if (array_intersect_key($srcGroups, $cg) === []) {
						$s *= 0.55;
					} elseif (isset($cg['c']) && !isset($srcGroups['c'])) {
						$s *= 0.8; // kaynak çocuk demiyor, aday çocuk kategorisi ("Erkek" ≠ "Erkek Çocuk")
					}
				} elseif ($srcGroups === [] && $cg !== []) {
					$s *= isset($cg['c']) ? 0.8 : 0.93; // kaynak cinsiyet/yaş belirtmiyor: tarafsız kategori tercih edilir
				}

				$best = max($best, $s);
			}

			if ($best > 0) {
				$scored[] = ['id' => $c['id'], 'score' => round(min(1.0, $best + 0.0004 * $c['depth']), 4), 'path' => $c['path']];
			}
		}

		usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

		if ($scored === []) {
			return self::$memo[$source] = $none;
		}

		// belirsizlik: iki farklı kategori (ör. Kadın > Mont / Erkek > Mont, Telefon Kılıfı / Tablet Kılıfı) neredeyse eşit puanlıysa
		// otomatik atama yapılmaz; öneri olarak kalır (yanlış cinsiyete ürün atılmasın)
		if (isset($scored[1]) && $scored[0]['score'] - $scored[1]['score'] < 0.02) {
			$names = [];
			$grp = [];

			foreach (self::candidates() as $c) {
				if ($c['id'] === $scored[0]['id'] || $c['id'] === $scored[1]['id']) {
					$names[] = $c['leafNorm'];
					$g = array_keys(self::groups(array_merge($c['ctx'], $c['leaf'])));
					sort($g);
					$grp[] = implode(',', $g);
				}
			}

			if (count($names) === 2 && ($names[0] !== $names[1] || $grp[0] !== $grp[1])) {
				$scored[0]['score'] = min($scored[0]['score'], round(self::AUTO_THRESHOLD - 0.01, 4));
				$scored[0]['ambiguous'] = true;
			}
		}

		$top = $scored[0];

		return self::$memo[$source] = ['id' => (int) $top['id'], 'score' => (float) $top['score'], 'path' => (string) $top['path'], 'alternatives' => array_slice($scored, 1, 4)];
	}

	private static function key(string $source): string
	{
		return sha1(implode(' > ', array_map([self::class, 'norm'], self::splitPath($source))));
	}

	private static function tableOk(): bool
	{
		if (self::$tableOk === null) {
			self::$tableOk = DB::execute('SELECT id_category FROM ' . self::TABLE . ' WHERE 1 = 0') !== false;
		}

		return self::$tableOk;
	}

	private static function activeCategory(int $id): bool
	{
		return $id > 0 && (int) DB::getValue('SELECT COUNT(*) FROM categories WHERE id_category = ? AND active = 1', [$id]) > 0;
	}

	/**
	 * Kaynak metin için kullanılacak kategori ID'si (0 = güvenli eşleşme yok).
	 * @param bool $learn otomatik eşleşmeyi kalıcı tabloya yazar (bir kez bulunan hep aynı kalır; yönetici düzeltebilir)
	 */
	public static function resolve(string $source, bool $learn = true): int
	{
		$source = trim($source);

		if ($source === '') {
			return 0;
		}

		$key = self::key($source);

		if (self::tableOk()) {
			$row = DB::execute('SELECT id_category, origin FROM ' . self::TABLE . ' WHERE source_key = ? LIMIT 1', [$key]);

			if ($row && isset($row[0])) {
				$id = (int) $row[0]['id_category'];

				if ($row[0]['origin'] === 'ignore') {
					return 0;
				}

				if (self::activeCategory($id)) {
					DB::execute('UPDATE ' . self::TABLE . ' SET hits = hits + 1 WHERE source_key = ?', [$key]);

					return $id;
				}
			}
		}

		$m = self::match($source);

		if ($m['id'] <= 0 || $m['score'] < self::AUTO_THRESHOLD) {
			if ($learn && self::tableOk()) {
				self::store($key, $source, 0, 'pending', $m['score'], $m['id']);
			}

			return 0;
		}

		if ($learn && self::tableOk()) {
			self::store($key, $source, $m['id'], 'auto', $m['score']);
		}

		return $m['id'];
	}

	private static function store(string $key, string $source, int $id, string $origin, float $score, int $suggest = 0): void
	{
		$exists = (int) DB::getValue('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE source_key = ?', [$key]) > 0;
		$row = ['source_text' => mb_substr($source, 0, 255), 'id_category' => $id, 'suggest_id' => $suggest, 'origin' => $origin, 'score' => round($score, 4), 'date_upd' => date('Y-m-d H:i:s')];

		if ($exists) {
			// yöneticinin kararı (manual/ignore) otomatik sonuçla ezilmez
			DB::update(self::TABLE, $row, "source_key = :k AND origin NOT IN ('manual', 'ignore')", ['k' => $key]);
		} else {
			DB::insert(self::TABLE, $row + ['source_key' => $key, 'hits' => 1]);
		}
	}

	/** Yönetici/satıcı onayı: kaynağı kalıcı olarak bir kategoriye bağlar (0 = "eşleştirme, varsayılanı kullan"). */
	public static function setManual(string $source, int $idCategory): array
	{
		$source = trim($source);

		if ($source === '' || !self::tableOk()) {
			return ['success' => false, 'message' => 'Kaynak kategori boş ya da modül kurulu değil.'];
		}

		if ($idCategory > 0 && !self::activeCategory($idCategory)) {
			return ['success' => false, 'message' => 'Kategori bulunamadı veya pasif.'];
		}

		$key = self::key($source);
		$origin = $idCategory > 0 ? 'manual' : 'ignore';
		$exists = (int) DB::getValue('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE source_key = ?', [$key]) > 0;
		$row = ['source_text' => mb_substr($source, 0, 255), 'id_category' => $idCategory, 'origin' => $origin, 'date_upd' => date('Y-m-d H:i:s')];

		if ($exists) {
			DB::update(self::TABLE, $row, 'source_key = :k', ['k' => $key]);
		} else {
			DB::insert(self::TABLE, $row + ['source_key' => $key, 'hits' => 0, 'score' => 1, 'suggest_id' => 0]);
		}

		unset(self::$memo[$source]);

		return ['success' => true, 'message' => $idCategory > 0 ? 'Eşleme kaydedildi.' : 'Kaynak yok sayılacak (varsayılan kategori kullanılır).'];
	}

	/** Bekleyen (eşik altı) önerileri toplu onaylar: önerilen kategori ile manual eşleme yapar. */
	public static function acceptSuggestions(array $keys): int
	{
		if (!self::tableOk()) {
			return 0;
		}

		$n = 0;

		foreach (array_slice($keys, 0, 500) as $k) {
			$k = (string) $k;

			if (!preg_match('/^[a-f0-9]{40}$/', $k)) {
				continue;
			}

			$r = DB::execute('SELECT source_text, suggest_id FROM ' . self::TABLE . " WHERE source_key = ? AND origin = 'pending' LIMIT 1", [$k]);

			if ($r && isset($r[0]) && (int) $r[0]['suggest_id'] > 0 && self::setManual((string) $r[0]['source_text'], (int) $r[0]['suggest_id'])['success']) {
				$n++;
			}
		}

		return $n;
	}

	/** @return array<int, array<string,mixed>> */
	public static function listMappings(string $origin = '', int $limit = 300): array
	{
		if (!self::tableOk()) {
			return [];
		}

		$where = in_array($origin, ['manual', 'auto', 'pending', 'ignore'], true) ? 'WHERE m.origin = ?' : '';
		$params = $where !== '' ? [$origin] : [];

		return DB::execute('SELECT m.*, c.category_name, s.category_name AS suggest_name FROM ' . self::TABLE . ' m LEFT JOIN categories c ON c.id_category = m.id_category LEFT JOIN categories s ON s.id_category = m.suggest_id ' . $where . ' ORDER BY m.hits DESC, m.date_upd DESC LIMIT ' . max(1, min(2000, $limit)), $params) ?: [];
	}

	/** Kategori ID → "Üst > Alt" gösterimi. */
	public static function pathOf(int $id): string
	{
		foreach (self::candidates() as $c) {
			if ($c['id'] === $id) {
				return $c['path'];
			}
		}

		return '';
	}
}
