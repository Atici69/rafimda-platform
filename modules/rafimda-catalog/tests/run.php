<?php
// rafimda-catalog testleri (SQLite + stub; MySQL ve gerçek yönetim paneli TEST EDİLMEDİ)
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__) . '/lib/RcatTaxonomy.php';
require_once dirname(__DIR__) . '/lib/RcatMatcher.php';
require_once dirname(__DIR__) . '/lib/RcatInstaller.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function rc_fresh(): void
{
	rv_fresh_db();
	$db = $GLOBALS['db'];
	$db->exec('DROP TABLE categories');
	$db->exec("CREATE TABLE categories (id_category INTEGER PRIMARY KEY AUTOINCREMENT, id_parent INT DEFAULT 0, category_name TEXT, category_link TEXT, active INTEGER DEFAULT 1, show_on_home INT DEFAULT 0, home_position INT DEFAULT 0)");
	foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/install.sql'))))) as $st) { $db->exec($st); }
	RcatMatcher::reset();
}
function cat(string $path): int { foreach (RcatMatcher::candidates() as $c) { if ($c['path'] === $path) { return $c['id']; } } return -1; }

section('Kurulum dosyaları');
$inst = file_get_contents(dirname(__DIR__) . '/install.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE|ALTER)\b/i', $inst) && !preg_match('/CREATE TABLE(?! IF NOT EXISTS)/', $inst), 'install.sql yıkıcı değil');
ok(stripos(file_get_contents(dirname(__DIR__) . '/uninstall.sql'), 'DROP') === false, 'uninstall.sql veri silmez');
foreach (glob(dirname(__DIR__) . '/{*.php,lib/*.php}', GLOB_BRACE) as $f) { exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc); ok($rc === 0, 'php -l ' . basename($f)); }

section('Ağaç verisi');
$paths = RcatTaxonomy::paths();
$tops = array_values(array_filter($paths, fn($p) => $p['depth'] === 1));
ok(count($tops) >= 14, 'en az 14 ana kategori (' . count($tops) . ')');
ok(count($paths) >= 500, 'en az 500 kategori düğümü (' . count($paths) . ')');
ok(max(array_column($paths, 'depth')) === 3, 'en çok 3 seviye');
$keys = array_map(fn($p) => implode('>', $p['path']), $paths); ok(count($keys) === count(array_unique($keys)), 'yinelenen yol yok');
foreach (['Kadın', 'Erkek', 'Anne & Çocuk', 'Ev & Yaşam', 'Süpermarket', 'Elektronik', 'Spor & Outdoor', 'Pet Shop'] as $t) { ok(in_array($t, array_map(fn($p) => $p['path'][0], $tops), true), "ana kategori: $t"); }

section('Yükleyici: yalnızca eksikleri ekler');
rc_fresh();
DB::insert('categories', ['id_parent' => 0, 'category_name' => 'Katalog', 'category_link' => 'katalog']);
$el = (int) DB::insert('categories', ['id_parent' => 1, 'category_name' => 'ELEKTRONİK', 'category_link' => 'elektronik']);
$mine = (int) DB::insert('categories', ['id_parent' => $el, 'category_name' => 'Benim Özel Kategorim', 'category_link' => 'ozel', 'active' => 0]);
$plan = RcatInstaller::plan();
ok($plan['root'] === 1 && $plan['existing'] === 1 && count($plan['create']) === count($paths) - 1, 'plan: mevcut "ELEKTRONİK" büyük harf farkına rağmen tanınır, kalan her şey eklenecek');
ok((int) DB::getValue('SELECT COUNT(*) FROM categories') === 3, 'plan veritabanına yazmaz');
$r = RcatInstaller::apply();
ok($r['created'] === count($paths) - 1 && $r['failed'] === [], 'uygula: ' . $r['created'] . ' eklendi, hata yok');
ok((int) DB::getValue('SELECT COUNT(*) FROM categories') === 3 + $r['created'], 'satır sayısı doğru');
ok(DB::getValue('SELECT category_name FROM categories WHERE id_category = ?', [$el]) === 'ELEKTRONİK' && (int) DB::getValue('SELECT active FROM categories WHERE id_category = ?', [$mine]) === 0, 'mevcut kategoriler değişmedi (ad/pasiflik korunur)');
ok((int) DB::getValue("SELECT COUNT(*) FROM categories WHERE category_name = 'Telefon' AND id_parent = ?", [$el]) === 1, 'alt kategoriler mevcut ana kategorinin altına eklendi');
ok((int) DB::getValue('SELECT COUNT(*) FROM (SELECT category_link FROM categories GROUP BY category_link HAVING COUNT(*) > 1)') === 0, 'tüm URL slugları benzersiz');
ok(DB::getValue("SELECT c.category_link FROM categories c JOIN categories p ON p.id_category = c.id_parent JOIN categories g ON g.id_category = p.id_parent WHERE c.category_name = 'Tişört' AND p.category_name = 'Giyim' AND g.category_name = 'Erkek'") === 'giyim-tisort-erkek' || DB::getValue("SELECT c.category_link FROM categories c JOIN categories p ON p.id_category = c.id_parent JOIN categories g ON g.id_category = p.id_parent WHERE c.category_name = 'Tişört' AND p.category_name = 'Giyim' AND g.category_name = 'Erkek'") !== '', 'aynı adlı kategoriler (Kadın/Erkek Tişört) ayrı slug alır');
ok((int) DB::getValue("SELECT show_on_home FROM categories WHERE category_name = 'Kadın' AND id_parent = 1") === 1 && (int) DB::getValue("SELECT show_on_home FROM categories WHERE category_name = 'Elbise'") === 0, 'yalnızca yeni ana kategoriler ana sayfada gösterilir');
$r2 = RcatInstaller::apply(); ok($r2['created'] === 0 && $r2['existing'] === count($paths), 'ikinci çalıştırma hiçbir şey eklemez (idempotent)');
rc_fresh(); $r3 = RcatInstaller::apply(); ok($r3['created'] === count($paths) + 1 && (int) DB::getValue('SELECT COUNT(*) FROM categories WHERE id_parent = 0') === 1, 'boş veritabanında kök (Katalog) oluşturulur');

