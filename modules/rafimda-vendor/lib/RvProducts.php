<?php

/**
 * Satıcıya kapsamlı (scoped) ürün işlemleri.
 *
 * KURAL: Bu sınıfın dışından bir ürün ID'si ile Product::save/deleteById çağrılmamalıdır.
 * Her yazma işlemi önce rafimda_vendor_products sahiplik kaydını doğrular (assertOwned).
 * Asıl ürün mantığı FriSay `Product` ve `ProductVariation` sınıflarından yeniden kullanılır.
 */
class RvProducts
{
	public const MAX_IMAGES_PER_UPLOAD = 10;
	public const MAX_IMAGE_BYTES = 5242880;

	public static function owns(int $idVendor, int $idProduct): bool
	{
		if ($idVendor <= 0 || $idProduct <= 0) {
			return false;
		}

		return (bool) DB::getRowSafe('rafimda_vendor_products', 'id_product = ? AND id_vendor = ?', [$idProduct, $idVendor]);
	}

	/** @return list<int> yalnızca satıcıya ait olan ID'ler */
	public static function filterOwned(int $idVendor, array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));

		if ($ids === [] || $idVendor <= 0) {
			return [];
		}

		$in = implode(',', array_fill(0, count($ids), '?'));
		$rows = DB::execute(
			'SELECT id_product FROM rafimda_vendor_products WHERE id_vendor = ? AND id_product IN (' . $in . ')',
			array_merge([$idVendor], $ids)
		) ?: [];

		return array_map(static fn(array $r): int => (int) $r['id_product'], $rows);
	}

	/**
	 * @return array{rows:list<array<string,mixed>>, total:int}
	 */
	public static function listFor(int $idVendor, string $q = '', string $state = '', int $limit = 20, int $offset = 0): array
	{
		$where = 'vp.id_vendor = ?';
		$params = [$idVendor];

		if ($q !== '') {
			$where .= ' AND (p.product_name LIKE ? OR p.stock_code LIKE ? OR p.barcode LIKE ?)';
			$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
			array_push($params, $like, $like, $like);
		}

		if ($state === 'active') {
			$where .= ' AND p.active = 1';
		} elseif ($state === 'passive') {
			$where .= ' AND p.active = 0';
		} elseif ($state === 'lowstock') {
			$where .= ' AND p.stock <= 5';
		}

		$total = (int) DB::getValue(
			'SELECT COUNT(*) FROM rafimda_vendor_products vp INNER JOIN products p ON p.id_product = vp.id_product WHERE ' . $where,
			$params
		);

		$rows = DB::execute(
			'SELECT p.id_product, p.product_name, p.price, p.old_price, p.stock, p.stock_code, p.barcode, p.active, vp.moderation, vp.moderation_note,
				b.brand_name, c.category_name,
				(SELECT i.id_image FROM images i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) AS id_image
			FROM rafimda_vendor_products vp
			INNER JOIN products p ON p.id_product = vp.id_product
			LEFT JOIN brands b ON b.id_brand = p.id_brand
			LEFT JOIN categories c ON c.id_category = p.id_category
			WHERE ' . $where . ' ORDER BY p.id_product DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
			$params
		) ?: [];

		foreach ($rows as &$row) {
			$row['image_url'] = !empty($row['id_image']) ? Product::getImageUrl((int) $row['id_image']) : '';
		}
		unset($row);

		return ['rows' => $rows, 'total' => $total];
	}

	/** @return array<string,mixed>|null */
	public static function getOwned(int $idVendor, int $idProduct): ?array
	{
		if (!self::owns($idVendor, $idProduct)) {
			return null;
		}

		$row = Product::getByIdAdmin($idProduct);

		if (!$row) {
			return null;
		}

		$row['images'] = Product::getImages($idProduct);
		$row['variations'] = ProductVariation::getByProduct($idProduct);
		$mod = DB::getRowSafe('rafimda_vendor_products', 'id_product = ?', [$idProduct]) ?: [];
		$row['moderation'] = (string) ($mod['moderation'] ?? 'approved');
		$row['moderation_note'] = (string) ($mod['moderation_note'] ?? '');

		return $row;
	}

	/**
	 * Formdan gelen alanları beyaz listeyle temizler. Yetki/sahiplik alanları (id_vendor vb.) asla kabul edilmez.
	 *
	 * @param array<string,mixed> $post
	 * @return array{data:array<string,mixed>, errors:array<string,string>}
	 */
	public static function sanitizeInput(array $post): array
	{
		$errors = [];
		$num = static fn($v): float => (float) str_replace(',', '.', trim((string) $v));

		$name = trim(strip_tags((string) ($post['product_name'] ?? '')));
		$idCategory = (int) ($post['id_category'] ?? 0);
		$idBrand = (int) ($post['id_brand'] ?? 0);
		$price = $num($post['price'] ?? 0);
		$oldPrice = $num($post['old_price'] ?? 0);
		$stock = $num($post['stock'] ?? 0);

		if (mb_strlen($name) < 3 || mb_strlen($name) > 128) {
			$errors['product_name'] = 'Ürün adı 3-128 karakter olmalı.';
		}

		if ($idCategory <= 0 || !DB::getRowSafe('categories', 'id_category = ? AND active = 1', [$idCategory])) {
			$errors['id_category'] = 'Geçerli bir kategori seçin.';
		}

		if ($idBrand <= 0 || !DB::getRowSafe('brands', 'id_brand = ? AND active = 1', [$idBrand])) {
			$errors['id_brand'] = 'Geçerli bir marka seçin.';
		}

		if ($price <= 0) {
			$errors['price'] = 'Fiyat 0\'dan büyük olmalı.';
		}

		if ($stock < 0) {
			$errors['stock'] = 'Stok negatif olamaz.';
		}

		$data = [
			'product_name' => $name,
			'id_category' => $idCategory,
			'id_brand' => $idBrand,
			'price' => $price,
			'old_price' => $oldPrice > $price ? $oldPrice : 0,
			'stock' => $stock,
			'barcode' => mb_substr(trim(strip_tags((string) ($post['barcode'] ?? ''))), 0, 64),
			'stock_code' => mb_substr(trim(strip_tags((string) ($post['stock_code'] ?? ''))), 0, 64),
			'short_description' => mb_substr(trim(strip_tags((string) ($post['short_description'] ?? ''))), 0, 500),
			'description' => (string) ($post['description'] ?? ''), // Product::save içinde Security::sanitizeHtml uygulanır
			'active' => !empty($post['active']) ? 1 : 0,
		];

		if (isset($post['vat']) && $post['vat'] !== '') {
			$data['vat'] = $num($post['vat']);
		}

		if (!empty($post['variations_present'])) {
			$rows = is_array($post['variations'] ?? null) ? $post['variations'] : [];
			$clean = [];

			foreach (array_slice($rows, 0, 100) as $r) {
				if (!is_array($r)) {
					continue;
				}

				$clean[] = [
					'option1_name' => mb_substr(trim(strip_tags((string) ($r['option1_name'] ?? ''))), 0, 40),
					'option1_value' => mb_substr(trim(strip_tags((string) ($r['option1_value'] ?? ''))), 0, 60),
					'option2_name' => mb_substr(trim(strip_tags((string) ($r['option2_name'] ?? ''))), 0, 40),
					'option2_value' => mb_substr(trim(strip_tags((string) ($r['option2_value'] ?? ''))), 0, 60),
					'sku' => mb_substr(trim(strip_tags((string) ($r['sku'] ?? ''))), 0, 64),
					'barcode' => mb_substr(trim(strip_tags((string) ($r['barcode'] ?? ''))), 0, 64),
					'price' => (string) ($r['price'] ?? ''),
					'stock' => (string) ($r['stock'] ?? '0'),
					'active' => isset($r['active']) ? '1' : '0',
				];
			}

			$data['variations'] = $clean;
			$hasAny = false;

			foreach ($clean as $r) {
				if ($r['option1_value'] !== '' || $r['option2_value'] !== '' || $r['sku'] !== '') {
					$hasAny = true;
					break;
				}
			}

			$data['has_variations'] = $hasAny ? '1' : '0';
		}

		return ['data' => $data, 'errors' => $errors];
	}

	/**
	 * @param array<string,mixed> $post
	 * @return array{success:bool, message:string, id:int, errors?:array<string,string>}
	 */
	public static function create(int $idVendor, array $post): array
	{
		$in = self::sanitizeInput($post);

		if ($in['errors'] !== []) {
			return ['success' => false, 'message' => 'Formu kontrol edin.', 'id' => 0, 'errors' => $in['errors']];
		}

		$quota = RvPlans::checkProductQuota($idVendor, 1);

		if (!$quota['ok']) {
			return ['success' => false, 'message' => $quota['message'], 'id' => 0, 'errors' => []];
		}

		$moderate = self::moderationEnabled();

		if ($moderate) {
			$in['data']['active'] = 0; // onaylanana kadar mağazada görünmez
		}

		$res = Product::save($in['data'], 0);

		if (empty($res['success']) || (int) ($res['id'] ?? 0) <= 0) {
			return ['success' => false, 'message' => (string) ($res['message'] ?? 'Ürün kaydedilemedi.'), 'id' => 0];
		}

		$id = (int) $res['id'];

		if (!DB::insert('rafimda_vendor_products', [
			'id_product' => $id,
			'id_vendor' => $idVendor,
			'moderation' => $moderate ? 'pending' : 'approved',
		])) {
			// Sahipsiz ürün bırakma: geri al.
			Product::deleteById($id);

			return ['success' => false, 'message' => 'Ürün sahiplik kaydı oluşturulamadı; işlem geri alındı.', 'id' => 0];
		}

		RvAudit::log($idVendor, 'product.created', 'product', $id, ['name' => $in['data']['product_name'], 'moderation' => $moderate ? 'pending' : 'approved']);

		return ['success' => true, 'message' => $moderate ? 'Ürün eklendi; yönetici onayından sonra yayına girecek.' : 'Ürün eklendi.', 'id' => $id];
	}

	/**
	 * @param array<string,mixed> $post
	 * @return array{success:bool, message:string, id:int, errors?:array<string,string>}
	 */
	public static function update(int $idVendor, int $idProduct, array $post): array
	{
		$existing = self::getOwned($idVendor, $idProduct);

		if (!$existing) {
			return ['success' => false, 'message' => 'Ürün bulunamadı.', 'id' => 0];
		}

		$in = self::sanitizeInput($post);

		if ($in['errors'] !== []) {
			return ['success' => false, 'message' => 'Formu kontrol edin.', 'id' => $idProduct, 'errors' => $in['errors']];
		}

		// Formda olmayan alanları (maliyet, desi, etiket, ürün tipi vb.) sıfırlamamak için mevcut satırla birleştir.
		$base = $existing;
		unset($base['images'], $base['variations'], $base['moderation'], $base['moderation_note'], $base['brand_name'], $base['brand_link'], $base['category_name'], $base['category_link']);
		$data = array_merge($base, $in['data']);

		if (($existing['moderation'] ?? 'approved') !== 'approved') {
			$data['active'] = 0; // onaysız/reddedilmiş ürün satıcı tarafından yayına alınamaz
		}

		$res = Product::save($data, $idProduct);

		if (empty($res['success'])) {
			return ['success' => false, 'message' => (string) ($res['message'] ?? 'Ürün kaydedilemedi.'), 'id' => $idProduct];
		}

		RvAudit::log($idVendor, 'product.updated', 'product', $idProduct);

		return ['success' => true, 'message' => 'Ürün güncellendi.', 'id' => $idProduct];
	}

	public static function delete(int $idVendor, int $idProduct): array
	{
		if (!self::owns($idVendor, $idProduct)) {
			return ['success' => false, 'message' => 'Ürün bulunamadı.'];
		}

		$res = Product::deleteById($idProduct);

		if (empty($res['success'])) {
			return ['success' => false, 'message' => (string) ($res['message'] ?? 'Silinemedi.')];
		}

		DB::execute('DELETE FROM rafimda_vendor_products WHERE id_product = ? AND id_vendor = ?', [$idProduct, $idVendor]);
		RvAudit::log($idVendor, 'product.deleted', 'product', $idProduct);

		return ['success' => true, 'message' => 'Ürün silindi.'];
	}

	/**
	 * Hızlı fiyat/stok güncelleme (varyasyonsuz ürünler). product.updated hook'u tetiklenir (pazaryeri senkronu için).
	 */
	public static function quickUpdate(int $idVendor, int $idProduct, $price, $stock): array
	{
		if (!self::owns($idVendor, $idProduct)) {
			return ['success' => false, 'message' => 'Ürün bulunamadı.'];
		}

		$price = (float) str_replace(',', '.', (string) $price);
		$stock = (float) str_replace(',', '.', (string) $stock);

		if ($price <= 0 || $stock < 0) {
			return ['success' => false, 'message' => 'Fiyat 0\'dan büyük, stok negatif olmayan bir değer olmalı.'];
		}

		$res = DB::update('products', ['price' => $price, 'stock' => $stock], 'id_product = :pid', ['pid' => $idProduct]);

		if ($res === false) {
			return ['success' => false, 'message' => 'Güncellenemedi.'];
		}

		if (class_exists('Module')) {
			Module::runHook('product.updated', [$idProduct, Product::getByIdAdmin($idProduct) ?: [], false]);
		}

		RvAudit::log($idVendor, 'product.quick', 'product', $idProduct, ['price' => $price, 'stock' => $stock]);

		return ['success' => true, 'message' => 'Fiyat ve stok güncellendi.'];
	}

	/**
	 * @param list<int|string> $ids
	 * @return array{success:bool, message:string, count:int}
	 */
	public static function bulk(int $idVendor, array $ids, string $action): array
	{
		$owned = self::filterOwned($idVendor, $ids);

		if ($owned === []) {
			return ['success' => false, 'message' => 'Seçili ürün yok.', 'count' => 0];
		}

		$count = 0;

		if ($action === 'activate' || $action === 'deactivate') {
			$val = $action === 'activate' ? 1 : 0;

			if ($val === 1) {
				$owned = self::filterApproved($owned);
			}

			foreach ($owned as $id) {
				if (DB::update('products', ['active' => $val], 'id_product = :pid', ['pid' => $id]) !== false) {
					$count++;
				}
			}
		} elseif ($action === 'delete') {
			foreach ($owned as $id) {
				if (!empty(self::delete($idVendor, $id)['success'])) {
					$count++;
				}
			}
		} else {
			return ['success' => false, 'message' => 'Geçersiz işlem.', 'count' => 0];
		}

		RvAudit::log($idVendor, 'product.bulk.' . $action, 'product', 0, ['count' => $count]);

		return ['success' => true, 'message' => $count . ' ürün işlendi.', 'count' => $count];
	}

	/**
	 * @param array<string,mixed> $files $_FILES['images'] (çoklu)
	 * @return array{uploaded:int, errors:list<string>}
	 */
	public static function uploadImages(int $idVendor, int $idProduct, array $files): array
	{
		if (!self::owns($idVendor, $idProduct)) {
			return ['uploaded' => 0, 'errors' => ['Ürün bulunamadı.']];
		}

		$list = [];

		if (isset($files['name']) && is_array($files['name'])) {
			foreach ($files['name'] as $i => $n) {
				$list[] = [
					'name' => $n,
					'tmp_name' => $files['tmp_name'][$i] ?? '',
					'size' => (int) ($files['size'][$i] ?? 0),
					'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
				];
			}
		} elseif (isset($files['name'])) {
			$list[] = $files;
		}

		$uploaded = 0;
		$errors = [];

		foreach (array_slice($list, 0, self::MAX_IMAGES_PER_UPLOAD) as $f) {
			if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
				continue;
			}

			if (($f['error'] ?? 0) !== UPLOAD_ERR_OK || (int) ($f['size'] ?? 0) > self::MAX_IMAGE_BYTES) {
				$errors[] = (string) ($f['name'] ?? 'dosya') . ': yükleme hatası veya 5 MB sınırı aşıldı.';
				continue;
			}

			$res = Product::uploadImage($idProduct, $f);

			if (!empty($res['success'])) {
				$uploaded++;
			} else {
				$errors[] = (string) ($f['name'] ?? 'dosya') . ': ' . (string) ($res['message'] ?? 'yüklenemedi');
			}
		}

		if ($uploaded > 0) {
			RvAudit::log($idVendor, 'product.images', 'product', $idProduct, ['count' => $uploaded]);
		}

		return ['uploaded' => $uploaded, 'errors' => $errors];
	}

	/** Görselin ait olduğu ürünün satıcıya ait olduğunu doğrular. */
	public static function ownsImage(int $idVendor, int $idImage): bool
	{
		$img = DB::getRowSafe('images', 'id_image = ?', [$idImage]);

		return $img ? self::owns($idVendor, (int) $img['id_product']) : false;
	}

	public static function deleteImage(int $idVendor, int $idImage): array
	{
		if (!self::ownsImage($idVendor, $idImage)) {
			return ['success' => false, 'message' => 'Görsel bulunamadı.'];
		}

		return Product::deleteImage($idImage);
	}

	public static function setCover(int $idVendor, int $idImage): array
	{
		if (!self::ownsImage($idVendor, $idImage)) {
			return ['success' => false, 'message' => 'Görsel bulunamadı.'];
		}

		return Product::setCover($idImage);
	}

	/** @return array{total:int, active:int, passive:int, low_stock:int} */
	public static function stats(int $idVendor): array
	{
		$row = DB::execute(
			'SELECT COUNT(*) AS total, SUM(CASE WHEN p.active = 1 THEN 1 ELSE 0 END) AS active_c,
				SUM(CASE WHEN p.active = 0 THEN 1 ELSE 0 END) AS passive_c,
				SUM(CASE WHEN p.stock <= ' . RvPrefs::lowStock($idVendor) . ' THEN 1 ELSE 0 END) AS low_c
			FROM rafimda_vendor_products vp INNER JOIN products p ON p.id_product = vp.id_product WHERE vp.id_vendor = ?',
			[$idVendor]
		)[0] ?? [];

		return [
			'total' => (int) ($row['total'] ?? 0),
			'active' => (int) ($row['active_c'] ?? 0),
			'passive' => (int) ($row['passive_c'] ?? 0),
			'low_stock' => (int) ($row['low_c'] ?? 0),
		];
	}

	/**
	 * Satıcının siparişleri: yalnızca kendi ürünlerini içeren sipariş satırları (salt okunur).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function ordersFor(int $idVendor, int $limit = 30, int $offset = 0): array
	{
		return DB::execute(
			'SELECT o.id_order, o.reference, o.status, o.date_add, o.customer_name, o.address_city,
				SUM(od.total) AS vendor_total, SUM(od.qty) AS vendor_qty, COUNT(*) AS line_count
			FROM order_detail od
			INNER JOIN rafimda_vendor_products vp ON vp.id_product = od.id_product AND vp.id_vendor = ?
			INNER JOIN orders o ON o.id_order = od.id_order
			GROUP BY o.id_order, o.reference, o.status, o.date_add, o.customer_name, o.address_city
			ORDER BY o.id_order DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
			[$idVendor]
		) ?: [];
	}

	// ---- Ürün onay (moderasyon) ----

	public static function moderationEnabled(): bool
	{
		return Settings::get('RAFIMDA_PRODUCT_MODERATION') !== '0';
	}

	/** @param list<int> $ids @return list<int> yalnızca onaylı olanlar */
	public static function filterApproved(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$in = implode(',', array_fill(0, count($ids), '?'));
		$rows = DB::execute(
			'SELECT id_product FROM rafimda_vendor_products WHERE moderation = ? AND id_product IN (' . $in . ')',
			array_merge(['approved'], array_map('intval', $ids))
		) ?: [];

		return array_map(static fn(array $r): int => (int) $r['id_product'], $rows);
	}

	/** Ana yönetici: ürünü onaylar ve yayına alır. */
	public static function approve(int $idProduct, int $idAdmin): bool
	{
		$vp = DB::getRowSafe('rafimda_vendor_products', 'id_product = ?', [$idProduct]);

		if (!$vp) {
			return false;
		}

		DB::update('rafimda_vendor_products', ['moderation' => 'approved', 'moderation_note' => '', 'moderated_at' => date('Y-m-d H:i:s')], 'id_product = :pid', ['pid' => $idProduct]);
		DB::update('products', ['active' => 1], 'id_product = :pid', ['pid' => $idProduct]);

		if (class_exists('Module')) {
			Module::runHook('product.updated', [$idProduct, Product::getByIdAdmin($idProduct) ?: [], false]);
		}

		RvAudit::log((int) $vp['id_vendor'], 'product.approved', 'product', $idProduct, [], $idAdmin);

		return true;
	}

	/** Ana yönetici: ürünü reddeder ve yayından kaldırır. */
	public static function reject(int $idProduct, string $note, int $idAdmin): bool
	{
		$vp = DB::getRowSafe('rafimda_vendor_products', 'id_product = ?', [$idProduct]);

		if (!$vp) {
			return false;
		}

		$note = mb_substr(trim(strip_tags($note)), 0, 255);
		DB::update('rafimda_vendor_products', ['moderation' => 'rejected', 'moderation_note' => $note, 'moderated_at' => date('Y-m-d H:i:s')], 'id_product = :pid', ['pid' => $idProduct]);
		DB::update('products', ['active' => 0], 'id_product = :pid', ['pid' => $idProduct]);
		RvAudit::log((int) $vp['id_vendor'], 'product.rejected', 'product', $idProduct, ['note' => $note], $idAdmin);

		return true;
	}

	/** @return list<array<string,mixed>> */
	public static function moderationQueue(string $state = 'pending', int $limit = 100): array
	{
		$state = in_array($state, ['pending', 'approved', 'rejected'], true) ? $state : 'pending';

		return DB::execute(
			'SELECT p.id_product, p.product_name, p.price, p.stock, v.id_vendor, v.store_name, vp.moderation, vp.moderation_note, vp.date_add
			FROM rafimda_vendor_products vp
			INNER JOIN products p ON p.id_product = vp.id_product
			INNER JOIN rafimda_vendors v ON v.id_vendor = vp.id_vendor
			WHERE vp.moderation = ? ORDER BY vp.date_add ASC LIMIT ' . max(1, min(300, $limit)),
			[$state]
		) ?: [];
	}

	// ---- CSV içe aktarma ----

	public const CSV_MAX_ROWS = 500;
	public const CSV_MAX_BYTES = 2097152;
	public const CSV_COLUMNS = ['urun_adi', 'kategori_id', 'marka_id', 'fiyat', 'stok', 'stok_kodu', 'barkod', 'aciklama'];

	public static function csvTemplate(): string
	{
		return "\xEF\xBB\xBF" . implode(';', self::CSV_COLUMNS) . "\r\n" . 'Örnek Tişört;1;1;99,90;25;TSH-001;8690000000001;Pamuklu tişört' . "\r\n";
	}

	/**
	 * CSV (UTF-8, ; veya , ayraçlı) içe aktarma. Satır mantığı importRows() ile Excel (.xlsx) içe aktarmayla ortaktır.
	 *
	 * @return array{success:bool, message:string, total:int, created:int, updated:int, failed:int, errors:list<string>}
	 */
	public static function importCsv(int $idVendor, string $path, string $fileName): array
	{
		$res = self::emptyImportResult();

		if (!is_file($path) || filesize($path) > self::CSV_MAX_BYTES) {
			$res['message'] = 'Dosya bulunamadı veya 2 MB sınırını aşıyor.';

			return $res;
		}

		$raw = (string) file_get_contents($path);
		$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

		if (!mb_check_encoding($raw, 'UTF-8')) {
			$res['message'] = 'Dosya UTF-8 olmalı (Excel: "CSV UTF-8" olarak kaydedin ya da doğrudan .xlsx yükleyin).';

			return $res;
		}

		$firstLine = strtok($raw, "\n") ?: '';
		$delim = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
		$fh = fopen('php://temp', 'r+');
		fwrite($fh, $raw);
		rewind($fh);

		$header = fgetcsv($fh, 0, $delim, '"', '');
		$rows = [];

		while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
			$rows[] = $cells;

			if (count($rows) > self::CSV_MAX_ROWS + 1) {
				break;
			}
		}

		fclose($fh);

		return self::importRows($idVendor, is_array($header) ? $header : [], $rows, $fileName, 'csv');
	}

	/** @return array{success:bool, message:string, total:int, created:int, updated:int, failed:int, errors:list<string>} */
	public static function emptyImportResult(): array
	{
		return ['success' => false, 'message' => '', 'total' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
	}

	/**
	 * Başlık hücresini makine adına çevirir: "Ürün Adı" → urun_adi, "Kategori ID" → kategori_id.
	 *
	 * @param mixed $h
	 */
	public static function normalizeHeader($h): string
	{
		$h = mb_strtolower(trim((string) $h), 'UTF-8');
		$h = strtr($h, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'i̇' => 'i']);
		$h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? '';
		$h = trim($h, '_');
		$alias = ['kategori' => 'kategori_id', 'marka' => 'marka_id', 'id' => 'urun_id', 'urun_no' => 'urun_id', 'sku' => 'stok_kodu', 'barcode' => 'barkod'];

		return $alias[$h] ?? $h;
	}

	/**
	 * Ortak satır işleyici (CSV ve Excel).
	 * Eşleşme sırası: urun_id (bu satıcıya aitse) → stok_kodu (bu satıcıya aitse) → eşleşme yoksa yeni ürün.
	 * Eşleşen üründe yalnızca fiyat/stok güncellenir; BOŞ hücre mevcut değeri korur (sıfırlamaz).
	 * Başka satıcının ürünüyle asla eşleşme yapılmaz.
	 *
	 * @param list<mixed> $header
	 * @param iterable<list<mixed>> $rows
	 * @return array{success:bool, message:string, total:int, created:int, updated:int, failed:int, errors:list<string>}
	 */
	public static function importRows(int $idVendor, array $header, iterable $rows, string $fileName, string $kind = 'csv'): array
	{
		$res = self::emptyImportResult();
		$header = array_map([self::class, 'normalizeHeader'], $header);
		$canCreate = array_diff(['urun_adi', 'kategori_id', 'marka_id', 'fiyat'], $header) === [];
		$canUpdate = (in_array('urun_id', $header, true) || in_array('stok_kodu', $header, true))
			&& (in_array('fiyat', $header, true) || in_array('stok', $header, true));

		if (!$canCreate && !$canUpdate) {
			$missing = array_diff(['urun_adi', 'kategori_id', 'marka_id', 'fiyat'], $header);
			$res['message'] = 'Eksik sütun(lar): ' . implode(', ', $missing) . '. Yeni ürün için bu sütunlar, yalnızca fiyat/stok güncellemesi için urun_id veya stok_kodu ile fiyat/stok gerekir. Şablonu indirip kullanın.';

			return $res;
		}

		$line = 1;

		foreach ($rows as $cells) {
			$line++;
			$cells = is_array($cells) ? array_values($cells) : [];

			if (implode('', array_map(static fn($c): string => trim((string) $c), $cells)) === '') {
				continue;
			}

			if ($res['total'] >= self::CSV_MAX_ROWS) {
				$res['errors'][] = 'Satır sınırı (' . self::CSV_MAX_ROWS . ') aşıldı; kalan satırlar işlenmedi.';
				break;
			}

			$res['total']++;
			$row = [];

			foreach ($header as $i => $h) {
				if ($h !== '' && !isset($row[$h])) {
					$row[$h] = trim((string) ($cells[$i] ?? ''));
				}
			}

			$code = mb_substr(strip_tags($row['stok_kodu'] ?? ''), 0, 64);
			$owned = null;
			$pid = (int) preg_replace('/\D/', '', (string) ($row['urun_id'] ?? ''));

			if ($pid > 0) {
				if (!self::owns($idVendor, $pid)) {
					$res['failed']++;
					$res['errors'][] = 'Satır ' . $line . ': urun_id ' . $pid . ' size ait bir ürün değil.';

					continue;
				}

				$owned = $pid;
			} elseif ($code !== '') {
				$hit = DB::execute(
					'SELECT p.id_product FROM rafimda_vendor_products vp INNER JOIN products p ON p.id_product = vp.id_product WHERE vp.id_vendor = ? AND p.stock_code = ? LIMIT 1',
					[$idVendor, $code]
				);
				$owned = ($hit && isset($hit[0])) ? (int) $hit[0]['id_product'] : null;
			}

			if ($owned) {
				$cur = DB::getRowSafe('products', 'id_product = ?', [$owned]) ?: [];
				$price = ($row['fiyat'] ?? '') !== '' ? $row['fiyat'] : ($cur['price'] ?? 0);
				$stock = ($row['stok'] ?? '') !== '' ? $row['stok'] : ($cur['stock'] ?? 0);
				$u = self::quickUpdate($idVendor, $owned, $price, $stock);

				if (!empty($u['success'])) {
					$res['updated']++;
				} else {
					$res['failed']++;
					$res['errors'][] = 'Satır ' . $line . ': ' . $u['message'];
				}

				continue;
			}

			if (!$canCreate) {
				$res['failed']++;
				$res['errors'][] = 'Satır ' . $line . ': eşleşen ürününüz yok (urun_id/stok_kodu); yeni ürün için urun_adi, kategori_id, marka_id, fiyat sütunları gerekir.';

				continue;
			}

			$c = self::create($idVendor, [
				'product_name' => $row['urun_adi'] ?? '',
				'id_category' => $row['kategori_id'] ?? 0,
				'id_brand' => $row['marka_id'] ?? 0,
				'price' => $row['fiyat'] ?? 0,
				'stock' => ($row['stok'] ?? '') !== '' ? $row['stok'] : 0,
				'stock_code' => $code,
				'barcode' => $row['barkod'] ?? '',
				'description' => $row['aciklama'] ?? '',
				'active' => 1,
			]);

			if (!empty($c['success'])) {
				$res['created']++;
			} else {
				$res['failed']++;
				$detail = !empty($c['errors']) ? implode(' ', $c['errors']) : $c['message'];
				$res['errors'][] = 'Satır ' . $line . ': ' . $detail;
			}
		}

		$res['success'] = true;
		$res['message'] = $res['created'] . ' yeni, ' . $res['updated'] . ' güncellenen, ' . $res['failed'] . ' hatalı satır.';

		$idUser = class_exists('Customer') && Customer::isLoggedIn() ? Customer::getId() : 0;
		DB::insert('rafimda_import_log', [
			'id_vendor' => $idVendor,
			'id_user' => $idUser,
			'file_name' => mb_substr($fileName, 0, 190),
			'rows_total' => $res['total'],
			'rows_created' => $res['created'],
			'rows_updated' => $res['updated'],
			'rows_failed' => $res['failed'],
			'errors' => $res['errors'] === [] ? null : json_encode(array_slice($res['errors'], 0, 50), JSON_UNESCAPED_UNICODE),
		]);
		RvAudit::log($idVendor, $kind === 'xlsx' ? 'product.import_xlsx' : 'product.import_csv', 'import', 0, ['created' => $res['created'], 'updated' => $res['updated'], 'failed' => $res['failed']]);

		return $res;
	}
}
