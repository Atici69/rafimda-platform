<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Canlıya yüklenecek sürüm paketini (zip) üretir: `git archive` ile yalnızca GİT'TE İZLENEN dosyalar (env.php, yedek, log, .git yok).
 * Hariç: docs/, modül/staging/live testleri. Çıktıya commit kimliği ve SHA-256 yazılır (hangi commit'in yüklendiğinin kanıtı).
 * Kullanım: php deploy/live/build-release.php [çıktı-dizini]
 */
$root = dirname(__DIR__, 2);
$outDir = $argv[1] ?? sys_get_temp_dir();
$commit = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git rev-parse HEAD 2>/dev/null'));
$dirty = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git status --porcelain 2>/dev/null'));

if ($commit === '') {
	fwrite(STDERR, "Git deposu bulunamadı.\n");
	exit(2);
}

if ($dirty !== '') {
	fwrite(STDERR, "UYARI: çalışma ağacında commit edilmemiş değişiklik var; paket yalnızca COMMIT'teki hâli içerir.\n");
}

$name = 'rafimda-release-' . substr($commit, 0, 10) . '.zip';
$zip = rtrim($outDir, '/') . '/' . $name;
$cmd = 'cd ' . escapeshellarg($root) . ' && git archive --format=zip -o ' . escapeshellarg($zip) . ' HEAD -- . '
	. escapeshellarg(':(exclude)docs') . ' ' . escapeshellarg(':(exclude,glob)modules/*/tests/**') . ' ' . escapeshellarg(':(exclude,glob)deploy/*/tests/**') . ' 2>&1';
exec($cmd, $o, $rc);

if ($rc !== 0 || !is_file($zip)) {
	fwrite(STDERR, "Paket oluşturulamadı: " . implode("\n", $o) . "\n");
	exit(3);
}

echo "Paket: $zip\nCommit: $commit\nSHA-256: " . hash_file('sha256', $zip) . "\nBoyut: " . number_format(filesize($zip) / 1048576, 2) . " MB\n";
