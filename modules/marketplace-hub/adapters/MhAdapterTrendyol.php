<?php

/**
 * Trendyol bağdaştırıcısı — çekirdek istemci (core/marketplace/TrendyolApi.php) üzerinden, SATICININ kendi anahtarlarıyla:
 *  - bağlantı testi, stok/fiyat güncelleme
 *  - kategori ağacı, kategori özellikleri, marka arama (salt-okunur)
 *  - ürün oluşturma (v2 createProduct) ve toplu istek (batch) sonucu sorgulama
 *
 * Canlı ürün gönderimi yönetici "MARKETPLACE_HUB_LIVE_SUBMIT" ayarını açana kadar KİLİTLİDİR: kalemler hazırlanır,
 * doğrulanır ve önizlemesi saklanır, Trendyol'a istek gönderilmez. Canlı hesapla uçtan uca doğrulanmadı.
 */
class MhAdapterTrendyol extends MhCoreAdapter
{
	public function key(): string
	{
		return 'trendyol';
	}

	public function title(): string
	{
		return 'Trendyol';
	}

	public function implemented(): bool
	{
		return true;
	}

	public function supportsCatalog(): bool
	{
		return true;
	}

	public function credentialFields(): array
	{
		return [
			'supplier_id' => self::f('Satıcı (Supplier) ID', false, true, 40),
			'api_key' => self::f('API Key', true),
			'api_secret' => self::f('API Secret', true),
			'cargo_company_id' => self::f('Kargo firması kodu (Trendyol kargo ID; boşsa hesap varsayılanı)', false, false, 12),
		];
	}

	protected function coreFile(): string
	{
		return 'TrendyolApi.php';
	}

	protected function makeClient(array $c): object
	{
		return new \Trendyol\TrendyolApi((string) $c['supplier_id'], (string) $c['api_key'], (string) $c['api_secret']);
	}

	protected function probe(object $client): ?array
	{
		return $client->getProducts(1, 0);
	}

	protected function push(object $client, array $i): array
	{
		return [$client->updateStockPrice((string) ($i['barcode'] ?: $i['sku']), (float) $i['list_price'], (float) $i['price'], (int) $i['stock'], (string) $i['sku'])];
	}

	/** PHP 7.4 uyumlu array_is_list. @param array<mixed> $a */
	private static function isList(array $a): bool
	{
		return $a === [] || array_keys($a) === range(0, count($a) - 1);
	}

	// ---- katalog ----

	public function remoteCategories(array $c): array
	{
		[$cl, $err] = $this->client($c);

		if (!$cl) {
			return ['ok' => false, 'message' => $err, 'items' => []];
		}

		try {
			$r = $cl->getCategories();
		} catch (Throwable $e) {
			return ['ok' => false, 'message' => 'Trendyol kategori listesi alınamadı: ' . self::scrub($e->getMessage(), $c), 'items' => []];
		}

		[$bad, $msg] = self::failure($r);
		$roots = is_array($r) ? ($r['categories'] ?? (self::isList($r) ? $r : [])) : [];

		if ($bad || !is_array($roots) || $roots === []) {
			return ['ok' => false, 'message' => 'Trendyol kategori listesi alınamadı' . ($msg !== '' ? ': ' . $msg : '.'), 'items' => []];
		}

		$out = [];
		$walk = static function (array $nodes, array $path) use (&$walk, &$out): void {
			foreach ($nodes as $n) {
				if (!is_array($n) || !isset($n['id'])) {
					continue;
				}

				$name = trim((string) ($n['name'] ?? ''));
				$p = array_merge($path, [$name]);
				$subs = is_array($n['subCategories'] ?? null) ? $n['subCategories'] : [];
				$out[] = ['id' => (string) (int) $n['id'], 'name' => $name, 'path' => implode(' > ', $p), 'leaf' => $subs === []];

				if ($subs !== [] && count($p) < 10) {
					$walk($subs, $p);
				}
			}
		};
		$walk($roots, []);

		return ['ok' => $out !== [], 'message' => count($out) . ' kategori', 'items' => $out];
	}

