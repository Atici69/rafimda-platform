<?php
// Satıcı Excel (.xlsx) / CSV içe aktarma: kategori/marka ADIYLA, otomatik kategori eşleştirme, eksik marka oluşturma,
// başlık eş anlamlıları, şablonlar. SQLite + stub; MySQL ve gerçek panel oturumu TEST EDİLMEDİ.
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/rafimda-catalog/lib/RcatTaxonomy.php';
require_once dirname(__DIR__, 2) . '/rafimda-catalog/lib/RcatMatcher.php';
require_once dirname(__DIR__, 2) . '/rafimda-catalog/lib/RcatInstaller.php';
require_once dirname(__DIR__, 3) . '/libs/SimpleGEN.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function xlsx(array $rows): string { $f = tempnam(sys_get_temp_dir(), 'rvx') . '.xlsx'; SimpleXLSXGen::fromArray($rows)->saveAs($f); return $f; }
function csvf(string $c): string { $f = tempnam(sys_get_temp_dir(), 'rvc'); file_put_contents($f, $c); return $f; }

rv_fresh_db(); rv_apply_migrations();
$db = $GLOBALS['db'];
$db->exec('DROP TABLE categories'); $db->exec('DROP TABLE brands');
$db->exec('CREATE TABLE categories (id_category INTEGER PRIMARY KEY AUTOINCREMENT, id_parent INT DEFAULT 0, category_name TEXT, category_link TEXT, active INTEGER DEFAULT 1, show_on_home INT DEFAULT 0, home_position INT DEFAULT 0)');
$db->exec('CREATE TABLE brands (id_brand INTEGER PRIMARY KEY AUTOINCREMENT, brand_name TEXT, brand_link TEXT, active INTEGER DEFAULT 1)');
$db->exec(rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__, 2) . '/rafimda-catalog/install.sql')));
RcatInstaller::apply(); RcatMatcher::reset();
DB::insert('brands', ['brand_name' => 'Samsung', 'brand_link' => 'samsung']);
DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'A', 'store_slug' => 'a', 'status' => 'active']);
$vA = 1;
$catId = fn(string $path) => (int) array_values(array_filter(RcatMatcher::candidates(), fn($c) => $c['path'] === $path))[0]['id'];

section('Şablonlar');
ok(strpos(RvProducts::csvTemplate(), 'urun_adi;kategori;marka;fiyat;stok;stok_kodu;barkod;aciklama;kdv;gorsel_url') !== false, 'CSV şablonu: kategori/marka adıyla, KDV ve görsel sütunu');
$tpl = RvProducts::xlsxTemplate(); $tf = tempnam(sys_get_temp_dir(), 'tpl'); file_put_contents($tf, $tpl);
require_once dirname(__DIR__, 3) . '/libs/SimpleXLSX.php';
$tx = SimpleXLSX::parse($tf); $tr = $tx ? $tx->rows() : [];
ok(substr($tpl, 0, 2) === 'PK' && ($tr[0][0] ?? '') === 'urun_adi' && ($tr[1][1] ?? '') === 'Kadın > Giyim > Tişört' && ($tr[1][6] ?? '') === '8690000000001', 'Excel şablonu geçerli .xlsx; barkod metin olarak korunur');

