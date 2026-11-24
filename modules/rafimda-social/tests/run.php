<?php
/** rafimda-social testleri (SQLite + GD + gerçek Smarty; GERÇEK SOSYAL MEDYA API'si YOK). */
ob_start();
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
require_once dirname(__DIR__) . '/rafimda-social.php';
$pass = 0; $fail = 0;
if (!function_exists('ok')) { function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } } }
if (!function_exists('section')) { function section(string $t): void { echo "\n== $t ==\n"; } }
$tmp = sys_get_temp_dir() . '/rso_' . getmypid(); @mkdir($tmp . '/pimg', 0777, true);
RsoContent::$mediaDirOverride = $tmp . '/media'; RsoContent::$productImgDir = $tmp . '/pimg';
$GLOBALS['domain'] = 'https://test.local/';
// Sabit "şimdi": 2026-12-01 10:00 Türkiye saati
RsoPlanner::$now = (new DateTimeImmutable('2026-12-01 10:00', new DateTimeZone('Europe/Istanbul')))->getTimestamp();
function world(): array {
	global $tmp;
	rv_fresh_db(); rv_apply_migrations(); Settings::$d = [];
	foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', rv_mysql_to_sqlite(file_get_contents(dirname(__DIR__) . '/install.sql'))))) as $st) { $GLOBALS['db']->exec($st); }
	foreach (['categories ADD COLUMN category_link TEXT', 'products ADD COLUMN short_description TEXT', 'products ADD COLUMN description TEXT', 'products ADD COLUMN product_link TEXT'] as $a) { $GLOBALS['db']->exec('ALTER TABLE ' . $a); }
	foreach (['PUBLISH' => '0', 'REPEAT_DAYS' => '14', 'VENDOR_MONTHLY_LIMIT' => '30', 'VENDOR_APPROVAL' => '1'] as $k => $v) { Settings::set('RAFIMDA_SOCIAL_' . $k, $v); }
	DB::execute("UPDATE brands SET brand_name = 'Pamukkale Tekstil' WHERE id_brand = 1"); DB::execute("UPDATE categories SET category_name = 'Giyim' WHERE id_category = 1");
	$A = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 1, 'store_name' => 'Moda Evi', 'store_slug' => 'moda', 'status' => 'active']);
	$B = (int) DB::insert('rafimda_vendors', ['owner_user_id' => 4, 'store_name' => 'Diğer', 'store_slug' => 'diger', 'status' => 'active']);
	$mk = function (int $v, string $n, float $price, float $old, int $stock, int $active = 1) { $id = (int) DB::insert('products', ['product_name' => $n, 'id_category' => 1, 'id_brand' => 1, 'price' => $price, 'old_price' => $old, 'stock' => $stock, 'active' => $active, 'short_description' => 'Yüzde yüz pamuk, rahat kesim. Şık ve günlük kullanım için.']); DB::insert('rafimda_vendor_products', ['id_product' => $id, 'id_vendor' => $v]); return $id; };
	$p1 = $mk($A, 'Organik Pamuklu Tişört Çocuk İçin Özel Seri', 249.90, 299.90, 5); $p2 = $mk($A, 'Keten Gömlek', 499, 0, 0); $p3 = $mk($B, 'Başka Mağaza Ürünü', 100, 0, 3); $p4 = $mk($A, 'Pasif Ürün', 50, 0, 3, 0); $p5 = $mk($A, 'Kot Ceket', 899, 0, 2);
	foreach ([$p1, $p1, $p5] as $pid) { $iid = (int) DB::insert('images', ['id_product' => $pid, 'cover' => 1]); $im = imagecreatetruecolor(600, 800); imagefill($im, 0, 0, imagecolorallocate($im, 200, 60, 60)); imagejpeg($im, "$tmp/pimg/$iid.jpg"); imagedestroy($im); }
	return [$A, $B, $p1, $p2, $p3, $p4, $p5];
}

