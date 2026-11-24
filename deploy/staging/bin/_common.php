<?php

/** Ortak yükleyici. Yalnızca komut satırından çalışır; veritabanı bağlantısı StagingGuard geçmeden açılmaz. */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

foreach (['StagingGuard', 'StagingSql', 'StagingModules', 'StagingMigrator', 'StagingVerifier', 'StagingSeed'] as $c) {
	require_once dirname(__DIR__) . '/lib/' . $c . '.php';
}

function staging_out(string $s): void
{
	fwrite(STDOUT, $s . "\n");
}

function staging_err(string $s): void
{
	fwrite(STDERR, $s . "\n");
}

/** Bekçiyi çalıştırır; geçmezse nedenleri yazıp çıkar. @return array<string,string> */
function staging_guard_or_exit(): array
{
	$env = StagingGuard::envFromProcess();
	$g = StagingGuard::check($env);

	if (!$g['ok']) {
		staging_err("REDDEDİLDİ — staging güvenlik kontrolü geçmedi, veritabanına bağlanılmadı:");

		foreach ($g['errors'] as $e) {
			staging_err('  - ' . $e);
		}

		staging_err("\nGerekli ortam değişkenleri için bkz. docs/STAGING_KURULUM.md (bölüm 3).");
		exit(2);
	}

	return $env;
}

function staging_connect_or_exit(array $env): PDO
{
	try {
		return StagingGuard::connect($env);
	} catch (Throwable $e) {
		staging_err('Veritabanı bağlantısı kurulamadı: ' . StagingGuard::scrub($e->getMessage(), $env));
		exit(3);
	}
}

/**
 * Gerçek FriSay açılışını (config/settings.php) komut satırında yapar.
 * Önce config/env.php'nin ortam değişkenleriyle AYNI veritabanını gösterdiğini doğrular; uyuşmuyorsa açılış çalıştırılmaz
 * (açılış `ensureDefaults` ile ayarlar tablosuna yazar). Ardından bağlanılan veritabanını yeniden kontrol eder.
 */
function staging_boot_core_or_exit(array $env): void
{
	$root = StagingModules::root();
	$cfgFile = $root . '/config/env.php';
	$cfg = is_file($cfgFile) ? require $cfgFile : null;

	if (!is_array($cfg) || ($cfg['DB_NAME'] ?? '') !== $env['DB_NAME'] || ($cfg['DB_HOST'] ?? '') !== $env['DB_HOST']) {
		staging_err('REDDEDİLDİ — config/env.php (DB_HOST/DB_NAME) ortam değişkenleriyle uyuşmuyor veya yok. Önce kurulum sihirbazını veya staging-env.php çalıştırın.');
		exit(2);
	}

	$siteHost = (string) parse_url((string) (getenv('RAFIMDA_SITE_URL') ?: 'https://test.rafimda.com.tr/'), PHP_URL_HOST);
	$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = $siteHost;
	$_SERVER['REQUEST_URI'] = '/';
	$_SERVER['HTTPS'] = 'on';
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	define('IN_SCRIPT', true);
	require_once $root . '/config/settings.php';

	if (!defined('_DB_NAME_') || _DB_NAME_ !== $env['DB_NAME']) {
		staging_err('REDDEDİLDİ — FriSay açılışı beklenen veritabanına bağlanmadı.');
		exit(2);
	}
}
