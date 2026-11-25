<?php

/**
 * Pazaryeri kategori özelliklerini ürün verisinden otomatik doldurur (yalnızca eşlemesi BOŞ olanlar; elle girilen değer ezilmez).
 *
 *  - Menşei: "Türkiye"/"TR" değeri (listede varsa)
 *  - Cinsiyet: ürün adı/kategori yolundaki kadın/erkek/unisex/çocuk ifadesinden
 *  - Yaş grubu: çocuk/bebek ifadesi → Çocuk/Bebek, yoksa Yetişkin
 *  - Diğer özellikler: özelliğin değer listesindeki bir değer ürün adında/açıklamasında geçiyorsa o değer
 *    (ör. Renk listesindeki "Siyah", Materyal listesindeki "Pamuk"); en uzun eşleşme tercih edilir
 * Varyant eksenleri (renk/beden) varyant satırlarından ayrıca gelir; burada yalnızca ortak değer doldurulur.
 */
class MhAttributeFill
{
	/**
	 * @param list<array<string,mixed>> $attrs kategori özellikleri
	 * @param array<string,array{value_id:string,value_text:string}> $map mevcut eşleme
	 * @param array<string,mixed> $p MhPayload
	 * @return array<string,array{value_id:string,value_text:string,auto?:bool}>
	 */
	public static function fill(array $attrs, array $map, array $p): array
	{
		$text = ' ' . self::clean((string) ($p['name'] ?? '') . ' ' . (string) ($p['category']['remote_category_name'] ?? '') . ' ' . (string) ($p['site_category_path'] ?? '')) . ' ';
		$long = ' ' . self::clean(mb_substr((string) ($p['description'] ?? ''), 0, 3000)) . ' ';
		$axes = [];

		foreach ((array) ($p['variants'] ?? []) as $v) {
			foreach ((array) ($v['options'] ?? []) as $n => $_) {
				$axes[MhCatalog::norm((string) $n)] = true;
			}
		}

		foreach ($attrs as $at) {
			$aid = (string) $at['id'];

			if (isset($map[$aid]) || empty($at['values'])) {
				continue;
			}

			$name = MhCatalog::norm((string) $at['name']);

			if (!empty($at['varianter']) && isset($axes[$name])) {
				continue;
			}

			$pick = null;

			if (strpos($name, 'mense') !== false || $name === 'origin') {
				$pick = self::byNames($at['values'], ['türkiye', 'turkiye', 'tr', 'turkey']);
			} elseif ($name === 'cinsiyet' || $name === 'gender') {
				$g = self::has($text, ['kiz cocuk', 'kız çocuk']) ? ['kız çocuk', 'kiz cocuk', 'kız'] : (self::has($text, ['erkek cocuk', 'erkek çocuk']) ? ['erkek çocuk', 'erkek cocuk'] : (self::has($text, ['kadin', 'kadın', 'bayan']) ? ['kadın', 'kadin'] : (self::has($text, ['erkek']) ? ['erkek'] : (self::has($text, ['unisex']) ? ['unisex'] : []))));
				$pick = $g !== [] ? self::byNames($at['values'], $g) : null;
			} elseif (strpos($name, 'yas grubu') !== false || $name === 'yas') {
				$pick = self::has($text, ['bebek']) ? self::byNames($at['values'], ['bebek']) : (self::has($text, ['cocuk', 'çocuk']) ? self::byNames($at['values'], ['çocuk', 'cocuk']) : self::byNames($at['values'], ['yetişkin', 'yetiskin']));
			}

			if ($pick === null) {
				$pick = self::inText($at['values'], $text) ?? self::inText($at['values'], $long);
			}

			if ($pick !== null) {
				$map[$aid] = ['value_id' => (string) $pick['id'], 'value_text' => (string) $pick['name'], 'auto' => true];
			}
		}

		return $map;
	}

	private static function clean(string $s): string
	{
		return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', MhCatalog::norm(strip_tags($s))) ?? '');
	}

	/** @param list<string> $needles */
	private static function has(string $text, array $needles): bool
	{
		foreach ($needles as $n) {
			if (strpos($text, ' ' . self::clean($n) . ' ') !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{id:string,name:string}> $values
	 * @param list<string> $names
	 * @return array{id:string,name:string}|null
	 */
	private static function byNames(array $values, array $names): ?array
	{
		foreach ($names as $n) {
			foreach ($values as $v) {
				if (MhCatalog::norm((string) $v['name']) === MhCatalog::norm($n)) {
					return $v;
				}
			}
		}

		return null;
	}

	/**
	 * Değer adı metinde tam kelime olarak geçiyor mu (en uzun eşleşme; 3 harften kısa ve sayısal değerler atlanır).
	 *
	 * @param list<array{id:string,name:string}> $values
	 * @return array{id:string,name:string}|null
	 */
	private static function inText(array $values, string $text): ?array
	{
		$best = null;
		$len = 0;

		foreach ($values as $v) {
			$n = self::clean((string) $v['name']);

			if (mb_strlen($n) < 3 || is_numeric($n) || in_array($n, ['yok', 'var', 'diğer', 'diger', 'standart', 'belirtilmemiş', 'belirtilmemis'], true)) {
				continue;
			}

			if (mb_strlen($n) > $len && strpos($text, ' ' . $n . ' ') !== false) {
				$best = $v;
				$len = mb_strlen($n);
			}
		}

		return $best;
	}
}
