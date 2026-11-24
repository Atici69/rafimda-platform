<?php

/**
 * Yedek dosyası denetimi (SALT OKUNUR; veritabanına BAĞLANMAZ, dosyayı değiştirmez/silmez/taşımaz, içeriğini ekrana basmaz).
 * Desteklenen: .sql, .sql.gz, .zip. Yalnızca "kullanılabilir görünüyor mu" sorusunu yanıtlar; gerçek geri yükleme denemesinin yerini TUTMAZ.
 */
class LiveBackup
{
	public const CORE_TABLES = ['users', 'products', 'orders', 'order_detail', 'categories', 'settings', 'modules'];
	public const MIN_BYTES = 1024;

	/**
	 * @return array{ok:bool,kind:string,size:int,mtime:int,sha256:string,errors:list<string>,warnings:list<string>,tables:list<string>,missing_core:list<string>,entries:int,notes:list<string>}
	 */
	public static function inspect(string $path, bool $hash = true): array
	{
		$r = ['ok' => false, 'kind' => '', 'size' => 0, 'mtime' => 0, 'sha256' => '', 'errors' => [], 'warnings' => [], 'tables' => [], 'missing_core' => [], 'entries' => 0, 'notes' => []];
		clearstatcache(true, $path);

		if (!is_file($path) || !is_readable($path)) {
			$r['errors'][] = 'Dosya yok veya okunamıyor.';

			return $r;
		}

		$r['size'] = (int) filesize($path);
		$r['mtime'] = (int) filemtime($path);

		if ($r['size'] < self::MIN_BYTES) {
			$r['errors'][] = 'Dosya çok küçük (' . $r['size'] . ' bayt).';
		}

		if ($hash) {
			$r['sha256'] = (string) hash_file('sha256', $path);
		}

		$lower = strtolower($path);

		if (substr($lower, -4) === '.zip') {
			$r['kind'] = 'zip';
			self::inspectZip($path, $r);
		} elseif (substr($lower, -7) === '.sql.gz' || substr($lower, -3) === '.gz') {
			$r['kind'] = 'sql.gz';
			self::inspectSql($path, true, $r);
		} elseif (substr($lower, -4) === '.sql') {
			$r['kind'] = 'sql';
			self::inspectSql($path, false, $r);
		} else {
			$r['errors'][] = 'Desteklenmeyen uzantı (.sql, .sql.gz veya .zip bekleniyor).';
		}

		if ($r['mtime'] > 0 && time() - $r['mtime'] > 7 * 86400) {
			$r['warnings'][] = 'Yedek 7 günden eski.';
		}

		$r['ok'] = $r['errors'] === [];

		return $r;
	}

	/** @param array<string,mixed> $r */
	private static function inspectSql(string $path, bool $gz, array &$r): void
	{
		$h = $gz ? @gzopen($path, 'rb') : @fopen($path, 'rb');

		if (!$h) {
			$r['errors'][] = 'Dosya açılamadı (bozuk sıkıştırma olabilir).';

			return;
		}

		$tables = [];
		$inserts = 0;
		$lines = 0;
		$sawEnd = false;

		while (($line = $gz ? gzgets($h, 1048576) : fgets($h, 1048576)) !== false) {
			$lines++;

			if (isset($line[0]) && ($line[0] === 'C' || $line[0] === 'c') && preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $line, $m)) {
				$tables[$m[1]] = true;
			} elseif (isset($line[0]) && ($line[0] === 'I' || $line[0] === 'i') && stripos($line, 'INSERT INTO') === 0) {
				$inserts++;
			}

			if (strpos($line, 'Dump completed') !== false) {
				$sawEnd = true;
			}
		}

		$ok = $gz ? gzeof($h) : feof($h);
		$gz ? gzclose($h) : fclose($h);

		if (!$ok) {
			$r['errors'][] = 'Dosya sonuna kadar okunamadı (bozuk veya kesik).';
		}

		$r['tables'] = array_keys($tables);
		$r['entries'] = $inserts;
		$r['missing_core'] = array_values(array_diff(self::CORE_TABLES, $r['tables']));

		if ($tables === []) {
			$r['errors'][] = 'Hiç CREATE TABLE bulunamadı; bu bir veritabanı dökümü gibi görünmüyor.';
		} elseif ($r['missing_core'] !== []) {
			$r['errors'][] = 'Çekirdek tablolar yedekte yok: ' . implode(', ', $r['missing_core']) . '.';
		}

		if ($inserts === 0) {
			$r['warnings'][] = 'Hiç INSERT INTO satırı yok (yalnızca yapı olabilir; veri içermeyebilir).';
		}

		if (!$sawEnd) {
			$r['notes'][] = 'phpMyAdmin/mysqldump "Dump completed" satırı görülmedi (yalnızca bilgi; her araç yazmaz).';
		}

		if (preg_match('/rafimda_staging|_staging|_test/i', implode(' ', $r['tables']))) {
			$r['warnings'][] = 'Tablo adlarında staging/test izi var; yedek canlı veritabanından mı?';
		}
	}

	/** @param array<string,mixed> $r */
	private static function inspectZip(string $path, array &$r): void
	{
		if (!class_exists('ZipArchive')) {
			$r['errors'][] = 'ZipArchive eklentisi yok; ZIP denetlenemedi.';

			return;
		}

		$z = new ZipArchive();
		$code = $z->open($path, ZipArchive::CHECKCONS);

		if ($code !== true) {
			$r['errors'][] = 'ZIP bozuk veya tutarsız (kod ' . (is_int($code) ? $code : 0) . ').';

			return;
		}

		$r['entries'] = $z->numFiles;
		$have = [];

		for ($i = 0; $i < $z->numFiles; $i++) {
			$n = (string) $z->getNameIndex($i);
			$n = preg_replace('#^[^/]+/(?=(index\.php|config/|core/|img/|modules/))#', '', $n) ?? $n;

			foreach (['index.php', 'config/', 'core/', 'img/', 'modules/'] as $k) {
				if (strpos($n, $k) === 0) {
					$have[$k] = true;
				}
			}

			if (preg_match('#(^|/)config/env\.php$#', $n)) {
				$have['env'] = true;
			}
		}

		$z->close();

		foreach (['index.php', 'core/', 'modules/'] as $k) {
			if (!isset($have[$k])) {
				$r['errors'][] = 'ZIP içinde beklenen yol yok: ' . $k;
			}
		}

		foreach (['config/' => 'config/ yok', 'img/' => 'img/ yok (ürün görselleri yedekte olmayabilir)'] as $k => $msg) {
			if (!isset($have[$k])) {
				$r['warnings'][] = $msg;
			}
		}

		if (!isset($have['env'])) {
			$r['warnings'][] = 'config/env.php ZIP içinde görünmüyor; yedek geri yüklenirse veritabanı bağlantı ayarı elle yazılmalı.';
		} else {
			$r['notes'][] = 'ZIP, config/env.php (parola içerir) barındırıyor: bu dosyayı GitHub\'a veya sohbete yüklemeyin.';
		}
	}
}
