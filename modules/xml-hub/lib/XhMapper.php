<?php

/**
 * Esnek alan eşleştirme motoru. Tedarikçi XML'inin düz alanlarını (XhXml::flatten çıktısı) Rafımda ürün alanlarına çevirir.
 * Saf fonksiyonlar (veritabanı çağrısı yok); marka/kategori çözümleme `$lookup` geri çağrımıyla yapılır.
 *
 * Eşleştirme biçimi:
 * {
 *   "fields": {"name":"Name", "price":"Price|ListPrice", "brand":"=Sabit Marka", ...},   // "a|b" = ilk dolu olan; "=x" = sabit değer
 *   "variant_source": "Variants/Variant",
 *   "variant_fields": {"sku":"Sku","stock":"Qty","price":"Price","size":"Size","color":"Color","barcode":"Barcode"},
 *   "category_map": {"Tedarikçi Kategorisi": 12}, "brand_map": {"Marka": 3}
 * }
 */
class XhMapper
{
	/** hedef alan => [etiket, zorunlu, çoklu] */
	public const TARGETS = [
		'name' => ['Ürün adı', true, false],
		'description' => ['Açıklama', false, false],
		'short_description' => ['Kısa açıklama', false, false],
		'sku' => ['SKU / Stok kodu', false, false],
		'barcode' => ['Barkod', false, false],
		'price' => ['Fiyat', true, false],
		'sale_price' => ['İndirimli fiyat', false, false],
		'stock' => ['Stok', false, false],
		'brand' => ['Marka', false, false],
		'category' => ['Kategori', false, false],
		'images' => ['Görseller', false, true],
		'desi' => ['Desi', false, false],
		'cargo_price' => ['Kargo ücreti (modül tablosunda saklanır, pazaryeri gönderiminde kullanılır)', false, false],
		'cargo_day' => ['Kargo süresi (gün)', false, false],
		'vat' => ['KDV oranı', false, false],
	];

	public const VARIANT_TARGETS = ['sku' => 'Varyant SKU', 'barcode' => 'Varyant barkod', 'price' => 'Varyant fiyat', 'stock' => 'Varyant stok', 'size' => 'Beden', 'color' => 'Renk'];

	private const SYNONYMS = [
		'name' => ['name', 'productname', 'title', 'urunadi', 'urunismi', 'ad', 'baslik', 'isim', 'label', 'product'],
		'description' => ['description', 'desc', 'aciklama', 'detail', 'urunaciklama', 'content', 'longdescription', 'detay'],
		'short_description' => ['shortdescription', 'kisaaciklama', 'summary', 'ozet'],
		'sku' => ['sku', 'stockcode', 'stokkodu', 'productcode', 'urunkodu', 'code', 'kod', 'productid', 'id', 'modelcode', 'supplierstockcode'],
		'barcode' => ['barcode', 'barkod', 'ean', 'gtin', 'upc'],
		'price' => ['price', 'fiyat', 'listprice', 'listefiyati', 'satisfiyati', 'saleprice', 'sellprice', 'pricewithvat', 'kdvlifiyat'],
		'sale_price' => ['discountprice', 'discountedprice', 'indirimlifiyat', 'specialprice', 'campaignprice', 'kampanyafiyati', 'netprice'],
		'stock' => ['stock', 'stok', 'quantity', 'qty', 'miktar', 'stockamount', 'stokadedi', 'inventory'],
		'brand' => ['brand', 'marka', 'manufacturer', 'vendor', 'ureticifirma'],
		'category' => ['category', 'kategori', 'categoryname', 'kategoriadi', 'categorypath', 'maincategory', 'catalog'],
		'images' => ['images', 'image', 'img', 'picture', 'pictures', 'resim', 'resimler', 'gorsel', 'gorseller', 'photo', 'photos', 'imageurl', 'image1', 'resim1', 'picture1'],
		'desi' => ['desi', 'deci', 'volumetricweight'],
		'cargo_price' => ['cargoprice', 'kargoucreti', 'shippingcost', 'shipping'],
		'cargo_day' => ['cargoday', 'kargosuresi', 'deliverytime', 'leadtime', 'shippingtime'],
		'vat' => ['vat', 'kdv', 'tax', 'taxrate', 'kdvorani'],
	];

