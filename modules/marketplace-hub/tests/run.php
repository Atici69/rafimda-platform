<?php
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0; $failures = [];
function ok(bool $c, string $n): void { global $pass, $fail, $failures; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; $failures[] = $n; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }

section('Migration');
mh_fresh_db();
$raw = file_get_contents(dirname(__DIR__) . '/migrations/001_init.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|ALTER|UPDATE)\b/i', $raw) && !preg_match('/^\s*--/m', $raw) && preg_match_all('/CREATE TABLE(?! IF NOT EXISTS)/', $raw) === 0, 'migration yıkıcı değil (CREATE TABLE IF NOT EXISTS)');
foreach (['mh_accounts', 'mh_category_map', 'mh_listings', 'mh_errors'] as $t) { ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "tablo: $t"); }
$GLOBALS['db']->exec(xh_mysql_to_sqlite($raw));
ok(MhMigrator::pending() === [], 'migration tekrar çalışınca hata yok, bekleyen yok');
DB::execute("INSERT INTO mh_accounts (id_vendor, marketplace, label) VALUES (1,'ozon','x')");
foreach (['mh_errors', 'mh_listings', 'mh_category_map', 'mh_accounts'] as $t) { DB::execute("DROP TABLE $t"); }
DB::execute("DELETE FROM mh_migrations");
ok(MhMigrator::pending() === ['001_init'] && DB::execute("SELECT name FROM sqlite_master WHERE name = 'products'") !== [] && (int) DB::getValue('SELECT COUNT(*) FROM categories') === 2, 'geri alma: modül tabloları kalkınca çekirdek tablolar ve veriler sağlam');
mh_fresh_db(); // geri alma testinden sonra şemayı yeniden kur
foreach (['install.sql', 'uninstall.sql', 'migrations/001_init.sql'] as $f) {
	$stm = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', file_get_contents(dirname(__DIR__) . '/' . $f))));
	ok(array_filter($stm, fn($s) => strpos($s, '--') === 0) === [], "$f: '--' ile başlayan ifade yok (runSqlFile atlamaz)");
}
ok(strpos(file_get_contents(dirname(__DIR__) . '/uninstall.sql'), 'DROP') === false, 'uninstall.sql veri silmiyor');

section('Şifreleme');
Settings::set('MARKETPLACE_HUB_SECRET', '');
$e = MhCrypt::encrypt('api-secret-değeri');
ok($e !== '' && strpos($e, 'api-secret') === false && MhCrypt::decrypt($e) === 'api-secret-değeri', 'gidiş-dönüş, düz metin yok');
ok(MhCrypt::decrypt(substr($e, 0, -3) . 'AAA') === '' && MhCrypt::decrypt('x') === '', 'bozuk veri boş döner');
$old = Settings::get('MARKETPLACE_HUB_SECRET'); Settings::set('MARKETPLACE_HUB_SECRET', bin2hex(random_bytes(32)));
ok(MhCrypt::decrypt($e) === '', 'farklı anahtarla çözülemez'); Settings::set('MARKETPLACE_HUB_SECRET', $old);

section('Bağdaştırıcılar: gerçek API yok, sahte sonuç yok');
$all = MhRegistry::all();
ok(array_keys($all) === ['trendyol', 'hepsiburada', 'n11', 'amazon', 'pazarama', 'ozon'], '6 pazaryeri kayıtlı');
foreach ($all as $k => $a) {
	$t = $a->testConnection(['x' => 'y']); $s = $a->submit([], ['id_product' => 1]);
	ok(!$a->implemented() && !$t['success'] && !$s['success'] && $s['remote_id'] === '' && $s['retryable'] === false && $a->credentialFields() !== [], "$k: implemented=false, test/gönderim BAŞARISIZ döner (sahte başarı yok)");
}

