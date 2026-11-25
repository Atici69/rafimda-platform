<?php

if (!class_exists('CategoryMatcher', false)) {
	require_once __DIR__ . '/CategoryMatcher.php';
}

/**
 * Otomatik kategori eşleştirmenin kalıcı tarafı:
 *  - Hafıza: "bu kaynak kategori metni → bu hedef kategori" (satıcıya/platforma özel, onaylanmış düzeltmeler önceliklidir)
 *  - Kontrol listesi: emin olunmayan (orta/düşük güven) atamalar; tek tıkla onay ya da düzeltme
 *  - Site kategori ağacı (aktif kategoriler, kök hariç, tam yol)
 *
 * Tablolar yalnızca EKLENİR (CREATE TABLE IF NOT EXISTS); mevcut tablolara dokunulmaz.
 */
class CategoryMatchStore
{
	public const SCOPE_SITE = 'site';

	private static bool $ready = false;

	/** @var array<string,list<array<string,mixed>>> */
	private static array $prepared = [];

	public static function reset(): void
	{
		self::$ready = false;
		self::$prepared = [];
	}

	private static function sqlite(): bool
	{
		global $db;

		return $db instanceof PDO && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
	}

	public static function ensureSchema(): void
	{
		if (self::$ready) {
			return;
		}

		self::$ready = true;

		if (self::sqlite()) {
			DB::execute('CREATE TABLE IF NOT EXISTS rafimda_category_memory (id_memory INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, id_owner INT NOT NULL DEFAULT 0, source_key TEXT NOT NULL, source_text TEXT NOT NULL DEFAULT \'\', target_id TEXT NOT NULL, target_path TEXT NOT NULL DEFAULT \'\', confirmed INT NOT NULL DEFAULT 0, uses INT NOT NULL DEFAULT 0, date_upd TEXT, UNIQUE (scope, id_owner, source_key))');
			DB::execute('CREATE TABLE IF NOT EXISTS rafimda_category_review (id_review INTEGER PRIMARY KEY AUTOINCREMENT, id_product INT NOT NULL UNIQUE, id_owner INT NOT NULL DEFAULT 0, origin TEXT NOT NULL DEFAULT \'\', source_text TEXT NOT NULL DEFAULT \'\', product_name TEXT NOT NULL DEFAULT \'\', id_category INT NOT NULL DEFAULT 0, score REAL NOT NULL DEFAULT 0, confidence TEXT NOT NULL DEFAULT \'\', alternatives TEXT, status TEXT NOT NULL DEFAULT \'pending\', date_add TEXT, date_upd TEXT)');

			return;
		}

		DB::execute("CREATE TABLE IF NOT EXISTS `rafimda_category_memory` (
			`id_memory` int(11) NOT NULL AUTO_INCREMENT,
			`scope` varchar(40) NOT NULL,
			`id_owner` int(11) NOT NULL DEFAULT 0,
			`source_key` char(40) NOT NULL,
			`source_text` varchar(255) NOT NULL DEFAULT '',
			`target_id` varchar(60) NOT NULL,
			`target_path` varchar(255) NOT NULL DEFAULT '',
			`confirmed` tinyint(1) NOT NULL DEFAULT 0,
			`uses` int(11) NOT NULL DEFAULT 0,
			`date_upd` datetime DEFAULT NULL,
			PRIMARY KEY (`id_memory`),
			UNIQUE KEY `scope_owner_key` (`scope`,`id_owner`,`source_key`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		DB::execute("CREATE TABLE IF NOT EXISTS `rafimda_category_review` (
			`id_review` int(11) NOT NULL AUTO_INCREMENT,
			`id_product` int(11) NOT NULL,
			`id_owner` int(11) NOT NULL DEFAULT 0,
			`origin` varchar(20) NOT NULL DEFAULT '',
			`source_text` varchar(255) NOT NULL DEFAULT '',
			`product_name` varchar(255) NOT NULL DEFAULT '',
			`id_category` int(11) NOT NULL DEFAULT 0,
			`score` decimal(5,3) NOT NULL DEFAULT 0.000,
			`confidence` varchar(8) NOT NULL DEFAULT '',
			`alternatives` text DEFAULT NULL,
			`status` varchar(10) NOT NULL DEFAULT 'pending',
			`date_add` datetime DEFAULT NULL,
			`date_upd` datetime DEFAULT NULL,
			PRIMARY KEY (`id_review`),
			UNIQUE KEY `id_product` (`id_product`),
			KEY `owner_status` (`id_owner`,`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	public static function key(string $source): string
	{
		return sha1(CategoryMatcher::fold($source));
	}

	private static function now(): string
	{
		return date('Y-m-d H:i:s');
	}

	// ---- site kategori ağacı ----

	/** @return list<array{id:int, path:list<string>}> */
	public static function siteTargets(): array
	{
		$rows = DB::execute('SELECT id_category, id_parent, category_name, active FROM categories') ?: [];
		$by = [];

		foreach ($rows as $r) {
			$by[(int) $r['id_category']] = $r;
		}

		$root = 0;

		foreach ($by as $id => $r) {
			if ((int) ($r['id_parent'] ?? 0) === 0 && ($root === 0 || $id < $root)) {
				$root = $id;
			}
		}

		$out = [];

		foreach ($by as $id => $r) {
			if ((int) ($r['active'] ?? 1) !== 1 || $id === $root) {
				continue;
			}

			$path = [];
			$cur = $r;
			$guard = 0;
			$activeChain = true;

			while ($cur && $guard++ < 12) {
				$cid = (int) $cur['id_category'];

				if ($cid === $root) {
					break;
				}

				if ((int) ($cur['active'] ?? 1) !== 1) {
					$activeChain = false;
				}

				array_unshift($path, (string) $cur['category_name']);
				$pid = (int) ($cur['id_parent'] ?? 0);
				$cur = $pid > 0 ? ($by[$pid] ?? null) : null;
			}

			if ($activeChain && $path !== []) {
				$out[] = ['id' => $id, 'path' => $path];
			}
		}

		return $out;
	}

	/** @return list<array<string,mixed>> */
	public static function prepared(string $scope, callable $targets): array
	{
		if (!isset(self::$prepared[$scope])) {
			self::$prepared[$scope] = CategoryMatcher::prepare($targets());
		}

		return self::$prepared[$scope];
	}

	public static function sitePath(int $idCategory): string
	{
		foreach (self::prepared(self::SCOPE_SITE, [self::class, 'siteTargets']) as $c) {
			if ((int) $c['id'] === $idCategory) {
				return (string) $c['path'];
			}
		}

		return '';
	}

	// ---- hafıza ----

	/** @return array{target_id:string, target_path:string, confirmed:bool}|null */
	public static function recall(string $scope, int $owner, string $source): ?array
	{
		if (trim($source) === '') {
			return null;
		}

		self::ensureSchema();
		$k = self::key($source);

		foreach (array_unique([$owner, 0]) as $o) {
			$r = DB::execute('SELECT target_id, target_path, confirmed FROM rafimda_category_memory WHERE scope = ? AND id_owner = ? AND source_key = ? LIMIT 1', [$scope, $o, $k]);

			if ($r && isset($r[0])) {
				return ['target_id' => (string) $r[0]['target_id'], 'target_path' => (string) $r[0]['target_path'], 'confirmed' => (int) $r[0]['confirmed'] === 1];
			}
		}

		return null;
	}

	/** Onaylı kayıt, onaysız (otomatik) kaydın üzerine yazılır; otomatik kayıt onaylının üzerine YAZILMAZ. */
	public static function remember(string $scope, int $owner, string $source, string $targetId, string $targetPath, bool $confirmed): void
	{
		if (trim($source) === '' || $targetId === '') {
			return;
		}

		self::ensureSchema();
		$k = self::key($source);
		$ex = DB::execute('SELECT id_memory, confirmed FROM rafimda_category_memory WHERE scope = ? AND id_owner = ? AND source_key = ? LIMIT 1', [$scope, $owner, $k]);
		$row = ['target_id' => mb_substr($targetId, 0, 60), 'target_path' => mb_substr($targetPath, 0, 255), 'confirmed' => $confirmed ? 1 : 0, 'date_upd' => self::now()];

		if ($ex && isset($ex[0])) {
			if ((int) $ex[0]['confirmed'] === 1 && !$confirmed) {
				DB::execute('UPDATE rafimda_category_memory SET uses = uses + 1 WHERE id_memory = ?', [(int) $ex[0]['id_memory']]);

				return;
			}

			DB::update('rafimda_category_memory', $row, 'id_memory = :i', ['i' => (int) $ex[0]['id_memory']]);

			return;
		}

		DB::insert('rafimda_category_memory', $row + ['scope' => $scope, 'id_owner' => $owner, 'source_key' => $k, 'source_text' => mb_substr(trim($source), 0, 255), 'uses' => 1]);
	}

	// ---- site kategorisi çözümleme ----

	public static function fallbackCategory(): int
	{
		$id = class_exists('Settings') ? (int) Settings::get('RAFIMDA_FALLBACK_CATEGORY') : 0;

		return $id > 0 && DB::execute('SELECT 1 AS x FROM categories WHERE id_category = ?', [$id]) ? $id : 0;
	}

	/**
	 * Kaynak kategori metni ve ürün adından site kategorisini bulur.
	 * Sıra: hafıza (onaylı düzeltme / önceki otomatik karar) → motor → yedek kategori.
	 *
	 * @return array{id_category:int, path:string, score:float, confidence:string, from:string, alternatives:list<array<string,mixed>>}
	 */
	public static function resolveSite(int $owner, string $sourceCategory, string $productName = ''): array
	{
		$mem = self::recall(self::SCOPE_SITE, $owner, $sourceCategory);

		// Yalnızca ONAYLI kararlar (kullanıcı düzeltmesi/onayı) motoru atlar; onaysız otomatik kayıtlar motor iyileştikçe yeniden hesaplanır.
		$m = CategoryMatcher::match($sourceCategory, $productName, self::prepared(self::SCOPE_SITE, [self::class, 'siteTargets']));

		if ($mem && $mem['confirmed'] && (int) $mem['target_id'] > 0 && DB::execute('SELECT 1 AS x FROM categories WHERE id_category = ? AND active = 1', [(int) $mem['target_id']])) {
			// Genel bir kaynak kategori ("Çocuk", "Muhtelif") için hatırlanan karar, ürün adı başka bir kategoriyi AÇIKÇA
			// gösteriyorsa (yüksek güven) uygulanmaz; ürün o kategoriye gider ve kontrol listesine düşer.
			if ($m['id'] !== null && $m['confidence'] === 'high' && (string) $m['id'] !== (string) $mem['target_id'] && trim($productName) !== '') {
				return ['id_category' => (int) $m['id'], 'path' => $m['path'], 'score' => $m['score'], 'confidence' => 'medium', 'from' => 'auto', 'alternatives' => array_merge([['id' => (int) $mem['target_id'], 'path' => $mem['target_path'], 'score' => 1.0]], $m['alternatives'])];
			}

			return ['id_category' => (int) $mem['target_id'], 'path' => $mem['target_path'], 'score' => 1.0, 'confidence' => 'confirmed', 'from' => 'memory', 'alternatives' => []];
		}

		if ($m['id'] !== null && $m['confidence'] !== 'low') {
			// Yüksek güvenli kararlar kaynak metin için hatırlanır (aynı tedarikçi kategorisi hep aynı yere gider).
			if ($m['confidence'] === 'high' && trim($sourceCategory) !== '') {
				self::remember(self::SCOPE_SITE, $owner, $sourceCategory, (string) $m['id'], $m['path'], false);
			}

			return ['id_category' => (int) $m['id'], 'path' => $m['path'], 'score' => $m['score'], 'confidence' => $m['confidence'], 'from' => 'auto', 'alternatives' => $m['alternatives']];
		}

		$fb = self::fallbackCategory();

		if ($fb > 0) {
			$alts = $m['id'] !== null ? array_merge([['id' => $m['id'], 'path' => $m['path'], 'score' => $m['score']]], $m['alternatives']) : [];

			return ['id_category' => $fb, 'path' => self::sitePath($fb), 'score' => 0.0, 'confidence' => 'low', 'from' => 'fallback', 'alternatives' => $alts];
		}

		if ($m['id'] !== null) {
			return ['id_category' => (int) $m['id'], 'path' => $m['path'], 'score' => $m['score'], 'confidence' => 'low', 'from' => 'auto', 'alternatives' => $m['alternatives']];
		}

		return ['id_category' => 0, 'path' => '', 'score' => 0.0, 'confidence' => 'none', 'from' => 'none', 'alternatives' => []];
	}

	// ---- kontrol listesi ----

	/** @param array<string,mixed> $res resolveSite() çıktısı */
	public static function queueReview(int $idProduct, int $owner, string $origin, string $sourceText, string $productName, array $res): void
	{
		if ($idProduct <= 0 || in_array($res['confidence'], ['high', 'confirmed'], true)) {
			return;
		}

		self::ensureSchema();
		$row = [
			'id_owner' => $owner,
			'origin' => mb_substr($origin, 0, 20),
			'source_text' => mb_substr($sourceText, 0, 255),
			'product_name' => mb_substr($productName, 0, 255),
			'id_category' => (int) $res['id_category'],
			'score' => (float) $res['score'],
			'confidence' => (string) $res['confidence'],
			'alternatives' => json_encode(array_slice((array) $res['alternatives'], 0, 4), JSON_UNESCAPED_UNICODE),
			'status' => 'pending',
			'date_upd' => self::now(),
		];

		if (DB::execute('SELECT 1 AS x FROM rafimda_category_review WHERE id_product = ?', [$idProduct])) {
			DB::update('rafimda_category_review', $row, 'id_product = :p', ['p' => $idProduct]);
		} else {
			DB::insert('rafimda_category_review', $row + ['id_product' => $idProduct, 'date_add' => self::now()]);
		}
	}

	/** @return list<array<string,mixed>> */
	public static function pending(int $owner, int $limit = 200): array
	{
		self::ensureSchema();
		$rows = DB::execute("SELECT r.*, p.product_name AS current_name, p.id_category AS current_category FROM rafimda_category_review r INNER JOIN products p ON p.id_product = r.id_product WHERE r.id_owner = ? AND r.status = 'pending' ORDER BY r.score ASC, r.id_review ASC LIMIT " . max(1, min(500, $limit)), [$owner]) ?: [];

		foreach ($rows as &$r) {
			$r['path'] = self::sitePath((int) $r['current_category']);
			$r['alternatives'] = json_decode((string) ($r['alternatives'] ?? ''), true) ?: [];
		}

		unset($r);

		return $rows;
	}

	public static function pendingCount(int $owner): int
	{
		self::ensureSchema();

		return (int) DB::getValue("SELECT COUNT(*) FROM rafimda_category_review WHERE id_owner = ? AND status = 'pending'", [$owner]);
	}

	/**
	 * Kontrol bekleyen atamayı onaylar ya da değiştirir. Kaynak metin için onaylı hafıza kaydı yazılır:
	 * aynı tedarikçi/Excel kategorisi sonraki aktarımlarda doğrudan bu kategoriye gider.
	 * Sahiplik denetimi çağıranın sorumluluğundadır ($owner ile kayıt eşleşmesi burada ayrıca doğrulanır).
	 *
	 * @return array{success:bool, message:string, updated:int}
	 */
	public static function resolveReview(int $owner, int $idProduct, int $idCategory, bool $applyToSameSource = true): array
	{
		self::ensureSchema();
		$r = DB::execute('SELECT * FROM rafimda_category_review WHERE id_product = ? AND id_owner = ?', [$idProduct, $owner]);

		if (!$r || !isset($r[0])) {
			return ['success' => false, 'message' => 'Kayıt bulunamadı.', 'updated' => 0];
		}

		if (!DB::execute('SELECT 1 AS x FROM categories WHERE id_category = ? AND active = 1', [$idCategory])) {
			return ['success' => false, 'message' => 'Kategori bulunamadı.', 'updated' => 0];
		}

		$rev = $r[0];
		$changed = (int) $rev['id_category'] !== $idCategory;
		$ids = [$idProduct];

		if ($applyToSameSource && trim((string) $rev['source_text']) !== '') {
			foreach (DB::execute("SELECT id_product FROM rafimda_category_review WHERE id_owner = ? AND status = 'pending' AND source_text = ?", [$owner, (string) $rev['source_text']]) ?: [] as $x) {
				$ids[] = (int) $x['id_product'];
			}
		}

		$ids = array_values(array_unique($ids));

		foreach ($ids as $pid) {
			DB::update('products', ['id_category' => $idCategory], 'id_product = :p', ['p' => $pid]);
			DB::update('rafimda_category_review', ['status' => $changed ? 'changed' : 'confirmed', 'id_category' => $idCategory, 'date_upd' => self::now()], 'id_product = :p', ['p' => $pid]);
		}

		$src = trim((string) $rev['source_text']);

		// Hafızaya yalnızca kaynak kategori metninin kendisi bu kararı taşıyorsa yazılır: kullanıcı elle değiştirdiyse ya da
		// metin tek başına aynı kategoriyi gösteriyorsa. "Çocuk" gibi genel bir metnin, ürün adıyla bulunmuş kararı
		// (ör. Çocuk Ayakkabı) toplu onayda bütün "Çocuk" ürünlerine genellenmez.
		if ($src !== '') {
			$alone = CategoryMatcher::match($src, '', self::prepared(self::SCOPE_SITE, [self::class, 'siteTargets']));

			if ($changed || (string) $alone['id'] === (string) $idCategory) {
				self::remember(self::SCOPE_SITE, $owner, $src, (string) $idCategory, self::sitePath($idCategory), true);
			}
		}

		return ['success' => true, 'message' => count($ids) . ' ürünün kategorisi ' . ($changed ? 'değiştirildi' : 'onaylandı') . '.', 'updated' => count($ids)];
	}

	/** Bekleyen ve güveni "orta" olan tüm atamaları onaylar. @return int onaylanan ürün sayısı */
	public static function confirmAllMedium(int $owner): int
	{
		$n = 0;

		foreach (self::pending($owner, 500) as $r) {
			if ($r['confidence'] === 'medium' && $r['status'] === 'pending') {
				$res = self::resolveReview($owner, (int) $r['id_product'], (int) $r['current_category'], false);
				$n += $res['updated'];
			}
		}

		return $n;
	}

	// ---- marka ----

	/**
	 * Marka adından marka ID'si: birebir (Türkçe büyük/küçük harf duyarsız) eşleşme; yoksa ve izin verilmişse yeni marka açılır
	 * (RAFIMDA_IMPORT_CREATE_BRANDS, varsayılan açık). Hiçbiri olmazsa RAFIMDA_FALLBACK_BRAND kullanılır.
	 *
	 * @return array{id:int, created:bool}
	 */
	public static function brandId(string $name, ?bool $create = null): array
	{
		$name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');

		if ($name !== '') {
			$want = CategoryMatcher::fold($name);

			foreach (DB::execute('SELECT id_brand, brand_name FROM brands WHERE active = 1') ?: [] as $b) {
				if (CategoryMatcher::fold((string) $b['brand_name']) === $want) {
					return ['id' => (int) $b['id_brand'], 'created' => false];
				}
			}

			$create = $create ?? (!class_exists('Settings') || (string) Settings::get('RAFIMDA_IMPORT_CREATE_BRANDS') !== '0');

			if ($create && mb_strlen($name) >= 2) {
				$label = mb_substr($name, 0, 48);
				$base = mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', CategoryMatcher::fold($label)) ?? '', '-'), 0, 56) ?: 'marka';
				$link = $base;

				for ($i = 2; DB::execute('SELECT 1 AS x FROM brands WHERE brand_link = ?', [$link]) && $i < 50; $i++) {
					$link = $base . '-' . $i;
				}

				$id = DB::insert('brands', ['brand_name' => $label, 'brand_link' => $link, 'active' => 1]);

				if ($id) {
					return ['id' => (int) $id, 'created' => true];
				}
			}
		}

		$fb = class_exists('Settings') ? (int) Settings::get('RAFIMDA_FALLBACK_BRAND') : 0;

		return ['id' => $fb > 0 && DB::execute('SELECT 1 AS x FROM brands WHERE id_brand = ? AND active = 1', [$fb]) ? $fb : 0, 'created' => false];
	}
}
