<?php

/**
 * Depo katmanı. Satıcı kapsamlı her okuma/yazma `$vendor` (null = yönetici/tümü) ile sınırlandırılır;
 * başka satıcının bağlantısı, çalıştırması, hatası burada ELENİR (IDOR).
 */
class XhRepo
{
	public const DEFAULT_OPTIONS = [
		'create_new' => 1, 'update_existing' => 1, 'sync_price' => 1, 'sync_stock' => 1, 'sync_description' => 0, 'sync_images' => 0,
		'price_markup_percent' => 0, 'price_add' => 0, 'default_category' => 0, 'default_brand' => 0, 'max_images' => 5,
		'image_mode' => 'if_empty', 'sync_cargo' => 1, 'create_variants' => 1,
	];

	public static function now(): string
	{
		return date('Y-m-d H:i:s');
	}

	// ---- tedarikçiler ----

	/** @return list<array<string,mixed>> */
	public static function suppliers(?int $vendor): array
	{
		return DB::execute('SELECT * FROM xh_suppliers' . ($vendor === null ? '' : ' WHERE id_vendor = ?') . ' ORDER BY name ASC', $vendor === null ? [] : [$vendor]) ?: [];
	}

	public static function supplier(int $id, ?int $vendor): ?array
	{
		$r = DB::execute('SELECT * FROM xh_suppliers WHERE id_supplier = ?' . ($vendor === null ? '' : ' AND id_vendor = ?'), $vendor === null ? [$id] : [$id, $vendor]);

		return $r && isset($r[0]) ? $r[0] : null;
	}

	/** @return array{success:bool,message:string,id:int} */
	public static function saveSupplier(int $vendor, int $id, string $name, string $website, string $notes): array
	{
		$name = mb_substr(trim(strip_tags($name)), 0, 120);
		$website = mb_substr(trim(strip_tags($website)), 0, 190);
		$notes = mb_substr(trim(strip_tags($notes)), 0, 500);

		if (mb_strlen($name) < 2) {
			return ['success' => false, 'message' => 'Tedarikçi adı en az 2 karakter olmalı.', 'id' => 0];
		}

		if ($id > 0) {
			if (!self::supplier($id, $vendor)) {
				return ['success' => false, 'message' => 'Tedarikçi bulunamadı.', 'id' => 0];
			}

			DB::update('xh_suppliers', ['name' => $name, 'website' => $website, 'notes' => $notes], 'id_supplier = :i AND id_vendor = :v', ['i' => $id, 'v' => $vendor]);

			return ['success' => true, 'message' => 'Tedarikçi güncellendi.', 'id' => $id];
		}

		$new = DB::insert('xh_suppliers', ['id_vendor' => $vendor, 'name' => $name, 'website' => $website, 'notes' => $notes]);

		return $new ? ['success' => true, 'message' => 'Tedarikçi eklendi.', 'id' => (int) $new] : ['success' => false, 'message' => 'Kaydedilemedi.', 'id' => 0];
	}

	// ---- bağlantılar ----

	/** @return list<array<string,mixed>> */
	public static function connections(?int $vendor): array
	{
		return DB::execute(
			'SELECT c.*, (SELECT name FROM xh_suppliers s WHERE s.id_supplier = c.id_supplier) AS supplier_name FROM xh_connections c'
			. ($vendor === null ? '' : ' WHERE c.id_vendor = ?') . ' ORDER BY c.id_connection DESC',
			$vendor === null ? [] : [$vendor]
		) ?: [];
	}

	public static function conn(int $id, ?int $vendor): ?array
	{
		if ($id <= 0) {
			return null;
		}

		$r = DB::execute('SELECT * FROM xh_connections WHERE id_connection = ?' . ($vendor === null ? '' : ' AND id_vendor = ?'), $vendor === null ? [$id] : [$id, $vendor]);

		return $r && isset($r[0]) ? $r[0] : null;
	}

	/** @return array<string,mixed> */
	public static function options(array $conn): array
	{
		$o = json_decode((string) ($conn['options'] ?? ''), true);

		return array_merge(self::DEFAULT_OPTIONS, is_array($o) ? $o : []);
	}

	/** @return array<string,mixed> */
	public static function mapping(array $conn): array
	{
		$m = json_decode((string) ($conn['mapping'] ?? ''), true);

		return is_array($m) ? $m : [];
	}

