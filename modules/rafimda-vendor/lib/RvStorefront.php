<?php

/**
 * Herkese açık mağaza vitrini (/magaza?s=slug) ve mağaza takibi. Yalnızca AKTİF mağazalar gösterilir.
 * Puan ve sayılar yalnızca gerçek kayıtlardan hesaplanır (onaylı değerlendirme yoksa puan gösterilmez).
 */
final class RvStorefront
{
	public const IMG_MAX = 3145728; // 3 MB
	public const IMG_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

	/** Testlerde public img dizini yerine geçici dizin */
	public static ?string $dirOverride = null;

	public static function imgDir(int $idVendor): string
	{
		return (self::$dirOverride ?? dirname(__DIR__, 3) . '/img/rafimda/stores') . '/' . $idVendor;
	}

	public static function imgUrl(string $file, int $idVendor): string
	{
		return $file === '' ? '' : RvView::url('img/rafimda/stores/' . $idVendor . '/' . rawurlencode($file));
	}

	/** @return array<string,mixed> */
	public static function profile(int $idVendor): array
	{
		$p = DB::getRowSafe('rafimda_store_profile', 'id_vendor = ?', [$idVendor]) ?: [];

		return [
			'logo' => (string) ($p['logo'] ?? ''), 'cover' => (string) ($p['cover'] ?? ''), 'about' => (string) ($p['about'] ?? ''),
			'shipping_note' => (string) ($p['shipping_note'] ?? ''), 'return_note' => (string) ($p['return_note'] ?? ''),
			'logo_url' => self::imgUrl((string) ($p['logo'] ?? ''), $idVendor), 'cover_url' => self::imgUrl((string) ($p['cover'] ?? ''), $idVendor),
		];
	}

	/** Satıcının düzenleyebileceği alanlar (izin verilenler): hakkında, kargo ve iade notu. */
	public static function saveProfile(int $idVendor, array $in): bool
	{
		$data = [
			'about' => mb_substr(trim(strip_tags((string) ($in['about'] ?? ''))), 0, 3000),
			'shipping_note' => mb_substr(trim(strip_tags((string) ($in['shipping_note'] ?? ''))), 0, 255),
			'return_note' => mb_substr(trim(strip_tags((string) ($in['return_note'] ?? ''))), 0, 255),
			'date_upd' => date('Y-m-d H:i:s'),
		];

		return self::upsert($idVendor, $data);
	}

	private static function upsert(int $idVendor, array $data): bool
	{
		if (DB::getRowSafe('rafimda_store_profile', 'id_vendor = ?', [$idVendor])) {
			return DB::update('rafimda_store_profile', $data, 'id_vendor = :v', ['v' => $idVendor]) !== false;
		}

		return (bool) DB::insert('rafimda_store_profile', ['id_vendor' => $idVendor] + $data + ['logo' => '', 'cover' => '']) || (bool) DB::getRowSafe('rafimda_store_profile', 'id_vendor = ?', [$idVendor]);
	}

	/**
	 * Logo/kapak yükleme: gerçek görsel kontrolü (getimagesize), boyut sınırı, GD ile yeniden kodlama (gömülü içerik temizlenir).
	 * @param array{tmp_name?:string,size?:int,error?:int} $file
	 */
	public static function uploadImage(int $idVendor, string $kind, array $file): array
	{
		if (!in_array($kind, ['logo', 'cover'], true)) {
			return ['success' => false, 'message' => 'Geçersiz görsel türü.'];
		}

		if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_file((string) $file['tmp_name'])) {
			return ['success' => false, 'message' => 'Görsel yüklenemedi.'];
		}

		if (filesize((string) $file['tmp_name']) > self::IMG_MAX) {
			return ['success' => false, 'message' => 'Görsel en fazla 3 MB olabilir.'];
		}

		$info = @getimagesize((string) $file['tmp_name']);

		if (!$info || !isset(self::IMG_TYPES[$info[2]])) {
			return ['success' => false, 'message' => 'Yalnızca JPG, PNG veya WEBP görsel yükleyin.'];
		}

		[$w, $h] = $info;

		if ($w < 64 || $h < 64 || $w > 6000 || $h > 6000) {
			return ['success' => false, 'message' => 'Görsel boyutu uygun değil (64–6000 piksel).'];
		}