	public function remoteAttributes(array $c, string $remoteCategoryId): array
	{
		[$cl, $err] = $this->client($c);

		if (!$cl) {
			return ['ok' => false, 'message' => $err, 'items' => []];
		}

		try {
			$r = $cl->getAttirupes((int) $remoteCategoryId);
		} catch (Throwable $e) {
			return ['ok' => false, 'message' => 'Trendyol özellik listesi alınamadı: ' . self::scrub($e->getMessage(), $c), 'items' => []];
		}

		[$bad, $msg] = self::failure($r);

		if ($bad || !is_array($r)) {
			return ['ok' => false, 'message' => 'Trendyol özellik listesi alınamadı' . ($msg !== '' ? ': ' . $msg : '.'), 'items' => []];
		}

		$items = [];

		foreach ((array) ($r['categoryAttributes'] ?? []) as $row) {
			$aid = (int) ($row['attribute']['id'] ?? 0);

			if ($aid <= 0) {
				continue;
			}

			$values = [];

			foreach ((array) ($row['attributeValues'] ?? []) as $v) {
				$vid = (int) ($v['id'] ?? $v['attributeValueId'] ?? 0);

				if ($vid > 0) {
					$values[] = ['id' => (string) $vid, 'name' => (string) ($v['name'] ?? $v['attributeValue'] ?? '')];
				}
			}

			$items[] = [
				'id' => (string) $aid,
				'name' => (string) ($row['attribute']['name'] ?? ''),
				'required' => !empty($row['required']) || !empty($row['mandatory']),
				'allow_custom' => !empty($row['allowCustom']),
				'varianter' => !empty($row['varianter']) || !empty($row['slicer']),
				'values' => $values,
			];
		}

		return ['ok' => true, 'message' => count($items) . ' özellik', 'items' => $items];
	}

	public function remoteBrands(array $c, string $name): array
	{
		[$cl, $err] = $this->client($c);

		if (!$cl) {
			return ['ok' => false, 'message' => $err, 'items' => []];
		}

		try {
			$r = $cl->getBrand($name);
		} catch (Throwable $e) {
			return ['ok' => false, 'message' => self::scrub($e->getMessage(), $c), 'items' => []];
		}

		[$bad, $msg] = self::failure($r);

		if ($bad || !is_array($r)) {
			return ['ok' => false, 'message' => $msg !== '' ? $msg : 'Marka sorgulanamadı.', 'items' => []];
		}

		$list = self::isList($r) ? $r : (array) ($r['brands'] ?? []);
		$out = [];

		foreach ($list as $b) {
			if (is_array($b) && (int) ($b['id'] ?? 0) > 0) {
				$out[] = ['id' => (string) (int) $b['id'], 'name' => (string) ($b['name'] ?? '')];
			}
		}

		return ['ok' => true, 'message' => count($out) . ' marka', 'items' => array_slice($out, 0, 50)];
	}

	// ---- ürün oluşturma ----

