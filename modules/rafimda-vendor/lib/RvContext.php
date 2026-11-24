<?php

/**
 * İstek bağlamı: oturum açmış FriSay kullanıcısının hangi satıcıda hangi rolle olduğunu çözer.
 * Panelin TÜM erişim kararları buradan geçer (sunucu tarafı yetki kontrolü).
 */
class RvContext
{
	/** @var array<string,mixed>|null */
	private static ?array $cache = null;
	private static bool $resolved = false;

	public static function reset(): void
	{
		self::$cache = null;
		self::$resolved = false;
	}

	/**
	 * @return list<array<string,mixed>> kullanıcının aktif personel üyelikleri (vendor bilgisiyle)
	 */
	public static function memberships(int $idUser): array
	{
		return DB::execute(
			'SELECT s.id_staff, s.role, s.status AS staff_status, v.*
			FROM rafimda_vendor_staff s INNER JOIN rafimda_vendors v ON v.id_vendor = s.id_vendor
			WHERE s.id_user = ? AND s.status = ? ORDER BY v.id_vendor ASC',
			[$idUser, 'active']
		) ?: [];
	}

	/**
	 * @return array{vendor:array<string,mixed>, role:string, id_user:int}|null
	 */
	public static function current(): ?array
	{
		if (self::$resolved) {
			return self::$cache;
		}

		self::$resolved = true;
		self::$cache = null;

		if (!Customer::isLoggedIn()) {
			return null;
		}

		$idUser = Customer::getId();
		$list = self::memberships($idUser);

		if ($list === []) {
			return null;
		}

		$wanted = (int) ($_SESSION['rv_vendor_id'] ?? 0);
		$pick = $list[0];

		foreach ($list as $m) {
			if ((int) $m['id_vendor'] === $wanted) {
				$pick = $m;
				break;
			}
		}

		$_SESSION['rv_vendor_id'] = (int) $pick['id_vendor'];
		$role = (string) $pick['role'];
		unset($pick['role'], $pick['staff_status'], $pick['id_staff']);

		self::$cache = ['vendor' => $pick, 'role' => $role, 'id_user' => $idUser];

		return self::$cache;
	}

	public static function switchVendor(int $idVendor): bool
	{
		if (!Customer::isLoggedIn()) {
			return false;
		}

		foreach (self::memberships(Customer::getId()) as $m) {
			if ((int) $m['id_vendor'] === $idVendor) {
				$_SESSION['rv_vendor_id'] = $idVendor;
				self::reset();

				return true;
			}
		}

		return false;
	}

	public static function vendorId(): int
	{
		$c = self::current();

		return $c ? (int) $c['vendor']['id_vendor'] : 0;
	}

	public static function isActiveVendor(): bool
	{
		$c = self::current();

		return $c !== null && ($c['vendor']['status'] ?? '') === RvVendor::STATUS_ACTIVE;
	}

	public static function can(string $perm): bool
	{
		$c = self::current();

		if (!$c || ($c['vendor']['status'] ?? '') !== RvVendor::STATUS_ACTIVE) {
			return false;
		}

		return RvPerm::roleAllows((string) $c['role'], $perm);
	}
}
