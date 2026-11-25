<?php

/**
 * Görsel sağlık kontrolü ve onarımı.
 *
 * Sorun: ürünler sitede görselsiz görünüyor. İki kaynak:
 *  1) Veritabanında görsel kaydı (images) var ama img/products/{id}.jpg dosyası yok
 *     (eski kod kaydı açıp dosyayı yazamadan çıkıyordu: sunucu GD'si WEBP okuyamıyor, bellek, izin…).
 *  2) Eski akışta indirme "başarısız" sayıldığı için xh_product_meta.image_urls boş kaldı ve sonraki
 *     senkronlar "ürünün görseli var" diyerek tekrar denemedi.
 *
 * Onarım (silme yok):
 *  - Görsel adresleri önce xh_product_meta.image_urls'ten, boşsa XML'in saklanan ham satırından (xh_items.data,
 *    bağlantının görsel eşlemesiyle) alınır.
 *  - Dosyası eksik kayıtlar SIRAYLA aynı görsel numarasına yeniden indirilir (yeni kayıt açılmaz).
 *  - Hiç görsel kaydı olmayan XML ürününe görseller normal yoldan eklenir.
 *  - Kapak görselinin dosyası yoksa ya da kapak işaretli görsel yoksa, dosyası olan ilk görsel kapak yapılır.
 */
class XhImageRepair
{
	/** Yalnızca testler için: görsel klasörünü değiştirir. */
	public static ?string $dirOverride = null;

	public static function dir(): string
	{
		return self::$dirOverride ?? dirname(__DIR__, 3) . '/img/products';
	}

	private static function hasFile(int $idImage): bool
	{
		return is_file(self::dir() . '/' . $idImage . '.jpg');
	}

	/** @return array{writable:bool, dir_exists:bool, total:int, missing:int, products_without_image:int, repairable:int, server:array} */
	public static function health(int $scanLimit = 20000): array
	{
		$dir = self::dir();
		$rows = DB::execute('SELECT id_image, id_product FROM images ORDER BY id_image DESC LIMIT ' . max(1, $scanLimit)) ?: [];
		$miss = 0;

		foreach ($rows as $r) {
			$miss += self::hasFile((int) $r['id_image']) ? 0 : 1;
		}

		$broken = self::brokenProductIds(5000);

		if (!class_exists('ImageWriter', false)) {
			require_once dirname(__DIR__, 3) . '/core/ImageWriter.php';
		}

		return [
			'writable' => is_dir($dir) && is_writable($dir),
			'dir_exists' => is_dir($dir),
			'total' => count($rows),
			'missing' => $miss,
			'products_without_image' => count($broken),
			'repairable' => count(array_filter(array_slice($broken, 0, 500), static fn(int $id) => self::sourceUrls($id) !== [])),
			'server' => ImageWriter::diagnostics(),
		];
	}

