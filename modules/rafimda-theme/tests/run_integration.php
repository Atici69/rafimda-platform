<?php
/** Entegrasyon testi: GERÇEK config/config.php Settings + core/ModuleBase.php + libs/Smarty; DB yerine SQLite kılıfı. Gerçek MySQL/theme4 değildir. */
error_reporting(E_ALL);
define('IN_SCRIPT', true);
$root = dirname(__DIR__, 3);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, value TEXT)');
class DB {
	public static PDO $p;
	public static function getValue($sql, $args = []) { $s = self::$p->prepare($sql); $s->execute($args); $r = $s->fetchColumn(); return $r === false ? false : $r; }
	public static function insert($t, $d) { $s = self::$p->prepare("INSERT INTO $t (" . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')'); return $s->execute(array_values($d)); }
	public static function update($t, $d, $where, $w = []) { $set = implode(',', array_map(fn($k) => "$k = :s_$k", array_keys($d))); $s = self::$p->prepare("UPDATE $t SET $set WHERE $where"); $a = $w; foreach ($d as $k => $v) { $a['s_' . $k] = $v; } return $s->execute($a); }
	public static function execute($sql, $a = []) { return []; }
}
DB::$p = $pdo;
class Module { public static array $en = []; public static function path($n) { return dirname(__DIR__, 2) . '/' . $n; } public static function registerHook($h, $f) {} public static function isEnabled($n) { return in_array($n, self::$en, true); } public static function isInstalled($n) { return in_array($n, self::$en, true); } }
class Admin { public static bool $demo = false; public static function isDemoMode() { return self::$demo; } }
require $root . '/config/config.php';
require $root . '/core/ModuleBase.php';
require $root . '/libs/Smarty.class.php';
$fail = 0; $pass = 0;
function t(bool $c, string $n): void { global $fail, $pass; if ($c) { $pass++; } echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) { $fail++; } }

$tmp = sys_get_temp_dir() . '/rt-smarty-' . getmypid();
@mkdir($tmp . '/c', 0777, true); @mkdir($tmp . '/t', 0777, true);
$smarty = new Smarty\Smarty();
$smarty->setCompileDir($tmp . '/c');
$smarty->setCacheDir($tmp . '/t');
$domain = 'https://rafimda.example/';
$adminToken = 'tok123';
require dirname(__DIR__) . '/rafimda-theme.php';
$m = new RafimdaThemeModule();

// 1) Ayar yaz → oku (gerçek Settings sınıfı)
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['token' => 'tok123', 'hero' => '1', 'footer' => '1', 'title' => 'Test <b>Başlık</b>', 'subtitle' => 'Alt "metin" & <i>x</i>'];
Module::$en = ['rafimda-vendor', 'xml-hub'];
$m->adminPage();
t(Settings::get('RAFIMDA_THEME_TITLE') === 'Test Başlık', 'POST sonrası başlık veritabanından okunur (etiketsiz)');
t(Settings::get('RAFIMDA_THEME_HERO') === '1' && Settings::get('RAFIMDA_THEME_FOOTER_LINKS') === '1', 'hero/bağlantı bayrakları kaydedildi');
$flash = $smarty->getTemplateVars('rtFlash');
t(is_array($flash) && $flash['type'] === 'success', 'başarı mesajı');
// Güncelleme (UPDATE yolu) + tekrar okuma
$_POST['title'] = 'Yeni Başlık'; $m->adminPage();
t(Settings::get('RAFIMDA_THEME_TITLE') === 'Yeni Başlık' && (int) DB::getValue('SELECT COUNT(*) FROM settings WHERE title = ?', ['RAFIMDA_THEME_TITLE']) === 1, 'ikinci kayıt günceller, satır çoğalmaz');
// Yanlış token / demo modu: yazmaz
$_POST['title'] = 'HACK'; $_POST['token'] = 'x'; $m->adminPage();
t(Settings::get('RAFIMDA_THEME_TITLE') === 'Yeni Başlık', 'yanlış CSRF token yazmaz');
$_POST['token'] = 'tok123'; Admin::$demo = true; $m->adminPage(); Admin::$demo = false;
t(Settings::get('RAFIMDA_THEME_TITLE') === 'Yeni Başlık', 'demo modunda yazmaz');
// GET: form değerleri geri okunur ve admin.tpl derlenir
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
$m->adminPage();
$html = $smarty->fetch('file:' . $m->getAdminTemplatePath('admin'));
t(strpos($html, 'value="Yeni Başlık"') !== false && strpos($html, 'name="token" value="tok123"') !== false, 'admin.tpl: kaydedilen değer forma geri gelir, token var');
t(preg_match('/<tr><td>Satıcı sistemi.*?<td>evet<\/td><td>evet<\/td>/s', $html) === 1 && preg_match('/Pazaryeri Hub.*?<b>hayır<\/b>/s', $html) === 1, 'admin.tpl: entegrasyon durumu doğru (vendor evet, marketplace-hub hayır)');

// 2) Ön yüz şablonları gerçek Smarty ile
$hero = $m->renderDisplayHook('home_slider');
t($hero !== null && strpos($hero, 'Yeni Başlık') !== false && strpos($hero, 'rt-hero') !== false && strpos($hero, 'https://rafimda.example/satici-kayit') !== false, 'hero çizilir, başlık ve Satıcı Ol bağlantısı var');
$ft = $m->renderDisplayHook('footer'); $mb = $m->renderDisplayHook('mobile_menu'); $ar = $m->renderDisplayHook('auth_register');
t(strpos((string) $ft, 'satici-giris') !== false && strpos((string) $mb, 'satici-giris') !== false && strpos((string) $ar, 'satici-kayit') !== false, 'alt bilgi/mobil menü/kayıt bağlantıları çizilir');
// XSS: başlık ayarı doğrudan DB'ye bozuk yazılsa bile kaçışlanır
Settings::set('RAFIMDA_THEME_TITLE', '<script>alert(1)</script>');
$h2 = (string) $m->renderDisplayHook('home_slider');
t(strpos($h2, '<script>') === false && strpos($h2, '&lt;script&gt;') !== false, 'şablon çıktıyı kaçışlar (XSS)');
// Ayarla kapat
Settings::set('RAFIMDA_THEME_HERO', '0'); Settings::set('RAFIMDA_THEME_FOOTER_LINKS', '0');
t($m->renderDisplayHook('home_slider') === null && $m->renderDisplayHook('footer') === null, 'ayar kapalıyken çıktı yok');
// Vendor kapalı → satıcı bağlantısı yok ama hero'da Satıcı Ol yok
Settings::set('RAFIMDA_THEME_HERO', '1'); Module::$en = [];
$h3 = (string) $m->renderDisplayHook('home_slider');
t($h3 !== '' && strpos($h3, 'satici-') === false, 'vendor kapalıyken hero var ama satıcı bağlantısı yok');
// Aynı çıktı iki çağrıda aynı (kullanıcıya göre değişmez → sayfa önbelleği güvenli)
t($m->renderDisplayHook('home_slider') === $m->renderDisplayHook('home_slider'), 'çıktı deterministik');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
