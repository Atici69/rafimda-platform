<?php

/** XML anlık görüntü/yükleme dosyalarının yeri. Adlar rastgele üretilir; kullanıcı girdisi dosya adına girmez. */
class XhStorage
{
	public static function dir(): string
	{
		$d = defined('XH_STORAGE_DIR') ? XH_STORAGE_DIR : dirname(__DIR__, 3) . '/storage/xml-hub';

		if (!is_dir($d)) {
			@mkdir($d, 0750, true);
		}

		if (is_dir($d) && !is_file($d . '/.htaccess')) {
			@file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
			@file_put_contents($d . '/index.html', '');
		}

		return $d;
	}

	public static function newPath(string $prefix): string
	{
		return self::dir() . '/' . preg_replace('/[^a-z]/', '', $prefix) . '-' . bin2hex(random_bytes(10)) . '.xml';
	}

	/** Yalnızca depolama klasörü içindeki dosyaları kabul eder (yol gezintisine karşı). */
	public static function isInside(string $path): bool
	{
		$real = realpath($path);
		$base = realpath(self::dir());

		return $real !== false && $base !== false && strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
	}

	public static function remove(string $path): void
	{
		if ($path !== '' && self::isInside($path)) {
			@unlink($path);
		}
	}
}
