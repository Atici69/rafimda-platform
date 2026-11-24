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

// ---- 0.2.0: ana sayfa bölümleri ----
echo "\n== Ana sayfa bölümleri (0.2.0) ==\n";
class Tools { public static string $c = ''; public static function getValue($k, $d = '') { return $k === 'container' ? self::$c : $d; } }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = ['token' => 'tok123', 'hero' => '1', 'footer' => '1', 'sections' => '1', 'banner' => [
	0 => ['title' => 'Kış <b>İndirimi</b>', 'text' => 'Fırsatlar', 'link' => '/search?q=kazak', 'image' => 'javascript:alert(1)', 'cta' => 'İncele'],
	1 => ['title' => '', 'text' => 'başlıksız', 'link' => '/x'],
	2 => ['title' => 'Dış', 'link' => 'https://example.com/k', 'image' => '/img/b.jpg'],
	3 => ['title' => 'Kötü', 'link' => '//evil.com', 'image' => "/a\"onerror=x"],
]];
$_SERVER['REQUEST_METHOD'] = 'POST'; $m->adminPage(); $_SERVER['REQUEST_METHOD'] = 'GET';
$bn = RafimdaThemeModule::banners();
t(count($bn) === 3, 'başlıksız banner atlanır');
t($bn[0]['title'] === 'Kış İndirimi' && $bn[0]['image'] === '' && $bn[0]['link'] === '/search?q=kazak', 'banner: etiket temizlenir, javascript: görsel reddedilir');
t($bn[1]['link'] === 'https://example.com/k' && $bn[1]['image'] === '/img/b.jpg', 'banner: https ve yerel yol kabul');
t($bn[2]['link'] === '' && $bn[2]['image'] === '', 'banner: //evil.com ve tırnaklı görsel reddedilir');

$vars = RafimdaThemeModule::viewVars() + ['rtCategories' => [], 'rtSections' => [], 'rtBanners' => $bn, 'rtShowPromo' => true];
$vars['rtSections'] = [['key' => 'new', 'title' => 'Yeni Ürünler', 'url' => '', 'products' => [['url' => '/p/1', 'image_url' => '/img/p1.jpg', 'product_name' => 'Ürün <script>', 'has_discount' => true, 'old_price_formatted' => '₺200', 'price_formatted' => '₺150']]]];
Module::$en = ['rafimda-vendor', 'rafimda-dealer'];
$html = $m->renderFrontTemplate('home_bottom', RafimdaThemeModule::viewVars() + $vars);
t(str_contains($html, 'SATIŞLARINIZI BÜYÜTMEK İÇİN YENİ ADRESİNİZ RAFIMDA!') && str_contains($html, 'Satıcımız Olmak İster misiniz?') && str_contains($html, 'satici-kayit'), 'satıcı bandı: tam metin + CTA + /satici-kayit');
t(str_contains($html, 'RAFIMDA BAYİSİ OLMAK İSTER MİSİNİZ?') && str_contains($html, 'Rafımda ailesine katılın, kendi bayi ağınızı oluşturun ve birlikte büyüyelim.') && str_contains($html, 'BAYİ BAŞVURUSU YAP') && str_contains($html, 'bayi-basvuru') && str_contains($html, 'bayi-giris'), 'bayi bandı: metin + buton + bağlantılar');
t(str_contains($html, '₺150') && str_contains($html, '/p/1') && str_contains($html, 'id="rt-urunler"'), 'ürün kartı gerçek veriyle çizilir');
t(!str_contains($html, '<script>') && str_contains($html, 'Ürün &lt;script&gt;'), 'ürün adı kaçışlanır');
Module::$en = [];
$html = $m->renderFrontTemplate('home_bottom', RafimdaThemeModule::viewVars() + $vars);
t(!str_contains($html, 'SATIŞLARINIZI') && !str_contains($html, 'BAYİSİ'), 'modüller kapalıyken satıcı/bayi bandı yok');
$vars['rtSections'] = []; $vars['rtBanners'] = [];
$html = trim($m->renderFrontTemplate('home_bottom', RafimdaThemeModule::viewVars() + $vars));
t($html === '', 'ürün/banner/modül yoksa hiçbir şey çizilmez (sahte içerik yok)');

Tools::$c = 'product';
t($m->renderDisplayHook('home_bottom') === null && $m->renderDisplayHook('home_slider') === null, 'ana sayfa dışında hook çıktısı yok');
Tools::$c = '';
$hero = $m->renderFrontTemplate('hero', RafimdaThemeModule::viewVars() + ['rtCategories' => [['name' => 'Giyim', 'url' => '/c/giyim', 'image' => '', 'initial' => 'G']]]);
t(str_contains($hero, 'rt-cat__ph') && str_contains($hero, '/c/giyim'), 'kategori şeridi: görselsiz kategori harf yer tutucu ile');
Module::$en = ['rafimda-dealer'];
$f = $m->renderDisplayHook('footer');
t($f !== null && str_contains($f, 'bayi-giris') && !str_contains($f, 'satici-giris'), 'altbilgi: yalnızca bayi etkinken bayi bağlantıları');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
