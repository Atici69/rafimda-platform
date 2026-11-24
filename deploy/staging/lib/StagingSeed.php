<?php

/**
 * Staging test verisi. YALNIZCA EKLER (silme/güncelleme yok), tekrar çalıştırılabilir (işaretçilerle idempotent).
 * Tüm kayıtlar TEST olarak işaretlidir: e-posta alanı @test.rafimda.invalid, telefon TEST-… ile başlar, ürün kodu TEST-…, sipariş referansı TEST-….
 * Gerçek müşteri verisi, gerçek e-posta/SMS adresi veya gerçek ödeme kullanılmaz.
 *
 * Gereksinim: DB sınıfı ($GLOBALS['db'] bağlı) ve Rafımda modül sınıfları (RvVendor, RvPlans, RvOrders, RvProducts, RvInventory, RvCustomers) yüklü olmalı.
 */
final class StagingSeed
{
	public const EMAIL_DOMAIN = 'test.rafimda.invalid';
	public const PLAN_CODE = 'test-sinirli';

	/** @return array{vendors:array<string,int>,products:array<string,list<int>>,orders:list<int>,log:list<string>,checks:list<array{name:string,ok:bool}>} */
	public static function run(): array
	{
		$log = [];
		$vendors = [];
		$products = [];
		$orders = [];

		RvPlans::ensureDefaults();
		RvPlans::save(['code' => self::PLAN_CODE, 'name' => 'TEST Sınırlı Paket', 'price_monthly' => 0, 'max_products' => 5, 'max_xml_connections' => 1, 'max_marketplace_accounts' => 1, 'active' => 1, 'sort_order' => 900]);

		$cat = self::ensureCategory();
		$brand = self::ensureBrand();

		foreach (['A', 'B', 'C'] as $i => $k) {
			$owner = self::ensureUser('TEST Satıcı ' . $k, 'satici-' . strtolower($k), 'TEST-S' . ($i + 1));
			$existing = DB::getRowSafe('rafimda_vendors', 'owner_user_id = ?', [$owner]);

			if ($existing) {
				$vendors[$k] = (int) $existing['id_vendor'];
				$log[] = 'Satıcı ' . $k . ' zaten var (id ' . $vendors[$k] . ').';
			} else {
				$v = RvVendor::validateStore(['store_name' => 'TEST Mağaza ' . $k, 'email' => 'magaza-' . strtolower($k) . '@' . self::EMAIL_DOMAIN, 'city' => 'Test', 'description' => 'TEST verisi — gerçek mağaza değildir.']);
				$r = RvVendor::create($owner, $v['clean'], true);
				$vendors[$k] = (int) $r['id_vendor'];
				$log[] = 'Satıcı ' . $k . ': ' . ($r['success'] ? 'oluşturuldu (id ' . $vendors[$k] . ')' : 'HATA: ' . $r['message']);
			}

			for ($n = 1; $n <= 4; $n++) {
				$products[$k][] = self::ensureProduct($vendors[$k], 'TEST-' . $k . '-' . $n, 'TEST Ürün ' . $k . $n, 100 + $n * 10 + $i * 5, 20 + $n, $cat, $brand);
			}
		}

		if (!empty($vendors['C'])) {
			$a = RvPlans::assign($vendors['C'], self::PLAN_CODE, null, 0, 'TEST: kota denemesi');
			$log[] = 'Satıcı C paketi: ' . ($a['success'] ? self::PLAN_CODE : 'HATA: ' . ($a['message'] ?? ''));
		}

		$customers = [];

		foreach ([1, 2, 3] as $n) {
			$customers[$n] = self::ensureUser('TEST Müşteri ' . $n, 'musteri-' . $n, 'TEST-M' . $n);
		}

		// Sipariş 1: A+B (bölünmeli) · Sipariş 2: B+C · Sipariş 3: yalnız A
		$defs = [
			1 => [$customers[1], [[$products['A'][0], 2], [$products['B'][0], 1]]],
			2 => [$customers[2], [[$products['B'][1], 3], [$products['C'][0], 1]]],
			3 => [$customers[3], [[$products['A'][1], 1]]],
		];

		foreach ($defs as $n => [$idUser, $lines]) {
			$orders[] = $idOrder = self::ensureOrder('TEST-ORD-' . $n, $idUser, $lines);
			$log[] = 'Sipariş TEST-ORD-' . $n . ' (id ' . $idOrder . ') bölündü: ' . RvOrders::splitOrder(['id_order' => $idOrder]) . ' yeni satıcı siparişi.';
		}

		return ['vendors' => $vendors, 'products' => $products, 'orders' => $orders, 'log' => $log, 'checks' => self::isolationChecks($vendors, $products, $orders)];
	}

