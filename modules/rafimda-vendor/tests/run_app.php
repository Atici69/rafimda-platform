<?php
/** Satıcı başvuru sistemi testleri (SQLite + gerçek Smarty; gerçek FriSay/MySQL/tarayıcı DEĞİL). */
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0;
if (!function_exists('ok')) { function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } } }
if (!function_exists('section')) { function section(string $t): void { echo "\n== $t ==\n"; } }
$tmp = sys_get_temp_dir() . '/rv_app_' . getmypid(); @mkdir($tmp, 0777, true);
RvApplication::$storageOverride = $tmp . '/docs';
RvApplication::$mover = static fn(string $a, string $b): bool => copy($a, $b);
$file = static function (string $name, string $content) use ($tmp): array { $p = $tmp . '/up_' . bin2hex(random_bytes(4)); file_put_contents($p, $content); return ['name' => $name, 'tmp_name' => $p, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK]; };
$PDF = "%PDF-1.4\n%test\n"; $PNG = "\x89PNG\r\n\x1a\n" . str_repeat('x', 30);
function fresh(): void { rv_fresh_db(); rv_apply_migrations(); DB::execute("INSERT INTO categories VALUES (3,'Elektronik',1)"); Settings::$d = []; }

section('Şema');
fresh();
foreach (['rafimda_vendor_applications', 'rafimda_vendor_app_docs', 'rafimda_vendor_app_log', 'rafimda_notifications'] as $t) { ok(DB::execute("SELECT name FROM sqlite_master WHERE name = '$t'") !== [], "tablo: $t"); }
$sql = file_get_contents(dirname(__DIR__) . '/migrations/006_applications.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE|UPDATE|ALTER)\b/i', $sql) && substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 4, '006 yalnızca CREATE TABLE IF NOT EXISTS');

section('Doğrulama');
ok(RvApplication::validTckn('10000000146') && !RvApplication::validTckn('10000000147') && !RvApplication::validTckn('01234567890') && !RvApplication::validTckn('1234'), 'T.C. kimlik algoritma kontrolü');
$store = ['store_name' => 'Yeni Dükkan', 'legal_name' => 'Yeni Dükkan Ltd. Şti.', 'tax_office' => 'Kadıköy', 'tax_number' => '1234567890', 'phone' => '05320000000', 'email' => 'dukkan@x.com', 'city' => 'İstanbul', 'description' => 'El yapımı ev tekstili ürünleri satıyoruz.'];
$in = ['person_type' => 'company', 'contact_name' => 'Ali Veli', 'address' => 'Moda Cad. No:1 Daire 2', 'district' => 'Kadıköy', 'categories' => [1, 3, 2, 999]];
$vs = RvVendor::validateStore($store); $va = RvApplication::validate($in, $vs['clean']);
ok($vs['ok'] && $va['ok'], 'tam tüzel kişi başvurusu geçerli');
ok(array_keys($va['clean']['categories']) === [1, 3], 'yalnızca etkin ve var olan kategoriler alınır (pasif/uydurma id atılır)');
$e = RvApplication::validate(['person_type' => 'company'] + $in, RvVendor::validateStore(['tax_number' => '12345678901', 'legal_name' => ''] + $store)['clean'])['errors'];
ok(isset($e['tax_number'], $e['legal_name']), 'tüzel kişi: 10 haneli VKN ve unvan zorunlu');
$e = RvApplication::validate(['person_type' => 'individual'] + $in, RvVendor::validateStore(['tax_number' => '12345678901'] + $store)['clean'])['errors'];
ok(isset($e['tax_number']), 'şahıs: geçersiz T.C. kimlik reddedilir');
$e = RvApplication::validate(['person_type' => 'individual'] + $in, RvVendor::validateStore(['tax_number' => '10000000146', 'legal_name' => ''] + $store)['clean'])['errors'];
ok($e === [], 'şahıs: geçerli T.C. kimlik ile unvansız başvuru geçerli');
$e = RvApplication::validate(['person_type' => 'x', 'categories' => [], 'address' => 'kısa'], RvVendor::validateStore(['description' => 'az', 'tax_office' => ''] + $store)['clean'])['errors'];
ok(isset($e['person_type'], $e['categories'], $e['address'], $e['description'], $e['tax_office'], $e['contact_name']), 'eksik alanlar tek tek bildirilir');

