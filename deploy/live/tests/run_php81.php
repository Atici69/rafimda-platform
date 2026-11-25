<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * PHP 8.1 UYUMLULUK TARAYICISI (statik). Bu makinede PHP 8.1 çalıştırıcısı yoksa gerçek 8.1 testi yapılamaz; bu tarayıcı 8.2/8.3'e özgü
 * sözdizimi ve işlevleri token düzeyinde arar. Kanıt değil, güçlü bir ön elemedir; gerçek doğrulama hostingte `php -v` + `php -l` ile yapılmalıdır.
 */
$root = dirname(__DIR__, 3);
$targets = [$root . '/deploy', $root . '/modules/rafimda-theme', $root . '/modules/rafimda-vendor', $root . '/modules/rafimda-dealer', $root . '/modules/rafimda-showcase', $root . '/modules/rafimda-catalog', $root . '/modules/xml-hub', $root . '/modules/marketplace-hub'];
$funcs = ['json_validate', 'mb_str_pad', 'mb_trim', 'mb_ltrim', 'mb_rtrim', 'array_find', 'array_find_key', 'array_any', 'array_all', 'str_increment', 'str_decrement', 'ini_parse_quantity', 'mysqli_execute_query', 'curl_upkeep', 'memory_reset_peak_usage', 'openssl_cipher_key_length', 'iterator_apply_', 'mb_strrev_'];
$bad = [];
$files = 0;

foreach ($targets as $dir) {
	if (!is_dir($dir)) {
		continue;
	}

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
		if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php' || $f->getFilename() === 'run_php81.php') {
			continue;
		}

		$files++;
		$src = (string) file_get_contents($f->getPathname());
		$toks = token_get_all($src);
		$rel = substr($f->getPathname(), strlen($root) + 1);
		$prev = '';
		$code = '';

		foreach ($toks as $i => $t) {
			if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
				$code .= ' ';
				continue;
			}

			$code .= is_array($t) ? $t[1] : $t;
		}

		$checks = [
			'readonly class (8.2)' => '/\b(final\s+|abstract\s+)?readonly\s+class\b/i',
			'standalone null/false/true tipi (8.2)' => '/\b(function|fn)\b(?:(?!=>)[^;{}])*\)\s*:\s*\??(null|false|true)\s*(\{|;|=>)/i',
			'tipli sınıf sabiti (8.3)' => '/\bconst\s+(string|int|float|bool|array|iterable|mixed|self)\s+[A-Z_a-z0-9]+\s*=/',
			'#[\\Override] (8.3)' => '/#\[\s*\\\\?Override\b/',
			'#[\\SensitiveParameter] (8.2)' => '/#\[\s*\\\\?SensitiveParameter\b/',
			'trait içinde const (8.2)' => '/\btrait\s+\w+[^{]*\{[^}]*\bconst\b/s',
			'dinamik sınıf sabiti ::{$x} (8.3)' => '/::\s*\{\s*\$/',
			'DNF tipi (A&B)|null (8.2)' => '/\(\s*\w+\s*&\s*\w+\s*\)\s*\|/',
			'Random\\ ad alanı (8.2)' => '/\bRandom\\\\(Randomizer|Engine)/',
		];

		foreach ($checks as $label => $re) {
			if (preg_match($re, $code)) {
				$bad[] = "$rel: $label";
			}
		}

		foreach ($funcs as $fn) {
			if (preg_match('/(?<![\w>:$])' . preg_quote($fn, '/') . '\s*\(/', $code) && !preg_match('/function\s+' . preg_quote($fn, '/') . '\b/', $code)) {
				$bad[] = "$rel: $fn() (8.2+)";
			}
		}
	}
}

echo ($bad ? "  FAIL" : "  ok  ") . " PHP 8.1 statik tarama: $files dosya, " . count($bad) . " bulgu\n";

foreach ($bad as $b) {
	echo "    - $b\n";
}

echo "\n==== SONUÇ: " . ($bad ? '0 geçti, 1 başarısız' : '1 geçti, 0 başarısız') . " ====\n";
exit($bad ? 1 : 0);