	/** @return list<array{name:string,ok:bool}> */
	public static function isolationChecks(array $vendors, array $products, array $orders): array
	{
		$c = [];
		$add = static function (string $name, bool $ok) use (&$c): void {
			$c[] = ['name' => $name, 'ok' => $ok];
		};

		foreach ($vendors as $vk => $vid) {
			foreach ($products as $pk => $ids) {
				foreach ($ids as $pid) {
					$add('RvProducts::owns satıcı ' . $vk . ' → ürün ' . $pk . '#' . $pid . ($vk === $pk ? ' (kendi)' : ' (başkası)'), RvProducts::owns($vid, $pid) === ($vk === $pk));
				}
			}

			$list = RvInventory::listProducts($vid, '', '', 100, 0)['rows'];
			$foreign = array_filter($list, static fn(array $r): bool => !in_array((int) $r['id_product'], $products[$vk], true));
			$add('Stok listesi satıcı ' . $vk . ': yalnız kendi ürünleri (' . count($list) . ')', count($list) === count($products[$vk]) && !$foreign);

			$own = RvOrders::listFor($vid, '', 100, 0);
			$ownIds = array_map(static fn(array $r): int => (int) $r['id_vendor_order'], $own);
			$add('Sipariş listesi satıcı ' . $vk . ': tüm satırlar kendisine ait', !array_filter($own, static fn(array $r): bool => (int) $r['id_vendor'] !== $vid));

			foreach ($vendors as $ok => $oid) {
				if ($ok === $vk) {
					continue;
				}

				foreach (RvOrders::listFor($oid, '', 100, 0) as $row) {
					$add('RvOrders::getFor satıcı ' . $vk . ' → ' . $ok . ' siparişi #' . $row['id_vendor_order'] . ' erişilemez', RvOrders::getFor($vid, (int) $row['id_vendor_order']) === null && !in_array((int) $row['id_vendor_order'], $ownIds, true));
				}
			}
		}

		$total = 0.0;

		foreach ($vendors as $vk => $vid) {
			foreach (RvOrders::listFor($vid, '', 100, 0) as $row) {
				$expected = round((float) $row['subtotal'] * (float) $row['commission_rate'] / 100, 2);
				$add('Komisyon satıcı ' . $vk . ' sipariş #' . $row['id_vendor_order'] . ' = oran × ara toplam', abs($expected - (float) $row['commission_amount']) < 0.011 && abs((float) $row['subtotal'] - (float) $row['commission_amount'] - (float) $row['vendor_earning']) < 0.011);
				$total += (float) $row['subtotal'];
			}
		}

		$sum = 0.0;

		foreach ($orders as $idOrder) {
			$d = DB::execute('SELECT SUM(od.total) AS t FROM order_detail od INNER JOIN rafimda_vendor_products vp ON vp.id_product = od.id_product WHERE od.id_order = ?', [$idOrder]);
			$sum += (float) ($d[0]['t'] ?? 0);
		}

		$add('Bölünmüş satıcı siparişleri toplamı = sipariş kalemleri toplamı (' . number_format($total, 2, '.', '') . ')', abs($total - $sum) < 0.011);

		if (isset($vendors['A'], $products['A'])) {
			$cust = RvCustomers::listFor($vendors['A'], '', 100, 0);
			$add('Müşteri listesi satıcı A: yalnız A ürünü alan müşteriler (' . count($cust) . ')', count($cust) === 2);
		}

		if (isset($vendors['C'])) {
			$add('Satıcı C kota: 4 ürün + 1 = izinli', RvPlans::checkProductQuota($vendors['C'], 1)['ok'] === true);
			$add('Satıcı C kota: 4 ürün + 2 = reddedilir', RvPlans::checkProductQuota($vendors['C'], 2)['ok'] === false);
			$add('Satıcı A (sınırsız paket): kota engeli yok', RvPlans::checkProductQuota($vendors['A'], 50)['ok'] === true);
		}

		return $c;
	}

