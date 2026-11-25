<?php

/**
 * Pazaryeri kategori ağacı (2 seviye) ve güvenli yükleyici.
 *
 * Yalnızca EKSİK kategorileri ekler: mevcut kategorilere dokunmaz (ad, bağlantı, sıra, görsel değişmez),
 * hiçbir şeyi silmez veya taşımaz. Aynı ada (Türkçe büyük/küçük harf duyarsız) sahip kategori aynı üst
 * kategoride zaten varsa o kullanılır. Tekrar çalıştırılırsa yeni kayıt oluşturmaz (idempotent).
 * Bağlantılar üst kategoriyle öneklenir (ör. kadin-giyim) çünkü kategori bağlantıları sitede benzersiz olmalıdır.
 */
class RtCategoryTree
{
	/** @return array<string, list<string>> üst kategori => alt kategoriler */
	public static function tree(): array
	{
		return [
			'Kadın' => ['Giyim', 'Ayakkabı', 'Çanta', 'Aksesuar', 'İç Giyim', 'Spor Giyim', 'Tesettür'],
			'Erkek' => ['Giyim', 'Ayakkabı', 'Çanta', 'Aksesuar', 'İç Giyim', 'Spor Giyim'],
			'Anne & Çocuk' => ['Bebek Giyim', 'Çocuk Giyim', 'Çocuk Ayakkabı', 'Bebek Bakım', 'Oyuncak', 'Bebek Arabası & Oto Koltuğu', 'Beslenme & Emzirme'],
			'Ev & Yaşam' => ['Ev Tekstili', 'Mutfak Gereçleri', 'Sofra & Servis', 'Dekorasyon', 'Aydınlatma', 'Banyo', 'Ev Düzenleme', 'Mobilya'],
			'Süpermarket' => ['Gıda', 'İçecek', 'Temizlik', 'Kağıt Ürünleri', 'Kişisel Hijyen', 'Evcil Hayvan'],
			'Kozmetik' => ['Makyaj', 'Cilt Bakımı', 'Saç Bakım', 'Parfüm & Deodorant', 'Ağız ve Diş Bakımı', 'Erkek Bakım', 'Güneş Ürünleri', 'Kişisel Bakım'],
			'Ayakkabı & Çanta' => ['Kadın Ayakkabı', 'Erkek Ayakkabı', 'Spor Ayakkabı', 'Kadın Çanta', 'Erkek Çanta', 'Valiz & Seyahat'],
			'Saat & Aksesuar' => ['Kol Saati', 'Akıllı Saat', 'Takı & Mücevher', 'Gözlük', 'Cüzdan', 'Kemer'],
			'Elektronik' => ['Cep Telefonu', 'Telefon Aksesuarları', 'Bilgisayar & Tablet', 'Bilgisayar Bileşenleri', 'TV & Ses Sistemleri', 'Kulaklık', 'Beyaz Eşya', 'Küçük Ev Aletleri', 'Kişisel Bakım Aletleri', 'Foto & Kamera', 'Oyun & Konsol'],
			'Spor & Outdoor' => ['Spor Giyim', 'Spor Ayakkabı', 'Fitness & Kondisyon', 'Kamp & Outdoor', 'Bisiklet', 'Takım Sporları'],
			'Vitamin & Takviye' => ['Vitamin', 'Mineral', 'Sporcu Besinleri', 'Bitkisel Takviye', 'Medikal Ürünler'],
			'Kitap, Kırtasiye & Hobi' => ['Kitap', 'Kırtasiye', 'Ofis Malzemeleri', 'Hobi & Oyun', 'Müzik Enstrümanları', 'Sanat Malzemeleri'],
			'Otomotiv & Motosiklet' => ['Oto Aksesuar', 'Oto Bakım', 'Motosiklet Ekipmanları', 'Lastik & Jant'],
			'Yapı Market & Bahçe' => ['Elektrikli El Aletleri', 'Hırdavat', 'Bahçe', 'Tesisat', 'Boya'],
		];
	}

	public static function norm(string $s): string
	{
		$s = str_replace(['I', 'İ'], ['ı', 'i'], trim($s));

		return preg_replace('/\s+/u', ' ', mb_strtolower($s, 'UTF-8')) ?? '';
	}

