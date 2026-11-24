<?php
// rafimda-rafi testleri: SQLite + stub (gerçek MySQL/tarayıcı DEĞİL; tarayıcı doğrulaması devsite ekran görüntüleriyle yapıldı)
require dirname(__DIR__, 2) . '/rafimda-vendor/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/rafimda-vendor/lib/RvStorefront.php';
require_once dirname(__DIR__) . '/lib/RafiEngine.php';
require_once dirname(__DIR__) . '/lib/RafiBot.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }

section('Metin işleme');
ok(RafiEngine::fold('KULAKLIK İŞÇİ Ğüşöç') === 'kulaklik isci gusoc', 'Türkçe büyük/küçük harf ve aksan katlama (I→ı→i, İ→i)');
ok(RafiEngine::terms('Kulaklıklar') === ['kulaklik'] && RafiEngine::terms('telefonu') === ['telefon'] && RafiEngine::terms('saatler') === ['saat'], 'basit kök alma');
ok(RafiEngine::parse('bana 1000 TL altı bir kulaklık önerir misin')['terms'] === ['kulaklik'] && RafiEngine::terms('SCM 2951') === ['scm', '2951'], 'durak kelimeler ve fiyat ifadesi ayıklanır; model numarası korunur');
ok(RafiEngine::likePattern('kulaklik') === '%k_lakl_k%' && strpos(RafiEngine::likePattern('a%b_c'), '%b') === false, 'LIKE kalıbı: Türkçe harf jokeri, kullanıcı % ve _ karakterleri temizlenir');

section('Niyet ve filtre çözümleme');
$p = RafiEngine::parse('1000 TL altı kulaklık'); ok($p['max'] === 1000.0 && $p['min'] === null && $p['terms'] === ['kulaklik'], '“1000 TL altı” → en fazla 1000');
$p = RafiEngine::parse("1.500 tl'den ucuz saat"); ok($p['max'] === 1500.0, '“1.500 tl’den ucuz” → 1500');
$p = RafiEngine::parse('2 bin altında telefon'); ok($p['max'] === 2000.0, '“2 bin altında” → 2000');
$p = RafiEngine::parse('500 TL üstü parfüm'); ok($p['min'] === 500.0 && $p['max'] === null, '“500 TL üstü” → en az 500');
$p = RafiEngine::parse('500-1500 TL arası telefon'); ok($p['min'] === 500.0 && $p['max'] === 1500.0 && $p['terms'] === ['telefon'], 'aralık “500-1500 TL arası”');
ok(RafiEngine::parse('en ucuz vitamin')['sort'] === 'price_asc' && RafiEngine::parse('en pahalı saat')['sort'] === 'price_desc', 'sıralama');
$p = RafiEngine::parse('stokta olan indirimli kulaklık'); ok($p['in_stock'] && $p['discount'], 'stok ve indirim filtresi');
ok(RafiEngine::parse('Merhaba Rafi!')['intent'] === 'greet' && RafiEngine::parse('ne yapabilirsin?')['intent'] === 'help' && RafiEngine::parse('teşekkürler')['intent'] === 'thanks', 'selam / yardım / teşekkür');
$p = RafiEngine::parse('ilk ikisini karşılaştır'); ok($p['intent'] === 'compare' && $p['picks'] === [1, 2] && $p['compare_parts'] === [], '“ilk ikisini karşılaştır” → 1,2');
$p = RafiEngine::parse("1 ve 3'ü karşılaştır"); ok($p['intent'] === 'compare' && $p['picks'] === [1, 3], '“1 ve 3’ü karşılaştır” → 1,3');
$p = RafiEngine::parse('Xiaomi saat ile Jabra kulaklık karşılaştır'); ok($p['intent'] === 'compare' && count($p['compare_parts']) === 2, 'ad ile karşılaştırma iki parçaya ayrılır');

