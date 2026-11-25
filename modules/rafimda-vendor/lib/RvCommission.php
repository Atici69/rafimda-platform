<?php

/**
 * Komisyon kuralları ve kalem bazlı komisyon hesabı.
 *
 * Öncelik (ilk eşleşen kazanır):
 *   1. Ürün kuralı (scope=product)
 *   2. Kategori kuralı (scope=category) — ürünün kategorisi; "alt kategorileri kapsa" açıksa üst kategorilere doğru aranır
 *   3. Satıcıya özel oran (rafimda_vendors.commission_rate > 0)
 *   4. Platform varsayılanı (RAFIMDA_DEFAULT_COMMISSION)
 * Kampanya (rafimda-campaign) bulunan oranın ÜSTÜNE uygulanır ve oranı yalnızca düşürebilir.
 *
 * Satıcılardan mağaza/abonelik ücreti alınmaz; tek gelir kalemi gerçekleşen satışların komisyonudur.
 * Oran değişiklikleri yalnızca yeni siparişlere uygulanır: oran sipariş anında kaleme yazılır.
 */
class RvCommission
{
	public const SCOPES = ['product' => 'Ürün', 'category' => 'Kategori'];

	public const SOURCES = [
		'product' => 'Ürün kuralı',
		'category' => 'Kategori kuralı',
		'vendor' => 'Satıcıya özel oran',
		'default' => 'Genel oran',
		'campaign' => 'Kampanya',
	];

	/** Kategori ağacında en fazla bu kadar üste çıkılır (döngüye karşı). */
	private const MAX_DEPTH = 12;

	/** @var array<string, array<string,mixed>|null> istek içi önbellek */
	private static array $ruleCache = [];

	private static bool $ready = false;

	public static function reset(): void
	{
		self::$ruleCache = [];
		self::$ready = false;
		self::$hasParent = null;
	}

	public static function tableReady(): bool
	{
		// Yalnızca olumlu sonuç önbelleğe alınır: migration sonradan çalıştırılırsa aynı istekte de görülür.
		if (!self::$ready) {
			try {
				self::$ready = DB::execute('SELECT COUNT(*) AS c FROM rafimda_commission_rules') !== false;
			} catch (Throwable $e) {
				self::$ready = false;
			}
		}

		return self::$ready;
	}

	/** @return array<string,mixed>|null etkin kural */
	private static function rule(string $scope, int $idTarget): ?array
	{
		$k = $scope . ':' . $idTarget;

		if (!array_key_exists($k, self::$ruleCache)) {
			$r = self::tableReady() ? DB::getRowSafe('rafimda_commission_rules', 'scope = ? AND id_target = ? AND active = 1', [$scope, $idTarget]) : null;
			self::$ruleCache[$k] = is_array($r) && $r ? $r : null;
		}

		return self::$ruleCache[$k];
	}

	/** null: henüz bilinmiyor; false: kategori tablosunda id_parent yok (ağaçsız kurulum) */
	private static ?bool $hasParent = null;

	private static function parentOf(int $idCategory): int
	{
		if (self::$hasParent === false) {
			return 0;
		}

		$r = DB::execute('SELECT id_parent FROM categories WHERE id_category = ?', [$idCategory]);

		if ($r === false) {
			self::$hasParent = false;

			return 0;
		}

		self::$hasParent = true;

		return isset($r[0]['id_parent']) ? (int) $r[0]['id_parent'] : 0;
	}

