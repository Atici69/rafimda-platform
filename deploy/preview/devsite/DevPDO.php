<?php

/**
 * YALNIZCA GELİŞTİRME ÖNİZLEMESİ: FriSay/Rafımda'yı MySQL olmadan, SQLite dosyası üzerinde gerçek index.php ile çalıştırmak için
 * MySQL sözdizimini SQLite'a çeviren PDO. Canlı/staging'de KULLANILMAZ; release paketine girmez (deploy/ hariç tutulur).
 * Gerçek MySQL davranışını KANITLAMAZ; yalnızca temanın ve sayfaların tarayıcıda görülmesini sağlar.
 */
final class DevPDO extends PDO
{
	public static string $log = '';

	public function __construct(string $file)
	{
		parent::__construct('sqlite:' . $file);
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$this->exec('PRAGMA journal_mode=WAL');
		$fn = [
			'NOW' => [static fn() => date('Y-m-d H:i:s'), 0], 'CURDATE' => [static fn() => date('Y-m-d'), 0], 'CURTIME' => [static fn() => date('H:i:s'), 0],
			'RAND' => [static fn() => mt_rand() / mt_getrandmax(), 0], 'UNIX_TIMESTAMP' => [static fn($d = null) => $d === null ? time() : (int) strtotime((string) $d), -1],
			'CONCAT' => [static fn(...$a) => in_array(null, $a, true) ? null : implode('', $a), -1], 'CONCAT_WS' => [static fn($s, ...$a) => implode((string) $s, array_filter($a, static fn($x) => $x !== null)), -1],
			'IF' => [static fn($c, $a, $b) => $c ? $a : $b, 3], 'GREATEST' => [static fn(...$a) => max($a), -1], 'LEAST' => [static fn(...$a) => min($a), -1],
			'DATE_FORMAT' => [static fn($d, $f) => $d === null ? null : date(strtr((string) $f, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's', '%y' => 'y', '%e' => 'j', '%M' => 'F', '%b' => 'M']), (int) strtotime((string) $d)), 2],
			'YEAR' => [static fn($d) => $d === null ? null : (int) date('Y', (int) strtotime((string) $d)), 1], 'MONTH' => [static fn($d) => $d === null ? null : (int) date('n', (int) strtotime((string) $d)), 1],
			'DAY' => [static fn($d) => $d === null ? null : (int) date('j', (int) strtotime((string) $d)), 1], 'DATEDIFF' => [static fn($a, $b) => (int) round((strtotime((string) $a) - strtotime((string) $b)) / 86400), 2],
			'FIELD' => [static function ($v, ...$l) { $i = array_search($v, $l); return $i === false ? 0 : $i + 1; }, -1], 'FLOOR' => [static fn($v) => floor((float) $v), 1], 'CEIL' => [static fn($v) => ceil((float) $v), 1],
			'LCASE' => [static fn($v) => mb_strtolower((string) $v), 1], 'UCASE' => [static fn($v) => mb_strtoupper((string) $v), 1], 'FIND_IN_SET' => [static function ($n, $l) { $i = array_search((string) $n, explode(',', (string) $l), true); return $i === false ? 0 : $i + 1; }, 2],
			'LOWER' => [static fn($v) => $v === null ? null : mb_strtolower((string) $v), 1], 'UPPER' => [static fn($v) => $v === null ? null : mb_strtoupper((string) $v), 1],
			'TIMESTAMPDIFF_SECOND' => [static fn($a, $b) => strtotime((string) $b) - strtotime((string) $a), 2],
		];

