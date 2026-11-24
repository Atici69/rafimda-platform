<?php
/** Soru-cevap, değerlendirme, mağaza vitrini/takip, destek ve bildirim testleri (SQLite + gerçek Smarty; gerçek FriSay/MySQL DEĞİL). */
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0;
if (!function_exists('ok')) { function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } } }
if (!function_exists('section')) { function section(string $t): void { echo "\n== $t ==\n"; } }
$GLOBALS['domain'] = 'https://test.local/';
$tmp = sys_get_temp_dir() . '/rv_eng_' . getmypid(); @mkdir($tmp, 0777, true);
RvStorefront::$dirOverride = $tmp . '/stores'; RvReviews::$photoDirOverride = $tmp . '/reviews';
function frisay_tables(): void {
	foreach (['question', 'reviews'] as $m) {
		$sql = (string) preg_replace('/ CHARACTER SET \w+ COLLATE \w+/', '', file_get_contents(dirname(__DIR__, 2) . "/$m/install.sql"));
		$sql = (string) preg_replace('/^\s*PRIMARY KEY \(`(id_question|id_review|id_queue)`\),?\s*$/m', '', rv_mysql_to_sqlite($sql));
		foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', (string) preg_replace('/,(\s*\))/', '$1', $sql)))) as $st) { $GLOBALS['db']->exec($st); }
	}
	RvStorefront::resetCache();
}
function world(): array {
	rv_fresh_db(); rv_apply_migrations(); frisay_tables(); Settings::$d = [];
	$a = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'Moda Evi', 'store_slug' => 'moda-evi', 'status' => 'active', 'city' => 'İzmir']);
	$b = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 4, 'store_name' => 'Diğer', 'store_slug' => 'diger', 'status' => 'active']);
	$p = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 2, 'store_name' => 'Onaysız', 'store_slug' => 'onaysiz', 'status' => 'pending']);
	DB::insert('rafimda_vendor_staff', ['id_vendor' => $a, 'id_user' => 1, 'role' => 'owner', 'status' => 'active']);
	DB::insert('rafimda_vendor_staff', ['id_vendor' => $b, 'id_user' => 4, 'role' => 'owner', 'status' => 'active']);
	$mk = function (int $v, string $n) { $id = (int) DB::insert('products', ['product_name' => $n, 'id_category' => 1, 'id_brand' => 1, 'price' => 100, 'old_price' => 0, 'stock' => 5, 'active' => 1]); DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $v]); return $id; };
	$p1 = $mk($a, 'Pamuklu Tişört'); $p2 = $mk($b, 'Başka Ürün');
	DB::execute("INSERT INTO orders (id_order, id_user, reference, status) VALUES (10, 3, 'R10', 4), (11, 3, 'R11', 1)");
	DB::execute('INSERT INTO order_detail (id_order, id_product, product_name, price, total, qty) VALUES (10, ?, "Pamuklu Tişört", 100, 100, 1), (11, ?, "Başka Ürün", 100, 100, 1)', [$p1, $p2]);
	return [$a, $b, $p, $p1, $p2];
}

section('Kişisel bilgi maskeleme');
foreach (['Beni 0532 123 45 67 arayın' => '[telefon gizlendi]', 'mail: ali.veli@ornek.com.tr' => '[e-posta gizlendi]', 'IBAN TR33 0006 1005 1978 6457 8413 26' => '[IBAN gizlendi]', 'TC 10000000146' => '[numara gizlendi]', 'bak https://kotu.site/x' => '[bağlantı gizlendi]', '+90 212 555 66 77' => '[telefon gizlendi]'] as $in => $want) {
	ok(str_contains(RvPrivacy::mask($in), $want), "maskelenir: $want");
}
ok(RvPrivacy::mask('Beden 42 uygun mu? 2 adet alırsam?') === 'Beden 42 uygun mu? 2 adet alırsam?', 'normal metin değişmez');

