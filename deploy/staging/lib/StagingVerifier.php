<?php

/**
 * Kurulum doğrulayıcı: şema (tablo/sütun/indeks), migration durumu, FriSay çekirdeği bileşenleri, PHP ortamı.
 * Şema okuma kaynağı soyutlanmıştır; MySQL için StagingSchemaReader::mysql() kullanılır.
 */
final class StagingSchemaReader
{
	/** @var callable */
	private $reader;

	/** @param callable(string):?array{columns:list<string>,indexes:list<string>} $reader */
	public function __construct(callable $reader)
	{
		$this->reader = $reader;
	}

	public static function mysql(PDO $pdo): self
	{
		return new self(static function (string $table) use ($pdo): ?array {
			$c = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
			$c->execute([$table]);
			$cols = array_map('strval', $c->fetchAll(PDO::FETCH_COLUMN));

			if ($cols === []) {
				return null;
			}

			$i = $pdo->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
			$i->execute([$table]);

			return ['columns' => $cols, 'indexes' => array_map('strval', $i->fetchAll(PDO::FETCH_COLUMN))];
		});
	}

	/** @return array{columns:list<string>,indexes:list<string>}|null */
	public function table(string $name): ?array
	{
		return ($this->reader)($name);
	}
}

final class StagingVerifier
{
	public const CORE_TABLES = ['settings', 'categories', 'brands', 'products', 'images', 'users', 'orders', 'order_detail', 'modules', 'module_display_hooks', 'admins'];
	public const CORE_FILES = ['core/autoload.php', 'config/app.php', 'config/connection.php', 'config/database.php', 'config/env.example.php', 'index.php', 'core/ModuleBase.php'];
	public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'json', 'curl', 'sodium', 'xmlreader', 'xmlwriter', 'dom', 'gd'];

	/** @var list<array{group:string,name:string,status:string,detail:string}> */
	private array $checks = [];

	/** @var string */
	private $root;

	public function __construct(string $root)
	{
		$this->root = $root;
	}

	private function add(string $group, string $name, string $status, string $detail = ''): void
	{
		$this->checks[] = ['group' => $group, 'name' => $name, 'status' => $status, 'detail' => $detail];
	}

	/** @return list<array{group:string,name:string,status:string,detail:string}> */
	public function checks(): array
	{
		return $this->checks;
	}

	public function failures(): int
	{
		return count(array_filter($this->checks, static fn(array $c): bool => $c['status'] === 'FAIL'));
	}

	public function warnings(): int
	{
		return count(array_filter($this->checks, static fn(array $c): bool => $c['status'] === 'WARN'));
	}

	public function checkPhp(?string $version = null, ?callable $hasExtension = null): void
	{
		$version = $version ?? PHP_VERSION;
		$hasExtension = $hasExtension ?? 'extension_loaded';
		$this->add('PHP', 'sürüm >= 7.4', version_compare($version, '7.4.0', '>=') ? 'OK' : 'FAIL', $version);

		foreach (self::EXTENSIONS as $ext) {
			$required = in_array($ext, ['pdo_mysql', 'mbstring', 'json', 'sodium', 'curl'], true);
			$this->add('PHP', 'eklenti ' . $ext, $hasExtension($ext) ? 'OK' : ($required ? 'FAIL' : 'WARN'), $required ? 'zorunlu' : 'önerilir');
		}
	}

	public function checkCoreFiles(): void
	{
		foreach (self::CORE_FILES as $f) {
			$this->add('Çekirdek', 'dosya ' . $f, is_file($this->root . '/' . $f) ? 'OK' : 'FAIL');
		}

		foreach (StagingModules::all($this->root) as $m) {
			$this->add('Çekirdek', 'modül dizini ' . $m['key'], is_dir($m['dir']) ? 'OK' : 'FAIL');
		}

		foreach (['cache', 'logs', 'tmp'] as $d) {
			$p = $this->root . '/' . $d;
			$this->add('Çekirdek', 'yazılabilir ' . $d . '/', is_dir($p) && is_writable($p) ? 'OK' : 'FAIL', $p);
		}
	}

	/** @param array<string,mixed>|null $env config/env.php içeriği */
	public function checkEnvConfig(?array $env, ?string $expectedDb = null): void
	{
		if ($env === null) {
			$this->add('Yapılandırma', 'config/env.php', 'FAIL', 'Dosya yok veya dizi döndürmüyor');

			return;
		}

		$this->add('Yapılandırma', 'APP_ENV = production (canlı davranışı)', ($env['APP_ENV'] ?? '') === 'production' ? 'OK' : 'WARN', 'FriSay yalnızca "production" iken hataları gizler ve Secure çerez/HSTS uygular; staging canlıya benzemeli. Şu an: ' . ($env['APP_ENV'] ?? '(yok)'));
		$this->add('Yapılandırma', 'APP_DEBUG kapalı', empty($env['APP_DEBUG']) ? 'OK' : 'WARN', 'Hata ayrıntısı sızdırmamak için kapalı olmalı');

		if ($expectedDb !== null) {
			$this->add('Yapılandırma', 'env.php DB_NAME = ortam DB_NAME', ($env['DB_NAME'] ?? '') === $expectedDb ? 'OK' : 'FAIL', 'env.php: ' . ($env['DB_NAME'] ?? '(yok)') . ' / beklenen: ' . $expectedDb);
		}
	}

	public function checkRobots(): void
	{
		$f = $this->root . '/deploy/staging/conf/robots.staging.txt';
		$this->add('Staging', 'robots.staging.txt hazır', is_file($f) && strpos((string) file_get_contents($f), 'Disallow: /') !== false ? 'OK' : 'FAIL');
	}

	public function checkSchema(StagingSchemaReader $reader, ?callable $appliedVersions = null): void
	{
		foreach (self::CORE_TABLES as $t) {
			$this->add('Çekirdek tablolar', $t, $reader->table($t) !== null ? 'OK' : 'FAIL');
		}

		$pv = $reader->table('product_variations');
		$this->add('Çekirdek tablolar', 'product_variations (isteğe bağlı)', $pv !== null ? 'OK' : 'WARN', $pv !== null ? '' : 'Çekirdek (ProductVariation::ensureSchema) ilk kullanımda oluşturur; varyant testinden önce bir kez oluşmasını sağlayın.');

		foreach (StagingModules::all($this->root) as $m) {
			$expected = StagingModules::expectedSchema($m);

			foreach ($expected as $table => $def) {
				$actual = $reader->table($table);

				if ($actual === null) {
					$this->add($m['key'], 'tablo ' . $table, 'FAIL', 'yok');
					continue;
				}

				$missingCols = array_values(array_diff($def['columns'], $actual['columns']));
				$missingIdx = array_values(array_diff($def['indexes'], $actual['indexes']));
				$this->add($m['key'], 'tablo ' . $table, $missingCols || $missingIdx ? 'FAIL' : 'OK', $missingCols || $missingIdx
					? 'eksik sütun: ' . ($missingCols ? implode(',', $missingCols) : '-') . ' | eksik indeks: ' . ($missingIdx ? implode(',', $missingIdx) : '-')
					: count($def['columns']) . ' sütun, ' . count($def['indexes']) . ' indeks');
			}

			if ($appliedVersions !== null && $m['tracker'] !== null) {
				$applied = $appliedVersions($m['tracker']);
				$all = StagingModules::migrationVersions($m);
				$pending = array_values(array_diff($all, $applied));
				$orphans = array_values(array_diff($applied, $all));
				$this->add($m['key'], 'bekleyen migration', $pending ? 'FAIL' : 'OK', $pending ? implode(', ', $pending) : 'yok (' . count($all) . ' uygulanmış)');

				if ($orphans) {
					$this->add($m['key'], 'dosyası olmayan kayıt', 'WARN', implode(', ', $orphans));
				}
			}
		}
	}

	public function toText(): string
	{
		$out = [];
		$group = '';

		foreach ($this->checks as $c) {
			if ($c['group'] !== $group) {
				$group = $c['group'];
				$out[] = "\n== " . $group;
			}

			$out[] = sprintf('  [%-4s] %s%s', $c['status'], $c['name'], $c['detail'] !== '' ? ' — ' . $c['detail'] : '');
		}

		$out[] = "\nÖZET: " . count($this->checks) . ' kontrol, ' . $this->failures() . ' başarısız, ' . $this->warnings() . ' uyarı.';

		return implode("\n", $out) . "\n";
	}

	public function toMarkdown(): string
	{
		$out = ['# Rafımda Staging Kurulum Doğrulama Raporu', '', 'Tarih: ' . date('Y-m-d H:i:s'), ''];
		$group = '';

		foreach ($this->checks as $c) {
			if ($c['group'] !== $group) {
				$group = $c['group'];
				$out[] = '';
				$out[] = '## ' . $group;
				$out[] = '| Durum | Kontrol | Ayrıntı |';
				$out[] = '|---|---|---|';
			}

			$out[] = '| ' . $c['status'] . ' | ' . str_replace('|', '/', $c['name']) . ' | ' . str_replace('|', '/', $c['detail']) . ' |';
		}

		$out[] = '';
		$out[] = '**Özet:** ' . count($this->checks) . ' kontrol, ' . $this->failures() . ' başarısız, ' . $this->warnings() . ' uyarı.';

		return implode("\n", $out) . "\n";
	}
}