	private const VARIANT_SYNONYMS = [
		'sku' => ['sku', 'stockcode', 'stokkodu', 'code', 'kod', 'id'],
		'barcode' => ['barcode', 'barkod', 'ean', 'gtin'],
		'price' => ['price', 'fiyat'],
		'stock' => ['stock', 'stok', 'quantity', 'qty', 'miktar'],
		'size' => ['size', 'beden', 'olcu'],
		'color' => ['color', 'colour', 'renk'],
	];

	public static function norm(string $s): string
	{
		$s = strtr($s, ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c']);
		$leaf = mb_strtolower($s);
		$leaf = preg_replace('/^.*[\/@]/', '', $leaf) ?? $leaf;

		return preg_replace('/[^a-z0-9]/', '', $leaf) ?? '';
	}

	/** Tam ad/yol normalizasyonu; Türkçe karakter ve noktalama farklarını eşleştirmede yok sayar. */
	public static function normText(string $s): string
	{
		$s = strtr($s, ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c']);
		$s = mb_strtolower(trim($s));

		return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
	}

	/** Haritada tam veya benzersiz normalize eşleşme arar; belirsizlikte hedef seçmez. */
	public static function mappedId(array $map, string $text): int
	{
		if (isset($map[$text]) && (int) $map[$text] > 0) {
			return (int) $map[$text];
		}
		$key = self::normText($text);
		if ($key === '') {
			return 0;
		}
		$found = 0;
		foreach ($map as $source => $id) {
			if (self::normText((string) $source) !== $key || (int) $id <= 0) {
				continue;
			}
			if ($found > 0 && $found !== (int) $id) {
				return 0;
			}
			$found = (int) $id;
		}
		return $found;
	}

	/**
	 * Örnek bir ürünün alanlarından otomatik eşleştirme önerir (tam eş anlamlı eşleşme; belirsizse boş bırakır).
	 *
	 * @param array<string,mixed> $sample
	 * @return array{fields:array<string,string>, variant_source:string, variant_fields:array<string,string>}
	 */
	public static function suggest(array $sample): array
	{
		$fields = [];
		$used = [];

		foreach (self::SYNONYMS as $target => $syns) {
			foreach ($syns as $syn) { // öncelik sırası eş anlamlı listesinin sırasıdır
				foreach (array_keys($sample) as $key) {
					$k = (string) $key;

					if (isset($used[$k]) || $k === '#text' || strpos($k, '@') !== false || is_array($sample[$key]) && $target !== 'images' && !self::isStringList($sample[$key])) {
						continue;
					}

					$parent = strpos($k, '/') !== false ? self::norm(substr($k, 0, (int) strrpos($k, '/'))) : '';

					if (self::norm($k) === $syn || ($target === 'images' && $parent === $syn)) {
						$fields[$target] = $k;
						$used[$k] = true;
						break 2;
					}
				}
			}
		}

		// Varyant kaynağı: kayıt listesi olan ilk anahtar
		$vs = '';
		$vf = [];

		foreach ($sample as $k => $v) {
			if (is_array($v) && $v !== [] && is_array(reset($v))) {
				if (!isset($fields['images']) && preg_match('/image|picture|resim|gorsel/i', (string) $k)) {
					continue;
				}

				$vs = (string) $k;
				$first = reset($v);

				foreach (self::VARIANT_SYNONYMS as $t => $syns) {
					foreach ($syns as $syn) {
						foreach (array_keys($first) as $sk) {
							if (self::norm((string) $sk) === $syn) {
								$vf[$t] = (string) $sk;
								break 2;
							}
						}
					}
				}

				break;
			}
		}

		return ['fields' => $fields, 'variant_source' => $vs, 'variant_fields' => $vf];
	}

	private static function isStringList($v): bool
	{
		return is_array($v) && $v !== [] && !is_array(reset($v));
	}

	/** @param array<string,mixed> $item @return mixed */
	public static function pick(array $item, string $spec)
	{
		$spec = trim($spec);

		if ($spec === '') {
			return null;
		}

		if ($spec[0] === '=') {
			return substr($spec, 1);
		}

		foreach (explode('|', $spec) as $key) {
			$key = trim($key);

			if ($key !== '' && array_key_exists($key, $item)) {
				$v = $item[$key];

				if (is_array($v) ? $v !== [] : trim((string) $v) !== '') {
					return $v;
				}
			}
		}

		return null;
	}

	/** "1.234,56" / "1,234.56" / "1234,5" / "12 TL" → float; çözülemezse null. Tek noktalı "1.234" ondalık sayılır. */
	public static function parseNumber($v): ?float
	{
		if (is_array($v)) {
			return null;
		}

		$s = trim((string) $v);
		$s = preg_replace('/[^\d.,\-]/u', '', $s) ?? '';

		if ($s === '' || $s === '-' || !preg_match('/\d/', $s)) {
			return null;
		}

		$lastDot = strrpos($s, '.');
		$lastComma = strrpos($s, ',');

		if ($lastDot !== false && $lastComma !== false) {
			if ($lastComma > $lastDot) {
				$s = str_replace(',', '.', str_replace('.', '', $s));
			} else {
				$s = str_replace(',', '', $s);
			}
		} elseif ($lastComma !== false) {
			$s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s); // tek virgül = ondalık (TR)
		} elseif (substr_count($s, '.') > 1) {
			$s = str_replace('.', '', $s);
		}

		return is_numeric($s) ? (float) $s : null;
	}

	/** @return list<string> */
	private static function urls($v, int $max): array
	{
		$list = is_array($v) ? $v : [$v];
		$out = [];

		foreach ($list as $x) {
			if (is_array($x)) {
				continue;
			}

			foreach (preg_split('/[\s|;,]+(?=https?:)/i', trim((string) $x)) ?: [] as $u) {
				$u = trim($u);

				if (preg_match('#^https?://[^\s<>"]+$#i', $u) && strlen($u) <= 500) {
					$out[$u] = true;
				}
			}
		}

		return array_slice(array_keys($out), 0, $max);
	}

	/**
	 * @param array<string,mixed> $item  XhXml düz ürün
	 * @param array<string,mixed> $mapping
	 * @param array<string,mixed> $opt  price_markup_percent, price_add, default_category, default_brand, max_images
	 * @param callable|null $lookup  fn(string $kind 'brand'|'category', string $text): int  (0 = bulunamadı)
	 * @return array<string,mixed> normalleştirilmiş satır; 'errors' doluysa içe aktarılamaz
	 */
	public static function apply(array $item, array $mapping, array $opt = [], ?callable $lookup = null): array
	{
		$f = is_array($mapping['fields'] ?? null) ? $mapping['fields'] : [];
		$errors = [];
		$get = static fn(string $t) => isset($f[$t]) ? self::pick($item, (string) $f[$t]) : null;
		$str = static fn($v, int $max = 255): string => $v === null || is_array($v) ? '' : mb_substr(trim(strip_tags((string) $v)), 0, $max);

		$name = $str($get('name'), 128);
		$sku = $str($get('sku'), 64);
		$barcode = $str($get('barcode'), 64);
		$price = self::parseNumber($get('price'));
		$sale = self::parseNumber($get('sale_price'));
		$stockRaw = $get('stock');
		$stock = $stockRaw === null ? null : self::parseNumber($stockRaw);
		$markup = (float) ($opt['price_markup_percent'] ?? 0);
		$add = (float) ($opt['price_add'] ?? 0);

		if (mb_strlen($name) < 3) {
			$errors[] = 'Ürün adı eksik/çok kısa.';
		}

		if ($price === null || $price <= 0) {
			$errors[] = 'Geçerli fiyat bulunamadı.';
			$price = 0.0;
		}

		$old = 0.0;

		if ($sale !== null && $sale > 0 && $sale < $price) {
			$old = $price;
			$price = $sale;
		}

		$adj = static fn(float $p): float => $p > 0 ? round($p * (1 + $markup / 100) + $add, 2) : 0.0;
		$price = $adj($price);
		$old = $adj($old);

		if ($stockRaw !== null && $stock === null) {
			$errors[] = 'Stok sayısal değil: "' . mb_substr((string) (is_array($stockRaw) ? '' : $stockRaw), 0, 30) . '".';
		}

		if ($stock !== null && $stock < 0) {
			$stock = 0.0;
		}

		// marka / kategori
		$brandTxt = $str($get('brand'), 128);
		$catTxt = $str($get('category'), 255);
		$brandMap = is_array($mapping['brand_map'] ?? null) ? $mapping['brand_map'] : [];
		$catMap = is_array($mapping['category_map'] ?? null) ? $mapping['category_map'] : [];
		$idBrand = $brandTxt !== '' ? self::mappedId($brandMap, $brandTxt) : 0;
		$idCat = $catTxt !== '' ? self::mappedId($catMap, $catTxt) : 0;

		if ($idBrand <= 0 && $brandTxt !== '' && $lookup) {
			$idBrand = (int) $lookup('brand', $brandTxt);
		}

		if ($idCat <= 0 && $catTxt !== '' && $lookup) {
			$idCat = (int) $lookup('category', $catTxt);
		}

		$idBrand = $idBrand > 0 ? $idBrand : (int) ($opt['default_brand'] ?? 0);
		$idCat = $idCat > 0 ? $idCat : (int) ($opt['default_category'] ?? 0);

		if ($idBrand <= 0) {
			$errors[] = $brandTxt !== '' ? 'Marka eşleşmedi: "' . $brandTxt . '".' : 'Marka yok ve varsayılan marka seçilmedi.';
		}

		if ($idCat <= 0) {
			$errors[] = $catTxt !== '' ? 'Kategori eşleşmedi: "' . $catTxt . '".' : 'Kategori yok ve varsayılan kategori seçilmedi.';
		}

		// varyantlar
		$variants = [];
		$vs = (string) ($mapping['variant_source'] ?? '');
		$vf = is_array($mapping['variant_fields'] ?? null) ? $mapping['variant_fields'] : [];

		if ($vs !== '' && isset($item[$vs]) && is_array($item[$vs])) {
			foreach (array_slice($item[$vs], 0, 100) as $rec) {
				if (!is_array($rec)) {
					continue;
				}

				$vv = static fn(string $t) => isset($vf[$t]) ? self::pick($rec, (string) $vf[$t]) : null;
				$size = $str($vv('size'), 60);
				$color = $str($vv('color'), 60);
				$o1n = $o1v = $o2n = $o2v = '';

				if ($size !== '') {
					$o1n = 'Beden';
					$o1v = $size;
				}

				if ($color !== '') {
					if ($o1n === '') {
						$o1n = 'Renk';
						$o1v = $color;
					} else {
						$o2n = 'Renk';
						$o2v = $color;
					}
				}

				$vp = self::parseNumber($vv('price'));
				$vst = self::parseNumber($vv('stock'));
				$variants[] = [
					'sku' => $str($vv('sku'), 64),
					'barcode' => $str($vv('barcode'), 64),
					'price' => $vp !== null && $vp > 0 ? (string) $adj($vp) : '',
					'stock' => (string) max(0.0, $vst ?? 0.0),
					'option1_name' => $o1n, 'option1_value' => $o1v, 'option2_name' => $o2n, 'option2_value' => $o2v,
				];
			}

			$variants = array_values(array_filter($variants, static fn(array $v): bool => $v['option1_value'] !== '' || $v['sku'] !== ''));
		}

		if ($stock === null) {
			$stock = $variants !== [] ? array_sum(array_map(static fn(array $v): float => (float) $v['stock'], $variants)) : null;
		}

		$key = $sku !== '' ? 'sku:' . $sku : ($barcode !== '' ? 'bc:' . $barcode : ($name !== '' ? 'n:' . sha1(mb_strtolower($name)) : ''));

		if ($key === '') {
			$errors[] = 'Ürünü tanımlayan SKU/barkod/ad yok.';
		}

		$desi = self::parseNumber($get('desi'));
		$descriptionValue = $get('description');
		$description = $descriptionValue !== null && !is_array($descriptionValue) ? mb_substr((string) $descriptionValue, 0, 60000) : '';
		$shortDescription = $str($get('short_description'), 500);
		if ($shortDescription === '' && $description !== '') {
			$plain = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$shortDescription = mb_substr(trim(preg_replace('/\\s+/u', ' ', $plain) ?? $plain), 0, 500);
		}
		$row = [
			'key' => mb_substr($key, 0, 100),
			'name' => $name,
			'description' => $description,
			'short_description' => $shortDescription,
			'sku' => $sku,
			'barcode' => $barcode,
			'price' => $price,
			'old_price' => $old,
			'stock' => $stock,
			'id_brand' => $idBrand,
			'id_category' => $idCat,
			'brand_text' => $brandTxt,
			'category_text' => $catTxt,
			'images' => self::urls($get('images'), max(0, min(10, (int) ($opt['max_images'] ?? 5)))),
			'variants' => $variants,
			'desi' => $desi !== null ? max(0, (int) round($desi)) : null,
			'cargo_day' => ($cd = self::parseNumber($get('cargo_day'))) !== null ? max(0, (int) $cd) : null,
			'cargo_price' => self::parseNumber($get('cargo_price')),
			'vat' => ($vt = self::parseNumber($get('vat'))) !== null && $vt >= 0 && $vt <= 100 ? $vt : null,
			'errors' => $errors,
		];
		$hashRow = $row;
		unset($hashRow['errors']);
		$row['hash'] = sha1((string) json_encode($hashRow, JSON_UNESCAPED_UNICODE));

		return $row;
	}

	/** @param array<string,mixed> $m @return array<string,mixed> */
	public static function sanitizeMapping(array $m): array
	{
		$clean = ['fields' => [], 'variant_source' => '', 'variant_fields' => [], 'category_map' => [], 'brand_map' => [], 'product_map' => []];

		foreach ((array) ($m['fields'] ?? []) as $t => $src) {
			if (isset(self::TARGETS[$t]) && is_scalar($src) && trim((string) $src) !== '') {
				$clean['fields'][$t] = mb_substr(trim((string) $src), 0, 190);
			}
		}

		$clean['variant_source'] = mb_substr(trim((string) ($m['variant_source'] ?? '')), 0, 190);

		foreach ((array) ($m['variant_fields'] ?? []) as $t => $src) {
			if (isset(self::VARIANT_TARGETS[$t]) && is_scalar($src) && trim((string) $src) !== '') {
				$clean['variant_fields'][$t] = mb_substr(trim((string) $src), 0, 190);
			}
		}

		foreach (['category_map', 'brand_map'] as $k) {
			foreach (array_slice((array) ($m[$k] ?? []), 0, 2000, true) as $txt => $id) {
				if ((int) $id > 0 && is_scalar($txt)) {
					$clean[$k][mb_substr(trim((string) $txt), 0, 255)] = (int) $id;
				}
			}
		}
		foreach (array_slice((array) ($m['product_map'] ?? []), 0, 5000, true) as $key => $id) {
			if (is_scalar($key) && trim((string) $key) !== '' && (int) $id > 0) {
				$clean['product_map'][mb_substr(trim((string) $key), 0, 100)] = (int) $id;
			}
		}

		return $clean;
	}
}