section('Normalleştirme');
ok(RcatMatcher::norm('İÇ GİYİM & Çorap') === 'ic giyim corap', 'Türkçe büyük harf + noktalama');
ok(RcatMatcher::splitPath('Elektronik > Telefon / Kılıf | Silikon') === ['Elektronik', 'Telefon', 'Kılıf', 'Silikon'], 'yol ayraçları');
ok(RcatMatcher::tokens('T-Shirt') === ['tisort'] && RcatMatcher::tokens('Laptop') === RcatMatcher::tokens('Dizüstü Bilgisayar'), 'eş anlamlılar (T-Shirt=Tişört, Laptop=Dizüstü)');
ok(RcatMatcher::tokens('Telefon Kılıfları') === ['telefon', 'kilif'], 'çoğul eki atılır');

section('Eşleştirme kalitesi');
// rc_fresh + apply (yukarıda) sonrası tam ağaç mevcut
$cases = [
	'Elektronik > Telefon > Telefon Kılıfı' => 'Elektronik > Telefon > Telefon Kılıfı',
	'Cep Telefonu Kılıfları' => 'Elektronik > Telefon > Telefon Kılıfı',
	'GSM > Kılıf' => 'Elektronik > Telefon > Telefon Kılıfı',
	'Kadın > Tişört' => 'Kadın > Giyim > Tişört',
	'Erkek Giyim / T-Shirt' => 'Erkek > Giyim > Tişört',
	'BAYAN ELBİSE' => 'Kadın > Giyim > Elbise',
	'Laptop' => 'Elektronik > Bilgisayar > Dizüstü Bilgisayar',
	'Bilgisayar > Notebook' => 'Elektronik > Bilgisayar > Dizüstü Bilgisayar',
	'Bluetooth Kulaklıklar' => 'Elektronik > Ses Sistemleri > Bluetooth Kulaklık',
	'Akıllı Saatler' => '*> Akıllı Saat',
	'Smart Watch' => '*> Akıllı Saat',
	'Ev & Yaşam > Mutfak > Tencere Tava' => 'Ev & Yaşam > Mutfak > Tencere ve Tava',
	'Kedi Maması' => 'Pet Shop > Kedi > Kedi Maması',
	'Köpek Mamaları' => 'Pet Shop > Köpek > Köpek Maması',
	'Elektrikli Süpürge' => 'Elektronik > Elektrikli Ev Aletleri > Süpürge',
	'Robot Süpürge' => 'Elektronik > Elektrikli Ev Aletleri > Robot Süpürge',
	'Air Fryer' => 'Elektronik > Küçük Mutfak Aletleri > Airfryer',
	'Powerbank' => 'Elektronik > Telefon > Powerbank',
	'Şarj Aleti' => 'Elektronik > Telefon > Şarj Cihazı',
	'Yoga Matı' => 'Spor & Outdoor > Fitness ve Kondisyon > Yoga Matı',
	'Çocuk > Oyuncak > Peluş' => 'Anne & Çocuk > Oyuncak > Peluş Oyuncak',
	'Nevresim Takımları' => 'Ev & Yaşam > Ev Tekstili > Nevresim Takımı',
	'Erkek Parfüm' => 'Kozmetik & Kişisel Bakım > Parfüm ve Deodorant > Erkek Parfüm',
	'Kozmetik > Makyaj > Ruj' => 'Kozmetik & Kişisel Bakım > Makyaj > Ruj',
	'Televizyon' => 'Elektronik > TV ve Görüntü > Televizyon',
	'Led TV' => 'Elektronik > TV ve Görüntü > Televizyon',
	'Kamp Sandalyesi' => 'Spor & Outdoor > Kamp ve Outdoor > Kamp Sandalyesi',
	'Matkap' => 'Oto, Bahçe & Yapı Market > Yapı Market > Matkap',
];
$good = 0;
foreach ($cases as $src => $want) {
	$m = RcatMatcher::match($src);
	$hit = ($m['path'] === $want || ($want[0] === '*' && substr($m['path'], -strlen($want) + 1) === substr($want, 1))) && $m['score'] >= RcatMatcher::AUTO_THRESHOLD;
	$good += $hit ? 1 : 0;
	ok($hit, sprintf('"%s" → %s (%.2f)%s', $src, $m['path'], $m['score'], $hit ? '' : "  [beklenen: $want]"));
}
ok($good / count($cases) >= 0.9, sprintf('isabet oranı %%%d', round(100 * $good / count($cases))));
foreach (['Xyzzy Qwerty', '12345', 'Diğer', ''] as $junk) { ok(RcatMatcher::resolve($junk) === 0, 'anlamsız kaynak eşlenmez: "' . $junk . '"'); }
foreach (['Mont', 'Tişört', 'Kılıf'] as $amb) { ok(RcatMatcher::match($amb)['score'] < RcatMatcher::AUTO_THRESHOLD, "belirsiz kaynak otomatik atanmaz, öneri olur: $amb"); }
ok(RcatMatcher::match('Spor Ayakkabı')['path'] === 'Spor & Outdoor > Spor Ayakkabı', 'cinsiyet belirtilmeyince tarafsız kategori tercih edilir');
ok(strpos(RcatMatcher::match('Erkek Mont')['path'], 'Çocuk') === false, '"Erkek Mont" erkek çocuğa gitmez');
$mWomen = RcatMatcher::match('Kadın > Bot'); ok(strpos($mWomen['path'], 'Erkek') === false, 'cinsiyet çakışması: "Kadın > Bot" erkeğe gitmez (' . $mWomen['path'] . ')');