section('Hesaplar ve kimlik bilgisi güvenliği');
DB::execute("INSERT INTO rafimda_vendors (owner_user_id, store_name, store_slug, status) VALUES (1,'A','a','active'),(2,'B','b','active')");
$vA = 1; $vB = 2;
$r = MhRepo::createAccount($vA, 'trendyol', 'Trendyol Ana', ['supplier_id' => '1234', 'api_key' => 'KEY-ABC', 'api_secret' => 'SECRET-XYZ']);
$acc = $r['id'];
ok($r['success'] && strpos($r['message'], 'henüz yapılmadı') !== false, 'hesap eklendi; API yok uyarısı verildi');
$dump = json_encode(DB::execute('SELECT * FROM mh_accounts'));
ok(strpos($dump, 'KEY-ABC') === false && strpos($dump, 'SECRET-XYZ') === false && strpos($dump, '1234') === false, 'veritabanında API anahtarı/secret düz metin değil');
ok(MhRepo::credentials($acc) === ['supplier_id' => '1234', 'api_key' => 'KEY-ABC', 'api_secret' => 'SECRET-XYZ'], 'gönderim anında çözülür');
$list = json_encode(MhRepo::accounts($vA)) . json_encode(MhRepo::account($acc, $vA)) . json_encode(MhRepo::credentialStatus($acc));
ok(strpos($list, 'KEY-ABC') === false && strpos($list, 'SECRET-XYZ') === false && strpos($list, 'credentials_enc') === false, 'liste/ayrıntı/durum çıktılarında kimlik bilgisi veya şifreli sütun yok');
ok(MhRepo::credentialStatus($acc) === ['supplier_id' => true, 'api_key' => true, 'api_secret' => true], 'yalnızca dolu/boş bilgisi döner');
ok(!MhRepo::createAccount($vA, 'trendyol', 'Eksik', ['supplier_id' => '1'])['success'] && !MhRepo::createAccount($vA, 'yok', 'Yok', [])['success'] && !MhRepo::createAccount($vA, 'ozon', 'X', ['client_id' => "a\nb", 'api_key' => 'k'])['success'] && !MhRepo::createAccount($vA, 'ozon', 'Çok', ['client_id' => str_repeat('a', 100), 'api_key' => 'k'])['success'], 'eksik/bilinmeyen/denetim karakterli/çok uzun kimlik reddedilir');
$u = MhRepo::updateCredentials($acc, $vA, ['supplier_id' => '9999', 'api_key' => '', 'api_secret' => '']);
ok($u['success'] && MhRepo::credentials($acc) === ['supplier_id' => '9999', 'api_key' => 'KEY-ABC', 'api_secret' => 'SECRET-XYZ'], 'boş bırakılan gizli alan eski değeri korur');
ok(MhRepo::account($acc, $vB) === null && MhRepo::accounts($vB) === [] && !MhRepo::updateCredentials($acc, $vB, ['supplier_id' => '1', 'api_key' => 'h', 'api_secret' => 'h'])['success'] && !MhRepo::deleteAccount($acc, $vB) && MhRepo::credentials($acc)['api_key'] === 'KEY-ABC', 'B, A\'nın hesabını göremez/güncelleyemez/silemez (IDOR)');

section('Kategori eşleme');
ok(MhRepo::setCategoryMap($acc, $vA, 1, 'TY-123', 'Giyim')['success'] && MhRepo::categoryMap($acc)[1]['remote_category_id'] === 'TY-123', 'eşleme kaydedildi');
ok(MhRepo::setCategoryMap($acc, $vA, 1, 'TY-999')['success'] && count(MhRepo::categoryMap($acc)) === 1 && MhRepo::categoryMap($acc)[1]['remote_category_id'] === 'TY-999', 'aynı kategori güncellenir (tekrar yok)');
ok(!MhRepo::setCategoryMap($acc, $vB, 2, 'X')['success'] && !MhRepo::setCategoryMap($acc, $vA, 999, 'X')['success'] && !MhRepo::setCategoryMap($acc, $vA, 2, "x';DROP")['success'], 'başkasının hesabı / var olmayan kategori / geçersiz kod reddedilir');
ok(MhRepo::setCategoryMap($acc, $vA, 2, 'TY-5')['success'] && MhRepo::setCategoryMap($acc, $vA, 2, '')['success'] && !isset(MhRepo::categoryMap($acc)[2]), 'eşleme kaldırılabilir');