	private static function ensureCategory(): int
	{
		$r = DB::getRowSafe('categories', 'category_link = ?', ['test-kategori']);

		return $r ? (int) $r['id_category'] : (int) DB::insert('categories', ['category_name' => 'TEST Kategori', 'category_link' => 'test-kategori']);
	}

	private static function ensureBrand(): int
	{
		$r = DB::getRowSafe('brands', 'brand_link = ?', ['test-marka']);

		return $r ? (int) $r['id_brand'] : (int) DB::insert('brands', ['brand_name' => 'TEST Marka', 'brand_link' => 'test-marka']);
	}

	private static function ensureUser(string $name, string $slug, string $phone): int
	{
		$r = DB::getRowSafe('users', 'phone = ?', [$phone]);

		if ($r) {
			return (int) $r['id_user'];
		}

		// Kullanılabilir bir parola ÜRETİLMEZ/yazdırılmaz: giriş testi için parolayı yönetim panelinden ayrıca belirleyin.
		return (int) DB::insert('users', ['user_full_name' => $name, 'phone' => $phone, 'email' => $slug . '@' . self::EMAIL_DOMAIN, 'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
	}

	private static function ensureProduct(int $idVendor, string $code, string $name, float $price, float $stock, int $cat, int $brand): int
	{
		$r = DB::getRowSafe('products', 'stock_code = ?', [$code]);

		if ($r) {
			return (int) $r['id_product'];
		}

		$id = (int) DB::insert('products', [
			'id_category' => $cat, 'id_brand' => $brand, 'product_name' => $name, 'description' => 'TEST ürünü — gerçek ürün değildir.',
			'product_link' => strtolower($code), 'price' => $price, 'stock' => $stock, 'stock_code' => $code, 'barcode' => '',
		]);

		if ($id > 0) {
			DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $idVendor]);
		}

		return $id;
	}

	/** @param list<array{0:int,1:int|float}> $lines [id_product, adet] */
	private static function ensureOrder(string $reference, int $idUser, array $lines): int
	{
		$r = DB::getRowSafe('orders', 'reference = ?', [$reference]);

		if ($r) {
			return (int) $r['id_order'];
		}

		$u = DB::getRowSafe('users', 'id_user = ?', [$idUser]) ?: [];
		$rows = [];
		$subtotal = 0.0;

		foreach ($lines as [$pid, $qty]) {
			$p = DB::getRowSafe('products', 'id_product = ?', [$pid]);
			$total = round((float) $p['price'] * $qty, 2);
			$subtotal += $total;
			$rows[] = ['id_product' => $pid, 'product_name' => $p['product_name'], 'price' => $p['price'], 'qty' => $qty, 'total' => $total];
		}

		$id = (int) DB::insert('orders', [
			'id_user' => $idUser, 'reference' => $reference, 'payment_method' => 'test', 'customer_name' => (string) ($u['user_full_name'] ?? 'TEST'),
			'customer_phone' => (string) ($u['phone'] ?? 'TEST'), 'customer_email' => (string) ($u['email'] ?? ''), 'address_city' => 'Test', 'address_district' => 'Test',
			'address_text' => 'TEST adresi — gerçek adres değildir.', 'note' => 'TEST siparişi', 'subtotal' => $subtotal, 'total' => $subtotal,
		]);

		foreach ($rows as $row) {
			DB::insert('order_detail', ['id_order' => $id] + $row);
		}

		return $id;
	}
}