section('İçerik üretimi (yalnızca gerçek ürün verisi)');
[$A, $B, $p1, $p2, $p3, $p4, $p5] = world();
$p = RsoContent::product($p1);
$t = RsoContent::text($p, 'ig_post');
ok(str_contains($t['caption'], 'Organik Pamuklu Tişört') && str_contains($t['caption'], 'Pamukkale Tekstil') && str_contains($t['caption'], '249,90 TL') && str_contains($t['caption'], 'önceki fiyat 299,90 TL') && str_contains($t['caption'], 'Moda Evi'), 'metin: ad, marka, fiyat, gerçek eski fiyat, satıcı');
ok(str_contains($t['hashtags'], '#rafimda') && str_contains($t['hashtags'], '#giyim') && str_contains($t['hashtags'], '#pamukkaletekstil') && str_contains($t['hashtags'], '#indirim'), 'hashtag: kategori/marka/indirim (Türkçe karakter sadeleştirilir)');
$t5 = RsoContent::text(RsoContent::product($p5), 'ig_post');
ok(!str_contains($t5['caption'], 'önceki fiyat') && !str_contains($t5['hashtags'], 'indirim'), 'eski fiyatı olmayan üründe indirim iddiası YOK');
ok(!preg_match('/(en iyi|garantili|%\d+ indirim|yorum|puan)/iu', $t['caption']), 'olmayan özellik / sahte iddia eklenmez');
ok(str_contains(RsoContent::text($p, 'ig_story')['caption'], 'profilimizdeki linke'), 'hikâye metni bağlantı yerine profil yönlendirmesi');
$img = RsoContent::renderImage($p, 1080, 1080);
$info = @getimagesize($tmp . '/media/' . $img);
ok($img !== '' && $info[0] === 1080 && $info[1] === 1080, 'Instagram gönderi görseli 1080×1080');
$st = RsoContent::renderImage($p, 1080, 1920);
ok(@getimagesize($tmp . '/media/' . $st)[1] === 1920, 'hikâye görseli 1080×1920');
ok(RsoContent::renderImage(RsoContent::product($p2) ?: [], 1080, 1080) !== '', 'görselsiz ürün için de şablon görsel üretilir ("Ürün görseli yok")');
$v = RsoContent::renderVideo($p, 6);
if (RsoContent::ffmpeg() !== '') {
	$probe = (string) shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height,codec_name -of csv=p=0 ' . escapeshellarg($tmp . '/media/' . $v['video']) . ' 2>&1');
	ok($v['video'] !== '' && str_contains($probe, 'h264,1080,1920'), 'dikey video (H.264 1080×1920) üretilir: ' . trim($probe));
} else {
	ok($v['video'] === '' && count($v['frames']) >= 1 && str_contains($v['message'], 'ffmpeg yok'), 'ffmpeg yoksa kare dizisi (storyboard) ve açık mesaj');
}
RsoContent::$videoRenderer = static fn(array $frames, string $out, int $s) => (bool) file_put_contents($out, 'X');
ok(RsoContent::renderVideo($p)['video'] !== '', 'video motoru değiştirilebilir (yapay zekâ için modüler)');
RsoContent::$videoRenderer = null;

