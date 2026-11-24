<?php

/** Satıcı raporları: yalnızca kendi sipariş verisi (rafimda_vendor_orders / kalemler). İptal ve iadeler satışa dahil edilmez. */
class RvReports
{
	/**
	 * @return array{days:int,orders:int,sales:float,commission:float,earning:float,avg:float,daily:list<array{day:string,orders:int,sales:float}>,top:list<array<string,mixed>>,status:array<string,int>}
	 */
	public static function summary(int $idVendor, int $days = 30): array
	{
		$days = max(1, min(365, $days));
		$since = date('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
		$rows = DB::execute("SELECT id_vendor_order, subtotal, commission_amount, vendor_earning, status, date_add FROM rafimda_vendor_orders WHERE id_vendor = ? AND date_add >= ?", [$idVendor, $since]) ?: [];
		$out = ['days' => $days, 'orders' => 0, 'sales' => 0.0, 'commission' => 0.0, 'earning' => 0.0, 'avg' => 0.0, 'daily' => [], 'top' => [], 'status' => []];
		$perDay = [];

		for ($i = $days - 1; $i >= 0; $i--) {
			$perDay[date('Y-m-d', time() - $i * 86400)] = ['day' => date('Y-m-d', time() - $i * 86400), 'orders' => 0, 'sales' => 0.0];
		}

		$valid = [];

		foreach ($rows as $r) {
			$st = (string) $r['status'];
			$out['status'][$st] = ($out['status'][$st] ?? 0) + 1;

			if (in_array($st, ['cancelled', 'returned'], true)) {
				continue;
			}

			$valid[] = (int) $r['id_vendor_order'];
			$out['orders']++;
			$out['sales'] += (float) $r['subtotal'];
			$out['commission'] += (float) $r['commission_amount'];
			$out['earning'] += (float) $r['vendor_earning'];
			$d = substr((string) $r['date_add'], 0, 10);

			if (isset($perDay[$d])) {
				$perDay[$d]['orders']++;
				$perDay[$d]['sales'] += (float) $r['subtotal'];
			}
		}

		foreach (['sales', 'commission', 'earning'] as $k) {
			$out[$k] = round($out[$k], 2);
		}

		$out['avg'] = $out['orders'] > 0 ? round($out['sales'] / $out['orders'], 2) : 0.0;
		$out['daily'] = array_values(array_map(static fn(array $d): array => ['day' => $d['day'], 'orders' => $d['orders'], 'sales' => round($d['sales'], 2)], $perDay));

		if ($valid !== []) {
			$in = implode(',', array_map('intval', $valid));
			$out['top'] = DB::execute("SELECT product_name, SUM(qty) AS qty, SUM(total) AS total FROM rafimda_vendor_order_items WHERE id_vendor_order IN ($in) GROUP BY id_product, product_name ORDER BY total DESC LIMIT 10") ?: [];
		}

		return $out;
	}
}
