<?php

/** Tek ürün satırının işlenmesi: eşleştirme → yinelenen/değişiklik denetimi → ekleme/güncelleme → geçmiş ve hata kaydı. */
class XhImporter
{
	/** Son otomatik kategori kararı (yeni ürün kaydedilince kontrol listesine yazmak için). @var array<string,mixed>|null */
	public static ?array $lastAuto = null;

	/** Otomatik eşleştirme açık mı? (bağlantı seçeneği; varsayılan açık) */
	public static function autoEnabled(array $opt): bool
	{
		return !array_key_exists('auto_category', $opt) || !empty($opt['auto_category']);
	}

	/**
	 * Bağlantıya özel eşleştirme geri çağrısı: tam ad → otomatik kategori (motor + hafıza + yedek) → marka açma.
	 */
	public static function lookupFor(int $idVendor, array $opt): callable
	{
		self::$lastAuto = null;
		$auto = self::autoEnabled($opt);

		return static function (string $kind, string $text, string $name = '') use ($idVendor, $auto): int {
			if ($kind === 'category' || $kind === 'brand') {
				return self::lookup($kind, $text);
			}

			if (!$auto) {
				return 0;
			}

			if (!class_exists('CategoryMatchStore')) {
				require_once dirname(__DIR__, 3) . '/core/CategoryMatchStore.php';
			}

			if ($kind === 'category_auto' || $kind === 'category_text') {
				$r = CategoryMatchStore::resolveSite($idVendor, $text, $name);

				if ($kind === 'category_text' && (in_array($r['from'], ['fallback', 'none'], true) || $r['confidence'] === 'low')) {
					return 0;
				}

				self::$lastAuto = $r + ['source_text' => $text, 'product_name' => $name];

				return (int) $r['id_category'];
			}

			if ($kind === 'brand_auto') {
				return (int) CategoryMatchStore::brandId($text)['id'];
			}

			return 0;
		};
	}

	/** @return int marka/kategori adına göre (büyük/küçük harf duyarsız, tam ad) aktif kayıt ID'si */
	public static function lookup(string $kind, string $text): int
	{
		$text = trim($text);

		if ($text === '') {
			return 0;
		}

		$sql = $kind === 'brand'
			? 'SELECT id_brand AS id FROM brands WHERE LOWER(brand_name) = LOWER(?) AND active = 1 LIMIT 1'
			: 'SELECT id_category AS id FROM categories WHERE LOWER(category_name) = LOWER(?) AND active = 1 LIMIT 1';
		$r = DB::execute($sql, [$text]);

		return $r && isset($r[0]) ? (int) $r[0]['id'] : 0;
	}

	/**
	 * Marka/kategori eşleştirme yardımcısı: kayıtlı satırlarda (xh_items.data) ne eşleme tablosunda ne de ada göre bulunabilen
	 * tedarikçi marka/kategori adlarını sayar. @return array{brand:array<string,int>, category:array<string,int>}
	 */
	public static function unmapped(array $conn, int $limit = 5000): array
	{
		$mapping = XhRepo::mapping($conn);
		$fields = is_array($mapping['fields'] ?? null) ? $mapping['fields'] : [];
		$out = ['brand' => [], 'category' => []];
		$rows = DB::execute('SELECT data FROM xh_items WHERE id_connection = ? AND data IS NOT NULL AND data <> \'\' LIMIT ' . max(1, min(20000, $limit)), [(int) $conn['id_connection']]) ?: [];

		foreach ($rows as $r) {
			$raw = json_decode((string) $r['data'], true);

			if (!is_array($raw)) {
				continue;
			}

			foreach (['brand', 'category'] as $kind) {
				$src = (string) ($fields[$kind] ?? '');

				if ($src === '' || $src[0] === '=') {
					continue;
				}

				$v = XhMapper::pick($raw, $src);
				$text = is_array($v) ? '' : trim((string) $v);

				if ($text === '' || isset(($mapping[$kind . '_map'] ?? [])[$text]) || self::lookup($kind, $text) > 0) {
					continue;
				}

				$out[$kind][$text] = ($out[$kind][$text] ?? 0) + 1;
			}
		}

		foreach ($out as $k => $list) {
			arsort($list);
			$out[$k] = array_slice($list, 0, 200, true);
		}

		return $out;
	}

