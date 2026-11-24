<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Kurulum sırası bağımsızlığı: SQL takip tablolu tüm modüllerin olası kurulum sıraları, her biri boş SQLite'ta; şema aynı olmalı. MySQL'de ÇALIŞTIRILMAMIŞTIR. */
error_reporting(E_ALL);
require_once dirname(__DIR__) . '/lib/StagingGuard.php';
require_once dirname(__DIR__) . '/lib/StagingSql.php';
require_once dirname(__DIR__) . '/lib/StagingModules.php';
require_once dirname(__DIR__) . '/lib/StagingMigrator.php';
require_once __DIR__ . '/sqlite_support.php';
$pass = 0; $fail = 0;
function t(string $n, bool $c): void { global $pass, $fail; echo ($c ? '  ok   ' : '  FAIL ') . $n . "\n"; $c ? $pass++ : $fail++; }
function perms(array $a): array { if (count($a) <= 1) { return [$a]; } $o = []; foreach ($a as $i => $x) { $r = $a; unset($r[$i]); foreach (perms(array_values($r)) as $p) { array_unshift($p, $x); $o[] = $p; } } return $o; }
$mods = StagingModules::all(); $sigs = []; $allOk = true; $n = 0;
// Modül sayısı arttıkça n! patlar (10 modül = 3,6 milyon sıra). 7'ye kadar TÜM sıralar; fazlasında belirlenimci örneklem:
// her modül bir kez başta + bir kez sonda (döndürmeler), ters sıra ve sabit tohumlu 150 karışık sıra.
function orders(array $mods): array {
	if (count($mods) <= 7) { return perms($mods); }
	$o = [];
	for ($i = 0; $i < count($mods); $i++) { $o[] = array_merge(array_slice($mods, $i), array_slice($mods, 0, $i)); }
	$o[] = array_reverse($mods);
	mt_srand(20261125);
	for ($i = 0; $i < 150; $i++) { $c = $mods; for ($j = count($c) - 1; $j > 0; $j--) { $k = mt_rand(0, $j); [$c[$j], $c[$k]] = [$c[$k], $c[$j]]; } $o[] = $c; }
	return $o;
}
$expected = count($mods) <= 7 ? array_product(range(1, count($mods))) : count($mods) + 151;
foreach (orders($mods) as $order) {
	$pdo = new SqliteMysqlPDO();
	$col = static function (string $t, string $c) use ($pdo): bool { foreach ($pdo->query('PRAGMA table_info(`' . $t . '`)')->fetchAll() as $r) { if ($r['name'] === $c) { return true; } } return false; };
	$mig = new StagingMigrator($pdo, $col);
	foreach ($order as $m) { $r = $mig->runModule($m); if (!$r['success']) { $allOk = false; echo '    ' . $m['key'] . ' ' . json_encode($r['steps'], JSON_UNESCAPED_UNICODE) . "\n"; } }
	$rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
	$sig = []; foreach ($rows as $t) { $c = []; foreach ($pdo->query('PRAGMA table_info(`' . $t . '`)')->fetchAll() as $x) { $c[] = $x['name']; } sort($c); $sig[$t] = $c; }
	$sigs[md5(json_encode($sig))] = count($rows); $n++;
}
t("$n  farklı kurulum sırası hatasız tamamlandı", $allOk && $n === $expected);
t('tüm sıralarda sonuç şema aynı (' . array_values($sigs)[0] . ' tablo)', count($sigs) === 1);
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n"; exit($fail ? 1 : 0);
