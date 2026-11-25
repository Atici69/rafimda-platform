<?php

/**
 * Ürün görselini diske güvenli yazar (bağımlılıksız; Product::writeImageFile ve testler kullanır).
 *
 * Neden ayrı sınıf: paylaşımlı hostinglerde (Natro vb.) GD bazen WEBP okuyamaz ya da büyük görselde bellek yetmez.
 * Eski kod bu durumda veritabanına görsel kaydı açıp dosyayı yazamadan çıkıyordu → sitede görselsiz ürün.
 * Burada sırayla denenir: GD ile JPEG'e çevirme → Imagick → (son çare) dosyanın kendisini yazma.
 * Tarayıcılar <img> içinde biçimi içerikten tanır; .jpg uzantılı WEBP de görüntülenir.
 */
class ImageWriter
{
	/** Yalnızca testler için: GD'si WEBP okuyamayan sunucuyu taklit eder. */
	public static bool $disableGd = false;

	/** @return array{success:bool, message:string, mode:string} mode: gd | imagick | raw */
	public static function write(string $dest, string $binary): array
	{
		if ($binary === '') {
			return self::fail('Görsel verisi boş');
		}

		$info = @getimagesizefromstring($binary);

		if (!$info || empty($info[0]) || empty($info[1])) {
			return self::fail('Dosya bir görsel değil');
		}

		$type = (int) $info[2];
		$allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

		if (!in_array($type, $allowed, true)) {
			return self::fail('Desteklenmeyen görsel biçimi');
		}

		$dir = dirname($dest);

		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return self::fail('Görsel klasörü (img/products) oluşturulamadı');
		}

		if (!is_writable($dir)) {
			return self::fail('Görsel klasörüne (img/products) yazılamıyor: klasör izinlerini kontrol edin (755, sahibi site kullanıcısı)');
		}

		$w = (int) $info[0];
		$h = (int) $info[1];
		$data = null;
		$mode = '';

		if (!self::$disableGd && self::gdCanDecode($type) && self::memoryFits($w, $h, $type !== IMAGETYPE_JPEG)) {
			$data = self::viaGd($binary, $type);
			$mode = 'gd';
		}

		if ($data === null && class_exists('Imagick')) {
			$data = self::viaImagick($binary);
			$mode = 'imagick';
		}

		if ($data === null) {
			// Son çare: dönüştürülemedi ama geçerli bir görsel (boyutları okundu) → olduğu gibi yaz.
			$data = $binary;
			$mode = 'raw';
		}

		$tmp = $dest . '.tmp' . bin2hex(random_bytes(3));
		$ok = @file_put_contents($tmp, $data) === strlen($data);

		if (!$ok || !is_file($tmp) || filesize($tmp) === 0 || !@rename($tmp, $dest)) {
			@unlink($tmp);

			return self::fail('Görsel dosyası yazılamadı (img/products izinleri veya disk kotası)');
		}

		return ['success' => true, 'message' => '', 'mode' => $mode];
	}

	/** Sunucunun görsel işleme durumu (yönetim panelindeki sağlık kontrolü için). */
	public static function diagnostics(): array
	{
		$gd = function_exists('imagecreatefromstring');

		return [
			'gd' => $gd,
			'gd_webp' => $gd && self::gdCanDecode(IMAGETYPE_WEBP),
			'gd_png' => $gd && self::gdCanDecode(IMAGETYPE_PNG),
			'imagick' => class_exists('Imagick'),
			'memory_limit' => (string) ini_get('memory_limit'),
			'curl' => function_exists('curl_init'),
		];
	}

	public static function gdCanDecode(int $type): bool
	{
		if (!function_exists('imagecreatefromstring') || !function_exists('imagetypes')) {
			return false;
		}

		$bit = [
			IMAGETYPE_JPEG => defined('IMG_JPG') ? IMG_JPG : 0,
			IMAGETYPE_PNG => defined('IMG_PNG') ? IMG_PNG : 0,
			IMAGETYPE_GIF => defined('IMG_GIF') ? IMG_GIF : 0,
			IMAGETYPE_WEBP => defined('IMG_WEBP') ? IMG_WEBP : 0,
		][$type] ?? 0;

		return $bit !== 0 && (imagetypes() & $bit) !== 0;
	}

	/** GD tam renkli görsel için piksel başına ~5 bayt kullanır; şeffaf görselde beyaz zemin için ikinci tuval gerekir. */
	public static function memoryFits(int $w, int $h, bool $needsCanvas, ?int $limitBytes = null): bool
	{
		$limit = $limitBytes ?? self::bytes((string) ini_get('memory_limit'));

		if ($limit <= 0) {
			return true; // -1 = sınırsız
		}

		$need = (int) ($w * $h * 5 * ($needsCanvas ? 2 : 1) * 1.2);

		return memory_get_usage(true) + $need < $limit;
	}

	public static function bytes(string $v): int
	{
		$v = trim($v);

		if ($v === '' || $v === '-1') {
			return -1;
		}

		$n = (int) $v;

		switch (strtolower(substr($v, -1))) {
			case 'g':
				return $n * 1073741824;
			case 'm':
				return $n * 1048576;
			case 'k':
				return $n * 1024;
		}

		return $n;
	}

	private static function viaGd(string $binary, int $type): ?string
	{
		$src = @imagecreatefromstring($binary);

		if (!$src) {
			return null;
		}

		$img = $src;

		if ($type !== IMAGETYPE_JPEG) {
			// Şeffaf PNG/WEBP/GIF JPEG'de siyah zemin olmasın
			$img = imagecreatetruecolor(imagesx($src), imagesy($src));
			imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
			imagecopy($img, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
			imagedestroy($src);
		}

		ob_start();
		$ok = @imagejpeg($img, null, 88);
		$out = (string) ob_get_clean();
		imagedestroy($img);

		return $ok && $out !== '' ? $out : null;
	}

	private static function viaImagick(string $binary): ?string
	{
		try {
			$im = new Imagick();
			$im->readImageBlob($binary);
			$im->setImageBackgroundColor('white');
			$flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
			$flat->setImageFormat('jpeg');
			$flat->setImageCompressionQuality(88);
			$flat->stripImage();
			$out = $flat->getImageBlob();
			$flat->clear();
			$im->clear();

			return $out !== '' ? $out : null;
		} catch (Throwable $e) {
			return null;
		}
	}

	private static function fail(string $m): array
	{
		return ['success' => false, 'message' => $m, 'mode' => ''];
	}
}