	/**
	 * XML ile gelmiş, sitede görseli görünmeyen ürünler: kapak dosyası yok ya da hiç görsel kaydı yok.
	 *
	 * @return list<int>
	 */
	public static function brokenProductIds(int $limit = 5000): array
	{
		$ids = array_map('intval', array_column(DB::execute('SELECT id_product FROM xh_product_meta ORDER BY id_product DESC LIMIT ' . max(1, $limit)) ?: [], 'id_product'));
		$out = [];

		foreach ($ids as $id) {
			$imgs = DB::execute('SELECT id_image, cover FROM images WHERE id_product = ? ORDER BY id_image ASC', [$id]) ?: [];
			$coverOk = false;

			foreach ($imgs as $im) {
				if ((int) $im['cover'] === 1 && self::hasFile((int) $im['id_image'])) {
					$coverOk = true;
					break;
				}
			}

			if (!$coverOk) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Ürünün XML'deki görsel adresleri (sıralı).
	 *
	 * @return list<string>
	 */
	public static function sourceUrls(int $idProduct): array
	{
		$meta = DB::getRowSafe('xh_product_meta', 'id_product = ?', [$idProduct]);
		$urls = $meta ? self::urlList(json_decode((string) ($meta['image_urls'] ?? ''), true)) : [];

		if ($urls !== []) {
			return $urls;
		}

		$item = DB::execute('SELECT i.data, c.mapping FROM xh_items i LEFT JOIN xh_connections c ON c.id_connection = i.id_connection
			WHERE i.id_product = ? AND i.data IS NOT NULL AND i.data <> \'\' ORDER BY i.id_item DESC LIMIT 1', [$idProduct]);

		if (!$item) {
			return [];
		}

		$raw = json_decode((string) $item[0]['data'], true);

		if (!is_array($raw)) {
			return [];
		}

		$map = json_decode((string) ($item[0]['mapping'] ?? ''), true);
		$spec = is_array($map) ? (string) ($map['fields']['images'] ?? '') : '';
		$keys = $spec !== '' && $spec[0] !== '=' ? array_filter(array_map('trim', explode('|', $spec))) : [];

		// Eşlemede görsel alanı yoksa yaygın adlar (image, image1, resim…) denenir
		if ($keys === []) {
			$keys = array_values(array_filter(array_keys($raw), static fn($k) => is_string($k) && preg_match('/^(image|img|picture|resim|gorsel|photo)[\w]*$/i', $k)));
		}

		$vals = [];

		foreach ($keys as $k) {
			if (array_key_exists($k, $raw)) {
				$vals[] = $raw[$k];
			}
		}

		return self::urlList($vals);
	}

	/** @param mixed $v @return list<string> */
	private static function urlList($v): array
	{
		$out = [];
		$walk = static function ($x) use (&$walk, &$out): void {
			if (is_array($x)) {
				foreach ($x as $y) {
					$walk($y);
				}

				return;
			}

			foreach (preg_split('/[\s|;,]+(?=https?:)/i', trim((string) $x)) ?: [] as $u) {
				$u = trim($u);

				if (preg_match('#^https?://[^\s<>"]+$#i', $u) && strlen($u) <= 500) {
					$out[$u] = true;
				}
			}
		};
		$walk($v);

		return array_slice(array_keys($out), 0, 10);
	}

	/**
	 * En çok $limit ürünü onarır. Tekrar basılabilir; düzelenler bir sonraki turda atlanır.
	 *
	 * @return array{products:int, fixed:int, failed:int, covers:int, no_source:int, errors:list<string>}
	 */
	public static function repair(int $limit = 30): array
	{
		$out = ['products' => 0, 'fixed' => 0, 'failed' => 0, 'covers' => 0, 'no_source' => 0, 'errors' => []];

		if (!is_dir(self::dir()) || !is_writable(self::dir())) {
			$out['errors'][] = 'img/products klasörüne yazılamıyor; önce klasör izinlerini düzeltin (755).';

			return $out;
		}

		foreach (self::brokenProductIds(5000) as $id) {
			if ($out['products'] >= $limit) {
				break;
			}

			$urls = self::sourceUrls($id);

			if ($urls === []) {
				// Adres yok: yalnızca kapak düzeltilebilir; sınıra sayılmaz ki sıradaki ürünler takılmasın
				$out['covers'] += self::fixCover($id) ? 1 : 0;
				$out['no_source']++;
				continue;
			}

			$out['products']++;
			$imgs = DB::execute('SELECT id_image FROM images WHERE id_product = ? ORDER BY id_image ASC', [$id]) ?: [];

			// 1) Dosyası eksik kayıtlar: aynı sırayla aynı görsel numarasına yaz
			foreach ($imgs as $i => $im) {
				$idImage = (int) $im['id_image'];

				if (self::hasFile($idImage) || !isset($urls[$i])) {
					continue;
				}

				self::fetchInto($id, $idImage, $urls[$i], $out);
			}

			// 2) Hiç kaydı yoksa normal yoldan ekle (kayıt yalnızca dosya yazılırsa açılır)
			if ($imgs === [] && $urls !== []) {
				$meta = DB::getRowSafe('xh_product_meta', 'id_product = ?', [$id]);
				$idVendor = $meta ? (int) ($meta['id_vendor'] ?? 0) : 0;
				$r = XhSink::addImages($idVendor, $id, $urls, 5);
				$out['fixed'] += $r['added'];
				$out['failed'] += count($r['warnings']);

				foreach (array_slice($r['warnings'], 0, 2) as $w) {
					$out['errors'][] = '#' . $id . ': ' . $w;
				}

				if ($r['done'] !== []) {
					XhMeta::save($id, $idVendor, ['image_urls' => json_encode($r['done'])]);
				}
			}

			$out['covers'] += self::fixCover($id) ? 1 : 0;
		}

		return $out;
	}

	/** Kapak dosyası yoksa dosyası olan ilk görseli kapak yapar. Değişiklik yaptıysa true. */
	public static function fixCover(int $idProduct): bool
	{
		$imgs = DB::execute('SELECT id_image, cover FROM images WHERE id_product = ? ORDER BY id_image ASC', [$idProduct]) ?: [];
		$first = 0;

		foreach ($imgs as $im) {
			if (!self::hasFile((int) $im['id_image'])) {
				continue;
			}

			if ((int) $im['cover'] === 1) {
				return false; // kapak zaten sağlam
			}

			$first = $first ?: (int) $im['id_image'];
		}

		if ($first > 0) {
			Product::setCover($first);

			return true;
		}

		return false;
	}

	private static function fetchInto(int $idProduct, int $idImage, string $url, array &$out): void
	{
		$r = XhHttp::fetchToString($url, 5242880, 10);

		if (!$r['ok']) {
			$out['failed']++;
			$out['errors'][] = '#' . $idProduct . ': ' . mb_substr($url, 0, 70) . ' alınamadı (' . $r['error'] . ')';

			return;
		}

		$w = Product::writeImageFile($idImage, (string) $r['body']);

		if ($w['success']) {
			$out['fixed']++;
		} else {
			$out['failed']++;
			$out['errors'][] = '#' . $idProduct . ': ' . $w['message'];
		}
	}
}
