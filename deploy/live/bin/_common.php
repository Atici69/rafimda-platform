<?php

/** Canlı araçlar ortak yükleyicisi. Yalnızca komut satırı; web'den 404. */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$root = dirname(__DIR__, 3);

foreach (['StagingSql', 'StagingModules', 'StagingMigrator', 'StagingVerifier'] as $c) {
	require_once $root . '/deploy/staging/lib/' . $c . '.php';
}

require_once dirname(__DIR__) . '/lib/LiveGuard.php';

function live_out(string $s): void
{
	fwrite(STDOUT, $s . "\n");
}

function live_err(string $s): void
{
	fwrite(STDERR, $s . "\n");
}

/** @return array<string,mixed>|null */
function live_read_cfg(string $root): ?array
{
	$f = $root . '/config/env.php';
	$c = is_file($f) ? require $f : null;

	return is_array($c) ? $c : null;
}

/** @return array{0:array<string,mixed>,1:PDO} */
function live_guard_and_connect(bool $apply): array
{
	$root = dirname(__DIR__, 3);
	$cfg = live_read_cfg($root);
	$g = LiveGuard::check(LiveGuard::envFromProcess(), $cfg, $apply);

	if (!$g['ok']) {
		live_err('REDDEDİLDİ — canlı güvenlik kontrolü geçmedi, veritabanına bağlanılmadı:');

		foreach ($g['errors'] as $e) {
			live_err('  - ' . $e);
		}

		live_err("\nBkz. docs/CANLI_KURULUM_NATRO.md");
		exit(2);
	}

	try {
		return [$cfg, LiveGuard::connect($cfg)];
	} catch (Throwable $e) {
		live_err('Veritabanı bağlantısı kurulamadı: ' . LiveGuard::scrub($e->getMessage(), $cfg));
		exit(3);
	}
}

function live_count(PDO $pdo, string $table): ?int
{
	try {
		return (int) $pdo->query('SELECT COUNT(*) FROM `' . preg_replace('/[^a-z0-9_]/i', '', $table) . '`')->fetchColumn();
	} catch (PDOException $e) {
		return null;
	}
}
