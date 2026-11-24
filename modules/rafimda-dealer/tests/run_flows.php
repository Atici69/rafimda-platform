<?php
/** Bayi akışları: her senaryo ayrı PHP sürecinde, gerçek Smarty şablonlarıyla (gerçek FriSay/MySQL/tarayıcı DEĞİL). */
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n\n"; } }
function drive(string ...$args): string
{
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/drive.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

	return (string) shell_exec($cmd);
}
function has(string $o, string $needle): bool { return strpos($o, $needle) !== false; }
function clean(string $o): bool { return !preg_match('/(Warning|Notice|Deprecated): |Fatal error|Uncaught|Smarty\\\\/', $o); }

echo "\n== Giriş\n";
$o = drive('login_get');
ok(has($o, 'Bayi Girişi') && has($o, 'forgot-password') && has($o, 'bayi-basvuru') && has($o, 'name="token" value="csrf-test"') && clean($o), 'giriş sayfası: form, şifremi unuttum, başvuru bağlantısı, CSRF alanı');
ok(has($o, 'viewport') && has($o, 'panel.css'), 'giriş sayfası mobil viewport ve CSS içeriyor');
$o = drive('login_bad');
ok(has($o, 'rd-alert danger') && has($o, 'hatalı') && !has($o, 'REDIRECT:'), 'hatalı girişte anlaşılır hata mesajı, yönlendirme yok');
ok(has(drive('login_dealer'), 'REDIRECT:https://test.local/bayi-panel?s=dashboard'), 'bayi girişi → bayi paneline yönlendirir');
ok(has(drive('login_nondealer'), 'REDIRECT:https://test.local/bayi-basvuru'), 'bayi olmayan hesap → başvuru sayfasına');

echo "\n== Başvuru\n";
$o = drive('apply_get');
ok(has($o, 'BAYİ BAŞVURUSU YAP') && has($o, 'name="company_name"') && has($o, 'accept_terms') && has($o, 'parent_code') && clean($o), 'başvuru formu tüm alanlarla çizilir');
ok(has(drive('apply_invite'), 'value="ABCD2345"'), 'davet bağlantısındaki üst bayi kodu forma gelir');
$o = drive('apply_invalid');
ok(has($o, 'class="err"') && !has($o, 'REDIRECT:'), 'geçersiz başvuruda alan hataları gösterilir');
$o = drive('apply_new');
ok(has($o, 'Başvurunuz alındı') && has($o, 'DEALERS:1 STATUS:pending USERS:7'), 'yeni hesap + başvuru: 1 bekleyen bayi, 1 yeni kullanıcı');
ok(has(drive('apply_noterms'), 'Bayilik koşullarını onaylamalısınız'), 'koşullar onaylanmadan başvuru alınmaz');
ok(has(drive('apply_wrongpass'), 'zaten kayıtlı'), 'kayıtlı e-postada yanlış şifre başvuruyu durdurur (hesap devralınamaz)');

echo "\n== Panel erişimi\n";
ok(has(drive('panel_guest'), 'REDIRECT:https://test.local/bayi-giris'), 'oturumsuz → bayi girişi');
ok(has(drive('panel_nondealer'), 'REDIRECT:https://test.local/bayi-basvuru'), 'bayi olmayan müşteri panele giremez');
$o = drive('panel_pending'); ok(has($o, 'Başvuru değerlendiriliyor') && !has($o, 'Müşteriler'), 'bekleyen bayi yalnızca durum sayfasını görür');
$o = drive('panel_rejected'); ok(has($o, 'Reddedildi') && has($o, 'Eksik evrak'), 'reddedilen bayi gerekçeyi görür');
$o = drive('panel_paused'); ok(has($o, 'durduruldu') && !has($o, 'Müşteriler'), 'üst bayi tarafından durdurulan alt bayi panele giremez');

