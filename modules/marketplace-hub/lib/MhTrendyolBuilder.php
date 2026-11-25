<?php

/**
 * Tarafsız ürün verisinden (MhPayload) Trendyol "ürün oluşturma" (v2 createProduct) kalemlerini üretir.
 * Saf fonksiyon: ağ çağrısı yapmaz; kategori özellikleri ve eşlemeler dışarıdan verilir (test edilebilir).
 * Alan seçimi çekirdeğin ProductSyncService::buildCreateItem() yapısını izler.
 */
class MhTrendyolBuilder
{
	public const MAX_IMAGES = 8;

	/**
	 * @param array<string,mixed> $p MhPayload payload
	 * @param array{brand_id:int, category_id:int, attrs:list<array<string,mixed>>, attr_map:array<string,array{value_id:string,value_text:string}>, base_url:string, cargo_company_id?:int} $ctx
	 * @return array{ok:bool, message:string, items:list<array<string,mixed>>}
	 */
	public static function build(array $p, array $ctx): array
	{
		$fail = static fn(string $m): array => ['ok' => false, 'message' => $m, 'items' => []];
		$title = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($p['name'] ?? ''))) ?? '');

		if ($title === '') {
			return $fail('Ürün adı boş.');
		}

		if ((int) ($ctx['brand_id'] ?? 0) <= 0) {
			return $fail('Trendyol marka kodu yok.');
		}

		if ((int) ($ctx['category_id'] ?? 0) <= 0) {
			return $fail('Trendyol kategori kodu sayısal olmalı.');
		}

		$images = self::images((array) ($p['images'] ?? []), (string) ($ctx['base_url'] ?? ''));

		if ($images === []) {
			return $fail('En az bir herkese açık ürün görseli gerekli (localhost/iç adres kabul edilmez).');
		}

		$attrs = (array) ($ctx['attrs'] ?? []);
		$map = (array) ($ctx['attr_map'] ?? []);
		$variants = array_values(array_filter((array) ($p['variants'] ?? []), 'is_array'));
		$axes = [];

		foreach ($variants as $v) {
			foreach ((array) ($v['options'] ?? []) as $name => $_) {
				$axes[MhCatalog::norm((string) $name)] = true;
			}
		}

		$missing = MhCatalog::missingRequired($attrs, $map, array_keys($axes));

		if ($missing !== []) {
			return $fail('Zorunlu özellik(ler) girilmemiş: ' . implode(', ', array_slice($missing, 0, 8)) . '. Özellikler sekmesinden doldurun.');
		}

		$base = [];

		foreach ($map as $aid => $m) {
			if ((string) ($m['value_id'] ?? '') !== '') {
				$base[] = ['attributeId' => (int) $aid, 'attributeValueId' => (int) $m['value_id']];
			} elseif ((string) ($m['value_text'] ?? '') !== '') {
				$base[] = ['attributeId' => (int) $aid, 'customAttributeValue' => mb_substr((string) $m['value_text'], 0, 50)];
			}
		}

		$vat = (int) round((float) ($p['vat'] ?? 20));
		$vat = in_array($vat, [0, 1, 10, 20], true) ? $vat : 20;
		$cargoDay = (int) ($p['cargo_day'] ?? 0);
		$sku = trim((string) ($p['sku'] ?? ''));
		$mainId = mb_substr($sku !== '' ? $sku : 'RF-' . (int) ($p['id_product'] ?? 0), 0, 40);
		$desc = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($p['description'] ?? ''))) ?? '');
		$common = [
			'title' => mb_substr($title, 0, 100),
			'productMainId' => $mainId,
			'brandId' => (int) $ctx['brand_id'],
			'categoryId' => (int) $ctx['category_id'],
			'dimensionalWeight' => max(1, (int) ($p['desi'] ?? 1)),
			'description' => mb_substr($desc !== '' ? $desc : $title, 0, 30000),
			'currencyType' => 'TRY',
			'vatRate' => $vat,
			'images' => $images,
			'deliveryOption' => ['deliveryDuration' => $cargoDay <= 0 ? 0 : min(3, $cargoDay)],
		];

		if ((int) ($ctx['cargo_company_id'] ?? 0) > 0) {
			$common['cargoCompanyId'] = (int) $ctx['cargo_company_id'];
		}

		$basePrice = (float) ($p['price'] ?? 0);
		$baseList = max($basePrice, (float) ($p['old_price'] ?? 0));
		$units = [];

		if ($variants === []) {
			$units[] = ['barcode' => (string) ($p['barcode'] ?? ''), 'sku' => $sku, 'price' => $basePrice, 'list' => $baseList, 'stock' => (float) ($p['stock'] ?? 0), 'options' => [], 'label' => ''];
		} else {
			foreach ($variants as $i => $v) {
				$vp = isset($v['price']) && $v['price'] !== null && (float) $v['price'] > 0 ? (float) $v['price'] : $basePrice;
				$label = implode(' / ', array_map('strval', (array) ($v['options'] ?? []))) ?: '#' . ($i + 1);
				$units[] = ['barcode' => (string) ($v['barcode'] ?? ''), 'sku' => (string) ($v['sku'] ?? ''), 'price' => $vp, 'list' => max($vp, $baseList), 'stock' => (float) ($v['stock'] ?? 0), 'options' => (array) ($v['options'] ?? []), 'label' => $label];
			}
		}

		$items = [];
		$errors = [];
		$seen = [];

		foreach ($units as $u) {
			$barcode = preg_replace('/\s+/', '', trim($u['barcode'])) ?? '';
			$label = $u['label'] !== '' ? $u['label'] . ': ' : '';

			if ($barcode === '') {
				// Uydurma barkod üretilmez: Trendyol barkodu ürünün kimliğidir ve sonradan değiştirilemez.
				$errors[] = $label . 'barkod yok.';
				continue;
			}

			if (isset($seen[$barcode])) {
				$errors[] = $label . 'barkod tekrarı (' . $barcode . ').';
				continue;
			}

			if ($u['price'] <= 0) {
				$errors[] = $label . 'fiyat geçersiz.';
				continue;
			}

			$attributes = $u['options'] !== [] ? self::mergeVariant($base, $attrs, $u['options']) : $base;
			$have = array_map(static fn(array $r): int => (int) $r['attributeId'], $attributes);
			$lack = [];

			foreach ($attrs as $at) {
				if (!empty($at['required']) && !in_array((int) $at['id'], $have, true)) {
					$lack[] = (string) $at['name'];
				}
			}

			if ($lack !== []) {
				$errors[] = $label . implode(', ', $lack) . ' değeri Trendyol listesiyle eşleşmedi.';
				continue;
			}

			$seen[$barcode] = true;
			$items[] = [
				'barcode' => mb_substr($barcode, 0, 40),
				'stockCode' => mb_substr($u['sku'] !== '' ? $u['sku'] : $barcode, 0, 100),
				'quantity' => max(0, (int) $u['stock']),
				'listPrice' => round(max($u['list'], $u['price']), 2),
				'salePrice' => round($u['price'], 2),
				'attributes' => $attributes,
			] + $common;
		}

		if ($errors !== []) {
			return $fail('Gönderilemez: ' . implode(' ', array_slice($errors, 0, 6)));
		}

		return ['ok' => true, 'message' => count($items) . ' kalem hazır.', 'items' => $items];
	}

	/**
	 * @param list<mixed> $urls
	 * @return list<array{url:string}>
	 */
	public static function images(array $urls, string $baseUrl): array
	{
		$out = [];

		foreach ($urls as $u) {
			$u = trim((string) $u);

			if ($u === '') {
				continue;
			}

			if (!preg_match('~^https?://~i', $u)) {
				$u = rtrim($baseUrl, '/') . '/' . ltrim($u, '/');
			}

			$host = strtolower((string) parse_url($u, PHP_URL_HOST));

			if (!preg_match('~^https?://~i', $u) || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || substr($host, -6) === '.local' || substr($host, -5) === '.test' || filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				continue;
			}

			$out[$u] = ['url' => $u];

			if (count($out) >= self::MAX_IMAGES) {
				break;
			}
		}

		return array_values($out);
	}

	/**
	 * Varyant seçeneklerini (Renk: Kırmızı, Beden: M) kategori özelliğine çevirir. Özellik listede değer olarak varsa kodu,
	 * yoksa ve serbest değer kabul ediyorsa metni kullanır. Eşleşmeyen eksen eklenmez.
	 *
	 * @param list<array<string,mixed>> $base
	 * @param list<array<string,mixed>> $attrs
	 * @param array<string,mixed> $options
	 * @return list<array<string,mixed>>
	 */
	public static function mergeVariant(array $base, array $attrs, array $options): array
	{
		$out = $base;

		foreach ($options as $name => $value) {
			$n = MhCatalog::norm((string) $name);
			$val = trim((string) $value);

			if ($n === '' || $val === '') {
				continue;
			}

			$target = null;

			foreach ($attrs as $at) {
				if (MhCatalog::norm((string) $at['name']) === $n) {
					$target = $at;
					break;
				}
			}

			if (!$target) {
				continue;
			}

			$aid = (int) $target['id'];
			$row = null;

			foreach ((array) ($target['values'] ?? []) as $v) {
				if (MhCatalog::norm((string) $v['name']) === MhCatalog::norm($val)) {
					$row = ['attributeId' => $aid, 'attributeValueId' => (int) $v['id']];
					break;
				}
			}

			if (!$row && !empty($target['allow_custom'])) {
				$row = ['attributeId' => $aid, 'customAttributeValue' => mb_substr($val, 0, 50)];
			}

			if (!$row) {
				continue;
			}

			$out = array_values(array_filter($out, static fn(array $r): bool => (int) $r['attributeId'] !== $aid));
			$out[] = $row;
		}

		return $out;
	}
}