section('Ürün soru-cevap (satıcı)');
[$A, $B, $P, $p1, $p2] = world();
DB::insert('product_questions', ['id_product' => $p1, 'id_user' => 3, 'author_name' => 'Ayşe Kaya', 'question' => 'Kalıbı dar mı? Numaram 0532 111 22 33', 'answer' => '', 'active' => 0, 'date_add' => date('Y-m-d H:i:s')]);
DB::insert('product_questions', ['id_product' => $p2, 'id_user' => 3, 'author_name' => 'Ayşe Kaya', 'question' => 'Başka mağaza sorusu burada', 'answer' => '', 'active' => 0, 'date_add' => date('Y-m-d H:i:s')]);
RvQA::onQuestionCreated(1); RvQA::onQuestionCreated(2);
ok((int) DB::getValue('SELECT id_vendor FROM rafimda_question_meta WHERE id_question = 1') === $A && count(RvNotify::forVendor($A)) === 1, 'soru satıcıya bağlanır ve satıcıya bildirim gider');
$list = RvQA::productQuestions($A);
ok(count($list) === 1 && (int) $list[0]['id_question'] === 1, 'satıcı yalnızca KENDİ ürününün sorusunu görür');
ok(!RvQA::answerProduct($A, 1, 2, 'Benim değil ama yanıtlıyorum')['success'], 'başka satıcının ürün sorusu yanıtlanamaz (IDOR)');
ok(!RvQA::answerProduct($A, 1, 1, 'x')['success'], 'çok kısa yanıt reddedilir');
$r = RvQA::answerProduct($A, 1, 1, 'Normal kalıptır, bir beden büyük alabilirsiniz.');
$q = DB::getRowSafe('product_questions', 'id_question = 1');
ok($r['success'] && (int) $q['active'] === 1 && $q['answer'] !== '', 'moderasyon kapalıyken yanıt yayınlanır');
ok(DB::getValue("SELECT COUNT(*) FROM rafimda_notifications WHERE id_user = 3 AND type = 'question'") == 1, 'soru sahibine bildirim gider');
$d = RvQA::decorate($q);
ok(str_contains($d['question'], '[telefon gizlendi]') && !str_contains($d['question'], '0532') && $d['answered_by_vendor'] && $d['answer_store'] === 'Moda Evi', 'herkese açık gösterimde telefon gizlenir, "Satıcı yanıtı · mağaza" etiketi');
Settings::set('RAFIMDA_QA_MODERATION', '1');
$r = RvQA::answerProduct($A, 1, 1, 'Güncellenmiş yanıt metni burada.');
ok($r['success'] && (int) DB::getValue('SELECT active FROM product_questions WHERE id_question = 1') === 0 && str_contains($r['message'], 'onay'), 'moderasyon açıkken yanıt yönetici onayına düşer');
Settings::set('RAFIMDA_QA_MODERATION', '0');
$qs = file_get_contents(dirname(__DIR__, 2) . '/question/question.php');
ok(str_contains($qs, "class_exists('RvQA', false)") && str_contains($qs, 'RvQA::onQuestionCreated') && str_contains($qs, 'RvQA::decorate'), 'FriSay soru modülü Rafımda kancalarıyla (modül yoksa davranış aynı)');

section('Mağaza soruları');
ok(!RvQA::askStore($A, 0, '', 'Giriş yapmadan soru soruyorum')['success'], 'giriş zorunlu');
ok(!RvQA::askStore($A, 1, 'Ali', 'Kendi mağazama soru soruyorum')['success'], 'satıcı kendi mağazasına soru soramaz');
ok(!RvQA::askStore($P, 3, 'Ayşe', 'Onaysız mağazaya soru soruyorum')['success'], 'onaysız mağazaya soru yok');
$r = RvQA::askStore($A, 3, 'Ayşe Kaya', 'Kargo hangi gün çıkıyor acaba?');
ok($r['success'] && DB::getValue('SELECT status FROM rafimda_store_questions') === 'pending', 'soru kaydedilir (bekliyor)');
RvQA::askStore($A, 3, 'Ayşe', 'http://a.com http://b.com spam');
ok((int) DB::getValue('SELECT COUNT(*) FROM rafimda_store_questions') === 1, 'çoklu bağlantılı spam kaydedilmez');
for ($i = 0; $i < 4; $i++) { RvQA::askStore($A, 3, 'Ayşe', "Hız sınırı deneme sorusu $i"); }
ok(!RvQA::askStore($A, 3, 'Ayşe', 'Altıncı soru deneme metni')['success'], 'saatte 5 soru sınırı');
ok(!RvQA::answerStore($B, 4, 1, 'Başkasının sorusu')['success'], 'başka satıcı mağaza sorusunu yanıtlayamaz');
ok(RvQA::answerStore($A, 1, 1, 'Hafta içi her gün kargoya veriyoruz.')['success'] && RvQA::publishedStore($A) !== [] && RvQA::publishedStore($A)[0]['author'] === 'A*** K***', 'yanıt yayınlanır; soran adı maskelenir');
Settings::set('RAFIMDA_QA_MODERATION', '1');
RvQA::answerStore($A, 1, 2, 'Onay bekleyecek yanıt.');
ok(DB::getValue('SELECT status FROM rafimda_store_questions WHERE id_store_question = 2') === 'answered' && !RvQA::moderateStore(3, 'published') && RvQA::moderateStore(2, 'published'), 'yönetici yalnızca yanıtlanmış soruyu yayınlar');
Settings::set('RAFIMDA_QA_MODERATION', '0');

