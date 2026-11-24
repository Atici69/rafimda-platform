<?php
/**
 * Rafi – Rafımda alışveriş asistanı (kural tabanlı, ücretsiz, harici API YOK).
 * Tüm yanıtlar YALNIZCA Rafımda veritabanındaki gerçek ürün/fiyat/stok/satıcı/yorum kayıtlarından üretilir;
 * katalogda olmayan bilgi uydurulmaz. Dış yapay zekâ hizmeti bağlı değildir (bkz. modül README).
 */
class RafiEngine
{
	public const MAX_LEN = 300;
	public const MAX_RESULTS = 6;
	public const MAX_COMPARE = 3;

	private const STOP = ['bir', 'bana', 'ben', 'icin', 've', 'ile', 'ya', 'da', 'de', 'mi', 'mu', 'en', 'cok', 'olan', 'var', 'yok', 'istiyorum', 'ariyorum',
		'lazim', 'goster', 'gosterir', 'misin', 'bul', 'bulur', 'oner', 'onerir', 'urun', 'urunler', 'urunu', 'fiyat', 'fiyati', 'fiyatli', 'kac', 'tl', 'lira',
		'alti', 'altinda', 'ustu', 'uzeri', 'uzerinde', 'kadar', 'arasi', 'arasinda', 'ucuz', 'ucuzu', 'pahali', 'pahalisi', 'uygun', 'stokta', 'stok', 'stoklu',
		'indirimli', 'indirim', 'indirimde', 'karsilastir', 'karsilastirir', 'kiyasla', 'hangisi', 'daha', 'iyi', 'yoksa', 'vs', 'su', 'bu', 'sunu', 'bunu',
		'oneri', 'merhaba', 'selam', 'rafi', 'lutfen', 'acaba', 'tane', 'adet', 'satici', 'magaza', 'marka', 'markali', 'hangi', 'nedir', 'ne', 'olarak', 'gibi',
		'ilk', 'iki', 'uc', 'son', 'sonuc', 'sonuclari', 'tum', 'hepsi', 'butce', 'butcem', 'maksimum', 'max', 'min', 'minimum', 'den', 'dan', 'ten', 'tan', 'e', 'a',
		'ye', 'ya', 'yi', 'yu', 'i', 'u', 'nin', 'nun', 'olsun', 'icinde', 'arasindan', 'teki', 'deki', 'daki', 'yeni', 'getir', 'listele', 'ara', 'aramak'];

	private const SUFFIXES = ['lari', 'leri', 'lar', 'ler', 'nin', 'nun', 'dan', 'den', 'tan', 'ten', 'da', 'de', 'ta', 'te', 'yi', 'yu', 'i', 'u', 'a', 'e'];

