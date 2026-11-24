<?php

/**
 * Çekirdek siparişin satıcılara bölünmesi, satıcı sipariş yaşam döngüsü ve komisyon.
 * Çekirdek `orders` / `order_detail` tablolarına YAZILMAZ; yalnızca okunur. Sistemi bozmamak için
 * hook dinleyicileri hata verse bile siparişi engellemez (bkz. safeHook).
 */
class RvOrders
{
	public const STATUSES = [
		'pending' => 'Beklemede',
		'preparing' => 'Hazırlanıyor',
		'shipped' => 'Kargoda',
		'delivered' => 'Teslim edildi',
		'cancelled' => 'İptal',
		'returned' => 'İade',
	];

	/** Satıcının kendisinin geçebileceği durumlar (teslim/iptal/iade çekirdek sipariş durumundan gelir). */
	public const VENDOR_SETTABLE = ['preparing', 'shipped'];

	public static function defaultCommission(): float
	{
		$v = Settings::get('RAFIMDA_DEFAULT_COMMISSION');

		return $v === '' ? 10.0 : max(0.0, min(100.0, (float) $v));
	}

	public static function holdDays(): int
	{
		$v = Settings::get('RAFIMDA_PAYOUT_HOLD_DAYS');

		return $v === '' ? 7 : max(0, min(90, (int) $v));
	}

	/** Kampanyasız taban oran: satıcıya özel oran varsa o, yoksa platform varsayılanı. */
	public static function baseCommissionFor(array $vendor): float
	{
		$r = (float) ($vendor['commission_rate'] ?? 0);

		return $r > 0 ? min(100.0, $r) : self::defaultCommission();
	}

	/**
	 * Sipariş komisyon oranı. rafimda-campaign etkinse kampanya (örn. düşük komisyon) uygulanır; hata/yoksa taban oran.
	 * $forOrder=false: yalnızca gösterim (otomatik katılım yapmaz, sayaç artırmaz).
	 */
	public static function commissionRateFor(array $vendor, bool $forOrder = false): float
	{
		$base = self::baseCommissionFor($vendor);

		return class_exists('RcCampaign', false) ? RcCampaign::rateFor($vendor, $base, $forOrder) : $base;
	}

	/** Hook dinleyicilerini sarar: satıcı kodu hata verirse sipariş akışı etkilenmez. */
	public static function safeHook(callable $fn): void
	{
		try {
			$fn();
		} catch (Throwable $e) {
			error_log('rafimda-vendor hook: ' . $e->getMessage());
		}
	}

	/**
	 * order.placed: siparişi satıcı bazında ayırır. İdempotent (UNIQUE id_order+id_vendor).
	 *
	 * @param array<string,mixed> $order
	 * @return int oluşturulan satıcı siparişi sayısı
	 */
	public static function splitOrder(array $order): int
	{
		$idOrder = (int) ($order['id_order'] ?? 0);

		if ($idOrder <= 0) {
			return 0;
		}

		$lines = DB::execute(
			'SELECT od.id_order_detail, od.id_product, od.product_name, od.qty, od.price, od.total, vp.id_vendor
			FROM order_detail od INNER JOIN rafimda_vendor_products vp ON vp.id_product = od.id_product
			WHERE od.id_order = ? ORDER BY od.id_order_detail ASC',
			[$idOrder]
		) ?: [];

		$byVendor = [];

		foreach ($lines as $l) {
			$byVendor[(int) $l['id_vendor']][] = $l;
		}

		$created = 0;

		foreach ($byVendor as $idVendor => $vLines) {
			if (DB::getRowSafe('rafimda_vendor_orders', 'id_order = ? AND id_vendor = ?', [$idOrder, $idVendor])) {
				continue;
			}

			$vendor = RvVendor::get($idVendor);

			if (!$vendor) {
				continue;
			}

			$subtotal = RvLedger::money(array_sum(array_map(static fn(array $l): float => (float) $l['total'], $vLines)));
			$rate = self::commissionRateFor($vendor, true);
			$commission = RvLedger::money($subtotal * $rate / 100);
			$earning = RvLedger::money($subtotal - $commission);

			try {
				RvLedger::tx(static function () use ($idOrder, $idVendor, $vLines, $subtotal, $rate, $commission, $earning): void {
					$idVo = RvLedger::must(DB::insert('rafimda_vendor_orders', [
						'id_order' => $idOrder,
						'id_vendor' => $idVendor,
						'subtotal' => $subtotal,
						'commission_rate' => $rate,
						'commission_amount' => $commission,
						'vendor_earning' => $earning,
					]), 'satıcı siparişi');

					foreach ($vLines as $l) {
						RvLedger::must(DB::insert('rafimda_vendor_order_items', [
							'id_vendor_order' => $idVo,
							'id_order_detail' => (int) $l['id_order_detail'],
							'id_product' => (int) $l['id_product'],
							'product_name' => mb_substr((string) $l['product_name'], 0, 190),
							'qty' => (float) $l['qty'],
							'unit_price' => RvLedger::money($l['price']),
							'total' => RvLedger::money($l['total']),
						]), 'sipariş kalemi');
					}

					RvLedger::add($idVendor, (int) $idVo, 'sale', $earning, null, 'sale:vo' . $idVo, 'Sipariş #' . $idOrder . ' (komisyon %' . $rate . ')');
					RvAudit::log($idVendor, 'order.split', 'vendor_order', (int) $idVo, ['id_order' => $idOrder, 'earning' => $earning]);
				});
				$created++;

				if (class_exists('RcCampaign', false)) {
					RcCampaign::commitUse($idVendor);
				}
			} catch (Throwable $e) {
				error_log('rafimda splitOrder: ' . $e->getMessage());

				if (class_exists('RcCampaign', false)) {
					RcCampaign::discardUse($idVendor);
				}
			}
		}

		return $created;
	}

