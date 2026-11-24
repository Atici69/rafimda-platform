<?php

/** Pazaryeri hesapları, kategori eşleştirmeleri, gönderim kayıtları ve hata günlüğü. Her okuma/yazma sahip (id_vendor) kapsamlıdır. */
class MhRepo
{
	public static function now(): string
	{
		return date('Y-m-d H:i:s');
	}

	/** @return list<array<string,mixed>> kimlik bilgisi sütunu DÖNMEZ */
	public static function accounts(?int $vendor): array
	{
		return DB::execute('SELECT id_account, id_vendor, marketplace, label, status, last_test_at, last_test_ok, last_error, date_add, (credentials_enc IS NOT NULL AND credentials_enc <> \'\') AS has_credentials FROM mh_accounts' . ($vendor === null ? '' : ' WHERE id_vendor = ?') . ' ORDER BY id_account DESC', $vendor === null ? [] : [$vendor]) ?: [];
	}

	/** @return array<string,mixed>|null kimlik bilgisi sütunu dönmez */
	public static function account(int $id, ?int $vendor): ?array
	{
		$r = DB::execute('SELECT id_account, id_vendor, marketplace, label, status, last_test_at, last_test_ok, last_error, date_add, (credentials_enc IS NOT NULL AND credentials_enc <> \'\') AS has_credentials FROM mh_accounts WHERE id_account = ?' . ($vendor === null ? '' : ' AND id_vendor = ?'), $vendor === null ? [$id] : [$id, $vendor]);

		return $r && isset($r[0]) ? $r[0] : null;
	}

	/**
	 * @param array<string,string> $creds
	 * @return array{ok:bool, error:string, clean:array<string,string>}
	 */
	private static function cleanCreds(MhAdapter $a, array $creds, array $old = []): array
	{
		$clean = [];

		foreach ($a->credentialFields() as $name => $f) {
			$v = trim((string) ($creds[$name] ?? ''));

			if ($v === '' && isset($old[$name])) {
				$v = (string) $old[$name]; // boş bırakılan gizli alan eskisini korur
			}

			if ($v === '') {
				if ($f['required']) {
					return ['ok' => false, 'error' => '"' . $f['label'] . '" gerekli.', 'clean' => []];
				}

				continue;
			}

			if (preg_match('/[\x00-\x1f]/', $v) || mb_strlen($v) > $f['max']) {
				return ['ok' => false, 'error' => '"' . $f['label'] . '" geçersiz veya çok uzun.', 'clean' => []];
			}

			$clean[$name] = $v;
		}

		return ['ok' => true, 'error' => '', 'clean' => $clean];
	}

	/** @param array<string,string> $creds @return array{success:bool,message:string,id:int} */
	public static function createAccount(int $vendor, string $marketplace, string $label, array $creds): array
	{
		$a = MhRegistry::get($marketplace);
		$label = mb_substr(trim(strip_tags($label)), 0, 120);

		if (!$a || mb_strlen($label) < 2) {
			return ['success' => false, 'message' => !$a ? 'Bilinmeyen pazaryeri.' : 'Hesap adı gerekli.', 'id' => 0];
		}

		if (!MhCrypt::available()) {
			return ['success' => false, 'message' => 'Sunucuda libsodium yok; API anahtarları güvenli saklanamaz.', 'id' => 0];
		}

		if ($vendor > 0 && class_exists('RvPlans')) {
			$q = RvPlans::checkQuota($vendor, 'marketplaces');

			if (!$q['ok']) {
				return ['success' => false, 'message' => $q['message'], 'id' => 0];
			}
		}

		$c = self::cleanCreds($a, $creds);

		if (!$c['ok']) {
			return ['success' => false, 'message' => $c['error'], 'id' => 0];
		}

		$id = DB::insert('mh_accounts', ['id_vendor' => $vendor, 'marketplace' => $marketplace, 'label' => $label, 'status' => 'draft', 'credentials_enc' => MhCrypt::encrypt((string) json_encode($c['clean']))]);

		return $id ? ['success' => true, 'message' => 'Hesap eklendi.' . ($a->implemented() ? '' : ' Uyarı: ' . $a->title() . ' API entegrasyonu henüz yapılmadı; ürün gönderimi çalışmaz.'), 'id' => (int) $id] : ['success' => false, 'message' => 'Kaydedilemedi.', 'id' => 0];
	}

