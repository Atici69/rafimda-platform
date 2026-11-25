<?php

/**
 * Pazaryeri kataloğu: uzak kategori ağacı (önbellekli arama), kategori özellikleri, marka arama,
 * hesap bazlı özellik ve marka eşlemeleri.
 *
 * Kategori ağacı ve özellik listeleri pazaryerinin GENEL verisidir (satıcıya özel değildir); 24 saat dosya önbelleğinde tutulur.
 * Önbellek yalnızca okuma çağrılarının sayısını azaltır; eşleme kayıtları veritabanındadır.
 */
class MhCatalog
{
	public const TTL = 86400;
	public const SEARCH_LIMIT = 60;

	/** Testler için önbellek klasörünü değiştirmeye izin verir. */
	public static string $cacheDir = '';

	private static function dir(): string
	{
		$d = self::$cacheDir !== '' ? self::$cacheDir : dirname(__DIR__, 3) . '/cache/marketplace-hub';

		if (!is_dir($d)) {
			@mkdir($d, 0755, true);
		}

		return $d;
	}

	private static function file(string $name): string
	{
		return self::dir() . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $name) . '.json';
	}

	/** @return mixed|null */
	private static function cacheGet(string $name)
	{
		$f = self::file($name);

		if (!is_file($f) || filemtime($f) < time() - self::TTL) {
			return null;
		}

		$d = json_decode((string) file_get_contents($f), true);

		return is_array($d) ? $d : null;
	}

	/** @param mixed $data */
	private static function cachePut(string $name, $data): void
	{
		$f = self::file($name);
		$tmp = $f . '.' . bin2hex(random_bytes(4));

		if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false) {
			@rename($tmp, $f);
		}
	}

	public static function clearCache(string $marketplace): int
	{
		$n = 0;

		foreach (glob(self::dir() . '/' . preg_replace('/[^a-z0-9]/', '', $marketplace) . '-*.json') ?: [] as $f) {
			$n += @unlink($f) ? 1 : 0;
		}

		return $n;
	}

	/** @return array{0:?MhCoreAdapter,1:array<string,string>,2:array<string,mixed>|null} */
	private static function ctx(int $idAccount, ?int $vendor): array
	{
		$acc = MhRepo::account($idAccount, $vendor);
		$a = $acc ? MhRegistry::get((string) $acc['marketplace']) : null;

		if (!$acc || !$a instanceof MhCoreAdapter || !$a->supportsCatalog()) {
			return [null, [], $acc];
		}

		return [$a, MhRepo::credentials($idAccount), $acc];
	}

	public static function supported(string $marketplace): bool
	{
		$a = MhRegistry::get($marketplace);

		return $a instanceof MhCoreAdapter && $a->supportsCatalog();
	}

	/**
	 * Düzleştirilmiş uzak kategori listesi: [{id, name, path, leaf}]
	 *
	 * @return array{ok:bool, message:string, items:list<array{id:string,name:string,path:string,leaf:bool}>}
	 */
	public static function categories(int $idAccount, ?int $vendor, bool $refresh = false): array
	{
		[$a, $creds, $acc] = self::ctx($idAccount, $vendor);

		if (!$a) {
			return ['ok' => false, 'message' => $acc ? 'Bu pazaryeri için kategori listesi henüz desteklenmiyor; kodu elle girin.' : 'Hesap bulunamadı.', 'items' => []];
		}

		$key = $a->key() . '-categories';
		$items = $refresh ? null : self::cacheGet($key);

		if ($items === null) {
			$r = $a->remoteCategories($creds);

			if (!$r['ok']) {
				return ['ok' => false, 'message' => $r['message'], 'items' => []];
			}

			$items = $r['items'];
			self::cachePut($key, $items);
		}

		return ['ok' => true, 'message' => '', 'items' => $items];
	}

	/**
	 * Ada/yola göre arama (Türkçe karakter duyarsız). Yalnızca son (alt kategorisi olmayan) kategoriler ürün kabul eder; önce onlar gelir.
	 *
	 * @return array{ok:bool, message:string, items:list<array{id:string,name:string,path:string,leaf:bool}>}
	 */
	public static function searchCategories(int $idAccount, ?int $vendor, string $q): array
	{
		$q = self::norm($q);

		if (mb_strlen($q) < 2) {
			return ['ok' => false, 'message' => 'En az 2 harf yazın.', 'items' => []];
		}

		$all = self::categories($idAccount, $vendor);

		if (!$all['ok']) {
			return $all;
		}

		$hits = [];

		foreach ($all['items'] as $c) {
			if (strpos(self::norm($c['path']), $q) !== false || (string) $c['id'] === $q) {
				$hits[] = $c;
			}
		}

		usort($hits, static function (array $x, array $y) use ($q): int {
			if ($x['leaf'] !== $y['leaf']) {
				return $x['leaf'] ? -1 : 1;
			}

			$xs = strpos(self::norm($x['name']), $q) === 0 ? 0 : 1;
			$ys = strpos(self::norm($y['name']), $q) === 0 ? 0 : 1;

			return $xs <=> $ys ?: strcmp($x['path'], $y['path']);
		});

		return ['ok' => true, 'message' => count($hits) . ' sonuç', 'items' => array_slice($hits, 0, self::SEARCH_LIMIT)];
	}

	public static function categoryPath(int $idAccount, ?int $vendor, string $remoteId): string
	{
		$all = self::categories($idAccount, $vendor);

		foreach ($all['items'] as $c) {
			if ((string) $c['id'] === $remoteId) {
				return (string) $c['path'];
			}
		}

		return '';
	}

	public static function isLeaf(int $idAccount, ?int $vendor, string $remoteId): ?bool
	{
		$all = self::categories($idAccount, $vendor);

		foreach ($all['items'] as $c) {
			if ((string) $c['id'] === $remoteId) {
				return (bool) $c['leaf'];
			}
		}

		return null;
	}

	/**
	 * Kategori özellikleri: [{id, name, required, allow_custom, varianter, values:[{id,name}]}]
	 *
	 * @return array{ok:bool, message:string, items:list<array<string,mixed>>}
	 */
	public static function attributes(int $idAccount, ?int $vendor, string $remoteCategoryId, bool $refresh = false): array
	{
		[$a, $creds, $acc] = self::ctx($idAccount, $vendor);

		if (!$a) {
			return ['ok' => false, 'message' => $acc ? 'Bu pazaryeri için özellik listesi henüz desteklenmiyor.' : 'Hesap bulunamadı.', 'items' => []];
		}

		$remoteCategoryId = preg_replace('/[^0-9A-Za-z_-]/', '', $remoteCategoryId) ?? '';

		if ($remoteCategoryId === '') {
			return ['ok' => false, 'message' => 'Kategori kodu geçersiz.', 'items' => []];
		}

		$key = $a->key() . '-attrs-' . $remoteCategoryId;
		$items = $refresh ? null : self::cacheGet($key);

		if ($items === null) {
			$r = $a->remoteAttributes($creds, $remoteCategoryId);

			if (!$r['ok']) {
				return ['ok' => false, 'message' => $r['message'], 'items' => []];
			}

			$items = $r['items'];
			self::cachePut($key, $items);
		}

		return ['ok' => true, 'message' => '', 'items' => $items];
	}

	/** @return array{ok:bool, message:string, items:list<array{id:string,name:string}>} */
	public static function searchBrands(int $idAccount, ?int $vendor, string $name): array
	{
		[$a, $creds] = self::ctx($idAccount, $vendor);
		$name = trim(strip_tags($name));

		if (!$a) {
			return ['ok' => false, 'message' => 'Bu pazaryeri için marka arama desteklenmiyor.', 'items' => []];
		}

		if (mb_strlen($name) < 2) {
			return ['ok' => false, 'message' => 'Marka adı en az 2 karakter olmalı.', 'items' => []];
		}

		return $a->remoteBrands($creds, mb_substr($name, 0, 100));
	}

	// ---- otomatik kategori eşleştirme ----

	/** @var array<string,list<array<string,mixed>>> */
	private static array $prepared = [];

	private static function matcher(): void
	{
		if (!class_exists('CategoryMatchStore')) {
			require_once dirname(__DIR__, 3) . '/core/CategoryMatchStore.php';
		}
	}

	/** @return list<array<string,mixed>> uzak ağacın eşleştirmeye hazır hâli (yalnızca en alt kategoriler seçilebilir) */
	private static function preparedRemote(int $idAccount, ?int $vendor, string $marketplace): array
	{
		if (isset(self::$prepared[$marketplace])) {
			return self::$prepared[$marketplace];
		}

		self::matcher();
		$all = self::categories($idAccount, $vendor);
		$targets = [];

		foreach ($all['items'] as $c) {
			$targets[] = ['id' => (string) $c['id'], 'path' => (string) $c['path'], 'selectable' => (bool) $c['leaf']];
		}

		return self::$prepared[$marketplace] = $all['ok'] ? CategoryMatcher::prepare($targets) : [];
	}

	public static function resetPrepared(): void
	{
		self::$prepared = [];
	}

	/**
	 * Bir site kategorisi (yolu) ve isteğe bağlı ürün adı için pazaryeri kategorisi önerir.
	 * Önce hesap sahibinin onaylı/önceki kararı (hafıza), sonra motor.
	 *
	 * @return array{id:string, path:string, score:float, confidence:string, from:string, alternatives:list<array<string,mixed>>}
	 */
	public static function suggestRemote(int $idAccount, ?int $vendor, string $sitePath, string $productName = ''): array
	{
		$acc = MhRepo::account($idAccount, $vendor);
		$none = ['id' => '', 'path' => '', 'score' => 0.0, 'confidence' => 'none', 'from' => 'none', 'alternatives' => []];

		if (!$acc || !self::supported((string) $acc['marketplace'])) {
			return $none;
		}

		self::matcher();
		$scope = 'mh:' . $acc['marketplace'];
		$owner = (int) $acc['id_vendor'];

		if ($productName === '') {
			$mem = CategoryMatchStore::recall($scope, $owner, $sitePath);

			if ($mem && $mem['confirmed']) {
				return ['id' => $mem['target_id'], 'path' => $mem['target_path'], 'score' => 1.0, 'confidence' => 'confirmed', 'from' => 'memory', 'alternatives' => []];
			}
		}

		$prep = self::preparedRemote($idAccount, $vendor, (string) $acc['marketplace']);

		if ($prep === []) {
			return $none;
		}

		$m = CategoryMatcher::match($sitePath, $productName, $prep);

		return $m['id'] === null ? $none : ['id' => (string) $m['id'], 'path' => $m['path'], 'score' => $m['score'], 'confidence' => $m['confidence'], 'from' => 'auto', 'alternatives' => $m['alternatives']];
	}

	/**
	 * Sitedeki kategorileri pazaryeri kategorilerine TEK TIKLA eşler. Elle yapılmış eşlemeye dokunmaz ($overwrite=false).
	 * Yüksek ve orta güvenli öneriler kaydedilir; düşük güvenliler kaydedilmez, öneri olarak döner.
	 *
	 * @return array{ok:bool, message:string, mapped:int, review:int, skipped:int, low:list<array<string,mixed>>, rows:list<array<string,mixed>>}
	 */
	public static function autoMapCategories(int $idAccount, ?int $vendor, bool $overwrite = false): array
	{
		$out = ['ok' => false, 'message' => '', 'mapped' => 0, 'review' => 0, 'skipped' => 0, 'low' => [], 'rows' => []];
		$acc = MhRepo::account($idAccount, $vendor);

		if (!$acc) {
			$out['message'] = 'Hesap bulunamadı.';

			return $out;
		}

		$all = self::categories($idAccount, $vendor);

		if (!$all['ok']) {
			$out['message'] = $all['message'];

			return $out;
		}

		self::matcher();
		$map = MhRepo::categoryMap($idAccount);
		$scope = 'mh:' . $acc['marketplace'];

		foreach (CategoryMatchStore::siteTargets() as $t) {
			$cid = (int) $t['id'];
			$path = implode(' > ', $t['path']);

			if (isset($map[$cid]) && !$overwrite) {
				$out['skipped']++;
				continue;
			}

			$s = self::suggestRemote($idAccount, $vendor, $path);
			$row = ['id_category' => $cid, 'site_path' => $path] + $s;

			if ($s['id'] === '' || $s['confidence'] === 'low' || $s['confidence'] === 'none') {
				$out['low'][] = $row;
				continue;
			}

			MhRepo::setCategoryMap($idAccount, $vendor, $cid, $s['id'], mb_substr($s['path'], 0, 190));

			if ($s['from'] !== 'memory') {
				CategoryMatchStore::remember($scope, (int) $acc['id_vendor'], $path, $s['id'], $s['path'], false);
			}

			$out['mapped']++;
			$out['review'] += $s['confidence'] === 'medium' ? 1 : 0;
			$out['rows'][] = $row;
		}

		$out['ok'] = true;
		$out['message'] = $out['mapped'] . ' kategori otomatik eşlendi' . ($out['review'] ? ' (' . $out['review'] . ' tanesi orta güvenli, kontrol edin)' : '') . ', ' . count($out['low']) . ' kategori için güvenli eşleşme bulunamadı' . ($out['skipped'] ? ', ' . $out['skipped'] . ' elle eşlenmiş kategoriye dokunulmadı' : '') . '.';

		return $out;
	}

	/** Elle yapılan eşleme onaylı hafızaya yazılır (sonraki otomatik eşlemelerde öncelikli). */
	public static function rememberManual(int $idAccount, ?int $vendor, int $idCategory, string $remoteId, string $remotePath): void
	{
		$acc = MhRepo::account($idAccount, $vendor);

		if (!$acc || $remoteId === '') {
			return;
		}

		self::matcher();
		$path = CategoryMatchStore::sitePath($idCategory);

		if ($path !== '') {
			CategoryMatchStore::remember('mh:' . $acc['marketplace'], (int) $acc['id_vendor'], $path, $remoteId, $remotePath, true);
		}
	}

	// ---- Hesap bazlı eşlemeler ----

	/** @return array<string,array{value_id:string,value_text:string,attribute_name:string}> attribute_id => değer */
	public static function attributeMap(int $idAccount, string $remoteCategoryId): array
	{
		$out = [];

		foreach (DB::execute('SELECT * FROM mh_attribute_map WHERE id_account = ? AND remote_category_id = ?', [$idAccount, $remoteCategoryId]) ?: [] as $r) {
			$out[(string) $r['attribute_id']] = ['value_id' => (string) $r['value_id'], 'value_text' => (string) $r['value_text'], 'attribute_name' => (string) $r['attribute_name']];
		}

		return $out;
	}

	/**
	 * Bir kategorinin özellik değerlerini kaydeder. Liste dışı değer yalnızca özellik serbest değer kabul ediyorsa saklanır.
	 *
	 * @param array<string,string> $values attribute_id => seçili value_id
	 * @param array<string,string> $custom attribute_id => serbest metin
	 * @return array{success:bool, message:string, saved:int}
	 */
	public static function saveAttributeMap(int $idAccount, ?int $vendor, string $remoteCategoryId, array $values, array $custom): array
	{
		$attrs = self::attributes($idAccount, $vendor, $remoteCategoryId);

		if (!$attrs['ok']) {
			return ['success' => false, 'message' => $attrs['message'], 'saved' => 0];
		}

		$saved = 0;
		$errors = [];

		foreach ($attrs['items'] as $at) {
			$aid = (string) $at['id'];
			$vid = trim((string) ($values[$aid] ?? ''));
			$txt = mb_substr(trim(strip_tags((string) ($custom[$aid] ?? ''))), 0, 190);
			$valueText = '';

			if ($vid !== '') {
				$found = null;

				foreach ($at['values'] as $v) {
					if ((string) $v['id'] === $vid) {
						$found = $v;
						break;
					}
				}

				if (!$found) {
					$errors[] = $at['name'] . ': listede olmayan değer.';
					continue;
				}

				$valueText = (string) $found['name'];
			} elseif ($txt !== '') {
				if (empty($at['allow_custom'])) {
					$errors[] = $at['name'] . ': serbest değer kabul etmiyor, listeden seçin.';
					continue;
				}

				$valueText = $txt;
			}

			DB::execute('DELETE FROM mh_attribute_map WHERE id_account = ? AND remote_category_id = ? AND attribute_id = ?', [$idAccount, $remoteCategoryId, $aid]);

			if ($vid === '' && $valueText === '') {
				continue;
			}

			DB::insert('mh_attribute_map', [
				'id_account' => $idAccount,
				'remote_category_id' => $remoteCategoryId,
				'attribute_id' => $aid,
				'attribute_name' => mb_substr((string) $at['name'], 0, 120),
				'value_id' => $vid,
				'value_text' => $valueText,
				'date_upd' => MhRepo::now(),
			]);
			$saved++;
		}

		return ['success' => $errors === [], 'message' => $errors === [] ? $saved . ' özellik kaydedildi.' : implode(' ', array_slice($errors, 0, 5)), 'saved' => $saved];
	}

	/**
	 * Zorunlu olup değeri girilmemiş özellikler. Varyant ekseni (renk/beden) ürün varyantından gelebileceği için $variantAxes ile hariç tutulur.
	 *
	 * @param list<array<string,mixed>> $attrs
	 * @param array<string,mixed> $map
	 * @param list<string> $variantAxes normalize edilmiş seçenek adları
	 * @return list<string>
	 */
	public static function missingRequired(array $attrs, array $map, array $variantAxes = []): array
	{
		$out = [];

		foreach ($attrs as $at) {
			if (empty($at['required']) || isset($map[(string) $at['id']])) {
				continue;
			}

			if (!empty($at['varianter']) && in_array(self::norm((string) $at['name']), $variantAxes, true)) {
				continue;
			}

			$out[] = (string) $at['name'];
		}

		return $out;
	}

	/** @return array<int,array{remote_brand_id:string,remote_brand_name:string}> */
	public static function brandMap(int $idAccount): array
	{
		$out = [];

		foreach (DB::execute('SELECT * FROM mh_brand_map WHERE id_account = ?', [$idAccount]) ?: [] as $r) {
			$out[(int) $r['id_brand']] = ['remote_brand_id' => (string) $r['remote_brand_id'], 'remote_brand_name' => (string) $r['remote_brand_name']];
		}

		return $out;
	}

	/** @return array{success:bool, message:string} */
	public static function setBrandMap(int $idAccount, ?int $vendor, int $idBrand, string $remoteId, string $remoteName = ''): array
	{
		if (!MhRepo::account($idAccount, $vendor) || $idBrand <= 0) {
			return ['success' => false, 'message' => 'Hesap veya marka bulunamadı.'];
		}

		$remoteId = trim($remoteId);
		DB::execute('DELETE FROM mh_brand_map WHERE id_account = ? AND id_brand = ?', [$idAccount, $idBrand]);

		if ($remoteId === '') {
			return ['success' => true, 'message' => 'Marka eşlemesi kaldırıldı.'];
		}

		if (!preg_match('/^[0-9A-Za-z_-]{1,60}$/', $remoteId)) {
			return ['success' => false, 'message' => 'Pazaryeri marka kodu geçersiz.'];
		}

		DB::insert('mh_brand_map', ['id_account' => $idAccount, 'id_brand' => $idBrand, 'remote_brand_id' => $remoteId, 'remote_brand_name' => mb_substr(trim(strip_tags($remoteName)), 0, 190), 'date_upd' => MhRepo::now()]);

		return ['success' => true, 'message' => 'Marka eşlendi.'];
	}

	/**
	 * Ürünün markası için pazaryeri marka kodu: önce elle eşleme, yoksa pazaryerinde birebir (büyük/küçük harf duyarsız) ad eşleşmesi.
	 * Birebir eşleşme bulunursa eşleme tablosuna yazılır (sonraki gönderimlerde tekrar sorgulanmaz).
	 *
	 * @return array{ok:bool, id:string, message:string}
	 */
	public static function resolveBrand(int $idAccount, int $idBrand, string $brandName, MhCoreAdapter $a, array $creds): array
	{
		$map = self::brandMap($idAccount);

		if (isset($map[$idBrand])) {
			return ['ok' => true, 'id' => $map[$idBrand]['remote_brand_id'], 'message' => ''];
		}

		if (trim($brandName) === '') {
			return ['ok' => false, 'id' => '', 'message' => 'Ürünün markası yok; pazaryeri marka zorunludur.'];
		}

		$r = $a->remoteBrands($creds, $brandName);

		if (!$r['ok']) {
			return ['ok' => false, 'id' => '', 'message' => 'Marka sorgulanamadı: ' . $r['message']];
		}

		foreach ($r['items'] as $b) {
			if (self::norm((string) $b['name']) === self::norm($brandName)) {
				if ($idBrand > 0) {
					DB::insert('mh_brand_map', ['id_account' => $idAccount, 'id_brand' => $idBrand, 'remote_brand_id' => (string) $b['id'], 'remote_brand_name' => mb_substr((string) $b['name'], 0, 190), 'date_upd' => MhRepo::now()]);
				}

				return ['ok' => true, 'id' => (string) $b['id'], 'message' => ''];
			}
		}

		return ['ok' => false, 'id' => '', 'message' => '"' . mb_substr($brandName, 0, 60) . '" markası ' . $a->title() . ' marka listesinde birebir bulunamadı; Özellikler sekmesinden marka kodunu elle eşleyin.'];
	}

	public static function norm(string $s): string
	{
		$s = mb_strtolower(trim($s), 'UTF-8');
		$s = strtr($s, ['ı' => 'i', 'i̇' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);

		return preg_replace('/\s+/', ' ', $s) ?? $s;
	}
}