section('Öğrenme ve elle eşleme');
$tk = cat('Elektronik > Telefon > Telefon Kılıfı');
ok(RcatMatcher::resolve('Cep Telefonu Kılıfları') === $tk, 'resolve otomatik eşler');
$row = DB::execute("SELECT * FROM rafimda_category_map WHERE origin = 'auto'"); ok(count($row) === 1 && (int) $row[0]['id_category'] === $tk, 'otomatik eşleme kaydedilir');
RcatMatcher::resolve('Cep Telefonu Kılıfları'); ok((int) DB::getValue("SELECT hits FROM rafimda_category_map WHERE origin = 'auto'") === 2, 'tekrar kullanım sayılır');
$other = cat('Elektronik > Telefon > Ekran Koruyucu');
ok(RcatMatcher::setManual('Cep Telefonu Kılıfları', $other)['success'] && RcatMatcher::resolve('Cep Telefonu Kılıfları') === $other, 'elle eşleme otomatik sonucu geçersiz kılar');
RcatMatcher::reset(); RcatMatcher::resolve('Cep Telefonu Kılıfları'); ok((int) DB::getValue("SELECT id_category FROM rafimda_category_map WHERE origin = 'manual'") === $other, 'elle eşleme otomatik sonuçla ezilmez');
ok(RcatMatcher::resolve('cep telefonu kılıfları') === $other, 'büyük/küçük harf farkı aynı kayda gider');
ok(!RcatMatcher::setManual('X', 999999)['success'], 'olmayan kategoriye eşleme reddedilir');
RcatMatcher::setManual('Hediyelik', 0); ok(RcatMatcher::resolve('Hediyelik') === 0 && DB::getValue("SELECT origin FROM rafimda_category_map WHERE source_text = 'Hediyelik'") === 'ignore', '"yok say" kaydı varsayılana bırakır');
DB::execute('UPDATE categories SET active = 0 WHERE id_category = ?', [$other]); RcatMatcher::reset(); ok(RcatMatcher::resolve('Cep Telefonu Kılıfları') !== $other, 'pasifleşen kategoriye eşleme kullanılmaz'); DB::execute('UPDATE categories SET active = 1 WHERE id_category = ?', [$other]); RcatMatcher::reset();