	/**
	 * Kampanyasız taban oran ve kaynağı.
	 *
	 * @param array<string,mixed> $vendor
	 * @return array{rate:float, source:string, id_rule:int, id_category:int}
	 */
	public static function baseFor(array $vendor, int $idProduct, ?int $idCategory = null): array
	{
		if ($idProduct > 0 && ($r = self::rule('product', $idProduct))) {
			return ['rate' => self::clamp((float) $r['rate']), 'source' => 'product', 'id_rule' => (int) $r['id_rule'], 'id_category' => 0];
		}

		if ($idCategory === null && $idProduct > 0) {
			$c = DB::getValue('SELECT id_category FROM products WHERE id_product = ?', [$idProduct]);
			$idCategory = $c === false || $c === null ? 0 : (int) $c;
		}

		$cat = (int) $idCategory;
		$seen = [];

		for ($depth = 0; $cat > 0 && $depth < self::MAX_DEPTH && !isset($seen[$cat]); $depth++) {
			$seen[$cat] = true;
			$r = self::rule('category', $cat);

			if ($r && ($depth === 0 || (int) $r['include_children'] === 1)) {
				return ['rate' => self::clamp((float) $r['rate']), 'source' => 'category', 'id_rule' => (int) $r['id_rule'], 'id_category' => $cat];
			}

			$cat = self::parentOf($cat);
		}

		$v = (float) ($vendor['commission_rate'] ?? 0);

		if ($v > 0) {
			return ['rate' => self::clamp($v), 'source' => 'vendor', 'id_rule' => 0, 'id_category' => 0];
		}

		return ['rate' => RvOrders::defaultCommission(), 'source' => 'default', 'id_rule' => 0, 'id_category' => 0];
	}

	/**
	 * Sipariş kalemleri için komisyon. Her kalem: rate, amount, source. Toplamlar kuruş yuvarlamalı.
	 *
	 * @param array<string,mixed> $vendor
	 * @param list<array<string,mixed>> $lines id_product, total (ve isteğe bağlı id_category)
	 * @return array{lines:list<array{rate:float,amount:float,source:string}>, subtotal:float, commission:float, earning:float, rate:float}
	 */
	public static function forLines(array $vendor, array $lines, bool $forOrder = false): array
	{
		$out = ['lines' => [], 'subtotal' => 0.0, 'commission' => 0.0, 'earning' => 0.0, 'rate' => 0.0];
		$campaign = class_exists('RcCampaign', false);
		$first = true;

		foreach ($lines as $l) {
			$total = RvLedger::money($l['total'] ?? 0);
			$b = self::baseFor($vendor, (int) ($l['id_product'] ?? 0), isset($l['id_category']) ? (int) $l['id_category'] : null);
			$rate = $b['rate'];
			$source = $b['source'];

			if ($campaign) {
				// Kampanyaya katılım/sayaç yalnızca ilk kalemde işaretlenir; sonraki kalemler aynı kaydı okur.
				$c = RcCampaign::rateFor($vendor, $rate, $forOrder && $first);

				if ($c < $rate) {
					$rate = round($c, 2);
					$source = 'campaign';
				}
			}

			$first = false;
			$amount = RvLedger::money($total * $rate / 100);
			$out['lines'][] = ['rate' => $rate, 'amount' => $amount, 'source' => $source];
			$out['subtotal'] += $total;
			$out['commission'] += $amount;
		}

		$out['subtotal'] = RvLedger::money($out['subtotal']);
		$out['commission'] = RvLedger::money($out['commission']);
		$out['earning'] = RvLedger::money($out['subtotal'] - $out['commission']);
		$out['rate'] = $out['subtotal'] > 0 ? round($out['commission'] / $out['subtotal'] * 100, 2) : ($out['lines'][0]['rate'] ?? RvOrders::baseCommissionFor($vendor));

		return $out;
	}

	private static function clamp(float $r): float
	{
		return round(max(0.0, min(100.0, $r)), 2);
	}

	// ---------- Yönetim ----------