section('Kuyruk: gerçek API yokken gönderilmiş gibi davranılmaz');
Settings::set('RAFIMDA_PRODUCT_MODERATION', '0');
$mkP = function (int $vendor, string $sku, int $cat = 1, string $price = '50') { $_SERVER['REQUEST_METHOD'] = 'GET'; $r = RvProducts::create($vendor, ['product_name' => 'Ürün ' . $sku, 'id_category' => $cat, 'id_brand' => 1, 'price' => $price, 'stock' => 5, 'stock_code' => $sku, 'barcode' => '', 'description' => 'd', 'active' => 1]); return (int) ($r['id'] ?? 0); };
$pA1 = $mkP($vA, 'A1'); $pA2 = $mkP($vA, 'A2', 2); $pB1 = $mkP($vB, 'B1');
ok($pA1 > 0 && $pA2 > 0 && $pB1 > 0, 'test ürünleri oluşturuldu');
$q = MhDispatcher::queue($acc, $vA, [$pA1, $pA2, $pB1]);
ok($q['rejected'] === 1 && $q['blocked'] === 2 && $q['queued'] === 0, 'B\'nin ürünü reddedildi; uygulanmamış API nedeniyle A ürünleri "blocked" (kuyruğa/gönderime ALINMADI)');
ok((int) DB::getValue("SELECT COUNT(*) FROM mh_listings WHERE state = 'sent'") === 0 && (int) DB::getValue('SELECT COUNT(*) FROM mh_listings WHERE id_product = ?', [$pB1]) === 0, 'hiçbir ürün "sent" değil, B ürünü kayıt bile edilmedi');
ok(MhDispatcher::tick(20)['processed'] === 0, 'tick: engellenenler işlenmez');
ok(strpos((string) DB::getValue('SELECT error FROM mh_listings WHERE id_product = ?', [$pA1]), 'henüz yapılmadı') !== false, 'engel nedeni kullanıcıya görünür');
$pl = MhPayload::build($pA1, $acc);
ok($pl['ok'] && $pl['payload']['category']['remote_category_id'] === 'TY-999' && $pl['payload']['sku'] === 'A1' && $pl['payload']['source'] === ['type' => 'manual'], 'payload: eşlenmiş kategori, SKU, kaynak=manual');
$pl2 = MhPayload::build($pA2, $acc);
ok(!$pl2['ok'] && strpos($pl2['error'], 'eşleştirilmemiş') !== false, 'kategorisi eşlenmeyen ürün için payload kurulamaz');

