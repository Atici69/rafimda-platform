<?php

/**
 * Rafımda vitrin altyapısı: ana sayfa vitrin alanları (öne çıkan ürünler, kampanyalı ürünler, reklam bannerı).
 * - Yönetici alan/öğe yönetir; satıcı yalnızca KENDİ ürünü için ürün alanlarına talep açar (onay bekler).
 * - Ödeme / reklam faturalaması YOKTUR: yerleşim yönetici onayıyla ve süreyle belirlenir.
 * - Görünürlük: onaylı + zaman penceresinde + (ürünse) ürün aktif ve satıcı ürünü onaylı.
 */
class RsShowcase
{
	public const DEFAULT_SLOTS = [
		['code' => 'home_featured', 'title' => 'Öne Çıkan Ürünler', 'kind' => 'product', 'max_items' => 8, 'vendor_requestable' => 1, 'sort_order' => 1],
		['code' => 'home_campaign', 'title' => 'Kampanyalı Ürünler', 'kind' => 'product', 'max_items' => 8, 'vendor_requestable' => 1, 'sort_order' => 2],
		['code' => 'home_ad', 'title' => 'Reklam Alanı', 'kind' => 'banner', 'max_items' => 3, 'vendor_requestable' => 0, 'sort_order' => 3],
	];

	public static function ensureDefaults(): void
	{
		foreach (self::DEFAULT_SLOTS as $s) {
			if (!DB::getRowSafe('rs_slots', 'code = ?', [$s['code']])) {
				DB::insert('rs_slots', $s);
			}
		}
	}