section('Katalog araması (gerçek kayıtlar)');
require __DIR__ . '/run_seed.php';
$ids = seed();
$r = RafiEngine::search(RafiEngine::parse('kulaklik'));
$names = array_column($r['items'], 'name');
ok(in_array('Jabra Evolve2 Kulaklık', $names, true) && in_array('Xiaomi Buds Kulaklık', $names, true), 'Türkçe karakter yazmadan arama (kulaklik → Kulaklık)');
ok(!array_intersect($names, ['Pasif Kulaklık', 'Askıdaki Satıcı Kulaklık', 'Onay Bekleyen Kulaklık']), 'pasif ürün, askıdaki satıcı ve onay bekleyen ürün gösterilmez');
ok($r['total'] === 4 && count($r['items']) === 4, 'toplam sayı gerçek eşleşme sayısı (4)');
$r = RafiEngine::search(RafiEngine::parse('1000 TL altı kulaklık'));
ok(array_filter($r['items'], fn($p) => $p['price'] > 1000) === [] && $r['total'] === 3 && $r['relaxed'] === 4, 'fiyat filtresi gerçek fiyatlarla uygulanır');
$r = RafiEngine::search(RafiEngine::parse('en ucuz stokta kulaklık'));
ok($r['items'][0]['name'] === 'Jabra Evolve2 Kulaklık' && !in_array('Eski Kulaklık', array_column($r['items'], 'name'), true), 'en ucuz + stokta (tükenen hariç)');
$r = RafiEngine::search(RafiEngine::parse('indirimli kulaklık')); ok(count($r['items']) === 1 && $r['items'][0]['discount_pct'] === 18, 'indirim oranı gerçek eski/yeni fiyattan (%18)');
$j = RafiEngine::byIds([$ids['jabra']])[0];
ok($j['seller']['name'] === 'Ses Dünyası' && $j['seller']['rating'] === 4.5 && $j['seller']['rating_count'] === 2, 'satıcı adı ve yalnızca ONAYLI değerlendirmelerden puan (4,5 / 2)');
ok($j['features'] === ['Aktif gürültü engelleme', 'USB-A'] && $j['stock_text'] === 'Stokta', 'özellikler yalnızca açıklamadaki maddelerden; stok durumu');
$x = RafiEngine::byIds([$ids['xkul']])[0]; ok($x['seller']['name'] === 'Rafımda' && $x['stock_text'] === 'Son 3 adet' && $x['features'] === [], 'platform ürünü “Rafımda”; az stok; açıklama yoksa özellik uydurulmaz');
ok(RafiEngine::byIds([$ids['pasif'], $ids['askida']]) === [], 'byIds de pasif/askıdaki ürünü döndürmez');

section('Sohbet yanıtları (uydurma yok)');
$a = RafiBot::reply('1000 TL altı kulaklık');
ok(strpos($a['reply'], '3 ürün buldum') === 0 && count($a['products']) === 3 && count($a['ids']) === 3, 'yanıttaki sayı gerçek sonuç sayısı');
ok(!isset($a['products'][0]['_score']), 'iç skor istemciye gönderilmez');
$b = RafiBot::reply('ilk ikisini karşılaştır', $a['ids']);
ok($b['compare'] !== null && count($b['compare']['columns']) === 2 && $b['compare']['rows'][0]['label'] === 'Fiyat', 'önceki listeden ilk ikisi karşılaştırılır');
$labels = array_column($b['compare']['rows'], 'label'); ok($labels === ['Fiyat', 'İndirim', 'Stok', 'Marka', 'Kategori', 'Satıcı', 'Ürün puanı', 'Özellikler'], 'karşılaştırma: fiyat, indirim, stok, marka, kategori, satıcı, puan, özellik');
ok(strpos($b['reply'], 'En düşük fiyat:') !== false && strpos($b['reply'], 'yeterli müşteri yorumu yok') !== false, 'özet yalnızca tablodaki değerlerden; yorum yoksa bunu söyler');
$c = RafiBot::reply('Xiaomi saat ile Jabra kulaklık karşılaştır');
ok($c['compare'] && array_column($c['compare']['columns'], 'name') === ['Xiaomi Redmi Watch 5 Akıllı Saat', 'Jabra Evolve2 Kulaklık'], 'adla karşılaştırma doğru ürünleri bulur');
$d = RafiBot::reply('uçan halı');
ok($d['products'] === [] && strpos($d['reply'], 'eşleşen ürün bulamadım') !== false, 'katalogda olmayan ürün için “bulamadım” (uydurma yok)');
ok(array_column($d['suggestions'], 'label') === ['Elektronik'], 'öneri olarak yalnızca gerçek, ürünü olan kategoriler');
$e = RafiBot::reply('100 TL altı kulaklık'); ok($e['products'] === [] && strpos($e['reply'], 'Filtreler olmadan 4 ürün var') !== false, 'filtre sonuçsuzsa gerçek filtresiz sayı söylenir');
ok(RafiBot::reply('ilk ikisini karşılaştır')['compare'] === null, 'önceki liste yoksa karşılaştırma yapmaz, yönlendirir');
ok(strpos(RafiBot::reply(str_repeat('a', 301))['reply'], 'çok uzun') !== false && RafiBot::reply('<script>alert(1)</script>')['products'] === [], 'uzun mesaj ve HTML içeren mesaj güvenli');
ok(RafiBot::reply('merhaba')['reply'] === RafiBot::GREETING, 'karşılama');

