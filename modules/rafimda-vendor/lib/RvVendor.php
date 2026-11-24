<?php

/**
 * Satıcı / mağaza kaydı ve durum yönetimi.
 * Kimlik: FriSay `users` tablosu kullanılır; satıcı hesabı ayrı parola tutmaz (mükerrer hesap yok).
 */
class RvVendor
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_REJECTED = 'rejected';

	public const STATUSES = [
		self::STATUS_PENDING => 'Onay Bekliyor',
		self::STATUS_ACTIVE => 'Aktif',
		self::STATUS_SUSPENDED => 'Askıda',
		self::STATUS_REJECTED => 'Reddedildi',
	];

	public static function slugify(string $text): string
	{
		$map = ['ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'İ' => 'i', 'I' => 'i',
			'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u'];
		$text = strtr($text, $map);
		$text = strtolower($text);
		$text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
		$text = trim($text, '-');

		return mb_substr($text, 0, 80);
	}

	public static function uniqueSlug(string $name): string
	{
		$base = self::slugify($name);

		if ($base === '') {
			$base = 'magaza';
		}

		$slug = $base;
		$i = 1;

		while (DB::getRowSafe('rafimda_vendors', 'store_slug = ?', [$slug])) {
			$i++;
			$slug = $base . '-' . $i;

			if ($i > 500) {
				$slug = $base . '-' . bin2hex(random_bytes(3));
				break;
			}
		}

		return $slug;
	}

	/**
	 * @param array<string,mixed> $in
	 * @return array{ok:bool, errors:array<string,string>, clean:array<string,string>}
	 */
	public static function validateStore(array $in): array
	{
		$errors = [];
		$clean = [
			'store_name' => trim(strip_tags((string) ($in['store_name'] ?? ''))),
			'legal_name' => trim(strip_tags((string) ($in['legal_name'] ?? ''))),
			'tax_office' => trim(strip_tags((string) ($in['tax_office'] ?? ''))),
			'tax_number' => preg_replace('/\D+/', '', (string) ($in['tax_number'] ?? '')) ?? '',
			'phone' => trim(strip_tags((string) ($in['phone'] ?? ''))),
			'email' => strtolower(trim((string) ($in['email'] ?? ''))),
			'city' => trim(strip_tags((string) ($in['city'] ?? ''))),
			'description' => trim(strip_tags((string) ($in['description'] ?? ''))),
		];

		$len = static fn(string $s): int => mb_strlen($s);

		if ($len($clean['store_name']) < 3 || $len($clean['store_name']) > 120) {
			$errors['store_name'] = 'Mağaza adı 3-120 karakter olmalı.';
		}

		if ($clean['tax_number'] !== '' && !in_array($len($clean['tax_number']), [10, 11], true)) {
			$errors['tax_number'] = 'Vergi/TC kimlik numarası 10 veya 11 haneli olmalı.';
		}

		if ($clean['email'] !== '' && !filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
			$errors['email'] = 'Geçerli bir e-posta girin.';
		}

		if ($len($clean['phone']) > 32) {
			$errors['phone'] = 'Telefon çok uzun.';
		}

		$clean['description'] = mb_substr($clean['description'], 0, 2000);
		$clean['legal_name'] = mb_substr($clean['legal_name'], 0, 190);
		$clean['tax_office'] = mb_substr($clean['tax_office'], 0, 120);
		$clean['city'] = mb_substr($clean['city'], 0, 80);

		return ['ok' => $errors === [], 'errors' => $errors, 'clean' => $clean];
	}

	/**
	 * Mağaza + sahip personel kaydı oluşturur. Kullanıcının zaten aktif bir sahiplik kaydı varsa reddeder.
	 *
	 * @param array<string,string> $clean validateStore() çıktısı
	 * @return array{success:bool, message:string, id_vendor:int}
	 */
	public static function create(int $idUser, array $clean, bool $autoApprove = false): array
	{
		if ($idUser <= 0) {
			return ['success' => false, 'message' => 'Oturum gerekli.', 'id_vendor' => 0];
		}

		$existing = DB::getRowSafe('rafimda_vendors', 'owner_user_id = ? AND status <> ?', [$idUser, self::STATUS_REJECTED]);

		if ($existing) {
			return ['success' => false, 'message' => 'Bu hesap için zaten bir mağaza başvurusu var.', 'id_vendor' => (int) $existing['id_vendor']];
		}

		$status = $autoApprove ? self::STATUS_ACTIVE : self::STATUS_PENDING;
		$id = DB::insert('rafimda_vendors', [
			'store_name' => $clean['store_name'],
			'store_slug' => self::uniqueSlug($clean['store_name']),
			'legal_name' => $clean['legal_name'],
			'tax_office' => $clean['tax_office'],
			'tax_number' => $clean['tax_number'],
			'phone' => $clean['phone'],
			'email' => $clean['email'],
			'city' => $clean['city'],
			'description' => $clean['description'],
			'status' => $status,
			'owner_user_id' => $idUser,
			'approved_at' => $autoApprove ? date('Y-m-d H:i:s') : null,
		]);

		if (!$id) {
			return ['success' => false, 'message' => 'Mağaza oluşturulamadı.', 'id_vendor' => 0];
		}

		$staffId = DB::insert('rafimda_vendor_staff', [
			'id_vendor' => (int) $id,
			'id_user' => $idUser,
			'role' => 'owner',
			'status' => 'active',
		]);

		if (!$staffId) {
			DB::execute('DELETE FROM rafimda_vendors WHERE id_vendor = ?', [(int) $id]);

			return ['success' => false, 'message' => 'Mağaza sahibi kaydı oluşturulamadı.', 'id_vendor' => 0];
		}

		RvAudit::log((int) $id, 'vendor.created', 'vendor', (int) $id, ['status' => $status]);

		return ['success' => true, 'message' => 'Mağaza oluşturuldu.', 'id_vendor' => (int) $id];
	}

	/** @param array<string,string> $clean */
	public static function updateStore(int $idVendor, array $clean): bool
	{
		$res = DB::update('rafimda_vendors', [
			'store_name' => $clean['store_name'],
			'legal_name' => $clean['legal_name'],
			'tax_office' => $clean['tax_office'],
			'tax_number' => $clean['tax_number'],
			'phone' => $clean['phone'],
			'email' => $clean['email'],
			'city' => $clean['city'],
			'description' => $clean['description'],
			'date_upd' => date('Y-m-d H:i:s'),
		], 'id_vendor = :vid', ['vid' => $idVendor]);

		if ($res !== false) {
			RvAudit::log($idVendor, 'store.updated', 'vendor', $idVendor);
		}

		return $res !== false;
	}

	public static function get(int $idVendor): ?array
	{
		$row = DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$idVendor]);

		return $row ?: null;
	}

	/**
	 * Ana yönetici durum değişikliği.
	 */
	public static function setStatus(int $idVendor, string $status, int $idAdmin, string $reason = ''): bool
	{
		if (!isset(self::STATUSES[$status]) || !self::get($idVendor)) {
			return false;
		}

		$data = [
			'status' => $status,
			'rejection_reason' => $status === self::STATUS_REJECTED ? mb_substr(trim(strip_tags($reason)), 0, 255) : '',
			'date_upd' => date('Y-m-d H:i:s'),
		];

		if ($status === self::STATUS_ACTIVE) {
			$data['approved_at'] = date('Y-m-d H:i:s');
		}

		$res = DB::update('rafimda_vendors', $data, 'id_vendor = :vid', ['vid' => $idVendor]);

		if ($res !== false) {
			RvAudit::log($idVendor, 'vendor.status', 'vendor', $idVendor, ['status' => $status, 'reason' => $reason], $idAdmin);
		}

		return $res !== false;
	}

	/** @return list<array<string,mixed>> */
	public static function adminList(string $status = '', string $q = '', int $limit = 50, int $offset = 0): array
	{
		$sql = 'SELECT v.*, u.user_full_name AS owner_name, u.email AS owner_email,
				(SELECT COUNT(*) FROM rafimda_vendor_products vp WHERE vp.id_vendor = v.id_vendor) AS product_count
			FROM rafimda_vendors v LEFT JOIN users u ON u.id_user = v.owner_user_id WHERE 1=1';
		$params = [];

		if ($status !== '' && isset(self::STATUSES[$status])) {
			$sql .= ' AND v.status = ?';
			$params[] = $status;
		}

		if ($q !== '') {
			$sql .= ' AND (v.store_name LIKE ? OR v.email LIKE ? OR u.email LIKE ?)';
			$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
			array_push($params, $like, $like, $like);
		}

		$sql .= ' ORDER BY v.id_vendor DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);

		return DB::execute($sql, $params) ?: [];
	}

	/** @return array<string,int> */
	public static function countByStatus(): array
	{
		$out = array_fill_keys(array_keys(self::STATUSES), 0);
		$rows = DB::execute('SELECT status, COUNT(*) AS c FROM rafimda_vendors GROUP BY status') ?: [];

		foreach ($rows as $r) {
			$out[(string) $r['status']] = (int) $r['c'];
		}

		return $out;
	}

	// ---- Personel ----

	/** @return list<array<string,mixed>> */
	public static function staffList(int $idVendor): array
	{
		return DB::execute(
			'SELECT s.*, u.user_full_name, u.email FROM rafimda_vendor_staff s
			LEFT JOIN users u ON u.id_user = s.id_user WHERE s.id_vendor = ? ORDER BY s.id_staff ASC',
			[$idVendor]
		) ?: [];
	}

	/**
	 * Mevcut bir FriSay hesabını (e-posta ile) personel olarak ekler. Yeni hesap açmaz.
	 *
	 * @return array{success:bool, message:string}
	 */
	public static function addStaffByEmail(int $idVendor, string $email, string $role): array
	{
		$email = strtolower(trim($email));

		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['success' => false, 'message' => 'Geçerli bir e-posta girin.'];
		}

		if (!RvPerm::isValidRole($role) || $role === 'owner') {
			return ['success' => false, 'message' => 'Geçersiz rol.'];
		}

		$user = DB::getRowSafe('users', 'email = ? AND active = 1', [$email]);

		if (!$user) {
			return ['success' => false, 'message' => 'Bu e-postayla kayıtlı aktif bir hesap yok. Önce kişinin mağaza sitesine üye olması gerekir.'];
		}

		$idUser = (int) $user['id_user'];
		$row = DB::getRowSafe('rafimda_vendor_staff', 'id_vendor = ? AND id_user = ?', [$idVendor, $idUser]);

		if ($row) {
			return ['success' => false, 'message' => 'Bu kullanıcı zaten personel.'];
		}

		$id = DB::insert('rafimda_vendor_staff', ['id_vendor' => $idVendor, 'id_user' => $idUser, 'role' => $role, 'status' => 'active']);

		if (!$id) {
			return ['success' => false, 'message' => 'Personel eklenemedi.'];
		}

		RvAudit::log($idVendor, 'staff.added', 'staff', (int) $id, ['id_user' => $idUser, 'role' => $role]);

		return ['success' => true, 'message' => 'Personel eklendi.'];
	}

	/**
	 * Personel rolü/durumu. Sahip (owner) satırı değiştirilemez; kendi satırını kimse devre dışı bırakamaz.
	 */
	public static function updateStaff(int $idVendor, int $idStaff, string $role, string $status, int $actorUserId): array
	{
		$row = DB::getRowSafe('rafimda_vendor_staff', 'id_staff = ? AND id_vendor = ?', [$idStaff, $idVendor]);

		if (!$row) {
			return ['success' => false, 'message' => 'Personel bulunamadı.'];
		}

		if ($row['role'] === 'owner') {
			return ['success' => false, 'message' => 'Mağaza sahibi değiştirilemez.'];
		}

		if ((int) $row['id_user'] === $actorUserId) {
			return ['success' => false, 'message' => 'Kendi kaydınızı değiştiremezsiniz.'];
		}

		if (!RvPerm::isValidRole($role) || $role === 'owner' || !in_array($status, ['active', 'disabled'], true)) {
			return ['success' => false, 'message' => 'Geçersiz değer.'];
		}

		DB::update('rafimda_vendor_staff', ['role' => $role, 'status' => $status], 'id_staff = :sid AND id_vendor = :vid', ['sid' => $idStaff, 'vid' => $idVendor]);
		RvAudit::log($idVendor, 'staff.updated', 'staff', $idStaff, ['role' => $role, 'status' => $status]);

		return ['success' => true, 'message' => 'Personel güncellendi.'];
	}
}
