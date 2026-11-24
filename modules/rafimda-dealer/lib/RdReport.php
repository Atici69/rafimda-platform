<?php

/** Bayi performans raporları. Her sorgu bayi kimliğiyle sınırlıdır (veri izolasyonu). */
class RdReport
{
	/** @return list<array{ym:string,sales:float,commission:float,orders:int}> son N ay, eksik aylar sıfırla doldurulur */
	public static function monthly(int $idDealer, int $months = 12): array
	{
		$months = max(1, min(36, $months));
		$from = date('Y-m-01 00:00:00', strtotime('-' . ($months - 1) . ' months'));
		$rows = DB::execute(
			'SELECT SUBSTR(date_add, 1, 7) AS ym, SUM(order_total) AS sales, SUM(amount) AS comm, COUNT(*) AS n FROM rd_commissions WHERE id_dealer = ? AND type = ? AND status <> ? AND date_add >= ? GROUP BY SUBSTR(date_add, 1, 7)',
			[$idDealer, 'direct', 'cancelled', $from]
		) ?: [];
		$by = [];

		foreach ($rows as $r) {
			$by[(string) $r['ym']] = $r;
		}

		$out = [];

		for ($i = $months - 1; $i >= 0; $i--) {
			$ym = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' months'));
			$r = $by[$ym] ?? null;
			$out[] = ['ym' => $ym, 'sales' => $r ? round((float) $r['sales'], 2) : 0.0, 'commission' => $r ? round((float) $r['comm'], 2) : 0.0, 'orders' => $r ? (int) $r['n'] : 0];
		}

		return $out;
	}

	/** @return list<array<string,mixed>> bayinin müşterileri; e-posta maskelenir, adres/telefon HİÇ okunmaz */
	public static function customers(int $idDealer, int $limit = 200): array
	{
		$rows = DB::execute(
			'SELECT c.id_user, u.user_full_name, u.email, c.source, c.date_add, COALESCE(SUM(k.order_total), 0) AS total, COUNT(k.id_commission) AS orders
			FROM rd_customers c LEFT JOIN users u ON u.id_user = c.id_user
			LEFT JOIN rd_commissions k ON k.id_user = c.id_user AND k.id_dealer = c.id_dealer AND k.type = \'direct\' AND k.status <> \'cancelled\'
			WHERE c.id_dealer = ? GROUP BY c.id_rdc, c.id_user, u.user_full_name, u.email, c.source, c.date_add ORDER BY total DESC, c.id_rdc DESC LIMIT ' . max(1, min(1000, $limit)),
			[$idDealer]
		) ?: [];

		foreach ($rows as &$r) {
			$r['email_masked'] = self::maskEmail((string) ($r['email'] ?? ''));
			unset($r['email']);
		}

		return $rows;
	}

	public static function maskEmail(string $email): string
	{
		$at = strpos($email, '@');

		if ($at === false || $at < 1) {
			return '';
		}

		return mb_substr($email, 0, 1) . '***' . substr($email, $at);
	}

	/** @return list<array<string,mixed>> üst bayinin alt bayileri ve performansları */
	public static function subPerformance(int $idParent): array
	{
		return DB::execute(
			'SELECT d.id_dealer, d.company_name, d.contact_name, d.city, d.status, d.paused_by_parent, d.referral_code, d.date_add,
			(SELECT COUNT(*) FROM rd_customers rc WHERE rc.id_dealer = d.id_dealer) AS customers,
			COALESCE(SUM(k.order_total), 0) AS sales, COALESCE(SUM(k.amount), 0) AS comm, COUNT(k.id_commission) AS orders
			FROM rd_dealers d LEFT JOIN rd_commissions k ON k.id_source_dealer = d.id_dealer AND k.id_dealer = d.id_dealer AND k.type = \'direct\' AND k.status <> \'cancelled\'
			WHERE d.id_parent = ? GROUP BY d.id_dealer, d.company_name, d.contact_name, d.city, d.status, d.paused_by_parent, d.referral_code, d.date_add ORDER BY sales DESC, d.id_dealer DESC',
			[$idParent]
		) ?: [];
	}

	/** @return array{customers:int,sub_dealers:int,sales:float,override:float} */
	public static function summary(int $idDealer): array
	{
		return [
			'customers' => (int) DB::getValue('SELECT COUNT(*) FROM rd_customers WHERE id_dealer = ?', [$idDealer]),
			'sub_dealers' => RdDealer::countSub($idDealer),
			'sales' => round((float) DB::getValue('SELECT COALESCE(SUM(order_total), 0) FROM rd_commissions WHERE id_dealer = ? AND type = ? AND status <> ?', [$idDealer, 'direct', 'cancelled']), 2),
			'override' => round((float) DB::getValue('SELECT COALESCE(SUM(amount), 0) FROM rd_commissions WHERE id_dealer = ? AND type = ? AND status <> ?', [$idDealer, 'override', 'cancelled']), 2),
		];
	}

	/** @return array<string,mixed> yönetici özeti */
	public static function platform(): array
	{
		return [
			'dealers' => RdDealer::countByStatus(),
			'customers' => (int) DB::getValue('SELECT COUNT(*) FROM rd_customers'),
			'sales' => round((float) DB::getValue('SELECT COALESCE(SUM(order_total), 0) FROM rd_commissions WHERE type = ? AND status <> ?', ['direct', 'cancelled']), 2),
			'pending' => round((float) DB::getValue('SELECT COALESCE(SUM(amount), 0) FROM rd_commissions WHERE status = ?', ['pending']), 2),
			'approved' => round((float) DB::getValue('SELECT COALESCE(SUM(amount), 0) FROM rd_commissions WHERE status = ?', ['approved']), 2),
		];
	}
}