section('Oluşturma, sahiplik ve sınırlar');
ok(!RsoPlanner::create('vendor', $A, $p3, 'ig_post', '', 'vendor')['success'], 'satıcı başka mağazanın ürünü için içerik oluşturamaz');
ok(!RsoPlanner::create('platform', 0, $p2, 'ig_post', '', 'admin')['success'] && !RsoPlanner::create('platform', 0, $p4, 'ig_post', '', 'admin')['success'], 'stoksuz ve pasif ürün tanıtılmaz');
ok(!RsoPlanner::create('platform', 0, $p1, 'ig_post', '2026-11-30 12:00', 'admin')['success'], 'geçmiş zamana yayın planlanmaz');
$r = RsoPlanner::create('vendor', $A, $p1, 'ig_post', '2026-12-02 20:00', 'vendor');
$post = RsoPlanner::get($r['id_post']);
ok($r['success'] && $post['status'] === 'pending_approval' && $post['scheduled_at'] === '2026-12-02 20:00:00' && (float) $post['price_snapshot'] === 249.9 && count($post['media_urls']) === 1, 'satıcı içeriği: onay bekliyor, TR saati, fiyat anlık görüntüsü, medya');
$r2 = RsoPlanner::create('platform', 0, $p1, 'ig_story', '2026-12-05 20:00', 'admin');
ok(!$r2['success'] && str_contains($r2['message'], 'tekrar'), 'aynı ürün + platform 14 gün içinde tekrar planlanamaz');
ok(RsoPlanner::create('platform', 0, $p1, 'tiktok', '2026-12-05 20:00', 'admin')['success'], 'farklı platformda aynı ürün planlanabilir');
$r3 = RsoPlanner::create('platform', 0, $p5, 'yt_short', '2026-12-05 20:00', 'admin');
ok($r3['success'] && RsoPlanner::get($r3['id_post'])['status'] === 'ready', 'yönetici içeriği doğrudan "yayına hazır"');
ok(!RsoPlanner::create('platform', 0, $p1, 'yt_short', '2026-12-05 20:00', 'admin')['success'], 'aynı hesapta aynı dakikaya ikinci yayın yok');
Settings::set('RAFIMDA_SOCIAL_VENDOR_MONTHLY_LIMIT', '1');
ok(!RsoPlanner::create('vendor', $A, $p5, 'ig_post', '', 'vendor')['success'], 'satıcı aylık içerik limiti uygulanır (paketlere bağlanabilir)');
Settings::set('RAFIMDA_SOCIAL_VENDOR_MONTHLY_LIMIT', '30');

section('Düzenleme, onay, saat, iptal');
$id = $r['id_post'];
ok(!RsoPlanner::edit($id, 'vendor', $B, 'Başka satıcı düzenlemesi metni', '', 'vendor')['success'], 'başka satıcı düzenleyemez');
ok(RsoPlanner::edit($id, 'vendor', $A, 'Yeni sezon tişörtümüz satışta! Detaylar profilde.', '#yeni, #tisort #<script>', 'vendor')['success'] && RsoPlanner::get($id)['hashtags'] === '#yeni #tisort #script', 'metin ve hashtag düzenlenir (temizlenir)');
ok(RsoPlanner::approve($id, true, 1)['success'] && RsoPlanner::get($id)['status'] === 'ready', 'yönetici onayı → yayına hazır');
ok((int) DB::getValue("SELECT COUNT(*) FROM rafimda_notifications WHERE id_vendor = ? AND type = 'social'", [$A]) === 1, 'satıcıya onay bildirimi');
RsoPlanner::edit($id, 'vendor', $A, 'Onaydan sonra tekrar düzenledim metni.', '', 'vendor');
ok(RsoPlanner::get($id)['status'] === 'pending_approval', 'onaylı satıcı içeriği değişirse yeniden onaya düşer');
RsoPlanner::approve($id, true, 1);
ok(!RsoPlanner::reschedule($id, 'vendor', $A, '2026-11-01 10:00', 'vendor')['success'] && RsoPlanner::reschedule($id, 'vendor', $A, '2026-12-01T10:30', 'vendor')['success'] && RsoPlanner::get($id)['scheduled_at'] === '2026-12-01 10:30:00', 'yayın saati değiştirilir (geçmiş reddedilir; datetime-local biçimi)');
$d = RsoPlanner::create('platform', 0, $p5, 'ig_post', '', 'admin');
ok(RsoPlanner::get($d['id_post'])['status'] === 'draft' && RsoPlanner::cancel($d['id_post'], 'platform', 0, 'admin')['success'] && !RsoPlanner::edit($d['id_post'], 'platform', 0, 'İptal sonrası düzenleme metni', '', 'admin')['success'], 'taslak → iptal; iptal edilen düzenlenemez');

