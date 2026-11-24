<?php

/**
 * Satıcıya özel müşteri görünümü: yalnızca BU satıcının alt siparişlerinden türetilir.
 * Gizlilik: e-posta ve telefon listelenmez (kargo için gerekli iletişim bilgisi sipariş detayındadır). Veri başka satıcıya sızmaz.
 */
class RvCustomers
{
	/**
	 * @return array{rows:list<array<string,mixed>>, total:int}
	 */
	public static function listFor(int $idVendor, string $q, int $limit, int $offset): array
	{
		$where = "vo.id_vendor = ? AND vo.status <> 'cancelled'";
		$params = [$idVendor];
		$q = trim($q);

		if ($q !== '') {
			$where .= ' AND o.customer_name LIKE ?';
			$params[] = '%' . str_replace(['\\', '%', '_'], '', mb_substr($q, 0, 60)) . '%';
		}

		$from = "FROM rafimda_vendor_orders vo JOIN orders o ON o.id_order = vo.id_order WHERE $where";
		$total = (int) DB::getValue("SELECT COUNT(*) FROM (SELECT o.id_user, o.customer_name $from GROUP BY o.id_user, o.customer_name) x", $params);
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$rows = DB::execute("SELECT o.id_user, o.customer_name, MAX(o.address_city) AS city, COUNT(*) AS orders, SUM(vo.subtotal) AS spent, MAX(o.date_add) AS last_order $from GROUP BY o.id_user, o.customer_name ORDER BY last_order DESC LIMIT $limit OFFSET $offset", $params) ?: [];

		return ['rows' => $rows, 'total' => $total];
	}

	/** @return array{customers:int, repeat:int, orders:int, revenue:float} */
	public static function summary(int $idVendor): array
	{
		$r = DB::execute("SELECT o.id_user, o.customer_name, COUNT(*) AS n, SUM(vo.subtotal) AS s FROM rafimda_vendor_orders vo JOIN orders o ON o.id_order = vo.id_order WHERE vo.id_vendor = ? AND vo.status <> 'cancelled' GROUP BY o.id_user, o.customer_name", [$idVendor]) ?: [];
		$out = ['customers' => count($r), 'repeat' => 0, 'orders' => 0, 'revenue' => 0.0];

		foreach ($r as $x) {
			$out['repeat'] += (int) $x['n'] > 1 ? 1 : 0;
			$out['orders'] += (int) $x['n'];
			$out['revenue'] += (float) $x['s'];
		}

		return $out;
	}
}
