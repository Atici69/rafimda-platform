<?php

/** Basit, idempotent migration çalıştırıcı (rd_migrations). Mevcut FriSay tablolarına dokunmaz. */
class RdMigrator
{
	public static function dir(): string
	{
		return dirname(__DIR__) . '/migrations';
	}

	/** @return list<string> */
	public static function files(): array
	{
		$files = glob(self::dir() . '/*.sql') ?: [];
		sort($files, SORT_STRING);

		return array_map(static fn(string $f): string => basename($f, '.sql'), $files);
	}

	/** @return list<string> */
	public static function applied(): array
	{
		$rows = DB::execute('SELECT version FROM rd_migrations');

		return is_array($rows) ? array_map(static fn(array $r): string => (string) $r['version'], $rows) : [];
	}

	/** @return list<string> */
	public static function pending(): array
	{
		return array_values(array_diff(self::files(), self::applied()));
	}

	/** @return array{success:bool, applied:list<string>, error:string} */
	public static function run(): array
	{
		$done = [];

		foreach (self::pending() as $version) {
			$sql = (string) file_get_contents(self::dir() . '/' . $version . '.sql');

			foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql) ?: [])) as $statement) {
				if (strpos($statement, '--') === 0) {
					continue;
				}

				if (DB::execute($statement) === false) {
					return ['success' => false, 'applied' => $done, 'error' => 'Migration başarısız: ' . $version];
				}
			}

			DB::execute('INSERT INTO rd_migrations (version) VALUES (?)', [$version]);
			$done[] = $version;
		}

		return ['success' => true, 'applied' => $done, 'error' => ''];
	}
}