section('Takvim (Türkiye saati)');
$w = RsoPlanner::calendar('platform', 0, 'week', '2026-12-01');
ok(count($w) === 3 && $w[0]['scheduled_at'] === '2026-12-01 10:30:00', 'haftalık takvim sıralı');
ok(count(RsoPlanner::calendar('platform', 0, 'day', '2026-12-05')) === 2 && count(RsoPlanner::calendar('platform', 0, 'month', '2026-12-15')) === 3, 'günlük ve aylık görünüm');
ok(count(RsoPlanner::calendar('vendor', $A, 'week', '2026-12-01')) === 1 && RsoPlanner::calendar('vendor', $B, 'week', '2026-12-01') === [], 'satıcı yalnızca kendi takvimini görür');
date_default_timezone_set('UTC');
ok(RsoPlanner::now()->format('H:i') === '10:00' && RsoPlanner::parseTime('2026-12-01 10:01') === '2026-12-01 10:01:00', 'sunucu UTC olsa da Türkiye saatiyle hesaplanır');

section('Yayın öncesi kontrol ve otomatik yayın kilidi');
RsoPlanner::$now = (new DateTimeImmutable('2026-12-01 10:31', new DateTimeZone('Europe/Istanbul')))->getTimestamp();
$s = RsoPlanner::runDue();
ok($s['waiting'] === 1 && RsoPlanner::get($id)['status'] === 'ready' && str_contains(RsoPlanner::get($id)['last_error'], 'Otomatik yayın kapalı') && $s['published'] === 0, 'zamanı gelen içerik kontrol edilir ama otomatik yayın kapalıyken PAYLAŞILMAZ');
DB::execute('UPDATE products SET price = 199.90 WHERE id_product = ?', [$p1]);
$s = RsoPlanner::runDue();
ok($s['held'] === 1 && RsoPlanner::get($id)['status'] === 'on_hold' && str_contains(RsoPlanner::get($id)['last_error'], 'Fiyat değişti'), 'fiyat değiştiyse eski fiyatlı içerik beklemeye alınır');
DB::execute('UPDATE products SET price = 249.90 WHERE id_product = ?', [$p1]);
RsoPlanner::$now = (new DateTimeImmutable('2026-12-05 20:01', new DateTimeZone('Europe/Istanbul')))->getTimestamp();
DB::execute('UPDATE products SET stock = 0 WHERE id_product = ?', [$p5]);
RsoPlanner::runDue();
ok(RsoPlanner::get($r3['id_post'])['status'] === 'on_hold' && str_contains(RsoPlanner::get($r3['id_post'])['last_error'], 'stokta yok'), 'stoğu biten ürün yayınlanmaz');
DB::execute('UPDATE products SET stock = 3, active = 0 WHERE id_product = ?', [$p5]);
DB::execute("UPDATE rso_posts SET status = 'ready' WHERE id_post = ?", [$r3['id_post']]); RsoPlanner::runDue();
ok(RsoPlanner::get($r3['id_post'])['status'] === 'cancelled', 'satıştan kaldırılan ürünün yayını iptal edilir');
$f = RafimdaSocialModule::saveSettings(['publish' => '1', 'publish_confirm' => 'evet', 'repeat_days' => '7']);
ok(!$f['success'] && !RsoPlanner::publishEnabled() && RsoPlanner::setting('REPEAT_DAYS') === '7', 'onay ifadesi olmadan otomatik yayın açılmaz');
RafimdaSocialModule::saveSettings(['publish' => '1', 'publish_confirm' => 'OTOMATIK YAYINI ACIYORUM', 'repeat_days' => '7']);
$tk = RsoPlanner::get($r['id_post'] + 1); DB::execute("UPDATE rso_posts SET status = 'ready' WHERE id_post = ?", [$tk['id_post']]);
$s = RsoPlanner::runDue();
ok(RsoPlanner::publishEnabled() && $s['failed'] === 1 && RsoPlanner::get($tk['id_post'])['status'] === 'failed' && str_contains(RsoPlanner::get($tk['id_post'])['last_error'], 'henüz uygulanmadı'), 'yayın açık olsa bile resmî API uygulanmadan PAYLAŞIM YAPILMAZ ve açıkça "başarısız" görünür');
RafimdaSocialModule::saveSettings(['repeat_days' => '14']);

