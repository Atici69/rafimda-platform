<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

/**
 * Satıcı paneli Excel (.xlsx) içe/dışa aktarma.
 *
 * - İçe aktarma: güvenlik ön kontrolü (boyut, zip yapısı, açılmış boyut, DOCTYPE/ENTITY reddi) → SimpleXLSX ile
 *   ilk sayfa okunur → RvProducts::importRows() (CSV ile aynı kurallar, satıcı izolasyonu dahil).
 * - Şablon: "Ürünler" + "Kategoriler" + "Markalar" + "Açıklama" sayfaları.
 * - Dışa aktarma: yalnızca bu satıcının ürünleri; dosya doğrudan geri yüklenebilir (urun_id ile eşleşir).
 *   Tüm metin hücreleri ham metin olarak yazılır (formül/etiket yorumlanmaz).
 */
class RvExcel
{
	public const MAX_BYTES = 5242880;          // yüklenen .xlsx en çok 5 MB
	public const MAX_UNPACKED = 52428800;      // açılmış toplam en çok 50 MB (zip bombası koruması)
	public const MAX_ENTRIES = 200;
	public const EXPORT_MAX = 20000;

	public const COLUMNS = ['urun_id', 'urun_adi', 'kategori', 'marka', 'fiyat', 'stok', 'stok_kodu', 'barkod', 'aciklama', 'kategori_id', 'marka_id'];

	public static function loadLibs(): void
	{
		$root = dirname(__DIR__, 3);

		if (!class_exists('SimpleXLSX', false)) {
			require_once $root . '/libs/SimpleXLSX.php';
		}

		if (!class_exists('SimpleXLSXGen', false)) {
			require_once $root . '/libs/SimpleGEN.php';
		}
	}

	/**
	 * .xlsx dosyasının güvenle okunabilir olduğunu doğrular. Hata metni döner; sorun yoksa ''.
	 */
	public static function inspect(string $path): string
	{
		if (!is_file($path)) {
			return 'Dosya bulunamadı.';
		}

		if (filesize($path) > self::MAX_BYTES) {
			return 'Excel dosyası 5 MB sınırını aşıyor.';
		}

		$fh = fopen($path, 'rb');
		$magic = $fh ? (string) fread($fh, 4) : '';

		if ($fh) {
			fclose($fh);
		}

		if ($magic !== "PK\x03\x04") {
			return 'Dosya geçerli bir Excel (.xlsx) değil. Eski .xls biçimini Excel\'de "Farklı kaydet → .xlsx" ile dönüştürün.';
		}

		if (!class_exists('ZipArchive')) {
			return 'Sunucuda zip desteği yok; lütfen CSV yükleyin.';
		}

		$zip = new ZipArchive();

		if ($zip->open($path) !== true) {
			return 'Excel dosyası açılamadı (bozuk olabilir).';
		}

		$total = 0;
		$hasWorkbook = false;

		if ($zip->numFiles > self::MAX_ENTRIES) {
			$zip->close();

			return 'Excel dosyası beklenmedik sayıda parça içeriyor.';
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$st = $zip->statIndex($i);

			if (!$st) {
				continue;
			}

			$name = (string) $st['name'];
			$total += (int) $st['size'];

			if ($total > self::MAX_UNPACKED) {
				$zip->close();

				return 'Excel dosyasının açılmış boyutu çok büyük.';
			}

			if ($name === 'xl/workbook.xml') {
				$hasWorkbook = true;
			}

			if (substr($name, -4) === '.xml' || substr($name, -5) === '.rels') {
				$head = (string) $zip->getFromIndex($i, 4096);

				if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
					$zip->close();

					return 'Excel dosyası güvenli olmayan içerik barındırıyor.';
				}
			}
		}

		$zip->close();

