<?php

/**
 * Ürün yazma katmanı. XML Hub ürün tablolarına doğrudan yazmaz; satıcı bağlantılarında RvProducts (sahiplik + onay kuralı),
 * platform bağlantılarında FriSay Product::save kullanılır. Her güncelleme önce XhOwner::owns() ile doğrulanır.
 */
class XhSink
{
	/** @return int bulunan ürün ID'si veya 0 */
	public static function find(int $idVendor, string $sku, string $barcode): int
	{
		foreach ([['stock_code', $sku], ['barcode', $barcode]] as [$col, $val]) {
			if ($val === '') {
				continue;
			}

			if ($idVendor > 0) {
				if (!XhOwner::vendorModuleAvailable()) {
					return 0;
				}

				$r = DB::execute('SELECT p.id_product FROM products p INNER JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE vp.id_vendor = ? AND p.' . $col . ' = ? ORDER BY p.id_product ASC LIMIT 1', [$idVendor, $val]);
			} else {
				$r = DB::execute('SELECT p.id_product FROM products p WHERE p.' . $col . ' = ?' . (XhOwner::vendorTableExists() ? ' AND NOT EXISTS (SELECT 1 FROM rafimda_vendor_products vp WHERE vp.id_product = p.id_product)' : '') . ' ORDER BY p.id_product ASC LIMIT 1', [$val]);
			}

			if ($r && isset($r[0])) {
				return (int) $r[0]['id_product'];
			}
		}

		return 0;
	}

	/** @return array{price:float, stock:float}|null */
	public static function current(int $id): ?array
	{
		$r = DB::execute('SELECT price, stock FROM products WHERE id_product = ?', [$id]);

		return $r && isset($r[0]) ? ['price' => (float) $r[0]['price'], 'stock' => (float) $r[0]['stock']] : null;
	}

	/**
	 * @param array<string,mixed> $row XhMapper çıktısı
	 * @return array{success:bool, id:int, message:string}
	 */
	public static function create(int $idVendor, array $row): array
	{
		$post = [
			'product_name' => $row['name'], 'id_category' => $row['id_category'], 'id_brand' => $row['id_brand'],
			'price' => $row['price'], 'old_price' => $row['old_price'], 'stock' => $row['stock'] ?? 0,
			'barcode' => $row['barcode'], 'stock_code' => $row['sku'], 'description' => $row['description'],
			'short_description' => $row['short_description'], 'active' => 1,
		];

		if ($row['vat'] !== null) {
			$post['vat'] = $row['vat'];
		}

		if ($row['variants'] !== []) {
			$post['variations_present'] = 1;
			$post['variations'] = array_map(static fn(array $v): array => $v + ['active' => '1'], $row['variants']);
		}

		if ($idVendor > 0) {
			if (!XhOwner::vendorModuleAvailable()) {
				return ['success' => false, 'id' => 0, 'message' => 'Satıcı modülü yüklü değil.'];
			}

			$res = RvProducts::create($idVendor, $post);

			if (empty($res['success'])) {
				$detail = !empty($res['errors']) ? implode(' ', $res['errors']) : (string) $res['message'];

				return ['success' => false, 'id' => 0, 'message' => $detail];
			}

			$id = (int) $res['id'];
		} else {
			$res = Product::save($post, 0);

			if (empty($res['success']) || (int) ($res['id'] ?? 0) <= 0) {
				return ['success' => false, 'id' => 0, 'message' => (string) ($res['message'] ?? 'Ürün kaydedilemedi.')];
			}

			$id = (int) $res['id'];
		}

		$extra = [];

		if ($row['desi'] !== null) {
			$extra['desi'] = max(1, (int) $row['desi']);
		}

		if ($row['cargo_day'] !== null) {
			$extra['cargo_day'] = (int) $row['cargo_day'];
		}

		if ($extra !== [] && XhOwner::owns($idVendor, $id)) {
			DB::update('products', $extra, 'id_product = :pid', ['pid' => $id]);
		}

		return ['success' => true, 'id' => $id, 'message' => 'Ürün eklendi.'];
	}

	/**
	 * Fiyat/stok güncelleme. İkisinden biri null ise mevcut değer korunur.
	 *
	 * @return array{success:bool,message:string}
	 */
	public static function updatePriceStock(int $idVendor, int $id, ?float $price, ?float $stock): array
	{
		if (!XhOwner::owns($idVendor, $id)) {
			return ['success' => false, 'message' => 'Ürün bu hesaba ait değil.'];
		}

		$cur = self::current($id);

		if (!$cur) {
			return ['success' => false, 'message' => 'Ürün bulunamadı.'];
		}

		$p = $price ?? $cur['price'];
		$s = $stock ?? $cur['stock'];

		if ($idVendor > 0) {
			return RvProducts::quickUpdate($idVendor, $id, $p, $s);
		}

		if ($p <= 0 || $s < 0) {
			return ['success' => false, 'message' => 'Geçersiz fiyat/stok.'];
		}

		if (DB::update('products', ['price' => $p, 'stock' => $s], 'id_product = :pid', ['pid' => $id]) === false) {
			return ['success' => false, 'message' => 'Güncellenemedi.'];
		}

		if (class_exists('Module')) {
			Module::runHook('product.updated', [$id, Product::getByIdAdmin($id) ?: [], false]);
		}

		return ['success' => true, 'message' => 'ok'];
	}

