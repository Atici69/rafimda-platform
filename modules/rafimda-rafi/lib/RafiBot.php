<?php
/**
 * Rafi sohbet akışı: mesaj → niyet → gerçek katalog verisi → yanıt.
 * Yanıt metinleri sabit şablonlardır; içindeki her sayı/ad/fiyat RafiEngine'in DB'den okuduğu değerlerdir.
 */
class RafiBot
{
	public const GREETING = 'Merhaba, ben Rafi! Rafımda kataloğunda ürün aramanıza ve ürünleri fiyat, stok, satıcı ve özelliklere göre karşılaştırmanıza yardımcı olurum. Ne arıyorsunuz?';

	/**
	 * @param int[] $lastIds önceki yanıtta gösterilen ürünler (oturumda tutulur, istemciden alınmaz)
	 * @return array{reply:string,products:array<int,array<string,mixed>>,compare:?array,suggestions:array<int,array<string,string>>,ids:int[]}
	 */
	public static function reply(string $message, array $lastIds = []): array
	{
		$message = trim(preg_replace('/\s+/u', ' ', strip_tags($message)) ?? '');
		$base = ['reply' => '', 'products' => [], 'compare' => null, 'suggestions' => [], 'ids' => $lastIds];

		if ($message === '') {
			return ['reply' => 'Aradığınız ürünü yazın; örneğin “kulaklık”, “1000 TL altı akıllı saat” veya “ilk ikisini karşılaştır”.'] + $base;
		}

		if (mb_strlen($message) > RafiEngine::MAX_LEN) {
			return ['reply' => 'Mesajınız çok uzun; lütfen ' . RafiEngine::MAX_LEN . ' karakteri geçmeyen kısa bir arama yazın.'] + $base;
		}

		$q = RafiEngine::parse($message);

		switch ($q['intent']) {
			case 'greet':
				return ['reply' => self::GREETING, 'suggestions' => self::chips()] + $base;
			case 'thanks':
				return ['reply' => 'Rica ederim! Başka bir ürün aramak isterseniz yazmanız yeterli.'] + $base;
			case 'help':
				return ['reply' => 'Yapabildiklerim: ürün arama (ör. “kulaklık”), fiyat aralığı (ör. “500-1500 TL arası telefon”, “1000 TL altı”), sıralama (“en ucuz”), filtre (“stokta”, “indirimli”) ve karşılaştırma (“ilk ikisini karşılaştır” veya “X ile Y’yi karşılaştır”). Bilgileri yalnızca Rafımda kataloğundaki güncel kayıtlardan veririm; sipariş, ödeme veya iade işlemi yapamam.', 'suggestions' => self::chips()] + $base;
			case 'compare':
				return self::compare($q, $lastIds) + $base;
		}

		return self::search($q, $message) + $base;
	}

	/** @param array<string,mixed> $q */
	private static function search(array $q, string $original = ''): array
	{
		if ($q['terms'] === [] && $q['min'] === null && $q['max'] === null && !$q['discount']) {
			return ['reply' => 'Ne aradığınızı anlayamadım. Ürün adı, marka veya kategori yazabilirsiniz (ör. “Xiaomi saat”, “vitamin”).', 'suggestions' => self::categoryChips()];
		}

		$r = RafiEngine::search($q);
		$filters = self::filterText($q);

		if ($r['items'] === []) {
			$shown = $original !== '' ? mb_substr($original, 0, 60) : implode(' ', $q['terms']);
			$msg = 'Rafımda kataloğunda' . ($shown !== '' ? ' “' . $shown . '”' : '') . ' için' . ($filters !== '' ? ' (' . $filters . ')' : '') . ' eşleşen ürün bulamadım.';

			if ($r['relaxed'] > 0) {
				$msg .= ' Filtreler olmadan ' . $r['relaxed'] . ' ürün var; fiyat/stok koşulunu değiştirerek tekrar deneyebilirsiniz.';
			}

			return ['reply' => $msg, 'suggestions' => self::categoryChips()];
		}

		$n = count($r['items']);
		$msg = $r['total'] . ' ürün buldum' . ($filters !== '' ? ' (' . $filters . ')' : '') . '.' . ($r['total'] > $n ? ' İlk ' . $n . ' tanesi:' : '');

		if ($n >= 2) {
			$msg .= ' Karşılaştırmak için “ilk ikisini karşılaştır” yazabilirsiniz.';
		}

		return ['reply' => $msg, 'products' => self::publicItems($r['items']), 'ids' => array_column($r['items'], 'id')];
	}

