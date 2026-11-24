<?php

/** Tek ürün satırının işlenmesi: eşleştirme → yinelenen/değişiklik denetimi → ekleme/güncelleme → geçmiş ve hata kaydı. */
class XhImporter
{
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

	/** Önizleme: yazmadan, ürünün ne olacağını hesaplar. @return array<string,mixed> */
	public static function preview(array $conn, array $raw): array
	{
		$opt = XhRepo::options($conn);
		$row = XhMapper::apply($raw, XhRepo::mapping($conn), $opt, [self::class, 'lookup']);
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
		$row = XhMapper::apply($raw, XhRepo::mapping($conn), $opt, [self::class, 'lookup']);
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

			if ($row['images'] !== []) {
				$im = XhSink::addImages($idVendor, $res['id'], $row['images'], (int) $opt['max_images']);

				foreach ($im['warnings'] as $w) {
					XhRepo::logError($idConn, $idRun, $key, $w, 'warning');
				}
			}

			XhRepo::saveItem($idConn, $key, ['id_product' => $res['id'], 'row_hash' => $row['hash'], 'data' => $rawJson, 'state' => 'imported', 'error' => '', 'attempts' => 0, 'last_run_id' => $idRun]);

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

		if ($row['variants'] !== [] && ($opt['sync_price'] || $opt['sync_stock'])) {
			$changed = XhSink::syncVariants($idVendor, $id, $row['variants'], (bool) $opt['sync_price'], (bool) $opt['sync_stock']) > 0 || $changed;
		}

		if ($opt['sync_description'] && $row['description'] !== '') {
			$r = XhSink::updateContent($idVendor, $id, (string) $row['description'], (string) $row['short_description']);

			if (!$r['success']) {
				return ['changed' => $changed, 'error' => 'Açıklama güncellenemedi: ' . $r['message']];
			}

			$changed = true;
		}

		// Görsel: yalnızca ürünün hiç görseli yoksa eklenir (mevcut görseller ASLA silinmez/değiştirilmez)
		if ($opt['sync_images'] && $row['images'] !== [] && !XhSink::hasImages($id)) {
			$im = XhSink::addImages($idVendor, $id, $row['images'], (int) $opt['max_images']);

			foreach ($im['warnings'] as $w) {
				XhRepo::logError($idConn, $idRun, (string) $row['key'], $w, 'warning');
			}

			$changed = $im['added'] > 0 || $changed;
		}

		return ['changed' => $changed, 'error' => ''];
	}
}