	/** Açıklama güncelleme (HTML temizliğini Product::save yapar). @return array{success:bool,message:string} */
	public static function updateContent(int $idVendor, int $id, string $description, string $short): array
	{
		if (!XhOwner::owns($idVendor, $id)) {
			return ['success' => false, 'message' => 'Ürün bu hesaba ait değil.'];
		}

		$existing = Product::getByIdAdmin($id);

		if (!$existing) {
			return ['success' => false, 'message' => 'Ürün bulunamadı.'];
		}

		foreach (['images', 'variations', 'moderation', 'moderation_note', 'brand_name', 'brand_link', 'category_name', 'category_link'] as $k) {
			unset($existing[$k]);
		}

		$res = Product::save(array_merge($existing, ['description' => $description, 'short_description' => $short !== '' ? $short : (string) ($existing['short_description'] ?? '')]), $id);

		return ['success' => !empty($res['success']), 'message' => (string) ($res['message'] ?? '')];
	}

	/** Varyant stok/fiyat eşitleme (SKU ile eşleşenler). Dönüş: güncellenen varyant sayısı. */
	public static function syncVariants(int $idVendor, int $id, array $variants, bool $price, bool $stock, bool $create = false, ?int &$created = null): int
	{
		if (!XhOwner::owns($idVendor, $id) || !class_exists('ProductVariation') || !method_exists('ProductVariation', 'getByProductSku')) {
			return 0;
		}

		$n = 0;
		$created = 0;

		foreach ($variants as $v) {
			if (($v['sku'] ?? '') === '') {
				continue;
			}

			$ex = ProductVariation::getByProductSku($id, (string) $v['sku']);

			if (!$ex) {
				if ($create && method_exists('ProductVariation', 'upsertOne')) {
					$opts = [];

					foreach ([1, 2] as $i) {
						if (($v['option' . $i . '_name'] ?? '') !== '' && ($v['option' . $i . '_value'] ?? '') !== '') {
							$opts[(string) $v['option' . $i . '_name']] = (string) $v['option' . $i . '_value'];
						}
					}

					if ($opts !== []) {
						$r = ProductVariation::upsertOne($id, ['sku' => $v['sku'], 'barcode' => $v['barcode'] ?? '', 'price' => $v['price'] ?? '', 'stock' => $v['stock'] ?? 0, 'options' => $opts, 'active' => 1]);

						if (!empty($r['success'])) {
							$n++;
							$created = (int) $created + (!empty($r['created']) ? 1 : 0);
						}
					}
				}

				continue;
			}

			$patch = [];

			if ($stock) {
				$patch['stock'] = $v['stock'];
			}

			if ($price && ($v['price'] ?? '') !== '') {
				$patch['price'] = $v['price'];
			}

			if ($patch !== []) {
				$r = ProductVariation::patchPriceStock((int) $ex['id_variation'], $patch);
				$n += !empty($r['success']) ? 1 : 0;
			}
		}

		return $n;
	}

	public static function hasImages(int $id): bool
	{
		return (bool) DB::getRowSafe('images', 'id_product = ?', [$id]);
	}

	/**
	 * Görselleri güvenli indirip ekler (SSRF korumalı, 5 MB sınır). Başarısız olanlar uyarı olarak döner.
	 *
	 * @param list<string> $urls
	 * @return array{added:int, warnings:list<string>, done:list<string>}  done = başarıyla eklenen URL'ler
	 */
	public static function addImages(int $idVendor, int $id, array $urls, int $max): array
	{
		$added = 0;
		$warn = [];
		$done = [];

		if (!XhOwner::owns($idVendor, $id)) {
			return ['added' => 0, 'warnings' => ['Ürün bu hesaba ait değil.'], 'done' => []];
		}

		foreach (array_slice($urls, 0, max(0, $max)) as $u) {
			$r = XhHttp::fetchToString($u, 5242880, 10);

			if (!$r['ok']) {
				$warn[] = 'Görsel alınamadı (' . mb_substr($u, 0, 80) . '): ' . $r['error'];

				continue;
			}

			$res = Product::importImageBinary($id, $r['body']);

			if (!empty($res['success'])) {
				$added++;
				$done[] = $u;
			} else {
				$warn[] = 'Görsel kaydedilemedi (' . mb_substr($u, 0, 80) . '): ' . (string) ($res['message'] ?? '');
			}
		}

		return ['added' => $added, 'warnings' => $warn, 'done' => $done];
	}
}
