<?php

/**
 * Referans/atama ve komisyon HESAPLAMA. Ödeme, banka, muhasebe ve fatura YOKTUR; kayıtlar yalnızca raporlama içindir.
 * Çekirdek sipariş akışını bozmamak için kancalar hataları yutar (safeHook).
 */
class RdCommission
{
	public const STATUSES = ['pending' => 'Beklemede', 'approved' => 'Onaylandı', 'cancelled' => 'İptal'];
	public const COOKIE = 'rd_ref';

	public static function defaultRate(): float
	{
		$v = (float) str_replace(',', '.', (string) Settings::get('RAFIMDA_DEALER_DEFAULT_COMMISSION'));

		return ($v >= 0 && $v <= 100) ? $v : 0.0;
	}

	/** Öncelik: bayiye özel oran > geçerli paket oranı > varsayılan. */
	public static function rateFor(array $dealer): float
	{
		$own = (float) ($dealer['commission_rate'] ?? 0);

		if ($own > 0) {
			return $own;
		}

		$plan = RdPlans::effective($dealer);

		if ($plan && (float) $plan['commission_rate'] > 0) {
			return (float) $plan['commission_rate'];
		}

		return self::defaultRate();
	}

	/** Oturum/çerezdeki referans kodu (ilk dokunuş). */
	public static function refFromRequest(): string
	{
		$c = (string) ($_SESSION['rd_ref'] ?? ($_COOKIE[self::COOKIE] ?? ''));

		return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c) ?? '');
	}

	/** @return bool kullanıcı bu bayiye bağlandı mı (zaten başka bayide ise false) */
	public static function attach(int $idUser, int $idDealer, string $source = 'ref'): bool
	{
		if ($idUser <= 0 || $idDealer <= 0 || DB::getRowSafe('rd_customers', 'id_user = ?', [$idUser])) {
			return false;
		}

		$d = RdDealer::get($idDealer);

		if (!$d || (int) $d['owner_user_id'] === $idUser) {
			return false; // kendi kendine referans yok
		}

		if ((int) $d['id_parent'] > 0) {
			$p = RdDealer::get((int) $d['id_parent']);

			if ($p && (int) $p['owner_user_id'] === $idUser) {
				return false;
			}
		}

		return (bool) DB::insert('rd_customers', ['id_user' => $idUser, 'id_dealer' => $idDealer, 'source' => $source]);
	}

	public static function safeHook(callable $fn): void
	{
		try {
			$fn();
		} catch (\Throwable $e) {
			error_log('rafimda-dealer: ' . $e->getMessage());
		}
	}

	/** order.placed: atamayı çöz, doğrudan + (alt bayi ise) üst bayi payı kayıtlarını bir kez oluştur. @return int oluşan kayıt sayısı */
	public static function onOrderPlaced(array $order): int
	{
		$idOrder = (int) ($order['id_order'] ?? 0);
		$row = $idOrder > 0 ? DB::getRowSafe('orders', 'id_order = ?', [$idOrder]) : false;

		if (!$row || (int) $row['id_user'] <= 0) {
			return 0;
		}

		$idUser = (int) $row['id_user'];
		$link = DB::getRowSafe('rd_customers', 'id_user = ?', [$idUser]);

		if (!$link) {
			$ref = RdDealer::byCode(self::refFromRequest());

			if (!$ref || !self::attach($idUser, (int) $ref['id_dealer'], 'ref')) {
				return 0;
			}

			$link = DB::getRowSafe('rd_customers', 'id_user = ?', [$idUser]);
		}

		$dealer = $link ? RdDealer::get((int) $link['id_dealer']) : null;

		if (!$dealer || !RdDealer::isOperational($dealer)) {
			return 0;
		}

		$total = round((float) $row['total'], 2);
		$status = ((int) $row['status'] === 4) ? 'approved' : 'pending';
		$made = 0;

		if ($total <= 0 || in_array((int) $row['status'], [5, 6], true)) {
			return 0;
		}

		$rate = self::rateFor($dealer);

		if ($rate > 0 && self::insertOnce((int) $dealer['id_dealer'], (int) $dealer['id_dealer'], $idOrder, $idUser, 'direct', $total, $rate, $status)) {
			$made++;
		}

		$share = (float) $dealer['parent_share_rate'];

		if ((int) $dealer['id_parent'] > 0 && $share > 0) {
			$parent = RdDealer::get((int) $dealer['id_parent']);

			if ($parent && RdDealer::isOperational($parent) && self::insertOnce((int) $parent['id_dealer'], (int) $dealer['id_dealer'], $idOrder, $idUser, 'override', $total, $share, $status)) {
				$made++;
			}
		}

		return $made;
	}

	private static function insertOnce(int $idDealer, int $idSource, int $idOrder, int $idUser, string $type, float $total, float $rate, string $status): bool
	{
		if (DB::getRowSafe('rd_commissions', 'id_order = ? AND id_dealer = ? AND type = ?', [$idOrder, $idDealer, $type])) {
			return false;
		}

		return (bool) DB::insert('rd_commissions', [
			'id_dealer' => $idDealer,
			'id_source_dealer' => $idSource,
			'id_order' => $idOrder,
			'id_user' => $idUser,
			'type' => $type,
			'order_total' => $total,
			'rate' => round($rate, 2),
			'amount' => round($total * $rate / 100, 2),
			'status' => $status,
		]);
	}

	/** order.updated: teslim → onaylı; iptal/iade → iptal. Yönetici elle iptal ettiği kayıt geri açılmaz. */
	public static function onOrderUpdated(array $order, int $oldStatus = 0): void
	{
		$idOrder = (int) ($order['id_order'] ?? 0);
		$status = (int) ($order['status'] ?? 0);

		if ($idOrder <= 0 || $status === $oldStatus) {
			return;
		}

		$now = date('Y-m-d H:i:s');

		if ($status === 4) {
			DB::execute('UPDATE rd_commissions SET status = ?, date_upd = ? WHERE id_order = ? AND status = ?', ['approved', $now, $idOrder, 'pending']);
		} elseif (in_array($status, [5, 6], true)) {
			DB::execute('UPDATE rd_commissions SET status = ?, date_upd = ? WHERE id_order = ? AND status IN (?, ?)', ['cancelled', $now, $idOrder, 'pending', 'approved']);
		}
	}

	/** Yönetici elle durum değiştirir. */
	public static function adminSet(int $idCommission, string $to, int $idAdmin): bool
	{
		if (!isset(self::STATUSES[$to])) {
			return false;
		}

		$row = DB::getRowSafe('rd_commissions', 'id_commission = ?', [$idCommission]);

		if (!$row) {
			return false;
		}

		$ok = DB::update('rd_commissions', ['status' => $to, 'date_upd' => date('Y-m-d H:i:s')], 'id_commission = :i', ['i' => $idCommission]) !== false;

		if ($ok) {
			RdAudit::log((int) $row['id_dealer'], 'commission.' . $to, 'commission', $idCommission, [], $idAdmin);
		}

		return $ok;
	}

	/** @return array{pending:float,approved:float,cancelled:float,count:int} yalnızca bu bayinin kendi kayıtları */
	public static function totals(int $idDealer): array
	{
		$out = ['pending' => 0.0, 'approved' => 0.0, 'cancelled' => 0.0, 'count' => 0];

		foreach (DB::execute('SELECT status, SUM(amount) AS s, COUNT(*) AS n FROM rd_commissions WHERE id_dealer = ? GROUP BY status', [$idDealer]) ?: [] as $r) {
			$out[(string) $r['status']] = round((float) $r['s'], 2);
			$out['count'] += (int) $r['n'];
		}

		return $out;
	}

	/** @return list<array<string,mixed>> */
	public static function listFor(int $idDealer, string $status = '', int $limit = 100): array
	{
		$sql = 'SELECT k.*, s.company_name AS source_name FROM rd_commissions k LEFT JOIN rd_dealers s ON s.id_dealer = k.id_source_dealer WHERE k.id_dealer = ?';
		$params = [$idDealer];

		if (isset(self::STATUSES[$status])) {
			$sql .= ' AND k.status = ?';
			$params[] = $status;
		}

		return DB::execute($sql . ' ORDER BY k.id_commission DESC LIMIT ' . max(1, min(1000, $limit)), $params) ?: [];
	}
}