		$src = match ($info[2]) {
			IMAGETYPE_JPEG => @imagecreatefromjpeg((string) $file['tmp_name']),
			IMAGETYPE_PNG => @imagecreatefrompng((string) $file['tmp_name']),
			default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp((string) $file['tmp_name']) : false,
		};

		if (!$src) {
			return ['success' => false, 'message' => 'Görsel okunamadı.'];
		}

		[$maxW, $maxH] = $kind === 'logo' ? [400, 400] : [1600, 500];
		$scale = min(1, $maxW / $w, $maxH / $h);
		$nw = max(1, (int) round($w * $scale));
		$nh = max(1, (int) round($h * $scale));
		$dst = imagecreatetruecolor($nw, $nh);
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
		$dir = self::imgDir($idVendor);

		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return ['success' => false, 'message' => 'Görsel dizini oluşturulamadı.'];
		}

		$name = $kind . '-' . bin2hex(random_bytes(6)) . '.png';
		$ok = imagepng($dst, $dir . '/' . $name, 6);
		imagedestroy($src);
		imagedestroy($dst);

		if (!$ok) {
			return ['success' => false, 'message' => 'Görsel kaydedilemedi.'];
		}

		$old = self::profile($idVendor)[$kind];
		self::upsert($idVendor, [$kind => $name, 'date_upd' => date('Y-m-d H:i:s')]);

		if ($old !== '' && is_file($dir . '/' . basename($old))) {
			@unlink($dir . '/' . basename($old)); // yalnızca bu mağazanın eski kendi görseli
		}

		return ['success' => true, 'message' => $kind === 'logo' ? 'Logo güncellendi.' : 'Kapak görseli güncellendi.'];
	}

	public static function bySlug(string $slug): ?array
	{
		$v = DB::getRowSafe('rafimda_vendors', 'store_slug = ? AND status = ?', [$slug, RvVendor::STATUS_ACTIVE]);

		return $v ?: null;
	}

	public static function url(array $vendor): string
	{
		return RvView::url('magaza', ['s' => (string) $vendor['store_slug']]);
	}

	/** @return array{avg:float,count:int} yalnızca onaylı değerlendirmelerden (mağaza + ürün) */
	public static function rating(int $idVendor): array
	{
		$rows = [];
		$store = DB::execute("SELECT rating FROM rafimda_store_reviews WHERE id_vendor = ? AND status = 'approved'", [$idVendor]) ?: [];

		foreach ($store as $r) {
			$rows[] = (int) $r['rating'];
		}

		if (self::tableExists('product_reviews')) {
			$prod = DB::execute('SELECT r.rating FROM product_reviews r INNER JOIN rafimda_vendor_products vp ON vp.id_product = r.id_product WHERE vp.id_vendor = ? AND r.active = 1', [$idVendor]) ?: [];

			foreach ($prod as $r) {
				$rows[] = (int) $r['rating'];
			}
		}

		return $rows === [] ? ['avg' => 0.0, 'count' => 0] : ['avg' => round(array_sum($rows) / count($rows), 1), 'count' => count($rows)];
	}

	/** @return array<int,array<string,mixed>> */
	public static function products(int $idVendor, string $sort = 'new', int $limit = 24): array
	{
		$order = $sort === 'best'
			? '(SELECT COALESCE(SUM(od.qty),0) FROM order_detail od INNER JOIN orders o ON o.id_order = od.id_order WHERE od.id_product = p.id_product AND o.status NOT IN (5,6)) DESC, p.id_product DESC'
			: 'p.id_product DESC';

		$ids = array_map('intval', array_column(DB::execute('SELECT p.id_product FROM products p INNER JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product
			WHERE vp.id_vendor = ? AND p.active = 1 ORDER BY ' . $order . ' LIMIT ' . max(1, min(60, $limit)), [$idVendor]) ?: [], 'id_product'));

		if ($ids === []) {
			return [];
		}

		// Gerçek FriSay'de: kart için bağlantı/görsel/fiyat biçimi Product::enrichList ile (tema kartlarıyla aynı)
		if (class_exists('Product') && method_exists('Product', 'enrichList')) {
			$rows = DB::execute('SELECT p.*, b.brand_name, b.brand_link, c.category_name, c.category_link, i.id_image FROM products p INNER JOIN brands b ON p.id_brand = b.id_brand
				INNER JOIN categories c ON p.id_category = c.id_category LEFT JOIN images i ON p.id_product = i.id_product AND i.cover = 1 WHERE p.id_product IN (' . implode(',', $ids) . ')') ?: [];
			$by = [];

			foreach (Product::enrichList($rows) as $r) {
				$by[(int) $r['id_product']] = $r;
			}

			return array_values(array_filter(array_map(static fn($id) => $by[$id] ?? null, $ids)));
		}

		$rows = DB::execute('SELECT id_product, product_name, price, old_price, stock FROM products WHERE id_product IN (' . implode(',', $ids) . ')') ?: [];
		$by = [];

		foreach ($rows as $r) {
			$r['url'] = '';
			$r['image_url'] = '';
			$r['price_formatted'] = number_format((float) $r['price'], 2, ',', '.') . ' ₺';
			$r['has_discount'] = (float) $r['old_price'] > (float) $r['price'];
			$r['old_price_formatted'] = number_format((float) $r['old_price'], 2, ',', '.') . ' ₺';
			$by[(int) $r['id_product']] = $r;
		}

		return array_values(array_filter(array_map(static fn($id) => $by[$id] ?? null, $ids)));
	}

	public static function follow(int $idUser, int $idVendor, bool $on): bool
	{
		if ($idUser <= 0 || !DB::getRowSafe('rafimda_vendors', 'id_vendor = ? AND status = ?', [$idVendor, RvVendor::STATUS_ACTIVE])) {
			return false;
		}

		// Kendi mağazasını takip etmek anlamsız ve sayıyı şişirir
		if (DB::getRowSafe('rafimda_vendor_staff', 'id_vendor = ? AND id_user = ? AND status = ?', [$idVendor, $idUser, 'active'])) {
			return false;
		}

		$exists = DB::getRowSafe('rafimda_follows', 'id_user = ? AND id_vendor = ?', [$idUser, $idVendor]);

		if ($on && !$exists) {
			DB::insert('rafimda_follows', ['id_user' => $idUser, 'id_vendor' => $idVendor, 'date_add' => date('Y-m-d H:i:s')]);
		} elseif (!$on && $exists) {
			DB::execute('DELETE FROM rafimda_follows WHERE id_user = ? AND id_vendor = ?', [$idUser, $idVendor]);
		}

		return true;
	}

	public static function isFollowing(int $idUser, int $idVendor): bool
	{
		return $idUser > 0 && (bool) DB::getRowSafe('rafimda_follows', 'id_user = ? AND id_vendor = ?', [$idUser, $idVendor]);
	}

	public static function followers(int $idVendor): int
	{
		return (int) DB::getValue('SELECT COUNT(*) FROM rafimda_follows WHERE id_vendor = ?', [$idVendor]);
	}

	/** @return array<int,array<string,mixed>> takip edilen mağazalar */
	public static function followedBy(int $idUser): array
	{
		return DB::execute("SELECT v.id_vendor, v.store_name, v.store_slug FROM rafimda_follows f INNER JOIN rafimda_vendors v ON v.id_vendor = f.id_vendor WHERE f.id_user = ? AND v.status = 'active' ORDER BY f.id_follow DESC", [$idUser]) ?: [];
	}

	/** Ürünün satıcısı (ürün sayfası "satıcı bilgisi" için). */
	public static function vendorOfProduct(int $idProduct): ?array
	{
		$rows = DB::execute("SELECT v.* FROM rafimda_vendor_products vp INNER JOIN rafimda_vendors v ON v.id_vendor = vp.id_vendor WHERE vp.id_product = ? AND v.status = 'active' LIMIT 1", [$idProduct]) ?: [];

		return $rows[0] ?? null;
	}

	/** @var array<string,bool> */
	private static array $tables = [];

	/** FriSay soru/yorum modülleri kurulu mu? (DB::execute hata durumunda false döner) */
	public static function tableExists(string $t): bool
	{
		$t = (string) preg_replace('/[^a-z_]/', '', $t);

		return self::$tables[$t] ??= DB::execute('SELECT 1 FROM ' . $t . ' LIMIT 1') !== false;
	}

	public static function resetCache(): void
	{
		self::$tables = [];
	}
}