section('Değerlendirmeler');
[$A, $B, $P, $p1, $p2] = world();
ok(RvReviews::deliveredOrderFor(3, $p1) === 10 && RvReviews::deliveredOrderFor(3, $p2) === 0, 'teslim edilmiş sipariş tespiti (teslim edilmemiş sayılmaz)');
ok(RvReviews::guard($p1, 1) !== null, 'mağaza sahibi kendi ürününü değerlendiremez');
ok(RvReviews::guard($p1, 3) === null && RvReviews::guard($p2, 3) === null, 'varsayılan: müşteri yorum yazabilir');
Settings::set('RAFIMDA_REVIEW_PURCHASE_ONLY', '1');
ok(RvReviews::guard($p2, 3) !== null && RvReviews::guard($p1, 3) === null, 'ayar açıkken yalnızca teslim alınan ürüne yorum');
Settings::set('RAFIMDA_REVIEW_PURCHASE_ONLY', '0');
DB::insert('product_reviews', ['id_product' => $p2, 'id_user' => 3, 'author_name' => 'Ayşe', 'rating' => 2, 'title' => '', 'comment' => 'Teslim almadan yazdım', 'active' => 1, 'date_add' => date('Y-m-d H:i:s')]);
RvReviews::onReviewCreated(1, $p2, 3);
ok((int) DB::getValue('SELECT verified FROM rafimda_review_meta WHERE id_review = 1') === 0, 'teslim kaydı yoksa "doğrulanmış" etiketi VERİLMEZ');
ok(!RvReviews::submitProduct(3, 'Ayşe', 11, $p2, 5, 'Teslim edilmeyen sipariş yorumu')['success'], 'teslim edilmemiş siparişle Rafımda formu kabul etmez');
ok(!RvReviews::submitProduct(4, 'Fatma', 10, $p1, 5, 'Başkasının siparişiyle yorum')['success'], 'başkasının siparişiyle yorum yapılamaz');
$jpg = $tmp . '/p.jpg'; $im = imagecreatetruecolor(120, 120); imagejpeg($im, $jpg); imagedestroy($im);
$bad = $tmp . '/x.jpg'; file_put_contents($bad, '<?php echo 1;');
ok(!RvReviews::submitProduct(3, 'Ayşe', 10, $p1, 5, 'Güzel ürün, tavsiye ederim.', [['tmp_name' => $bad, 'size' => 13, 'error' => 0]])['success'] && (int) DB::getValue('SELECT COUNT(*) FROM product_reviews WHERE id_product = ?', [$p1]) === 0, 'sahte görsel reddedilir ve yorum kaydedilmez');
$r = RvReviews::submitProduct(3, 'Ayşe Kaya', 10, $p1, 5, 'Güzel ürün, tavsiye ederim.', [['tmp_name' => $jpg, 'size' => filesize($jpg), 'error' => 0]]);
$m = DB::getRowSafe('rafimda_review_meta', 'id_review = ?', [$r['id_review'] ?? 0]);
ok($r['success'] && (int) $m['verified'] === 1 && (int) $m['id_order'] === 10 && count(json_decode($m['photos'], true)) === 1 && (int) DB::getValue('SELECT active FROM product_reviews WHERE id_review = ?', [$r['id_review']]) === 0, 'teslim edilmiş siparişle fotoğraflı yorum: doğrulanmış, onay bekliyor');
ok(!RvReviews::submitProduct(3, 'Ayşe', 10, $p1, 4, 'İkinci yorum denemesi yapıyorum')['success'], 'aynı ürüne ikinci yorum yok');
ok(is_file($tmp . '/reviews/' . json_decode($m['photos'], true)[0]) && @getimagesize($tmp . '/reviews/' . json_decode($m['photos'], true)[0])[2] === IMAGETYPE_JPEG, 'fotoğraf yeniden kodlanıp saklanır');
DB::execute('UPDATE product_reviews SET active = 1');
$d = RvReviews::decorate(DB::getRowSafe('product_reviews', 'id_review = ?', [$r['id_review']]));
ok($d['verified'] === true && count($d['photos']) === 1 && str_contains($d['photos'][0], 'img/rafimda/reviews/'), 'ürün sayfası: doğrulanmış etiketi ve fotoğraf bağlantısı');
ok(!RvReviews::voteHelpful('product', (int) $r['id_review'], 3)['success'], 'kendi yorumuna faydalı oyu verilemez');
ok(RvReviews::voteHelpful('product', (int) $r['id_review'], 2)['success'] && !RvReviews::voteHelpful('product', (int) $r['id_review'], 2)['success'] && (int) DB::getValue('SELECT helpful FROM rafimda_review_meta WHERE id_review = ?', [$r['id_review']]) === 1, 'faydalı oyu kişi başı bir kez');
ok(!RvReviews::reply($B, 'product', (int) $r['id_review'], 'Başka satıcı yanıtı')['success'], 'başka satıcı yoruma yanıt yazamaz');
ok(RvReviews::reply($A, 'product', (int) $r['id_review'], 'Teşekkür ederiz!')['success'] && RvReviews::decorate(['id_review' => $r['id_review']])['vendor_reply'] === 'Teşekkür ederiz!', 'satıcı yanıtı ürün sayfasında görünür');
ok(!RvReviews::submitStore(3, 'Ayşe', 11, $B, 5, 'Teslim edilmeyen sipariş mağaza yorumu')['success'], 'mağaza değerlendirmesi teslim edilmiş sipariş ister');
ok(!RvReviews::submitStore(3, 'Ayşe', 10, $B, 5, 'Bu siparişte o mağaza yok ki')['success'], 'siparişte o mağazanın ürünü yoksa değerlendirilemez');
ok(!RvReviews::submitStore(1, 'Ali', 10, $A, 5, 'Kendi mağazamı övüyorum burada')['success'], 'kendi mağazasını değerlendirme engellenir');
ok(RvReviews::submitStore(3, 'Ayşe Kaya', 10, $A, 4, 'Hızlı kargo, özenli paket.')['success'] && !RvReviews::submitStore(3, 'Ayşe', 10, $A, 5, 'İkinci kez deniyorum burada')['success'], 'sipariş başına bir mağaza değerlendirmesi');
ok(RvStorefront::rating($A) === ['avg' => 5.0, 'count' => 1], 'puan yalnızca ONAYLI değerlendirmelerden (bekleyen mağaza yorumu sayılmaz)');
RvReviews::moderateStore(1, 'approved');
ok(RvStorefront::rating($A) === ['avg' => 4.5, 'count' => 2] && count(RvReviews::publishedStore($A)) === 1, 'onaydan sonra puana dahil');
$rs = file_get_contents(dirname(__DIR__, 2) . '/reviews/reviews.php');
ok(str_contains($rs, 'RvReviews::guard') && str_contains($rs, 'RvReviews::onReviewCreated') && str_contains($rs, 'RvReviews::decorate'), 'FriSay yorum modülü Rafımda kancalarıyla');

