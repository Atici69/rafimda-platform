<?php

/**
 * Ürün verisinden tanıtım içeriği: metin (başlık, açıklama, hashtag) + görsel (Instagram gönderi 1080×1080, hikâye 1080×1920) +
 * dikey kısa video (TikTok / Reels / YouTube Shorts, 1080×1920, ffmpeg varsa). Yalnızca ÜRÜNÜN GERÇEK alanları kullanılır;
 * olmayan özellik, sahte indirim veya uydurma yorum eklenmez. İleride yapay zekâ ile üretim için RsoContent::$videoRenderer değiştirilebilir.
 */
final class RsoContent
{
	public const FORMATS = [
		'ig_post' => ['Instagram gönderisi', 'instagram', 1080, 1080, 'image'],
		'ig_story' => ['Instagram hikâyesi', 'instagram', 1080, 1920, 'image'],
		'reels' => ['Instagram Reels (dikey video)', 'instagram', 1080, 1920, 'video'],
		'tiktok' => ['TikTok videosu', 'tiktok', 1080, 1920, 'video'],
		'yt_short' => ['YouTube Shorts', 'youtube', 1080, 1920, 'video'],
	];

	public static ?string $mediaDirOverride = null;

	/** Testlerde ürün görsel dizini */
	public static ?string $productImgDir = null;

	/** Gelecekte yapay zekâ/başka motor: fn(array $frames, string $out, int $seconds): bool */
	public static $videoRenderer = null;

	public static function mediaDir(): string
	{
		return self::$mediaDirOverride ?? dirname(__DIR__, 3) . '/img/rafimda/social';
	}

	public static function mediaUrl(string $file): string
	{
		global $domain;

		return rtrim((string) $domain, '/') . '/img/rafimda/social/' . rawurlencode(basename($file));
	}

	/** Ürünün gerçek verileri (yalnızca aktif ürün). */
	public static function product(int $idProduct): ?array
	{
		$rows = DB::execute('SELECT p.*, c.category_name, c.category_link, b.brand_name, v.store_name, v.id_vendor FROM products p
			LEFT JOIN categories c ON c.id_category = p.id_category LEFT JOIN brands b ON b.id_brand = p.id_brand
			LEFT JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product LEFT JOIN rafimda_vendors v ON v.id_vendor = vp.id_vendor
			WHERE p.id_product = ? LIMIT 1', [$idProduct]) ?: [];
		$p = $rows[0] ?? null;

		if (!$p) {
			return null;
		}

		$p['images'] = array_values(array_filter(array_map(static fn($r) => (self::$productImgDir ?? dirname(__DIR__, 3) . '/img/products') . '/' . (int) $r['id_image'] . '.jpg',
			DB::execute('SELECT id_image FROM images WHERE id_product = ? ORDER BY cover DESC, id_image ASC LIMIT 6', [$idProduct]) ?: []), 'is_file'));
		$p['url'] = class_exists('Product') && method_exists('Product', 'getLink') && !empty($p['category_link'])
			? Product::getLink($p) : rtrim((string) ($GLOBALS['domain'] ?? ''), '/') . '/';

		return $p;
	}

	public static function money(float $v): string
	{
		return number_format($v, 2, ',', '.') . ' TL';
	}

	private static function tag(string $s): string
	{
		$s = mb_strtolower(trim($s), 'UTF-8');
		$s = strtr($s, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u']);

		return (string) preg_replace('/[^a-z0-9]/', '', $s);
	}

	/** @return array{title:string,caption:string,hashtags:string} */
	public static function text(array $p, string $format): array
	{
		$name = trim((string) $p['product_name']);
		$price = (float) $p['price'];
		$old = (float) ($p['old_price'] ?? 0);
		$discount = $old > $price && $price > 0; // yalnızca ürün kaydında gerçekten eski fiyat varsa
		$desc = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($p['short_description'] ?? '') ?: (string) ($p['description'] ?? ''))) ?? '');
		$desc = mb_strlen($desc) > 220 ? rtrim(mb_substr($desc, 0, 217)) . '…' : $desc;
		$lines = [$name];

		if (!empty($p['brand_name'])) {
			$lines[] = 'Marka: ' . $p['brand_name'];
		}

		if ($desc !== '') {
			$lines[] = $desc;
		}

		$lines[] = $discount ? 'Fiyat: ' . self::money($price) . ' (önceki fiyat ' . self::money($old) . ')' : 'Fiyat: ' . self::money($price);

		if (!empty($p['store_name'])) {
			$lines[] = 'Satıcı: ' . $p['store_name'];
		}

		$lines[] = $format === 'ig_story' ? 'Bağlantı için profilimizdeki linke dokunun.' : 'İncele: ' . $p['url'];
		$lines[] = 'Fiyat ve stok bilgisi paylaşım anındaki durumdur.';
		$tags = array_values(array_unique(array_filter(['rafimda', self::tag((string) ($p['category_name'] ?? '')), self::tag((string) ($p['brand_name'] ?? '')), $discount ? 'indirim' : '', 'alisveris'])));

