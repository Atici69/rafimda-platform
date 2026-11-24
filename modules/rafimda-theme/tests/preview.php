<?php
/** (preview.php başlığı) Entegrasyon testi: GERÇEK config/config.php Settings + core/ModuleBase.php + libs/Smarty; DB yerine SQLite kılıfı. Gerçek MySQL/theme4 değildir. */
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
// ===== ÖNİZLEME ÜRETİCİ: gerçek şablonlar + ÖRNEK VERİ. Canlı veri/gerçek ürün DEĞİLDİR. =====
$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/');
@mkdir($out, 0777, true);
$svg = static function (string $label, string $c1, string $c2): string {
	$label = htmlspecialchars($label, ENT_XML1);
	$s = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/></linearGradient></defs><rect width="400" height="400" fill="url(#g)"/><text x="200" y="215" font-family="sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">' . $label . '</text><text x="200" y="250" font-family="sans-serif" font-size="16" fill="#fff" fill-opacity=".85" text-anchor="middle">ÖRNEK GÖRSEL</text></svg>';
	return 'data:image/svg+xml;base64,' . base64_encode($s);
};
$pal = [['#0a2540', '#1d4f91'], ['#ff7a1a', '#ffb066'], ['#0f766e', '#34d399'], ['#7c3aed', '#c4b5fd'], ['#be123c', '#fb7185'], ['#334155', '#94a3b8']];
$cats = [];
foreach (['Giyim', 'Ayakkabı', 'Elektronik', 'Ev & Yaşam', 'Kozmetik', 'Spor', 'Oyuncak', 'Kitap'] as $i => $n) {
	$cats[] = ['name' => $n, 'url' => '#', 'image' => $i % 3 === 2 ? '' : $svg($n, $pal[$i % 6][0], $pal[$i % 6][1]), 'initial' => mb_substr($n, 0, 1)];
}
$prod = static function (int $i, string $n, float $p, float $old = 0) use ($svg, $pal): array {
	return ['url' => '#', 'image_url' => $svg(mb_substr($n, 0, 14), $pal[$i % 6][0], $pal[$i % 6][1]), 'product_name' => $n, 'has_discount' => $old > 0, 'old_price_formatted' => $old > 0 ? number_format($old, 2, ',', '.') . ' ₺' : '', 'price_formatted' => number_format($p, 2, ',', '.') . ' ₺'];
};
$secs = [
	['key' => 'new', 'title' => 'Yeni Ürünler', 'url' => '', 'products' => [$prod(0, 'Pamuklu Tişört', 149.9), $prod(1, 'Spor Ayakkabı', 899), $prod(2, 'Kablosuz Kulaklık', 1299.5), $prod(3, 'Seramik Kupa Seti', 219), $prod(4, 'Sırt Çantası', 459), $prod(5, 'Termos 500 ml', 189)]],
	['key' => 'deals', 'title' => 'Fırsat Ürünleri', 'url' => '', 'products' => [$prod(1, 'Kot Pantolon', 299, 449), $prod(2, 'Akıllı Saat', 1499, 1999), $prod(3, 'Yoga Matı', 129, 189), $prod(4, 'Masa Lambası', 249, 349)]],
	['key' => 'best', 'title' => 'Çok Satanlar', 'url' => '', 'products' => [$prod(5, 'Bluetooth Hoparlör', 599), $prod(0, 'Deri Cüzdan', 349), $prod(1, 'Parfüm 50 ml', 799), $prod(2, 'Koşu Ayakkabısı', 1099), $prod(3, 'Yastık', 159), $prod(4, 'Powerbank', 429)]],
];
$bn = [
	['title' => 'Kış Koleksiyonu', 'text' => 'Yeni sezon ürünlerde fırsatlar', 'link' => '/kategori/giyim', 'image' => $svg('', '#0a2540', '#12365f'), 'cta' => 'İncele'],
	['title' => 'Ücretsiz Kargo', 'text' => '500 ₺ üzeri siparişlerde', 'link' => '/kategori/ev', 'image' => $svg('', '#ff7a1a', '#c2410c'), 'cta' => 'Alışverişe başla'],
	['title' => 'Satıcılar için ilk ay vitrin', 'text' => 'Ürünlerinizi ana sayfada tanıtın', 'link' => '/satici-kayit', 'image' => '', 'cta' => 'Başvur'],
];
Module::$en = ['rafimda-vendor', 'rafimda-dealer'];
$v = RafimdaThemeModule::viewVars();
$hero = $m->renderFrontTemplate('hero', $v + ['rtCategories' => $cats, 'rtHeroImage' => $svg('', '#1d4f91', '#ff7a1a')]);
$bottom = $m->renderFrontTemplate('home_bottom', $v + ['rtSections' => $secs, 'rtBanners' => $bn, 'rtShowPromo' => true]);
$foot = $m->renderFrontTemplate('links_footer', $v);
$mob = $m->renderFrontTemplate('links_mobile', $v);
$css = '';
foreach (['style', 'colors', 'header2', 'catalog', 'custom'] as $c) { $css .= '<link rel="stylesheet" href="https://test.local/templates/theme4/css/' . $c . '.css">'; }
$css .= '<link rel="stylesheet" href="https://test.local/modules/rafimda-theme/assets/css/rafimda-theme.css">';
$shell = static fn(string $inner, string $title): string => '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title>' . $GLOBALS['css'] . '<style>body{margin:0;font-family:Inter,system-ui,sans-serif}.pv-top{background:#0a2540;color:#fff;padding:12px 16px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}.pv-top b{font-size:22px}.pv-top input{flex:1;min-width:160px;padding:9px 12px;border-radius:8px;border:0}.pv-top a{color:#fff;text-decoration:none;font-size:14px}.pv-foot{background:#0a2540;color:#cbd5e1;padding:24px 16px;text-align:center;font-size:13px}</style></head><body><div class="pv-top"><b>Rafımda</b><input placeholder="Aradığınız her şey burada…"><a href="#">Giriş</a><a href="#">Sepet</a></div>' . $inner . '<div class="pv-foot">Önizleme kabuğu (basitleştirilmiş üst/alt bilgi). Rafımda tema bölümleri gerçek şablonlardan çizildi.</div></body></html>';
file_put_contents("$out/home.html", $shell($hero . $bottom . $foot, 'Rafımda ana sayfa'));
file_put_contents("$out/home-empty.html", $shell(trim($m->renderFrontTemplate('hero', $v + ['rtCategories' => []])) . trim($m->renderFrontTemplate('home_bottom', $v + ['rtSections' => [], 'rtBanners' => [], 'rtShowPromo' => true])), 'Rafımda ana sayfa (ürün yok)'));
// admin
$_SERVER['REQUEST_METHOD'] = 'GET';
$m->adminPage();
$smarty->setEscapeHtml(false);
file_put_contents("$out/theme-admin.html", $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl'));
echo "ok\n";
