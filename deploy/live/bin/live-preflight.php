<?php

/**
 * CANLI ÖN KONTROL — YALNIZCA OKUR (hiçbir şey yazmaz/silmez/oluşturmaz).
 * PHP/eklentiler/dizin izinleri/env.php, veritabanı bağlantısı ve doluluk SAYILARI (kayıt içeriği okunmaz).
 * Kullanım:  RAFIMDA_ENV=live RAFIMDA_LIVE_CONFIRM=EVET-CANLI-DB RAFIMDA_LIVE_DB_NAME=<env.php'deki ad> php deploy/live/bin/live-preflight.php
 */
require __DIR__ . '/_common.php';

[$cfg, $pdo] = live_guard_and_connect(false);
$root = dirname(__DIR__, 3);
$v = new StagingVerifier($root);
$v->checkPhp();
$v->checkCoreFiles();
$v->checkSecretFilePerms($root . '/config/env.php', (int) (fileperms($root . '/config/env.php') & 0777));

$ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$v->note('Veritabanı', 'bağlantı + sürüm', 'OK', $ver . ' / ' . $cfg['DB_NAME']);
$mode = (string) $pdo->query('SELECT @@sql_mode')->fetchColumn();
$v->note('Veritabanı', 'sql_mode STRICT', strpos($mode, 'STRICT') !== false ? 'WARN' : 'OK', 'Katı mod açıksa FriSay/modül insert\'leri varsayılansız sütunlarda hata verebilir; staging\'de denenmedi');
$v->note('Veritabanı', 'karakter kümesi utf8mb4', 'OK', (string) $pdo->query('SELECT @@character_set_database')->fetchColumn());

$sum = LiveGuard::summarize(static fn(string $t): ?int => live_count($pdo, $t));
$v->note('Veritabanı', 'FriSay çekirdek tabloları kurulu', $sum['core_installed'] ? 'OK' : 'FAIL', $sum['core_installed'] ? '' : 'Önce FriSay kurulum sihirbazı çalışmalı; bu araçlar çekirdek tablo oluşturmaz.');
$v->note('Veritabanı', 'mevcut veri (yalnızca sayılar)', $sum['has_data'] ? 'WARN' : 'OK', json_encode($sum['counts']) . ($sum['has_data'] ? ' — veritabanı BOŞ DEĞİL; hiçbir şey sıfırlanmaz, yalnızca modül tabloları eklenir' : ''));

$reader = StagingSchemaReader::mysql($pdo);
$pending = [];

foreach (StagingModules::all($root) as $m) {
	if ($m['tracker'] !== null) {
		$p = array_values(array_diff(StagingModules::migrationVersions($m), (new StagingMigrator($pdo))->applied($m['tracker'])));
		$pending[$m['key']] = $p;
	}
}

$v->note('Migration', 'bekleyen migration', 'OK', json_encode($pending));
live_out($v->toText());
exit($v->failures() > 0 ? 1 : 0);