	/** @param array<string,string> $creds @return array{success:bool,message:string} */
	public static function updateCredentials(int $id, ?int $vendor, array $creds): array
	{
		$acc = self::account($id, $vendor);
		$a = $acc ? MhRegistry::get((string) $acc['marketplace']) : null;

		if (!$acc || !$a) {
			return ['success' => false, 'message' => 'Hesap bulunamadı.'];
		}

		$c = self::cleanCreds($a, $creds, self::credentials($id));

		if (!$c['ok']) {
			return ['success' => false, 'message' => $c['error']];
		}

		DB::update('mh_accounts', ['credentials_enc' => MhCrypt::encrypt((string) json_encode($c['clean'])), 'last_test_ok' => 0, 'date_upd' => self::now()], 'id_account = :i', ['i' => $id]);

		return ['success' => true, 'message' => 'Kimlik bilgileri şifreli olarak güncellendi.'];
	}

	/** Yalnızca gönderim/test anında çözülür. Arayüze ve günlüğe verilmez. @return array<string,string> */
	public static function credentials(int $id): array
	{
		$r = DB::execute('SELECT credentials_enc FROM mh_accounts WHERE id_account = ?', [$id]);
		$j = $r && isset($r[0]) ? MhCrypt::decrypt((string) $r[0]['credentials_enc']) : '';
		$a = json_decode($j, true);

		return is_array($a) ? array_map('strval', $a) : [];
	}

	/** Arayüz için: hangi alanlar dolu (değer YOK). @return array<string,bool> */
	public static function credentialStatus(int $id): array
	{
		$c = self::credentials($id);
		$a = ($acc = self::account($id, null)) ? MhRegistry::get((string) $acc['marketplace']) : null;
		$out = [];

		foreach ($a ? array_keys($a->credentialFields()) : [] as $k) {
			$out[$k] = isset($c[$k]) && $c[$k] !== '';
		}

		return $out;
	}

	public static function deleteAccount(int $id, ?int $vendor): bool
	{
		if (!self::account($id, $vendor)) {
			return false;
		}

		foreach (['mh_errors', 'mh_listings', 'mh_category_map'] as $t) {
			DB::execute('DELETE FROM ' . $t . ' WHERE id_account = ?', [$id]); // yalnızca bu modülün kayıtları; ürünlere dokunulmaz
		}

		DB::execute('DELETE FROM mh_accounts WHERE id_account = ?', [$id]);

		return true;
	}

	public static function setStatus(int $id, string $status, string $error = ''): void
	{
		DB::update('mh_accounts', ['status' => $status, 'last_error' => mb_substr($error, 0, 255), 'date_upd' => self::now()], 'id_account = :i', ['i' => $id]);
	}

	// ---- kategori eşleştirme ----

	/** @return array<int,array{remote_category_id:string,remote_category_name:string}> yerel kategori ID → uzak kategori */
	public static function categoryMap(int $idAccount): array
	{
		$out = [];

		foreach (DB::execute('SELECT id_category, remote_category_id, remote_category_name FROM mh_category_map WHERE id_account = ?', [$idAccount]) ?: [] as $r) {
			$out[(int) $r['id_category']] = ['remote_category_id' => (string) $r['remote_category_id'], 'remote_category_name' => (string) $r['remote_category_name']];
		}

		return $out;
	}

