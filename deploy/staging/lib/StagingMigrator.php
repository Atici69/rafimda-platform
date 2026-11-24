<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Sıralı, durdurucu migration çalıştırıcı (PDO ile doğrudan; FriSay çekirdeği gerekmez).
 * Modüllerin kendi çalıştırıcılarıyla AYNI izleme tablolarını kullanır (rafimda_migrations, xh_migrations, mh_migrations),
 * bu yüzden daha sonra yönetim panelinden modül kurulumu yapılırsa tekrar çalıştırmaz.
 * Farkı: hata yutmaz, ilk hatada durur ve başarısız migration'ı "uygulandı" diye işaretlemez.
 * Yıkıcı ifade (DROP, TRUNCATE, DELETE, RENAME) içeren migration çalıştırılmaz.
 * İşlem modeli: MySQL'de DDL örtük commit yaptığından migration dosyaları transaction'a SARILMAZ. Bir dosya ortasında hata verirse
 * önceki ifadeler kalır; bu yüzden migration dosyaları yeniden çalıştırılabilir olmalıdır (CREATE TABLE IF NOT EXISTS, korumalı ADD COLUMN, INSERT/UPDATE yok).
 */
final class StagingMigrator
{
	/** @var callable(string,string):bool tablo, sütun -> var mı */
	private $columnExists;

	/** @var PDO */
	private $pdo;

	public function __construct(PDO $pdo, ?callable $columnExists = null)
	{
		$this->pdo = $pdo;
		$this->columnExists = $columnExists ?? function (string $table, string $column): bool {
			$st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
			$st->execute([$table, $column]);

			return (int) $st->fetchColumn() > 0;
		};
	}

	public static function isDestructive(string $statement): bool
	{
		return (bool) preg_match('/^\s*(DROP|TRUNCATE|DELETE|RENAME)\b/i', $statement) || (bool) preg_match('/\bDROP\s+(COLUMN|TABLE|INDEX|KEY|PRIMARY)\b/i', $statement);
	}

	/** @return list<string> uygulanmış sürümler (izleme tablosu yoksa boş) */
	public function applied(?string $tracker): array
	{
		if ($tracker === null) {
			return [];
		}

		try {
			return array_map('strval', $this->pdo->query('SELECT version FROM `' . $tracker . '`')->fetchAll(PDO::FETCH_COLUMN));
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Tek modülü kurar: install.sql (CREATE IF NOT EXISTS) + bekleyen migration'lar.
	 *
	 * @param array{key:string,tracker:?string,install:string,migrations:string,dir:string} $module
	 * @return array{module:string,success:bool,steps:list<array{step:string,status:string,detail:string}>}
	 */
	public function runModule(array $module, bool $dryRun = false): array
	{
		$steps = [];
		$fail = static function (array $steps, string $step, string $detail) use ($module): array {
			$steps[] = ['step' => $step, 'status' => 'FAIL', 'detail' => $detail];

			return ['module' => $module['key'], 'success' => false, 'steps' => $steps];
		};

		if (!is_file($module['install'])) {
			return $fail($steps, 'install.sql', 'Dosya bulunamadı: ' . $module['install']);
		}

		$r = $this->applyFile($module['install'], $dryRun);
		$steps[] = ['step' => 'install.sql', 'status' => $r['ok'] ? ($dryRun ? 'DRY' : 'OK') : 'FAIL', 'detail' => $r['detail']];

		if (!$r['ok']) {
			return ['module' => $module['key'], 'success' => false, 'steps' => $steps];
		}

		$versions = StagingModules::migrationVersions($module);
		$done = $this->applied($module['tracker']);

		foreach ($versions as $v) {
			if (in_array($v, $done, true)) {
				$steps[] = ['step' => $v, 'status' => 'SKIP', 'detail' => 'zaten uygulanmış'];
				continue;
			}

			$r = $this->applyFile($module['migrations'] . '/' . $v . '.sql', $dryRun);

			if (!$r['ok']) {
				return $fail($steps, $v, $r['detail']);
			}

			if (!$dryRun && $module['tracker'] !== null) {
				try {
					$this->pdo->prepare('INSERT INTO `' . $module['tracker'] . '` (version) VALUES (?)')->execute([$v]);
				} catch (PDOException $e) {
					return $fail($steps, $v, 'Sürüm kaydı yazılamadı: ' . $e->getMessage());
				}
			}

			$steps[] = ['step' => $v, 'status' => $dryRun ? 'DRY' : 'OK', 'detail' => $r['detail']];
		}

		return ['module' => $module['key'], 'success' => true, 'steps' => $steps];
	}

	/** @return array{ok:bool,detail:string} */
	private function applyFile(string $file, bool $dryRun): array
	{
		try {
			$statements = StagingSql::statements((string) file_get_contents($file));
		} catch (RuntimeException $e) {
			return ['ok' => false, 'detail' => 'SQL ayrıştırılamadı (' . basename($file) . '): ' . $e->getMessage()];
		}

		$ran = 0;
		$skipped = 0;

		foreach ($statements as $st) {
			if (self::isDestructive($st)) {
				return ['ok' => false, 'detail' => 'Yıkıcı ifade reddedildi: ' . substr($st, 0, 80)];
			}

			$alter = StagingSql::alterAddColumn($st);

			if ($alter !== null && ($this->columnExists)($alter['table'], $alter['column'])) {
				$skipped++;
				continue;
			}

			if ($dryRun) {
				$ran++;
				continue;
			}

			try {
				$this->pdo->exec($st);
				$ran++;
			} catch (PDOException $e) {
				return ['ok' => false, 'detail' => 'SQL hatası: ' . $e->getMessage() . ' | ifade: ' . substr(preg_replace('/\s+/', ' ', $st), 0, 120)];
			}
		}

		return ['ok' => true, 'detail' => count($statements) . ' ifade, ' . $ran . ($dryRun ? ' çalıştırılacak' : ' çalıştı') . ($skipped ? ', ' . $skipped . ' sütun zaten vardı' : '')];
	}
}