section('Mağaza vitrini ve takip');
ok(RvStorefront::bySlug('moda-evi') !== null && RvStorefront::bySlug('onaysiz') === null, 'yalnızca aktif mağaza herkese açık');
ok(!RvStorefront::follow(1, $A, true) && RvStorefront::follow(3, $A, true) && RvStorefront::follow(3, $A, true) && RvStorefront::followers($A) === 1, 'takip: kendi mağazasını takip yok, tekrar takip sayıyı artırmaz');
ok(RvStorefront::followedBy(3)[0]['store_slug'] === 'moda-evi' && RvStorefront::follow(3, $A, false) && RvStorefront::followers($A) === 0, 'takip listesi ve bırakma');
ok(!RvStorefront::follow(3, $P, true), 'onaysız mağaza takip edilemez');
$png = $tmp . '/logo.png'; $im = imagecreatetruecolor(300, 300); imagepng($im, $png); imagedestroy($im);
ok(RvStorefront::uploadImage($A, 'logo', ['tmp_name' => $png, 'size' => filesize($png), 'error' => 0])['success'] && RvStorefront::profile($A)['logo_url'] !== '', 'logo yüklenir (yeniden kodlanır)');
ok(!RvStorefront::uploadImage($A, 'logo', ['tmp_name' => $bad, 'size' => 13, 'error' => 0])['success'] && !RvStorefront::uploadImage($A, 'favicon', ['tmp_name' => $png, 'error' => 0])['success'], 'sahte görsel ve izinsiz alan reddedilir');
RvStorefront::saveProfile($A, ['about' => '<b>El yapımı</b> ürünler', 'shipping_note' => 'Aynı gün kargo', 'logo' => 'hack.php']);
ok(RvStorefront::profile($A)['about'] === 'El yapımı ürünler' && str_starts_with(RvStorefront::profile($A)['logo'], 'logo-'), 'satıcı yalnızca izinli alanları düzenler (logo adı formdan değişmez)');
ok(count(RvStorefront::products($A)) === 1 && RvStorefront::products($A)[0]['product_name'] === 'Pamuklu Tişört', 'vitrin ürünleri yalnızca o mağazanın aktif ürünleri');
ok(RvStorefront::vendorOfProduct($p1)['store_name'] === 'Moda Evi', 'ürün sayfası satıcı bilgisi');