section('Kuyruk: bağdaştırıcı çalışırken (test bağdaştırıcısı)');
$ta = new MhTestAdapter(); MhRegistry::register($ta);
$r2 = MhRepo::createAccount($vA, 'testmp', 'Test Hesap', ['token' => 'good', 'shop' => 'm1']); $ac2 = $r2['id'];
ok($r2['success'] && strpos($r2['message'], 'henüz') === false, 'implemented bağdaştırıcıda uyarı yok');
MhRepo::setCategoryMap($ac2, $vA, 1, 'C1');
DB::execute('INSERT INTO xh_product_meta (id_product, id_vendor, source_type, id_connection, id_supplier, cargo_price) VALUES (?,?,?,?,?,?)', [$pA1, $vA, 'xml', 7, 3, 19.9]);
$q = MhDispatcher::queue($ac2, $vA, [$pA1, $pA2, $pB1]);
ok($q['queued'] === 1 && $q['blocked'] === 1 && $q['rejected'] === 1, 'kuyruğa 1, kategori eşlenmediği için engellenen 1, başkasının ürünü reddedilen 1');
$t = MhDispatcher::tick(20);
ok($t['processed'] === 1 && $t['sent'] === 1 && count($ta->calls) === 1, 'ürün gönderildi');
ok($ta->calls[0]['creds'] === ['token' => 'good', 'shop' => 'm1'] && $ta->calls[0]['payload']['cargo_price'] === 19.9 && $ta->calls[0]['payload']['source'] === ['type' => 'xml', 'id_connection' => 7, 'id_supplier' => 3], 'bağdaştırıcı çözülmüş kimliği ve XML Hub verisini (kargo, kaynak, tedarikçi) alır');
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1]) === 'sent' && DB::getValue('SELECT remote_id FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1]) === 'R' . $pA1, 'durum sent, uzak ID kaydedildi');
// yeniden deneme
$ta->script = [['success' => false, 'error' => 'HTTP 503', 'retryable' => true]];
MhDispatcher::queue($ac2, $vA, [$pA1]);
$t = MhDispatcher::tick(20);
$row = DB::execute('SELECT * FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1])[0];
ok($t['retried'] === 1 && $row['state'] === 'queued' && (int) $row['attempts'] === 1 && strtotime($row['next_attempt_at']) > time() + 50, 'geçici hata: yeniden denenecek, geri çekilme 60 sn');
ok(MhDispatcher::tick(20)['processed'] === 0, 'bekleme süresi dolmadan tekrar işlenmez');
$ta->script = [['success' => false, 'error' => 'HTTP 503', 'retryable' => true], ['success' => false, 'error' => 'HTTP 503', 'retryable' => true]];
DB::execute('UPDATE mh_listings SET next_attempt_at = ? WHERE id_account = ?', [date('Y-m-d H:i:s', time() - 5), $ac2]); MhDispatcher::tick(20);
DB::execute('UPDATE mh_listings SET next_attempt_at = ? WHERE id_account = ?', [date('Y-m-d H:i:s', time() - 5), $ac2]); $t = MhDispatcher::tick(20);
$row = DB::execute('SELECT * FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1])[0];
ok($t['failed'] === 1 && $row['state'] === 'failed' && (int) $row['attempts'] === MhDispatcher::MAX_ATTEMPTS, 'en fazla ' . MhDispatcher::MAX_ATTEMPTS . ' deneme, sonra failed (sonsuz döngü yok)');
ok(MhDispatcher::retryFailed($ac2, $vB) === 0 && MhDispatcher::retryFailed($ac2, $vA) === 1 && DB::getValue('SELECT state FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1]) === 'queued', 'başarısızlar yalnızca sahibi tarafından yeniden kuyruğa alınır');
$ta->script = [['success' => false, 'error' => 'Geçersiz barkod', 'retryable' => false]]; MhDispatcher::tick(20);
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1]) === 'failed' && DB::getValue('SELECT attempts FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1]) == 1, 'kalıcı hata: tekrar denenmez');
MhDispatcher::retryFailed($ac2, $vA); $ta->script = [['throw' => true]]; MhDispatcher::tick(20);
ok(DB::getValue('SELECT state FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1]) === 'queued' && strpos((string) DB::getValue('SELECT error FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1]), 'patladı') !== false, 'bağdaştırıcı istisnası yakalanır, yeniden denenir');
DB::execute("UPDATE mh_listings SET state = 'sending', error = 'claim:x', date_upd = ? WHERE id_account = ? AND id_product = ?", [date('Y-m-d H:i:s'), $ac2, $pA1]);
ok(MhDispatcher::tick(20)['processed'] === 0, 'başka işçinin aldığı (sending) kayıt işlenmez');
DB::execute("UPDATE mh_listings SET date_upd = ?, next_attempt_at = ? WHERE id_account = ? AND id_product = ?", [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 5), $ac2, $pA1]);
ok(MhDispatcher::tick(20)['processed'] === 1, 'bayat kilit (çöken işçi) devralınır');
$errs = json_encode(DB::execute('SELECT * FROM mh_errors')) . json_encode(DB::execute('SELECT * FROM mh_listings'));
ok(strpos($errs, 'KEY-ABC') === false && strpos($errs, '"good"') === false && count(MhRepo::errors($vA, $ac2)) >= 3 && MhRepo::errors($vB) === [], 'hata günlüğü dolu, kimlik bilgisi içermiyor, B göremez');
// sahiplik kaybı
DB::execute('DELETE FROM rafimda_vendor_products WHERE id_product = ?', [$pA1]);
DB::execute("UPDATE mh_listings SET state = 'queued', next_attempt_at = ? WHERE id_account = ?", [date('Y-m-d H:i:s', time() - 5), $ac2]);
$n = count($ta->calls); MhDispatcher::tick(20);
ok(count($ta->calls) === $n && DB::getValue('SELECT state FROM mh_listings WHERE id_account = ? AND id_product = ?', [$ac2, $pA1]) === 'failed', 'ürün satıcıdan alınmışsa gönderilmez (sahiplik gönderim anında da doğrulanır)');

section('Panel: izolasyon ve şablonlar');
$call = function (int $vendor, string $method, array $get, array $post = [], bool $admin = false) {
	$_SERVER['REQUEST_METHOD'] = $method; $_GET = $get; $_POST = $post; $_SESSION = [];
	try { $p = new MhPanel($vendor, $admin, 'https://t.local/pazaryeri'); $r = $p->handle(); return ['view' => $r[0], 'vars' => $r[1], 'redirect' => null, 'flash' => null]; }
	catch (RuntimeException $e) { return ['view' => null, 'vars' => [], 'redirect' => substr($e->getMessage(), 9), 'flash' => $_SESSION['mh_flash'] ?? null]; }
};
$r = $call($vB, 'GET', ['view' => 'account', 'id' => $acc]);
ok($r['redirect'] !== null && $r['flash']['type'] === 'danger', 'B, A\'nın hesap sayfasını açamaz');
$r = $call($vA, 'GET', ['view' => 'account', 'id' => $acc, 'tab' => 'general']);
ok($r['view'] === 'account' && strpos(json_encode($r['vars']), 'KEY-ABC') === false && strpos(json_encode($r['vars']), 'SECRET-XYZ') === false, 'A kendi hesabını görür; ekran verisinde kimlik bilgisi yok');
foreach ([['account_cred_save', ['cred' => ['supplier_id' => '1', 'api_key' => 'h', 'api_secret' => 'h']]], ['account_test', []], ['account_delete', []], ['category_map_save', ['remote' => [1 => 'EVIL']]], ['queue_products', ['ids' => [$pA1]]], ['retry_failed', []]] as [$act, $extra]) {
	$before = json_encode([DB::execute('SELECT * FROM mh_accounts'), DB::execute('SELECT * FROM mh_category_map'), DB::execute('SELECT * FROM mh_listings')]);
	$r = $call($vB, 'POST', [], ['mhAction' => $act, 'id_account' => $acc] + $extra);
	ok($r['flash']['type'] !== 'success' && json_encode([DB::execute('SELECT * FROM mh_accounts'), DB::execute('SELECT * FROM mh_category_map'), DB::execute('SELECT * FROM mh_listings')]) === $before, "B, A'nın hesabında '$act' yapamaz");
}
$r = $call($vB, 'POST', [], ['mhAction' => 'account_create', 'marketplace' => 'n11', 'label' => 'B N11', 'cred' => ['app_key' => 'k', 'app_secret' => 's'], 'id_vendor' => 1]);
ok((int) DB::getValue("SELECT id_vendor FROM mh_accounts WHERE label = 'B N11'") === $vB, 'hesap sahibi oturumdan belirlenir (formdan gelen id_vendor yok sayılır)');
$r = $call($vB, 'GET', ['view' => 'list']);
ok(count($r['vars']['accounts']) === 1 && $r['vars']['accounts'][0]['label'] === 'B N11', 'B yalnızca kendi hesabını listeler');
$r = $call(0, 'POST', [], ['mhAction' => 'queue_tick'], true);
ok($r['flash']['type'] === 'success', 'yönetici kuyruğu elle işleyebilir');
$r = $call($vA, 'POST', [], ['mhAction' => 'queue_tick']);
ok($r['flash']['type'] === 'danger', 'satıcı yönetici eylemini (queue_tick) yapamaz');
// şablonlar
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$tplDir = dirname(__DIR__) . '/assets/templates/';
foreach (['front/mh_content.tpl', 'front/mh_front.tpl', 'front/mh_listings.tpl', 'front/mh_errors.tpl', 'admin/admin.tpl'] as $t) {
	$src = file_get_contents($tplDir . $t); preg_match_all('/\{\$[^{}]*\}/', $src, $m);
	$bad = array_filter($m[0], fn($o) => !preg_match('/\|\s*(escape|count)\b/', $o) && !preg_match('/\{\$mhTplDir\}/', $o));
	ok($bad === [] && strpos($src, 'nofilter') === false, "$t: tüm çıktılar |escape, nofilter yok" . ($bad ? ' → ' . implode(' ', array_slice($bad, 0, 3)) : ''));
}
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/mh_compile_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true);
$xss = '<script>alert(1)</script>';
$accX = ['id_account' => 1, 'marketplace' => $xss, 'label' => $xss, 'has_credentials' => 1, 'last_test_at' => 'd', 'last_error' => $xss, 'status' => 'draft', 'id_vendor' => 1];
$mk = ['trendyol' => ['key' => 'trendyol', 'title' => $xss, 'implemented' => false, 'fields' => ['a' => ['label' => $xss, 'secret' => true, 'required' => true, 'max' => 10]]]];
$base = ['mhFlash' => ['type' => 'danger', 'message' => $xss], 'mhToken' => $xss, 'mhCanManage' => true, 'mhBase' => '/b', 'mhTplDir' => $tplDir . 'front/', 'mhCss' => '/c.css', 'panelUrl' => '/p', 'mhContent' => 'file:' . $tplDir . 'front/mh_content.tpl', 'mhPending' => [], 'mhCronUrl' => $xss, 'adminToken' => 't'];
$lst = [['id_product' => 1, 'marketplace' => $xss, 'state' => $xss, 'attempts' => 1, 'error' => $xss, 'date_add' => $xss, 'code' => $xss, 'message' => $xss]];
$acct = ['acc' => $accX, 'adapter' => ['title' => $xss, 'implemented' => false, 'fields' => $mk['trendyol']['fields']], 'credStatus' => ['a' => true], 'counts' => ['blocked' => 2], 'catMap' => [1 => ['remote_category_id' => $xss, 'remote_category_name' => $xss]], 'categories' => [['id_category' => 1, 'category_name' => $xss]], 'products' => [['id_product' => 1, 'product_name' => $xss, 'stock_code' => $xss, 'id_category' => 9]], 'listings' => $lst, 'errors' => $lst];
$views = ['list' => ['accounts' => [$accX], 'marketplaces' => $mk], 'listings' => ['listings' => $lst], 'errors' => ['errors' => $lst]];
foreach (['general', 'categories', 'products', 'listings', 'errors'] as $tab) { $views["account:$tab"] = $acct + ['tab' => $tab]; }
foreach ([false, true] as $auto) {
	foreach ($views as $name => $vars) {
		$smarty->setEscapeHtml($auto); $smarty->assign(array_merge($base, $vars, ['view' => explode(':', $name)[0]]));
		try { $html = $smarty->fetch('file:' . $tplDir . 'front/mh_content.tpl'); $o = true; } catch (Throwable $e) { $html = $e->getMessage(); $o = false; }
		ok($o && strpos($html, '<script>alert(1)</script>') === false && strlen($html) > 100 && strpos($html, '&amp;lt;') === false, "görünüm '$name' render + XSS kaçışı (otomatik kaçış " . ($auto ? 'açık' : 'kapalı') . ')' . ($o ? '' : " ($html)"));
	}
}
$smarty->setEscapeHtml(false); $smarty->assign(array_merge($base, $views['list'], ['view' => 'list']));
$html = $smarty->fetch('file:' . $tplDir . 'admin/admin.tpl');
ok(strpos($html, '<script>alert(1)</script>') === false && strlen($html) > 200 && strpos($html, 'gerçek API entegrasyonu yoktur') !== false, 'admin şablonu render: XSS kaçışı ve "gerçek API yok" uyarısı görünür');