	/** Önizleme: yazmadan, ürünün ne olacağını hesaplar. @return array<string,mixed> */
	public static function preview(array $conn, array $raw): array
	{
		$opt = XhRepo::options($conn);
		$row = XhMapper::apply($raw, XhRepo::mapping($conn), $opt, self::lookupFor((int) $conn['id_vendor'], $opt));
		$row['category_auto'] = self::$lastAuto;
		$idVendor = (int) $conn['id_vendor'];
		$existing = $row['errors'] === [] ? self::resolveExisting($conn, $row) : 0;
		$row['action'] = $row['errors'] !== [] ? 'error' : ($existing > 0 ? 'update' : 'create');
		$row['id_product'] = $existing;

		return $row;
	}

	private static function resolveExisting(array $conn, array $row): int
	{
		$idVendor = (int) $conn['id_vendor'];
		$link = XhRepo::item((int) $conn['id_connection'], (string) $row['key']);

		if ($link && (int) $link['id_product'] > 0 && XhOwner::owns($idVendor, (int) $link['id_product'])) {
			return (int) $link['id_product'];
		}

		return XhSink::find($idVendor, (string) $row['sku'], (string) $row['barcode']);
	}

	/**
	 * @param array<string,mixed> $conn
	 * @param array<string,mixed> $run
	 * @param array<string,mixed> $raw
	 * @return array{outcome:string, key:string}  outcome: created|updated|unchanged|skipped|failed|filtered
	 */
	public static function process(array $conn, array $run, array $raw, ?array $selected = null): array
	{
		$idConn = (int) $conn['id_connection'];
		$idRun = (int) $run['id_run'];
		$idVendor = (int) $conn['id_vendor'];
		$kind = (string) $run['kind'];
		$opt = XhRepo::options($conn);
		$row = XhMapper::apply($raw, XhRepo::mapping($conn), $opt, self::lookupFor($idVendor, $opt));
		$autoCat = self::$lastAuto;
		$key = (string) $row['key'];

		if ($key === '') {
			XhRepo::logError($idConn, $idRun, '', 'Satır atlandı: ' . implode(' ', $row['errors']));

			return ['outcome' => 'failed', 'key' => ''];
		}

		if ($selected !== null && !isset($selected[$key])) {
			return ['outcome' => 'filtered', 'key' => $key];
		}

		$item = XhRepo::item($idConn, $key);
		$rawJson = (string) json_encode($raw, JSON_UNESCAPED_UNICODE);
		$rawJson = strlen($rawJson) > 60000 ? '' : $rawJson;

		if ($item && (int) $item['last_run_id'] === $idRun) {
			XhRepo::logError($idConn, $idRun, $key, 'Yinelenen ürün (aynı anahtar bu içe aktarmada ikinci kez): ilk kayıt korundu.', 'warning');

			return ['outcome' => 'skipped', 'key' => $key];
		}

		$fail = static function (string $msg) use ($idConn, $idRun, $key, $rawJson, $item): array {
			XhRepo::saveItem($idConn, $key, ['data' => $rawJson, 'state' => 'failed', 'error' => mb_substr($msg, 0, 255), 'attempts' => (int) ($item['attempts'] ?? 0) + 1, 'last_run_id' => $idRun]);
			XhRepo::logError($idConn, $idRun, $key, $msg);

			return ['outcome' => 'failed', 'key' => $key];
		};

		if ($row['errors'] !== []) {
			return $fail(implode(' ', $row['errors']));
		}

		try {
			$id = self::resolveExisting($conn, $row);

			if ($id > 0) {
				if (!$opt['update_existing']) {
					XhRepo::saveItem($idConn, $key, ['id_product' => $id, 'state' => 'skipped', 'error' => 'Mevcut ürün; güncelleme kapalı.', 'last_run_id' => $idRun, 'row_hash' => $row['hash'], 'data' => $rawJson]);

					return ['outcome' => 'skipped', 'key' => $key];
				}

				if ($item && (string) $item['row_hash'] === $row['hash'] && (int) $item['id_product'] === $id && in_array($item['state'], ['imported', 'updated', 'unchanged'], true)) {
					XhRepo::saveItem($idConn, $key, ['state' => 'unchanged', 'last_run_id' => $idRun]);

					return ['outcome' => 'unchanged', 'key' => $key];
				}

				$changed = self::update($conn, $idRun, $id, $row, $opt);

				if ($changed['error'] !== '') {
					return $fail($changed['error']);
				}

				XhRepo::saveItem($idConn, $key, ['id_product' => $id, 'row_hash' => $row['hash'], 'data' => $rawJson, 'state' => $changed['changed'] ? 'updated' : 'unchanged', 'error' => '', 'attempts' => 0, 'last_run_id' => $idRun]);

				return ['outcome' => $changed['changed'] ? 'updated' : 'unchanged', 'key' => $key];
			}

			if ($kind === 'sync' || !$opt['create_new']) {
				XhRepo::saveItem($idConn, $key, ['state' => 'skipped', 'error' => 'Yeni ürün; bu işlemde eklenmez.', 'last_run_id' => $idRun, 'row_hash' => $row['hash'], 'data' => $rawJson]);

				return ['outcome' => 'skipped', 'key' => $key];
			}

			$res = XhSink::create($idVendor, $row);

			if (!$res['success']) {
				return $fail($res['message']);
			}

			$imported = [];

			if ($row['images'] !== []) {
				$im = XhSink::addImages($idVendor, $res['id'], $row['images'], (int) $opt['max_images']);
				$imported = $im['done'];

				foreach ($im['warnings'] as $w) {
					XhRepo::logError($idConn, $idRun, $key, $w, 'warning');
				}
			}

			XhMeta::save($res['id'], $idVendor, [
				'source_type' => 'xml', 'id_connection' => $idConn, 'id_supplier' => (int) $conn['id_supplier'],
				'cargo_price' => !empty($opt['sync_cargo']) ? $row['cargo_price'] : null,
				'desc_hash' => XhMeta::fingerprint($row['description'] . '|' . $row['short_description']),
				'image_urls' => json_encode($imported),
			]);

			XhRepo::saveItem($idConn, $key, ['id_product' => $res['id'], 'row_hash' => $row['hash'], 'data' => $rawJson, 'state' => 'imported', 'error' => '', 'attempts' => 0, 'last_run_id' => $idRun]);

			if ($autoCat && (int) $autoCat['id_category'] === (int) $row['id_category'] && class_exists('CategoryMatchStore', false)) {
				CategoryMatchStore::queueReview((int) $res['id'], $idVendor, 'xml', (string) $autoCat['source_text'], (string) $autoCat['product_name'], $autoCat);
			}

			return ['outcome' => 'created', 'key' => $key];
		} catch (Throwable $e) {
			error_log('xml-hub item: ' . $e->getMessage());

			return $fail('Beklenmeyen hata: ' . mb_substr($e->getMessage(), 0, 150));
		}
	}

