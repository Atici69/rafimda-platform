<?php

/** Bayi kaydı, başvuru doğrulama, durum yönetimi ve 3 seviyeli yapı (yönetici → bayi → alt bayi). */
class RdDealer
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_REJECTED = 'rejected';

	public const STATUSES = [
		'pending' => 'Başvuru değerlendiriliyor',
		'active' => 'Aktif',
		'suspended' => 'Askıda',
		'rejected' => 'Reddedildi',
	];

	/** @param array<string,mixed> $in @return array{ok:bool,errors:array<string,string>,clean:array<string,string>} */
	public static function validate(array $in): array
	{
		$e = [];
		$c = [];
		$clean = static fn(string $k, int $max): string => mb_substr(trim(strip_tags((string) ($in[$k] ?? ''))), 0, $max);
		$c['company_name'] = $clean('company_name', 160);
		$c['contact_name'] = $clean('contact_name', 120);
		$c['phone'] = preg_replace('/[^0-9+\s\-()]/', '', $clean('phone', 32)) ?? '';
		$c['email'] = strtolower($clean('email', 190));
		$c['city'] = $clean('city', 80);
		$c['tax_office'] = $clean('tax_office', 120);
		$c['tax_number'] = preg_replace('/\D/', '', (string) ($in['tax_number'] ?? '')) ?? '';
		$c['note'] = $clean('note', 1000);

		if (mb_strlen($c['company_name']) < 3) {
			$e['company_name'] = 'Firma / bayi adı en az 3 karakter olmalı.';
		}

		if (mb_strlen($c['contact_name']) < 3) {
			$e['contact_name'] = 'Yetkili adı soyadı girin.';
		}

		if (strlen(preg_replace('/\D/', '', $c['phone']) ?? '') < 10) {
			$e['phone'] = 'Geçerli bir telefon numarası girin.';
		}

		if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
			$e['email'] = 'Geçerli bir e-posta girin.';
		}

		if ($c['city'] === '') {
			$e['city'] = 'Şehir girin.';
		}

		if ($c['tax_number'] !== '' && !in_array(strlen($c['tax_number']), [10, 11], true)) {
			$e['tax_number'] = 'Vergi/TC kimlik numarası 10 veya 11 haneli olmalı.';
		}

		return ['ok' => $e === [], 'errors' => $e, 'clean' => $c];
	}

	public static function newCode(): string
	{
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

		for ($try = 0; $try < 20; $try++) {
			$code = '';

			for ($i = 0; $i < 8; $i++) {
				$code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
			}

			if (!self::byCode($code, false)) {
				return $code;
			}
		}

		return strtoupper(bin2hex(random_bytes(6)));
	}

	/** @return array<string,mixed>|null */
	public static function get(int $id): ?array
	{
		$r = DB::getRowSafe('rd_dealers', 'id_dealer = ?', [$id]);

		return $r ?: null;
	}

	/** @return array<string,mixed>|null */
	public static function byUser(int $idUser): ?array
	{
		$r = DB::getRowSafe('rd_dealers', 'owner_user_id = ?', [$idUser]);

		return $r ?: null;
	}

	/** @return array<string,mixed>|null */
	public static function byCode(string $code, bool $onlyActive = true): ?array
	{
		$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

		if ($code === '') {
			return null;
		}

		$r = DB::getRowSafe('rd_dealers', 'referral_code = ?', [$code]);

		if (!$r) {
			return null;
		}

		return (!$onlyActive || self::isOperational($r)) ? $r : null;
	}

	/** Bayi hem onaylı hem (alt bayi ise) üst bayi tarafından durdurulmamış mı? */
	public static function isOperational(array $d): bool
	{
		if (($d['status'] ?? '') !== self::STATUS_ACTIVE || !empty($d['paused_by_parent'])) {
			return false;
		}

		if ((int) ($d['id_parent'] ?? 0) > 0) {
			$p = self::get((int) $d['id_parent']);

			return $p !== null && ($p['status'] ?? '') === self::STATUS_ACTIVE && empty($p['paused_by_parent']);
		}

		return true;
	}

	public static function level(array $d): int
	{
		return (int) $d['id_parent'] > 0 ? 2 : 1;
	}

	/** @param array<string,string> $clean @return array{success:bool,message:string,id_dealer?:int} */
	public static function create(int $idUser, array $clean, string $parentCode = ''): array
	{
		if ($idUser <= 0) {
			return ['success' => false, 'message' => 'Geçersiz kullanıcı.'];
		}

		if (self::byUser($idUser)) {
			return ['success' => false, 'message' => 'Bu hesapla zaten bir bayi başvurusu/kaydı var.'];
		}

		$idParent = 0;

		if (trim($parentCode) !== '') {
			$parent = self::byCode($parentCode);

			if (!$parent) {
				return ['success' => false, 'message' => 'Üst bayi kodu geçersiz veya üst bayi aktif değil.'];
			}

			if (self::level($parent) !== 1) {
				return ['success' => false, 'message' => 'Alt bayinin altına yeni bayi eklenemez (en fazla 3 seviye).'];
			}

			if ((int) $parent['owner_user_id'] === $idUser) {
				return ['success' => false, 'message' => 'Kendi kodunuzla başvuru yapamazsınız.'];
			}

			$max = (int) (RdPlans::effective($parent)['max_sub_dealers'] ?? 0);

			if ($max <= 0 || self::countSub((int) $parent['id_dealer'], true) >= $max) {
				return ['success' => false, 'message' => 'Üst bayinin alt bayi kontenjanı dolu veya paketi alt bayiye izin vermiyor.'];
			}

			$idParent = (int) $parent['id_dealer'];
		}

		$id = DB::insert('rd_dealers', [
			'owner_user_id' => $idUser,
			'id_parent' => $idParent,
			'company_name' => $clean['company_name'],
			'contact_name' => $clean['contact_name'],
			'phone' => $clean['phone'],
			'email' => $clean['email'],
			'city' => $clean['city'],
			'tax_office' => $clean['tax_office'],
			'tax_number' => $clean['tax_number'],
			'note' => $clean['note'] === '' ? null : $clean['note'],
			'referral_code' => self::newCode(),
			'status' => self::STATUS_PENDING,
		]);

		if (!$id) {
			return ['success' => false, 'message' => 'Başvuru kaydedilemedi.'];
		}

		RdAudit::log((int) $id, 'dealer.apply', 'dealer', (int) $id, ['parent' => $idParent]);

		return ['success' => true, 'message' => 'Başvurunuz alındı.', 'id_dealer' => (int) $id];
	}

	/** @return int alt bayi sayısı (aktif+bekleyen veya yalnızca kontenjana sayılanlar) */
	public static function countSub(int $idParent, bool $forQuota = false): int
	{
		$sql = 'SELECT COUNT(*) FROM rd_dealers WHERE id_parent = ?' . ($forQuota ? " AND status IN ('pending','active','suspended')" : '');

		return (int) DB::getValue($sql, [$idParent]);
	}

	/** @return list<array<string,mixed>> */
	public static function subDealers(int $idParent): array
	{
		return DB::execute('SELECT * FROM rd_dealers WHERE id_parent = ? ORDER BY id_dealer DESC', [$idParent]) ?: [];
	}

	/** Yönetici durum değişikliği. */
	public static function setStatus(int $id, string $to, int $idAdmin, string $reason = ''): bool
	{
		if (!isset(self::STATUSES[$to]) || !self::get($id)) {
			return false;
		}

		$data = ['status' => $to, 'rejection_reason' => $to === self::STATUS_REJECTED ? mb_substr(trim($reason), 0, 255) : '', 'date_upd' => date('Y-m-d H:i:s')];

		if ($to === self::STATUS_ACTIVE) {
			$data['approved_at'] = date('Y-m-d H:i:s');
		}

		$ok = DB::update('rd_dealers', $data, 'id_dealer = :d', ['d' => $id]) !== false;

		if ($ok) {
			RdAudit::log($id, 'dealer.status', 'dealer', $id, ['to' => $to, 'reason' => $reason], $idAdmin);
		}

		return $ok;
	}

	/** Üst bayi, KENDİ alt bayisini durdurur/devam ettirir (yalnızca sahiplik doğrulanarak). */
	public static function setPausedByParent(int $idParent, int $idSub, bool $paused): bool
	{
		$sub = self::get($idSub);

		if (!$sub || (int) $sub['id_parent'] !== $idParent || $idParent <= 0) {
			return false;
		}

		$ok = DB::update('rd_dealers', ['paused_by_parent' => $paused ? 1 : 0, 'date_upd' => date('Y-m-d H:i:s')], 'id_dealer = :d AND id_parent = :p', ['d' => $idSub, 'p' => $idParent]) !== false;

		if ($ok) {
			RdAudit::log($idSub, $paused ? 'dealer.paused_by_parent' : 'dealer.resumed_by_parent', 'dealer', $idSub, ['parent' => $idParent]);
		}

		return $ok;
	}

	/** @return bool oran 0-100 aralığında mı ve kaydedildi mi */
	public static function setRates(int $id, float $commission, float $parentShare, int $idAdmin): bool
	{
		if ($commission < 0 || $commission > 100 || $parentShare < 0 || $parentShare > 100 || $commission + $parentShare > 100 || !self::get($id)) {
			return false;
		}

		$ok = DB::update('rd_dealers', ['commission_rate' => round($commission, 2), 'parent_share_rate' => round($parentShare, 2), 'date_upd' => date('Y-m-d H:i:s')], 'id_dealer = :d', ['d' => $id]) !== false;

		if ($ok) {
			RdAudit::log($id, 'dealer.rates', 'dealer', $id, ['commission' => $commission, 'parent_share' => $parentShare], $idAdmin);
		}

		return $ok;
	}

	/** @return list<array<string,mixed>> */
	public static function adminList(string $status = '', string $q = '', int $limit = 100): array
	{
		$where = [];
		$params = [];

		if (isset(self::STATUSES[$status])) {
			$where[] = 'd.status = ?';
			$params[] = $status;
		}

		if ($q !== '') {
			$where[] = '(d.company_name LIKE ? OR d.email LIKE ? OR d.referral_code LIKE ?)';
			$like = '%' . $q . '%';
			array_push($params, $like, $like, $like);
		}

		return DB::execute(
			'SELECT d.*, p.company_name AS parent_name FROM rd_dealers d LEFT JOIN rd_dealers p ON p.id_dealer = d.id_parent'
			. ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY d.id_dealer DESC LIMIT ' . max(1, min(500, $limit)),
			$params
		) ?: [];
	}

	/** @return array<string,int> */
	public static function countByStatus(): array
	{
		$out = array_fill_keys(array_keys(self::STATUSES), 0);

		foreach (DB::execute('SELECT status, COUNT(*) AS n FROM rd_dealers GROUP BY status') ?: [] as $r) {
			$out[(string) $r['status']] = (int) $r['n'];
		}

		return $out;
	}
}
