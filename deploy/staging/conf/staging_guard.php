<?php

/**
 * İSTEĞE BAĞLI ek koruma. php.ini / pool ayarında: auto_prepend_file = /yol/deploy/staging/conf/staging_guard.php
 * 1) Her yanıta X-Robots-Tag: noindex ekler (sunucu ayarı yapılmamışsa bile).
 * 2) config/env.php'deki DB_NAME "test|staging|stg" içermiyorsa isteği 503 ile durdurur
 *    (staging sitesinin yanlışlıkla canlı veritabanına bağlanmasına karşı son savunma).
 * Çekirdek dosyalara dokunmaz.
 */
if (PHP_SAPI !== 'cli') {
	header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

	$envFile = dirname(__DIR__, 3) . '/config/env.php';
	$cfg = is_file($envFile) ? require $envFile : [];
	$name = strtolower((string) (is_array($cfg) ? ($cfg['DB_NAME'] ?? '') : ''));

	if ($name === '' || !preg_match('/(staging|stg|test)/', $name)) {
		http_response_code(503);
		header('Content-Type: text/plain; charset=utf-8');
		echo "Staging koruması: veritabanı adı staging gibi görünmüyor; istek durduruldu.\n";
		exit;
	}
}