section('Gerçek RvContext/RvPerm ile oturum/RBAC kapıları (Pazaryeri Hub)');
$rbac = fn(string $args): string => trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(dirname(__DIR__, 2) . '/xml-hub/tests/rbac_probe.php') . ' ' . $args . ' 2>/dev/null'));
foreach ([['0 GET', '302', 'oturumsuz kullanıcı giriş sayfasına yönlenir'], ['6 GET', '403', 'satıcı olmayan oturum 403'], ['2 GET', '403', 'viewer rolü (marketplaces.view yok) 403'], ['3 GET', '403', 'orders rolü 403'], ['1 GET', '200|PAGE|FORMS', 'owner sayfayı ve formları görür'], ['4 GET', '403', 'catalog rolü (marketplaces.view yok) 403'], ['5 GET', '200|PAGE|FORMS', 'manager rolü erişir'], ['7 GET', '200|PAGE|FORMS', 'başka satıcının sahibi yalnızca kendi alanını görür'], ['3 POST', '403', 'orders rolü POST yapamaz'], ['2 POST', '403', 'viewer POST yapamaz'], ['6 POST', '403', 'satıcı olmayan POST yapamaz'], ['1 POST', '302', 'owner POST (CSRF geçerli) işlenir']] as [$a, $exp, $name]) {
	$o = $rbac('mh ' . $a); ok(strpos($o, $exp) === 0, "Pazaryeri Hub: $name [$o]");
}


section('Cron güvenliği');
$probe = fn(string $k, string $m = '') => trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(__DIR__ . '/cron_probe.php') . ' ' . escapeshellarg($k) . ' ' . escapeshellarg($m) . ' 2>/dev/null'));
$o = $probe('-'); ok(substr($o, -4) === '|403', 'anahtarsız 403');
$o = $probe('YANLIS'); ok(substr($o, -4) === '|403' && strpos($o, 'YANLIS') === false, 'yanlış anahtar 403');
$o = $probe('MH-GIZLI-KEY-1'); ok(substr($o, -4) === '|200' && strpos($o, '"ok":true') !== false, 'doğru anahtar tick çalıştırır');
$o = $probe('MH-GIZLI-KEY-1', 'pending'); ok(substr($o, -4) === '|503', 'bekleyen migration varken 503');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
foreach ($failures as $f) { echo " BAŞARISIZ: $f\n"; }
exit($fail ? 1 : 0);
