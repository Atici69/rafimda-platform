<?php
// Ürün görseli testleri: ImageWriter (GD / son çare yazma) ve XhImageRepair (eksik dosya, kayıt yok, kapak, ham XML'den adres)
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/core/ImageWriter.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function img(string $fmt, bool $alpha = false): string {
	$im = imagecreatetruecolor(40, 30);
	if ($alpha) { imagesavealpha($im, true); imagealphablending($im, false); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); }
	else { imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30)); }
	ob_start();
	$fmt === 'png' ? imagepng($im) : ($fmt === 'webp' ? imagewebp($im) : imagejpeg($im));
	imagedestroy($im);
	return (string) ob_get_clean();
}
$tmp = sys_get_temp_dir() . '/rf_img_' . getmypid();
@mkdir($tmp . '/products', 0755, true);
XhImageRepair::$dirOverride = $tmp . '/products';

section('ImageWriter');
$r = ImageWriter::write("$tmp/products/1.jpg", img('jpg'));
ok($r['success'] && $r['mode'] === 'gd' && getimagesize("$tmp/products/1.jpg")[2] === IMAGETYPE_JPEG, 'JPEG GD ile yazılır');
$r = ImageWriter::write("$tmp/products/2.jpg", img('png', true));
$px = imagecolorat(imagecreatefromjpeg("$tmp/products/2.jpg"), 5, 5);
ok($r['success'] && (($px >> 16) & 0xFF) > 240 && ($px & 0xFF) > 240, 'şeffaf PNG beyaz zemine oturur (siyah değil)');
$r = ImageWriter::write("$tmp/products/3.jpg", img('webp'));
ok($r['success'] && getimagesize("$tmp/products/3.jpg")[2] === IMAGETYPE_JPEG, 'WEBP JPEG\'e çevrilir (GD WEBP destekliyorsa)');
ok(!ImageWriter::write("$tmp/products/4.jpg", '<html>not image</html>')['success'] && !is_file("$tmp/products/4.jpg"), 'görsel olmayan veri reddedilir, dosya oluşmaz');
ok(!ImageWriter::memoryFits(8000, 8000, true, 128 * 1048576) && ImageWriter::memoryFits(800, 800, true, 128 * 1048576), 'bellek tahmini: 8000x8000 128 MB\'a sığmaz, 800x800 sığar');
ok(ImageWriter::bytes('256M') === 268435456 && ImageWriter::bytes('-1') === -1 && ImageWriter::bytes('1G') === 1073741824, 'memory_limit çözümleme');
// GD'nin WEBP okuyamadığı sunucu (Natro'da görülen durum): kayıt kaybolmaz, dosya olduğu gibi yazılır
ImageWriter::$disableGd = true;
$webp = img('webp');
$r = ImageWriter::write("$tmp/products/5.jpg", $webp);
ImageWriter::$disableGd = false;
ok($r['success'] && in_array($r['mode'], ['raw', 'imagick'], true) && filesize("$tmp/products/5.jpg") > 0, 'GD çözemezse görsel yine yazılır (son çare: ' . $r['mode'] . ')');
ok($r['mode'] !== 'raw' || file_get_contents("$tmp/products/5.jpg") === $webp, 'son çarede dosya bozulmadan, aynen yazılır');
@chmod("$tmp/ro", 0755); @mkdir("$tmp/ro", 0555);
if (!is_writable("$tmp/ro")) {
	ok(strpos(ImageWriter::write("$tmp/ro/1.jpg", img('jpg'))['message'], 'izin') !== false, 'yazılamayan klasörde açık izin hatası');
} else {
	ok(true, '(root olarak çalışıyor; izin testi atlandı)');
}
$d = ImageWriter::diagnostics();
ok(isset($d['gd'], $d['gd_webp'], $d['imagick'], $d['memory_limit']), 'sunucu tanılama alanları');

