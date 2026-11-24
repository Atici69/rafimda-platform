<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Tüm test takımlarını çalıştırır ve özet tablo basar (çıkış kodu: herhangi biri başarısızsa 1).
 * Hepsi SQLite + stub/çeviri üzerindedir; MySQL'de ÇALIŞMAZ.
 */
$root = dirname(__DIR__, 3);
$suites = [
	'rafimda-vendor/run' => 'modules/rafimda-vendor/tests/run.php',
	'rafimda-vendor/run_v2' => 'modules/rafimda-vendor/tests/run_v2.php',
	'rafimda-vendor/run_v3' => 'modules/rafimda-vendor/tests/run_v3.php',
	'rafimda-vendor/run_flows' => 'modules/rafimda-vendor/tests/run_flows.php',
	'rafimda-vendor/run_app' => 'modules/rafimda-vendor/tests/run_app.php',
	'rafimda-vendor/run_engage' => 'modules/rafimda-vendor/tests/run_engage.php',
	'rafimda-campaign/run' => 'modules/rafimda-campaign/tests/run.php',
	'rafimda-legal/run' => 'modules/rafimda-legal/tests/run.php',
	'rafimda-invoice/run' => 'modules/rafimda-invoice/tests/run.php',
	'xml-hub/run' => 'modules/xml-hub/tests/run.php',
	'xml-hub/run_v2' => 'modules/xml-hub/tests/run_v2.php',
	'xml-hub/run_v3' => 'modules/xml-hub/tests/run_v3.php',
	'marketplace-hub/run' => 'modules/marketplace-hub/tests/run.php',
	'marketplace-hub/run_v2' => 'modules/marketplace-hub/tests/run_v2.php',
	'rafimda-showcase/run' => 'modules/rafimda-showcase/tests/run.php',
	'rafimda-dealer/run' => 'modules/rafimda-dealer/tests/run.php',
	'rafimda-dealer/run_flows' => 'modules/rafimda-dealer/tests/run_flows.php',
	'rafimda-dealer/run_rbac' => 'modules/rafimda-dealer/tests/run_rbac.php',
	'rafimda-dealer/run_passive' => 'modules/rafimda-dealer/tests/run_passive.php',
	'rafimda-dealer/load_module' => 'modules/rafimda-dealer/tests/load_module.php',
	'rafimda-theme/run' => 'modules/rafimda-theme/tests/run.php',
	'rafimda-theme/run_integration' => 'modules/rafimda-theme/tests/run_integration.php',
	'staging/run' => 'deploy/staging/tests/run.php',
	'staging/run_seed' => 'deploy/staging/tests/run_seed.php',
	'staging/run_order' => 'deploy/staging/tests/run_order.php',
	'staging/run_web' => 'deploy/staging/tests/run_web.php',
	'live/run' => 'deploy/live/tests/run.php',
	'live/run_php81' => 'deploy/live/tests/run_php81.php',
];
$total = 0;
$failed = 0;
$bad = [];

foreach ($suites as $name => $file) {
	$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . $file) . ' 2>&1');

	if (preg_match('/SONUÇ: (\d+) geçti, (\d+) başarısız/', $out, $m)) {
		printf("  %-26s %4d geçti, %d başarısız\n", $name, $m[1], $m[2]);
		$total += (int) $m[1];
		$failed += (int) $m[2];
	} else {
		printf("  %-26s ÇALIŞMADI (özet satırı yok)\n", $name);
		$failed++;
		$bad[] = $name;
	}
}

echo "\nTOPLAM: $total geçti, $failed başarısız\n";
exit($failed > 0 ? 1 : 0);