	/** @param array<string,mixed> $in @return array<string,mixed> */
	public static function sanitizeOptions(array $in): array
	{
		$o = [];

		foreach (['create_new', 'update_existing', 'sync_price', 'sync_stock', 'sync_description', 'sync_images', 'sync_cargo', 'create_variants'] as $b) {
			$o[$b] = array_key_exists($b, $in) ? (!empty($in[$b]) ? 1 : 0) : (int) self::DEFAULT_OPTIONS[$b]; // formlar işaretsiz kutu için gizli "0" gönderir
		}

		$o['price_markup_percent'] = max(-90.0, min(500.0, (float) str_replace(',', '.', (string) ($in['price_markup_percent'] ?? 0))));
		$o['price_add'] = max(-100000.0, min(100000.0, (float) str_replace(',', '.', (string) ($in['price_add'] ?? 0))));
		$o['default_category'] = max(0, (int) ($in['default_category'] ?? 0));
		$o['default_brand'] = max(0, (int) ($in['default_brand'] ?? 0));
		$o['max_images'] = max(0, min(10, (int) ($in['max_images'] ?? 5)));
		$o['image_mode'] = in_array($in['image_mode'] ?? '', ['if_empty', 'add_new'], true) ? $in['image_mode'] : 'if_empty';

		return $o;
	}

	public static function sanitizeInterval($v): int
	{
		$allowed = [0, 60, 180, 360, 720, 1440];
		$v = (int) $v;

		return in_array($v, $allowed, true) ? $v : 0;
	}

	/**
	 * @param array<string,mixed> $d name, source_type, url|file_path, id_supplier, interval_minutes
	 * @return array{success:bool,message:string,id:int}
	 */
	public static function createConnection(int $vendor, array $d): array
	{
		$name = mb_substr(trim(strip_tags((string) ($d['name'] ?? ''))), 0, 120);
		$type = ($d['source_type'] ?? '') === 'file' ? 'file' : 'url';
		$idSupplier = (int) ($d['id_supplier'] ?? 0);

		if (mb_strlen($name) < 2) {
			return ['success' => false, 'message' => 'Bağlantı adı gerekli.', 'id' => 0];
		}

		if ($idSupplier > 0 && !self::supplier($idSupplier, $vendor)) {
			return ['success' => false, 'message' => 'Tedarikçi bulunamadı.', 'id' => 0];
		}

		$url = '';
		$file = '';

		if ($type === 'url') {
			$g = XhUrlGuard::validate((string) ($d['url'] ?? ''));

			if (!$g['ok']) {
				return ['success' => false, 'message' => $g['error'], 'id' => 0];
			}

			$url = $g['url'];
		} else {
			$file = (string) ($d['file_path'] ?? '');

			if ($file === '' || !XhStorage::isInside($file)) {
				return ['success' => false, 'message' => 'Dosya bulunamadı.', 'id' => 0];
			}
		}

		$id = DB::insert('xh_connections', [
			'id_vendor' => $vendor, 'id_supplier' => $idSupplier, 'name' => $name, 'source_type' => $type, 'url' => $url,
			'file_path' => $file, 'options' => json_encode(self::sanitizeOptions($d['options'] ?? [])), 'mapping' => '{}',
			'interval_minutes' => self::sanitizeInterval($d['interval_minutes'] ?? 0), 'status' => 'draft',
		]);

		return $id ? ['success' => true, 'message' => 'Bağlantı oluşturuldu.', 'id' => (int) $id] : ['success' => false, 'message' => 'Kaydedilemedi.', 'id' => 0];
	}

	public static function setConn(int $id, array $fields): void
	{
		$fields['date_upd'] = self::now();
		DB::update('xh_connections', $fields, 'id_connection = :cid', ['cid' => $id]);
	}