section('XhImageRepair');
xh_fresh_db();
array_map('unlink', glob("$tmp/products/*.jpg") ?: []);
XhUrlGuard::$resolver = fn(string $h): array => ['93.184.216.34'];
$served = [];
XhHttp::$transport = function (array $req) use (&$served): array {
	$served[] = $req['url'];
	if (strpos($req['url'], '404') !== false) { return ['status' => 404, 'location' => '', 'error' => '']; }
	($req['sink'])(img(strpos($req['url'], '.webp') !== false ? 'webp' : 'jpg'));
	return ['status' => 200, 'location' => '', 'error' => ''];
};
$mk = function (string $name, int $images, ?array $metaUrls, ?array $raw = null): int {
	$id = (int) DB::insert('products', ['product_name' => $name, 'id_category' => 1, 'id_brand' => 1, 'price' => 10, 'stock' => 1, 'active' => 1]);
	for ($i = 0; $i < $images; $i++) { DB::insert('images', ['id_product' => $id, 'cover' => $i === 0 ? 1 : 0]); }
	DB::insert('xh_product_meta', ['id_product' => $id, 'id_vendor' => 0, 'source_type' => 'xml', 'image_urls' => $metaUrls === null ? '[]' : json_encode($metaUrls)]);
	if ($raw !== null) { DB::insert('xh_items', ['id_connection' => 1, 'item_key' => 'k' . $id, 'id_product' => $id, 'data' => json_encode($raw)]); }
	return $id;
};
DB::insert('xh_connections', ['id_connection' => 1, 'id_vendor' => 0, 'name' => 'K', 'url' => 'https://pub.example.com/k.xml', 'mapping' => json_encode(['fields' => ['images' => 'image1|image2']])]);
$a = $mk('Kayıt var dosya yok (meta adresli)', 2, ['https://cdn.example.com/a1.jpg', 'https://cdn.example.com/a2.webp']);
$b = $mk('Kayıt var dosya yok (eski akış: meta boş, ham XML var)', 2, null, ['name' => 'B', 'image1' => 'https://cdn.example.com/b1.webp', 'image2' => 'https://cdn.example.com/b2.webp']);
$c = $mk('Hiç kayıt yok (yeni akışta indirme başarısız olmuş)', 0, null, ['name' => 'C', 'image1' => 'https://cdn.example.com/c1.jpg']);
$d = $mk('Adres yok', 1, null);
$e = $mk('Sağlam ürün', 1, ['https://cdn.example.com/e1.jpg']);
$eImg = (int) DB::getValue('SELECT id_image FROM images WHERE id_product = ?', [$e]);
file_put_contents("$tmp/products/$eImg.jpg", img('jpg'));
$f = $mk('Kapak dosyasız, ikinci görsel sağlam', 2, null);
$fImgs = array_column(DB::execute('SELECT id_image FROM images WHERE id_product = ? ORDER BY id_image', [$f]), 'id_image');
file_put_contents("$tmp/products/{$fImgs[1]}.jpg", img('jpg'));

$broken = XhImageRepair::brokenProductIds();
ok(in_array($a, $broken) && in_array($b, $broken) && in_array($c, $broken) && in_array($d, $broken) && in_array($f, $broken) && !in_array($e, $broken), 'görselsiz ürünler bulunur, sağlam ürün listede yok');
ok(XhImageRepair::sourceUrls($b) === ['https://cdn.example.com/b1.webp', 'https://cdn.example.com/b2.webp'], 'meta boşsa adresler ham XML satırından, eşlemedeki TÜM görsel alanlarından alınır');
$h = XhImageRepair::health();
ok($h['products_without_image'] === 5 && $h['repairable'] === 3 && $h['writable'], 'sağlık: 5 görselsiz ürün, 3 onarılabilir');

$r = XhImageRepair::repair(30);
$aImgs = array_column(DB::execute('SELECT id_image FROM images WHERE id_product = ? ORDER BY id_image', [$a]), 'id_image');
$bImgs = array_column(DB::execute('SELECT id_image FROM images WHERE id_product = ? ORDER BY id_image', [$b]), 'id_image');
ok(is_file("$tmp/products/{$aImgs[0]}.jpg") && is_file("$tmp/products/{$aImgs[1]}.jpg"), 'eksik dosyalar aynı görsel numarasına yazılır');
ok(is_file("$tmp/products/{$bImgs[0]}.jpg") && is_file("$tmp/products/{$bImgs[1]}.jpg"), 'eski akış ürünü ham XML adresleriyle onarılır');
ok((int) DB::getValue('SELECT COUNT(*) FROM images WHERE id_product = ?', [$a]) === 2, 'onarım yeni kayıt açmaz');
ok(in_array('https://cdn.example.com/c1.jpg', $served, true), 'kaydı olmayan ürün için görsel normal ekleme yolundan indirilir');
ok((int) DB::getValue('SELECT cover FROM images WHERE id_image = ?', [$fImgs[1]]) === 1 && (int) DB::getValue('SELECT cover FROM images WHERE id_image = ?', [$fImgs[0]]) === 0, 'kapak dosyası yoksa sağlam görsel kapak yapılır');
ok($r['no_source'] >= 1 && $r['products'] === 3 && $r['fixed'] >= 4, 'adresi olmayan ürün sınıra sayılmaz; özet doğru (' . json_encode(array_diff_key($r, ['errors' => 1])) . ')');
ok((int) DB::getValue('SELECT COUNT(*) FROM images') >= 6 && (int) DB::getValue('SELECT COUNT(*) FROM products') === 6, 'hiçbir ürün ya da görsel kaydı silinmez');
$n = count($served);
XhImageRepair::repair(30);
ok(!in_array($a, XhImageRepair::brokenProductIds()) && !in_array($b, XhImageRepair::brokenProductIds()), 'onarılan ürünler bir sonraki turda atlanır');

section('Hatalı adres');
xh_fresh_db();
array_map('unlink', glob("$tmp/products/*.jpg") ?: []);
XhUrlGuard::$resolver = fn(string $h): array => ['93.184.216.34'];
$g = $mk('404 görsel', 1, ['https://cdn.example.com/404.jpg']);
$r = XhImageRepair::repair(30);
ok($r['failed'] === 1 && $r['fixed'] === 0 && strpos(implode(' ', $r['errors']), 'alınamadı') !== false, 'indirilemeyen görsel hata olarak raporlanır');
ok((int) DB::getValue('SELECT COUNT(*) FROM images WHERE id_product = ?', [$g]) === 1, 'başarısız onarım kaydı silmez');

array_map('unlink', glob("$tmp/products/*") ?: []);
@rmdir("$tmp/products"); @rmdir("$tmp/ro"); @rmdir($tmp);
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail > 0 ? 1 : 0);