		return $hasWorkbook ? '' : 'Dosya geçerli bir Excel çalışma kitabı değil.';
	}

	/**
	 * @return array{success:bool, message:string, total:int, created:int, updated:int, failed:int, errors:list<string>}
	 */
	public static function import(int $idVendor, string $path, string $fileName): array
	{
		$res = RvProducts::emptyImportResult();
		$err = self::inspect($path);

		if ($err !== '') {
			$res['message'] = $err;

			return $res;
		}

		self::loadLibs();
		$xlsx = SimpleXLSX::parse($path);

		if (!$xlsx) {
			$res['message'] = 'Excel dosyası okunamadı.';

			return $res;
		}

		$rows = $xlsx->rows(0, RvProducts::CSV_MAX_ROWS + 2);

		if (!$rows) {
			$res['message'] = 'Excel dosyasının ilk sayfası boş.';

			return $res;
		}

		$header = array_shift($rows);
		$rows = array_map([self::class, 'cleanRow'], $rows);

		return RvProducts::importRows($idVendor, (array) $header, $rows, $fileName, 'xlsx');
	}

	/**
	 * Excel sayısal hücreleri: 8.69E+12 gibi gösterimleri ve "25.0" gibi kayan değerleri düz metne çevirir.
	 *
	 * @param list<mixed> $row
	 * @return list<string>
	 */
	public static function cleanRow(array $row): array
	{
		$out = [];

		foreach ($row as $v) {
			if (is_float($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?E\+?\d+$/i', $v))) {
				$f = (float) $v;
				$v = (floor($f) === $f && abs($f) < 1e15) ? sprintf('%.0f', $f) : rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
			}

			$out[] = trim((string) $v);
		}

		return $out;
	}

	/**
	 * @param list<array<string,mixed>> $categories id_category, category_name
	 * @param list<array<string,mixed>> $brands id_brand, brand_name
	 */
	public static function template(array $categories, array $brands): SimpleXLSXGen
	{
		self::loadLibs();
		$r = [SimpleXLSXGen::class, 'raw'];
		$products = [
			array_map(static fn($c) => '<b>' . $c . '</b>', self::COLUMNS),
			['', $r('Örnek Kadın Basic Tişört'), $r('Kadın > Giyim'), $r('Örnek Marka'), 99.90, 25, $r('TSH-001'), $r('8690000000001'), $r('Pamuklu tişört'), '', ''],
		];
		$cats = [['<b>kategori_id</b>', '<b>Kategori</b>']];

		foreach ($categories as $c) {
			$cats[] = [(int) $c['id_category'], $r((string) $c['category_name'])];
		}

		$brs = [['<b>marka_id</b>', '<b>Marka</b>']];

		foreach ($brands as $b) {
			$brs[] = [(int) $b['id_brand'], $r((string) $b['brand_name'])];
		}

		$help = [
			['<b>Rafımda toplu ürün şablonu</b>'],
			[$r('1. "Ürünler" sayfasını doldurun; ilk satırdaki sütun adlarını değiştirmeyin.')],
			[$r('2. Yeni ürün: urun_id BOŞ bırakın; yalnızca urun_adi ve fiyat zorunludur.')],
			[$r('3. Kategori OTOMATİK bulunur: "kategori" sütununa kendi kategori adınızı ya da yolunu yazın (ör. "Kadın > Elbise") veya boş bırakın; boşsa ürün adından bulunur.')],
			[$r('4. Emin olunamayan kategoriler "Kategori kontrolü" ekranında onayınıza sunulur; yaptığınız düzeltme sonraki yüklemelerde hatırlanır.')],
			[$r('5. Marka: "marka" sütununa adını yazın; sitede yoksa yeni marka açılır. İsterseniz kategori_id / marka_id sütunlarına numara da yazabilirsiniz.')],
			[$r('6. Mevcut ürün: urun_id veya stok_kodu sizin ürününüzle eşleşirse yalnızca fiyat ve stok güncellenir; boş hücre mevcut değeri korur.')],
			[$r('7. En fazla ' . RvProducts::CSV_MAX_ROWS . ' satır, dosya en fazla 5 MB. Kategori ve marka numaraları ayrı sayfalardadır.')],
		];

		return SimpleXLSXGen::fromArray($products, 'Ürünler')
			->addSheet($cats, 'Kategoriler')
			->addSheet($brs, 'Markalar')
			->addSheet($help, 'Açıklama')
			->setAuthor('Rafımda')
			->setCompany('Rafımda');
	}

	/**
	 * Satıcının kendi ürünleri (en çok EXPORT_MAX). Satırlar doğrudan geri yüklenebilir.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function exportRows(int $idVendor): array
	{
		return DB::execute(
			'SELECT p.id_product, p.product_name, p.id_category, p.id_brand, p.price, p.stock, p.stock_code, p.barcode, p.active, vp.moderation
			FROM rafimda_vendor_products vp
			INNER JOIN products p ON p.id_product = vp.id_product
			WHERE vp.id_vendor = ?
			ORDER BY p.id_product ASC LIMIT ' . self::EXPORT_MAX,
			[$idVendor]
		) ?: [];
	}

	public static function export(int $idVendor): SimpleXLSXGen
	{
		self::loadLibs();
		$r = [SimpleXLSXGen::class, 'raw'];
		$head = ['urun_id', 'urun_adi', 'kategori', 'marka', 'fiyat', 'stok', 'stok_kodu', 'barkod', 'kategori_id', 'marka_id', 'durum', 'onay'];
		$brands = [];

		foreach (DB::execute('SELECT id_brand, brand_name FROM brands') ?: [] as $b) {
			$brands[(int) $b['id_brand']] = (string) $b['brand_name'];
		}

		if (!class_exists('CategoryMatchStore')) {
			require_once dirname(__DIR__, 3) . '/core/CategoryMatchStore.php';
		}
		$rows = [array_map(static fn($c) => '<b>' . $c . '</b>', $head)];
		$mod = ['approved' => 'Onaylı', 'pending' => 'Onay bekliyor', 'rejected' => 'Reddedildi'];

		foreach (self::exportRows($idVendor) as $p) {
			$rows[] = [
				(int) $p['id_product'],
				$r((string) $p['product_name']),
				$r(CategoryMatchStore::sitePath((int) $p['id_category'])),
				$r($brands[(int) $p['id_brand']] ?? ''),
				(float) $p['price'],
				(float) $p['stock'],
				$r((string) ($p['stock_code'] ?? '')),
				$r((string) ($p['barcode'] ?? '')),
				(int) $p['id_category'],
				(int) $p['id_brand'],
				$r((int) $p['active'] === 1 ? 'Yayında' : 'Pasif'),
				$r($mod[(string) ($p['moderation'] ?? 'approved')] ?? (string) $p['moderation']),
			];
		}

		RvAudit::log($idVendor, 'product.export_xlsx', 'export', 0, ['rows' => count($rows) - 1]);

		return SimpleXLSXGen::fromArray($rows, 'Ürünler')->setAuthor('Rafımda')->setCompany('Rafımda');
	}
}
