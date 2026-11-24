<?php

/**
 * CANLI ortam bekçisi. Canlı araçlar veritabanı bilgisini yeni parola girişi yerine sunucudaki mevcut config/env.php'den okur
 * (gizli bilgi komut satırına/loga/repoya girmez). Her işlem açık onay ifadeleri ister; değişiklik yapan mod ayrıca yedek onayı ister.
 */
final class LiveGuard
{
	public const CONFIRM_LIVE = 'EVET-CANLI-DB';
	public const CONFIRM_BACKUP = 'EVET-YEDEK-ALINDI';
	public const CONFIRM_MIGRATE = 'EVET-MIGRATION';
	public const CONFIRM_MODULE = 'EVET-MODUL-KURULUMU';
	/** Değişiklik yapan işlem türleri; her biri ayrı onay ifadesi ister (migration ile modül kurulumu birbirinin yerine geçmez). */
	public const OPERATIONS = ['migrate' => ['RAFIMDA_MIGRATE_CONFIRM', self::CONFIRM_MIGRATE], 'module-install' => ['RAFIMDA_MODULE_CONFIRM', self::CONFIRM_MODULE]];
	/** Yedek dosyası en fazla bu kadar saniye eski olabilir. */
	public const BACKUP_MAX_AGE = 86400;
	public const BACKUP_MIN_BYTES = 1024;

	/**
	 * @param array<string,string> $env ortam değişkenleri
	 * @param array<string,mixed>|null $cfg config/env.php içeriği
	 * @return array{ok:bool,errors:list<string>}
	 */
	public static function check(array $env, ?array $cfg, bool $apply, string $operation = 'migrate'): array
	{
		$errors = [];
		$get = static fn(string $k): string => trim((string) ($env[$k] ?? ''));

		if ($get('RAFIMDA_ENV') !== 'live') {
			$errors[] = 'RAFIMDA_ENV "live" olmalı.';
		}

		if ($get('RAFIMDA_LIVE_CONFIRM') !== self::CONFIRM_LIVE) {
			$errors[] = 'RAFIMDA_LIVE_CONFIRM değeri ' . self::CONFIRM_LIVE . ' olmalı.';
		}

		if ($cfg === null) {
			$errors[] = 'config/env.php bulunamadı veya dizi döndürmüyor (FriSay kurulu değil).';
		} else {
			foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $k) {
				if (trim((string) ($cfg[$k] ?? '')) === '') {
					$errors[] = 'config/env.php içinde ' . $k . ' boş.';
				}
			}

			if (($cfg['APP_ENV'] ?? '') !== 'production') {
				$errors[] = 'config/env.php APP_ENV "production" olmalı (hata gizleme ve Secure çerez için).';
			}

			if (!empty($cfg['APP_DEBUG'])) {
				$errors[] = 'config/env.php APP_DEBUG canlıda kapalı olmalı.';
			}

			$dbName = strtolower(trim((string) ($cfg['DB_NAME'] ?? '')));

			if ($dbName !== '' && preg_match('/(staging|stg|test)/', $dbName)) {
				$errors[] = 'DB_NAME staging/test veritabanı gibi görünüyor (' . $dbName . '); canlı araçlar reddetti.';
			}

			if ($dbName !== '' && preg_match('/[^a-z0-9_]/', $dbName)) {
				$errors[] = 'DB_NAME yalnızca harf, rakam ve alt çizgi içermeli.';
			}

			if ($get('RAFIMDA_STAGING_DB_NAME') !== '' && strtolower($get('RAFIMDA_STAGING_DB_NAME')) === $dbName) {
				$errors[] = 'DB_NAME, bildirilen staging veritabanı adıyla aynı (RAFIMDA_STAGING_DB_NAME).';
			}

			if ($get('RAFIMDA_LIVE_DB_NAME') === '' || $get('RAFIMDA_LIVE_DB_NAME') !== (string) ($cfg['DB_NAME'] ?? '')) {
				$errors[] = 'RAFIMDA_LIVE_DB_NAME, config/env.php DB_NAME ile birebir aynı olmalı (yanlış veritabanına karşı elle doğrulama).';
			}
		}

		if ($get('RAFIMDA_STAGING_CONFIRM') !== '') {
			$errors[] = 'RAFIMDA_STAGING_CONFIRM tanımlı: staging ve canlı ortam değişkenleri karıştırılmış olabilir; temiz bir oturum açın.';
		}

		$site = $get('RAFIMDA_SITE_URL');

		if ($site !== '' && preg_match('/^(test|staging|stg)\./', strtolower((string) parse_url($site, PHP_URL_HOST)))) {
			$errors[] = 'RAFIMDA_SITE_URL bir staging adresi; canlı araçlar reddetti.';
		}

