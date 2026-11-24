<?php
/**
 * Test harness: MySQL OLMADAN, SQLite üzerinde modül mantığını doğrular.
 * DİKKAT: Product/Category/Tax/Customer sınıfları burada KÜÇÜK TAKLİTLERDİR (stub).
 * Gerçek Product::save, Customer::login vb. bu testlerde çalışmaz; bunlar canlıya benzer bir MySQL kurulumunda ayrıca denenmelidir.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('IN_SCRIPT', true);
define('XH_TEST', true);
define('XH_STORAGE_DIR', sys_get_temp_dir() . '/xh_test_' . getmypid());

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
}
class Customer {
	public static function isLoggedIn(): bool { return !empty($_SESSION['id_user']); }
	public static function getId(): int { return (int) ($_SESSION['id_user'] ?? 0); }
	public static function as(int $id): void { $_SESSION['id_user'] = $id; }
	public static function logout(): void { unset($_SESSION['id_user']); }
}
class Module { public static array $hooks = []; public static function runHook($n, $a = []) { self::$hooks[] = $n; } }
class Tax { public static function getDefaultRate() { return 20.0; } }
class Category { public static function getProductSelectOptions() { return DB::execute('SELECT id_category, category_name FROM categories WHERE active = 1') ?: []; } }
class ProductVariation {
	public static function getByProduct($id) { return []; }
	public static function getByProductSku($id, $sku) { $r = DB::execute('SELECT * FROM product_variations WHERE id_product = ? AND sku = ?', [$id, $sku]); return $r[0] ?? null; }
	public static function patchPriceStock($idv, $d) { foreach (['price', 'stock'] as $k) { if (isset($d[$k])) { DB::execute("UPDATE product_variations SET $k = ? WHERE id_variation = ?", [(float) $d[$k], $idv]); } } return ['success' => true]; }
}
class Product { // TAKLİT
	public static function save(array $d, int $id = 0) {
		if ($id > 0) {
			DB::update('products', ['product_name' => $d['product_name'], 'price' => $d['price'], 'stock' => $d['stock'], 'active' => $d['active'], 'description' => strip_tags((string) ($d['description'] ?? ''), '<p><b>')], 'id_product = :pid', ['pid' => $id]);
			return ['success' => true, 'id' => $id];
		}
		if (!empty($d['force_fail'])) { return ['success' => false, 'message' => 'kayıt hatası']; }
		$new = DB::insert('products', ['product_name' => $d['product_name'], 'id_category' => $d['id_category'], 'id_brand' => $d['id_brand'], 'price' => $d['price'], 'stock' => $d['stock'], 'stock_code' => $d['stock_code'] ?? '', 'barcode' => $d['barcode'] ?? '', 'description' => strip_tags((string) ($d['description'] ?? ''), '<p><b>'), 'active' => $d['active']]);
		if ($new && !empty($d['variations'])) { foreach ($d['variations'] as $v) { DB::execute('INSERT INTO product_variations (id_product, sku, price, stock) VALUES (?,?,?,?)', [$new, $v['sku'], (float) $v['price'], (float) $v['stock']]); } }
		return ['success' => (bool) $new, 'id' => (int) $new];
	}
	public static function deleteById(int $id) { DB::execute('DELETE FROM products WHERE id_product = ?', [$id]); return ['success' => true]; }
	public static function getByIdAdmin(int $id) { return DB::getRowSafe('products', 'id_product = ?', [$id]) ?: null; }
	public static function getImages(int $id) { return []; }
	public static function getImageUrl(int $id) { return '/img/products/' . $id . '.jpg'; }
	public static function uploadImage(int $id, array $f) { return ['success' => true]; }
	public static function deleteImage(int $id) { DB::execute('DELETE FROM images WHERE id_image = ?', [$id]); return ['success' => true]; }
	public static function setCover(int $id) { return ['success' => true]; }
	public static function importImageBinary(int $id, string $bin) { if (strncmp($bin, 'IMG', 3) !== 0) { return ['success' => false, 'message' => 'görsel değil']; } DB::execute('INSERT INTO images (id_product, cover) VALUES (?, 0)', [$id]); return ['success' => true]; }
}

class RafimdaVendorModule { public const VERSION = 'test'; }

$rv = dirname(__DIR__, 2) . '/rafimda-vendor/lib/';
foreach (['RvMigrator', 'RvPerm', 'RvAudit', 'RvVendor', 'RvContext', 'RvProducts'] as $c) {
	require_once $rv . $c . '.php';
}
foreach (['XhUrlGuard', 'XhHttp', 'XhXml', 'XhMapper', 'XhMigrator', 'XhOwner', 'XhStorage', 'XhRepo', 'XhSink', 'XhImporter', 'XhRunner', 'XhPanel'] as $c) {
	require_once dirname(__DIR__) . '/lib/' . $c . '.php';
}

function xh_mysql_to_sqlite(string $sql): string
{
	$sql = preg_replace('/\)\s*ENGINE=.*?;/s', ');', $sql);
	$sql = preg_replace('/^\s*UNIQUE KEY\s+`[^`]+`\s*(\([^)]*\)),?\s*$/m', 'UNIQUE $1,', $sql);
	$sql = preg_replace('/^\s*KEY\s+`[^`]+`\s*\([^)]*\),?\s*$/m', '', $sql);
	$sql = preg_replace('/`(\w+)` (?:int\(11\)|bigint\(20\)) NOT NULL AUTO_INCREMENT,/', '`$1` INTEGER PRIMARY KEY AUTOINCREMENT,', $sql);
	$sql = preg_replace('/^\s*PRIMARY KEY \(`id_\w+`\),?\s*$/m', '', $sql);
	$sql = preg_replace('/,(\s*\))/', '$1', $sql);
	return $sql;
}

function xh_fresh_db(bool $withVendor = true): void
{
	global $db;
	$db = new PDO('sqlite::memory:');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	$GLOBALS['db'] = $db;
	$db->exec('CREATE TABLE rafimda_migrations (version TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
	$db->exec('CREATE TABLE xh_migrations (version TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
	$db->exec('CREATE TABLE users (id_user INTEGER PRIMARY KEY AUTOINCREMENT, user_full_name TEXT, email TEXT, active INTEGER DEFAULT 1)');
	$db->exec('CREATE TABLE categories (id_category INTEGER PRIMARY KEY, category_name TEXT, active INTEGER DEFAULT 1)');
	$db->exec('CREATE TABLE brands (id_brand INTEGER PRIMARY KEY, brand_name TEXT, active INTEGER DEFAULT 1)');
	$db->exec('CREATE TABLE products (id_product INTEGER PRIMARY KEY AUTOINCREMENT, product_name TEXT, id_category INT, id_brand INT, price REAL, old_price REAL DEFAULT 0, stock REAL, stock_code TEXT, barcode TEXT, description TEXT, desi INT DEFAULT 1, cargo_day INT DEFAULT 0, active INT)');
	$db->exec('CREATE TABLE images (id_image INTEGER PRIMARY KEY AUTOINCREMENT, id_product INT, cover INT DEFAULT 0)');
	$db->exec('CREATE TABLE product_variations (id_variation INTEGER PRIMARY KEY AUTOINCREMENT, id_product INT, sku TEXT, price REAL, stock REAL)');
	$db->exec("INSERT INTO categories VALUES (1,'Giyim',1),(2,'Ayakkabı',1)");
	$db->exec("INSERT INTO brands VALUES (1,'MarkaA',1),(2,'MarkaB',1)");
	foreach ([[1, 'Ali', 'ali@x.com'], [2, 'Veli', 'veli@x.com']] as $u) {
		$db->prepare('INSERT INTO users (id_user,user_full_name,email) VALUES (?,?,?)')->execute($u);
	}
	foreach (XhMigrator::files() as $f) {
		$db->exec(xh_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/migrations/' . $f . '.sql')));
	}
	if ($withVendor) {
		foreach (RvMigrator::files() as $f) {
			$db->exec(xh_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . '/rafimda-vendor/migrations/' . $f . '.sql')));
		}
	}
	RvContext::reset();
	$_SESSION = [];
	XhUrlGuard::$resolver = null;
	XhHttp::$transport = null;
}
