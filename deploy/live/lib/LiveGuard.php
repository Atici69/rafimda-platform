<?php

/**
 * CANLI ortam bekçisi. Canlı araçlar veritabanı bilgisini yeni parola girişi yerine sunucudaki mevcut config/env.php'den okur
 * (gizli bilgi komut satırına/loga/repoya girmez). Her işlem açık onay ifadeleri ister; değişiklik yapan mod ayrıca yedek onayı ister.
 */
final class LiveGuard
{
	public const CONFIRM_LIVE = 'EVET-CANLI-DB';
	public const CONFIRM_BACKUP = 'EVET-YEDEK-ALINDI';

	/**
	 * @param array<string,string> $env ortam değişkenleri
	 * @param array<string,mixed>|null $cfg config/env.php içeriği
	 * @return array{ok:bool,errors:list<string>}
	 */
	public static function check(array $env, ?array $cfg, bool $apply): array
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

			if ($get('RAFIMDA_LIVE_DB_NAME') === '' || $get('RAFIMDA_LIVE_DB_NAME') !== (string) ($cfg['DB_NAME'] ?? '')) {
				$errors[] = 'RAFIMDA_LIVE_DB_NAME, config/env.php DB_NAME ile birebir aynı olmalı (yanlış veritabanına karşı elle doğrulama).';
			}
		}

		if ($apply && $get('RAFIMDA_BACKUP_CONFIRM') !== self::CONFIRM_BACKUP) {
			$errors[] = 'Değişiklik için RAFIMDA_BACKUP_CONFIRM=' . self::CONFIRM_BACKUP . ' gerekir (önce veritabanı yedeği alın).';
		}

		return ['ok' => $errors === [], 'errors' => $errors];
	}

	/** @return array<string,string> */
	public static function envFromProcess(): array
	{
		$out = [];

		foreach (['RAFIMDA_ENV', 'RAFIMDA_LIVE_CONFIRM', 'RAFIMDA_LIVE_DB_NAME', 'RAFIMDA_BACKUP_CONFIRM'] as $k) {
			$v = getenv($k);
			$out[$k] = $v === false ? '' : (string) $v;
		}

		return $out;
	}

	/** @param array<string,mixed> $cfg */
	public static function connect(array $cfg): PDO
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

		return $pass !== '' ? str_replace($pass, '***', $message) : $message;
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
