<?php
/**
 * Test harness: MySQL OLMADAN, SQLite üzerinde modül mantığını doğrular.
 * DİKKAT: Product/Category/Tax/Customer sınıfları burada KÜÇÜK TAKLİTLERDİR (stub).
 * Gerçek Product::save, Customer::login vb. bu testlerde çalışmaz; bunlar canlıya benzer bir MySQL kurulumunda ayrıca denenmelidir.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('IN_SCRIPT', true);

$GLOBALS['db'] = new PDO('sqlite::memory:');
$GLOBALS['db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['domain'] = 'https://test.local/';

class DB // config/database.php ile aynı sözleşme
{
	public static function execute($sql, $params = [])
	{
		global $db;
		try {
			$stmt = $db->prepare($sql);
			$stmt->execute($params);
			return $stmt->columnCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : true;
		} catch (Exception $e) {
			error_log('Query error: ' . $e->getMessage());
			return false;
		}
	}
	public static function insert($table, $data)
	{
		global $db;
		$cols = array_keys($data);
		$sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')';
		try {
			$db->prepare($sql)->execute($data);
			return (int) $db->lastInsertId() ?: 1;
		} catch (Exception $e) {
			error_log('Insert error: ' . $e->getMessage());
			return false;
		}
	}
	public static function update($table, $data, $where, $whereParams = [])
	{
		global $db;
		$set = [];
		foreach ($data as $k => $v) {
			$set[] = '`' . $k . '` = :' . $k;
		}
		try {
			$st = $db->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where);
			$st->execute(array_merge($data, $whereParams));
			return $st->rowCount();
		} catch (Exception $e) {
			error_log('Update error: ' . $e->getMessage());
			return false;
		}
	}
	public static function getValue($sql, $params = [])
	{
		$r = self::execute($sql, $params);
		return ($r && isset($r[0])) ? reset($r[0]) : false;
	}
	public static function getRowSafe($table, $whereSql, $params = [])
	{
		$r = self::execute('SELECT * FROM `' . $table . '` WHERE ' . $whereSql . ' LIMIT 1', $params);
		return ($r && isset($r[0])) ? $r[0] : false;
	}
}

class Settings { public static array $d = []; public static function get($k) { return self::$d[$k] ?? ''; } public static function set($k, $v) { self::$d[$k] = $v; } }
class Tools { public static function getValue($k, $d = false) { return $_POST[$k] ?? $_GET[$k] ?? $d; } public static function isSubmit($k) { return isset($_POST[$k]) || isset($_GET[$k]); } }
class App { public static function sendNoStoreHeaders(): void {} }
class Security {
	public static function getCsrfToken($s = 'front') { return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(8)); }
	public static function requireCsrf($s = 'front') {}
	public static function validateCsrf($s = 'front') { return isset($_POST['token']) && hash_equals(self::getCsrfToken($s), (string) $_POST['token']); }
}
class Customer {
	public static function isLoggedIn(): bool { return !empty($_SESSION['id_user']); }
	public static function getId(): int { return (int) ($_SESSION['id_user'] ?? 0); }
	public static function as(int $id): void { $_SESSION['id_user'] = $id; }
	// Misafir başvuru akışı için FriSay Customer davranışının küçük taklidi (gerçek doğrulama kuralları core/Customer.php'de)
	public static function emailIsRegistered(string $e): bool { return (bool) DB::getValue('SELECT id_user FROM users WHERE email = ?', [strtolower(trim($e))]); }
	public static function login(string $e, string $p, bool $r = true): array { $id = (int) DB::getValue('SELECT id_user FROM users WHERE email = ?', [strtolower(trim($e))]); if ($id && $p === 'DogruSifre1!') { $_SESSION['id_user'] = $id; return ['success' => true]; } return ['success' => false, 'message' => 'E-posta veya şifre hatalı.']; }
	public static function register(string $n, string $ph, string $p, string $e = ''): array { if (trim($n) === '') { return ['success' => false, 'message' => 'Geçerli bir ad soyad girin']; } if (strlen($p) < 8) { return ['success' => false, 'message' => 'Şifre en az 8 karakter olmalı']; } $id = (int) DB::insert('users', ['user_full_name' => $n, 'email' => strtolower($e)]); if (!empty($GLOBALS['RV_TEST_ACTIVATE'])) { $_SESSION['id_user'] = $id; } return ['success' => true, 'id_user' => $id]; }
	public static function logout(): void { unset($_SESSION['id_user']); }
}
class Module { public static array $hooks = []; public static array $listeners = []; public static function runHook($n, $a = []) { self::$hooks[] = $n; foreach (self::$listeners[$n] ?? [] as $fn) { $fn(...$a); } return []; } public static function registerHook($n, $fn) { self::$listeners[$n][] = $fn; } public static function isEnabled($n) { return true; } }
class Tax { public static function getDefaultRate() { return 20.0; } }
class Category { public static function getProductSelectOptions() { return DB::execute('SELECT id_category, category_name FROM categories WHERE active = 1') ?: []; } }
class ProductVariation { public static function getByProduct($id) { return []; } }
class Product { // TAKLİT
	public static function save(array $d, int $id = 0) {
		if ($id > 0) {
			DB::update('products', ['product_name' => $d['product_name'], 'price' => $d['price'], 'stock' => $d['stock'], 'active' => $d['active']], 'id_product = :pid', ['pid' => $id]);
			return ['success' => true, 'id' => $id];
		}
		$new = DB::insert('products', ['product_name' => $d['product_name'], 'id_category' => $d['id_category'], 'id_brand' => $d['id_brand'], 'price' => $d['price'], 'stock' => $d['stock'], 'stock_code' => $d['stock_code'] ?? '', 'barcode' => $d['barcode'] ?? '', 'active' => $d['active']]);
		return ['success' => (bool) $new, 'id' => (int) $new];
	}
	public static function deleteById(int $id) { DB::execute('DELETE FROM products WHERE id_product = ?', [$id]); return ['success' => true]; }
	public static function getByIdAdmin(int $id) { return DB::getRowSafe('products', 'id_product = ?', [$id]) ?: null; }
	public static function getImages(int $id) { return []; }
	public static function getImageUrl(int $id) { return '/img/products/' . $id . '.jpg'; }
	public static function uploadImage(int $id, array $f) { return ['success' => true]; }
	public static function deleteImage(int $id) { DB::execute('DELETE FROM images WHERE id_image = ?', [$id]); return ['success' => true]; }
	public static function setCover(int $id) { return ['success' => true]; }
}
class RafimdaVendorModule { public const VERSION = 'test'; }

foreach (['RvMigrator', 'RvPerm', 'RvAudit', 'RvVendor', 'RvContext', 'RvProducts', 'RvInventory', 'RvCustomers', 'RvPlans', 'RvLedger', 'RvOrders', 'RvCommission', 'RvView', 'RvPrefs', 'RvReports', 'RvNotify', 'RvApplication', 'RvPrivacy', 'RvStorefront', 'RvQA', 'RvReviews', 'RvTickets', 'RvEngageAdmin'] as $c) {
	require_once dirname(__DIR__) . '/lib/' . $c . '.php';
}

/** MySQL DDL → SQLite çevirisi (yalnızca test içindir). */
function rv_mysql_to_sqlite(string $sql): string
{
	$sql = preg_replace('/\)\s*ENGINE=.*?;/s', ');', $sql);
	$sql = preg_replace('/^\s*UNIQUE KEY\s+`[^`]+`\s*(\([^)]*\)),?\s*$/m', 'UNIQUE $1,', $sql);
	$sql = preg_replace('/^\s*KEY\s+`[^`]+`\s*\([^)]*\),?\s*$/m', '', $sql);
	$sql = preg_replace('/`(\w+)` (?:int\(11\)|bigint\(20\)) NOT NULL AUTO_INCREMENT,/', '`$1` INTEGER PRIMARY KEY AUTOINCREMENT,', $sql);
	$sql = preg_replace('/^\s*PRIMARY KEY \(`(?:id_vendor|id_staff|id_log|id_vendor_order|id_item|id_entry|id_payout|id_import|id_plan|id_plan_log|id_campaign|id_enrollment|id_charge|id_application|id_doc|id_app_log|id_notification|id_document|id_version|id_acceptance|id_consent|id_follow|id_store_question|id_vote|id_store_review|id_ticket|id_ticket_message|id_invoice|id_event|id_account|id_post|id_metric|id_rule)`\),?\s*$/m', '', $sql);
	$sql = preg_replace('/,(\s*\))/', '$1', $sql);
	return $sql;
}

