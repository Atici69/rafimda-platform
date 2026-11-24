<?php
/** rafimda-legal testleri (SQLite + gerçek Smarty; gerçek FriSay/MySQL/tarayıcı DEĞİL). Hukuki doğruluk testi DEĞİLDİR. */
ob_start(); // CLI: http_response_code için başlık gönderilmemiş kalsın
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
require_once dirname(__DIR__) . '/rafimda-legal.php';
$pass = 0; $fail = 0;
if (!function_exists('ok')) { function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } } }
if (!function_exists('section')) { function section(string $t): void { echo "\n== $t ==\n"; } }
function fresh(): void {
	rv_fresh_db(); rv_apply_migrations(); Settings::$d = []; $_SESSION = []; $_COOKIE = [];
	foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/install.sql'))))) as $st) { $GLOBALS['db']->exec($st); }
	RlLegal::installDefaults();
}
$smarty = new Smarty\Smarty(); $smarty->setCompileDir(sys_get_temp_dir() . '/rl_c_' . getmypid()); @mkdir($smarty->getCompileDir(), 0777, true); $GLOBALS['smarty'] = $smarty;
$smarty->registerPlugin('modifier', 'adminT', static fn($s) => $s);
$GLOBALS['domain'] = 'https://test.local/';
$tpl = static function (string $file, array $vars) use ($smarty): string { $smarty->clearAllAssign(); $smarty->assign($vars); return $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/' . $file . '.tpl'); };

section('Kurulum ve taslak belgeler');
fresh();
$need = ['kvkk-aydinlatma', 'acik-riza', 'gizlilik-politikasi', 'cerez-politikasi', 'cerez-tercihleri', 'kullanim-kosullari', 'uyelik-sozlesmesi', 'satici-sozlesmesi', 'pazaryeri-aracilik-sozlesmesi', 'on-bilgilendirme-formu', 'mesafeli-satis-sozlesmesi', 'iptal-iade-politikasi', 'cayma-hakki', 'teslimat-kargo', 'sikayet-uyusmazlik', 'ticari-ileti-izni', 'kvkk-basvuru', 'bayi-sozlesmesi', 'alt-bayi-sozlesmesi'];
$slugs = array_column(RlLegal::all(), 'slug');
ok(array_diff($need, $slugs) === [], 'istenen 16 belge + açık rıza + 2 bayilik taslağı kurulur (' . count($slugs) . ')');
ok((int) DB::getValue('SELECT COUNT(*) FROM rl_documents WHERE needs_review = 0') === 0, 'tüm belgeler "hukuki inceleme gerekli" işaretli başlar');
ok(RlLegal::bySlug('bayi-sozlesmesi')['status'] === 'passive' && RlLegal::bySlug('alt-bayi-sozlesmesi')['status'] === 'passive', 'bayilik sözleşmeleri pasif taslak');
ok(RlLegal::published('bayi-sozlesmesi') === null && RlLegal::linksFor(['bayi-sozlesmesi']) === [], 'bayilik sözleşmesi kullanıcıya gösterilmez');
ok(!RlLegal::setMeta((int) RlLegal::bySlug('bayi-sozlesmesi')['id_document'], 'published', true, ''), 'bayilik sistemi etkin değilken bayilik sözleşmesi yayınlanamaz');
$id = (int) RlLegal::bySlug('kvkk-aydinlatma')['id_document'];
RlLegal::saveVersion($id, "## Düzenlenmiş\nAvukat onaylı metin burada yer alır.", 'avukat', 1, true);
RlLegal::installDefaults();
ok((int) DB::getValue('SELECT COUNT(*) FROM rl_documents') === count($slugs) && str_contains(RlLegal::published('kvkk-aydinlatma')['version']['body'], 'Avukat onaylı'), 'yeniden kurulum mevcut belgeleri ve düzenlemeleri KORUR');
$inst = file_get_contents(dirname(__DIR__) . '/install.sql');
ok(!preg_match('/\b(DROP|TRUNCATE|DELETE|UPDATE|ALTER)\b/i', $inst) && substr_count($inst, 'CREATE TABLE IF NOT EXISTS') === 4 && trim(file_get_contents(dirname(__DIR__) . '/uninstall.sql')) === 'SELECT 1;', 'kurulum yıkıcı değil; kaldırma veri silmez');

section('Şirket bilgisi uydurulmaz');
fresh();
$p = RlLegal::published('kullanim-kosullari');
ok(str_contains($p['html'], '[DOLDURULMADI: Şirket unvanı]') && str_contains($p['html'], 'rl-missing'), 'boş şirket alanı sayfada açıkça işaretlenir');
ok(count(RlLegal::missingFields()) === count(RlLegal::FIELDS), 'tüm şirket alanları boş başlar (varsayılan değer yok)');
RlLegal::saveCompany(['RAFIMDA_LEGAL_COMPANY' => 'Örnek A.Ş. <b>', 'RAFIMDA_LEGAL_EMAIL' => 'destek@ornek.example']);
$p = RlLegal::published('kullanim-kosullari');
ok(str_contains($p['html'], 'Örnek A.Ş. ') && !str_contains($p['html'], '<b>') && str_contains($p['html'], 'destek@ornek.example') && !str_contains($p['html'], '[DOLDURULMADI: Şirket unvanı]'), 'girilen bilgi yazılır, HTML temizlenir');
ok(str_contains($p['html'], '<h2>') && str_contains($p['html'], '<li>') && str_contains($p['html'], '<strong>'), 'başlık/madde/kalın biçimi');
$h = RlLegal::render("## <script>alert(1)</script>\n- <img src=x onerror=alert(2)>\n\"tırnak\" {{SIRKET_UNVANI}}");
ok(!str_contains($h, '<script>') && !str_contains($h, '<img') && str_contains($h, '&lt;script&gt;'), 'belge metnindeki HTML/JS çalışmaz (kaçışlanır)');

section('Sürüm ve kabul kayıtları');
fresh();
$doc = RlLegal::bySlug('uyelik-sozlesmesi'); $v1 = (int) $doc['current_version']; $sha1 = RlLegal::version($v1)['body_sha256'];
ok(RlLegal::accept('uyelik-sozlesmesi', 7, 'register'), 'kabul kaydı');
$r = RlLegal::saveVersion((int) $doc['id_document'], "## Üyelik Sözleşmesi\nYeni madde eklendi.", 'madde 5 eklendi', 1, true);
ok($r['success'] && RlLegal::bySlug('uyelik-sozlesmesi')['current_version'] === $r['id_version'] && RlLegal::version($v1)['body_sha256'] === $sha1, 'yeni sürüm yayınlanır; eski sürüm metni değişmez');
RlLegal::accept('uyelik-sozlesmesi', 8, 'register');
$acc = RlLegal::acceptances(0, 'uyelik-sozlesmesi');
ok((int) $acc[1]['id_version'] === $v1 && (int) $acc[0]['id_version'] === $r['id_version'], 'her kabul, o an yürürlükte olan sürüme bağlanır');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; RlLegal::accept('gizlilik-politikasi', 9, 'x');
$row = RlLegal::acceptances(9)[0];
ok(strlen($row['ip_hash']) === 64 && !str_contains(json_encode($row), '203.0.113.9'), 'IP düz metin saklanmaz (özet)');
ok(!RlLegal::accept('bayi-sozlesmesi', 9, 'x') && !RlLegal::accept('yok', 9, 'x'), 'pasif/olmayan belge kabul edilemez');
$draft = RlLegal::saveVersion((int) $doc['id_document'], "## Taslak\nHenüz yayınlanmadı metin.", 'taslak', 1, false);
ok(RlLegal::bySlug('uyelik-sozlesmesi')['current_version'] === $r['id_version'] && RlLegal::publishVersion((int) $doc['id_document'], $draft['id_version']) && (int) RlLegal::bySlug('uyelik-sozlesmesi')['current_version'] === $draft['id_version'], 'taslak sürüm ayrıca yayınlanabilir');

section('İzinler ayrı ve varsayılan kapalı');
fresh();
ok(array_values(RlLegal::consentsFor(5)) === array_fill(0, count(RlLegal::CONSENTS), false), 'kayıt yokken tüm isteğe bağlı izinler KAPALI');
RlLegal::setConsent(5, '', 'commercial_email', true, 'account'); RlLegal::setConsent(5, '', 'commercial_email', false, 'account');
ok(RlLegal::consentsFor(5)['commercial_email'] === false && count(RlLegal::consentLog('commercial_email')) === 2, 'geri alma yeni kayıt olarak eklenir, son durum geçerli');
ok(!RlLegal::setConsent(5, '', 'uydurma', true, 'x'), 'tanımsız izin türü reddedilir');

section('Kayıt formu (çekirdek kayıt kodu değişmeden)');
fresh(); Module::$listeners = []; (new RafimdaLegalModule())->boot();
$err = ''; $_POST = ['email' => 'Yeni@X.com'];
foreach (Module::$listeners['form.captcha.validate'] as $fn) { $fn('register', $err); }
ok(str_contains($err, 'Üyelik Sözleşmesi'), 'üyelik sözleşmesi onaylanmadan kayıt durdurulur');
$err = ''; $_POST = ['email' => 'Yeni@X.com', 'rl_membership' => '1'];
foreach (Module::$listeners['form.captcha.validate'] as $fn) { $fn('register', $err); }
ok($err === '' && ($_SESSION['rl_pending_reg']['commercial'] ?? null) === false, 'onaylı kayıt geçer; ticari ileti işaretlenmediyse kapalı');
DB::execute("UPDATE users SET email = 'yeni@x.com' WHERE id_user = 3"); Customer::as(3);
foreach (Module::$listeners['smarty.assign'] as $fn) { $fn(null); }
ok(count(RlLegal::acceptances(3)) === 2 && RlLegal::consentsFor(3)['commercial_email'] === false && empty($_SESSION['rl_pending_reg']), 'hesap oluşunca üyelik + KVKK kabulü bağlanır; ticari ileti izni VERİLMEZ');
$_POST = ['email' => 'baska@x.com', 'rl_membership' => '1', 'rl_commercial' => '1']; $err = '';
foreach (Module::$listeners['form.captcha.validate'] as $fn) { $fn('register', $err); }
foreach (Module::$listeners['smarty.assign'] as $fn) { $fn(null); }
ok(count(RlLegal::acceptances(3)) === 2 && RlLegal::consentsFor(3)['commercial_email'] === false, 'e-postası eşleşmeyen oturuma başkasının onayı bağlanmaz');
Customer::as(4); DB::execute("UPDATE users SET email = 'baska@x.com' WHERE id_user = 4");
$_SESSION['rl_pending_reg'] = ['email' => 'baska@x.com', 'at' => time() - 3600, 'membership' => true, 'commercial' => true, 'explicit' => false];
foreach (Module::$listeners['smarty.assign'] as $fn) { $fn(null); }
ok(RlLegal::acceptances(4) === [], '30 dakikadan eski bekleyen onay bağlanmaz');
$_SESSION['rl_pending_reg'] = ['email' => 'baska@x.com', 'at' => time(), 'membership' => true, 'commercial' => true, 'explicit' => false];
foreach (Module::$listeners['smarty.assign'] as $fn) { $fn(null); }
ok(RlLegal::consentsFor(4)['commercial_email'] === true && RlLegal::consentsFor(4)['explicit_consent'] === false, 'yalnızca işaretlenen isteğe bağlı izin kaydedilir');
$h = $tpl('front/register', ['rlLinks' => RlLegal::linksFor(['uyelik-sozlesmesi', 'kvkk-aydinlatma', 'ticari-ileti-izni', 'acik-riza'])]);
ok(str_contains($h, 'name="rl_membership"') && preg_match('/name="rl_membership"[^>]*required/', $h) && !preg_match('/name="rl_(commercial|explicit)"[^>]*checked/', $h) && !str_contains($h, 'checked'), 'kayıt kutuları: sözleşme zorunlu; hiçbir kutu önceden seçili değil');
ok(str_contains($h, 'hukuki?belge=uyelik-sozlesmesi') && str_contains($h, 'hukuki?belge=kvkk-aydinlatma') && str_contains($h, 'İsteğe bağlı'), 'kayıt formunda belge bağlantıları ve isteğe bağlı etiketi');

section('Sipariş kabulü');
fresh(); Module::$listeners = []; (new RafimdaLegalModule())->boot();
foreach (Module::$listeners['order.placed'] as $fn) { $fn(['id_order' => 55, 'id_user' => 3]); $fn(['id_order' => 55, 'id_user' => 3]); }
$a = RlLegal::acceptances(3);
ok(count($a) === 2 && $a[0]['ref'] === 'order:55' && in_array('mesafeli-satis-sozlesmesi', array_column($a, 'slug'), true) && in_array('on-bilgilendirme-formu', array_column($a, 'slug'), true), 'sipariş: ön bilgilendirme + mesafeli satış kabulü siparişe bağlanır (tekrar yazılmaz)');
DB::execute('DROP TABLE rl_acceptances');
foreach (Module::$listeners['order.placed'] as $fn) { $fn(['id_order' => 56, 'id_user' => 3]); }
ok(true, 'tablo bozuk olsa da sipariş akışı kesilmez');

section('Çerez tercihleri');
fresh(); $written = [];
RlLegal::$cookieWriter = static function (string $n, string $v, int $e) use (&$written): void { $written[$n] = $v; };
ok(RlLegal::cookiePrefs() === ['analytics' => false, 'marketing' => false, 'set' => false] && !RlLegal::cookieAllowed('analytics') && !RlLegal::cookieAllowed('marketing') && RlLegal::cookieAllowed('necessary'), 'tercih yokken yalnızca zorunlu çerezler');
RlLegal::saveCookiePrefs(true, false, 0);
ok(RlLegal::cookieAllowed('analytics') && !RlLegal::cookieAllowed('marketing') && isset($written['rl_vid'], $written[RlLegal::COOKIE]), 'seçim kaydedilir (analitik açık, pazarlama kapalı)');
ok(count(RlLegal::consentLog('cookie_analytics')) === 1 && (int) RlLegal::consentLog('cookie_marketing')[0]['granted'] === 0, 'ziyaretçi çerez izni günlüğe yazılır');
$_COOKIE = [];
$f = $tpl('front/footer', ['rlPrefs' => RlLegal::cookiePrefs(), 'rlLinks' => RlLegal::linksFor(['kvkk-aydinlatma']), 'rlCookieUrl' => '/cerez-tercihleri', 'rlListUrl' => '/hukuki', 'rlToken' => 'tk']);
ok(str_contains($f, 'id="rl-banner"') && str_contains($f, 'value="none"') && str_contains($f, 'Tercihleri yönet') && str_contains($f, 'KVKK Aydınlatma'), 'ilk ziyarette çerez bildirimi: "yalnızca zorunlu" eşit seçenek');
$_COOKIE[RlLegal::COOKIE] = '{"a":0,"m":0}';
$f = $tpl('front/footer', ['rlPrefs' => RlLegal::cookiePrefs(), 'rlLinks' => [], 'rlCookieUrl' => '/c', 'rlListUrl' => '/h', 'rlToken' => 'tk']);
ok(!str_contains($f, 'rl-banner'), 'tercih yapıldıktan sonra bildirim gösterilmez');
$c = $tpl('front/cookies', ['rlPrefs' => ['analytics' => false, 'marketing' => false, 'set' => false], 'rlSaved' => false, 'rlError' => '', 'rlItem' => RlLegal::published('cerez-tercihleri'), 'rlPolicyUrl' => '/p', 'rlToken' => 't']);
ok(preg_match_all('/name="(analytics|marketing)"[^>]*checked/', $c) === 0 && str_contains($c, 'disabled') && str_contains($c, 'Zorunlu çerezler'), 'tercih sayfasında isteğe bağlı çerezler seçili gelmez');
$ga = file_get_contents(dirname(__DIR__, 2) . '/google-analytics/google-analytics.php'); $fb = file_get_contents(dirname(__DIR__, 2) . '/facebook-pixel/facebook-pixel.php');
ok(str_contains($ga, "RlLegal::cookieAllowed('analytics')") && str_contains($fb, "RlLegal::cookieAllowed('marketing')") && str_contains($ga, "class_exists('RlLegal', false)"), 'Google Analytics ve Meta Pixel izin yoksa yüklenmez (modül yoksa davranış aynı)');

section('Halka açık sayfa');
fresh();
$list = $tpl('front/doc', ['rlItem' => null, 'rlDocs' => RlLegal::all(true), 'rlGroups' => RlLegal::GROUPS, 'rlListUrl' => '/hukuki', 'rlCookieUrl' => '/c']);
ok(str_contains($list, 'KVKK Aydınlatma Metni') && str_contains($list, 'Mesafeli Satış Sözleşmesi') && !str_contains($list, 'Bayilik') && !str_contains($list, 'bayi-sozlesmesi'), 'belge listesi: bayilik belgeleri yok');
$one = $tpl('front/doc', ['rlItem' => RlLegal::published('cayma-hakki'), 'rlDocs' => [], 'rlGroups' => [], 'rlListUrl' => '/hukuki', 'rlCookieUrl' => '/c']);
ok(str_contains($one, '14 gün') && str_contains($one, 'Sürüm 1.0'), 'belge sayfası: metin ve sürüm');
$container = 'hukuki'; $_GET = ['belge' => 'bayi-sozlesmesi']; ob_start(); http_response_code(200);
include dirname(__DIR__) . '/front/doc.php'; ob_end_clean();
ok($container === '404' && http_response_code() === 404, 'pasif belge adresi 404 verir');
$_GET = [];

section('Yönetici paneli');
fresh();
foreach (['documents', 'company', 'acceptances', 'consents'] as $t) {
	$h = $tpl('admin/admin', ['rlFlash' => null, 'rlToken' => 'tk'] + RafimdaLegalModule::adminVars(['tab' => $t]));
	ok(str_contains($h, 'taslaktır') && str_contains($h, 'nav-tabs'), "sekme çizilir: $t");
}
$h = $tpl('admin/admin', ['rlFlash' => null, 'rlToken' => 'tk'] + RafimdaLegalModule::adminVars(['tab' => 'documents']));
ok(substr_count($h, 'İnceleme gerekli') >= 19 && str_contains($h, 'Pasif (gösterilmez)') && str_contains($h, 'Doldurulmamış şirket bilgisi'), 'belge listesi: inceleme işaretleri, pasif durum, eksik şirket bilgisi uyarısı');
$did = (int) RlLegal::bySlug('gizlilik-politikasi')['id_document'];
$_POST = ['rlAction' => 'save_version', 'id_document' => $did, 'body' => "## Gizlilik\n<script>x</script> yeni metin içeriği burada.", 'change_note' => 'güncelleme', 'publish' => '1'];
$r = RafimdaLegalModule::handlePost(1);
$h = $tpl('admin/admin', ['rlFlash' => $r, 'rlToken' => 'tk'] + RafimdaLegalModule::adminVars(['tab' => 'edit', 'id' => $did]));
ok($r['type'] === 'success' && str_contains($h, 'sürüm 1.1') && !str_contains($h, '<script>x</script>') && str_contains($h, 'geçerli'), 'düzenleme: yeni sürüm, önizleme kaçışlı, sürüm listesi');
$_POST = ['rlAction' => 'meta', 'id_document' => $did, 'status' => 'published', 'review_note' => 'Avukat onayladı'];
RafimdaLegalModule::handlePost(1);
ok((int) RlLegal::bySlug('gizlilik-politikasi')['needs_review'] === 0, 'hukuki inceleme işareti kaldırılabilir');
$_POST = ['rlAction' => 'company', 'RAFIMDA_LEGAL_COMPANY' => 'Deneme Ltd.', 'BASKA_AYAR' => 'x'];
RafimdaLegalModule::handlePost(1);
ok(Settings::get('RAFIMDA_LEGAL_COMPANY') === 'Deneme Ltd.' && Settings::get('BASKA_AYAR') === '', 'şirket formu yalnızca tanımlı alanları yazar');
$_POST = [];

section('Satıcı başvurusu ile entegrasyon');
function vdrive(array $cfg): string { return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/rafimda-vendor/tests/drive.php') . ' ' . escapeshellarg(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . ' 2>&1'); }
$o = vdrive(['user' => 3, 'register' => 1, 'legal' => 1]);
ok(str_contains($o, 'hukuki?belge=satici-sozlesmesi') && str_contains($o, 'hukuki?belge=kvkk-aydinlatma'), 'satıcı başvuru formu yayındaki sözleşmelere bağlanır');
$post = ['person_type' => 'company', 'contact_name' => 'Ayşe Yılmaz', 'address' => 'Atatürk Bulvarı No:5', 'district' => 'Çankaya', 'categories' => ['1'], 'store_name' => 'Ayşe Butik', 'legal_name' => 'Ayşe Butik Ltd.', 'tax_office' => 'Çankaya', 'tax_number' => '1234567890', 'phone' => '05321234567', 'email' => 'ayse@x.com', 'city' => 'Ankara', 'description' => 'Kadın giyim ve aksesuar ürünleri satıyoruz.', 'accept_terms' => '1', 'kvkk_ack' => '1'];
$o = vdrive(['user' => 3, 'register' => 1, 'legal' => 1, 'method' => 'POST', 'post' => $post, 'probes' => ["SELECT group_concat(slug || '@' || version || '#' || ref, ',') FROM rl_acceptances WHERE id_user = 3"]]);
ok(preg_match('/PROBE0:"[^"]*satici-sozlesmesi@1\.0#SB\d{4}-\d{6}/', $o) === 1 && str_contains($o, 'pazaryeri-aracilik-sozlesmesi@') && str_contains($o, 'kvkk-aydinlatma@'), 'başvuruda kabul edilen sözleşme sürümleri başvuru numarasıyla kaydedilir');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