section('API uç noktası (ayrı süreç)');
$run = function (string $method, array $post, bool $tokenOk, int $preHits = 0) { $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/chat_harness.php') . ' ' . escapeshellarg(json_encode(['m' => $method, 'p' => $post, 't' => $tokenOk, 'h' => $preHits])); return json_decode((string) shell_exec($cmd), true); };
$o = $run('GET', [], true); ok($o['code'] === 405, 'GET reddedilir (405)');
$o = $run('POST', ['message' => 'kulaklik'], false); ok($o['code'] === 419 && $o['body']['success'] === false, 'CSRF jetonu yoksa reddedilir (419)');
$o = $run('POST', ['message' => 'kulaklik'], true); ok($o['code'] === 200 && $o['body']['success'] && count($o['body']['products']) === 4 && !isset($o['body']['ids']) && count($o['session_last']) === 4, 'geçerli istek: ürünler döner; son liste oturumda (istemciye ids gitmez)');
ok(strpos($o['raw'], '<') === false, 'JSON çıktısında ham < yok (JSON_HEX_TAG)');
$o = $run('POST', ['message' => 'kulaklik'], true, 20); ok($o['code'] === 429, 'dakikada 20 mesaj sınırı (429)');

section('Şablon, JS ve modül');
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';
$sm = new Smarty\Smarty(); $sm->setCompileDir(sys_get_temp_dir() . '/rafi_tpl_' . getmypid()); @mkdir($sm->getCompileDir(), 0777, true); $sm->setEscapeHtml(false); // üretimle aynı: kaçış şablondaki |escape ile
$sm->assign(['rafiApi' => '/api/module.php?m=rafimda-rafi&action=chat', 'rafiAvatar' => '/x.svg"><script>', 'rafiGreet' => true, 'rafiGreeting' => RafiBot::GREETING]);
$h = $sm->fetch('file:' . dirname(__DIR__) . '/assets/templates/front/widget.tpl');
ok(strpos($h, '<script>') === false && strpos($h, 'data-greet="1"') !== false && strpos($h, 'aria-controls="rafiPanel"') !== false, 'widget: kaçış, karşılama bayrağı, erişilebilirlik');
$js = file_get_contents(dirname(__DIR__) . '/assets/js/rafi.js');
ok(strpos($js, 'innerHTML') === false && strpos($js, 'insertAdjacentHTML') === false && strpos($js, 'eval(') === false, 'JS sunucu verisini HTML olarak yazmaz');
ok(strpos($js, 'window.csrfToken') !== false && strpos($js, 'safeUrl') !== false, 'JS CSRF jetonu gönderir; yalnızca http(s)/site içi bağlantılar');
$code = file_get_contents(dirname(__DIR__) . '/lib/RafiEngine.php') . file_get_contents(dirname(__DIR__) . '/lib/RafiBot.php') . file_get_contents(dirname(__DIR__) . '/api/chat.php');
ok(!preg_match('/curl_|file_get_contents\s*\(\s*[\'"]https?:|fsockopen|stream_socket_client|AiClient/i', $code), 'harici ağ / ücretli yapay zekâ çağrısı YOK');
ok(!file_exists(dirname(__DIR__) . '/install.sql'), 'tablo oluşturmaz (canlı şema değişmez)');
$svg = file_get_contents(dirname(__DIR__) . '/assets/img/rafi.svg'); ok(strpos($svg, '#0b2545') !== false && strpos($svg, '#ff7a00') !== false && stripos($svg, '<script') === false, 'Rafi karakteri: lacivert/turuncu, betik içermez');
@array_map('unlink', glob(sys_get_temp_dir() . '/rafi_tpl_' . getmypid() . '/*') ?: []);
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n"; exit($fail ? 1 : 0);
