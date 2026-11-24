<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
/**
 * Seed testi: GERÇEK FriSay çekirdek şeması (install/schema.sql, SQLite'a çevrilmiş) + gerçek config/database.php (DB sınıfı)
 * + gerçek Rafımda modül sınıfları. Product/Customer/Security/oturum burada YOK (seed bunları kullanmaz).
 * MySQL'de çalıştırılmamıştır.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('IN_SCRIPT', true);
$root = dirname(__DIR__, 3);
foreach (['StagingSql', 'StagingModules', 'StagingMigrator', 'StagingSeed'] as $c) {
	require_once dirname(__DIR__) . '/lib/' . $c . '.php';
}
require_once __DIR__ . '/sqlite_support.php';

$pass = 0;
$fail = 0;
function t(string $name, bool $ok, string $detail = ''): void
{
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
}

$pdo = new SqliteMysqlPDO();
$GLOBALS['db'] = $pdo;
$GLOBALS['domain'] = 'https://test.rafimda.com.tr/';

// Gerçek çekirdek şeması
$schema = (string) file_get_contents($root . '/install/schema.sql');
$need = ['settings', 'categories', 'brands', 'products', 'images', 'users', 'orders', 'order_detail', 'modules', 'module_display_hooks', 'admins'];
$made = 0;
foreach (StagingSql::statements($schema) as $st) {
	if (preg_match('/^CREATE TABLE\s+`?(\w+)`?/i', $st, $m) && in_array($m[1], $need, true)) {
		$pdo->exec($st . ' ENGINE=InnoDB');
		$made++;
	}
}
t('gerçek çekirdek tabloları oluşturuldu (' . $made . '/' . count($need) . ')', $made === count($need));

$col = static function (string $t, string $c) use ($pdo): bool {
	foreach ($pdo->query('PRAGMA table_info(`' . $t . '`)')->fetchAll() as $r) {
		if ($r['name'] === $c) {
			return true;
		}
	}

	return false;
};
// Çekirdek bu tabloyu ProductVariation::ensureSchema() ile (SHOW TABLES, MySQL'e özgü) ilk kullanımda üretir; testte asgari hâli.
$pdo->exec('CREATE TABLE product_variations (id_variation INTEGER PRIMARY KEY AUTOINCREMENT, id_product INT, active INT DEFAULT 1, stock REAL DEFAULT 0)');
$mig = new StagingMigrator($pdo, $col);
$ok = true;
foreach (StagingModules::all() as $m) {
	$ok = $ok && $mig->runModule($m)['success'];
}
t('modül migration\'ları gerçek şema üzerinde uygulandı', $ok);

require_once $root . '/config/database.php';
require_once $root . '/core/autoload.php';
// Gerçek açılış (config/settings.php) SQLite'ta çalıştırılamaz; Settings yalnızca bu testte taklittir.
class Settings { public static array $d = []; public static function get($k) { return self::$d[$k] ?? ''; } public static function set($k, $v) { self::$d[$k] = $v; } }
foreach (['RvMigrator', 'RvPerm', 'RvAudit', 'RvVendor', 'RvContext', 'RvProducts', 'RvInventory', 'RvCustomers', 'RvPlans', 'RvLedger', 'RvOrders'] as $c) {
	require_once $root . '/modules/rafimda-vendor/lib/' . $c . '.php';
}

// TEST OLMAYAN (gerçek gibi) kayıtlar: seed bunlara dokunmamalı.
$pdo->exec("INSERT INTO users (user_full_name, phone, email, password) VALUES ('Gercek Musteri', '05320000000', 'gercek@example.com', 'x')");
$pdo->exec("INSERT INTO categories (category_name, category_link) VALUES ('Gercek Kategori', 'gercek-kategori')");
$pdo->exec("INSERT INTO brands (brand_name, brand_link) VALUES ('Gercek Marka', 'gercek-marka')");
$pdo->exec("INSERT INTO products (id_category, id_brand, product_name, description, product_link, price, stock_code) VALUES (1, 1, 'Gercek Urun', '', 'gercek-urun', 99, 'GERCEK-1')");
$pdo->exec("INSERT INTO orders (id_user, reference, payment_method, customer_name, customer_phone, address_city, address_district, address_text, note, subtotal, total) VALUES (1, 'REF-REAL-1', 'kart', 'Gercek Musteri', '05320000000', 'X', 'Y', 'Z', '', 99, 99)");
$pdo->exec("INSERT INTO order_detail (id_order, id_product, product_name, price, qty, total) VALUES (1, 1, 'Gercek Urun', 99, 1, 99)");
$pdo->exec("INSERT INTO rafimda_vendors (store_name, store_slug, owner_user_id, status) VALUES ('Gercek Magaza', 'gercek-magaza', 1, 'active')");
$snap = static function () use ($pdo): string {
	$o = [];
	foreach (['users' => "phone NOT LIKE 'TEST-%'", 'categories' => "category_link NOT LIKE 'test-%'", 'brands' => "brand_link NOT LIKE 'test-%'", 'products' => "stock_code NOT LIKE 'TEST-%'", 'orders' => "reference NOT LIKE 'TEST-%'", 'rafimda_vendors' => "store_slug NOT LIKE 'test-%'"] as $tb => $w) {
		$o[$tb] = $pdo->query("SELECT * FROM `$tb` WHERE $w ORDER BY 1")->fetchAll();
	}
	$o['detail'] = $pdo->query('SELECT * FROM order_detail WHERE id_order = 1')->fetchAll();
	$o['vendor_products'] = $pdo->query('SELECT * FROM rafimda_vendor_products WHERE id_product = 1')->fetchAll();
	$o['vendor_orders'] = $pdo->query('SELECT * FROM rafimda_vendor_orders WHERE id_order = 1')->fetchAll();

	return json_encode($o);
};
$realBefore = $snap();
t('seed öncesi TEST olmayan sipariş sayılır (bin bu durumda reddeder)', StagingSeed::realOrderCount($pdo) === 1);
$r = StagingSeed::run();
t('seed TEST OLMAYAN kayıtlara dokunmadı (kullanıcı/kategori/marka/ürün/sipariş/satıcı/kalem birebir aynı)', $snap() === $realBefore);
$nonTestVendorOrders = (int) $pdo->query('SELECT COUNT(*) FROM rafimda_vendor_orders WHERE id_order = 1')->fetchColumn();
t('gerçek siparişe satıcı siparişi/komisyon kaydı yazılmadı', $nonTestVendorOrders === 0);
echo implode("\n", array_map(static fn($l) => '    ' . $l, $r['log'])) . "\n";
t('3 satıcı oluşturuldu', count($r['vendors']) === 3 && !in_array(0, $r['vendors'], true), json_encode($r['vendors']));
t('her satıcıya 4 ürün', array_sum(array_map('count', $r['products'])) === 12 && !in_array(0, array_merge(...array_values($r['products'])), true));
t('3 TEST siparişi', count($r['orders']) === 3 && !in_array(0, $r['orders'], true));
$vo = (int) $pdo->query('SELECT COUNT(*) FROM rafimda_vendor_orders')->fetchColumn();
t('sipariş bölme: 5 satıcı siparişi (A+B, B+C, A)', $vo === 5, (string) $vo);
$bad = array_filter($r['checks'], static fn(array $c): bool => !$c['ok']);
t('izolasyon/komisyon/kota kontrolleri (' . count($r['checks']) . ' adet) hepsi geçti', count($r['checks']) > 40 && !$bad, json_encode(array_values($bad), JSON_UNESCAPED_UNICODE));

// TEST işaretleri
t('seed\'in eklediği tüm kullanıcılar TEST işaretli', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id_user > 1 AND (phone NOT LIKE 'TEST-%' OR email NOT LIKE '%@test.rafimda.invalid')")->fetchColumn() === 0);
t('seed\'in eklediği tüm ürünler/siparişler TEST işaretli', (int) $pdo->query("SELECT COUNT(*) FROM products WHERE id_product > 1 AND stock_code NOT LIKE 'TEST-%'")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE id_order > 1 AND reference NOT LIKE 'TEST-%'")->fetchColumn() === 0);

// Idempotans
$before = [];
foreach (['users', 'products', 'orders', 'order_detail', 'rafimda_vendors', 'rafimda_vendor_orders', 'rafimda_vendor_products'] as $tb) {
	$before[$tb] = (int) $pdo->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn();
}
$r2 = StagingSeed::run();
$same = true;
foreach ($before as $tb => $n) {
	$same = $same && (int) $pdo->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn() === $n;
}
t('seed ikinci kez çalışınca kayıt çoğalmaz (idempotent)', $same);
t('ikinci çalıştırmada da TEST olmayan kayıtlar aynı', $snap() === $realBefore);
t('ikinci çalıştırmada da kontroller geçer', !array_filter($r2['checks'], static fn(array $c): bool => !$c['ok']));

// Sızıntı negatif kontrolü: izolasyon kontrolü gerçekten yakalıyor mu? (bilerek bozuyoruz)
$pdo->exec('UPDATE rafimda_vendor_products SET id_vendor = ' . (int) $r['vendors']['B'] . ' WHERE id_product = ' . (int) $r['products']['A'][0]);
$broken = StagingSeed::isolationChecks($r['vendors'], $r['products'], $r['orders']);
t('bozulmuş sahiplikte izolasyon kontrolü FAIL verir (testin kendisi doğrulandı)', (bool) array_filter($broken, static fn(array $c): bool => !$c['ok']));

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
