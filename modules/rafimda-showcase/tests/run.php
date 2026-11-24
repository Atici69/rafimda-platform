<?php
// rafimda-showcase testleri (SQLite + stub; gerçek tema/ana sayfa, MySQL ve tarayıcı TEST EDİLMEDİ)
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__) . '/lib/RsShowcase.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
function fresh(): void { rv_fresh_db(); rv_apply_migrations(); foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/install.sql'))))) as $st) { $GLOBALS['db']->exec($st); } }
function prod(int $vendor, string $n, array $o = []): int { $id = (int) DB::insert('products', ['product_name' => $n, 'id_category' => 1, 'id_brand' => 1, 'price' => $o['price'] ?? 10, 'old_price' => $o['old'] ?? 0, 'stock' => $o['stock'] ?? 5, 'stock_code' => 'S' . mt_rand(), 'barcode' => '', 'active' => $o['active'] ?? 1]); if ($vendor > 0) { DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $vendor, 'moderation' => $o['mod'] ?? 'approved']); } return $id; }
$day = 86400; $ymd = fn(int $off) => date('Y-m-d', time() + $off * $day);

section('Kurulum');
fresh();
$inst = file_get_contents(dirname(__DIR__) . '/install.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE|ALTER)\b/i', $inst) && !preg_match('/CREATE TABLE(?! IF NOT EXISTS)/', $inst) && !preg_match('/^\s*--/m', $inst), 'install.sql yıkıcı değil ve "--" ile başlamıyor');
ok(strpos(file_get_contents(dirname(__DIR__) . '/uninstall.sql'), 'DROP') === false, 'uninstall.sql veri silmez');
$sl = RsShowcase::slots(); ok(count($sl) === 3 && $sl[0]['code'] === 'home_featured', '3 varsayılan alan tohumlanır'); RsShowcase::slots(); ok(count(RsShowcase::slots()) === 3, 'ensureDefaults idempotent');

section('Alan yönetimi');
ok(RsShowcase::saveSlot('home_featured', ['title' => 'Vitrin', 'max_items' => 2, 'active' => 1, 'vendor_requestable' => 1])['success'] && RsShowcase::slot('home_featured')['max_items'] == 2, 'alan güncellenir');
foreach ([['title' => 'x', 'max_items' => 3], ['title' => 'Tamam', 'max_items' => 0], ['title' => 'Tamam', 'max_items' => 99]] as $bad) { ok(!RsShowcase::saveSlot('home_featured', $bad)['success'], 'geçersiz alan reddedilir ' . json_encode($bad)); }
ok(!RsShowcase::saveSlot('yok', ['title' => 'Tamam', 'max_items' => 3])['success'], 'olmayan alan');

section('URL güvenliği');
foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.com/x', 'http://insecure.com/x', 'ftp://x/y', "https://a.com/x\"onload=1", 'https://a.com/<s>', ' ', '', "/ok\nx", 'https://', str_repeat('a', 300)] as $u) { ok(RsShowcase::safeUrl($u) === '', 'reddedilir: ' . substr(json_encode($u), 0, 40)); }
foreach (['https://cdn.example.com/a.jpg', '/img/banner.jpg', '/kampanya?x=1'] as $u) { ok(RsShowcase::safeUrl($u) === $u, "kabul: $u"); }

