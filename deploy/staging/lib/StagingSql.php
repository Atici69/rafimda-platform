<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** SQL dosyalarını ayrıştırır (yalnızca bu projedeki migration biçimi için). */
final class StagingSql
{
	/**
	 * SQL metnini ifadelere böler. Tırnak (' " `) içindeki ; ve yorum işaretleri ifadeyi bölmez; "-- ", "#" ve
	 * blok yorumları atılır. DELIMITER/prosedür gövdesi desteklenmez: görülürse istisna fırlatılır (sessizce yanlış bölmez).
	 *
	 * @return list<string>
	 * @throws RuntimeException desteklenmeyen yapı veya kapanmamış tırnak/yorum
	 */
	public static function statements(string $sql): array
	{
		$out = [];
		$cur = '';
		$n = strlen($sql);
		$i = 0;

		while ($i < $n) {
			$c = $sql[$i];
			$next = $i + 1 < $n ? $sql[$i + 1] : '';

			if ($c === "'" || $c === '"' || $c === '`') {
				$j = $i + 1;

				while ($j < $n) {
					if ($sql[$j] === '\\' && $c !== '`') {
						$j += 2;
						continue;
					}

					if ($sql[$j] === $c) {
						if (($j + 1 < $n) && $sql[$j + 1] === $c) {
							$j += 2;
							continue;
						}

						break;
					}

					$j++;
				}

				if ($j >= $n) {
					throw new RuntimeException('Kapanmamış tırnak: ' . substr($sql, $i, 40));
				}

				$cur .= substr($sql, $i, $j - $i + 1);
				$i = $j + 1;
				continue;
			}

			$lineStart = $i === 0 || $sql[$i - 1] === "\n";

			if (($c === '-' && $next === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2]) || $lineStart)) || $c === '#') {
				$eol = strpos($sql, "\n", $i);
				$i = $eol === false ? $n : $eol;
				continue;
			}

			if ($c === '/' && $next === '*') {
				$end = strpos($sql, '*/', $i + 2);

				if ($end === false) {
					throw new RuntimeException('Kapanmamış blok yorumu.');
				}

				$cur .= ' ';
				$i = $end + 2;
				continue;
			}

			if ($c === ';') {
				self::push($out, $cur);
				$cur = '';
				$i++;
				continue;
			}

			$cur .= $c;
			$i++;
		}

		self::push($out, $cur);

		return $out;
	}

	/** @param list<string> $out */
	private static function push(array &$out, string $stmt): void
	{
		$stmt = trim($stmt);

		if ($stmt === '') {
			return;
		}

		if (preg_match('/^(DELIMITER|CREATE\s+(DEFINER\S*\s+)?(PROCEDURE|FUNCTION|TRIGGER|EVENT))\b/i', $stmt)) {
			throw new RuntimeException('Desteklenmeyen yapı (DELIMITER/prosedür/tetikleyici): ' . substr($stmt, 0, 60));
		}

		$out[] = $stmt;
	}

	/**
	 * CREATE TABLE ve "ALTER TABLE .. ADD COLUMN" ifadelerinden beklenen şemayı çıkarır.
	 *
	 * @return array<string,array{columns:list<string>,indexes:list<string>,auto:list<string>}>
	 */
	public static function expectedSchema(string $sql): array
	{
		$schema = [];

		foreach (self::statements($sql) as $st) {
			if (preg_match('/^CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*(?:ENGINE|DEFAULT|$)/is', $st, $m)) {
				$table = $m[1];
				$schema[$table] ??= ['columns' => [], 'indexes' => [], 'auto' => []];

				foreach (preg_split('/\r\n|\r|\n/', $m[2]) ?: [] as $line) {
					$line = trim($line);

					if ($line === '') {
						continue;
					}

					if (preg_match('/^PRIMARY KEY\b/i', $line)) {
						$schema[$table]['indexes'][] = 'PRIMARY';
					} elseif (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`(\w+)`/i', $line, $k)) {
						$schema[$table]['indexes'][] = $k[1];
					} elseif (preg_match('/^`(\w+)`\s+\w+/', $line, $c)) {
						$schema[$table]['columns'][] = $c[1];

						if (stripos($line, 'AUTO_INCREMENT') !== false) {
							$schema[$table]['auto'][] = $c[1];
						}
					}
				}
			} elseif (($alter = self::alterAddColumn($st)) !== null) {
				$schema[$alter['table']] ??= ['columns' => [], 'indexes' => [], 'auto' => []];
				$schema[$alter['table']]['columns'][] = $alter['column'];
			}
		}

		foreach ($schema as $t => $def) {
			$schema[$t] = ['columns' => array_values(array_unique($def['columns'])), 'indexes' => array_values(array_unique($def['indexes'])), 'auto' => array_values(array_unique($def['auto']))];
		}

		return $schema;
	}

	/** @return array{table:string,column:string}|null */
	public static function alterAddColumn(string $statement): ?array
	{
		if (preg_match('/^ALTER TABLE\s+`?(\w+)`?\s+ADD COLUMN\s+`?(\w+)`?\s/i', trim($statement), $m)) {
			return ['table' => $m[1], 'column' => $m[2]];
		}

		return null;
	}
}
