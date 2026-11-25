<?php
// Pazaryeri Hub testleri: xml-hub'un SQLite/stub altyapısını yeniden kullanır (yalnızca test amaçlı).
error_reporting(E_ALL & ~E_DEPRECATED);
define('MH_TEST', 1);
require dirname(__DIR__, 2) . '/xml-hub/tests/bootstrap.php';
foreach (['MhMigrator', 'MhCrypt', 'MhOwner', 'MhAdapter', 'MhAbstractAdapter', 'MhCoreAdapter', 'MhRegistry', 'MhRepo', 'MhCatalog', 'MhAttributeFill', 'MhTrendyolBuilder', 'MhPayload', 'MhDispatcher', 'MhPanel'] as $c) {
	require_once dirname(__DIR__) . '/lib/' . $c . '.php';
}

/** Yalnızca testlerde: gerçek API OLMADAN kuyruğu/yeniden denemeyi sınamak için. Üretimde kayıtlı DEĞİLDİR. */
class MhTestAdapter extends MhAbstractAdapter
{
	public array $script = [];
	public array $calls = [];
	public function key(): string { return 'testmp'; }
	public function title(): string { return 'TestMP'; }
	public function implemented(): bool { return true; }
	public function credentialFields(): array { return ['token' => self::f('Token', true), 'shop' => self::f('Mağaza', false, false, 20)]; }
	public function testConnection(array $c): array { return ['success' => ($c['token'] ?? '') === 'good', 'message' => 'test']; }
	public function submit(array $c, array $p): array
	{
		$this->calls[] = ['creds' => $c, 'payload' => $p];
		$s = array_shift($this->script) ?? ['success' => true, 'remote_id' => 'R' . $p['id_product'], 'error' => '', 'retryable' => false];
		if (!empty($s['throw'])) { throw new RuntimeException('patladı'); }
		return $s + ['remote_id' => '', 'error' => '', 'retryable' => false];
	}
}

function mh_fresh_db(): void
{
	xh_fresh_db();
	$GLOBALS['db']->exec('CREATE TABLE mh_migrations (version TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
	foreach (MhMigrator::files() as $f) {
		$GLOBALS['db']->exec(xh_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/migrations/' . $f . '.sql')));
		DB::execute('INSERT INTO mh_migrations (version) VALUES (?)', [$f]);
	}
}
