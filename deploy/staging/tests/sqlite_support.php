<?php

/**
 * Test desteği: MySQL biçimli DDL'i SQLite'a çevirir. YALNIZCA testlerde kullanılır; gerçek MySQL davranışını KANITLAMAZ.
 */
final class MysqlToSqlite
{
	/** @return list<string> bir MySQL ifadesinin SQLite karşılığı (CREATE TABLE için ek CREATE INDEX ifadeleri dahil) */
	public static function translate(string $st): array
	{
		if (!preg_match('/^\s*CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*ENGINE=[^;]*;?\s*$/is', $st, $m)) {
			return [$st];
		}

		$table = $m[1];
		$lines = preg_split('/\r\n|\r|\n/', $m[2]) ?: [];
		$cols = [];
		$idx = [];
		$autoCol = null;

		foreach ($lines as $line) {
			$line = rtrim(trim($line), ',');

			if ($line === '') {
				continue;
			}

			if (preg_match('/^`(\w+)`\s+\w+(?:\([^)]*\))?\s+NOT NULL AUTO_INCREMENT$/i', $line, $a)) {
				$autoCol = $a[1];
				$cols[] = '`' . $a[1] . '` INTEGER PRIMARY KEY AUTOINCREMENT';
			} elseif (preg_match('/^PRIMARY KEY\s*\((.+)\)$/i', $line, $p)) {
				if ($autoCol === null || trim($p[1], '` ') !== $autoCol) {
					$cols[] = 'PRIMARY KEY (' . $p[1] . ')';
				}
			} elseif (preg_match('/^(UNIQUE\s+)?KEY\s+`(\w+)`\s*\((.+)\)$/i', $line, $k)) {
				$idx[] = 'CREATE ' . ($k[1] !== '' ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS `' . $table . '__' . $k[2] . '` ON `' . $table . '` (' . $k[3] . ')';
			} else {
				$cols[] = str_ireplace('current_timestamp()', 'CURRENT_TIMESTAMP', $line);
			}
		}

		return array_merge(['CREATE TABLE IF NOT EXISTS `' . $table . "` (\n  " . implode(",\n  ", $cols) . "\n)"], $idx);
	}

	/** @return array{columns:list<string>,indexes:list<string>}|null */
	public static function readTable(PDO $pdo, string $table): ?array
	{
		$cols = $pdo->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC);

		if (!$cols) {
			return null;
		}

		$indexes = [];

		foreach ($cols as $c) {
			if ((int) $c['pk'] > 0) {
				$indexes[] = 'PRIMARY';
			}
		}

		foreach ($pdo->query('PRAGMA index_list(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC) as $i) {
			$n = (string) $i['name'];
			$indexes[] = strpos($n, $table . '__') === 0 ? substr($n, strlen($table) + 2) : $n;
		}

		return ['columns' => array_column($cols, 'name'), 'indexes' => array_values(array_unique($indexes))];
	}
}

/** exec() içinde MySQL DDL'ini SQLite'a çeviren PDO. */
final class SqliteMysqlPDO extends PDO
{
	public function __construct()
	{
		parent::__construct('sqlite::memory:');
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	}

	#[\ReturnTypeWillChange]
	public function exec($statement)
	{
		$n = 0;

		foreach (MysqlToSqlite::translate($statement) as $s) {
			$n += (int) parent::exec($s);
		}

		return $n;
	}
}
