<?php

/**
 * Değerlendirmeler (ürün + mağaza). Ürün yorumları FriSay `reviews` modülünün `product_reviews` tablosundadır; Rafımda ekler:
 * - Doğrulanmış satın alma: yalnızca kullanıcının TESLİM EDİLMİŞ (durum 4) siparişinde o ürün varsa.
 * - Manipülasyon engeli: mağaza personeli kendi mağazasının ürününü/mağazasını değerlendiremez; aynı ürüne bir yorum;
 *   mağaza değerlendirmesi yalnızca teslim edilmiş siparişle ve sipariş başına bir kez; "faydalı" oyu kişi başı bir kez, kendi yorumuna oy yok.
 * - Fotoğraflı yorum (en fazla 3, GD ile yeniden kodlanır), satıcı yanıtı (sahiplik kontrollü), yönetici moderasyonu, bildirimler.
 * Yorumlar varsayılan olarak yönetici onayından sonra yayınlanır (FriSay davranışı korunur).
 */
final class RvReviews
{
	public const DELIVERED = 4;
	public const PHOTO_MAX = 3;
	public const PHOTO_BYTES = 3145728;

	public static ?string $photoDirOverride = null;

	public static function available(): bool
	{
		return RvStorefront::tableExists('product_reviews');
	}

	public static function photoDir(): string
	{
		return self::$photoDirOverride ?? dirname(__DIR__, 3) . '/img/rafimda/reviews';
	}

	/** Kullanıcının teslim edilmiş siparişlerinde ürün var mı? Varsa sipariş numarası döner. */
	public static function deliveredOrderFor(int $idUser, int $idProduct): int
	{
		return (int) DB::getValue('SELECT o.id_order FROM orders o INNER JOIN order_detail od ON od.id_order = o.id_order WHERE o.id_user = ? AND od.id_product = ? AND o.status = ? ORDER BY o.id_order DESC LIMIT 1', [$idUser, $idProduct, self::DELIVERED]);
	}

	public static function isStaffOf(int $idUser, int $idVendor): bool
	{
		return $idUser > 0 && $idVendor > 0 && (bool) DB::getRowSafe('rafimda_vendor_staff', 'id_vendor = ? AND id_user = ?', [$idVendor, $idUser]);
	}

	/** FriSay reviews::submit öncesi koruma: null = devam, dizi = reddet. */
	public static function guard(int $idProduct, int $idUser): ?array
	{
		$vendor = RvStorefront::vendorOfProduct($idProduct);

		if ($vendor && self::isStaffOf($idUser, (int) $vendor['id_vendor'])) {
			return ['success' => false, 'message' => 'Kendi mağazanızın ürününü değerlendiremezsiniz.'];
		}

		if ((string) Settings::get('RAFIMDA_REVIEW_PURCHASE_ONLY') === '1' && self::deliveredOrderFor($idUser, $idProduct) === 0) {
			return ['success' => false, 'message' => 'Yalnızca teslim aldığınız ürünleri değerlendirebilirsiniz.'];
		}

		return null;
	}

	/** FriSay yorum kaydı sonrası: satıcı bağı + doğrulanmış satın alma etiketi (yalnızca gerçek teslim kaydı varsa) + satıcıya bildirim. */
	public static function onReviewCreated(int $idReview, int $idProduct, int $idUser): void
	{
		try {
			$vendor = RvStorefront::vendorOfProduct($idProduct);
			$order = self::deliveredOrderFor($idUser, $idProduct);

			if (!DB::getRowSafe('rafimda_review_meta', 'id_review = ?', [$idReview])) {
				DB::insert('rafimda_review_meta', ['id_review' => $idReview, 'id_vendor' => (int) ($vendor['id_vendor'] ?? 0), 'id_order' => $order, 'verified' => $order > 0 ? 1 : 0, 'photos' => '[]', 'helpful' => 0]);
			}

			if ($vendor) {
				RvNotify::send(['id_vendor' => (int) $vendor['id_vendor'], 'audience' => 'vendor'], 'review', 'Yeni ürün değerlendirmesi', 'Bir ürününüz değerlendirildi (yönetici onayından sonra yayınlanır).', RvView::panelUrl('reviews'));
			}
		} catch (Throwable $e) {
			error_log('RvReviews: ' . $e->getMessage());
		}
	}