section('Excel içe aktarma');
$f = xlsx([
	['Ürün Adı', 'Kategori', 'Marka', 'Fiyat', 'Stok', 'Stok Kodu', 'Barkod', 'Açıklama', 'KDV'],
	['Galaxy Silikon Kılıf', 'Telefon Aksesuarları > Kılıf', 'SAMSUNG', 149.9, 10, 'XL-1', '8690000000011', 'Şeffaf', 20],
	['Pamuklu Kadın Tişört', 'Kadın Tişört', 'Yeni Moda Markası', '199,90', 5, 'XL-2', '', '', ''],
	['Bilinmez Ürün', 'Qwerty Zxcv', 'Samsung', 10, 1, 'XL-3', '', '', ''],
	['Numaralı Kategori', (string) $catId('Elektronik > Telefon > Powerbank'), (string) DB::getValue("SELECT id_brand FROM brands WHERE brand_name = 'Samsung'"), 300, 2, 'XL-4', '', '', ''],
	['', '', '', '', '', '', '', '', ''],
]);
$r = RvProducts::importXlsx($vA, $f, 'urunler.xlsx');
ok($r['success'] && $r['created'] === 3 && $r['failed'] === 1 && $r['total'] === 4, "3 yeni, 1 hatalı, boş satır sayılmaz => {$r['message']}");
$p1 = DB::getRowSafe('products', "stock_code = 'XL-1'", []);
ok($p1 && (int) $p1['id_category'] === $catId('Elektronik > Telefon > Telefon Kılıfı') && abs((float) $p1['price'] - 149.9) < 0.001, '"Telefon Aksesuarları > Kılıf" otomatik eşlendi, fiyat doğru');
ok((int) $p1['id_brand'] === (int) DB::getValue("SELECT id_brand FROM brands WHERE brand_name = 'Samsung'"), '"SAMSUNG" mevcut Samsung markasına bağlandı (yeni marka açılmadı)');
$p2 = DB::getRowSafe('products', "stock_code = 'XL-2'", []);
ok($p2 && (int) $p2['id_category'] === $catId('Kadın > Giyim > Tişört') && abs((float) $p2['price'] - 199.9) < 0.001, '"Kadın Tişört" → Kadın > Giyim > Tişört; virgüllü fiyat');
ok((int) DB::getValue("SELECT COUNT(*) FROM brands WHERE brand_name = 'Yeni Moda Markası'") === 1 && (int) $p2['id_brand'] === (int) DB::getValue("SELECT id_brand FROM brands WHERE brand_name = 'Yeni Moda Markası'"), 'sitede olmayan marka otomatik oluşturuldu');
ok(strpos(implode(' ', $r['errors']), 'kategori eşleşmedi: "Qwerty Zxcv"') !== false, 'eşleşmeyen kategori satır numarasıyla raporlanır');
ok(DB::getValue("SELECT origin FROM rafimda_category_map WHERE source_text = 'Qwerty Zxcv'") === 'pending', '...ve yönetici onayına öneri olarak düşer');
ok((int) DB::getValue("SELECT id_category FROM products WHERE stock_code = 'XL-4'") === $catId('Elektronik > Telefon > Powerbank'), 'kategori numarası da kabul edilir');
$r = RvProducts::importXlsx($vA, xlsx([['urun_adi', 'kategori', 'marka', 'fiyat', 'stok', 'stok_kodu'], ['X', 'Kılıf', 'Samsung', 175, 3, 'XL-1']]), 'g.xlsx');
ok($r['updated'] === 1 && abs((float) DB::getValue("SELECT price FROM products WHERE stock_code = 'XL-1'") - 175) < 0.001, 'aynı stok kodu: yalnızca fiyat/stok güncellenir');
$r = RvProducts::importXlsx($vA, xlsx([['urun_adi', 'fiyat'], ['X', 1]]), 'h.xlsx'); ok(!$r['success'] && strpos($r['message'], 'kategori') !== false && strpos($r['message'], 'marka') !== false, 'eksik sütun reddedilir');
$bad = tempnam(sys_get_temp_dir(), 'bad'); file_put_contents($bad, 'bu bir excel değil'); $r = RvProducts::importXlsx($vA, $bad, 'x.xlsx'); ok(!$r['success'], 'bozuk dosya reddedilir');

section('CSV: ad ile kategori/marka (yeni şablon)');
$r = RvProducts::importCsv($vA, csvf("\xEF\xBB\xBF" . "urun_adi;kategori;marka;fiyat;stok;stok_kodu\r\nKedi Maması 2 kg;Pet Shop > Kedi > Kedi Maması;Samsung;250;4;CS-1\r\n"), 'k.csv');
ok($r['created'] === 1 && (int) DB::getValue("SELECT id_category FROM products WHERE stock_code = 'CS-1'") === $catId('Pet Shop > Kedi > Kedi Maması'), 'CSV yol ile kategori');
$r = RvProducts::importCsv($vA, csvf("urun_adi;kategori_id;marka_id;fiyat;stok;stok_kodu\r\nEski Biçim;" . $catId('Pet Shop > Kedi > Kedi Kumu') . ";1;20;1;CS-2\r\n"), 'old.csv');
ok($r['created'] === 1, 'eski şablon (kategori_id/marka_id) çalışmaya devam eder');
ok(RvProducts::resolveCategoryInput('Mont') === 0, 'belirsiz ad ("Mont": Kadın/Erkek) rastgele kategoriye atanmaz');
ok(RvProducts::resolveBrandInput('<b>x</b>') === 0 && RvProducts::resolveBrandInput('') === 0, 'geçersiz marka adı oluşturulmaz');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail > 0 ? 1 : 0);
