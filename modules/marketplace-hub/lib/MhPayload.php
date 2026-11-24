<?php

/**
 * Pazaryerine gidecek tarafsız ürün verisi. Kaynak: ortak ürün tablosu (+ XML Hub ek verisi varsa: kargo ücreti, kaynak/tedarikçi).
 * Pazaryerine özgü dönüşümü her bağdaştırıcı kendi içinde yapar.
 */
class MhPayload
{
	/** @return array{ok:bool, error:string, payload:array<string,mixed>} */
	public static function build(int $idProduct, int $idAccount): array
	{
		$r = DB::execute('SELECT * FROM products WHERE id_product = ?', [$idProduct]);

		if (!$r || !isset($r[0])) {
			return ['ok' => false, 'error' => 'Ürün bulunamadı.', 'payload' => []];
		}

		$p = $r[0];
		$map = MhRepo::categoryMap($idAccount);
		$cat = $map[(int) ($p['id_category'] ?? 0)] ?? null;

		if (!$cat) {
			return ['ok' => false, 'error' => 'Ürünün kategorisi bu pazaryeri için eşleştirilmemiş.', 'payload' => []];
		}

		if ((float) $p['price'] <= 0 || trim((string) $p['product_name']) === '') {
			return ['ok' => false, 'error' => 'Ürün adı veya fiyatı geçersiz.', 'payload' => []];
		}

		$brand = DB::execute('SELECT brand_name FROM brands WHERE id_brand = ?', [(int) ($p['id_brand'] ?? 0)]);
		$images = [];

		foreach (DB::execute('SELECT id_image FROM images WHERE id_product = ? ORDER BY cover DESC, id_image ASC', [$idProduct]) ?: [] as $im) {
			$images[] = class_exists('Product') && method_exists('Product', 'getImageUrl') ? (string) Product::getImageUrl((int) $im['id_image']) : '/img/products/' . (int) $im['id_image'] . '.jpg';
		}

		$variants = [];

		if (class_exists('ProductVariation') && method_exists('ProductVariation', 'getByProduct')) {
			foreach ((array) ProductVariation::getByProduct($idProduct, true) as $v) {
				$variants[] = ['sku' => (string) ($v['sku'] ?? ''), 'barcode' => (string) ($v['barcode'] ?? ''), 'price' => $v['price'] ?? null, 'stock' => (float) ($v['stock'] ?? 0), 'options' => $v['options'] ?? []];
			}
		}

		$meta = class_exists('XhMeta', false) ? XhMeta::get($idProduct) : null;
		$payload = [
			'id_product' => $idProduct,
			'name' => (string) $p['product_name'],
			'description' => (string) ($p['description'] ?? ''),
			'sku' => (string) ($p['stock_code'] ?? ''),
			'barcode' => (string) ($p['barcode'] ?? ''),
			'price' => (float) $p['price'],
			'stock' => (float) $p['stock'],
			'brand' => $brand && isset($brand[0]) ? (string) $brand[0]['brand_name'] : '',
			'category' => $cat,
			'images' => $images,
			'variants' => $variants,
			'desi' => (int) ($p['desi'] ?? 1),
			'cargo_day' => (int) ($p['cargo_day'] ?? 0),
			'cargo_price' => $meta && $meta['cargo_price'] !== null ? (float) $meta['cargo_price'] : null,
			'source' => $meta && $meta['source_type'] === 'xml' ? ['type' => 'xml', 'id_connection' => (int) $meta['id_connection'], 'id_supplier' => (int) $meta['id_supplier']] : ['type' => 'manual'],
		];

		return ['ok' => true, 'error' => '', 'payload' => $payload];
	}

	/**
	 * Yalnızca fiyat/stok güncellemesi için hafif veri (kategori eşlemesi GEREKMEZ). Varyantlı üründe her varyant ayrı satır olur.
	 *
	 * @return list<array{sku:string,barcode:string,price:float,list_price:float,stock:int}>
	 */
	public static function stockPriceItems(int $idProduct): array
	{
		$r = DB::execute('SELECT * FROM products WHERE id_product = ?', [$idProduct]);

		if (!$r || !isset($r[0])) {
			return [];
		}

		$p = $r[0];
		$list = max((float) $p['price'], (float) ($p['old_price'] ?? 0));
		$items = [];
		$vars = class_exists('ProductVariation') && method_exists('ProductVariation', 'getByProduct') ? (array) ProductVariation::getByProduct($idProduct, true) : [];

		foreach ($vars as $v) {
			$price = isset($v['price']) && $v['price'] !== null && (float) $v['price'] > 0 ? (float) $v['price'] : (float) $p['price'];
			$items[] = ['sku' => (string) ($v['sku'] ?? ''), 'barcode' => (string) ($v['barcode'] ?? ''), 'price' => $price, 'list_price' => max($price, $list), 'stock' => max(0, (int) ($v['stock'] ?? 0))];
		}

		if ($items === []) {
			$items[] = ['sku' => (string) ($p['stock_code'] ?? ''), 'barcode' => (string) ($p['barcode'] ?? ''), 'price' => (float) $p['price'], 'list_price' => $list, 'stock' => max(0, (int) $p['stock'])];
		}

		return $items;
	}

	/** @param array<string,mixed> $payload */
	public static function hash(array $payload): string
	{
		return sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
	}
}