	/** Tedarikçi marka/kategori adını sitedeki marka/kategoriye eşler (0 = eşlemeyi kaldır). Hedef ID doğrulanır. @return array{success:bool,message:string} */
	public static function setMapEntry(array $conn, string $kind, string $text, int $target): array
	{
		$text = mb_substr(trim($text), 0, 255);

		if (!in_array($kind, ['brand', 'category'], true) || $text === '') {
			return ['success' => false, 'message' => 'Geçersiz eşleştirme.'];
		}

		if ($target > 0) {
			$ok = $kind === 'brand'
				? DB::execute('SELECT 1 AS x FROM brands WHERE id_brand = ? AND active = 1', [$target])
				: DB::execute('SELECT 1 AS x FROM categories WHERE id_category = ? AND active = 1', [$target]);

			if (!$ok) {
				return ['success' => false, 'message' => 'Hedef ' . ($kind === 'brand' ? 'marka' : 'kategori') . ' bulunamadı.'];
			}
		}

		$m = self::mapping($conn) + ['fields' => []];
		$map = is_array($m[$kind . '_map'] ?? null) ? $m[$kind . '_map'] : [];

		if ($target > 0) {
			$map[$text] = $target;
		} else {
			unset($map[$text]);
		}

		$m[$kind . '_map'] = array_slice($map, 0, 2000, true);
		self::setConn((int) $conn['id_connection'], ['mapping' => json_encode(XhMapper::sanitizeMapping($m))]);

		return ['success' => true, 'message' => 'Eşleştirme kaydedildi.'];
	}

	/** Kimlik bilgisi kaydeder (şifreli). Boş kullanıcı adı = kaldır. @return array{success:bool,message:string} */
	public static function saveAuth(int $idConn, string $user, string $pass): array
	{
		if ($user === '') {
			DB::execute('DELETE FROM xh_credentials WHERE id_connection = ?', [$idConn]);

			return ['success' => true, 'message' => 'Kimlik bilgisi kaldırıldı.'];
		}

		if (!XhCrypt::available()) {
			return ['success' => false, 'message' => 'Sunucuda libsodium yok; kimlik bilgisi güvenli saklanamaz.'];
		}

		$user = mb_substr(trim($user), 0, 120);
		$pass = mb_substr($pass, 0, 200);

		if (preg_match('/[\x00-\x1f]/', $user . $pass) || strpos($user, ':') !== false) {
			return ['success' => false, 'message' => 'Kullanıcı adı ":" veya denetim karakteri içeremez.'];
		}

		$row = ['auth_type' => 'basic', 'username_enc' => XhCrypt::encrypt($user), 'password_enc' => XhCrypt::encrypt($pass), 'date_upd' => self::now()];

		if (DB::getRowSafe('xh_credentials', 'id_connection = ?', [$idConn])) {
			DB::update('xh_credentials', $row, 'id_connection = :cid', ['cid' => $idConn]);
		} else {
			DB::insert('xh_credentials', $row + ['id_connection' => $idConn]);
		}

		return ['success' => true, 'message' => 'Kimlik bilgisi şifreli olarak kaydedildi.'];
	}

	public static function hasAuth(int $idConn): bool
	{
		return (bool) DB::getRowSafe('xh_credentials', 'id_connection = ?', [$idConn]);
	}

	/** Yalnızca indirme sırasında çözülür; arayüze/günlüğe asla verilmez. @return array{user:string,pass:string}|null */
	public static function auth(int $idConn): ?array
	{
		$r = DB::getRowSafe('xh_credentials', 'id_connection = ?', [$idConn]);

		if (!$r) {
			return null;
		}

		$u = XhCrypt::decrypt((string) $r['username_enc']);

		return $u === '' ? null : ['user' => $u, 'pass' => XhCrypt::decrypt((string) $r['password_enc'])];
	}

	public static function deleteConnection(int $id, ?int $vendor): bool
	{
		$c = self::conn($id, $vendor);

		if (!$c) {
			return false;
		}

		if (DB::getRowSafe('xh_runs', "id_connection = ? AND status IN ('queued','running')", [$id])) {
			return false; // çalışan işlem varken silinmez
		}

		XhStorage::remove((string) $c['file_path']); // yüklenen dosya veya URL örneği

		// Yalnızca XML Hub kayıtları silinir; ürünlere dokunulmaz.
		foreach (['xh_errors', 'xh_items', 'xh_runs', 'xh_credentials', 'xh_changes'] as $t) {
			DB::execute('DELETE FROM ' . $t . ' WHERE id_connection = ?', [$id]);
		}

		DB::execute('DELETE FROM xh_connections WHERE id_connection = ?', [$id]);

		return true;
	}

	// ---- çalıştırmalar / hatalar / geçmiş ----