		foreach ($fn as $name => [$cb, $n]) {
			$this->sqliteCreateFunction($name, $cb, $n);
		}
	}

	/** MySQL ters eğik çizgi kaçışlarını ('\\'', '\\n') SQLite biçimine çevirir (yalnızca tek tırnaklı dizeler). */
	public static function unescape(string $sql): string
	{
		if (!str_contains($sql, '\\')) {
			return $sql;
		}

		return (string) preg_replace_callback("/'((?:[^'\\\\]|\\\\.|'')*)'/s", static function ($m) {
			$v = strtr($m[1], ['\\\\' => "\x00BS\x00", "\\'" => "''", '\\"' => '"', '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\0' => '']);

			return "'" . str_replace("\x00BS\x00", '\\', $v) . "'";
		}, $sql);
	}

	public static function rewrite(string $sql): string
	{
		$s = self::unescape(trim($sql));
		$s = preg_replace('/\s+FROM\s+DUAL\b/i', '', $s) ?? $s;
		$s = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO/i', 'INSERT OR IGNORE INTO', $s) ?? $s;
		$s = preg_replace('/^\s*REPLACE\s+INTO/i', 'INSERT OR REPLACE INTO', $s) ?? $s;
		$s = preg_replace_callback('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.*)$/is', static fn($m) => 'ON CONFLICT DO UPDATE SET ' . preg_replace('/VALUES\s*\(\s*`?(\w+)`?\s*\)/i', 'excluded.$1', $m[1]), $s) ?? $s;
		$s = preg_replace_callback('/(DATE_SUB|DATE_ADD)\s*\(\s*(.+?)\s*,\s*INTERVAL\s+(-?\??[\d.]*)\s+(SECOND|MINUTE|HOUR|DAY|MONTH|YEAR)\s*\)/i', static function ($m) {
			$sign = strtoupper($m[1]) === 'DATE_SUB' ? '-' : '+';

			return "datetime({$m[2]}, '{$sign}' || {$m[3]} || ' " . strtolower($m[4]) . "')";
		}, $s) ?? $s;
		$s = preg_replace_callback('/(NOW\(\)|CURDATE\(\)|\?|\'[^\']*\'|`?\w+`?\.`?\w+`?|`\w+`)\s*([-+])\s*INTERVAL\s+(\??[\d.]*)\s+(SECOND|MINUTE|HOUR|DAY|MONTH|YEAR)/i', static fn($m) => "datetime({$m[1]}, '{$m[2]}' || {$m[3]} || ' " . strtolower($m[4]) . "')", $s) ?? $s;
		$s = preg_replace('/\bLOCK\s+IN\s+SHARE\s+MODE\b|\bFOR\s+UPDATE\b/i', '', $s) ?? $s;
		$s = preg_replace('/\bCOLLATE\s+\w+/i', '', $s) ?? $s;
		$s = preg_replace('/^\s*SHOW\s+TABLES\s+LIKE\s+(.+)$/i', "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE $1", $s) ?? $s;
		$s = preg_replace('/^\s*SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+`?(\w+)`?\s+LIKE\s+(.+)$/i', "SELECT name AS Field, type AS Type FROM pragma_table_info('$1') WHERE name LIKE $2", $s) ?? $s;
		$s = preg_replace('/^\s*SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+`?(\w+)`?\s*$/i', "SELECT name AS Field, type AS Type FROM pragma_table_info('$1')", $s) ?? $s;
		$s = preg_replace('/^\s*SHOW\s+INDEX(?:ES)?\s+FROM\s+`?(\w+)`?.*$/i', "SELECT name AS Key_name FROM pragma_index_list('$1')", $s) ?? $s;

		return $s;
	}

	/** DDL: CREATE TABLE / ALTER TABLE MySQL biçimlerini SQLite'a çevirir. @return list<string> */
	public static function ddl(string $sql): array
	{
		$s = trim($sql);
		$s = (string) preg_replace('/(`\w+`)\(\d+\)/', '$1', $s); // dizin önek uzunluğu (MySQL) kaldırılır

		if (preg_match('/^SET\s+(NAMES|CHARACTER|COLLATION|FOREIGN_KEY_CHECKS|SQL_MODE|time_zone|@)/i', $s) || preg_match('/^(START TRANSACTION|LOCK TABLES|UNLOCK TABLES|OPTIMIZE TABLE|ANALYZE TABLE)/i', $s)) {
			return ['SELECT 1'];
		}

		if (preg_match('/^CREATE TABLE/i', $s)) {
			$s = (string) preg_replace('/\b(?:tiny|small|medium|big)?int\s*\(\d+\)(\s+unsigned)?/i', 'INTEGER', $s);
			$s = (string) preg_replace('/\bunsigned\b/i', '', $s);
			$s = (string) preg_replace('/\s+ON UPDATE (current_timestamp\(\)|CURRENT_TIMESTAMP)/i', '', $s);
			$s = (string) preg_replace('/\b(CHARACTER SET|CHARSET)\s+\w+/i', '', $s);
			$s = (string) preg_replace('/\bCOLLATE\s*=?\s*\w+/i', '', $s);
			$s = (string) preg_replace("/\\s+COMMENT\\s+'[^']*'/i", '', $s);
			$s = (string) preg_replace('/\benum\s*\([^)]*\)/i', 'TEXT', $s);
			$s = (string) preg_replace('/\)\s*ENGINE\s*=.*$/is', ') ENGINE=InnoDB;', $s);
			$s = (string) preg_replace('/,\s*(FULLTEXT|SPATIAL)\s+KEY[^,\n]*\([^)]*\)/i', '', $s);
			$s = (string) preg_replace('/,\s*(CONSTRAINT\s+`?\w+`?\s+)?FOREIGN KEY[^\n]*/i', '', $s);

			// Aynı satırdaki "PRIMARY KEY (...), KEY ..." tanımları satır bazlı çeviriciye ayrı satır olarak verilir
			$s = (string) preg_replace('/\),\s*((?:PRIMARY|UNIQUE)\s+KEY|KEY|INDEX|UNIQUE\s+INDEX)\s/i', "),\n$1 ", $s);

			if (!preg_match('/ENGINE=/i', $s)) {
				$s .= ' ENGINE=InnoDB;';
			}

			$out = class_exists('MysqlToSqlite') ? MysqlToSqlite::translate($s) : [$s];

			// AUTO_INCREMENT sütunu tanınmadıysa (ör. "int NOT NULL AUTO_INCREMENT" farklı biçim) kaba düzeltme
			return array_map(static fn($q) => (string) preg_replace('/\bAUTO_INCREMENT\b/i', '', $q), $out);
		}

		if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $s, $m)) {
			$t = $m[1];
			$out = [];

			foreach (preg_split('/,\s*(?=(ADD|DROP|MODIFY|CHANGE)\b)/i', $m[2]) ?: [] as $part) {
				$part = trim($part);

				if (preg_match('/^ADD\s+(?:COLUMN\s+)?(`?\w+`?\s+.+?)(\s+(AFTER\s+`?\w+`?|FIRST))?$/is', $part, $a) && !preg_match('/^ADD\s+(UNIQUE|KEY|INDEX|PRIMARY|CONSTRAINT|FULLTEXT)/i', $part)) {
					$col = (string) preg_replace('/\b(?:tiny|small|medium|big)?int\s*\(\d+\)(\s+unsigned)?/i', 'INTEGER', $a[1]);
					$col = (string) preg_replace('/\s+ON UPDATE (current_timestamp\(\)|CURRENT_TIMESTAMP)/i', '', $col);
					$col = (string) preg_replace('/\b(CHARACTER SET|CHARSET)\s+\w+|\bCOLLATE\s+\w+/i', '', $col);
					$col = str_ireplace('current_timestamp()', 'CURRENT_TIMESTAMP', $col);
					$col = (string) preg_replace('/NOT NULL DEFAULT CURRENT_TIMESTAMP/i', "NOT NULL DEFAULT '2000-01-01 00:00:00'", $col);
					$out[] = "ALTER TABLE `$t` ADD COLUMN $col";
				} elseif (preg_match('/^ADD\s+(UNIQUE\s+)?(?:KEY|INDEX)\s+`?(\w+)`?\s*\((.+)\)$/is', $part, $k)) {
					$out[] = 'CREATE ' . ($k[1] ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS `{$t}__{$k[2]}` ON `$t` ({$k[3]})";
				} else {
					$out[] = 'SELECT 1'; // MODIFY/CHANGE/DROP: SQLite önizlemesinde yok sayılır
				}
			}

			return $out;
		}

		if (preg_match('/^(CREATE\s+(UNIQUE\s+)?INDEX)\s+`?(\w+)`?\s+ON\s+`?(\w+)`?\s*(\(.+\))/is', $s, $m)) {
			return ["CREATE {$m[2]}INDEX IF NOT EXISTS `{$m[4]}__{$m[3]}` ON `{$m[4]}` {$m[5]}"];
		}

		if (preg_match('/^DROP\s+(INDEX)/i', $s)) {
			return ['SELECT 1'];
		}

		return [self::rewrite($s)];
	}

	#[\ReturnTypeWillChange]
	public function exec($statement)
	{
		$n = 0;

		foreach (self::ddl((string) $statement) as $q) {
			try {
				$n += (int) parent::exec($q);
			} catch (PDOException $e) {
				if (!preg_match('/duplicate column|already exists/i', $e->getMessage())) {
					self::trace($q, $e);

					throw $e;
				}
			}
		}

		return $n;
	}

	#[\ReturnTypeWillChange]
	public function prepare($query, $options = [])
	{
		$qs = self::ddl((string) $query);

		for ($i = 0; $i < count($qs) - 1; $i++) {
			$this->exec($qs[$i]);
		}

		try {
			return parent::prepare(end($qs), $options);
		} catch (PDOException $e) {
			if (preg_match('/duplicate column|already exists/i', $e->getMessage())) {
				return parent::prepare('SELECT 1 WHERE 0');
			}

			self::trace((string) end($qs), $e);

			throw $e;
		}
	}

	#[\ReturnTypeWillChange]
	public function query($query, $fetchMode = null, ...$args)
	{
		$q = self::rewrite((string) $query);

		try {
			return $fetchMode === null ? parent::query($q) : parent::query($q, $fetchMode, ...$args);
		} catch (PDOException $e) {
			self::trace($q, $e);

			throw $e;
		}
	}

	private static function trace(string $q, Throwable $e): void
	{
		if (self::$log !== '') {
			@file_put_contents(self::$log, date('H:i:s') . ' ' . $e->getMessage() . "\n    " . preg_replace('/\s+/', ' ', mb_substr($q, 0, 400)) . "\n", FILE_APPEND);
		}
	}
}