section('Yönetici öğesi ve banner');
$p1 = prod(0, 'Platform Ürün'); $p2 = prod(0, 'İkinci'); $p3 = prod(0, 'Üçüncü');
$r = RsShowcase::addByAdmin(['slot_code' => 'home_featured', 'id_product' => $p1, 'position' => 2]); ok($r['success'], 'yönetici ürün ekler (onaylı)');
RsShowcase::addByAdmin(['slot_code' => 'home_featured', 'id_product' => $p2, 'position' => 1]); RsShowcase::addByAdmin(['slot_code' => 'home_featured', 'id_product' => $p3, 'position' => 3]);
$a = RsShowcase::active('home_featured'); ok(count($a) === 2 && $a[0]['title'] === 'İkinci' && $a[1]['title'] === 'Platform Ürün', 'sıra (position) ve alan azami öğe sınırı (2) uygulanır');
ok(!RsShowcase::addByAdmin(['slot_code' => 'home_featured', 'id_product' => 99999])['success'] && !RsShowcase::addByAdmin(['slot_code' => 'yok', 'id_product' => $p1])['success'], 'olmayan ürün/alan reddedilir');
ok(!RsShowcase::addByAdmin(['slot_code' => 'home_ad', 'title' => 'Banner', 'image_url' => 'javascript:alert(1)', 'link_url' => '/k'])['success'] && !RsShowcase::addByAdmin(['slot_code' => 'home_ad', 'title' => 'Banner', 'image_url' => '/i.jpg', 'link_url' => 'javascript:x'])['success'] && !RsShowcase::addByAdmin(['slot_code' => 'home_ad', 'title' => '', 'image_url' => '/i.jpg', 'link_url' => '/k'])['success'], 'banner: tehlikeli URL ve boş başlık reddedilir');
ok(RsShowcase::addByAdmin(['slot_code' => 'home_ad', 'title' => 'Kış <b>Kampanyası</b>', 'image_url' => 'https://cdn.example.com/b.jpg', 'link_url' => '/kampanya'])['success'], 'geçerli banner eklenir');
$b = RsShowcase::active('home_ad'); ok(count($b) === 1 && $b[0]['kind'] === 'banner' && strpos($b[0]['title'], '<b>') === false && $b[0]['url'] === '/kampanya', 'banner yayında, HTML temizlenmiş');
ok(RsShowcase::active('yok') === [], 'olmayan alan boş döner');

section('Zaman penceresi');
fresh(); $p = prod(0, 'Zamanlı');
RsShowcase::addByAdmin(['slot_code' => 'home_featured', 'id_product' => $p, 'starts_at' => $ymd(2), 'ends_at' => $ymd(5)]);
ok(RsShowcase::active('home_featured') === [], 'başlangıç tarihinden önce görünmez');
ok(count(RsShowcase::active('home_featured', time() + 3 * $day)) === 1, 'pencere içinde görünür');
ok(RsShowcase::active('home_featured', time() + 6 * $day) === [], 'bitişten sonra görünmez');
ok(!RsShowcase::addByAdmin(['slot_code' => 'home_featured', 'id_product' => $p, 'starts_at' => $ymd(5), 'ends_at' => $ymd(2)])['success'] && !RsShowcase::addByAdmin(['slot_code' => 'home_featured', 'id_product' => $p, 'starts_at' => 'abc'])['success'], 'ters/geçersiz tarih reddedilir');