section('Destek talepleri');
$t = RvTickets::create(['opener' => 'customer', 'id_user' => 3, 'category' => 'order', 'subject' => 'Siparişim gelmedi', 'body' => 'R11 numaralı siparişim nerede?', 'id_order' => 11]);
ok($t['success'] && preg_match('/^DT\d{2}-\d{6}$/', $t['ticket_no']) === 1 && (int) DB::getValue('SELECT id_order FROM rafimda_tickets') === 11, 'müşteri talebi: numara + sipariş bağlantısı');
$t2 = RvTickets::create(['opener' => 'customer', 'id_user' => 4, 'category' => 'order', 'subject' => 'Başkasının siparişi', 'body' => 'Başkasının siparişini bağlamaya çalışıyorum', 'id_order' => 11]);
ok((int) DB::getValue('SELECT id_order FROM rafimda_tickets WHERE id_ticket = ?', [$t2['id_ticket']]) === 0, 'başkasının siparişi talebe bağlanamaz');
ok(RvTickets::get($t['id_ticket'], 'customer', 4) === null && RvTickets::get($t['id_ticket'], 'vendor', 0, $A) === null && RvTickets::get($t['id_ticket'], 'customer', 3) !== null, 'talep izolasyonu (başka müşteri/satıcı göremez)');
$vt = RvTickets::create(['opener' => 'vendor', 'id_user' => 1, 'id_vendor' => $A, 'category' => 'store', 'subject' => 'Panel sorusu', 'body' => 'Ürün onayı ne kadar sürüyor?']);
ok(count(RvTickets::listFor('vendor', 1, $A)) === 1 && RvTickets::listFor('vendor', 4, $B) === [] && count(RvTickets::listFor('admin')) === 3, 'satıcı yalnızca kendi mağazasının taleplerini görür; yönetici hepsini');
ok(RvTickets::reply($t['id_ticket'], 'admin', 99, 'Kargoya verildi, takip no iletildi.')['success'] && DB::getValue('SELECT status FROM rafimda_tickets WHERE id_ticket = ?', [$t['id_ticket']]) === 'answered', 'yönetici yanıtı → durum "Yanıtlandı"');
ok((int) DB::getValue("SELECT COUNT(*) FROM rafimda_notifications WHERE id_user = 3 AND type = 'support'") === 1, 'talep sahibine yanıt bildirimi');
ok(RvTickets::reply($t['id_ticket'], 'customer', 3, 'Teşekkürler', 3)['success'] && DB::getValue('SELECT status FROM rafimda_tickets WHERE id_ticket = ?', [$t['id_ticket']]) === 'waiting', 'müşteri yanıtı → "Yanıt bekleniyor"');
ok(!RvTickets::reply($t['id_ticket'], 'customer', 4, 'Başkasının talebine yazıyorum', 4)['success'], 'başkasının talebine yazılamaz');
RvTickets::close($t['id_ticket'], 'admin');
ok(!RvTickets::reply($t['id_ticket'], 'customer', 3, 'Kapandıktan sonra', 3)['success'] && count(RvTickets::get($t['id_ticket'], 'admin')['messages']) === 3, 'kapanan talebe yazılamaz; geçmiş korunur');

