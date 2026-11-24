<?php

/** Bayi paketleri ve abonelik KAYDI. Ödeme alınmaz / muhasebe yoktur; yalnızca takip içindir. */
class RdPlans
{
	public const SUB_STATUSES = ['requested' => 'Talep edildi', 'active' => 'Aktif', 'expired' => 'Süresi doldu', 'cancelled' => 'İptal'];

	/** @return list<array<string,mixed>> */
	public static function all(bool $onlyActive = false): array
	{
		return DB::execute('SELECT * FROM rd_plans' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY monthly_price ASC, id_plan ASC') ?: [];
	}

	/** @return array<string,mixed>|null */
	public static function get(string $code): ?array
	{
		$r = DB::getRowSafe('rd_plans', 'code = ?', [$code]);

		return $r ?: null;
	}

	/** @param array<string,mixed> $in @return array{success:bool,message:string} */
	public static function save(array $in): array
	{
		$code = strtolower(preg_replace('/[^a-z0-9\-]/i', '', (string) ($in['code'] ?? '')) ?? '');
		$name = mb_substr(trim(strip_tags((string) ($in['name'] ?? ''))), 0, 80);
		$price = (float) str_replace(',', '.', (string) ($in['monthly_price'] ?? '0'));
		$rate = (float) str_replace(',', '.', (string) ($in['commission_rate'] ?? '0'));
		$max = (int) ($in['max_sub_dealers'] ?? 0);

		if ($code === '' || strlen($code) > 32 || $name === '' || $price < 0 || $rate < 0 || $rate > 100 || $max < 0 || $max > 1000) {
			return ['success' => false, 'message' => 'Kod, ad, fiyat (≥0), komisyon (0-100) ve alt bayi sınırı (0-1000) geçerli olmalı.'];
		}

		$data = ['name' => $name, 'monthly_price' => round($price, 2), 'commission_rate' => round($rate, 2), 'max_sub_dealers' => $max, 'active' => empty($in['active']) ? 0 : 1];

		if (self::get($code)) {
			DB::update('rd_plans', $data, 'code = :c', ['c' => $code]);
		} else {
			DB::insert('rd_plans', $data + ['code' => $code]);
		}

		return ['success' => true, 'message' => 'Paket kaydedildi.'];
	}

	/** Kurulumda en az bir paket olsun diye (idempotent). Komisyon oranı yöneticiye aittir; burada yalnızca başlangıç değeri. */
	public static function ensureDefault(): void
	{
		if (!DB::getValue('SELECT COUNT(*) FROM rd_plans')) {
			self::save(['code' => 'baslangic', 'name' => 'Başlangıç', 'monthly_price' => '0', 'commission_rate' => '3', 'max_sub_dealers' => '0', 'active' => '1']);
		}
	}

	/** Bayi paket talebi (yönetici onayı gerekir). @return array{success:bool,message:string} */
	public static function request(int $idDealer, string $code): array
	{
		$plan = self::get($code);

		if (!$plan || (int) $plan['active'] !== 1) {
			return ['success' => false, 'message' => 'Paket bulunamadı.'];
		}

		if (DB::getRowSafe('rd_subscriptions', 'id_dealer = ? AND status = ?', [$idDealer, 'requested'])) {
			return ['success' => false, 'message' => 'Bekleyen bir paket talebiniz zaten var.'];
		}

		DB::insert('rd_subscriptions', ['id_dealer' => $idDealer, 'plan_code' => $code, 'price' => $plan['monthly_price'], 'status' => 'requested']);
		RdAudit::log($idDealer, 'subscription.request', 'plan', 0, ['plan' => $code]);

		return ['success' => true, 'message' => 'Talebiniz alındı; yönetici onayından sonra paketiniz atanır.'];
	}

	/** Yönetici ataması / talep onayı. @return array{success:bool,message:string} */
	public static function assign(int $idDealer, string $code, string $expires, int $idAdmin, string $note = ''): array
	{
		$plan = self::get($code);

		if (!$plan || !RdDealer::get($idDealer)) {
			return ['success' => false, 'message' => 'Bayi veya paket bulunamadı.'];
		}

		$exp = null;

		if (trim($expires) !== '') {
			$t = strtotime($expires);

			if ($t === false || $t < time()) {
				return ['success' => false, 'message' => 'Bitiş tarihi geçerli ve gelecekte olmalı.'];
			}

			$exp = date('Y-m-d H:i:s', $t);
		}

		DB::execute('UPDATE rd_subscriptions SET status = ? WHERE id_dealer = ? AND status IN (?, ?)', ['cancelled', $idDealer, 'requested', 'active']);
		DB::insert('rd_subscriptions', ['id_dealer' => $idDealer, 'plan_code' => $code, 'price' => $plan['monthly_price'], 'status' => 'active', 'starts_at' => date('Y-m-d H:i:s'), 'expires_at' => $exp, 'note' => mb_substr($note, 0, 255), 'id_admin' => $idAdmin]);
		DB::update('rd_dealers', ['plan_code' => $code, 'plan_expires_at' => $exp, 'date_upd' => date('Y-m-d H:i:s')], 'id_dealer = :d', ['d' => $idDealer]);
		RdAudit::log($idDealer, 'subscription.assign', 'plan', 0, ['plan' => $code, 'expires' => $exp], $idAdmin);

		return ['success' => true, 'message' => 'Paket atandı.'];
	}

	/** Bayinin geçerli (süresi dolmamış) paketi. @return array<string,mixed>|null */
	public static function effective(array $dealer): ?array
	{
		$code = (string) ($dealer['plan_code'] ?? '');

		if ($code === '') {
			return null;
		}

		$exp = (string) ($dealer['plan_expires_at'] ?? '');

		if ($exp !== '' && strtotime($exp) < time()) {
			return null;
		}

		return self::get($code);
	}

	/** @return list<array<string,mixed>> */
	public static function history(int $idDealer): array
	{
		return DB::execute('SELECT s.*, p.name AS plan_name FROM rd_subscriptions s LEFT JOIN rd_plans p ON p.code = s.plan_code WHERE s.id_dealer = ? ORDER BY s.id_sub DESC LIMIT 50', [$idDealer]) ?: [];
	}
}