section('Resmî OAuth ve token güvenliği');
$c = RsoPlanner::connectUrl('vendor', $A, 'instagram', 'https://test.local/sosyal-geri-donus');
ok(!$c['success'] && str_contains($c['message'], 'uygulama kimliği'), 'uygulama kimliği yokken bağlantı başlatılmaz (açık mesaj)');
RafimdaSocialModule::saveSettings(['client_instagram' => '123456', 'client_tiktok' => 'tk-key', 'client_youtube' => 'g.apps']);
$c = RsoPlanner::connectUrl('vendor', $A, 'instagram', 'https://test.local/sosyal-geri-donus');
parse_str((string) parse_url($c['url'], PHP_URL_QUERY), $q);
ok($c['success'] && str_starts_with($c['url'], 'https://www.facebook.com/dialog/oauth?') && $q['client_id'] === '123456' && str_contains($q['scope'], 'instagram_content_publish') && str_starts_with($q['state'], 'instagram.'), 'Instagram: resmî Meta OAuth adresi + yayın izni + state');
$ct = RsoPlanner::connectUrl('platform', 0, 'tiktok', 'https://test.local/sosyal-geri-donus');
$cy = RsoPlanner::connectUrl('platform', 0, 'youtube', 'https://test.local/sosyal-geri-donus');
ok(str_starts_with($ct['url'], 'https://www.tiktok.com/v2/auth/authorize/?client_key=tk-key') && str_contains(urldecode($ct['url']), 'video.publish') && str_starts_with($cy['url'], 'https://accounts.google.com/o/oauth2/v2/auth?') && str_contains(urldecode($cy['url']), 'youtube.upload'), 'TikTok ve YouTube resmî yetkilendirme adresleri');
ok(!RsoPlanner::oauthCallback('instagram.' . str_repeat('a', 32), 'kod', 'x')['success'], 'bilinmeyen state reddedilir (CSRF)');
$r = RsoPlanner::oauthCallback($q['state'], 'gercek-kod', 'https://test.local/sosyal-geri-donus');
$acc = RsoPlanner::account('vendor', $A, 'instagram');
ok(!$r['success'] && $acc['status'] === 'error' && $acc['oauth_state'] === '' && str_contains($acc['last_error'], 'henüz uygulanmadı'), 'kod değişimi uygulanmadığı için hesap "hata" — bağlı gibi gösterilmez; state tek kullanımlık');
ok(!RsoPlanner::oauthCallback($q['state'], 'tekrar', 'x')['success'], 'aynı state ikinci kez kullanılamaz');
DB::update('rso_accounts', ['status' => 'connected', 'token_enc' => RsoCrypt::encrypt('GIZLI-TOKEN-123')], 'id_account = :a', ['a' => (int) $acc['id_account']]);
$view = RsoPlanner::accounts('vendor', $A)['instagram'];
ok($view['status'] === 'connected' && $view['has_token'] === true && !str_contains(json_encode(RsoPlanner::accounts('vendor', $A)), 'GIZLI') && !str_contains(json_encode(DB::execute('SELECT * FROM rso_accounts')), 'GIZLI-TOKEN'), 'token şifreli; arayüz verisinde yok');
ok(RsoPlanner::disconnect('vendor', $A, 'instagram') && RsoPlanner::account('vendor', $A, 'instagram')['token_enc'] === '' && RsoPlanner::account('vendor', $A, 'instagram')['status'] === 'revoked', 'bağlantı kaldırma token\'ı siler');
ok(!str_contains(json_encode(DB::execute('SELECT * FROM rso_events')), 'gercek-kod'), 'yetkilendirme kodu kayıtlara yazılmaz');
$src = implode('', array_map('file_get_contents', glob(dirname(__DIR__) . '/lib/*.php') + glob(dirname(__DIR__) . '/lib/publishers/*.php')));
ok(!preg_match('/\b(like|follow|comment|direct_message|login\?|password_field)\s*\(/i', $src), 'otomatik beğeni/takip/yorum/mesaj veya şifreyle giriş kodu yok');