	/** @return list<array<string,mixed>> */
	public static function slots(bool $onlyActive = false): array
	{
		self::ensureDefaults();

		return DB::execute('SELECT * FROM rs_slots' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort_order ASC, code ASC') ?: [];
	}

	/** @return array<string,mixed>|null */
	public static function slot(string $code): ?array
	{
		self::ensureDefaults();
		$r = DB::getRowSafe('rs_slots', 'code = ?', [$code]);

		return $r ?: null;
	}

	/** @param array<string,mixed> $d @return array{success:bool, message:string} */
	public static function saveSlot(string $code, array $d): array
	{
		if (!self::slot($code)) {
			return ['success' => false, 'message' => 'Alan bulunamadı.'];
		}

		$title = mb_substr(trim(strip_tags((string) ($d['title'] ?? ''))), 0, 80);
		$max = (int) ($d['max_items'] ?? 0);

		if (mb_strlen($title) < 2 || $max < 1 || $max > 24) {
			return ['success' => false, 'message' => 'Başlık en az 2 karakter, öğe sayısı 1-24 olmalı.'];
		}

		DB::update('rs_slots', ['title' => $title, 'max_items' => $max, 'active' => !empty($d['active']) ? 1 : 0, 'vendor_requestable' => !empty($d['vendor_requestable']) ? 1 : 0], 'code = :c', ['c' => $code]);

		return ['success' => true, 'message' => 'Alan kaydedildi.'];
	}

	/** Yalnızca site içi göreli yol veya https adresi (javascript:, data: vb. reddedilir). */
	public static function safeUrl(string $u): string
	{
		$u = trim($u);

		if ($u === '' || strlen($u) > 255 || preg_match('/[\x00-\x20"\'<>\\\\]/', $u)) {
			return '';
		}

		return preg_match('#^(https://[^\s/]+[^\s]*|/(?!/)[^\s]*)$#i', $u) ? $u : '';
	}

	private static function date(?string $s, bool $end): ?string
	{
		$s = trim((string) $s);

		if ($s === '') {
			return null;
		}

		$t = strtotime($s . ($end ? ' 23:59:59' : ' 00:00:00'));

		return $t === false ? null : date('Y-m-d H:i:s', $t);
	}

	/** Yönetici öğe ekler (onaylı). @param array<string,mixed> $d @return array{success:bool, message:string, id:int} */
	public static function addByAdmin(array $d): array
	{
		return self::insert($d, 0, 'approved');
	}

	/** Satıcı yalnızca KENDİ ürünü için talep açar (onay bekler). @return array{success:bool, message:string, id:int} */
	public static function request(int $idVendor, array $d): array
	{
		$slot = self::slot((string) ($d['slot_code'] ?? ''));

		if ($idVendor <= 0 || !$slot || !(int) $slot['active'] || !(int) $slot['vendor_requestable'] || $slot['kind'] !== 'product') {
			return ['success' => false, 'message' => 'Bu alana talep açılamaz.', 'id' => 0];
		}

		$idProduct = (int) ($d['id_product'] ?? 0);

		if (!class_exists('RvProducts') || !RvProducts::owns($idVendor, $idProduct)) {
			return ['success' => false, 'message' => 'Ürün bulunamadı.', 'id' => 0];
		}

		$open = (int) DB::getValue("SELECT COUNT(*) FROM rs_items WHERE id_vendor = ? AND status = 'pending'", [$idVendor]);

		if ($open >= 20) {
			return ['success' => false, 'message' => 'En fazla 20 bekleyen talebiniz olabilir.', 'id' => 0];
		}

		if (DB::getRowSafe('rs_items', "id_vendor = ? AND slot_code = ? AND id_product = ? AND status IN ('pending','approved')", [$idVendor, $slot['code'], $idProduct])) {
			return ['success' => false, 'message' => 'Bu ürün için bu alanda zaten bir talep/yerleşim var.', 'id' => 0];
		}

		$res = self::insert(['slot_code' => $slot['code'], 'id_product' => $idProduct, 'starts_at' => $d['starts_at'] ?? '', 'ends_at' => $d['ends_at'] ?? ''], $idVendor, 'pending');

		// Ticari kampanya modülü (varsa): ücretsiz dönem muafiyeti veya ücret borcu kaydı
		if ($res['success'] && class_exists('RcCampaign', false)) {
			RcCampaign::onShowcaseRequest($idVendor, (int) $res['id']);
		}

		return $res;
	}

	/** @param array<string,mixed> $d @return array{success:bool, message:string, id:int} */
	private static function insert(array $d, int $idVendor, string $status): array
	{
		$slot = self::slot((string) ($d['slot_code'] ?? ''));

		if (!$slot) {
			return ['success' => false, 'message' => 'Alan bulunamadı.', 'id' => 0];
		}

		$start = self::date($d['starts_at'] ?? '', false);
		$end = self::date($d['ends_at'] ?? '', true);

		if (($d['starts_at'] ?? '') !== '' && $start === null || ($d['ends_at'] ?? '') !== '' && $end === null || $start !== null && $end !== null && $end < $start) {
			return ['success' => false, 'message' => 'Tarih aralığı geçersiz.', 'id' => 0];
		}

		$row = ['slot_code' => $slot['code'], 'id_vendor' => $idVendor, 'starts_at' => $start, 'ends_at' => $end, 'status' => $status, 'position' => max(0, min(999, (int) ($d['position'] ?? 0)))];

		if ($slot['kind'] === 'product') {
			$p = DB::getRowSafe('products', 'id_product = ?', [(int) ($d['id_product'] ?? 0)]);

			if (!$p) {
				return ['success' => false, 'message' => 'Ürün bulunamadı.', 'id' => 0];
			}

			$row['id_product'] = (int) $p['id_product'];
		} else {
			$img = self::safeUrl((string) ($d['image_url'] ?? ''));
			$link = self::safeUrl((string) ($d['link_url'] ?? ''));
			$title = mb_substr(trim(strip_tags((string) ($d['title'] ?? ''))), 0, 120);

			if ($img === '' || $link === '' || mb_strlen($title) < 2) {
				return ['success' => false, 'message' => 'Banner için başlık, https/yerel görsel adresi ve bağlantı gerekli.', 'id' => 0];
			}

			$row += ['title' => $title, 'image_url' => $img, 'link_url' => $link];
		}

		$id = DB::insert('rs_items', $row);

		return $id ? ['success' => true, 'message' => $status === 'pending' ? 'Talep alındı; yönetici onayından sonra yayınlanır.' : 'Öğe eklendi.', 'id' => (int) $id] : ['success' => false, 'message' => 'Kaydedilemedi.', 'id' => 0];
	}

	public static function moderate(int $idItem, string $to, string $note = ''): bool
	{
		if (!in_array($to, ['approved', 'rejected', 'removed'], true) || !DB::getRowSafe('rs_items', 'id_item = ?', [$idItem])) {
			return false;
		}

		if (class_exists('RcCampaign', false)) {
			if ($to === 'approved' && !RcCampaign::canApprove($idItem)) {
				return false; // vitrin ücreti ödenmeden onaylanamaz
			}

			if ($to !== 'approved') {
				RcCampaign::voidForItem($idItem);
			}
		}

		return DB::update('rs_items', ['status' => $to, 'note' => mb_substr(trim(strip_tags($note)), 0, 255)], 'id_item = :i', ['i' => $idItem]) !== false;
	}

	/** Satıcı kendi bekleyen talebini geri çeker. */
	public static function withdraw(int $idVendor, int $idItem): bool
	{
		$ok = $idVendor > 0 && (bool) DB::update('rs_items', ['status' => 'removed'], "id_item = :i AND id_vendor = :v AND status IN ('pending','approved')", ['i' => $idItem, 'v' => $idVendor]);

		if ($ok && class_exists('RcCampaign', false)) {
			RcCampaign::voidForItem($idItem);
		}

		return $ok;
	}

	/** @return list<array<string,mixed>> */
	public static function forVendor(int $idVendor): array
	{
		return $idVendor <= 0 ? [] : (DB::execute('SELECT i.*, s.title AS slot_title, p.product_name FROM rs_items i JOIN rs_slots s ON s.code = i.slot_code LEFT JOIN products p ON p.id_product = i.id_product WHERE i.id_vendor = ? ORDER BY i.id_item DESC LIMIT 100', [$idVendor]) ?: []);
	}

	/** @return list<array<string,mixed>> */
	public static function adminList(string $status = 'pending'): array
	{
		$w = in_array($status, ['pending', 'approved', 'rejected', 'removed'], true) ? 'WHERE i.status = ?' : 'WHERE ? = ?';

		return DB::execute("SELECT i.*, s.title AS slot_title, p.product_name FROM rs_items i JOIN rs_slots s ON s.code = i.slot_code LEFT JOIN products p ON p.id_product = i.id_product $w ORDER BY i.id_item DESC LIMIT 200", in_array($status, ['pending', 'approved', 'rejected', 'removed'], true) ? [$status] : ['x', 'x']) ?: [];
	}

	/**
	 * Vitrinde gösterilecek öğeler (yalnızca geçerli olanlar).
	 *
	 * @return list<array<string,mixed>> {kind, title, url, image, price, old_price}
	 */
	public static function active(string $slotCode, ?int $now = null): array
	{
		$slot = self::slot($slotCode);

		if (!$slot || !(int) $slot['active']) {
			return [];
		}

		$at = date('Y-m-d H:i:s', $now ?? time());
		$rows = DB::execute("SELECT * FROM rs_items WHERE slot_code = ? AND status = 'approved' AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?) ORDER BY position ASC, id_item ASC LIMIT 100", [$slotCode, $at, $at]) ?: [];
		$out = [];

		foreach ($rows as $r) {
			if (count($out) >= (int) $slot['max_items']) {
				break;
			}

			if ($slot['kind'] === 'banner') {
				$out[] = ['kind' => 'banner', 'title' => (string) $r['title'], 'url' => self::safeUrl((string) $r['link_url']), 'image' => self::safeUrl((string) $r['image_url']), 'price' => null, 'old_price' => null];

				continue;
			}

			$p = DB::getRowSafe('products', 'id_product = ?', [(int) $r['id_product']]);

			if (!$p || !(int) $p['active'] || (float) $p['stock'] <= 0) {
				continue;
			}

			if ((int) $r['id_vendor'] > 0) {
				$vp = DB::getRowSafe('rafimda_vendor_products', 'id_product = ?', [(int) $r['id_product']]);

				if (!$vp || (int) $vp['id_vendor'] !== (int) $r['id_vendor'] || ($vp['moderation'] ?? 'approved') !== 'approved') {
					continue;
				}
			}

			$out[] = ['kind' => 'product', 'title' => (string) $p['product_name'], 'url' => self::productUrl($p), 'image' => self::productImage((int) $p['id_product']), 'price' => (float) $p['price'], 'old_price' => (float) ($p['old_price'] ?? 0) > (float) $p['price'] ? (float) $p['old_price'] : null];
		}

		return $out;
	}

	/** @param array<string,mixed> $p */
	private static function productUrl(array $p): string
	{
		if (class_exists('Product') && method_exists('Product', 'getLink')) {
			$c = DB::getRowSafe('categories', 'id_category = ?', [(int) ($p['id_category'] ?? 0)]);

			return (string) Product::getLink($p + ['category_link' => (string) ($c['category_link'] ?? 'urun')]);
		}

		return '/urun-' . (int) $p['id_product'];
	}

	private static function productImage(int $idProduct): string
	{
		$im = DB::getValue('SELECT id_image FROM images WHERE id_product = ? ORDER BY cover DESC, id_image ASC LIMIT 1', [$idProduct]);

		return $im !== false && class_exists('Product') && method_exists('Product', 'getImageUrl') ? (string) Product::getImageUrl((int) $im) : '';
	}

	/**
	 * Öne çıkan mağazalar: YALNIZCA gerçek, aktif ve en az bir aktif+onaylı ürünü olan mağazalar. Puan/takipçi yalnızca onaylı kayıtlardan.
	 * Mağaza yoksa boş döner (örnek/uydurma mağaza gösterilmez).
	 * @return array<int,array<string,mixed>>
	 */
	public static function featuredStores(int $limit = 8): array
	{
		if (!class_exists('RvStorefront') || !self::hasTable('rafimda_vendors')) {
			return [];
		}

		$rows = DB::execute("SELECT v.id_vendor, v.store_name, v.store_slug, v.city,
			(SELECT COUNT(*) FROM rafimda_vendor_products vp INNER JOIN products p ON p.id_product = vp.id_product WHERE vp.id_vendor = v.id_vendor AND p.active = 1 AND COALESCE(vp.moderation, 'approved') = 'approved') AS product_count,
			(SELECT COUNT(*) FROM rafimda_follows f WHERE f.id_vendor = v.id_vendor) AS followers
			FROM rafimda_vendors v WHERE v.status = 'active' ORDER BY followers DESC, product_count DESC, v.id_vendor ASC LIMIT 60") ?: [];
		$out = [];

		foreach ($rows as $r) {
			if ((int) $r['product_count'] < 1) {
				continue;
			}

			$id = (int) $r['id_vendor'];
			$rating = RvStorefront::rating($id);
			$profile = RvStorefront::profile($id);
			$out[] = [
				'name' => (string) $r['store_name'], 'url' => RvStorefront::url($r), 'city' => (string) $r['city'], 'logo' => (string) ($profile['logo_url'] ?? ''),
				'products' => (int) $r['product_count'], 'followers' => (int) $r['followers'], 'rating' => $rating['avg'], 'rating_count' => $rating['count'],
				'initial' => mb_strtoupper(mb_substr((string) $r['store_name'], 0, 1)),
			];

			if (count($out) >= max(1, min(24, $limit))) {
				break;
			}
		}

		return $out;
	}

	/** Son gezilen ürünler: yalnızca bu ziyaretçinin oturumunda (en fazla 12 kimlik); kişisel veri DB'ye yazılmaz */
	public static function rememberViewed(int $idProduct): void
	{
		if ($idProduct <= 0 || session_status() !== PHP_SESSION_ACTIVE && !isset($_SESSION)) {
			return;
		}

		$list = array_values(array_filter(array_map('intval', (array) ($_SESSION['rs_recent'] ?? [])), static fn($id) => $id > 0 && $id !== $idProduct));
		array_unshift($list, $idProduct);
		$_SESSION['rs_recent'] = array_slice($list, 0, 12);
	}

	/** @return array<int,array<string,mixed>> oturumdaki kimliklerden yalnızca hâlâ aktif olan ürünler, gezilme sırasıyla */
	public static function recentlyViewed(int $limit = 6): array
	{
		$ids = array_slice(array_values(array_filter(array_map('intval', (array) ($_SESSION['rs_recent'] ?? [])))), 0, 12);
		$out = [];

		foreach ($ids as $id) {
			$p = DB::getRowSafe('products', 'id_product = ? AND active = 1', [$id]);

			if (!$p) {
				continue;
			}

			$out[] = ['kind' => 'product', 'title' => (string) $p['product_name'], 'url' => self::productUrl($p), 'image' => self::productImage($id), 'price' => (float) $p['price'], 'old_price' => (float) ($p['old_price'] ?? 0) > (float) $p['price'] ? (float) $p['old_price'] : null];

			if (count($out) >= $limit) {
				break;
			}
		}

		return $out;
	}

	private static function hasTable(string $t): bool
	{
		try {
			DB::execute('SELECT 1 FROM ' . $t . ' LIMIT 1');

			return true;
		} catch (Throwable $e) {
			return false;
		}
	}
}
