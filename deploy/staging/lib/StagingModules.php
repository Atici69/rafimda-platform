<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Rafımda modüllerinin kurulum tanımları (sıra önemlidir). */
final class StagingModules
{
	public static function root(): string
	{
		return dirname(__DIR__, 3);
	}

	/**
	 * @return list<array{key:string,dir:string,tracker:?string,install:string,migrations:string}>
	 */
	public static function all(?string $root = null): array
	{
		$root = $root ?? self::root();
		$defs = [
			['rafimda-vendor', 'rafimda_migrations'],
			['xml-hub', 'xh_migrations'],
			['marketplace-hub', 'mh_migrations'],
			['rafimda-showcase', null],
			['rafimda-dealer', 'rd_migrations'],
			['rafimda-campaign', null],
			['rafimda-legal', null],
			['rafimda-invoice', null],
			['rafimda-social', null],
			['rafimda-catalog', null],
		];
		$out = [];

		foreach ($defs as [$key, $tracker]) {
			$out[] = [
				'key' => $key,
				'dir' => $root . '/modules/' . $key,
				'tracker' => $tracker,
				'install' => $root . '/modules/' . $key . '/install.sql',
				'migrations' => $root . '/modules/' . $key . '/migrations',
			];
		}

		return $out;
	}

	/** @return list<string> sıralı migration sürümleri (uzantısız dosya adları) */
	public static function migrationVersions(array $module): array
	{
		$files = glob($module['migrations'] . '/*.sql') ?: [];
		sort($files, SORT_STRING);

		return array_map(static fn(string $f): string => basename($f, '.sql'), $files);
	}

	/** Bir modülün beklenen tüm şeması (install.sql + migration'lar). */
	public static function expectedSchema(array $module): array
	{
		$sql = is_file($module['install']) ? (string) file_get_contents($module['install']) : '';

		foreach (self::migrationVersions($module) as $v) {
			$sql .= "\n" . file_get_contents($module['migrations'] . '/' . $v . '.sql');
		}

		return StagingSql::expectedSchema($sql);
	}
}