	/** @return array{changed:bool, error:string} */
	private static function update(array $conn, int $idRun, int $id, array $row, array $opt): array
	{
		$idVendor = (int) $conn['id_vendor'];
		$idConn = (int) $conn['id_connection'];
		$cur = XhSink::current($id);

		if (!$cur) {
			return ['changed' => false, 'error' => 'Ürün bulunamadı.'];
		}

		$changed = false;
		$newPrice = $opt['sync_price'] && $row['price'] > 0 && abs($row['price'] - $cur['price']) > 0.004 ? (float) $row['price'] : null;
		$newStock = $opt['sync_stock'] && $row['stock'] !== null && abs((float) $row['stock'] - $cur['stock']) > 0.0004 ? (float) $row['stock'] : null;

		if ($newPrice !== null || $newStock !== null) {
			$r = XhSink::updatePriceStock($idVendor, $id, $newPrice, $newStock);

			if (!$r['success']) {
				return ['changed' => false, 'error' => $r['message']];
			}

			if ($newPrice !== null) {
				XhRepo::addHistory('price', $id, $idConn, $idRun, $cur['price'], $newPrice);
			}

			if ($newStock !== null) {
				XhRepo::addHistory('stock', $id, $idConn, $idRun, $cur['stock'], $newStock);
			}

			$changed = true;
		}

		$meta = XhMeta::get($id);
		$metaPatch = [];

		if ($row['variants'] !== [] && ($opt['sync_price'] || $opt['sync_stock'] || !empty($opt['create_variants']))) {
			$newVar = 0;
			$n = XhSink::syncVariants($idVendor, $id, $row['variants'], (bool) $opt['sync_price'], (bool) $opt['sync_stock'], !empty($opt['create_variants']), $newVar);
			$changed = $n > 0 || $changed;

			if ($newVar > 0) {
				XhMeta::addChange($id, $idConn, $idRun, 'variant', $newVar . ' yeni varyant eklendi.');
			}
		}

		// Açıklama değişikliği: yalnızca içerik parmak izi değiştiyse yazılır
		if ($opt['sync_description'] && $row['description'] !== '') {
			$fp = XhMeta::fingerprint($row['description'] . '|' . $row['short_description']);

			if (!$meta || (string) $meta['desc_hash'] !== $fp) {
				$r = XhSink::updateContent($idVendor, $id, (string) $row['description'], (string) $row['short_description']);

				if (!$r['success']) {
					return ['changed' => $changed, 'error' => 'Açıklama güncellenemedi: ' . $r['message']];
				}

				$metaPatch['desc_hash'] = $fp;
				XhMeta::addChange($id, $idConn, $idRun, 'description', 'Açıklama tedarikçi verisine göre güncellendi.');
				$changed = true;
			}
		}

		// Görsel: mevcut görseller ASLA silinmez/değiştirilmez. if_empty = yalnızca hiç görseli yoksa; add_new = daha önce alınmamış yeni adresleri ekler
		if ($opt['sync_images'] && $row['images'] !== []) {
			$known = $meta && (string) $meta['image_urls'] !== '' ? (array) json_decode((string) $meta['image_urls'], true) : [];
			$todo = [];

			if (($opt['image_mode'] ?? 'if_empty') === 'add_new') {
				$todo = array_values(array_diff($row['images'], $known));
			} elseif (!XhSink::hasImages($id)) {
				$todo = $row['images'];
			}

			if ($todo !== []) {
				$im = XhSink::addImages($idVendor, $id, $todo, (int) $opt['max_images']);

				foreach ($im['warnings'] as $w) {
					XhRepo::logError($idConn, $idRun, (string) $row['key'], $w, 'warning');
				}

				if ($im['added'] > 0) {
					$metaPatch['image_urls'] = json_encode(array_values(array_unique(array_merge($known, $im['done']))));
					XhMeta::addChange($id, $idConn, $idRun, 'images', $im['added'] . ' görsel eklendi.');
					$changed = true;
				}
			}
		}

		// Kargo ücreti çekirdek ürün tablosunda alan olmadığı için modülün kendi tablosunda (xh_product_meta) tutulur
		if (!empty($opt['sync_cargo']) && $row['cargo_price'] !== null) {
			$old = $meta && $meta['cargo_price'] !== null ? (float) $meta['cargo_price'] : null;

			if ($old === null || abs($old - (float) $row['cargo_price']) > 0.004) {
				$metaPatch['cargo_price'] = (float) $row['cargo_price'];
				XhMeta::addChange($id, $idConn, $idRun, 'cargo', ($old === null ? '—' : number_format($old, 2, '.', '')) . ' → ' . number_format((float) $row['cargo_price'], 2, '.', ''));
				$changed = true;
			}
		}

		if (!$meta) {
			$metaPatch += ['source_type' => 'xml', 'id_connection' => $idConn, 'id_supplier' => (int) $conn['id_supplier']];
		}

		if ($metaPatch !== []) {
			XhMeta::save($id, $idVendor, $metaPatch);
		}

		return ['changed' => $changed, 'error' => ''];
	}
}
