<?php

/**
 * Staging güvenlik bekçisi. Hiçbir staging aracı, bu kontroller geçmeden veritabanına bağlanmaz.
 * Amaç: canlı veritabanına yanlışlıkla migration/seed çalıştırmayı engellemek.
 */
final class StagingGuard
{
	public const CONFIRM_PHRASE = 'EVET-STAGING-DB';

	/** Bu ortam değişkenlerinin hepsi zorunludur. */
	public const REQUIRED = ['RAFIMDA_ENV', 'RAFIMDA_STAGING_CONFIRM', 'RAFIMDA_PROD_DB_NAME', 'DB_HOST', 'DB_NAME', 'DB_USER'];

	/**
	 * @param array<string,string|false|null> $env ortam değişkenleri (getenv dizisi)
	 * @return array{ok:bool, errors:list<string>}
	 */
	public static function check(array $env): array
	{
		$errors = [];
		$get = static fn(string $k): string => trim((string) ($env[$k] ?? ''));

		foreach (self::REQUIRED as $k) {
			if ($get($k) === '') {
				$errors[] = $k . ' tanımlı değil.';
			}
		}

		if ($errors) {
			return ['ok' => false, 'errors' => $errors];
		}

		if ($get('RAFIMDA_ENV') !== 'staging') {
			$errors[] = 'RAFIMDA_ENV "staging" olmalı (şu an: ' . $get('RAFIMDA_ENV') . ').';
		}

		if ($get('RAFIMDA_STAGING_CONFIRM') !== self::CONFIRM_PHRASE) {
			$errors[] = 'RAFIMDA_STAGING_CONFIRM değeri ' . self::CONFIRM_PHRASE . ' olmalı.';
		}

		$name = strtolower($get('DB_NAME'));
		$prod = strtolower($get('RAFIMDA_PROD_DB_NAME'));

		if ($name === $prod) {
			$errors[] = 'DB_NAME canlı veritabanı adıyla aynı (' . $prod . '). İşlem reddedildi.';
		}

		if (!preg_match('/(staging|stg|test)/', $name)) {
			$errors[] = 'DB_NAME "staging", "stg" veya "test" içermeli (şu an: ' . $name . ').';
		}

		$prodHost = strtolower($get('RAFIMDA_PROD_DB_HOST'));

		if ($prodHost !== '' && $prodHost === strtolower($get('DB_HOST')) && $name === $prod) {
			$errors[] = 'DB_HOST ve DB_NAME canlı ile aynı.';
		}

		$site = $get('RAFIMDA_SITE_URL');

		if ($site !== '') {
			$host = strtolower((string) parse_url($site, PHP_URL_HOST));

			if ($host === '' || !preg_match('/^(test|staging|stg)\./', $host)) {
				$errors[] = 'RAFIMDA_SITE_URL bir staging adresi olmalı (test.… / staging.…); şu an: ' . $host . '.';
			}
		}

		if (preg_match('/[^a-z0-9_]/', $name)) {
			$errors[] = 'DB_NAME yalnızca harf, rakam ve alt çizgi içermeli.';
		}

		return ['ok' => $errors === [], 'errors' => $errors];
	}

	/** Ortam değişkenlerini toplar (yalnızca getenv; dosyadan okumaz). @return array<string,string> */
	public static function envFromProcess(): array
	{
		$out = [];

		foreach (array_merge(self::REQUIRED, ['DB_PASS', 'DB_PORT', 'RAFIMDA_PROD_DB_HOST', 'RAFIMDA_SITE_URL']) as $k) {
			$v = getenv($k);
			$out[$k] = $v === false ? '' : (string) $v;
		}

		return $out;
	}

	/** Hata mesajlarında parolayı maskeler. */
	public static function scrub(string $message, array $env): string
	{
		$pass = (string) ($env['DB_PASS'] ?? '');

		return $pass !== '' ? str_replace($pass, '***', $message) : $message;
	}

	/** Güvenlik kontrolünden sonra PDO bağlantısı açar. */
	public static function connect(array $env): PDO
	{
		$port = (int) ($env['DB_PORT'] ?? 0) ?: 3306;
		$dsn = 'mysql:host=' . $env['DB_HOST'] . ';port=' . $port . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';

		return new PDO($dsn, $env['DB_USER'], (string) ($env['DB_PASS'] ?? ''), [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}
}