	/**
	 * order.updated: çekirdek sipariş durumuna göre bakiye serbest bırakma / iade-iptal düzeltmesi.
	 *
	 * @param array<string,mixed> $order güncel sipariş
	 */
	public static function onCoreOrderUpdated(array $order, int $oldStatus = 0): void
	{
		$idOrder = (int) ($order['id_order'] ?? 0);
		$status = (int) ($order['status'] ?? 0);

		if ($idOrder <= 0 || $status === $oldStatus) {
			return;
		}

		$vos = DB::execute('SELECT * FROM rafimda_vendor_orders WHERE id_order = ?', [$idOrder]) ?: [];

		foreach ($vos as $vo) {
			$idVo = (int) $vo['id_vendor_order'];
			$idVendor = (int) $vo['id_vendor'];

			if ($status === 4 && !in_array($vo['status'], ['cancelled', 'returned', 'delivered'], true)) {
				$availableAt = date('Y-m-d H:i:s', time() + self::holdDays() * 86400);
				DB::execute('UPDATE rafimda_ledger SET available_at = ? WHERE ref_key = ? AND available_at IS NULL', [$availableAt, 'sale:vo' . $idVo]);
				DB::update('rafimda_vendor_orders', ['status' => 'delivered', 'date_upd' => date('Y-m-d H:i:s')], 'id_vendor_order = :id', ['id' => $idVo]);
				RvAudit::log($idVendor, 'order.delivered', 'vendor_order', $idVo, ['available_at' => $availableAt]);
			} elseif (in_array($status, [5, 6], true) && !in_array($vo['status'], ['cancelled', 'returned'], true)) {
				self::reverseVendorOrder($vo, $status === 5 ? 'cancelled' : 'returned');
			}
		}
	}

	/** Satıcı siparişini iptal/iade eder ve kazancı defterde ters kayıtla düzeltir (tek sefer). */
	private static function reverseVendorOrder(array $vo, string $newStatus): void
	{
		$idVo = (int) $vo['id_vendor_order'];
		$idVendor = (int) $vo['id_vendor'];
		$sale = DB::getRowSafe('rafimda_ledger', 'ref_key = ?', [ 'sale:vo' . $idVo ]);

		try {
			RvLedger::tx(static function () use ($vo, $idVo, $idVendor, $sale, $newStatus): void {
				if ($sale) {
					$released = !empty($sale['available_at']);
					RvLedger::add(
						$idVendor, $idVo, 'refund', -((float) $sale['amount']),
						$released ? date('Y-m-d H:i:s') : null,
						'refund:vo' . $idVo,
						'Sipariş #' . $vo['id_order'] . ' ' . self::STATUSES[$newStatus] . ' düzeltmesi'
					);
				}

				RvLedger::must(DB::update('rafimda_vendor_orders', ['status' => $newStatus, 'date_upd' => date('Y-m-d H:i:s')], 'id_vendor_order = :id', ['id' => $idVo]), 'durum');
				RvAudit::log($idVendor, 'order.' . $newStatus, 'vendor_order', $idVo);
			});
		} catch (Throwable $e) {
			error_log('rafimda reverse: ' . $e->getMessage());
		}
	}