echo "\n== Panel bölümleri (gerçek şablonlar)\n";
foreach (['dashboard' => 'Genel Bakış', 'customers' => 'Müşterilerim', 'subdealers' => 'Alt bayi davet', 'earnings' => 'Komisyon kayıtları', 'subscription' => 'Mevcut paket', 'reports' => 'Son 12 ay', 'profile' => 'Referans bağlantınız'] as $s => $needle) {
	$o = drive('panel_section', $s, 'top');
	ok(has($o, $needle) && has($o, 'rd-nav') && clean($o) && !has($o, 'REDIRECT:'), "ana bayi: $s bölümü çizilir");
}
$o = drive('panel_section', 'customers', 'top');
ok(has($o, 'Ayşe Müşteri') && has($o, 'a***@x.com') && !has($o, 'ayse@x.com'), 'müşteri listesinde e-posta maskeli');
$o = drive('panel_section', 'subdealers', 'sub');
ok(!has($o, 'Alt bayi davet') && has($o, 'Genel Bakış'), 'alt bayi, Alt Bayiler bölümüne giremez (dashboard\'a düşer)');
$o = drive('panel_section', 'dashboard', 'sub');
ok(!has($o, 'href="https://test.local/bayi-panel?s=subdealers"'), 'alt bayi menüsünde Alt Bayiler yok');
$o = drive('panel_section', 'dashboard', 'top');
ok(has($o, 'href="https://test.local/bayi-panel?s=subdealers"') && has($o, 'name="token" value="csrf-test"') && has($o, 'action="https://test.local/bayi-cikis"'), 'ana bayi menüsünde Alt Bayiler var; çıkış formu CSRF\'li');
$o = drive('panel_csv'); ok(has($o, 'Siparis No') && has($o, '100,00') && has($o, 'Doğrudan'), 'CSV dışa aktarma: başlık ve komisyon satırı');
$o = drive('panel_plan_request'); ok(has($o, 'REDIRECT:https://test.local/bayi-panel?s=subscription') && has($o, 'REQ:1'), 'paket talebi kaydedilir ve yönlendirilir');
$o = drive('panel_foreign_pause'); ok(has($o, 'PAUSED:0') && has($o, 'FLASH:danger'), 'başka bayinin alt bayisi durdurulamaz');

echo "\n== Çıkış ve referans\n";
ok(has(drive('logout_get'), 'REDIRECT:https://test.local/bayi-giris'), 'çıkış GET ile yönlendirir');
ok(has(drive('logout_post'), 'REDIRECT:https://test.local/bayi-giris'), 'çıkış POST yönlendirir');
$o = drive('ref_valid'); ok(has($o, 'REDIRECT:https://test.local/') && preg_match('/SESSION:[A-Z2-9]{8}/', $o) === 1, 'geçerli referans kodu oturuma yazılır, ana sayfaya yönlendirir');
$o = drive('ref_invalid'); ok(has($o, 'REDIRECT:https://test.local/') && has($o, 'SESSION:-'), 'geçersiz kod sessizce yok sayılır');

echo "\n== Yönetici ekranları (gerçek admin.tpl)\n";
foreach (['applications' => 'Mega Bayi|Ana Bayi', 'dealers' => 'Oranlar', 'plans' => 'Yeni paket', 'subscriptions' => 'Paket ata', 'commissions' => 'ödeme/muhasebe kaydı yoktur', 'reports' => 'Genel özet', 'settings' => 'Varsayılan komisyon'] as $tab => $needle) {
	$o = drive('admin', $tab);
	ok(preg_match('#' . $needle . '#u', $o) === 1 && clean($o) && has($o, 'nav-tabs'), "yönetici sekmesi çizilir: $tab");
}
$o = drive('admin_post', 'dealer_status'); ok(has($o, 'FLASH:success:') && has($o, 'STATUS:active'), 'yönetici başvuruyu onaylar');
$o = drive('admin_post', 'dealer_rates'); ok(has($o, 'RATE:7'), 'yönetici oranı kaydeder');
$o = drive('admin_post', 'settings'); ok(has($o, 'DEF:4.5'), 'varsayılan komisyon kaydedilir ve okunur');
$o = drive('admin_post', 'plan_save'); ok(has($o, 'PLAN:var'), 'yönetici paket ekler');
$o = drive('admin_badtoken'); ok(has($o, 'STATUS:pending'), 'yanlış yönetici CSRF token\'ı işlem yapmaz');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