	/** @return list<array<string,mixed>> ad bilgisiyle kurallar */
	public static function rules(string $scope = ''): array
	{
		if (!self::tableReady()) {
			return [];
		}

		$sql = "SELECT r.*, CASE WHEN r.scope = 'product' THEN p.product_name ELSE c.category_name END AS target_name
			FROM rafimda_commission_rules r
			LEFT JOIN products p ON r.scope = 'product' AND p.id_product = r.id_target
			LEFT JOIN categories c ON r.scope = 'category' AND c.id_category = r.id_target";
		$params = [];

		if (isset(self::SCOPES[$scope])) {
			$sql .= ' WHERE r.scope = ?';
			$params[] = $scope;
		}

		return DB::execute($sql . ' ORDER BY r.scope ASC, target_name ASC, r.id_rule ASC', $params) ?: [];
	}

	/**
	 * Kural ekle / güncelle (scope + hedef benzersiz). Mevcut siparişler etkilenmez.
	 *
	 * @param array<string,mixed> $d scope, id_target, rate, include_children, active, note
	 * @return array{success:bool, message:string}
	 */
	public static function saveRule(array $d, int $idAdmin): array
	{
		if (!self::tableReady()) {
			return ['success' => false, 'message' => 'Önce bekleyen migration\'ı çalıştırın (008_commission_rules).'];
		}

		$scope = (string) ($d['scope'] ?? '');
		$id = (int) ($d['id_target'] ?? 0);
		$rateRaw = str_replace(',', '.', trim((string) ($d['rate'] ?? '')));

		if (!isset(self::SCOPES[$scope]) || $id <= 0) {
			return ['success' => false, 'message' => 'Kural türü ve hedef (ürün/kategori numarası) gerekli.'];
		}

		if ($rateRaw === '' || !is_numeric($rateRaw) || (float) $rateRaw < 0 || (float) $rateRaw > 100) {
			return ['success' => false, 'message' => 'Geçersiz oran (0-100).'];
		}

		$exists = $scope === 'product'
			? DB::getValue('SELECT COUNT(*) FROM products WHERE id_product = ?', [$id])
			: DB::getValue('SELECT COUNT(*) FROM categories WHERE id_category = ?', [$id]);

		if (!(int) $exists) {
			return ['success' => false, 'message' => ($scope === 'product' ? 'Ürün' : 'Kategori') . ' #' . $id . ' bulunamadı.'];
		}

		$row = [
			'rate' => round((float) $rateRaw, 2),
			'include_children' => !empty($d['include_children']) ? 1 : 0,
			'active' => !array_key_exists('active', $d) || !empty($d['active']) ? 1 : 0,
			'note' => mb_substr(trim(strip_tags((string) ($d['note'] ?? ''))), 0, 255),
			'id_admin' => $idAdmin,
			'date_upd' => date('Y-m-d H:i:s'),
		];
		$old = DB::getRowSafe('rafimda_commission_rules', 'scope = ? AND id_target = ?', [$scope, $id]);

		if ($old) {
			$ok = DB::update('rafimda_commission_rules', $row, 'id_rule = :id', ['id' => (int) $old['id_rule']]) !== false;
		} else {
			$ok = DB::insert('rafimda_commission_rules', $row + ['scope' => $scope, 'id_target' => $id]) !== false;
		}

		self::reset();

		if ($ok) {
			RvAudit::log(0, 'commission.rule', 'commission_rule', $old ? (int) $old['id_rule'] : 0, ['scope' => $scope, 'target' => $id, 'from' => $old ? (float) $old['rate'] : null, 'to' => $row['rate'], 'active' => $row['active']], $idAdmin);
		}

		return $ok
			? ['success' => true, 'message' => 'Komisyon kuralı kaydedildi (yalnızca yeni siparişlere uygulanır).']
			: ['success' => false, 'message' => 'Kural kaydedilemedi.'];
	}