	/**
	 * @param array<string,string> $credentials
	 * @param array<string,mixed> $payload MhPayload::build çıktısı (id_account, id_brand dahil)
	 */
	public function submit(array $credentials, array $payload): array
	{
		$fail = static fn(string $m, bool $retry = false): array => ['success' => false, 'remote_id' => '', 'error' => $m, 'retryable' => $retry];
		$idAccount = (int) ($payload['id_account'] ?? 0);
		$remoteCat = (string) ($payload['category']['remote_category_id'] ?? '');

		if ($idAccount <= 0 || !ctype_digit($remoteCat)) {
			return $fail('Trendyol kategori kodu sayısal olmalı (Kategori eşleme sekmesi).');
		}

		if (MhCatalog::isLeaf($idAccount, null, $remoteCat) === false) {
			return $fail('Seçilen Trendyol kategorisi en alt kategori değil; ürün yalnızca en alt kategoriye eklenebilir.');
		}

		$attrs = MhCatalog::attributes($idAccount, null, $remoteCat);

		if (!$attrs['ok']) {
			return $fail($attrs['message'], true);
		}

		$brand = MhCatalog::resolveBrand($idAccount, (int) ($payload['id_brand'] ?? 0), (string) ($payload['brand'] ?? ''), $this, $credentials);

		if (!$brand['ok']) {
			return $fail($brand['message']);
		}

		global $domain;
		$map = MhCatalog::attributeMap($idAccount, $remoteCat);
		$map = MhAttributeFill::fill($attrs['items'], $map, $payload);
		$built = MhTrendyolBuilder::build($payload, [
			'brand_id' => (int) $brand['id'],
			'category_id' => (int) $remoteCat,
			'attrs' => $attrs['items'],
			'attr_map' => $map,
			'base_url' => (string) ($domain ?? ''),
			'cargo_company_id' => (int) ($credentials['cargo_company_id'] ?? 0),
		]);

		if (!$built['ok']) {
			return $fail($built['message']);
		}

		if (!self::liveSubmitEnabled()) {
			return ['success' => false, 'dry_run' => true, 'preview' => $built['items'], 'remote_id' => '', 'error' => 'Gönderime hazır: ' . $built['message'] . ' Canlı gönderim kilidi kapalı olduğu için Trendyol\'a gönderilmedi.', 'retryable' => false];
		}

		[$cl, $err] = $this->client($credentials);

		if (!$cl) {
			return $fail($err, true);
		}

		try {
			$r = $cl->createProduct(['items' => $built['items']]);
		} catch (Throwable $e) {
			return $fail('Trendyol bağlantı hatası: ' . self::scrub($e->getMessage(), $credentials), true);
		}

		[$bad, $msg, $code] = self::failure($r);

		if ($bad) {
			return $fail('Trendyol reddetti: ' . $msg, $code === 0 || $code === 429 || $code >= 500);
		}

		$batch = (string) ($r['batchRequestId'] ?? '');

		if ($batch === '') {
			return $fail('Trendyol yanıtında toplu istek numarası yok.', true);
		}

		return ['success' => true, 'remote_id' => 'batch:' . mb_substr($batch, 0, 80), 'error' => '', 'retryable' => false, 'preview' => $built['items']];
	}

	public function batchStatus(array $c, string $batchId): array
	{
		[$cl, $err] = $this->client($c);

		if (!$cl) {
			return ['done' => false, 'ok' => false, 'message' => $err, 'remote_id' => ''];
		}

		try {
			$r = $cl->getBatch(rawurlencode($batchId));
		} catch (Throwable $e) {
			return ['done' => false, 'ok' => false, 'message' => self::scrub($e->getMessage(), $c), 'remote_id' => ''];
		}

		[$bad, $msg] = self::failure($r);

		if ($bad || !is_array($r)) {
			return ['done' => false, 'ok' => false, 'message' => $msg, 'remote_id' => ''];
		}

		$items = (array) ($r['items'] ?? []);
		$status = strtoupper((string) ($r['status'] ?? ''));
		$pending = false;
		$errors = [];
		$barcode = '';

		foreach ($items as $it) {
			$st = strtoupper((string) ($it['status'] ?? ''));
			$barcode = $barcode !== '' ? $barcode : (string) ($it['requestItem']['product']['barcode'] ?? $it['requestItem']['barcode'] ?? '');

			if ($st === '' || $st === 'IN_PROGRESS' || $st === 'PROCESSING') {
				$pending = true;
			} elseif ($st !== 'SUCCESS') {
				foreach ((array) ($it['failureReasons'] ?? []) as $fr) {
					$errors[] = is_array($fr) ? (string) ($fr['message'] ?? json_encode($fr, JSON_UNESCAPED_UNICODE)) : (string) $fr;
				}

				if ($errors === []) {
					$errors[] = 'Trendyol kalemi reddetti (' . $st . ').';
				}
			}
		}

		if (($pending || $items === []) && $status !== 'COMPLETED') {
			return ['done' => false, 'ok' => false, 'message' => 'Trendyol onayı sürüyor.', 'remote_id' => ''];
		}

		return $errors === []
			? ['done' => true, 'ok' => true, 'message' => 'Trendyol ürünü kabul etti.', 'remote_id' => $barcode]
			: ['done' => true, 'ok' => false, 'message' => self::clean(implode(' | ', array_unique($errors))), 'remote_id' => ''];
	}
}