section('Başvuru kaydı');
$v = RvVendor::create(3, $vs['clean']);
$s = RvApplication::submit(3, $v['id_vendor'], $va['clean'], true);
ok($s['success'] && preg_match('/^SB' . date('Y') . '-\d{6}$/', $s['app_no']) === 1, 'başvuru numarası üretilir: ' . $s['app_no']);
$A = $s['id_application']; $app = RvApplication::get($A);
ok($app['status'] === 'new' && $app['kvkk_ack_at'] !== null && $app['category_list'] === ['Giyim', 'Elektronik'] && RvVendor::get($v['id_vendor'])['status'] === 'pending', 'durum Yeni, KVKK beyanı zamanı, kategoriler; mağaza onaya kadar pasif');
ok(!RvApplication::submit(3, $v['id_vendor'], $va['clean'], true)['success'], 'aynı kullanıcı ikinci açık başvuru yapamaz');
$n = DB::execute('SELECT audience, type, email_status FROM rafimda_notifications ORDER BY id_notification') ?: [];
ok(count($n) === 2 && $n[0]['audience'] === 'user' && $n[1]['audience'] === 'admin' && $n[0]['type'] === 'application', 'başvuru sahibine ve yöneticiye bildirim kaydı');
ok($n[0]['email_status'] === 'not_configured', 'e-posta sağlayıcısı yoksa durum açıkça "not_configured" kaydedilir (başarılı sayılmaz)');
ok(RvNotify::channelStatus() === ['email' => 'not_configured', 'sms' => 'not_available'], 'kanal durumu raporlanır (SMS altyapısı yok)');

section('Belge yükleme');
ok(RvApplication::addDocument($A, 3, $file('vergi.pdf', $PDF), 'tax_plate')['success'], 'PDF yüklenir');
$d = DB::execute('SELECT * FROM rafimda_vendor_app_docs WHERE id_application = ?', [$A])[0];
ok(preg_match('/^[a-f0-9]{32}\.pdf$/', $d['stored_name']) === 1 && $d['original_name'] === 'vergi.pdf' && strlen($d['sha256']) === 64, 'rastgele adla saklanır, SHA-256 kaydı');
ok(is_file($tmp . '/docs/.htaccess') && str_contains(file_get_contents($tmp . '/docs/.htaccess'), 'Require all denied'), 'belge dizini web erişimine kapalı (.htaccess)');
ok(!RvApplication::addDocument($A, 3, $file('kabuk.php', '<?php echo 1;'), 'other')['success'], '.php reddedilir');
ok(!RvApplication::addDocument($A, 3, $file('sahte.pdf', '<?php system($_GET[1]);'), 'other')['success'], 'uzantısı PDF ama içeriği PDF olmayan dosya reddedilir');
ok(!RvApplication::addDocument($A, 3, $file('resim.png', $PDF), 'other')['success'], 'PNG uzantılı PDF içerik reddedilir');
$big = $file('buyuk.pdf', $PDF . str_repeat('a', RvApplication::MAX_DOC_BYTES));
ok(!RvApplication::addDocument($A, 3, $big, 'other')['success'], '5 MB üstü reddedilir');
ok(!RvApplication::addDocument($A, 4, $file('x.pdf', $PDF), 'other')['success'], 'başkasının başvurusuna belge yüklenemez');
ok(RvApplication::addDocument($A, 3, $file('imza.png', $PNG), 'signature')['success'], 'PNG yüklenir');
ok(RvApplication::documentPath((int) $d['id_doc'], 4, false) === null, 'başka kullanıcı belgeyi indiremez');
ok(RvApplication::documentPath((int) $d['id_doc'], 3, false) !== null && RvApplication::documentPath((int) $d['id_doc'], 0, true) !== null, 'sahibi ve yönetici indirebilir');

