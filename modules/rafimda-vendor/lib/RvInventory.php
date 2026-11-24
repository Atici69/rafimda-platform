<?php

/**
 * Satıcı stok ve fiyat yönetimi (varyasyonsuz ürünler için). Tüm işlemler sunucu tarafında sahiplik (RvProducts::owns) kontrollüdür.
 * Varyantlı ürünlerin stok/fiyatı varyant bazlıdır; bu ekranlarda değiştirilmez (ürün düzenleme ekranından yönetilir).
 */
class RvInventory
{
	public const LOW_STOCK = 5;
	public const MAX_BULK = 100;

	/** @return array{total:int, out:int, low:int, inactive:int, variant:int} */
	public static function summary(int $idVendor): array
	{
		$base = 'FROM products p JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE vp.id_vendor = ?';

		return [
			'total' => (int) DB::getValue("SELECT COUNT(*) $base", [$idVendor]),
			'out' => (int) DB::getValue("SELECT COUNT(*) $base AND p.stock <= 0", [$idVendor]),
			'low' => (int) DB::getValue("SELECT COUNT(*) $base AND p.stock > 0 AND p.stock <= " . self::LOW_STOCK, [$idVendor]),
			'inactive' => (int) DB::getValue("SELECT COUNT(*) $base AND p.active = 0", [$idVendor]),
			'variant' => self::variantCount($idVendor),
		];
	}

	private static function variantCount(int $idVendor): int
	{
		$r = DB::getValue('SELECT COUNT(DISTINCT pv.id_product) FROM product_variations pv JOIN rafimda_vendor_products vp ON vp.id_product = pv.id_product WHERE vp.id_vendor = ?', [$idVendor]);

		return $r === false ? 0 : (int) $r;
	}

	/**
	 * @return array{rows:list<array<string,mixed>>, total:int}
	 */
	public static function listProducts(int $idVendor, string $filter, string $q, int $limit, int $offset): array
	{
		$where = 'vp.id_vendor = ?';
		$params = [$idVendor];

		if ($filter === 'out') {
			$where .= ' AND p.stock <= 0';
		} elseif ($filter === 'low') {
			$where .= ' AND p.stock > 0 AND p.stock <= ' . self::LOW_STOCK;
		} elseif ($filter === 'inactive') {
			$where .= ' AND p.active = 0';
		}

		$q = trim($q);

		if ($q !== '') {
			$like = '%' . str_replace(['\\', '%', '_'], '', mb_substr($q, 0, 60)) . '%';
			$where .= ' AND (p.product_name LIKE ? OR p.stock_code LIKE ? OR p.barcode LIKE ?)';
			array_push($params, $like, $like, $like);
		}

		$from = "FROM products p JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE $where";
		$total = (int) DB::getValue("SELECT COUNT(*) $from", $params);
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$rows = DB::execute("SELECT p.id_product, p.product_name, p.stock_code, p.barcode, p.price, p.old_price, p.stock, p.active, vp.moderation $from ORDER BY p.stock ASC, p.id_product DESC LIMIT $limit OFFSET $offset", $params) ?: [];

		foreach ($rows as &$r) {
			$v = DB::getValue('SELECT COUNT(*) FROM product_variations WHERE id_product = ?', [(int) $r['id_product']]);
			$r['has_variants'] = $v !== false && (int) $v > 0;
		}

		unset($r);

		return ['rows' => $rows, 'total' => $total];
	}

	private static function hasVariants(int $idProduct): bool
	{
		$v = DB::getValue('SELECT COUNT(*) FROM product_variations WHERE id_product = ?', [$idProduct]);

		return $v !== false && (int) $v > 0;
	}

	/** @return array{success:bool, message:string} */
	public static function setStock(int $idVendor, int $idProduct, $stock): array
	{
		return self::apply($idVendor, $idProduct, ['stock' => $stock], 'stock.set');
	}

	/** @return array{success:bool, message:string} */
	public static function setPrice(int $idVendor, int $idProduct, $price, $oldPrice = null): array
	{
		$f = ['price' => $price];

		if ($oldPrice !== null && $oldPrice !== '') {
			$f['old_price'] = $oldPrice;
		}

		return self::apply($idVendor, $idProduct, $f, 'price.set');
	}