	/** @param array<string,mixed> $q @param int[] $lastIds */
	private static function compare(array $q, array $lastIds): array
	{
		$items = [];

		if ($q['compare_parts'] !== []) {
			foreach ($q['compare_parts'] as $part) {
				$sub = RafiEngine::parse($part);
				$sub['sort'] = 'relevance';
				$hit = RafiEngine::search($sub, 1)['items'][0] ?? null;

				if ($hit && !in_array($hit['id'], array_column($items, 'id'), true)) {
					$items[] = $hit;
				}
			}

			if (count($items) < 2) {
				return ['reply' => 'Karşılaştırmak istediğiniz ürünlerin en az ikisini katalogda bulamadım. Ürün adlarını biraz daha açık yazar mısınız?', 'products' => self::publicItems($items), 'ids' => array_column($items, 'id')];
			}
		} else {
			if ($lastIds === []) {
				if ($q['terms'] !== []) {
					$items = RafiEngine::search($q, RafiEngine::MAX_COMPARE)['items'];
				}

				if (count($items) < 2) {
					return ['reply' => 'Önce ürün arayın, sonra “ilk ikisini karşılaştır” yazın; ya da “X ile Y’yi karşılaştır” şeklinde iki ürün adı verin.'];
				}
			} else {
				$picks = $q['picks'] !== [] ? $q['picks'] : [1, 2];
				$ids = [];

				foreach ($picks as $i) {
					if (isset($lastIds[$i - 1])) {
						$ids[] = $lastIds[$i - 1];
					}
				}

				$items = RafiEngine::byIds(array_slice($ids, 0, RafiEngine::MAX_COMPARE));

				if (count($items) < 2) {
					return ['reply' => 'Son listede karşılaştırılacak en az iki ürün yok. Listeden sıra numarası verin (ör. “1 ve 3’ü karşılaştır”).'];
				}
			}
		}

		$table = RafiEngine::compareTable($items);

		return ['reply' => count($items) . ' ürünü karşılaştırdım. ' . implode(' ', $table['notes']), 'products' => self::publicItems($items), 'compare' => $table, 'ids' => array_column($items, 'id')];
	}

	/** @param array<string,mixed> $q */
	private static function filterText(array $q): string
	{
		$p = [];

		if ($q['min'] !== null && $q['max'] !== null) {
			$p[] = RafiEngine::money($q['min']) . ' – ' . RafiEngine::money($q['max']);
		} elseif ($q['max'] !== null) {
			$p[] = RafiEngine::money($q['max']) . ' ve altı';
		} elseif ($q['min'] !== null) {
			$p[] = RafiEngine::money($q['min']) . ' ve üzeri';
		}

		if ($q['in_stock']) {
			$p[] = 'stokta olanlar';
		}

		if ($q['discount']) {
			$p[] = 'indirimli';
		}

		$p[] = ['price_asc' => 'en ucuzdan', 'price_desc' => 'en pahalıdan', 'rating' => 'puana göre', 'relevance' => ''][$q['sort']];

		return implode(', ', array_filter($p));
	}

	/** İstemciye giden alanlar (iç skor vb. çıkarılır) */
	private static function publicItems(array $items): array
	{
		return array_map(static function ($p) {
			unset($p['_score']);

			return $p;
		}, $items);
	}

	/** @return array<int,array<string,string>> */
	private static function chips(): array
	{
		return [['label' => 'Ne yapabilirsin?', 'text' => 'ne yapabilirsin'], ['label' => 'İndirimli ürünler', 'text' => 'indirimli ürünler']];
	}

	/** @return array<int,array<string,string>> */
	private static function categoryChips(): array
	{
		return array_map(static fn($c) => ['label' => $c['name'], 'text' => $c['name']], RafiEngine::topCategories(4));
	}
}