		return [
			'title' => mb_substr($name . ($discount ? ' – indirimde' : ''), 0, 100),
			'caption' => mb_substr(implode("\n", $lines), 0, 2100),
			'hashtags' => implode(' ', array_map(static fn($t) => '#' . $t, array_slice($tags, 0, 8))),
		];
	}

	private static function font(bool $bold): string
	{
		return dirname(__DIR__) . '/assets/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf';
	}

	/** Metni kutuya sığdırır (satır kaydırma). */
	private static function wrap(string $text, float $size, string $font, int $maxW, int $maxLines): array
	{
		$out = [];
		$line = '';

		foreach (preg_split('/\s+/u', trim($text)) ?: [] as $w) {
			$try = $line === '' ? $w : $line . ' ' . $w;
			$box = imagettfbbox($size, 0, $font, $try);

			if ($box !== false && ($box[2] - $box[0]) > $maxW && $line !== '') {
				$out[] = $line;
				$line = $w;
			} else {
				$line = $try;
			}

			if (count($out) >= $maxLines) {
				break;
			}
		}

		if ($line !== '' && count($out) < $maxLines) {
			$out[] = $line;
		}

		if (count($out) === $maxLines && mb_strlen(implode(' ', $out)) < mb_strlen($text)) {
			$out[$maxLines - 1] = rtrim(mb_substr($out[$maxLines - 1], 0, max(1, mb_strlen($out[$maxLines - 1]) - 1))) . '…';
		}

		return $out;
	}

	/** Şablon tabanlı görsel (lacivert/turuncu). $imgIndex: kaçıncı ürün görseli. Döner: dosya adı veya ''. */
	public static function renderImage(array $p, int $w, int $h, int $imgIndex = 0, string $suffix = ''): string
	{
		if (!function_exists('imagettftext')) {
			return '';
		}

		$im = imagecreatetruecolor($w, $h);
		$navy = imagecolorallocate($im, 11, 37, 69);
		$navy2 = imagecolorallocate($im, 19, 49, 92);
		$orange = imagecolorallocate($im, 255, 122, 0);
		$white = imagecolorallocate($im, 255, 255, 255);
		$muted = imagecolorallocate($im, 200, 210, 225);
		imagefill($im, 0, 0, $navy);
		imagefilledrectangle($im, 0, (int) ($h * 0.62), $w, $h, $navy2);
		$pad = (int) round($w * 0.06);
		$boxH = (int) ($h * ($h > $w ? 0.52 : 0.56));
		$boxTop = (int) ($h > $w ? $h * 0.12 : $h * 0.08);
		imagefilledrectangle($im, $pad, $boxTop, $w - $pad, $boxTop + $boxH, $white);
		$src = $p['images'][$imgIndex] ?? ($p['images'][0] ?? '');

		if ($src !== '' && ($pi = @imagecreatefromjpeg($src))) {
			$sw = imagesx($pi);
			$sh = imagesy($pi);
			$scale = min(($w - 2 * $pad - 40) / $sw, ($boxH - 40) / $sh);
			$dw = (int) ($sw * $scale);
			$dh = (int) ($sh * $scale);
			imagecopyresampled($im, $pi, (int) (($w - $dw) / 2), $boxTop + (int) (($boxH - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
			imagedestroy($pi);
		} else {
			$bb = imagettfbbox(38, 0, self::font(false), 'Ürün görseli yok');
			imagettftext($im, 38, 0, (int) (($w - ($bb[2] - $bb[0])) / 2), $boxTop + (int) ($boxH / 2), $muted, self::font(false), 'Ürün görseli yok');
		}

		$logo = imagettftext($im, (int) ($w * 0.034), 0, $pad, (int) ($boxTop * 0.62) + 10, $white, self::font(true), 'Rafımda');
		imagettftext($im, (int) ($w * 0.02), 0, (int) $logo[2] + (int) ($w * 0.025), (int) ($boxTop * 0.62) + 8, $orange, self::font(false), 'Aradığınız Her Şey, Rafımda!');
		$y = $boxTop + $boxH + (int) ($h * 0.07);
		$size = (int) ($w * 0.045);

		foreach (self::wrap((string) $p['product_name'], $size, self::font(true), $w - 2 * $pad, 2) as $ln) {
			imagettftext($im, $size, 0, $pad, $y, $white, self::font(true), $ln);
			$y += (int) ($size * 1.45);
		}

		$price = (float) $p['price'];
		$old = (float) ($p['old_price'] ?? 0);
		$psize = (int) ($w * 0.06);
		$y += (int) ($h * 0.01);
		imagettftext($im, $psize, 0, $pad, $y + $psize, $orange, self::font(true), self::money($price));

		if ($old > $price && $price > 0) {
			$bb = imagettfbbox($psize, 0, self::font(true), self::money($price));
			$ox = $pad + ($bb[2] - $bb[0]) + 30;
			$osz = (int) ($psize * 0.5);
			imagettftext($im, $osz, 0, $ox, $y + $psize, $muted, self::font(false), self::money($old));
			$ob = imagettfbbox($osz, 0, self::font(false), self::money($old));
			imagesetthickness($im, 3);
			imageline($im, $ox, $y + $psize - (int) ($osz * 0.35), $ox + ($ob[2] - $ob[0]), $y + $psize - (int) ($osz * 0.35), $muted);
		}

		$foot = (string) (!empty($p['store_name']) ? 'Satıcı: ' . $p['store_name'] . ' · rafimda.com.tr' : 'rafimda.com.tr');
		imagettftext($im, (int) ($w * 0.022), 0, $pad, $h - (int) ($h * 0.04), $muted, self::font(false), $foot);
		$dir = self::mediaDir();

		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			imagedestroy($im);

			return '';
		}

		$name = 'p' . (int) $p['id_product'] . '-' . $w . 'x' . $h . $suffix . '-' . bin2hex(random_bytes(5)) . '.jpg';
		$ok = imagejpeg($im, $dir . '/' . $name, 88);
		imagedestroy($im);

		return $ok ? $name : '';
	}

	/** @var array<string,bool> */
	private static array $tables = [];

	public static function tableExists(string $t): bool
	{
		$t = (string) preg_replace('/[^a-z_]/', '', $t);

		return self::$tables[$t] ??= DB::execute('SELECT 1 FROM ' . $t . ' LIMIT 1') !== false;
	}

	public static function ffmpeg(): string
	{
		if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
			return '';
		}

		foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $b) {
			if (is_file($b) && is_executable($b)) {
				return $b;
			}
		}

		return '';
	}

	/**
	 * Dikey kısa video: ürün görsellerinden 3-4 kare (şablonlu) + yumuşak geçiş, sessiz MP4 (H.264). ffmpeg yoksa yalnızca kareler (storyboard) üretilir.
	 * @return array{video:string,frames:list<string>,message:string}
	 */
	public static function renderVideo(array $p, int $seconds = 9): array
	{
		$n = max(1, min(4, count($p['images']) ?: 1));
		$frames = [];

		for ($i = 0; $i < $n; $i++) {
			$f = self::renderImage($p, 1080, 1920, $i, '-f' . $i);

			if ($f !== '') {
				$frames[] = $f;
			}
		}

		if ($frames === []) {
			return ['video' => '', 'frames' => [], 'message' => 'Görsel üretilemedi (GD/FreeType gerekli).'];
		}

		$out = 'p' . (int) $p['id_product'] . '-video-' . bin2hex(random_bytes(5)) . '.mp4';
		$dir = self::mediaDir();
		$paths = array_map(static fn($f) => $dir . '/' . $f, $frames);

		if (is_callable(self::$videoRenderer)) {
			$ok = (bool) (self::$videoRenderer)($paths, $dir . '/' . $out, $seconds);

			return ['video' => $ok ? $out : '', 'frames' => $frames, 'message' => $ok ? 'Video üretildi.' : 'Video motoru başarısız.'];
		}

		$bin = self::ffmpeg();

		if ($bin === '') {
			return ['video' => '', 'frames' => $frames, 'message' => 'Sunucuda ffmpeg yok: video yerine kare dizisi (storyboard) hazırlandı. Videoyu bu karelerle uygulama içinde oluşturabilirsiniz.'];
		}

		$per = max(2, (int) floor($seconds / count($paths)));
		$args = [$bin, '-y', '-loglevel', 'error'];

		foreach ($paths as $pth) {
			array_push($args, '-loop', '1', '-t', (string) $per, '-i', $pth);
		}

		$filter = '';

		foreach (array_keys($paths) as $i) {
			$filter .= "[$i:v]scale=1080:1920,setsar=1,fps=30,fade=t=in:st=0:d=0.4,fade=t=out:st=" . ($per - 0.4) . ":d=0.4[v$i];";
		}

		$filter .= implode('', array_map(static fn($i) => "[v$i]", array_keys($paths))) . 'concat=n=' . count($paths) . ':v=1:a=0,format=yuv420p[out]';
		array_push($args, '-filter_complex', $filter, '-map', '[out]', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-movflags', '+faststart', $dir . '/' . $out);
		$proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($proc)) {
			return ['video' => '', 'frames' => $frames, 'message' => 'ffmpeg başlatılamadı.'];
		}

		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		$ok = $code === 0 && is_file($dir . '/' . $out) && filesize($dir . '/' . $out) > 1000;

		return ['video' => $ok ? $out : '', 'frames' => $frames, 'message' => $ok ? 'Dikey video üretildi (' . (count($paths) * $per) . ' sn).' : 'Video üretilemedi: ' . mb_substr((string) $err, 0, 200)];
	}
}