	/**
	 * @param array<string,mixed> $fields stock | price | old_price
	 * @return array{success:bool, message:string}
	 */
	private static function apply(int $idVendor, int $idProduct, array $fields, string $audit): array
	{
		if (!RvProducts::owns($idVendor, $idProduct)) {
			return ['success' => false, 'message' => 'Ürün bulunamadı.'];
		}

		if (self::hasVariants($idProduct)) {
			return ['success' => false, 'message' => 'Varyantlı ürünün stok/fiyatı varyant bazlıdır; ürün düzenleme ekranından değiştirin.'];
		}

		$set = [];

		foreach ($fields as $k => $v) {
			$n = self::num($v);

			if ($n === null || $n < 0 || $n > 99999999) {
				return ['success' => false, 'message' => 'Geçersiz sayı.'];
			}

			if ($k === 'price' && $n <= 0) {
				return ['success' => false, 'message' => 'Fiyat 0\'dan büyük olmalı.'];
			}

			$set[$k] = $k === 'stock' ? floor($n) : round($n, 2);
		}

		if (isset($set['old_price']) && $set['old_price'] > 0 && isset($set['price']) && $set['old_price'] < $set['price']) {
			return ['success' => false, 'message' => 'Eski (liste) fiyat satış fiyatından küçük olamaz.'];
		}

		if (DB::update('products', $set, 'id_product = :pid', ['pid' => $idProduct]) === false) {
			return ['success' => false, 'message' => 'Güncellenemedi.'];
		}

		if (class_exists('Module')) {
			Module::runHook('product.updated', [$idProduct, Product::getByIdAdmin($idProduct) ?: [], false]);
		}

		RvAudit::log($idVendor, 'product.' . $audit, 'product', $idProduct, $set);

		return ['success' => true, 'message' => 'Güncellendi.'];
	}

	/** @param mixed $v */
	private static function num($v): ?float
	{
		if (is_int($v) || is_float($v)) {
			return (float) $v;
		}

		$s = str_replace(',', '.', trim((string) $v));

		return $s !== '' && is_numeric($s) ? (float) $s : null;
	}

	/**
	 * Toplu stok/fiyat. $rows: id_product => ['stock' => ..., 'price' => ...] (boş alan değiştirilmez).
	 *
	 * @param array<int|string,array<string,mixed>> $rows
	 * @return array{ok:int, failed:int, errors:list<string>}
	 */
	public static function bulkSet(int $idVendor, array $rows): array
	{
		$out = ['ok' => 0, 'failed' => 0, 'errors' => []];

		foreach (array_slice($rows, 0, self::MAX_BULK, true) as $id => $f) {
			$id = (int) $id;
			$fields = [];

			foreach (['stock', 'price'] as $k) {
				if (isset($f[$k]) && is_scalar($f[$k]) && trim((string) $f[$k]) !== '') {
					$fields[$k] = $f[$k];
				}
			}

			if ($fields === []) {
				continue;
			}

			$r = self::apply($idVendor, $id, $fields, 'bulk');

			if ($r['success']) {
				$out['ok']++;
			} else {
				$out['failed']++;
				$out['errors'][] = '#' . $id . ': ' . $r['message'];
			}
		}

		return $out;
	}

	/**
	 * Seçili ürünlerin fiyatını yüzde ile değiştirir (−50..+100).
	 *
	 * @param list<int|string> $ids
	 * @return array{ok:int, failed:int, errors:list<string>}
	 */
	public static function adjustPrices(int $idVendor, array $ids, $percent): array
	{
		$p = self::num($percent);

		if ($p === null || $p < -50 || $p > 100 || $p == 0.0) {
			return ['ok' => 0, 'failed' => 0, 'errors' => ['Yüzde −50 ile +100 arasında ve sıfırdan farklı olmalı.']];
		}

		$out = ['ok' => 0, 'failed' => 0, 'errors' => []];

		foreach (array_slice(array_unique(array_map('intval', $ids)), 0, self::MAX_BULK) as $id) {
			$cur = RvProducts::owns($idVendor, $id) ? DB::getValue('SELECT price FROM products WHERE id_product = ?', [$id]) : false;

			if ($cur === false) {
				$out['failed']++;
				$out['errors'][] = '#' . $id . ': Ürün bulunamadı.';

				continue;
			}

			$new = round((float) $cur * (1 + $p / 100), 2);
			$r = self::apply($idVendor, $id, ['price' => $new], 'price.adjust');

			if ($r['success']) {
				$out['ok']++;
			} else {
				$out['failed']++;
				$out['errors'][] = '#' . $id . ': ' . $r['message'];
			}
		}

		return $out;
	}
}