section('Satıcı talebi (sahiplik, onay, görünürlük)');
fresh(); DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'A', 'store_slug' => 'a', 'status' => 'active']); DB::insert('rafimda_vendors', ['owner_user_id' => 2, 'store_name' => 'B', 'store_slug' => 'b', 'status' => 'active']);
$mine = prod(1, 'Benim'); $other = prod(2, 'Başkasının'); $plat = prod(0, 'Platform'); $pend = prod(1, 'Onaysız', ['mod' => 'pending']);
$r = RsShowcase::request(1, ['slot_code' => 'home_featured', 'id_product' => $mine]); ok($r['success'], 'satıcı kendi ürünü için talep açar');
ok(RsShowcase::active('home_featured') === [], 'onaylanana kadar vitrinde görünmez');
ok(!RsShowcase::request(1, ['slot_code' => 'home_featured', 'id_product' => $other])['success'] && !RsShowcase::request(1, ['slot_code' => 'home_featured', 'id_product' => $plat])['success'] && !RsShowcase::request(1, ['slot_code' => 'home_featured', 'id_product' => 99999])['success'], 'başkasının/platform/olmayan ürün için talep reddedilir (IDOR)');
ok(!RsShowcase::request(1, ['slot_code' => 'home_ad', 'id_product' => $mine])['success'] && !RsShowcase::request(1, ['slot_code' => 'yok', 'id_product' => $mine])['success'], 'satıcı banner alanına / olmayan alana talep açamaz');
ok(!RsShowcase::request(0, ['slot_code' => 'home_featured', 'id_product' => $mine])['success'], 'satıcı kimliği olmadan talep olmaz');
ok(!RsShowcase::request(1, ['slot_code' => 'home_featured', 'id_product' => $mine])['success'], 'aynı ürün/alan için çift talep reddedilir');
RsShowcase::saveSlot('home_campaign', ['title' => 'Kampanya', 'max_items' => 8, 'active' => 1, 'vendor_requestable' => 0]); ok(!RsShowcase::request(1, ['slot_code' => 'home_campaign', 'id_product' => $mine])['success'], 'satıcı talebine kapalı alan reddedilir');
ok(RsShowcase::request(1, ['slot_code' => 'home_featured', 'id_product' => $pend])['success'], 'onaysız (moderasyonda) ürün için talep açılabilir ama...');
$id = (int) DB::getValue("SELECT id_item FROM rs_items WHERE id_product = $mine"); $id2 = (int) DB::getValue("SELECT id_item FROM rs_items WHERE id_product = $pend");
RsShowcase::moderate($id, 'approved'); RsShowcase::moderate($id2, 'approved');
$a = RsShowcase::active('home_featured'); ok(count($a) === 1 && $a[0]['title'] === 'Benim', '...ürün moderasyondan onaylanana kadar vitrine çıkmaz; onaylı ürün yayınlanır');
DB::execute("UPDATE products SET active = 0 WHERE id_product = $mine"); ok(RsShowcase::active('home_featured') === [], 'ürün pasifleşince vitrinden düşer'); DB::execute("UPDATE products SET active = 1, stock = 0 WHERE id_product = $mine"); ok(RsShowcase::active('home_featured') === [], 'stoksuz ürün vitrinden düşer'); DB::execute("UPDATE products SET stock = 5 WHERE id_product = $mine");
DB::execute("UPDATE rafimda_vendor_products SET id_vendor = 2 WHERE id_product = $mine"); ok(RsShowcase::active('home_featured') === [], 'ürün sahibi değişirse eski talep geçersiz olur');
DB::execute("UPDATE rafimda_vendor_products SET id_vendor = 1 WHERE id_product = $mine");
ok(!RsShowcase::withdraw(2, $id) && RsShowcase::withdraw(1, $id) && RsShowcase::active('home_featured') === [], 'başka satıcı geri çekemez; sahibi geri çekince yayından kalkar');
for ($i = 0; $i < 25; $i++) { $pp = prod(1, "Ürün $i"); RsShowcase::request(1, ['slot_code' => 'home_featured', 'id_product' => $pp]); } ok((int) DB::getValue("SELECT COUNT(*) FROM rs_items WHERE id_vendor = 1 AND status = 'pending'") <= 20, 'bekleyen talep üst sınırı (20)');
ok(count(RsShowcase::forVendor(1)) > 0 && array_unique(array_column(RsShowcase::forVendor(1), 'id_vendor')) === [1] && RsShowcase::forVendor(0) === [], 'satıcı yalnızca kendi taleplerini görür');
ok(RsShowcase::moderate($id2, 'bogus') === false && RsShowcase::moderate(99999, 'approved') === false, 'geçersiz moderasyon reddedilir');
$adm = RsShowcase::adminList('pending'); ok(count($adm) > 0 && !array_filter($adm, fn($x) => $x['status'] !== 'pending'), 'yönetici listesi duruma göre süzer'); ok(count(RsShowcase::adminList('hepsi')) >= count($adm), "'hepsi' süzmesiz");

