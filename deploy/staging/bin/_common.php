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
