<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Kurulum doğrulayıcı: şema (tablo/sütun/indeks), migration durumu, FriSay çekirdeği bileşenleri, PHP ortamı.
 * Şema okuma kaynağı soyutlanmıştır; MySQL için StagingSchemaReader::mysql() kullanılır.
 */
final class StagingSchemaReader
{
	/** @var callable */
	private $reader;

	/** @param callable(string):?array{columns:list<string>,indexes:list<string>,auto?:list<string>} $reader */
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
			$a = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA LIKE '%auto_increment%'");
			$a->execute([$table]);

			return ['columns' => $cols, 'indexes' => array_map('strval', $i->fetchAll(PDO::FETCH_COLUMN)), 'auto' => array_map('strval', $a->fetchAll(PDO::FETCH_COLUMN))];
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

	/** Dış araçların (canlı ön kontrol) ek kontrol eklemesi için. */
	public function note(string $group, string $name, string $status, string $detail = ''): void
	{
		$this->add($group, $name, $status, $detail);
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

	/** env.php izinleri: grup/diğer okuyamamalı (parola içerir). */
	public function checkSecretFilePerms(string $file, ?int $perms): void
	{
		if ($perms === null) {
			$this->add('Gizli bilgiler', 'config/env.php izinleri', 'WARN', 'dosya yok');

			return;
		}

		$this->add('Gizli bilgiler', 'config/env.php izinleri (0600/0640)', ($perms & 0007) === 0 ? 'OK' : 'FAIL', sprintf('%04o', $perms) . ' — "diğer" kullanıcılar okuyabiliyor' . (($perms & 0007) === 0 ? ' DEĞİL' : ''));
	}

	/**
	 * Staging'de olmaması gereken dış servis ayarları (e-posta/SMS/ödeme/anahtar). Yalnızca ANAHTAR ADLARI listelenir, değerler asla.
	 *
	 * @param list<string> $nonEmptyTitles settings tablosunda dolu olan ve desene uyan başlıklar
	 */
	public function checkExternalServices(array $nonEmptyTitles, string $mailDriver): void
	{
		$own = ['XML_HUB_SECRET', 'MARKETPLACE_HUB_SECRET', 'XML_HUB_CRON_KEY', 'MARKETPLACE_HUB_CRON_KEY'];
		$risky = array_values(array_diff($nonEmptyTitles, $own));
		$this->add('Dış servisler', 'e-posta sürücüsü', $mailDriver === 'smtp' ? 'WARN' : 'OK', $mailDriver === 'smtp' ? 'SMTP yapılandırılmış: staging gerçek müşterilere e-posta gönderebilir' : 'php (sunucuda sendmail_path=/bin/true ile boşa çıkarın)');
		$this->add('Dış servisler', 'dolu SMTP/SMS/ödeme/API anahtarı ayarı', $risky ? 'WARN' : 'OK', $risky ? implode(', ', $risky) : 'yok');
	}

	public function checkRobots(): void
	{
		$f = $this->root . '/deploy/staging/conf/robots.staging.txt';
		$this->add('Staging', 'robots.staging.txt hazır', is_file($f) && strpos((string) file_get_contents($f), 'Disallow: /') !== false ? 'OK' : 'FAIL');
	}

	public function checkSchema(StagingSchemaReader $reader, ?callable $appliedVersions = null, bool $requireVariations = false): void
	{
		foreach (self::CORE_TABLES as $t) {
			$this->add('Çekirdek tablolar', $t, $reader->table($t) !== null ? 'OK' : 'FAIL');
		}

		$pv = $reader->table('product_variations');
		$this->add('Çekirdek tablolar', 'product_variations (çekirdek tembel tablo)', $pv !== null ? 'OK' : ($requireVariations ? 'FAIL' : 'WARN'), $pv !== null ? '' : 'Çekirdek ProductVariation::ensureSchema() ile ilk kullanımda oluşturur. Şimdi oluşturmak için: staging-verify.php --init-lazy (veya staging-seed.php).');

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
				$missingAuto = isset($actual['auto']) ? array_values(array_diff($def['auto'] ?? [], $actual['auto'])) : [];
				$bad = $missingCols || $missingIdx || $missingAuto;
				$this->add($m['key'], 'tablo ' . $table, $bad ? 'FAIL' : 'OK', $bad
					? 'eksik sütun: ' . ($missingCols ? implode(',', $missingCols) : '-') . ' | eksik indeks: ' . ($missingIdx ? implode(',', $missingIdx) : '-') . ' | AUTO_INCREMENT eksik: ' . ($missingAuto ? implode(',', $missingAuto) : '-')
					: count($def['columns']) . ' sütun, ' . count($def['indexes']) . ' indeks' . (isset($actual['auto']) && ($def['auto'] ?? []) ? ', AUTO_INCREMENT: ' . implode(',', $def['auto']) : ''));
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