	/** Kural kaydı silinmez, pasifleştirilir (geçmiş izlenebilir kalır). */
	public static function setActive(int $idRule, bool $active, int $idAdmin): bool
	{
		if (!self::tableReady() || !DB::getRowSafe('rafimda_commission_rules', 'id_rule = ?', [$idRule])) {
			return false;
		}

		$ok = DB::update('rafimda_commission_rules', ['active' => $active ? 1 : 0, 'id_admin' => $idAdmin, 'date_upd' => date('Y-m-d H:i:s')], 'id_rule = :id', ['id' => $idRule]) !== false;
		self::reset();

		if ($ok) {
			RvAudit::log(0, 'commission.rule_' . ($active ? 'on' : 'off'), 'commission_rule', $idRule, [], $idAdmin);
		}

		return $ok;
	}

	/** Satıcıya gösterilecek etkin kategori kuralları. @return list<array{category_name:string,rate:float,include_children:int}> */
	public static function publicCategoryRules(): array
	{
		if (!self::tableReady()) {
			return [];
		}

		return DB::execute(
			"SELECT c.category_name, r.rate, r.include_children FROM rafimda_commission_rules r
			INNER JOIN categories c ON c.id_category = r.id_target
			WHERE r.scope = 'category' AND r.active = 1 ORDER BY c.category_name ASC"
		) ?: [];
	}

	// ---------- Raporlar ----------

	private static function since(int $days): string
	{
		return date('Y-m-d 00:00:00', time() - (max(1, min(3650, $days)) - 1) * 86400);
	}

	/**
	 * Satıcı kazanç dökümü (dönem). Brüt = geçerli siparişlerin ara toplamı; iptal/iade edilenler ayrıca gösterilir.
	 * Net kazanç = brüt − komisyon − düzeltmeler (defter: satış + iade düzeltmesi + manuel düzeltme).
	 *
	 * @return array<string,mixed>
	 */
	public static function vendorStatement(int $idVendor, int $days = 30): array
	{
		$since = self::since($days);
		$rows = DB::execute('SELECT status, subtotal, commission_amount, vendor_earning FROM rafimda_vendor_orders WHERE id_vendor = ? AND date_add >= ?', [$idVendor, $since]) ?: [];
		$s = ['days' => $days, 'orders' => 0, 'gross' => 0.0, 'commission' => 0.0, 'cancelled_orders' => 0, 'cancelled_gross' => 0.0, 'cancelled_commission' => 0.0];

		foreach ($rows as $r) {
			if (in_array((string) $r['status'], ['cancelled', 'returned'], true)) {
				$s['cancelled_orders']++;
				$s['cancelled_gross'] += (float) $r['subtotal'];
				$s['cancelled_commission'] += (float) $r['commission_amount'];
				continue;
			}

			$s['orders']++;
			$s['gross'] += (float) $r['subtotal'];
			$s['commission'] += (float) $r['commission_amount'];
		}

		$adj = DB::getValue("SELECT COALESCE(SUM(amount),0) FROM rafimda_ledger WHERE id_vendor = ? AND entry_type = 'adjustment' AND date_add >= ?", [$idVendor, $since]);
		$s['adjustments'] = RvLedger::money($adj === false ? 0 : $adj);

		foreach (['gross', 'commission', 'cancelled_gross', 'cancelled_commission'] as $k) {
			$s[$k] = RvLedger::money($s[$k]);
		}

		$s['deductions'] = RvLedger::money($s['commission'] - min(0.0, $s['adjustments']));
		$s['net'] = RvLedger::money($s['gross'] - $s['commission'] + $s['adjustments']);
		$s['effective_rate'] = $s['gross'] > 0 ? round($s['commission'] / $s['gross'] * 100, 2) : 0.0;
		$s['by_source'] = self::bySource('vo.id_vendor = ? AND', [$idVendor], $since);

		return $s;
	}