	/** @return array{success:bool,message:string} remoteId boşsa eşleme kaldırılır */
	public static function setCategoryMap(int $idAccount, ?int $vendor, int $idCategory, string $remoteId, string $remoteName = ''): array
	{
		if (!self::account($idAccount, $vendor)) {
			return ['success' => false, 'message' => 'Hesap bulunamadı.'];
		}

		if (!DB::execute('SELECT 1 AS x FROM categories WHERE id_category = ?', [$idCategory])) {
			return ['success' => false, 'message' => 'Kategori bulunamadı.'];
		}

		$remoteId = mb_substr(trim($remoteId), 0, 60);

		if ($remoteId === '') {
			DB::execute('DELETE FROM mh_category_map WHERE id_account = ? AND id_category = ?', [$idAccount, $idCategory]);

			return ['success' => true, 'message' => 'Eşleme kaldırıldı.'];
		}

		if (!preg_match('/^[A-Za-z0-9_.\-:]+$/', $remoteId)) {
			return ['success' => false, 'message' => 'Pazaryeri kategori kodu yalnızca harf, rakam ve _ . - : içerebilir.'];
		}

		$row = ['remote_category_id' => $remoteId, 'remote_category_name' => mb_substr(trim(strip_tags($remoteName)), 0, 190)];

		if (DB::execute('SELECT 1 AS x FROM mh_category_map WHERE id_account = ? AND id_category = ?', [$idAccount, $idCategory])) {
			DB::update('mh_category_map', $row, 'id_account = :a AND id_category = :c', ['a' => $idAccount, 'c' => $idCategory]);
		} else {
			DB::insert('mh_category_map', $row + ['id_account' => $idAccount, 'id_category' => $idCategory]);
		}

		return ['success' => true, 'message' => 'Kategori eşlendi.'];
	}

	// ---- gönderim kayıtları / hatalar ----

	/** @return list<array<string,mixed>> */
	public static function listings(?int $vendor, int $idAccount = 0, int $limit = 200): array
	{
		$w = [];
		$p = [];

		if ($vendor !== null) {
			$w[] = 'l.id_vendor = ?';
			$p[] = $vendor;
		}

		if ($idAccount > 0) {
			$w[] = 'l.id_account = ?';
			$p[] = $idAccount;
		}

		return DB::execute('SELECT l.*, a.label AS account_label, a.marketplace FROM mh_listings l INNER JOIN mh_accounts a ON a.id_account = l.id_account' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY l.id_listing DESC LIMIT ' . max(1, min(500, $limit)), $p) ?: [];
	}

	/** @return array<string,int> */
	public static function listingCounts(int $idAccount): array
	{
		$out = [];

		foreach (DB::execute('SELECT state, COUNT(*) AS n FROM mh_listings WHERE id_account = ? GROUP BY state', [$idAccount]) ?: [] as $r) {
			$out[(string) $r['state']] = (int) $r['n'];
		}

		return $out;
	}

	public static function logError(int $idAccount, int $idProduct, string $code, string $message): void
	{
		DB::insert('mh_errors', ['id_account' => $idAccount, 'id_product' => $idProduct, 'code' => mb_substr($code, 0, 30), 'message' => mb_substr($message, 0, 255)]);
	}

	/** @return list<array<string,mixed>> */
	public static function errors(?int $vendor, int $idAccount = 0, int $limit = 200): array
	{
		$w = [];
		$p = [];

		if ($vendor !== null) {
			$w[] = 'a.id_vendor = ?';
			$p[] = $vendor;
		}

		if ($idAccount > 0) {
			$w[] = 'e.id_account = ?';
			$p[] = $idAccount;
		}

		return DB::execute('SELECT e.*, a.label AS account_label, a.marketplace FROM mh_errors e INNER JOIN mh_accounts a ON a.id_account = e.id_account' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY e.id_error DESC LIMIT ' . max(1, min(500, $limit)), $p) ?: [];
	}
}