		if ($apply) {
			if ($get('RAFIMDA_BACKUP_CONFIRM') !== self::CONFIRM_BACKUP) {
				$errors[] = 'Değişiklik için RAFIMDA_BACKUP_CONFIRM=' . self::CONFIRM_BACKUP . ' gerekir (önce veritabanı yedeği alın).';
			}

			// Yedek dosyası ZORUNLU: yalnızca ifade yazmak yetmez; dosya var, anlamlı boyutta (>= 1 KB) ve en fazla 24 saat eski olmalı.
			$backupFile = $get('RAFIMDA_BACKUP_FILE');
			clearstatcache(true, $backupFile);

			if ($backupFile === '') {
				$errors[] = 'RAFIMDA_BACKUP_FILE gerekir (aldığınız yedek dosyasının tam yolu).';
			} elseif (!is_file($backupFile) || (int) @filesize($backupFile) < self::BACKUP_MIN_BYTES) {
				$errors[] = 'RAFIMDA_BACKUP_FILE bulunamadı veya çok küçük (en az ' . self::BACKUP_MIN_BYTES . ' bayt).';
			} elseif (time() - (int) @filemtime($backupFile) > self::BACKUP_MAX_AGE) {
				$errors[] = 'RAFIMDA_BACKUP_FILE 24 saatten eski; yeni yedek alın.';
			}

			if (!isset(self::OPERATIONS[$operation])) {
				$errors[] = 'Bilinmeyen işlem türü: ' . $operation . '.';
			} else {
				[$var, $phrase] = self::OPERATIONS[$operation];

				if ($get($var) !== $phrase) {
					$errors[] = $var . '=' . $phrase . ' gerekir (' . $operation . ' için ayrı onay).';
				}
			}
		}

		return ['ok' => $errors === [], 'errors' => $errors];
	}

	/** @return array<string,string> */
	public static function envFromProcess(): array
	{
		$out = [];

		foreach (['RAFIMDA_ENV', 'RAFIMDA_LIVE_CONFIRM', 'RAFIMDA_LIVE_DB_NAME', 'RAFIMDA_BACKUP_CONFIRM', 'RAFIMDA_BACKUP_FILE', 'RAFIMDA_MIGRATE_CONFIRM', 'RAFIMDA_MODULE_CONFIRM', 'RAFIMDA_STAGING_CONFIRM', 'RAFIMDA_STAGING_DB_NAME', 'RAFIMDA_SITE_URL'] as $k) {
			$v = getenv($k);
			$out[$k] = $v === false ? '' : (string) $v;
		}

		return $out;
	}

	/** Komut satırı mı? Web (cgi/fpm/apache) SAPI'lerinde veya HTTP istek değişkenleri varsa false. @param array<string,mixed> $server */
	public static function isCli(string $sapi, array $server = []): bool
	{
		return in_array($sapi, ['cli', 'phpdbg'], true) && !isset($server['REQUEST_METHOD']) && !isset($server['HTTP_HOST']) && !isset($server['GATEWAY_INTERFACE']);
	}

	/**
	 * Güvenlik kontrolü TEKRAR çalıştırılmadan bağlantı açılmaz (check atlanarak PDO açılamaz).
	 *
	 * @param array<string,mixed> $cfg
	 * @param array<string,string> $env
	 */
	public static function connect(array $cfg, array $env, bool $apply = false, string $operation = 'migrate', string $sapi = PHP_SAPI): PDO
	{
		if (!self::isCli($sapi, $sapi === PHP_SAPI ? $_SERVER : [])) {
			throw new RuntimeException('Canlı araçlar yalnızca komut satırından çalışır.');
		}

		$g = self::check($env, $cfg, $apply, $operation);

		if (!$g['ok']) {
			throw new RuntimeException('Canlı güvenlik kontrolü geçmedi: ' . implode(' ', $g['errors']));
		}

		return self::open($cfg);
	}

	/** @param array<string,mixed> $cfg */
	private static function open(array $cfg): PDO
	{
		return new PDO('mysql:host=' . $cfg['DB_HOST'] . ';dbname=' . $cfg['DB_NAME'] . ';charset=utf8mb4', (string) $cfg['DB_USER'], (string) ($cfg['DB_PASS'] ?? ''), [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}

	/** Hata metninden DB parolasını maskeler. */
	public static function scrub(string $message, array $cfg): string
	{
		$pass = (string) ($cfg['DB_PASS'] ?? '');

		if ($pass !== '') {
			$message = str_replace($pass, '***', $message);
		}

		foreach (['DB_USER', 'DB_HOST'] as $k) {
			$v = (string) ($cfg[$k] ?? '');

			if (strlen($v) >= 4 && $v !== 'localhost') {
				$message = str_replace($v, '***', $message);
			}
		}

		return $message;
	}

	/**
	 * Veritabanının doluluk özeti (yalnızca sayılar; kayıt içeriği okunmaz).
	 *
	 * @param callable(string):?int $count tablo → satır sayısı (tablo yoksa null)
	 * @return array{core_installed:bool,has_data:bool,counts:array<string,?int>}
	 */
	public static function summarize(callable $count): array
	{
		$counts = [];

		foreach (['settings', 'products', 'users', 'orders', 'admins', 'categories', 'rafimda_vendors'] as $t) {
			$counts[$t] = $count($t);
		}

		$core = true;

		foreach (['settings', 'products', 'users', 'orders', 'admins', 'categories'] as $t) {
			$core = $core && $counts[$t] !== null;
		}

		$data = ($counts['products'] ?? 0) > 0 || ($counts['users'] ?? 0) > 0 || ($counts['orders'] ?? 0) > 0;

		return ['core_installed' => $core, 'has_data' => $data, 'counts' => $counts];
	}
}