	/**
	 * Yönetici komisyon geliri raporu (dönem): toplam, satıcıya göre, kategoriye göre, kaynağa göre.
	 *
	 * @return array<string,mixed>
	 */
	public static function platformReport(int $days = 30): array
	{
		$since = self::since($days);
		$t = DB::execute(
			"SELECT COUNT(*) AS c, COALESCE(SUM(subtotal),0) AS g, COALESCE(SUM(commission_amount),0) AS cm, COALESCE(SUM(vendor_earning),0) AS e
			FROM rafimda_vendor_orders WHERE status NOT IN ('cancelled','returned') AND date_add >= ?",
			[$since]
		)[0] ?? [];
		$lost = DB::execute(
			"SELECT COUNT(*) AS c, COALESCE(SUM(commission_amount),0) AS cm FROM rafimda_vendor_orders WHERE status IN ('cancelled','returned') AND date_add >= ?",
			[$since]
		)[0] ?? [];
		$gross = RvLedger::money($t['g'] ?? 0);
		$comm = RvLedger::money($t['cm'] ?? 0);

		return [
			'days' => $days,
			'orders' => (int) ($t['c'] ?? 0),
			'gross' => $gross,
			'commission' => $comm,
			'vendor_earning' => RvLedger::money($t['e'] ?? 0),
			'effective_rate' => $gross > 0 ? round($comm / $gross * 100, 2) : 0.0,
			'cancelled_orders' => (int) ($lost['c'] ?? 0),
			'cancelled_commission' => RvLedger::money($lost['cm'] ?? 0),
			'by_vendor' => DB::execute(
				"SELECT v.id_vendor, v.store_name, COUNT(*) AS orders, COALESCE(SUM(vo.subtotal),0) AS gross, COALESCE(SUM(vo.commission_amount),0) AS commission
				FROM rafimda_vendor_orders vo INNER JOIN rafimda_vendors v ON v.id_vendor = vo.id_vendor
				WHERE vo.status NOT IN ('cancelled','returned') AND vo.date_add >= ?
				GROUP BY v.id_vendor, v.store_name ORDER BY commission DESC LIMIT 50",
				[$since]
			) ?: [],
			'by_category' => DB::execute(
				"SELECT COALESCE(c.category_name, '—') AS category_name, COUNT(*) AS line_count, COALESCE(SUM(i.total),0) AS gross,
					COALESCE(SUM(i.commission_amount),0) AS commission
				FROM rafimda_vendor_order_items i
				INNER JOIN rafimda_vendor_orders vo ON vo.id_vendor_order = i.id_vendor_order
				LEFT JOIN products p ON p.id_product = i.id_product
				LEFT JOIN categories c ON c.id_category = p.id_category
				WHERE vo.status NOT IN ('cancelled','returned') AND vo.date_add >= ? AND i.commission_amount IS NOT NULL
				GROUP BY c.category_name ORDER BY commission DESC LIMIT 50",
				[$since]
			) ?: [],
			'by_source' => self::bySource('', [], $since),
		];
	}

	/** @param list<mixed> $params @return list<array{source:string,label:string,line_count:int,gross:float,commission:float}> */
	private static function bySource(string $where, array $params, string $since): array
	{
		$rows = DB::execute(
			"SELECT i.commission_source AS source, COUNT(*) AS line_count, COALESCE(SUM(i.total),0) AS gross, COALESCE(SUM(i.commission_amount),0) AS commission
			FROM rafimda_vendor_order_items i INNER JOIN rafimda_vendor_orders vo ON vo.id_vendor_order = i.id_vendor_order
			WHERE $where vo.status NOT IN ('cancelled','returned') AND vo.date_add >= ? AND i.commission_amount IS NOT NULL
			GROUP BY i.commission_source ORDER BY commission DESC",
			array_merge($params, [$since])
		) ?: [];

		return array_map(static fn(array $r): array => [
			'source' => (string) $r['source'],
			'label' => self::SOURCES[(string) $r['source']] ?? 'Eski sipariş',
			'line_count' => (int) $r['line_count'],
			'gross' => RvLedger::money($r['gross']),
			'commission' => RvLedger::money($r['commission']),
		], $rows);
	}
}