	/**
	 * Rafımda değerlendirme formu (/degerlendir): yalnızca teslim edilmiş siparişteki ürün için; fotoğraf eklenebilir.
	 * @param list<array{tmp_name?:string,size?:int,error?:int}> $photos
	 */
	public static function submitProduct(int $idUser, string $authorName, int $idOrder, int $idProduct, int $rating, string $comment, array $photos = []): array
	{
		if (!self::available()) {
			return ['success' => false, 'message' => 'Yorum modülü kurulu değil.'];
		}

		$ok = (int) DB::getValue('SELECT COUNT(*) FROM orders o INNER JOIN order_detail od ON od.id_order = o.id_order WHERE o.id_order = ? AND o.id_user = ? AND od.id_product = ? AND o.status = ?', [$idOrder, $idUser, $idProduct, self::DELIVERED]);

		if ($ok === 0) {
			return ['success' => false, 'message' => 'Bu ürünü değerlendirebilmek için siparişinizin teslim edilmiş olması gerekir.'];
		}

		$g = self::guard($idProduct, $idUser);

		if ($g !== null) {
			return $g;
		}

		if (DB::getValue('SELECT id_review FROM product_reviews WHERE id_product = ? AND id_user = ? LIMIT 1', [$idProduct, $idUser])) {
			return ['success' => false, 'message' => 'Bu ürün için zaten değerlendirme yaptınız.'];
		}

		$comment = trim(strip_tags($comment));

		if ($rating < 1 || $rating > 5) {
			return ['success' => false, 'message' => '1 ile 5 arasında puan verin.'];
		}

		if (mb_strlen($comment) < 10 || mb_strlen($comment) > 2000) {
			return ['success' => false, 'message' => 'Yorum 10-2000 karakter olmalı.'];
		}

		$saved = [];

		foreach (array_slice($photos, 0, self::PHOTO_MAX) as $f) {
			if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
				continue;
			}

			$r = self::storePhoto($f);

			if (!$r['success']) {
				foreach ($saved as $s) {
					@unlink(self::photoDir() . '/' . $s);
				}

				return $r;
			}

			$saved[] = $r['file'];
		}

		$id = (int) DB::insert('product_reviews', ['id_product' => $idProduct, 'id_user' => $idUser, 'author_name' => mb_substr($authorName !== '' ? $authorName : 'Müşteri', 0, 128),
			'rating' => $rating, 'title' => '', 'comment' => $comment, 'active' => 0, 'date_add' => date('Y-m-d H:i:s')]);

		if ($id <= 0) {
			return ['success' => false, 'message' => 'Değerlendirme kaydedilemedi.'];
		}

		self::onReviewCreated($id, $idProduct, $idUser);
		DB::update('rafimda_review_meta', ['photos' => json_encode($saved), 'id_order' => $idOrder, 'verified' => 1], 'id_review = :r', ['r' => $id]);

