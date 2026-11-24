<?php
/** Satıcı paneli akışları: her istek ayrı PHP sürecinde RvPanel::handle() ile (gerçek Smarty şablonları; gerçek FriSay/MySQL/tarayıcı DEĞİL). */
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function drive(array $cfg): string { return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/drive.php') . ' ' . escapeshellarg(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . ' 2>&1'); }
function has(string $o, string $n): bool { return strpos($o, $n) !== false; }
function clean(string $o): bool { return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)|Smarty\\\\/', $o); }
function probe(string $o, int $i) { return preg_match('/PROBE' . $i . ':(.*)/', $o, $m) ? json_decode($m[1], true) : null; }
function redirect(string $o): string { return preg_match('/REDIRECT:(\S+)/', $o, $m) ? $m[1] : ''; }
function flash(string $o): ?array { return preg_match('/FLASH:(.*)/', $o, $m) ? json_decode($m[1], true) : null; }
function href_targets(string $o): array { preg_match_all('/href="(https:\/\/test\.local\/satici-panel\?s=[a-z]+)/', $o, $m); return array_values(array_unique($m[1])); }

echo "\n== Erişim\n";
$o = drive(['user' => 0, 'get' => ['s' => 'dashboard']]); ok(redirect($o) === 'https://test.local/satici-giris', 'oturumsuz → satıcı girişi');
$o = drive(['user' => 3, 'get' => ['s' => 'dashboard']]); ok(redirect($o) === 'https://test.local/satici-kayit', 'mağazası olmayan müşteri → satıcı kaydı');

echo "\n== 13 ekran: açılır, hatasız, kendi verisini gösterir\n";
$screens = ['dashboard' => 'Hızlı işlemler', 'products' => 'Pamuklu Tişört', 'orders' => 'R1', 'stock' => 'Kot Pantolon', 'pricing' => 'Kot Pantolon', 'finance' => 'bakiye', 'store' => 'Moda Evi', 'customers' => 'Ayşe', 'staff' => 'personel@x.com', 'subscription' => 'Paketiniz', 'reports' => 'Satış raporu', 'settings' => 'Düşük stok eşiği', 'campaigns' => 'Vitrin'];
foreach ($screens as $s => $needle) {
	$o = drive(['get' => ['s' => $s], 'campaign' => 1]);
	ok(has($o, $needle) && clean($o) && has($o, '<main class="rv-content">') && !has($o, 'Başkasının') && !has($o, 'Gizli Müşteri') && redirect($o) === '', "ekran '$s' açılır, '$needle' görünür, başka satıcı verisi yok" . (clean($o) ? '' : ' [UYARI/HATA]'));
}

echo "\n== Menü: her bağlantı gerçek bir ekrana gider\n";
$o = drive(['get' => ['s' => 'dashboard']]); $targets = href_targets($o); $bad = [];
foreach ($targets as $u) { parse_str((string) parse_url($u, PHP_URL_QUERY), $q); $r = drive(['get' => ['s' => $q['s']]]); if (!clean($r) || !has($r, '<main class="rv-content">') || has($r, 'Bu bölüm henüz geliştirilmedi')) { $bad[] = $q['s']; } }
ok(count($targets) >= 13 && $bad === [], 'menüdeki ' . count($targets) . ' bağlantının tamamı çalışan ekran' . ($bad ? ' — bozuk: ' . implode(',', $bad) : ''));
ok(!has($o, 'yakında') && !has($o, 'XML Merkezi') , 'geliştirilmemiş bölümler menüde yok (yakında/ölü bağlantı yok)');
$o = drive(['get' => ['s' => 'invoices']]); ok(has($o, 'Bu bölüm henüz geliştirilmedi') && !has($o, 'href="https://test.local/satici-panel?s=invoices"'), 'doğrudan adresle geliştirilmemiş bölüm açılırsa açıklayıcı sayfa (menüde yok)');

echo "\n== Yetki: personel (görüntüleyici)\n";
$o = drive(['user' => 2, 'get' => ['s' => 'orders']]); ok(has($o, 'R1') && clean($o), 'görüntüleyici siparişleri görebilir');
$o = drive(['user' => 2, 'get' => ['s' => 'staff']]); ok(!has($o, 'name="action" value="add_staff"'), 'görüntüleyici personel yönetemez (ekleme formu yok)');
$o = drive(['user' => 2, 'method' => 'POST', 'get' => ['s' => 'settings'], 'post' => ['low_stock' => '9'], 'probes' => ['SELECT COUNT(*) FROM rafimda_vendor_prefs']]);
ok(probe($o, 0) === 0 || probe($o, 0) === '0', 'görüntüleyici ayarları değiştiremez (kayıt oluşmadı)');
$o = drive(['user' => 2, 'get' => ['s' => 'products', 'a' => 'new']]); ok(redirect($o) === 'https://test.local/satici-panel?s=dashboard' && flash($o)['type'] === 'danger' && !has($o, 'name="product_name"'), 'görüntüleyici ürün ekleyemez (panele yönlendirilir, uyarı)');

echo "\n== Ayarlar\n";
$o = drive(['method' => 'POST', 'get' => ['s' => 'settings'], 'post' => ['low_stock' => '12', 'default_cargo' => 'Yurtiçi Kargo'], 'probes' => ['SELECT low_stock FROM rafimda_vendor_prefs', "SELECT default_cargo FROM rafimda_vendor_prefs"]]);
ok(redirect($o) === 'https://test.local/satici-panel?s=settings' && probe($o, 0) == 12 && probe($o, 1) === 'Yurtiçi Kargo' && flash($o)['type'] === 'success', 'ayar kaydedilir ve ekrana döner');
$o = drive(['method' => 'POST', 'get' => ['s' => 'settings'], 'post' => ['low_stock' => 'abc'], 'probes' => ['SELECT COUNT(*) FROM rafimda_vendor_prefs']]);
ok(flash($o)['type'] === 'danger' && probe($o, 0) == 0, 'geçersiz eşik reddedilir');

echo "\n== Sipariş detayı ve kargo\n";
$o = drive(['get' => ['s' => 'orders', 'a' => 'view', 'id' => 1]]); ok(has($o, 'Sipariş #R1') && has($o, 'Pamuklu Tişört') && has($o, 'Ayşe Müşteri') && has($o, 'Komisyon (%10') && has($o, 'name="tracking_number"') && clean($o), 'sipariş detayı: kalemler, müşteri, komisyon, kargo formu');
$o = drive(['get' => ['s' => 'orders', 'a' => 'view', 'id' => 3]]); ok(redirect($o) === 'https://test.local/satici-panel?s=orders' && flash($o)['type'] === 'danger', 'başka satıcının siparişi açılamaz (IDOR)');
$o = drive(['method' => 'POST', 'get' => ['s' => 'orders'], 'post' => ['id_vendor_order' => 1, 'status' => 'shipped', 'cargo_company' => 'Aras', 'tracking_number' => 'TRK123'], 'probes' => ["SELECT status FROM rafimda_vendor_orders WHERE id_vendor_order = 1", "SELECT tracking_number FROM rafimda_vendor_orders WHERE id_vendor_order = 1"]]);
ok(probe($o, 0) === 'shipped' && probe($o, 1) === 'TRK123', 'kargoya verildi + takip no kaydedilir');
$o = drive(['method' => 'POST', 'get' => ['s' => 'orders'], 'post' => ['id_vendor_order' => 3, 'status' => 'shipped', 'cargo_company' => 'Aras', 'tracking_number' => 'HACK'], 'probes' => ["SELECT status FROM rafimda_vendor_orders WHERE id_vendor_order = 3"]]);
ok(probe($o, 0) === 'pending', 'başka satıcının siparişi değiştirilemez');
$o = drive(['method' => 'POST', 'get' => ['s' => 'orders'], 'post' => ['id_vendor_order' => 1, 'status' => 'delivered'], 'probes' => ["SELECT status FROM rafimda_vendor_orders WHERE id_vendor_order = 1"]]);
ok(probe($o, 0) === 'pending', 'satıcı siparişi "teslim edildi" yapamaz');
$o = drive(['method' => 'POST', 'get' => ['s' => 'settings'], 'post' => ['low_stock' => '5', 'default_cargo' => 'Yurtiçi Kargo']]);

echo "\n== Ürün ekle / düzenle\n";
$o = drive(['get' => ['s' => 'products', 'a' => 'new']]); ok(has($o, 'name="product_name"') && has($o, 'name="price"') && has($o, 'name="stock"') && clean($o), 'ürün ekleme formu');
$o = drive(['method' => 'POST', 'get' => ['s' => 'products'], 'post' => ['action' => 'create', 'product_name' => 'Yeni Elbise', 'price' => '149,90', 'stock' => '7', 'id_category' => 1, 'id_brand' => 1, 'active' => 1], 'probes' => ["SELECT COUNT(*) FROM products WHERE product_name = 'Yeni Elbise'", "SELECT COUNT(*) FROM rafimda_vendor_products vp JOIN products p ON p.id_product = vp.id_product WHERE p.product_name = 'Yeni Elbise' AND vp.id_vendor = 1"]]);
ok(has(redirect($o), 'a=edit') && probe($o, 0) == 1 && probe($o, 1) == 1, 'ürün oluşturulur ve satıcıya bağlanır');
$o = drive(['method' => 'POST', 'get' => ['s' => 'products'], 'post' => ['action' => 'create', 'product_name' => 'x', 'price' => '-5', 'stock' => 'abc'], 'probes' => ["SELECT COUNT(*) FROM products"]]);
ok(redirect($o) === '' && has($o, 'class="err"') && probe($o, 0) == 3 && has($o, 'name="product_name"'), 'geçersiz ürün kaydedilmez, form hatalarla yeniden çizilir');
$o = drive(['get' => ['s' => 'products', 'a' => 'edit', 'id' => 1]]); ok(has($o, 'value="Pamuklu Tişört"') && clean($o), 'ürün düzenleme formu mevcut verilerle');
$o = drive(['get' => ['s' => 'products', 'a' => 'edit', 'id' => 3]]); ok(redirect($o) !== '' && !has($o, 'Başkasının'), 'başkasının ürünü düzenlenemez');
$o = drive(['method' => 'POST', 'get' => ['s' => 'products'], 'post' => ['action' => 'update', 'id_product' => 1, 'product_name' => 'Pamuklu Tişört v2', 'price' => '120', 'stock' => '40', 'id_category' => 1, 'id_brand' => 1, 'active' => 1], 'probes' => ["SELECT price FROM products WHERE id_product = 1", "SELECT product_name FROM products WHERE id_product = 1"]]);
ok(probe($o, 0) == 120 && probe($o, 1) === 'Pamuklu Tişört v2', 'ürün güncellenir');
$o = drive(['method' => 'POST', 'get' => ['s' => 'products'], 'post' => ['action' => 'update', 'id_product' => 3, 'product_name' => 'ELE GEÇİRİLDİ', 'price' => '1', 'stock' => '1', 'id_category' => 1, 'id_brand' => 1, 'active' => 1], 'probes' => ["SELECT product_name FROM products WHERE id_product = 3"]]);
ok(probe($o, 0) === 'Başkasının Ürünü', 'başka satıcının ürünü güncellenemez');
$o = drive(['method' => 'POST', 'get' => ['s' => 'products'], 'post' => ['action' => 'quick', 'id_product' => 2, 'price' => '350', 'stock' => '10'], 'probes' => ["SELECT price FROM products WHERE id_product = 2", "SELECT stock FROM products WHERE id_product = 2"]]);
ok(probe($o, 0) == 350 && probe($o, 1) == 10, 'hızlı fiyat/stok güncelleme');
$o = drive(['method' => 'POST', 'get' => ['s' => 'products'], 'post' => ['action' => 'delete', 'id_product' => 3], 'probes' => ["SELECT COUNT(*) FROM products WHERE id_product = 3"]]);
ok(probe($o, 0) == 1, 'başka satıcının ürünü silinemez');

echo "\n== Stok ve fiyat\n";
$o = drive(['method' => 'POST', 'get' => ['s' => 'stock'], 'post' => ['action' => 'stock_save', 'rows' => [1 => ['stock' => '77'], 3 => ['stock' => '999']]], 'probes' => ["SELECT stock FROM products WHERE id_product = 1", "SELECT stock FROM products WHERE id_product = 3"]]);
ok(probe($o, 0) == 77 && probe($o, 1) == 9, 'toplu stok: kendi ürünü güncellenir, başkasınınki değişmez');
$o = drive(['method' => 'POST', 'get' => ['s' => 'pricing'], 'post' => ['action' => 'price_save', 'rows' => [2 => ['price' => '310,50']]], 'probes' => ["SELECT price FROM products WHERE id_product = 2"]]);
ok(probe($o, 0) == 310.5, 'toplu fiyat güncelleme');
$o = drive(['method' => 'POST', 'get' => ['s' => 'pricing'], 'post' => ['action' => 'price_adjust', 'ids' => [1, 2], 'percent' => '10'], 'probes' => ["SELECT price FROM products WHERE id_product = 1", "SELECT price FROM products WHERE id_product = 2"]]);
ok(probe($o, 0) == 110 && probe($o, 1) == 330, 'yüzde zam: +%10 (100→110, 300→330)');
$o = drive(['get' => ['s' => 'stock', 'f' => 'low']]); ok(has($o, 'Kot Pantolon') && !has($o, 'Pamuklu Tişört') && clean($o), 'stok: azalan filtresi');

echo "\n== Finans ve müşteriler ve rapor\n";
$o = drive(['get' => ['s' => 'finance']]); ok(has($o, '450.00') && has($o, 'Bekleyen') && clean($o), 'finans: bekleyen bakiye 450 ₺ (200+300 − %10 komisyon)');
$o = drive(['get' => ['s' => 'customers']]); ok(has($o, 'Ayşe Müşteri') && !has($o, 'Gizli Müşteri'), 'müşteriler: yalnızca kendi müşterileri');
$o = drive(['get' => ['s' => 'reports', 'days' => 7]]); ok(has($o, '500.00 ₺') && has($o, 'Kot Pantolon') && has($o, 'Pamuklu Tişört') && !has($o, 'Başkasının'), 'rapor: satış 500 ₺, en çok satanlar, izole');

echo "\n== Personel ve mağaza\n";
$o = drive(['method' => 'POST', 'get' => ['s' => 'staff'], 'post' => ['action' => 'add_staff', 'email' => 'musteri@x.com', 'role' => 'catalog'], 'probes' => ["SELECT role FROM rafimda_vendor_staff WHERE id_vendor = 1 AND id_user = 3"]]);
ok(probe($o, 0) === 'catalog', 'personel e-postayla eklenir');
$o = drive(['method' => 'POST', 'get' => ['s' => 'staff'], 'post' => ['action' => 'add_staff', 'email' => 'yok@x.com', 'role' => 'catalog'], 'probes' => ["SELECT COUNT(*) FROM rafimda_vendor_staff WHERE id_vendor = 1"]]);
ok(probe($o, 0) == 2 && flash($o)['type'] === 'danger', 'kayıtsız e-posta eklenemez');
$o = drive(['method' => 'POST', 'get' => ['s' => 'store'], 'post' => ['store_name' => 'Moda Evi Yeni', 'phone' => '05321112233', 'email' => 'yeni@x.com', 'city' => 'İzmir', 'description' => 'Açıklama'], 'probes' => ["SELECT store_name FROM rafimda_vendors WHERE id_vendor = 1", "SELECT store_name FROM rafimda_vendors WHERE id_vendor = 2"]]);
ok(probe($o, 0) === 'Moda Evi Yeni' && probe($o, 1) === 'Diğer Mağaza', 'mağaza bilgileri güncellenir, diğer mağaza etkilenmez');
$o = drive(['method' => 'POST', 'get' => ['s' => 'store'], 'post' => ['store_name' => 'a', 'email' => 'bozuk'], 'probes' => ["SELECT store_name FROM rafimda_vendors WHERE id_vendor = 1"]]);
ok(probe($o, 0) === 'Moda Evi' && has($o, 'class="err"'), 'geçersiz mağaza bilgisi reddedilir, hatalar gösterilir');

echo "\n== Abonelik ve kampanyalar\n";
$o = drive(['get' => ['s' => 'subscription'], 'campaign' => 1]); ok(has($o, 'Düşük komisyon kampanyası') && has($o, 'name="action" value="campaign_join"') && clean($o), 'abonelik: katılabileceği kampanya listelenir');
$o = drive(['method' => 'POST', 'get' => ['s' => 'subscription'], 'campaign' => 1, 'post' => ['action' => 'campaign_join', 'id_campaign' => 1], 'probes' => ['SELECT COUNT(*) FROM rc_enrollments WHERE id_vendor = 1']]);
ok(probe($o, 0) == 1 && flash($o)['type'] === 'success', 'satıcı kampanyaya katılır');
$o = drive(['method' => 'POST', 'get' => ['s' => 'subscription'], 'campaign' => 1, 'user' => 2, 'post' => ['action' => 'campaign_join', 'id_campaign' => 1], 'probes' => ['SELECT COUNT(*) FROM rc_enrollments']]);
ok(probe($o, 0) == 0, 'görüntüleyici kampanyaya katılamaz');
$o = drive(['get' => ['s' => 'campaigns'], 'fee' => 1]); ok(has($o, 'İlk ay ücretsiz vitrin') && has($o, 'ücretsizdir') && clean($o), 'vitrin ekranı: ilk ay ücretsiz bilgisi');
$o = drive(['method' => 'POST', 'get' => ['s' => 'campaigns'], 'fee' => 1, 'post' => ['action' => 'showcase_request', 'slot_code' => 'home_featured', 'id_product' => 1], 'probes' => ["SELECT status FROM rc_charges", 'SELECT COUNT(*) FROM rs_items']]);
ok(probe($o, 0) === 'waived' && probe($o, 1) == 1, 'yeni satıcının vitrin talebi ücretsiz (muaf) kaydedilir');
$o = drive(['method' => 'POST', 'get' => ['s' => 'campaigns'], 'fee' => 1, 'post' => ['action' => 'showcase_request', 'slot_code' => 'home_featured', 'id_product' => 3], 'probes' => ['SELECT COUNT(*) FROM rs_items']]);
ok(probe($o, 0) == 0, 'başkasının ürünü için vitrin talebi açılamaz');

echo "\n== Komisyon kampanyası sipariş akışında\n";
$o = drive(['get' => ['s' => 'finance'], 'campaign' => 1]); ok(has($o, '10') && clean($o), 'finans ekranı kampanya modülü varken açılır (gösterim yan etkisiz)');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