	/** Türkçe küçük harf + aksan katlama (ç→c, ğ→g, ı/i→i, ö→o, ş→s, ü→u) */
	public static function fold(string $s): string
	{
		$s = str_replace(['I', 'İ'], ['ı', 'i'], $s);
		$s = mb_strtolower($s, 'UTF-8');

		return strtr($s, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
	}

	public static function stem(string $w): string
	{
		foreach (self::SUFFIXES as $suf) {
			if (strlen($w) - strlen($suf) >= 4 && substr($w, -strlen($suf)) === $suf) {
				return substr($w, 0, -strlen($suf));
			}
		}

		return $w;
	}

	/**
	 * Mesajı çözümler: niyet, anahtar kelimeler, fiyat aralığı, sıralama, filtreler.
	 * @return array{intent:string,terms:string[],min:?float,max:?float,sort:string,in_stock:bool,discount:bool,compare_parts:string[],picks:int[]}
	 */
	public static function parse(string $msg): array
	{
		$f = ' ' . preg_replace('/\s+/', ' ', self::fold($msg)) . ' ';
		$out = ['intent' => 'search', 'terms' => [], 'min' => null, 'max' => null, 'sort' => 'relevance', 'in_stock' => false, 'discount' => false, 'compare_parts' => [], 'picks' => []];
		$num = '(\d{1,3}(?:[.\s]\d{3})+|\d+)(?:[,]\d+)?\s*(?:k\b|bin\b)?';
		$toNum = static function (string $raw, string $full): float {
			$n = (float) preg_replace('/[.\s]/', '', $raw);

			return preg_match('/^\s*[\d.\s]+\s*(k|bin)\b/', $full) ? $n * 1000 : $n;
		};

		if (preg_match('/(\d[\d.\s]*)\s*(?:tl|lira|₺)?\s*(?:-|ile|ila)\s*(\d[\d.\s]*)\s*(?:tl|lira|₺)?\s*(?:arasi|arasinda)/u', $f, $m)) {
			$a = (float) preg_replace('/[.\s]/', '', $m[1]);
			$b = (float) preg_replace('/[.\s]/', '', $m[2]);
			[$out['min'], $out['max']] = [min($a, $b), max($a, $b)];
			$f = str_replace($m[0], ' ', $f);
		}

		if (preg_match('/' . $num . '\s*(?:tl|lira|₺)?\s*(?:\'?[ydt]?[ae]n\s+)?(?:alti|altinda|altindaki|asmayan|kadar|ucuz|az)\b/u', $f, $m) || preg_match('/(?:max|maksimum|en fazla|butcem|butce)\s*' . $num . '/u', $f, $m)) {
			$out['max'] = $toNum($m[1], $m[0]);
			$f = str_replace($m[0], ' ', $f);
		}

		if (preg_match('/' . $num . '\s*(?:tl|lira|₺)?\s*(?:\'?[ydt]?[ae]n\s+)?(?:ustu|uzeri|uzerinde|fazla|pahali)\b/u', $f, $m) || preg_match('/(?:min|minimum|en az)\s*' . $num . '/u', $f, $m)) {
			$out['min'] = $toNum($m[1], $m[0]);
			$f = str_replace($m[0], ' ', $f);
		}

		if (preg_match('/\ben ucuz|\bucuzdan|\bfiyati dusuk|\buygun fiyat/u', $f)) {
			$out['sort'] = 'price_asc';
		} elseif (preg_match('/\ben pahali|\bpahalidan/u', $f)) {
			$out['sort'] = 'price_desc';
		} elseif (preg_match('/\ben iyi|\ben cok begenilen|\bpuani yuksek|\ben yuksek puan/u', $f)) {
			$out['sort'] = 'rating';
		}

		$out['in_stock'] = (bool) preg_match('/\bstok(ta|lu)\b|\bmevcut\b|\bhemen teslim/u', $f);
		$out['discount'] = (bool) preg_match('/\bindirim(li|de)?\b|\bkampanyali\b|\bfirsat/u', $f);

		$trim = trim($f);

		if (preg_match('/^(merhaba|selam|selamlar|hey|iyi gunler|gunaydin|iyi aksamlar|slm|mrb)\b[\s!.]*(rafi)?[\s!.]*$/u', $trim)) {
			$out['intent'] = 'greet';

			return $out;
		}

		if (preg_match('/^(tesekkur(ler)?|sagol|sag ol|eyvallah|tsk)\b/u', $trim)) {
			$out['intent'] = 'thanks';

			return $out;
		}

		if (preg_match('/(ne yapabilirsin|neler yapabilirsin|yardim|nasil kullan|kimsin|sen kimsin)/u', $trim)) {
			$out['intent'] = 'help';

			return $out;
		}

		if (preg_match('/(karsilastir|kiyasla|\bvs\b|\bversus\b|farki ne|farklari|hangisi daha|mi yoksa)/u', $trim)) {
			$out['intent'] = 'compare';

			if (preg_match_all('/\b([1-9])\s*(?:\.|nci|inci|uncu|ncu|\'?(?:yi|i|u|yu|ile|ve)\b)?/u', $trim, $mm) && preg_match('/\b[1-9]\b/', $trim)) {
				$out['picks'] = array_values(array_unique(array_map('intval', $mm[1])));
			}

			if (preg_match('/\bilk (iki|uc|2|3)/u', $trim, $m2)) {
				$n = ['iki' => 2, 'uc' => 3, '2' => 2, '3' => 3][$m2[1]];
				$out['picks'] = range(1, $n);
			}

			$rest = preg_replace('/(karsilastir\w*|kiyasla\w*|farki ne\w*|farklari\w*|hangisi daha \w+|urunleri|urunlerini|bunlari|sunlari|lutfen|rafi|\bilk (iki|uc|2|3)\w*)/u', ' ', $trim);
			$parts = preg_split('/\s+(?:ile|ve|vs|versus|veya|yoksa|mi yoksa|mu yoksa)\s+|,|\//u', (string) $rest) ?: [];
			$parts = array_values(array_filter(array_map(static fn($p) => trim((string) $p), $parts), static fn($p) => count(self::terms($p)) > 0 && !preg_match('/^\d+\s*\W*\w{0,4}$/u', $p)));

			if (count($parts) >= 2) {
				$out['compare_parts'] = array_slice($parts, 0, self::MAX_COMPARE);
			}
		}

		$out['terms'] = self::terms($f);

		return $out;
	}

	/** @return string[] durak kelimeler ve sayılar çıkarılmış, kök alınmış arama terimleri */
	public static function terms(string $text): array
	{
		$words = preg_split('/[^a-z0-9]+/', self::fold($text)) ?: [];
		$out = [];

		foreach ($words as $w) {
			if ($w === '' || in_array($w, self::STOP, true) || ctype_digit($w) && strlen($w) < 3 || strlen($w) < 2) {
				continue;
			}

			$out[] = self::stem($w);
		}

		return array_values(array_unique(array_slice($out, 0, 8)));
	}

	/** SQL LIKE kalıbı: Türkçe karakter ihtimali olan harfler tek karakter jokeri (_) olur (ör. kulaklik → k_lakl_k ~ kulaklık) */
	public static function likePattern(string $term): string
	{
		return '%' . strtr($term, ['c' => '_', 'g' => '_', 'i' => '_', 'o' => '_', 's' => '_', 'u' => '_', '%' => '', '_' => '']) . '%';
	}

	/**
	 * Katalog araması (gerçek DB). Yalnızca aktif ürünler; satıcı ürünü ise satıcı aktif ve ürün moderasyondan onaylı olmalı.
	 * @param array<string,mixed> $q parse() çıktısı
	 * @return array{items:array<int,array<string,mixed>>,total:int,relaxed:int}
	 */
	public static function search(array $q, int $limit = self::MAX_RESULTS): array
	{
		$terms = $q['terms'];

		if ($terms === [] && $q['min'] === null && $q['max'] === null && !$q['discount']) {
			return ['items' => [], 'total' => 0, 'relaxed' => 0];
		}

		$rows = self::candidates($terms);
		$scored = [];

		foreach ($rows as $r) {
			$s = self::score($r, $terms);

			if ($terms !== [] && $s <= 0) {
				continue;
			}

			$r['_score'] = $s;
			$scored[] = $r;
		}

		$items = self::enrich($scored);
		$beforeFilters = count($items);
		$items = array_values(array_filter($items, static function ($p) use ($q) {
			if ($q['min'] !== null && $p['price'] < $q['min']) {
				return false;
			}

			if ($q['max'] !== null && $p['price'] > $q['max']) {
				return false;
			}

			if ($q['in_stock'] && !$p['in_stock']) {
				return false;
			}

			return !$q['discount'] || $p['discount_pct'] > 0;
		}));

		usort($items, static function ($a, $b) use ($q) {
			switch ($q['sort']) {
				case 'price_asc':
					return $a['price'] <=> $b['price'];
				case 'price_desc':
					return $b['price'] <=> $a['price'];
				case 'rating':
					return [$b['rating'], $b['review_count']] <=> [$a['rating'], $a['review_count']];
				default:
					return [$b['_score'], (int) $b['in_stock'], $a['price']] <=> [$a['_score'], (int) $a['in_stock'], $b['price']];
			}
		});

		return ['items' => array_slice($items, 0, $limit), 'total' => count($items), 'relaxed' => $beforeFilters];
	}

	/** @return array<int,array<string,mixed>> */
	private static function candidates(array $terms): array
	{
		$vendor = self::vendorTables();
		$select = 'SELECT p.*, b.brand_name, b.brand_link, c.category_name, c.category_link, i.id_image'
			. ($vendor ? ', v.id_vendor AS rv_id_vendor, v.store_name AS rv_store_name, v.store_slug AS rv_store_slug' : ", 0 AS rv_id_vendor, '' AS rv_store_name, '' AS rv_store_slug")
			. ' FROM products p INNER JOIN brands b ON p.id_brand = b.id_brand INNER JOIN categories c ON p.id_category = c.id_category'
			. ' LEFT JOIN images i ON p.id_product = i.id_product AND i.cover = 1'
			. ($vendor ? ' LEFT JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product LEFT JOIN rafimda_vendors v ON v.id_vendor = vp.id_vendor' : '')
			. ' WHERE p.active = 1'
			. ($vendor ? " AND (vp.id_product IS NULL OR (v.status = 'active' AND COALESCE(vp.moderation, 'approved') = 'approved'))" : '');
		$params = [];

		if ($terms !== []) {
			$or = [];

			foreach ($terms as $t) {
				foreach (['p.product_name', 'b.brand_name', 'c.category_name', 'p.short_description'] as $col) {
					$or[] = $col . ' LIKE ?';
					$params[] = self::likePattern($t);
				}

				if ($vendor) {
					$or[] = 'v.store_name LIKE ?';
					$params[] = self::likePattern($t);
				}
			}

			$select .= ' AND (' . implode(' OR ', $or) . ')';
		}

		return DB::execute($select . ' ORDER BY p.id_product DESC LIMIT 400', $params) ?: [];
	}

	/** @param array<string,mixed> $r */
	private static function score(array $r, array $terms): float
	{
		$name = self::fold((string) $r['product_name']);
		$brand = self::fold((string) $r['brand_name']);
		$cat = self::fold((string) $r['category_name']);
		$store = self::fold((string) ($r['rv_store_name'] ?? ''));
		$desc = self::fold((string) ($r['short_description'] ?? ''));
		$s = 0.0;
		$hit = 0;

		foreach ($terms as $t) {
			$h = 0.0;
			$h += str_contains($name, $t) ? 3 : 0;
			$h += str_contains($brand, $t) ? 2 : 0;
			$h += str_contains($cat, $t) ? 2 : 0;
			$h += $store !== '' && str_contains($store, $t) ? 1.5 : 0;
			$h += str_contains($desc, $t) ? 0.5 : 0;
			$hit += $h > 0 ? 1 : 0;
			$s += $h;
		}

		// tüm terimler eşleşirse belirgin öncelik; hiçbiri eşleşmezse 0
		return $hit === 0 ? 0.0 : $s + ($hit === count($terms) ? 10 : 0) * $hit / max(1, count($terms));
	}

	/**
	 * Kart/karşılaştırma için ortak, yalnızca gerçek verilerden oluşan ürün özeti.
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	public static function enrich(array $rows): array
	{
		if ($rows === []) {
			return [];
		}

		$meta = [];

		foreach ($rows as $r) {
			$meta[(int) $r['id_product']] = $r;
		}

		$list = class_exists('Product') && method_exists('Product', 'enrichList') ? Product::enrichList($rows) : $rows;
		$out = [];
		$ratings = [];

		foreach ($list as $p) {
			$id = (int) $p['id_product'];
			$m = $meta[$id] ?? $p;
			$price = (float) $p['price'];
			$old = (float) ($p['old_price'] ?? 0);
			$stock = (float) ($p['stock'] ?? 0);
			$inStock = array_key_exists('in_stock', $p) ? (bool) $p['in_stock'] : $stock > 0;
			$vid = (int) ($m['rv_id_vendor'] ?? 0);
			$seller = ['name' => 'Rafımda', 'url' => '', 'rating' => null, 'rating_count' => 0];

			if ($vid > 0) {
				if (!isset($ratings[$vid]) && class_exists('RvStorefront')) {
					$ratings[$vid] = RvStorefront::rating($vid);
				}

				$seller = [
					'name' => (string) $m['rv_store_name'],
					'url' => class_exists('RvStorefront') ? RvStorefront::url(['store_slug' => (string) $m['rv_store_slug']]) : '',
					'rating' => isset($ratings[$vid]) && $ratings[$vid]['count'] > 0 ? (float) $ratings[$vid]['avg'] : null,
					'rating_count' => (int) ($ratings[$vid]['count'] ?? 0),
				];
			}

			$out[] = [
				'id' => $id,
				'name' => (string) $p['product_name'],
				'url' => (string) ($p['url'] ?? ''),
				'image' => (string) ($p['image_url'] ?? ''),
				'price' => $price,
				'price_text' => (string) ($p['price_formatted'] ?? self::money($price)),
				'old_price' => $old > $price ? $old : null,
				'old_price_text' => $old > $price ? (string) ($p['old_price_formatted'] ?? self::money($old)) : '',
				'discount_pct' => $old > $price && $old > 0 ? (int) round(($old - $price) / $old * 100) : 0,
				'in_stock' => $inStock,
				'stock_text' => !$inStock ? 'Tükendi' : ($stock > 0 && $stock <= 5 ? 'Son ' . (int) $stock . ' adet' : 'Stokta'),
				'brand' => (string) ($m['brand_name'] ?? ''),
				'category' => (string) ($m['category_name'] ?? ''),
				'seller' => $seller,
				'rating' => (float) ($p['rating'] ?? 0),
				'review_count' => (int) ($p['review_count'] ?? 0),
				'features' => self::features((string) ($m['short_description'] ?? ''), (string) ($m['description'] ?? '')),
				'_score' => (float) ($m['_score'] ?? 0),
			];
		}

		return $out;
	}

	/** Ürün açıklamasındaki madde işaretleri veya kısa açıklama (en fazla 4) – yalnızca mevcut metinden */
	public static function features(string $short, string $desc): array
	{
		$items = [];

		if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $desc, $m)) {
			foreach ($m[1] as $li) {
				$t = trim(html_entity_decode(strip_tags($li), ENT_QUOTES, 'UTF-8'));

				if ($t !== '') {
					$items[] = mb_substr($t, 0, 80);
				}
			}
		}

