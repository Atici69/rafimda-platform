<?php

/**
 * Güvenli XML ayrıştırma: DOCTYPE/ENTITY tamamen reddedilir (XXE, billion-laughs), ağ erişimi kapalı (LIBXML_NONET),
 * UTF-16/32 reddedilir (başlık taraması atlatılamasın), boyut/derinlik/düğüm sınırları vardır, XMLReader ile akış okunur
 * (tüm dosya belleğe alınmaz; yalnızca tek ürün düğümü genişletilir).
 */
class XhXml
{
	public const MAX_BYTES = 52428800;
	public const MAX_DEPTH = 40;
	public const MAX_ITEM_NODES = 3000;
	public const MAX_ITEMS = 200000;
	public const MAX_VALUE_LEN = 65535;

	/** @return array{ok:bool, error:string} */
	public static function inspect(string $path): array
	{
		if (!is_file($path) || !is_readable($path)) {
			return ['ok' => false, 'error' => 'Dosya okunamadı.'];
		}

		$size = (int) filesize($path);

		if ($size <= 0) {
			return ['ok' => false, 'error' => 'Dosya boş.'];
		}

		if ($size > self::MAX_BYTES) {
			return ['ok' => false, 'error' => 'Dosya ' . (int) (self::MAX_BYTES / 1048576) . ' MB sınırını aşıyor.'];
		}

		$fh = fopen($path, 'rb');
		$head = (string) fread($fh, 1048576);
		fclose($fh);

		if (strncmp($head, "\xFF\xFE", 2) === 0 || strncmp($head, "\xFE\xFF", 2) === 0 || strpos(substr($head, 0, 4096), "\0") !== false) {
			return ['ok' => false, 'error' => 'UTF-16/32 veya ikili dosya kabul edilmez; UTF-8 XML yükleyin.'];
		}

		$h = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head);

		if ($h === '' || $h[0] !== '<') {
			return ['ok' => false, 'error' => 'Dosya XML ile başlamıyor.'];
		}

		if (preg_match('/<!(DOCTYPE|ENTITY)/i', $head)) {
			return ['ok' => false, 'error' => 'DOCTYPE/ENTITY içeren XML güvenlik nedeniyle reddedildi.'];
		}

		if (preg_match('/<\?xml[^>]*encoding\s*=\s*["\'](utf-?16|utf-?32|ucs-?[24])/i', $head)) {
			return ['ok' => false, 'error' => 'Desteklenmeyen karakter kodlaması.'];
		}

