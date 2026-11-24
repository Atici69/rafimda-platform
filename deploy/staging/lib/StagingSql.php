<?php

/** SQL dosyalarını ayrıştırır (yalnızca bu projedeki migration biçimi için). */
final class StagingSql
{
	/** Modüllerin kendi çalıştırıcılarıyla aynı ayırma kuralı; tam satır yorumları atılır. @return list<string> */
	public static function statements(string $sql): array
	{
		$lines = [];

		foreach (preg_split('/\r\n|\r|\n/', $sql) ?: [] as $line) {
			if (strpos(ltrim($line), '--') === 0) {
				continue;
			}

			$lines[] = $line;
		}

		$parts = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];

		return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
	}

	/**
	 * CREATE TABLE ve "ALTER TABLE .. ADD COLUMN" ifadelerinden beklenen şemayı çıkarır.
	 *
	 * @return array<string,array{columns:list<string>,indexes:list<string>}>
	 */
	public static function expectedSchema(string $sql): array
	{
		$schema = [];

		foreach (self::statements($sql) as $st) {
			if (preg_match('/^CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*(?:ENGINE|DEFAULT|$)/is', $st, $m)) {
				$table = $m[1];
				$schema[$table] ??= ['columns' => [], 'indexes' => []];

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
					}
				}
			} elseif (($alter = self::alterAddColumn($st)) !== null) {
				$schema[$alter['table']] ??= ['columns' => [], 'indexes' => []];
				$schema[$alter['table']]['columns'][] = $alter['column'];
			}
		}

		foreach ($schema as $t => $def) {
			$schema[$t] = ['columns' => array_values(array_unique($def['columns'])), 'indexes' => array_values(array_unique($def['indexes']))];
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