		if ($items === []) {
			$t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($short !== '' ? $short : $desc), ENT_QUOTES, 'UTF-8')));

			if ($t !== '') {
				$items[] = mb_strlen($t) > 140 ? mb_substr($t, 0, 137) . '…' : $t;
			}
		}

		return array_slice($items, 0, 4);
	}

	public static function money(float $v): string
	{
		return '₺' . number_format($v, 2, ',', '.');
	}

	/** @param int[] $ids @return array<int,array<string,mixed>> sırası korunur */
	public static function byIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if ($ids === []) {
			return [];
		}

		$vendor = self::vendorTables();
		$rows = DB::execute('SELECT p.*, b.brand_name, b.brand_link, c.category_name, c.category_link, i.id_image'
			. ($vendor ? ', v.id_vendor AS rv_id_vendor, v.store_name AS rv_store_name, v.store_slug AS rv_store_slug' : ", 0 AS rv_id_vendor, '' AS rv_store_name, '' AS rv_store_slug")
			. ' FROM products p INNER JOIN brands b ON p.id_brand = b.id_brand INNER JOIN categories c ON p.id_category = c.id_category LEFT JOIN images i ON p.id_product = i.id_product AND i.cover = 1'
			. ($vendor ? ' LEFT JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product LEFT JOIN rafimda_vendors v ON v.id_vendor = vp.id_vendor' : '')
			. ' WHERE p.active = 1 AND p.id_product IN (' . implode(',', $ids) . ')'
			. ($vendor ? " AND (vp.id_product IS NULL OR (v.status = 'active' AND COALESCE(vp.moderation, 'approved') = 'approved'))" : '')) ?: [];
		$by = [];

		foreach (self::enrich($rows) as $p) {
			$by[$p['id']] = $p;
		}

		return array_values(array_filter(array_map(static fn($id) => $by[$id] ?? null, $ids)));
	}

	/**
	 * Karşılaştırma tablosu (2–3 ürün); yalnızca mevcut alanlar. Özet cümleler de yalnızca bu değerlerden türetilir.
	 * @param array<int,array<string,mixed>> $items
	 * @return array{columns:array<int,array<string,string>>,rows:array<int,array{label:string,values:string[]}>,notes:string[]}
	 */
	public static function compareTable(array $items): array
	{
		$items = array_slice($items, 0, self::MAX_COMPARE);
		$rows = [
			['label' => 'Fiyat', 'values' => array_map(static fn($p) => $p['price_text'] . ($p['old_price'] ? ' (önceki ' . $p['old_price_text'] . ')' : ''), $items)],
			['label' => 'İndirim', 'values' => array_map(static fn($p) => $p['discount_pct'] > 0 ? '%' . $p['discount_pct'] : '—', $items)],
			['label' => 'Stok', 'values' => array_map(static fn($p) => $p['stock_text'], $items)],
			['label' => 'Marka', 'values' => array_map(static fn($p) => $p['brand'] !== '' ? $p['brand'] : '—', $items)],
			['label' => 'Kategori', 'values' => array_map(static fn($p) => $p['category'] !== '' ? $p['category'] : '—', $items)],
			['label' => 'Satıcı', 'values' => array_map(static fn($p) => $p['seller']['name'] . ($p['seller']['rating'] !== null ? ' (★ ' . number_format((float) $p['seller']['rating'], 1, ',', '') . ', ' . $p['seller']['rating_count'] . ' değerlendirme)' : ''), $items)],
			['label' => 'Ürün puanı', 'values' => array_map(static fn($p) => $p['review_count'] > 0 ? '★ ' . number_format($p['rating'], 1, ',', '') . ' (' . $p['review_count'] . ' yorum)' : 'Henüz yorum yok', $items)],
			['label' => 'Özellikler', 'values' => array_map(static fn($p) => $p['features'] !== [] ? implode(' · ', $p['features']) : 'Açıklamada belirtilmemiş', $items)],
		];
		$notes = [];

		if (count($items) >= 2) {
			$cheap = $items;
			usort($cheap, static fn($a, $b) => $a['price'] <=> $b['price']);

			if ($cheap[0]['price'] < $cheap[1]['price']) {
				$notes[] = 'En düşük fiyat: ' . $cheap[0]['name'] . ' (' . $cheap[0]['price_text'] . ').';
			} else {
				$notes[] = 'Fiyatlar eşit.';
			}

			$out = array_filter($items, static fn($p) => !$p['in_stock']);

			foreach ($out as $p) {
				$notes[] = $p['name'] . ' şu anda stokta yok.';
			}

			$rated = array_filter($items, static fn($p) => $p['review_count'] > 0);

			if (count($rated) >= 2) {
				usort($rated, static fn($a, $b) => $b['rating'] <=> $a['rating']);
				$notes[] = 'Müşteri puanı en yüksek: ' . $rated[0]['name'] . ' (★ ' . number_format($rated[0]['rating'], 1, ',', '') . ').';
			} else {
				$notes[] = 'Puan karşılaştırması için yeterli müşteri yorumu yok.';
			}
		}

		return ['columns' => array_map(static fn($p) => ['name' => $p['name'], 'url' => $p['url']], $items), 'rows' => $rows, 'notes' => $notes];
	}

	/** @return array<int,array{name:string,url:string}> gerçek, aktif üst kategoriler (öneri olarak) */
	public static function topCategories(int $limit = 5): array
	{
		$rows = DB::execute('SELECT c.category_name, c.category_link, COUNT(p.id_product) AS n FROM categories c INNER JOIN products p ON p.id_category = c.id_category AND p.active = 1
			GROUP BY c.id_category, c.category_name, c.category_link ORDER BY n DESC LIMIT ' . max(1, min(10, $limit))) ?: [];
		global $domain;

		return array_map(static fn($r) => ['name' => (string) $r['category_name'], 'url' => rtrim((string) ($domain ?? ''), '/') . '/' . ltrim((string) $r['category_link'], '/')], $rows);
	}

	private static function vendorTables(): bool
	{
		static $has = null;

		if ($has === null) {
			try {
				$has = DB::execute('SELECT id_vendor FROM rafimda_vendors LIMIT 1') !== false && DB::execute('SELECT id_product FROM rafimda_vendor_products LIMIT 1') !== false;
			} catch (Throwable $e) {
				$has = false;
			}
		}

		return $has;
	}
}
