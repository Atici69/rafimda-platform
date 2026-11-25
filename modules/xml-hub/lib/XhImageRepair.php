<?php

/**
 * Görsel sağlık kontrolü ve onarımı.
 *
 * Sorun: veritabanında görsel kaydı (images) olduğu hâlde img/products/{id}.jpg dosyası olmayan ürünler sitede görselsiz görünür
 * (yazma izni sorunu, sunucu taşıma/paket kurulumu sırasında klasörün boşalması vb.).
 * Onarım: XML ile gelen ürünlerde, içe aktarmada saklanan görsel adresleri (xh_product_meta.image_urls, kayıt sırasıyla)
 * yeniden indirilip AYNI görsel numarasına yazılır. Kayıt silinmez, yeni kayıt açılmaz; yalnızca eksik dosya oluşturulur.
 */
class XhImageRepair
{
	public static function dir(): string
	{
		return dirname(__DIR__, 3) . '/img/products';
	}

	/** @return array{writable:bool, dir_exists:bool, total:int, missing:int, repairable:int} */
	public static function health(int $scanLimit = 20000): array
	{
		$dir = self::dir();
		$rows = DB::execute('SELECT i.id_image, i.id_product FROM images i ORDER BY i.id_image DESC LIMIT ' . max(1, $scanLimit)) ?: [];
		$missing = [];

		foreach ($rows as $r) {
			if (!is_file($dir . '/' . (int) $r['id_image'] . '.jpg')) {
				$missing[(int) $r['id_product']] = true;
			}
		}

		$repairable = 0;

		if ($missing !== []) {
			foreach (array_chunk(array_keys($missing), 500) as $chunk) {
				$repairable += (int) DB::getValue('SELECT COUNT(*) FROM xh_product_meta WHERE image_urls IS NOT NULL AND image_urls NOT IN (\'\', \'[]\') AND id_product IN (' . implode(',', array_map('intval', $chunk)) . ')');
			}
		}

		$miss = 0;

		foreach ($rows as $r) {
			$miss += is_file($dir . '/' . (int) $r['id_image'] . '.jpg') ? 0 : 1;
		}

		return ['writable' => is_dir($dir) && is_writable($dir), 'dir_exists' => is_dir($dir), 'total' => count($rows), 'missing' => $miss, 'repairable' => $repairable];
	}

	/**
	 * En çok $limit ürünün eksik görsel dosyalarını yeniden indirir.
	 *
	 * @return array{products:int, fixed:int, failed:int, errors:list<string>}
	 */
	public static function repair(int $limit = 30): array
	{
		$out = ['products' => 0, 'fixed' => 0, 'failed' => 0, 'errors' => []];
		$dir = self::dir();
		$metas = DB::execute("SELECT id_product, image_urls FROM xh_product_meta WHERE image_urls IS NOT NULL AND image_urls NOT IN ('', '[]') ORDER BY id_product DESC") ?: [];

		foreach ($metas as $m) {
			if ($out['products'] >= $limit) {
				break;
			}

			$urls = array_values(array_filter((array) json_decode((string) $m['image_urls'], true), 'is_string'));
			$imgs = DB::execute('SELECT id_image FROM images WHERE id_product = ? ORDER BY id_image ASC', [(int) $m['id_product']]) ?: [];
			$todo = [];

			foreach ($imgs as $i => $im) {
				if (!is_file($dir . '/' . (int) $im['id_image'] . '.jpg') && isset($urls[$i])) {
					$todo[(int) $im['id_image']] = $urls[$i];
				}
			}

			if ($todo === []) {
				continue;
			}

			$out['products']++;

			foreach ($todo as $idImage => $url) {
				$r = XhHttp::fetchToString($url, 5242880, 10);

				if (!$r['ok']) {
					$out['failed']++;
					$out['errors'][] = '#' . (int) $m['id_product'] . ': ' . mb_substr($url, 0, 70) . ' alınamadı (' . $r['error'] . ')';
					continue;
				}

				$w = Product::writeImageFile($idImage, (string) $r['body']);

				if ($w['success']) {
					$out['fixed']++;
				} else {
					$out['failed']++;
					$out['errors'][] = '#' . (int) $m['id_product'] . ': ' . $w['message'];

					if (!is_writable($dir)) {
						return $out; // izin sorunu: devam etmenin anlamı yok
					}
				}
			}
		}

		return $out;
	}
}