section('Yönetici moderasyon ve ayarlar');
[$ok, $msg] = RvEngageAdmin::handle('eng_settings', ['qa_moderation' => '1', 'purchase_only' => '1'], 1);
ok($ok && RvQA::moderation() && Settings::get('RAFIMDA_REVIEW_PURCHASE_ONLY') === '1', 'etkileşim ayarları kaydedilir');
ok(RvEngageAdmin::handle('bilinmeyen', [], 1) === null, 'tanımsız işlem bu sınıfta işlenmez');

section('Ekranlar (gerçek denetleyici + Smarty)');
function drive(array $cfg): string { return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/drive.php') . ' ' . escapeshellarg(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . ' 2>&1'); }
foreach (['storefront' => 'Logo ve kapak', 'questions' => 'Soru-Cevap', 'reviews' => 'Değerlendirmeler', 'notifications' => 'Bildirimler', 'support' => 'Destek taleplerim'] as $s => $needle) {
	$o = drive(['get' => ['s' => $s], 'engage' => 1]);
	ok(str_contains($o, $needle) && str_contains($o, '<main class="rv-content">') && !str_contains($o, 'Fatal') && !str_contains($o, 'Warning'), "satıcı paneli: $s");
}
$o = drive(['get' => ['s' => 'dashboard'], 'engage' => 1]);
foreach (['storefront', 'questions', 'reviews', 'notifications', 'support'] as $s) { ok(str_contains($o, 'satici-panel?s=' . $s), "menüde: $s"); }
$o = drive(['user' => 2, 'get' => ['s' => 'questions'], 'engage' => 1]);
ok(str_contains($o, 'Soru-Cevap') && !str_contains($o, 'name="answer"'), 'görüntüleyici soruları görür ama yanıtlayamaz');
$o = drive(['user' => 2, 'get' => ['s' => 'support'], 'method' => 'POST', 'post' => ['action' => 'create', 'subject' => 'Deneme konu', 'body' => 'Görüntüleyici talep açmaya çalışıyor', 'category' => 'other'], 'engage' => 1, 'probes' => ["SELECT COUNT(*) FROM rafimda_tickets WHERE opener = 'vendor'"]]);
ok(str_contains($o, 'PROBE0:0'), 'görüntüleyici destek talebi açamaz (yetki)');
$o = drive(['get' => ['s' => 'support'], 'method' => 'POST', 'post' => ['action' => 'create', 'subject' => 'Ödeme sorusu', 'body' => 'Hakedişim ne zaman yatar?', 'category' => 'payment'], 'engage' => 1, 'probes' => ["SELECT ticket_no || '|' || opener || '|' || id_vendor FROM rafimda_tickets WHERE opener = 'vendor'"]]);
ok(preg_match('/PROBE0:"DT\d{2}-\d{6}\|vendor\|1"/', $o) === 1, 'satıcı sahibi destek talebi açar');
$o = drive(['get' => ['s' => 'questions'], 'method' => 'POST', 'post' => ['kind' => 'store', 'id' => '1', 'answer' => 'Yanıt'], 'engage' => 1, 'probes' => ["SELECT answer FROM rafimda_store_questions WHERE id_store_question = 1"]]);
ok(str_contains($o, 'PROBE0:"Yanıt"'), 'satıcı paneli üzerinden mağaza sorusu yanıtlanır');
$o = drive(['admin' => 1, 'atab' => 'engage', 'get' => [], 'engage' => 1]);
ok(str_contains($o, 'Destek talepleri') && str_contains($o, 'Mağaza soruları (moderasyon)') && str_contains($o, 'yapılandırılmadı'), 'yönetici: soru/yorum/destek sekmesi');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