section('Durum akışı ve bildirim');
ok(!RvApplication::transition($A, 'docs_required', 1, '')['success'], 'belge istemek not gerektirir');
ok(!RvApplication::transition($A, 'bogus', 1, 'x')['success'], 'tanımsız duruma geçilemez');
$r = RvApplication::transition($A, 'docs_required', 1, 'İmza sirküleri eksik.');
$last = DB::execute('SELECT * FROM rafimda_notifications ORDER BY id_notification DESC LIMIT 1')[0];
ok($r['success'] && RvApplication::get($A)['missing_docs'] === 'İmza sirküleri eksik.' && $last['type'] === 'documents' && str_contains($last['body'], 'İmza sirküleri eksik.') && (int) $last['id_user'] === 3, 'eksik belge bildirimi başvuru sahibine gider');
RvApplication::addDocument($A, 3, $file('imza2.pdf', $PDF), 'signature');
ok(RvApplication::get($A)['status'] === 'reviewing', 'belge yüklenince başvuru otomatik incelemeye döner');
$sent = [];
RvNotify::$mailer = static function (string $to, string $s, string $h) use (&$sent): bool { $sent[] = [$to, $s]; return true; };
$r = RvApplication::transition($A, 'approved', 1, '');
ok($r['success'] && RvVendor::get($v['id_vendor'])['status'] === 'active', 'onay mağazayı etkinleştirir');
ok($sent !== [] && $sent[0][0] === 'dukkan@x.com' && str_contains($sent[0][1], 'Onaylandı'), 'e-posta yapılandırılmışsa onay e-postası gönderilir');
RvNotify::$mailer = null;
ok(!RvApplication::transition($A, 'rejected', 1, 'geç kaldı')['success'] && !RvApplication::addDocument($A, 3, $file('z.pdf', $PDF), 'other')['success'], 'onaylanan başvuru kapanır (değiştirilemez, belge eklenmez)');
$h = RvApplication::history($A);
ok(array_column($h, 'to_status') === ['new', 'new', 'new', 'docs_required', 'reviewing', 'approved'], 'tüm geçişler ve belge yüklemeleri geçmişte: ' . implode(',', array_column($h, 'to_status')));

section('Ret ve yeniden inceleme');
$v2 = RvVendor::create(4, $vs['clean']);
$B = RvApplication::submit(4, $v2['id_vendor'], $va['clean'], true)['id_application'];
ok(!RvApplication::transition($B, 'rejected', 1, '')['success'], 'ret gerekçesiz yapılamaz');
ok(RvApplication::transition($B, 'rejected', 1, 'Vergi levhası okunaksız')['success'] && RvVendor::get($v2['id_vendor'])['status'] === 'rejected' && RvVendor::get($v2['id_vendor'])['rejection_reason'] === 'Vergi levhası okunaksız', 'ret mağazaya gerekçesiyle yansır');
ok(RvApplication::transition($B, 'reviewing', 1, '')['success'] && RvVendor::get($v2['id_vendor'])['status'] === 'pending', 'reddedilen başvuru yeniden incelemeye alınabilir');
RvVendor::setStatus($v2['id_vendor'], 'active', 1); RvApplication::syncFromVendor($v2['id_vendor'], 'active', 1);
ok(RvApplication::get($B)['status'] === 'approved', 'Satıcılar listesinden onay başvuruyu da onaylar (tutarlılık)');

