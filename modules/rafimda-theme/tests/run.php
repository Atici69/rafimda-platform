<?php
/** rafimda-theme: modül yükleme + mantık testleri (gerçek core/ModuleBase.php ile; gerçek tema/DB olmadan). */
error_reporting(E_ALL);
define('IN_SCRIPT', true);
class DB { public static function execute() { return true; } }
class Settings { public static array $s = []; public static function get($k) { return self::$s[$k] ?? ''; } public static function set($k, $v) { self::$s[$k] = $v; } }
class Module { public static array $en = []; public static function isEnabled($n) { return in_array($n, self::$en, true); } public static function isInstalled($n) { return in_array($n, self::$en, true); } }
require dirname(__DIR__, 3) . '/core/ModuleBase.php';
$fail = 0; $pass = 0;
function t(bool $c, string $n): void { global $fail, $pass; if ($c) { $pass++; } echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) { $fail++; } }

$name = 'rafimda-theme';
require dirname(__DIR__) . '/' . $name . '.php';
t(class_exists('RafimdaThemeModule'), 'sınıf adı kurala uyuyor');
$m = new RafimdaThemeModule();
t($m instanceof ModuleBase && $m->name === $name, 'ModuleBase türevi, name = klasör adı');
t($m->install() === true && $m->uninstall() === true && !file_exists(dirname(__DIR__) . '/install.sql'), 'tablo/migration yok (kurulum canlı DB şeması değiştirmez)');
$themeHooks = ['main_menu','mobile_menu','footer','footer_menu','home','home_slider','home_bottom','head.top','auth_login','auth_register','cart'];
foreach (array_keys($m->displayHooks) as $h) { t(in_array($h, $themeHooks, true), "kanca theme4 tarafından çizilen listede: $h"); }
t($m->defaultDisplayHooks === array_keys($m->displayHooks), 'tüm kancalar varsayılan bağlı');
foreach ($m->frontStylesheets as $css) { t(is_file(dirname(__DIR__) . '/assets/css/' . $css), "CSS var: $css"); }
foreach (['hero','links_footer','links_mobile','auth_hint'] as $tpl) { t(is_file(dirname(__DIR__) . "/assets/templates/front/$tpl.tpl"), "şablon var: $tpl"); }
t(is_file(dirname(__DIR__) . '/assets/templates/admin/admin.tpl'), 'admin şablonu var');
$css = file_get_contents(dirname(__DIR__) . '/assets/css/rafimda-theme.css');
t(strpos($css, 'html:root') !== false && strpos($css, '--primary: var(--rt-navy)') !== false && strpos($css, '@media (max-width: 600px)') !== false, 'CSS: marka değişkenleri ezilir, mobil kırılım var');

// Satıcı modülü kapalıyken bağlantı kancaları null (ölü link üretmez)
Module::$en = [];
t($m->renderDisplayHook('footer') === null && $m->renderDisplayHook('mobile_menu') === null && $m->renderDisplayHook('auth_register') === null, 'rafimda-vendor kapalı → satıcı bağlantısı yok');
t($m->renderDisplayHook('product') === null, 'ilgisiz kanca null');
t(RafimdaThemeModule::vendorEnabled() === false, 'vendorEnabled false');
Module::$en = ['rafimda-vendor'];
t(RafimdaThemeModule::vendorEnabled() === true, 'vendorEnabled true');
$v = RafimdaThemeModule::viewVars();
t(substr($v['rtLoginUrl'], -12) === 'satici-giris' && substr($v['rtRegisterUrl'], -12) === 'satici-kayit', 'bağlantılar vendor modülünün gerçek rotalarına işaret eder');
t($v['rtTitle'] === 'Aradığınız Her Şey, Rafımda!', 'varsayılan slogan');
// vendor rotaları gerçekten tanımlı mı
$vsrc = file_get_contents(dirname(__DIR__, 2) . '/rafimda-vendor/rafimda-vendor.php');
t(strpos($vsrc, 'satici-giris') !== false && strpos($vsrc, 'satici-kayit') !== false, 'rafimda-vendor satici-giris/satici-kayit rotalarını tanımlıyor');

// Ayar kaydı: HTML temizlenir, uzunluk sınırlanır
RafimdaThemeModule::saveSettings(['hero' => '1', 'title' => '<script>x</script>Merhaba ' . str_repeat('a', 300), 'subtitle' => '<b>alt</b>']);
t(strpos(Settings::get('RAFIMDA_THEME_TITLE'), '<') === false && mb_strlen(Settings::get('RAFIMDA_THEME_TITLE')) <= 120, 'başlık etiketsiz ve ≤120');
t(Settings::get('RAFIMDA_THEME_SUBTITLE') === 'alt' && Settings::get('RAFIMDA_THEME_FOOTER_LINKS') === '0', 'alt metin temizlendi; işaretlenmeyen kutu 0');
// Şablonlar: kullanıcıya özgü içerik yok (sayfa önbelleği güvenli), çıktı kaçışlı
foreach (glob(dirname(__DIR__) . '/assets/templates/front/*.tpl') as $f) {
	$s = file_get_contents($f);
	t(!preg_match('/\$(customer|user|session|_SESSION|csrf|token)/i', $s), 'kullanıcıya özgü değişken yok: ' . basename($f));
	preg_match_all('/\{\$[a-zA-Z]+(?:\.[a-zA-Z]+)?(\|[^}]*)?\}/', $s, $mm);
	$bad = array_filter($mm[0], fn($x) => strpos($x, '|escape') === false);
	t($bad === [], 'tüm çıktılar |escape: ' . basename($f));
}
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n"; exit($fail ? 1 : 0);
