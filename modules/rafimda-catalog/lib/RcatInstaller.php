<?php

/**
 * Pazaryeri kategori ağacını (RcatTaxonomy) mevcut kategorilere EKLER.
 * - Var olan kategori (aynı üst altında aynı ad, Türkçe harf/büyük-küçük farkı yok sayılır) yeniden oluşturulmaz.
 * - Hiçbir kategori silinmez, yeniden adlandırılmaz, taşınmaz; pasif kategoriler pasif kalır.
 * - Önce plan() ile ne ekleneceği görülür; apply() yalnızca eksikleri ekler (tekrar çalıştırmak güvenlidir).
 */
final class RcatInstaller
{
	/** @return array{root:int, total:int, existing:int, create: array<int, string>} */
	public static function plan(): array
	{
		return self::run(false);
	}

	/** @return array{root:int, total:int, existing:int, create: array<int, string>, created:int, failed: array<int,string>} */
	public static function apply(): array
	{
		return self::run(true);
	}

	public static function slug(string $s): string
	{
		$s = RcatMatcher::norm($s);

		return trim(preg_replace('/\s+/', '-', $s) ?? '', '-');
	}

	/** @return array<string,mixed> */
	private static function run(bool $apply): array
	{
		$rows = DB::execute('SELECT id_category, id_parent, category_name FROM categories') ?: [];
		$children = [];
		$root = 0;

		foreach ($rows as $r) {
			$children[(int) $r['id_parent']][RcatMatcher::norm((string) $r['category_name'])] = (int) $r['id_category'];

			if ((int) $r['id_parent'] === 0 && ($root === 0 || (int) $r['id_category'] < $root)) {
				$root = (int) $r['id_category'];
			}
		}

		$out = ['root' => $root, 'total' => 0, 'existing' => 0, 'create' => [], 'created' => 0, 'failed' => []];

		if ($root === 0) {
			if (!$apply) {
				$out['create'][] = 'Katalog (kök)';
			} else {
				$root = self::insert('Katalog', 0, 'katalog', 0, 0);

				if ($root <= 0) {
					$out['failed'][] = 'Kök kategori oluşturulamadı.';

					return $out;
				}

				$out['root'] = $root;
				$out['created']++;
			}
		}

		$ids = []; // yol → id
		$position = [];

		foreach (RcatTaxonomy::paths() as $p) {
			$out['total']++;
			$path = $p['path'];
			$name = (string) end($path);
			$parentKey = implode(' > ', array_slice($path, 0, -1));
			$parentId = $parentKey === '' ? $root : ($ids[$parentKey] ?? -1);
			$key = implode(' > ', $path);
			$position[$parentKey] = ($position[$parentKey] ?? 0) + 1;

			if ($parentId > 0 && isset($children[$parentId][RcatMatcher::norm($name)])) {
				$ids[$key] = $children[$parentId][RcatMatcher::norm($name)];
				$out['existing']++;
				continue;
			}

			$out['create'][] = $key;

			if (!$apply) {
				$ids[$key] = -1; // planlama: alt kategoriler de oluşturulacak
				continue;
			}

			if ($parentId <= 0) {
				$out['failed'][] = $key . ' (üst kategori yok)';
				continue;
			}

			$slugs = [self::slug($name)];

			for ($i = count($path) - 2; $i >= 0; $i--) {
				$slugs[] = self::slug(implode(' ', array_slice($path, $i)));
			}

			$slug = '';

			foreach ($slugs as $cand) {
				if ($cand !== '' && self::slugFree($cand)) {
					$slug = $cand;
					break;
				}
			}

			if ($slug === '') {
				$base = (string) end($slugs);

				for ($n = 2; $n < 100 && $slug === ''; $n++) {
					$slug = self::slugFree($base . '-' . $n) ? $base . '-' . $n : '';
				}
			}

			$id = $slug !== '' ? self::insert($name, $parentId, $slug, count($path) === 1 ? 1 : 0, $position[$parentKey]) : 0;

			if ($id > 0) {
				$ids[$key] = $id;
				$children[$parentId][RcatMatcher::norm($name)] = $id;
				$out['created']++;
			} else {
				$out['failed'][] = $key;
			}
		}

		if ($apply && $out['created'] > 0) {
			RcatMatcher::reset();
		}

		return $out;
	}

	private static function slugFree(string $slug): bool
	{
		return (int) DB::getValue('SELECT COUNT(*) FROM categories WHERE category_link = ?', [$slug]) === 0;
	}

	private static function insert(string $name, int $parent, string $slug, int $showOnHome, int $position): int
	{
		$name = mb_substr($name, 0, 64);

		if (class_exists('Category') && method_exists('Category', 'save')) {
			$r = Category::save(['category_name' => $name, 'category_link' => $slug, 'id_parent' => $parent, 'active' => 1, 'show_on_home' => $showOnHome, 'home_position' => $position], 0);
			$id = !empty($r['success']) ? (int) ($r['id'] ?? 0) : 0;

			if ($id > 0) {
				DB::update('categories', ['category_position' => $position], 'id_category = :id', ['id' => $id]);
			}

			return $id;
		}

		$id = DB::insert('categories', ['category_name' => $name, 'category_link' => $slug, 'id_parent' => $parent, 'active' => 1, 'show_on_home' => $showOnHome, 'home_position' => $position]);

		return $id ? (int) $id : 0;
	}
}