section('Ekranlar (gerçek denetleyici + Smarty)');
function drive(array $cfg): string { return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/drive.php') . ' ' . escapeshellarg(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . ' 2>&1'); }
$o = drive(['user' => 3, 'register' => 1]);
foreach (['name="person_type"', 'value="individual"', 'value="company"', 'name="contact_name"', 'name="tax_office"', 'name="tax_number"', 'name="address"', 'name="district"', 'name="categories[]"', 'name="kvkk_ack"', 'name="accept_terms"'] as $f) { ok(str_contains($o, $f), "başvuru formu alanı: $f"); }
ok(!preg_match('/name="(kvkk_ack|accept_terms)"[^>]*checked/', $o), 'onay kutuları önceden seçili gelmez');
$post = ['person_type' => 'company', 'contact_name' => 'Ayşe Yılmaz', 'address' => 'Atatürk Bulvarı No:5', 'district' => 'Çankaya', 'categories' => ['1'], 'store_name' => 'Ayşe Butik', 'legal_name' => 'Ayşe Butik Ltd.', 'tax_office' => 'Çankaya', 'tax_number' => '1234567890', 'phone' => '05321234567', 'email' => 'ayse@x.com', 'city' => 'Ankara', 'description' => 'Kadın giyim ve aksesuar ürünleri satıyoruz.', 'accept_terms' => '1'];
$o = drive(['user' => 3, 'register' => 1, 'method' => 'POST', 'post' => $post, 'probes' => ['SELECT COUNT(*) FROM rafimda_vendor_applications']]);
ok(str_contains($o, 'KVKK aydınlatma metnini okuduğunuzu onaylayın') && str_contains($o, 'PROBE0:0') && !preg_match('/name="kvkk_ack"[^>]*checked/', $o) && preg_match('/name="accept_terms"[^>]*checked/', $o), 'KVKK beyanı olmadan başvuru alınmaz; yalnızca kullanıcının işaretlediği kutu korunur');
$o = drive(['user' => 3, 'register' => 1, 'method' => 'POST', 'post' => $post + ['kvkk_ack' => '1'], 'probes' => ["SELECT app_no || '|' || status || '|' || person_type FROM rafimda_vendor_applications", "SELECT status FROM rafimda_vendors WHERE store_name = 'Ayşe Butik'"]]);
ok(str_contains($o, 'REDIRECT:https://test.local/satici-panel') && preg_match('/PROBE0:"SB\d{4}-\d{6}\|new\|company"/', $o) === 1 && str_contains($o, 'PROBE1:"pending"'), 'gerçek akış: başvuru kaydı + pasif mağaza + panele yönlendirme');
$o = drive(['user' => 5, 'register' => 1, 'method' => 'POST', 'post' => ['store_name' => '"><script>alert(1)</script>', 'address' => '<img src=x onerror=alert(2)>'] + $post]);
ok(str_contains($o, 'KVKK aydınlatma') && !str_contains($o, '<script>alert(1)</script>') && !str_contains($o, '<img src=x') && str_contains($o, '&lt;script&gt;'), 'hatalı formun yeniden çiziminde XSS kaçışlanır');

section('Misafir (giriş yapmamış) başvuru akışları');
$gbase = ['person_type' => 'individual', 'contact_name' => 'Ayşe Yılmaz', 'address' => 'Atatürk Bulvarı No:5', 'district' => 'Çankaya', 'categories' => ['1'], 'store_name' => 'Ayşe El İşi', 'tax_office' => 'Çankaya', 'tax_number' => '10000000146', 'phone' => '05321234567', 'email' => 'ayse@x.com', 'city' => 'Ankara', 'description' => 'El yapımı ev tekstili ürünleri satıyoruz.', 'accept_terms' => '1', 'kvkk_ack' => '1'];
$gp = ["SELECT group_concat(v.owner_user_id || ':' || v.status || ':' || COALESCE(a.app_no,'-'), ',') FROM rafimda_vendors v LEFT JOIN rafimda_vendor_applications a ON a.id_vendor = v.id_vendor WHERE v.store_name = 'Ayşe El İşi'"];
$o = drive(['user' => 0, 'register' => 1]);
ok(str_contains($o, 'name="account_email"') && str_contains($o, 'name="password2"') && str_contains($o, 'yeni hesap açıyorsanız') === false && str_contains($o, 'Yeni hesap açıyorsanız'), 'misafir formu: hesap alanları ve açıklama');
$o = drive(['user' => 0, 'register' => 1, 'method' => 'POST', 'post' => $gbase + ['account_email' => 'yeni@x.com', 'password' => 'Sifre12345!', 'password2' => 'Sifre12345!', 'full_name' => 'Ayşe Yılmaz', 'account_phone' => '05321234567'], 'probes' => $gp]);
ok(preg_match('/PROBE0:"5:pending:SB\d{4}-\d{6}"/', $o) === 1 && str_contains($o, 'Başvuru numaranız') && str_contains($o, 'Üyelik hesabınız da yönetici onayı bekliyor'), 'yeni hesap (üyelik onayı bekleyen): hesap + başvuru oluşur, açık bilgilendirme');
$o = drive(['user' => 0, 'register' => 1, 'activate' => 1, 'method' => 'POST', 'post' => $gbase + ['account_email' => 'yeni@x.com', 'password' => 'Sifre12345!', 'password2' => 'Sifre12345!', 'full_name' => 'Ayşe Yılmaz', 'account_phone' => '05321234567'], 'probes' => $gp]);
ok(str_contains($o, 'REDIRECT:https://test.local/satici-panel') && preg_match('/PROBE0:"5:pending:SB/', $o) === 1, 'yeni hesap (hemen aktif): başvuru + panele (başvuru durumu) yönlendirme');
$o = drive(['user' => 0, 'register' => 1, 'method' => 'POST', 'post' => $gbase + ['account_email' => 'yeni@x.com', 'password' => 'Sifre12345!', 'password2' => 'farkli', 'full_name' => 'A B'], 'probes' => $gp]);
ok(str_contains($o, 'Şifreler eşleşmiyor') && str_contains($o, 'PROBE0:null'), 'şifre tekrarı uyuşmazsa kayıt yok');
$o = drive(['user' => 0, 'register' => 1, 'method' => 'POST', 'post' => $gbase + ['account_email' => 'musteri@x.com', 'password' => 'yanlis'], 'probes' => $gp]);
ok(str_contains($o, 'Bu e-posta zaten kayıtlı') && str_contains($o, 'PROBE0:null'), 'kayıtlı e-posta + yanlış şifre: başvuru devralınamaz');
$o = drive(['user' => 0, 'register' => 1, 'method' => 'POST', 'post' => $gbase + ['account_email' => 'musteri@x.com', 'password' => 'DogruSifre1!'], 'probes' => $gp]);
ok(preg_match('/PROBE0:"3:pending:SB/', $o) === 1, 'kayıtlı e-posta + doğru şifre: başvuru o hesaba bağlanır');
$o = drive(['user' => 0, 'register' => 1, 'method' => 'POST', 'post' => ['person_type' => 'company'] + $gbase + ['account_email' => 'musteri@x.com', 'password' => 'DogruSifre1!'], 'probes' => $gp]);
ok(str_contains($o, '10 haneli vergi kimlik') && str_contains($o, 'Şirket unvanını') && str_contains($o, 'PROBE0:null'), 'tüzel kişi seçilip TCKN girilirse anlaşılır hata, kayıt yok');

$o = drive(['user' => 1, 'get' => ['s' => 'dashboard'], 'pending_app' => 1]);
ok(str_contains($o, 'Başvuru numarası') && str_contains($o, 'enctype="multipart/form-data"') && str_contains($o, 'Belge Bekleniyor') && str_contains($o, 'Eksik belge bildirimi') && str_contains($o, 'Vergi levhası eksik'), 'onay bekleyen satıcı: başvuru no, durum adımları, eksik belge bildirimi, yükleme formu');
$o = drive(['user' => 2, 'get' => ['s' => 'dashboard'], 'pending_app' => 1]);
ok(!str_contains($o, 'enctype="multipart/form-data"'), 'personel (başvuru sahibi olmayan) belge yükleyemez');
$o = drive(['user' => 1, 'get' => ['s' => 'dashboard', 'a' => 'doc', 'id' => 999], 'pending_app' => 1]);
ok(!str_contains($o, 'Başvuru numarası'), 'olmayan/başkasına ait belge indirilemez (404)');

$o = drive(['admin' => 1, 'get' => [], 'pending_app' => 1]);
ok(str_contains($o, 'SB') && str_contains($o, 'Moda Evi') && str_contains($o, 'İncele') && str_contains($o, 'Belge Bekleniyor: 1'), 'yönetici başvuru listesi ve durum sayaçları');
$o = drive(['admin' => 1, 'get' => ['app' => 1], 'pending_app' => 1]);
ok(str_contains($o, 'name="rvAction" value="app_transition"') && str_contains($o, 'Vergi levhası eksik') && str_contains($o, 'Kadıköy') && str_contains($o, 'Giyim'), 'yönetici başvuru detayı: bilgiler, kategoriler, inceleme formu, geçmiş');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