	/** @return list<array<string,mixed>> */
	public static function runs(?int $vendor, int $idConn = 0, int $limit = 30): array
	{
		$sql = 'SELECT r.*, c.name AS conn_name FROM xh_runs r INNER JOIN xh_connections c ON c.id_connection = r.id_connection WHERE 1=1';
		$p = [];

		if ($vendor !== null) {
			$sql .= ' AND c.id_vendor = ?';
			$p[] = $vendor;
		}

		if ($idConn > 0) {
			$sql .= ' AND r.id_connection = ?';
			$p[] = $idConn;
		}

		return DB::execute($sql . ' ORDER BY r.id_run DESC LIMIT ' . max(1, min(200, $limit)), $p) ?: [];
	}

	public static function run(int $id, ?int $vendor): ?array
	{
		$r = DB::execute(
			'SELECT r.* FROM xh_runs r INNER JOIN xh_connections c ON c.id_connection = r.id_connection WHERE r.id_run = ?' . ($vendor === null ? '' : ' AND c.id_vendor = ?'),
			$vendor === null ? [$id] : [$id, $vendor]
		);

		return $r && isset($r[0]) ? $r[0] : null;
	}

	/** @return list<array<string,mixed>> */
	public static function errors(?int $vendor, int $idConn = 0, int $limit = 100): array
	{
		$sql = 'SELECT e.*, c.name AS conn_name FROM xh_errors e INNER JOIN xh_connections c ON c.id_connection = e.id_connection WHERE 1=1';
		$p = [];

		if ($vendor !== null) {
			$sql .= ' AND c.id_vendor = ?';
			$p[] = $vendor;
		}

		if ($idConn > 0) {
			$sql .= ' AND e.id_connection = ?';
			$p[] = $idConn;
		}

		return DB::execute($sql . ' ORDER BY e.id_error DESC LIMIT ' . max(1, min(500, $limit)), $p) ?: [];
	}

	public static function logError(int $idConn, int $idRun, string $key, string $msg, string $level = 'error'): void
	{
		DB::insert('xh_errors', ['id_connection' => $idConn, 'id_run' => $idRun, 'item_key' => mb_substr($key, 0, 100), 'level' => $level, 'message' => mb_substr($msg, 0, 500)]);
	}

	/** Fiyat/stok geçmişi (ürün başına). Yalnızca ürünün sahibi çağırabilir: çağıran owns() kontrolünü yapmış olmalı. */
	public static function history(string $kind, int $idProduct, int $limit = 50): array
	{
		$t = $kind === 'stock' ? 'xh_stock_history' : 'xh_price_history';

		return DB::execute('SELECT * FROM ' . $t . ' WHERE id_product = ? ORDER BY id_history DESC LIMIT ' . max(1, min(200, $limit)), [$idProduct]) ?: [];
	}

	public static function addHistory(string $kind, int $idProduct, int $idConn, int $idRun, float $old, float $new): void
	{
		DB::insert($kind === 'stock' ? 'xh_stock_history' : 'xh_price_history', [
			'id_product' => $idProduct, 'id_connection' => $idConn, 'id_run' => $idRun, 'old_value' => $old, 'new_value' => $new,
		]);
	}

	// ---- ürün satırları (xh_items) ----

	public static function item(int $idConn, string $key): ?array
	{
		$r = DB::execute('SELECT * FROM xh_items WHERE id_connection = ? AND item_key = ?', [$idConn, $key]);

		return $r && isset($r[0]) ? $r[0] : null;
	}

	/** @param array<string,mixed> $f */
	public static function saveItem(int $idConn, string $key, array $f): void
	{
		$f['date_upd'] = self::now();

		if (self::item($idConn, $key)) {
			DB::update('xh_items', $f, 'id_connection = :cid AND item_key = :k', ['cid' => $idConn, 'k' => $key]);

			return;
		}

		DB::insert('xh_items', array_merge($f, ['id_connection' => $idConn, 'item_key' => $key]));
	}

	/** @return array<string,int> durum => adet */
	public static function itemCounts(int $idConn): array
	{
		$out = [];

		foreach (DB::execute('SELECT state, COUNT(*) AS c FROM xh_items WHERE id_connection = ? GROUP BY state', [$idConn]) ?: [] as $r) {
			$out[(string) $r['state']] = (int) $r['c'];
		}

		return $out;
	}
}