section('Otomatik ürün seçimi ve rapor');
[$A, $B, $p1, $p2, $p3, $p4, $p5] = world();
$a = RsoPlanner::autoPlan('vendor', $A, 'campaign', 'ig_post', '2026-12-10', '19:00', 5, 'vendor');
ok($a['created'] === 1 && (int) DB::getValue('SELECT id_product FROM rso_posts') === $p1, 'kampanyalı seçim: yalnızca gerçek eski fiyatı olan, stoklu ve aktif ürün');
$a = RsoPlanner::autoPlan('vendor', $A, 'new', 'ig_post', '2026-12-10', '19:00', 5, 'vendor');
ok($a['created'] === 1 && str_contains($a['message'], 'Atlanan'), 'yeni ürünler: tekrar engeline takılan atlanır, stoksuz/pasif seçilmez');
ok(RsoPlanner::autoPlan('vendor', $A, 'featured', 'ig_post', '2026-12-10', '19:00', 3, 'vendor')['created'] === 0, 'vitrin modülü yoksa "öne çıkan" seçimi boş (uydurma yok)');
$rep = RsoPlanner::report('vendor', $A);
ok($rep['has_metrics'] === false && (int) ($rep['counts']['pending_approval'] ?? 0) === 2, 'rapor: durum sayıları; API verisi yokken istatistik gösterilmez');

section('Ekranlar');
$sm = new Smarty\Smarty(); $sm->setCompileDir($tmp . '/c'); @mkdir($tmp . '/c'); $sm->registerPlugin('modifier', 'adminT', static fn($s) => $s);
$firstId = (int) DB::getValue('SELECT MIN(id_post) FROM rso_posts');
foreach (['calendar', 'create', 'queue', 'accounts', 'reports', 'settings'] as $tab) {
	$sm->clearAllAssign(); $sm->assign(['rsoFlash' => null, 'rsoToken' => 't', 'rsoTab' => $tab] + RsoPanel::vars('platform', 0, ['d' => '2026-12-10']) + RafimdaSocialModule::settingsVars());
	$h = $sm->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl');
	ok(str_contains($h, 'Otomatik yayın kapalı') && str_contains($h, 'nav-tabs'), "yönetici sekmesi: $tab");
}
$sm->clearAllAssign(); $sm->assign(['rsoFlash' => null, 'rsoToken' => 't', 'rsoTab' => 'queue'] + RsoPanel::vars('platform', 0, ['post' => $firstId]) + RafimdaSocialModule::settingsVars());
$h = $sm->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl');
ok(str_contains($h, 'Önizleme') && str_contains($h, 'img/rafimda/social/') && str_contains($h, 'name="rsoAction" value="approve"'), 'önizleme + onay düğmeleri');
function vdrive(array $cfg): string { return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/rafimda-vendor/tests/drive.php') . ' ' . escapeshellarg(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . ' 2>&1'); }
$o = vdrive(['get' => ['s' => 'social'], 'social' => 1]);
ok(str_contains($o, 'Sosyal Medya') && str_contains($o, 'Otomatik yayın kapalı') && str_contains($o, 'Mağaza hesaplarım') && !str_contains($o, 'Warning') && !str_contains($o, 'Fatal'), 'satıcı paneli: Sosyal Medya bölümü');
ok(str_contains($o, 'satici-panel?s=social') && !str_contains(vdrive(['get' => ['s' => 'dashboard']]), 'satici-panel?s=social'), 'menüde yalnızca modül kuruluysa');
$o = vdrive(['user' => 2, 'get' => ['s' => 'social'], 'social' => 1, 'method' => 'POST', 'post' => ['rsoAction' => 'create', 'id_product' => '1', 'formats' => ['ig_post']], 'probes' => ['SELECT COUNT(*) FROM rso_posts']]);
ok(str_contains($o, 'PROBE0:0'), 'görüntüleyici içerik oluşturamaz');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
