<?php

/**
 * Satıcı bazlı XML dışa aktarma. Her satıcı kendi ürünleri için gizli adresli (token) bir besleme oluşturur.
 * - Yalnızca o satıcıya ait (rafimda_vendor_products), onaylı (moderation = approved) ve aktif ürünler.
 * - Token yalnızca oluşturulurken bir kez gösterilir; veritabanında SHA-256 özeti saklanır.
 * - Akışlı yazım (XMLWriter + sayfalı sorgu): büyük kataloglarda bellek sabit kalır.
 */
class XhExport
{
	public const BATCH = 200;

	/** @return array{success:bool, message:string, token:string, id:int} */
	public static function create(int $idVendor, string $name, bool $onlyInStock): array
	{
		$name = mb_substr(trim(strip_tags($name)), 0, 120);

		if ($idVendor <= 0) {
			return ['success' => false, 'message' => 'Dışa aktarma yalnızca satıcılar içindir.', 'token' => '', 'id' => 0];
		}

		if (mb_strlen($name) < 2) {
			return ['success' => false, 'message' => 'Besleme adı en az 2 karakter olmalı.', 'token' => '', 'id' => 0];
		}

		if ((int) DB::getValue('SELECT COUNT(*) FROM xh_export_feeds WHERE id_vendor = ? AND active = 1', [$idVendor]) >= 10) {
			return ['success' => false, 'message' => 'En fazla 10 etkin besleme oluşturabilirsiniz.', 'token' => '', 'id' => 0];
		}

		$token = bin2hex(random_bytes(24));
		$id = DB::insert('xh_export_feeds', ['id_vendor' => $idVendor, 'name' => $name, 'token_hash' => hash('sha256', $token), 'token_hint' => substr($token, 0, 6), 'only_in_stock' => $onlyInStock ? 1 : 0]);

		return $id ? ['success' => true, 'message' => 'Besleme oluşturuldu.', 'token' => $token, 'id' => (int) $id] : ['success' => false, 'message' => 'Kaydedilemedi.', 'token' => '', 'id' => 0];
	}

	/** @return list<array<string,mixed>> */
	public static function feeds(int $idVendor): array
	{
		if ($idVendor <= 0) {
			return [];
		}

		return DB::execute('SELECT id_feed, name, token_hint, only_in_stock, active, hits, last_hit, date_add FROM xh_export_feeds WHERE id_vendor = ? ORDER BY id_feed DESC', [$idVendor]) ?: [];
	}

	public static function revoke(int $idVendor, int $idFeed): bool
	{
		return $idVendor > 0 && (bool) DB::update('xh_export_feeds', ['active' => 0], 'id_feed = :f AND id_vendor = :v', ['f' => $idFeed, 'v' => $idVendor]);
	}

	/** @return array<string,mixed>|null */
	public static function byToken(string $token): ?array
	{
		if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
			return null;
		}

		$f = DB::getRowSafe('xh_export_feeds', 'token_hash = ? AND active = 1', [hash('sha256', $token)]);