		return ['ok' => true, 'error' => ''];
	}

	private static function open(string $path): ?XMLReader
	{
		libxml_use_internal_errors(true);
		$r = new XMLReader();

		if (!$r->open($path, null, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT)) {
			return null;
		}

		return $r;
	}

	/**
	 * Dosyanın tamamının iyi biçimli olduğunu, ürün düğümü sayısını ve derinliği doğrular (akış; bellek kullanımı sabit).
	 *
	 * @return array{ok:bool, error:string, nodes:int}
	 */
	public static function validate(string $path): array
	{
		$i = self::inspect($path);

		if (!$i['ok']) {
			return $i + ['nodes' => 0];
		}

		$r = self::open($path);

		if (!$r) {
			return ['ok' => false, 'error' => 'XML açılamadı.', 'nodes' => 0];
		}

		$nodes = 0;
		$depth = 0;
		libxml_clear_errors();

		while (@$r->read()) {
			if ($r->nodeType === XMLReader::ELEMENT) {
				$nodes++;

				if ($r->depth > self::MAX_DEPTH) {
					$r->close();

					return ['ok' => false, 'error' => 'XML çok derin (en fazla ' . self::MAX_DEPTH . ').', 'nodes' => $nodes];
				}

				$depth = max($depth, $r->depth);
			} elseif ($r->nodeType === XMLReader::DOC_TYPE || $r->nodeType === XMLReader::ENTITY_REF || $r->nodeType === XMLReader::ENTITY) {
				$r->close();

				return ['ok' => false, 'error' => 'DOCTYPE/ENTITY reddedildi.', 'nodes' => $nodes];
			}
		}

		$errs = libxml_get_errors();
		libxml_clear_errors();
		$r->close();

		foreach ($errs as $e) {
			if ($e->level >= LIBXML_ERR_ERROR) {
				return ['ok' => false, 'error' => 'XML hatalı: satır ' . $e->line . ': ' . trim($e->message), 'nodes' => $nodes];
			}
		}

		if ($nodes === 0) {
			return ['ok' => false, 'error' => 'XML içinde öğe yok.', 'nodes' => 0];
		}

		return ['ok' => true, 'error' => '', 'nodes' => $nodes];
	}

	/**
	 * Ürün düğümü yolunu tahmin eder: tekrar eden (>=2) ve alt öğeleri olan EN SIĞ yol (ör. "products/product").
	 * Varyant gibi iç içe tekrarlar daha derin olduğundan seçilmez.
	 */
	public static function detectItemPath(string $path, int $scanLimit = 20000): ?string
	{
		$r = self::open($path);

		if (!$r) {
			return null;
		}

		$stack = [];
		$count = [];
		$hasChild = [];
		$kids = [];
		$n = 0;

		while (@$r->read() && $n < $scanLimit) {
			if ($r->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			$n++;
			$d = $r->depth;
			$stack[$d] = $r->localName;
			$stack = array_slice($stack, 0, $d + 1);
			$p = implode('/', $stack);
			$count[$p] = ($count[$p] ?? 0) + 1;

			if ($d > 0) {
				$parent = implode('/', array_slice($stack, 0, $d));
				$hasChild[$parent] = true;
				$kids[$parent][$p] = true;
			}
		}

		$r->close();
		$best = null;
		$bestDepth = PHP_INT_MAX;
		$bestCount = 0;

		foreach ($count as $p => $c) {
			$depth = substr_count($p, '/');

			if ($c >= 2 && isset($hasChild[$p]) && ($depth < $bestDepth || ($depth === $bestDepth && $c > $bestCount))) {
				$best = $p;
				$bestDepth = $depth;
				$bestCount = $c;
			}
		}

		// Tek ürünlü, varyantlı besleme: tekrar eden öğe aslında ürünün varyantlarıdır (Product/Variants/Variant gibi).
		// Ata zincirinde TEK kez geçen, en az 2 yaprak alanı olan düğüm ürün kaydıdır.
		if ($best !== null && preg_match('/(variant|varyant|varyasyon|option|combination|secenek|seçenek|sku|stok)/iu', basename($best))) {
			$parts = explode('/', $best);

			for ($i = count($parts) - 1; $i >= 1; $i--) {
				$anc = implode('/', array_slice($parts, 0, $i));
				$leaves = 0;

				foreach (array_keys($kids[$anc] ?? []) as $k) {
					$leaves += isset($hasChild[$k]) ? 0 : 1;
				}

				if (($count[$anc] ?? 0) === 1 && $leaves >= 2) {
					return $anc;
				}
			}
		}

		if ($best === null) {
			// tek ürünlü dosya: en az 2 yaprak alanı olan en sığ kayıt düğümü
			$cand = null;

			foreach ($kids as $parent => $set) {
				$leaves = 0;

				foreach (array_keys($set) as $k) {
					$leaves += isset($hasChild[$k]) ? 0 : 1;
				}

				if ($leaves >= 2 && ($cand === null || substr_count($parent, '/') < substr_count($cand, '/'))) {
					$cand = $parent;
				}
			}

			return $cand;
		}

		return $best;
	}

	/**
	 * Ürün öğelerini akış olarak üretir. Anahtar = 0 tabanlı sıra numarası.
	 *
	 * @return Generator<int, array<string,mixed>>
	 */
	public static function stream(string $path, string $itemPath, int $offset = 0): Generator
	{
		$r = self::open($path);

		if (!$r) {
			throw new RuntimeException('XML açılamadı.');
		}

		$target = trim($itemPath, '/');
		$stack = [];
		$idx = 0;
		$more = @$r->read();

		while ($more) {
			if ($r->nodeType === XMLReader::ELEMENT) {
				$d = $r->depth;

				if ($d > self::MAX_DEPTH) {
					$r->close();
					throw new RuntimeException('XML çok derin.');
				}

				$stack[$d] = $r->localName;
				$stack = array_slice($stack, 0, $d + 1);

				if (implode('/', $stack) === $target) {
					if ($idx >= self::MAX_ITEMS) {
						$r->close();
						throw new RuntimeException('Ürün sayısı sınırı aşıldı (' . self::MAX_ITEMS . ').');
					}

					if ($idx >= $offset) {
						$doc = new DOMDocument();
						$node = @$r->expand($doc);

						if ($node instanceof DOMElement) {
							$budget = self::MAX_ITEM_NODES;
							$flat = self::flatten($node, '', 0, $budget);

							yield $idx => $flat;
						} else {
							yield $idx => [];
						}
					}

					$idx++;
					$more = @$r->next();

					continue;
				}
			}

			$more = @$r->read();
		}

		$errs = libxml_get_errors();
		libxml_clear_errors();
		$r->close();

		foreach ($errs as $e) {
			if ($e->level >= LIBXML_ERR_FATAL) {
				throw new RuntimeException('XML hatalı: satır ' . $e->line . ': ' . trim($e->message));
			}
		}
	}

	/**
	 * DOM öğesini düz diziye çevirir. Anahtar = göreli yol ("Images/Image", "Price@currency").
	 * Tekrarlayan yaprak → string listesi; tekrarlayan/yapılı öğe → kayıt listesi (her kayıt kendi düz dizisi).
	 *
	 * @return array<string,mixed>
	 */
	private static function flatten(DOMElement $el, string $prefix, int $depth, int &$budget): array
	{
		$out = [];

		if ($el->hasAttributes()) {
			foreach ($el->attributes as $a) {
				$out[$prefix . ($prefix === '' ? '' : '') . '@' . $a->nodeName] = mb_substr((string) $a->nodeValue, 0, self::MAX_VALUE_LEN);
			}
		}

		$groups = [];

		foreach ($el->childNodes as $c) {
			if ($c instanceof DOMElement) {
				if (--$budget < 0 || $depth > 8) {
					throw new RuntimeException('Ürün düğümü çok büyük/derin.');
				}

				$groups[$c->localName][] = $c;
			}
		}

		if ($groups === []) {
			$text = trim($el->textContent);

			if ($prefix === '') {
				return $out + ['#text' => mb_substr($text, 0, self::MAX_VALUE_LEN)];
			}

			$out[$prefix] = mb_substr($text, 0, self::MAX_VALUE_LEN);

			return $out;
		}

		foreach ($groups as $name => $nodes) {
			$key = $prefix === '' ? $name : $prefix . '/' . $name;
			$structured = false;

			foreach ($nodes as $n) {
				foreach ($n->childNodes as $cc) {
					if ($cc instanceof DOMElement) {
						$structured = true;
						break 2;
					}
				}
			}

			if ($structured) {
				if (count($nodes) === 1) {
					$out += self::flatten($nodes[0], $key, $depth + 1, $budget);
				} else {
					$records = [];

					foreach ($nodes as $n) {
						$records[] = self::flatten($n, '', $depth + 1, $budget);
					}

					$out[$key] = $records;
				}
			} elseif (count($nodes) === 1) {
				$out += self::flatten($nodes[0], $key, $depth + 1, $budget);
			} else {
				$vals = [];

				foreach ($nodes as $n) {
					$vals[] = mb_substr(trim($n->textContent), 0, self::MAX_VALUE_LEN);
				}

				$out[$key] = $vals;
			}
		}

		return $out;
	}

	/** İlk N ürünü (önizleme için). @return list<array<string,mixed>> */
	public static function sample(string $path, string $itemPath, int $n = 5): array
	{
		$out = [];

		foreach (self::stream($path, $itemPath, 0) as $row) {
			$out[] = $row;

			if (count($out) >= $n) {
				break;
			}
		}

		return $out;
	}

	/**
	 * XML'i ilk kez tararken ürün kayıtlarını JSONL anlık görüntüsüne ve sabit genişlikli byte-offset
	 * indeksine yazar. Sonraki cron dilimleri XML'i baştan ayrıştırmadan doğrudan kaldığı ürüne gider.
	 *
	 * @return int yazılan ürün sayısı
	 */
	public static function buildSnapshot(string $sourcePath, string $itemPath, string $rowsPath, string $indexPath): int
	{
		$dir = realpath(XhStorage::dir());
		if ($dir === false || realpath(dirname($rowsPath)) !== $dir || realpath(dirname($indexPath)) !== $dir) {
			throw new RuntimeException('XML çalışma dosyası depolama klasörünün dışında.');
		}

		$rows = @fopen($rowsPath, 'wb');
		$index = @fopen($indexPath, 'wb');
		if (!$rows || !$index) {
			if (is_resource($rows)) { fclose($rows); }
			if (is_resource($index)) { fclose($index); }
			throw new RuntimeException('XML ürün anlık görüntüsü oluşturulamadı.');
		}

		$count = 0;
		try {
			foreach (self::stream($sourcePath, $itemPath, 0) as $raw) {
				$json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
				if (!is_string($json)) {
					throw new RuntimeException('XML ürün kaydı JSON biçimine dönüştürülemedi.');
				}
				$offset = ftell($rows);
				if ($offset === false || $offset > 9999999999) {
					throw new RuntimeException('XML ürün anlık görüntüsü boyut sınırını aştı.');
				}
				$line = $json . "\n";
				if (fwrite($index, sprintf('%010d', $offset) . "\n") !== 11 || fwrite($rows, $line) !== strlen($line)) {
					throw new RuntimeException('XML ürün anlık görüntüsü diske yazılamadı.');
				}
				$count++;
				if ($count > self::MAX_ITEMS || ftell($rows) > 209715200) {
					throw new RuntimeException('XML ürün anlık görüntüsü sınırı aşıldı (200 MB / ' . self::MAX_ITEMS . ' ürün).');
				}
			}
		} finally {
			fclose($rows);
			fclose($index);
		}

		return $count;
	}

	/**
	 * JSONL anlık görüntüsünden ürünleri indeks üzerinden okur; offset öncesindeki ürünleri tekrar taramaz.
	 *
	 * @return Generator<int,array<string,mixed>>
	 */
	public static function streamSnapshot(string $rowsPath, string $indexPath, int $offset = 0): Generator
	{
		if ($offset < 0 || !XhStorage::isInside($rowsPath) || !XhStorage::isInside($indexPath)) {
			throw new RuntimeException('XML ürün anlık görüntüsü bulunamadı.');
		}
		$rows = @fopen($rowsPath, 'rb');
		$index = @fopen($indexPath, 'rb');
		if (!$rows || !$index) {
			if (is_resource($rows)) { fclose($rows); }
			if (is_resource($index)) { fclose($index); }
			throw new RuntimeException('XML ürün indeksi açılamadı.');
		}

		try {
			if (fseek($index, $offset * 11) !== 0) {
				throw new RuntimeException('XML ürün indeksinde konumlanılamadı.');
			}
			$idx = $offset;
			while (($entry = fgets($index, 12)) !== false) {
				if (strlen($entry) !== 11 || substr($entry, 10, 1) !== "\n") {
					throw new RuntimeException('XML ürün indeksi bozuk.');
				}
				$byte = (int) substr($entry, 0, 10);
				if (fseek($rows, $byte) !== 0) {
					throw new RuntimeException('XML ürün kaydına konumlanılamadı.');
				}
				$json = fgets($rows);
				$raw = $json !== false ? json_decode($json, true) : null;
				if (!is_array($raw)) {
					throw new RuntimeException('XML ürün anlık görüntüsü bozuk.');
				}
				yield $idx => $raw;
				$idx++;
			}
		} finally {
			fclose($rows);
			fclose($index);
		}
	}

	public static function countItems(string $path, string $itemPath): int
	{
		$r = self::open($path);

		if (!$r) {
			return 0;
		}

		$target = trim($itemPath, '/');
		$stack = [];
		$c = 0;
		$more = @$r->read();

		while ($more) {
			if ($r->nodeType === XMLReader::ELEMENT) {
				$d = $r->depth;
				$stack[$d] = $r->localName;
				$stack = array_slice($stack, 0, $d + 1);

				if (implode('/', $stack) === $target) {
					$c++;
					$more = @$r->next();

					continue;
				}
			}

			$more = @$r->read();
		}

		$r->close();

		return $c;
	}
}