		return ['success' => true, 'message' => 'Değerlendirmeniz alındı; onaylandıktan sonra yayınlanacak.', 'id_review' => $id];
	}

	/** @param array{tmp_name?:string,size?:int,error?:int} $f */
	private static function storePhoto(array $f): array
	{
		$tmp = (string) ($f['tmp_name'] ?? '');

		if ((int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp) || filesize($tmp) > self::PHOTO_BYTES) {
			return ['success' => false, 'message' => 'Fotoğraf en fazla 3 MB olabilir.'];
		}

		$info = @getimagesize($tmp);

		if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
			return ['success' => false, 'message' => 'Fotoğraf JPG, PNG veya WEBP olmalı.'];
		}

		$src = match ($info[2]) {
			IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
			IMAGETYPE_PNG => @imagecreatefrompng($tmp),
			default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
		};

		if (!$src) {
			return ['success' => false, 'message' => 'Fotoğraf okunamadı.'];
		}

		$scale = min(1, 1200 / $info[0], 1200 / $info[1]);
		$dst = imagecreatetruecolor(max(1, (int) ($info[0] * $scale)), max(1, (int) ($info[1] * $scale)));
		imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $info[0], $info[1]);
		$dir = self::photoDir();

		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return ['success' => false, 'message' => 'Fotoğraf dizini oluşturulamadı.'];
		}

		$name = bin2hex(random_bytes(10)) . '.jpg';
		$ok = imagejpeg($dst, $dir . '/' . $name, 85); // yeniden kodlama: EXIF/konum ve gömülü içerik temizlenir
		imagedestroy($src);
		imagedestroy($dst);

		return $ok ? ['success' => true, 'file' => $name] : ['success' => false, 'message' => 'Fotoğraf kaydedilemedi.'];
	}

	/** Mağaza değerlendirmesi: yalnızca teslim edilmiş ve o mağazaya ait kalem içeren siparişle, sipariş başına bir kez. */
	public static function submitStore(int $idUser, string $authorName, int $idOrder, int $idVendor, int $rating, string $comment): array
	{
		$has = (int) DB::getValue('SELECT COUNT(*) FROM orders o INNER JOIN order_detail od ON od.id_order = o.id_order INNER JOIN rafimda_vendor_products vp ON vp.id_product = od.id_product
			WHERE o.id_order = ? AND o.id_user = ? AND vp.id_vendor = ? AND o.status = ?', [$idOrder, $idUser, $idVendor, self::DELIVERED]);

		if ($has === 0) {
			return ['success' => false, 'message' => 'Mağazayı değerlendirmek için bu mağazadan teslim aldığınız bir sipariş gerekir.'];
		}

		if (self::isStaffOf($idUser, $idVendor)) {
			return ['success' => false, 'message' => 'Kendi mağazanızı değerlendiremezsiniz.'];
		}

		if (DB::getRowSafe('rafimda_store_reviews', 'id_vendor = ? AND id_order = ? AND id_user = ?', [$idVendor, $idOrder, $idUser])) {
			return ['success' => false, 'message' => 'Bu sipariş için mağazayı zaten değerlendirdiniz.'];
		}

		$comment = trim(strip_tags($comment));

		if ($rating < 1 || $rating > 5 || mb_strlen($comment) < 10 || mb_strlen($comment) > 2000) {
			return ['success' => false, 'message' => 'Puan (1-5) ve en az 10 karakterlik yorum girin.'];
		}

		DB::insert('rafimda_store_reviews', ['id_vendor' => $idVendor, 'id_user' => $idUser, 'id_order' => $idOrder, 'author_name' => mb_substr($authorName !== '' ? $authorName : 'Müşteri', 0, 128),
			'rating' => $rating, 'comment' => $comment, 'verified' => 1, 'status' => 'pending', 'helpful' => 0, 'date_add' => date('Y-m-d H:i:s')]);
		RvNotify::send(['id_vendor' => $idVendor, 'audience' => 'vendor'], 'review', 'Yeni mağaza değerlendirmesi', 'Mağazanız değerlendirildi (yönetici onayından sonra yayınlanır).', RvView::panelUrl('reviews'));

		return ['success' => true, 'message' => 'Mağaza değerlendirmeniz alındı; onaylandıktan sonra yayınlanacak.'];
	}

	/** "Faydalı buldum": kişi başı bir kez, kendi yorumuna değil, yalnızca yayındaki yoruma. */
	public static function voteHelpful(string $kind, int $idReview, int $idUser): array
	{
		if ($idUser <= 0) {
			return ['success' => false, 'message' => 'Oy vermek için giriş yapın.'];
		}

		if ($kind === 'store') {
			$r = DB::getRowSafe('rafimda_store_reviews', 'id_store_review = ? AND status = ?', [$idReview, 'approved']);
		} else {
			$kind = 'product';
			$r = self::available() ? DB::getRowSafe('product_reviews', 'id_review = ? AND active = 1', [$idReview]) : null;
		}

		if (!$r) {
			return ['success' => false, 'message' => 'Yorum bulunamadı.'];
		}

		if ((int) $r['id_user'] === $idUser) {
			return ['success' => false, 'message' => 'Kendi yorumunuza oy veremezsiniz.'];
		}

		if (DB::getRowSafe('rafimda_review_votes', 'kind = ? AND id_review = ? AND id_user = ?', [$kind, $idReview, $idUser])) {
			return ['success' => false, 'message' => 'Bu yoruma zaten oy verdiniz.'];
		}

		DB::insert('rafimda_review_votes', ['kind' => $kind, 'id_review' => $idReview, 'id_user' => $idUser, 'date_add' => date('Y-m-d H:i:s')]);
		$n = (int) DB::getValue('SELECT COUNT(*) FROM rafimda_review_votes WHERE kind = ? AND id_review = ?', [$kind, $idReview]);

		if ($kind === 'store') {
			DB::update('rafimda_store_reviews', ['helpful' => $n], 'id_store_review = :i', ['i' => $idReview]);
		} elseif (DB::getRowSafe('rafimda_review_meta', 'id_review = ?', [$idReview])) {
			DB::update('rafimda_review_meta', ['helpful' => $n], 'id_review = :i', ['i' => $idReview]);
		} else {
			DB::insert('rafimda_review_meta', ['id_review' => $idReview, 'helpful' => $n, 'verified' => 0, 'photos' => '[]']);
		}

		return ['success' => true, 'message' => 'Teşekkürler!', 'helpful' => $n];
	}

	/** Satıcı yanıtı (yalnızca kendi ürününün/mağazasının yorumuna). */
	public static function reply(int $idVendor, string $kind, int $idReview, string $text): array
	{
		$text = trim(strip_tags($text));

		if (mb_strlen($text) < 3 || mb_strlen($text) > 1500) {
			return ['success' => false, 'message' => 'Yanıt 3-1500 karakter olmalı.'];
		}

		if ($kind === 'store') {
			$r = DB::getRowSafe('rafimda_store_reviews', 'id_store_review = ? AND id_vendor = ?', [$idReview, $idVendor]);

			if (!$r) {
				return ['success' => false, 'message' => 'Değerlendirme bulunamadı.'];
			}

			DB::update('rafimda_store_reviews', ['vendor_reply' => $text, 'vendor_reply_at' => date('Y-m-d H:i:s')], 'id_store_review = :i', ['i' => $idReview]);
		} else {
			$rows = self::available() ? (DB::execute('SELECT r.id_review, r.id_user FROM product_reviews r INNER JOIN rafimda_vendor_products vp ON vp.id_product = r.id_product WHERE r.id_review = ? AND vp.id_vendor = ?', [$idReview, $idVendor]) ?: []) : [];
			$r = $rows[0] ?? null;

			if (!$r) {
				return ['success' => false, 'message' => 'Değerlendirme bulunamadı.'];
			}

			if (DB::getRowSafe('rafimda_review_meta', 'id_review = ?', [$idReview])) {
				DB::update('rafimda_review_meta', ['vendor_reply' => $text, 'vendor_reply_at' => date('Y-m-d H:i:s'), 'id_vendor' => $idVendor], 'id_review = :i', ['i' => $idReview]);
			} else {
				DB::insert('rafimda_review_meta', ['id_review' => $idReview, 'id_vendor' => $idVendor, 'verified' => 0, 'photos' => '[]', 'helpful' => 0, 'vendor_reply' => $text, 'vendor_reply_at' => date('Y-m-d H:i:s')]);
			}
		}

		RvNotify::send(['id_user' => (int) $r['id_user'], 'email' => (string) DB::getValue('SELECT email FROM users WHERE id_user = ?', [(int) $r['id_user']])], 'review', 'Değerlendirmenize satıcı yanıt verdi', mb_substr($text, 0, 300), '');

		return ['success' => true, 'message' => 'Yanıtınız kaydedildi.'];
	}

	/** Yönetici moderasyonu (mağaza değerlendirmesi). Ürün yorumları FriSay yorum ekranından onaylanır. */
	public static function moderateStore(int $id, string $to): bool
	{
		return in_array($to, ['approved', 'rejected'], true) && DB::getRowSafe('rafimda_store_reviews', 'id_store_review = ?', [$id])
			&& DB::update('rafimda_store_reviews', ['status' => $to], 'id_store_review = :i', ['i' => $id]) !== false;
	}

	/** @return array<int,array<string,mixed>> satıcı paneli */
	public static function forVendor(int $idVendor): array
	{
		$out = ['product' => [], 'store' => DB::execute('SELECT * FROM rafimda_store_reviews WHERE id_vendor = ? ORDER BY id_store_review DESC LIMIT 200', [$idVendor]) ?: []];

		if (self::available()) {
			$out['product'] = DB::execute('SELECT r.id_review, r.id_product, r.rating, r.comment, r.active, r.date_add, r.author_name, p.product_name, m.verified, m.vendor_reply, m.helpful
				FROM product_reviews r INNER JOIN rafimda_vendor_products vp ON vp.id_product = r.id_product INNER JOIN products p ON p.id_product = r.id_product
				LEFT JOIN rafimda_review_meta m ON m.id_review = r.id_review WHERE vp.id_vendor = ? ORDER BY r.id_review DESC LIMIT 200', [$idVendor]) ?: [];
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> mağaza sayfası (yalnızca onaylı) */
	public static function publishedStore(int $idVendor, int $limit = 20): array
	{
		$rows = DB::execute("SELECT * FROM rafimda_store_reviews WHERE id_vendor = ? AND status = 'approved' ORDER BY id_store_review DESC LIMIT " . max(1, min(100, $limit)), [$idVendor]) ?: [];

		return array_map(static fn($r) => ['comment' => RvPrivacy::mask((string) $r['comment']), 'author' => RvQA::maskName((string) $r['author_name'])] + $r, $rows);
	}

	/** FriSay yorum satırını süsler (reviews modülü formatRow içinden): doğrulanmış etiket, fotoğraflar, faydalı sayısı, satıcı yanıtı. */
	public static function decorate(array $row): array
	{
		$m = isset($row['id_review']) ? DB::getRowSafe('rafimda_review_meta', 'id_review = ?', [(int) $row['id_review']]) : null;
		$row['comment'] = RvPrivacy::mask((string) ($row['comment'] ?? ''));
		$row['verified'] = $m && (int) $m['verified'] === 1;
		$row['helpful'] = (int) ($m['helpful'] ?? 0);
		$row['vendor_reply'] = (string) ($m['vendor_reply'] ?? '');
		$photos = json_decode((string) ($m['photos'] ?? '[]'), true);
		$row['photos'] = array_map(static fn($f) => RvView::url('img/rafimda/reviews/' . rawurlencode(basename((string) $f))), is_array($photos) ? $photos : []);

		return $row;
	}

	/** @return array<int,array<string,mixed>> değerlendirilebilecek kalemler (teslim edilmiş sipariş) */
	public static function reviewableItems(int $idUser, int $idOrder): array
	{
		$rows = DB::execute('SELECT od.id_product, od.product_name, vp.id_vendor, v.store_name FROM orders o INNER JOIN order_detail od ON od.id_order = o.id_order
			LEFT JOIN rafimda_vendor_products vp ON vp.id_product = od.id_product LEFT JOIN rafimda_vendors v ON v.id_vendor = vp.id_vendor
			WHERE o.id_order = ? AND o.id_user = ? AND o.status = ?', [$idOrder, $idUser, self::DELIVERED]) ?: [];

		foreach ($rows as &$r) {
			$r['reviewed'] = self::available() && (bool) DB::getValue('SELECT id_review FROM product_reviews WHERE id_product = ? AND id_user = ? LIMIT 1', [(int) $r['id_product'], $idUser]);
			$r['store_reviewed'] = (int) $r['id_vendor'] > 0 && (bool) DB::getRowSafe('rafimda_store_reviews', 'id_vendor = ? AND id_order = ? AND id_user = ?', [(int) $r['id_vendor'], $idOrder, $idUser]);
		}

		return $rows;
	}
}
