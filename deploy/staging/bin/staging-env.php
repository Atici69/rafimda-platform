<?php

/**
 * config/env.php üretir (ortam değişkenlerinden). Mevcut dosyanın ÜZERİNE YAZMAZ. Dosya 0600 izniyle yazılır.
 * APP_ENV bilerek "production" yazılır: FriSay hata gizleme, Secure çerez ve HSTS davranışını yalnızca production'da uygular;
 * staging canlıya benzer davranmalıdır. Staging kimliği RAFIMDA_ENV + veritabanı adı + guard ile korunur.
 * Kullanım: php deploy/staging/bin/staging-env.php
 */
require __DIR__ . '/_common.php';

$env = staging_guard_or_exit();
$target = StagingModules::root() . '/config/env.php';

if (is_file($target)) {
	staging_err('config/env.php zaten var; üzerine yazılmadı. Gerekirse elle düzenleyin veya yedekleyip kaldırın.');
	exit(4);
}

$admin = trim((string) getenv('RAFIMDA_ADMIN_URI'));

if ($admin === '') {
	$admin = 'bo_' . bin2hex(random_bytes(6));
}

if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $admin)) {
	staging_err('RAFIMDA_ADMIN_URI yalnızca harf, rakam, _ ve - içermeli.');
	exit(2);
}

$cfg = [
	'APP_ENV' => 'production',
	'APP_DEBUG' => false,
	'DB_HOST' => $env['DB_HOST'],
	'DB_NAME' => $env['DB_NAME'],
	'DB_USER' => $env['DB_USER'],
	'DB_PASS' => $env['DB_PASS'],
	'REWRITE_BASE' => '/',
	'ADMIN_URI' => $admin,
];

if ($env['DB_PORT'] !== '') {
	staging_out('UYARI: config/connection.php portu desteklemez; MySQL varsayılan portta (3306) olmalı veya socket/host adıyla erişilmeli.');
}

$php = "<?php\n// Staging için otomatik üretildi — GİZLİ. Git'e eklemeyin (config/env.php .gitignore'dadır).\nreturn " . var_export($cfg, true) . ";\n";
$old = umask(0077);
$ok = file_put_contents($target, $php, LOCK_EX);
umask($old);

if ($ok === false) {
	staging_err('config/env.php yazılamadı.');
	exit(5);
}

chmod($target, 0600);
staging_out('config/env.php yazıldı (izin 0600). Yönetim paneli adresi: /' . $admin . '/  — bu değeri not edin.');