		return $f ?: null;
	}

	public static function feedUrl(string $base, string $token): string
	{
		return rtrim($base, '/') . '/xml-export?t=' . $token;
	}

	/** Besleme XML'ini $out (php://output ya da dosya) akışına yazar. Dönüş: yazılan ürün sayısı. */
	public static function stream(array $feed, string $out = 'php://output'): int
	{
		$v = (int) $feed['id_vendor'];
		$w = new XMLWriter();
		$w->openUri($out);
		$w->setIndent(true);
		$w->startDocument('1.0', 'UTF-8');
		$w->startElement('Rafimda');
		$w->writeAttribute('vendor', (string) $v);
		$w->writeAttribute('generated', gmdate('c'));
		$w->startElement('Products');
		$last = 0;
		$n = 0;
		$stockSql = !empty($feed['only_in_stock']) ? ' AND p.stock > 0' : '';

		while (true) {
			$rows = DB::execute(
				"SELECT p.* FROM products p JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product
				 WHERE vp.id_vendor = ? AND vp.moderation = 'approved' AND p.active = 1 AND p.id_product > ?$stockSql ORDER BY p.id_product ASC LIMIT " . self::BATCH,
				[$v, $last]
			);

			if (!$rows) {
				break;
			}

			foreach ($rows as $p) {
				self::writeProduct($w, $p);
				$last = (int) $p['id_product'];
				$n++;
			}

			$w->flush();
		}

		$w->endElement();
		$w->endElement();
		$w->endDocument();
		$w->flush();

		return $n;
	}

	/** @param array<string,mixed> $p */
	private static function writeProduct(XMLWriter $w, array $p): void
	{
		$id = (int) $p['id_product'];
		$brand = DB::getValue('SELECT brand_name FROM brands WHERE id_brand = ?', [(int) ($p['id_brand'] ?? 0)]);
		$cat = DB::getValue('SELECT category_name FROM categories WHERE id_category = ?', [(int) ($p['id_category'] ?? 0)]);
		$meta = class_exists('XhMeta') ? XhMeta::get($id) : null;
		$w->startElement('Product');
		$w->writeElement('Id', (string) $id);
		$w->writeElement('Sku', (string) ($p['stock_code'] ?? ''));
		$w->writeElement('Barcode', (string) ($p['barcode'] ?? ''));
		$w->writeElement('Name', (string) $p['product_name']);
		$w->startElement('Description');
		$w->writeCdata(str_replace(']]>', ']]]]><![CDATA[>', (string) ($p['description'] ?? '')));
		$w->endElement();
		$w->writeElement('Price', number_format((float) $p['price'], 2, '.', ''));
		$w->writeElement('Stock', (string) (int) $p['stock']);
		$w->writeElement('Brand', (string) ($brand ?: ''));
		$w->writeElement('Category', (string) ($cat ?: ''));
		$w->writeElement('Desi', (string) (int) ($p['desi'] ?? 1));
		$w->writeElement('CargoDay', (string) (int) ($p['cargo_day'] ?? 0));

		if ($meta && $meta['cargo_price'] !== null) {
			$w->writeElement('CargoPrice', number_format((float) $meta['cargo_price'], 2, '.', ''));
		}

		$w->startElement('Images');

		foreach (DB::execute('SELECT id_image FROM images WHERE id_product = ? ORDER BY cover DESC, id_image ASC', [$id]) ?: [] as $im) {
			$w->writeElement('Image', class_exists('Product') && method_exists('Product', 'getImageUrl') ? (string) Product::getImageUrl((int) $im['id_image']) : '/img/products/' . (int) $im['id_image'] . '.jpg');
		}

		$w->endElement();
		$w->startElement('Variants');
		$vars = class_exists('ProductVariation') && method_exists('ProductVariation', 'getByProduct') ? (array) ProductVariation::getByProduct($id, true) : (DB::execute('SELECT * FROM product_variations WHERE id_product = ?', [$id]) ?: []);

		foreach ($vars as $vr) {
			$w->startElement('Variant');
			$w->writeElement('Sku', (string) ($vr['sku'] ?? ''));
			$w->writeElement('Barcode', (string) ($vr['barcode'] ?? ''));
			$w->writeElement('Price', isset($vr['price']) && $vr['price'] !== null && $vr['price'] !== '' ? number_format((float) $vr['price'], 2, '.', '') : '');
			$w->writeElement('Stock', (string) (int) ($vr['stock'] ?? 0));
			$w->endElement();
		}

		$w->endElement();
		$w->endElement();
	}

	public static function hit(int $idFeed): void
	{
		DB::execute('UPDATE xh_export_feeds SET hits = hits + 1, last_hit = ? WHERE id_feed = ?', [date('Y-m-d H:i:s'), $idFeed]);
	}
}