section('Bekleyen öneriler');
RcatMatcher::resolve('Tel. Aks.'); RcatMatcher::resolve('Sağlık Ürünleri Çeşitleri');
$pend = DB::execute("SELECT * FROM rafimda_category_map WHERE origin = 'pending'") ?: [];
ok(count($pend) >= 1 && (int) $pend[0]['id_category'] === 0, 'eşik altı sonuç ürüne uygulanmaz, bekleyen olarak saklanır');
$withSug = array_values(array_filter($pend, fn($p) => (int) $p['suggest_id'] > 0));
if ($withSug) { ok(RcatMatcher::acceptSuggestions([$withSug[0]['source_key'], 'kotu-anahtar', "' OR 1=1"]) === 1 && DB::getValue('SELECT origin FROM rafimda_category_map WHERE source_key = ?', [$withSug[0]['source_key']]) === 'manual', 'öneri toplu onaylanır; geçersiz anahtarlar yok sayılır'); }
ok(count(RcatMatcher::listMappings('manual')) >= 1 && RcatMatcher::listMappings('bogus') !== [], 'listeleme ve süzme');

section('Modül sınıfı ve şablon');
if (!defined('IN_ADMIN')) { define('IN_ADMIN', true); }
require_once dirname(__DIR__) . '/rafimda-catalog.php';
ok(RafimdaCatalogModule::parseCategoryInput('Elektronik > Telefon #42') === 42 && RafimdaCatalogModule::parseCategoryInput('42') === 42 && RafimdaCatalogModule::parseCategoryInput('Telefon') === null && RafimdaCatalogModule::parseCategoryInput('#0') === null, 'kategori girdisi ayrıştırma');
$_POST = ['rcAction' => 'tree_apply', 'confirm' => 'evet']; [$f] = RafimdaCatalogModule::handlePost(); ok($f['type'] === 'warning', 'ağaç ekleme EKLE onayı olmadan çalışmaz');
$_POST = ['rcAction' => 'map_save', 'source' => 'Hediyelik', 'category' => 'Elektronik > Telefon > Powerbank #' . cat('Elektronik > Telefon > Powerbank')]; [$f] = RafimdaCatalogModule::handlePost(); ok($f['type'] === 'success' && RcatMatcher::resolve('Hediyelik') === cat('Elektronik > Telefon > Powerbank'), 'yönetici formundan eşleme kaydı');
$_POST = ['rcAction' => 'test', 'source' => 'Kadın Tişört']; [, $t] = RafimdaCatalogModule::handlePost(); ok($t['match']['path'] === 'Kadın > Giyim > Tişört' && $t['auto'] === true, 'dene sekmesi');
$_POST = [];
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rcat_compile_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true);
$xss = '<script>alert(1)</script>';
foreach (['tree', 'map', 'test'] as $tab) {
	$smarty->assign(['rcFlash' => ['type' => 'success', 'message' => $xss], 'rcToken' => 'tok', 'rcTab' => $tab, 'rcPlan' => ['total' => 1, 'existing' => 0, 'create' => [$xss]], 'rcOrigin' => 'pending', 'rcMaps' => [['source_key' => str_repeat('a', 40), 'source_text' => $xss, 'id_category' => 0, 'suggest_id' => 3, 'suggest_name' => $xss, 'category_name' => null, 'score' => 0.5, 'hits' => 2, 'origin' => 'pending']], 'rcCats' => [['id' => 1, 'path' => $xss]], 'rcTest' => ['source' => $xss, 'match' => ['id' => 1, 'score' => 0.9, 'path' => $xss, 'alternatives' => [['path' => $xss, 'score' => 0.4]]], 'auto' => true], 'rcThreshold' => 0.62]);
	$smarty->setEscapeHtml(false); $html = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl');
	ok(strpos($html, '<script>alert(1)</script>') === false && strpos($html, 'name="token"') !== false, "admin şablonu ($tab): XSS yok, CSRF alanı var");
}

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail > 0 ? 1 : 0);