	public static function slug(string $s): string
	{
		$map = ['ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'I' => 'i', 'İ' => 'i', 'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u', '&' => ' ve '];
		$s = mb_strtolower(strtr($s, $map), 'UTF-8');
		$s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

		return trim(preg_replace('/-+/', '-', $s) ?? '', '-');
	}

	private static function rootId(): int
	{
		return class_exists('Category') ? (int) Category::getRootId() : 0;
	}

	/** @return list<array{id:int,name:string,link:string}> */
	private static function children(int $idParent): array
	{
		$rows = DB::execute('SELECT id_category, category_name, category_link FROM categories WHERE id_parent = ?', [$idParent]) ?: [];

		return array_map(static fn(array $r): array => ['id' => (int) $r['id_category'], 'name' => (string) $r['category_name'], 'link' => (string) $r['category_link']], $rows);
	}

	private static function find(array $list, string $name): ?array
	{
		$n = self::norm($name);

		foreach ($list as $c) {
			if (self::norm($c['name']) === $n) {
				return $c;
			}
		}

		return null;
	}

	private static function linkFree(string $link): bool
	{
		return !(int) DB::getValue('SELECT COUNT(*) FROM categories WHERE category_link = ?', [$link]);
	}

	/** Benzersiz bağlantı: önce aday, doluysa -2, -3 … */
	private static function uniqueLink(string $base): string
	{
		$base = $base !== '' ? mb_substr($base, 0, 120) : 'kategori';
		$link = $base;

		for ($i = 2; !self::linkFree($link) && $i < 100; $i++) {
			$link = $base . '-' . $i;
		}

		return $link;
	}

	/**
	 * Plan: hangi kategoriler var, hangileri eklenecek. Veritabanına yazmaz.
	 *
	 * @return array{root:int, existing:int, create:int, items:list<array{top:string,name:string,exists:bool}>}
	 */
	public static function plan(): array
	{
		$out = ['root' => self::rootId(), 'existing' => 0, 'create' => 0, 'items' => []];

		if ($out['root'] <= 0) {
			return $out;
		}

		$tops = self::children($out['root']);

		foreach (self::tree() as $top => $subs) {
			$t = self::find($tops, $top);
			$out['items'][] = ['top' => $top, 'name' => '', 'exists' => $t !== null];
			$t ? $out['existing']++ : $out['create']++;
			$kids = $t ? self::children($t['id']) : [];

			foreach ($subs as $sub) {
				$e = $t !== null && self::find($kids, $sub) !== null;
				$out['items'][] = ['top' => $top, 'name' => $sub, 'exists' => $e];
				$e ? $out['existing']++ : $out['create']++;
			}
		}

		return $out;
	}

	/**
	 * Eksik kategorileri ekler. Yeni üst kategoriler ana sayfada gösterilir (show_on_home), mevcutların ayarı değişmez.
	 *
	 * @return array{success:bool, message:string, created:int, skipped:int, errors:list<string>}
	 */
	public static function apply(): array
	{
		$res = ['success' => false, 'message' => '', 'created' => 0, 'skipped' => 0, 'errors' => []];
		$root = self::rootId();

		if ($root <= 0 || !class_exists('Category')) {
			$res['message'] = 'Kök kategori bulunamadı.';

			return $res;
		}

		$homePos = (int) DB::getValue('SELECT COALESCE(MAX(home_position), 0) FROM categories');

		foreach (self::tree() as $top => $subs) {
			$t = self::find(self::children($root), $top);

			if ($t) {
				$res['skipped']++;
			} else {
				$r = Category::save(['category_name' => $top, 'category_link' => self::uniqueLink(self::slug($top)), 'id_parent' => $root, 'active' => 1, 'show_on_home' => 1, 'home_position' => ++$homePos]);

				if (empty($r['success'])) {
					$res['errors'][] = $top . ': ' . ($r['message'] ?? 'eklenemedi');
					continue;
				}

				$t = ['id' => (int) $r['id'], 'name' => $top, 'link' => ''];
				$res['created']++;
			}

			$kids = self::children($t['id']);
			$parentSlug = self::slug($top);

			foreach ($subs as $sub) {
				if (self::find($kids, $sub)) {
					$res['skipped']++;
					continue;
				}

				$r = Category::save(['category_name' => $sub, 'category_link' => self::uniqueLink($parentSlug . '-' . self::slug($sub)), 'id_parent' => $t['id'], 'active' => 1, 'show_on_home' => 0]);

				if (empty($r['success'])) {
					$res['errors'][] = $top . ' › ' . $sub . ': ' . ($r['message'] ?? 'eklenemedi');
					continue;
				}

				$res['created']++;
			}
		}

		// Sıra: ağaçtaki düzen (üst kategoriler ve alt kategoriler). Yalnızca category_position değişir.
		$tops = self::children($root);
		$pos = 0;

		foreach (self::tree() as $top => $subs) {
			$t = self::find($tops, $top);

			if (!$t) {
				continue;
			}

			DB::update('categories', ['category_position' => ++$pos], 'id_category = :id', ['id' => $t['id']]);
			$kids = self::children($t['id']);
			$sp = 0;

			foreach ($subs as $sub) {
				$k = self::find($kids, $sub);

				if ($k) {
					DB::update('categories', ['category_position' => ++$sp], 'id_category = :id', ['id' => $k['id']]);
				}
			}
		}

		$res['success'] = $res['errors'] === [];
		$res['message'] = $res['created'] . ' kategori eklendi, ' . $res['skipped'] . ' kategori zaten vardı.' . ($res['errors'] ? ' ' . count($res['errors']) . ' hata.' : '');

		if (class_exists('RvAudit')) {
			RvAudit::log(0, 'category.tree_import', 'category', 0, ['created' => $res['created'], 'skipped' => $res['skipped']]);
		}

		return $res;
	}
}