function rv_fresh_db(): void
{
	global $db;
	$db = new PDO('sqlite::memory:');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	$GLOBALS['db'] = $db;
	$db->exec('CREATE TABLE rafimda_migrations (version TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
	$db->exec('CREATE TABLE users (id_user INTEGER PRIMARY KEY AUTOINCREMENT, user_full_name TEXT, email TEXT, active INTEGER DEFAULT 1)');
	$db->exec('CREATE TABLE categories (id_category INTEGER PRIMARY KEY, category_name TEXT, active INTEGER DEFAULT 1)');
	$db->exec('CREATE TABLE brands (id_brand INTEGER PRIMARY KEY, brand_name TEXT, active INTEGER DEFAULT 1)');
	$db->exec('CREATE TABLE products (id_product INTEGER PRIMARY KEY AUTOINCREMENT, product_name TEXT, id_category INT, id_brand INT, price REAL, old_price REAL DEFAULT 0, stock REAL, stock_code TEXT, barcode TEXT, active INT)');
	$db->exec('CREATE TABLE images (id_image INTEGER PRIMARY KEY AUTOINCREMENT, id_product INT, cover INT DEFAULT 0)');
	$db->exec('CREATE TABLE orders (id_order INTEGER PRIMARY KEY, id_user INT DEFAULT 0, reference TEXT, status INT, date_add TEXT, customer_name TEXT, customer_phone TEXT, address_city TEXT, address_district TEXT, address_text TEXT)');
	$db->exec('CREATE TABLE order_detail (id_order_detail INTEGER PRIMARY KEY AUTOINCREMENT, id_order INT, id_product INT, product_name TEXT, price REAL, total REAL, qty REAL)');
	$db->exec("INSERT INTO categories VALUES (1,'Giyim',1),(2,'Pasif Kat',0)");
	$db->exec("INSERT INTO brands VALUES (1,'MarkaA',1)");
	foreach ([[1, 'Ali', 'ali@x.com'], [2, 'Veli', 'veli@x.com'], [3, 'Ayse', 'ayse@x.com'], [4, 'Fatma', 'fatma@x.com']] as $u) {
		$db->prepare('INSERT INTO users (id_user,user_full_name,email) VALUES (?,?,?)')->execute($u);
	}
	RvContext::reset();
	$_SESSION = [];
}

/** Tüm migration'ları (çeviriyle) uygular. */
function rv_apply_migrations(): void
{
	foreach (RvMigrator::files() as $f) {
		$GLOBALS['db']->exec(rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/migrations/' . $f . '.sql')));
		DB::execute('INSERT OR IGNORE INTO rafimda_migrations (version) VALUES (?)', [$f]);
	}
}
