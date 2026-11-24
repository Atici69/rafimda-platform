<?php
/**
 * Test harness: MySQL OLMADAN, SQLite üzerinde bayi modülü mantığını ve gerçek Smarty şablonlarını doğrular.
 * Customer/Security/Tools/Module sınıfları KÜÇÜK TAKLİTLERDİR; gerçek FriSay oturum/CSRF/MySQL davranışı burada kanıtlanmaz.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('IN_SCRIPT', true);
$root = dirname(__DIR__, 3);
require_once $root . '/deploy/staging/lib/StagingSql.php';
require_once $root . '/deploy/staging/tests/sqlite_support.php';

$GLOBALS['db'] = new PDO('sqlite::memory:');
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
			error_log('Query error: ' . $e->getMessage() . ' :: ' . $sql);
			return false;
		}
	}
	public static function insert($table, $data)
	{
		global $db;
		$cols = array_keys($data);
		try {
			$db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')')->execute($data);
			return (int) $db->lastInsertId() ?: false;
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

class Settings { public static array $d = []; public static function get($k) { return self::$d[$k] ?? ''; } public static function set($k, $v) { self::$d[$k] = $v; return true; } }
class Tools { public static function getValue($k, $d = false) { return $_POST[$k] ?? $_GET[$k] ?? $d; } public static function isSubmit($k) { return isset($_POST[$k]) || isset($_GET[$k]); } }
class App { public static function sendNoStoreHeaders(): void {} }
class Admin { public static function isDemoMode() { return false; } public static function getId() { return 1; } }
class Security {
	public static array $csrfChecked = [];
	public static function getCsrfToken($s = 'front') { return 'csrf-test'; }
	public static function requireCsrf($s = 'front') { self::$csrfChecked[] = $s; }
}
class Customer {
	public static array $users = []; // email => ['id'=>, 'pass'=>]
	public static function isLoggedIn(): bool { return !empty($_SESSION['id_user']); }
	public static function getId(): int { return (int) ($_SESSION['id_user'] ?? 0); }
	public static function as(int $id): void { $_SESSION['id_user'] = $id; }
	public static function logout(): void { unset($_SESSION['id_user']); }
	public static function emailIsRegistered($e) { return isset(self::$users[$e]); }
	public static function login($l, $p, $remember = false) {
		if (isset(self::$users[$l]) && self::$users[$l]['pass'] === $p) { self::as(self::$users[$l]['id']); return ['success' => true]; }
		return ['success' => false, 'message' => 'E-posta veya şifre hatalı.'];
	}
	public static function register($name, $phone, $pass, $email) {
		$id = (int) DB::insert('users', ['user_full_name' => $name, 'email' => $email]);
		self::$users[$email] = ['id' => $id, 'pass' => $pass];
		self::as($id);
		return ['success' => true, 'id_user' => $id];
	}
}
class Module {
	public static array $hooks = [];
	public static function registerHook($n, $fn) { self::$hooks[$n][] = $fn; }
	public static function path($n) { return dirname(__DIR__, 2) . '/' . $n; }
	public static function isEnabled($n) { return true; }
	public static function isInstalled($n) { return true; }
	public static function fire($n, ...$a) { foreach (self::$hooks[$n] ?? [] as $fn) { $fn(...$a); } }
}

require_once $root . '/libs/Smarty.class.php';
$smarty = new Smarty\Smarty();
$__tmp = sys_get_temp_dir() . '/rd-smarty-' . getmypid();
@mkdir($__tmp . '/c', 0777, true);
$smarty->setCompileDir($__tmp . '/c');
$smarty->setCacheDir($__tmp . '/c');
$adminToken = 'admintok';
require_once $root . '/core/ModuleBase.php';
require_once dirname(__DIR__) . '/rafimda-dealer.php';
(new RafimdaDealerModule())->boot(); // order.placed / order.updated dinleyicilerini kaydeder

function rd_fresh_db(): void
{
	$db = new SqliteMysqlPDO();
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	$GLOBALS['db'] = $db;
	$db->exec('CREATE TABLE users (id_user INTEGER PRIMARY KEY AUTOINCREMENT, user_full_name TEXT, email TEXT, active INTEGER DEFAULT 1)');
	$db->exec('CREATE TABLE orders (id_order INTEGER PRIMARY KEY AUTOINCREMENT, id_user INT DEFAULT 0, status INT DEFAULT 1, total REAL DEFAULT 0, date_add TEXT DEFAULT CURRENT_TIMESTAMP)');
	foreach ([[1, 'Ali Admin', 'ali@x.com'], [2, 'Veli Bayi', 'veli@x.com'], [3, 'Ayşe Müşteri', 'ayse@x.com'], [4, 'Fatma Alt', 'fatma@x.com'], [5, 'Can Bayi2', 'can@x.com'], [6, 'Deniz Müşteri', 'deniz@x.com']] as $u) {
		$db->prepare('INSERT INTO users (id_user,user_full_name,email) VALUES (?,?,?)')->execute($u);
	}
	$_SESSION = [];
	$_POST = $_GET = $_COOKIE = [];
	Settings::$d = [];
	RdContext::reset();
}

function rd_install(): void
{
	$db = $GLOBALS['db'];
	$sql = file_get_contents(dirname(__DIR__) . '/install.sql');
	foreach (StagingSql::statements($sql) as $st) {
		$db->exec($st);
	}
	foreach (RdMigrator::files() as $f) {
		foreach (StagingSql::statements(file_get_contents(dirname(__DIR__) . '/migrations/' . $f . '.sql')) as $st) {
			$db->exec($st);
		}
		DB::execute('INSERT OR IGNORE INTO rd_migrations (version) VALUES (?)', [$f]);
	}
	RdPlans::ensureDefault();
}

$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function finish(): void { global $pass, $fail; echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n"; exit($fail ? 1 : 0); }