section('Şablon (render ve XSS)');
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rs_compile_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true);
$xss = '<script>alert(1)</script>';
$blocks = [['code' => 'home_featured', 'title' => $xss, 'kind' => 'product', 'items' => [['kind' => 'product', 'title' => $xss, 'url' => '/u', 'image' => '', 'price' => 10.0, 'old_price' => 20.0], ['kind' => 'product', 'title' => 'x', 'url' => '/u', 'image' => '/i.jpg', 'price' => 5.5, 'old_price' => null]]], ['code' => 'home_ad', 'title' => 'Reklam', 'kind' => 'banner', 'items' => [['kind' => 'banner', 'title' => $xss, 'url' => '/k', 'image' => '/b.jpg', 'price' => null, 'old_price' => null]]]];
$smarty->setEscapeHtml(false); $smarty->assign('rsBlocks', $blocks); $html = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/front/showcase.tpl');
ok(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false && strpos($html, 'rel="sponsored noopener"') !== false && strpos($html, '10.00') !== false && strpos($html, '<s>20.00') !== false, 'vitrin şablonu: motor kaçışı kapalıyken bile XSS yok, banner sponsored, fiyat/eski fiyat');
$smarty->assign(['rsFlash' => ['type' => 'success', 'message' => $xss], 'rsToken' => 'tok', 'rsSlots' => RsShowcase::slots(), 'rsItems' => [['id_item' => 1, 'slot_title' => $xss, 'id_product' => 1, 'product_name' => $xss, 'title' => $xss, 'id_vendor' => 1, 'starts_at' => '', 'ends_at' => '', 'note' => $xss]], 'rsStatus' => 'pending']);
$html = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl'); ok(strpos($html, '<script>alert(1)</script>') === false && strpos($html, 'name="token"') !== false && strpos($html, 'item_moderate') !== false, 'admin şablonu: XSS yok, CSRF alanı, moderasyon formu');
$e = fn() => $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/front/showcase.tpl'); $smarty->assign('rsBlocks', []); ok(trim(strip_tags($e())) === '', 'boş vitrin çıktı üretmez');

section('Satıcı paneli entegrasyonu');
require_once dirname(__DIR__, 2) . '/rafimda-vendor/lib/RvPanel.php';
$v = ['id_vendor' => 1, 'store_name' => $xss, 'store_slug' => 's', 'status' => 'active', 'plan_code' => 'free']; $smarty->setEscapeHtml(true); $GLOBALS['smarty'] = $smarty;
$layout = ['rvTitle' => 'x', 'pageTitle' => 'T', 'vendor' => $v, 'roleLabel' => 'Sahip', 'memberships' => [$v], 'flash' => null, 'logoutUrl' => '/c', 'storefrontUrl' => '/', 'menu' => [['key' => 'campaigns', 'label' => 'K', 'icon' => '✦', 'ready' => true, 'url' => '/s']], 'section' => 'campaigns', 'can' => ['create' => true, 'edit' => true, 'delete' => true, 'staff' => true, 'store' => true]];
$vars = ['slots' => [['code' => 'home_featured', 'title' => $xss]], 'products' => [['id_product' => 1, 'product_name' => $xss]], 'items' => [['slot_title' => $xss, 'product_name' => $xss, 'starts_at' => '', 'ends_at' => '', 'status' => 'pending', 'note' => $xss, 'id_item' => 1]]];
ob_start(); RvView::display('panel_layout', $layout + $vars + ['contentFile' => 'file:' . RvView::tplDir() . 'panel_campaigns.tpl']); $html = ob_get_clean();
ok(strpos($html, '<script>alert(1)</script>') === false && strpos($html, 'showcase_request') !== false && strpos($html, 'name="token"') !== false && strpos($html, 'showcase_withdraw') !== false, 'kampanyalar sayfası: XSS yok, CSRF alanı, talep/geri çekme formları');
$code = file_get_contents(dirname(__DIR__, 2) . '/rafimda-vendor/lib/RvPanel.php');
ok(preg_match("/private static function campaigns.*?requireCsrf\('front'\);\s*self::deny\('products\.edit'\)/s", $code) === 1, 'talep POST: önce CSRF, sonra yetki');
ok(strpos($code, "class_exists('RsShowcase', false) ? self::campaigns") !== false, 'vitrin modülü kurulu değilse eski \"planlanan\" sayfa gösterilir (geriye uyumlu)');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n"; exit($fail ? 1 : 0);