	/** @return list<array<string,mixed>> */
	public static function listFor(int $idVendor, string $status = '', int $limit = 30, int $offset = 0): array
	{
		$sql = 'SELECT vo.*, o.reference, o.date_add AS order_date, o.customer_name, o.address_city, o.status AS core_status,
				(SELECT COUNT(*) FROM rafimda_vendor_order_items i WHERE i.id_vendor_order = vo.id_vendor_order) AS line_count
			FROM rafimda_vendor_orders vo INNER JOIN orders o ON o.id_order = vo.id_order WHERE vo.id_vendor = ?';
		$params = [$idVendor];

		if ($status !== '' && isset(self::STATUSES[$status])) {
			$sql .= ' AND vo.status = ?';
			$params[] = $status;
		}

		return DB::execute($sql . ' ORDER BY vo.id_vendor_order DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset), $params) ?: [];
	}

	/** @return array<string,mixed>|null sipariş + kalemler; başka satıcının kaydı için null (IDOR koruması) */
	public static function getFor(int $idVendor, int $idVendorOrder): ?array
	{
		$vo = DB::execute(
			'SELECT vo.*, o.reference, o.date_add AS order_date, o.customer_name, o.customer_phone, o.address_city, o.address_district, o.address_text, o.status AS core_status
			FROM rafimda_vendor_orders vo INNER JOIN orders o ON o.id_order = vo.id_order
			WHERE vo.id_vendor_order = ? AND vo.id_vendor = ? LIMIT 1',
			[$idVendorOrder, $idVendor]
		);

		if (!$vo || !isset($vo[0])) {
			return null;
		}

		$vo = $vo[0];
		$vo['items'] = DB::execute('SELECT * FROM rafimda_vendor_order_items WHERE id_vendor_order = ? ORDER BY id_item ASC', [$idVendorOrder]) ?: [];

		return $vo;
	}

	/**
	 * Satıcı kargo/hazırlık güncellemesi. Geçişler: pending→preparing|shipped, preparing→shipped, shipped→shipped (takip düzeltme).
	 *
	 * @return array{success:bool, message:string}
	 */
	public static function updateFulfillment(int $idVendor, int $idVendorOrder, string $newStatus, string $cargo, string $tracking): array
	{
		$vo = self::getFor($idVendor, $idVendorOrder);

		if (!$vo) {
			return ['success' => false, 'message' => 'Sipariş bulunamadı.'];
		}

		if (!in_array($newStatus, self::VENDOR_SETTABLE, true)) {
			return ['success' => false, 'message' => 'Bu durumu yalnızca sistem belirler.'];
		}

		$allowed = ['pending' => ['preparing', 'shipped'], 'preparing' => ['shipped'], 'shipped' => ['shipped']];

		if (!in_array($newStatus, $allowed[$vo['status']] ?? [], true)) {
			return ['success' => false, 'message' => 'Bu siparişin durumu artık değiştirilemez.'];
		}

		$cargo = mb_substr(trim(strip_tags($cargo)), 0, 64);
		$tracking = trim($tracking);

		if ($newStatus === 'shipped') {
			if ($cargo === '' || !preg_match('/^[A-Za-z0-9\-_.\/ ]{4,64}$/', $tracking)) {
				return ['success' => false, 'message' => 'Kargo firması ve geçerli bir takip numarası (4-64 karakter) girin.'];
			}
		} else {
			$tracking = (string) $vo['tracking_number'];
			$cargo = (string) $vo['cargo_company'];
		}

		DB::update('rafimda_vendor_orders', [
			'status' => $newStatus,
			'cargo_company' => $cargo,
			'tracking_number' => $tracking,
			'date_upd' => date('Y-m-d H:i:s'),
		], 'id_vendor_order = :id AND id_vendor = :vid', ['id' => $idVendorOrder, 'vid' => $idVendor]);
		RvAudit::log($idVendor, 'order.' . $newStatus, 'vendor_order', $idVendorOrder, ['cargo' => $cargo, 'tracking' => $tracking]);

		return ['success' => true, 'message' => 'Sipariş güncellendi.'];
	}

	/** Ana yönetici listesi (tüm satıcılar). @return list<array<string,mixed>> */
	public static function adminList(string $status = '', int $limit = 100): array
	{
		$sql = 'SELECT vo.*, v.store_name, o.reference FROM rafimda_vendor_orders vo
			INNER JOIN rafimda_vendors v ON v.id_vendor = vo.id_vendor INNER JOIN orders o ON o.id_order = vo.id_order';
		$params = [];

		if ($status !== '' && isset(self::STATUSES[$status])) {
			$sql .= ' WHERE vo.status = ?';
			$params[] = $status;
		}

		return DB::execute($sql . ' ORDER BY vo.id_vendor_order DESC LIMIT ' . max(1, min(300, $limit)), $params) ?: [];
	}

	/** @return array{orders:int, gross:float, commission:float, earning:float} */
	public static function platformTotals(): array
	{
		$r = DB::execute(
			"SELECT COUNT(*) AS c, COALESCE(SUM(subtotal),0) AS g, COALESCE(SUM(commission_amount),0) AS cm, COALESCE(SUM(vendor_earning),0) AS e
			FROM rafimda_vendor_orders WHERE status NOT IN ('cancelled','returned')"
		)[0] ?? [];

		return ['orders' => (int) ($r['c'] ?? 0), 'gross' => RvLedger::money($r['g'] ?? 0), 'commission' => RvLedger::money($r['cm'] ?? 0), 'earning' => RvLedger::money($r['e'] ?? 0)];
	}
}
