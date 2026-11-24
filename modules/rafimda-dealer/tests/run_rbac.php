<?php
/** RBAC: yetki matrisi, panel menüsü ve işlem kapıları tutarlı mı? */
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/RdPanel.php';
echo "\n== Matris\n";
ok(array_keys(RdPerm::ROLES) === ['admin', 'dealer', 'subdealer', 'guest'], 'dört rol tanımlı');
foreach (RdPerm::ROLES as $r => $_) { foreach (RdPerm::forRole($r) as $p) { ok(isset(RdPerm::PERMISSIONS[$p]), "yetki tanımlı ($r): $p"); } }
ok(RdPerm::forRole('yok') === [] && !RdPerm::can('yok', 'panel.dashboard') && !RdPerm::can('dealer', 'yok.yetki'), 'bilinmeyen rol/yetki reddedilir');
ok(!RdPerm::can('subdealer', 'panel.subdealers') && !RdPerm::can('subdealer', 'subdealers.manage') && RdPerm::can('dealer', 'subdealers.manage'), 'alt bayi, alt bayi yönetemez; ana bayi yönetir');
ok(!array_filter(['dealer', 'subdealer', 'guest'], fn($r) => count(array_filter(RdPerm::forRole($r), fn($p) => in_array($p, RdPerm::forRole('admin'), true))) > 0), 'hiçbir bayi/ziyaretçi yönetici yetkisine sahip değil');
ok(!array_filter(RdPerm::forRole('admin'), fn($p) => str_starts_with($p, 'panel.')), 'yönetici bayi paneli yetkilerini taşımaz (ayrı yönetim ekranı)');
ok(!array_filter(array_keys(RdPerm::PERMISSIONS), fn($p) => preg_match('/muhasebe|accounting|invoice|fatura/i', $p . RdPerm::PERMISSIONS[$p])), 'muhasebe/fatura yetkisi yok (muhasebe modülü pasif)');
ok(RdPerm::roleOf(['id_parent' => 0]) === 'dealer' && RdPerm::roleOf(['id_parent' => 5]) === 'subdealer' && RdPerm::roleOf([]) === 'dealer', 'rol bayi kaydından türetilir');
echo "\n== Panel menüsü ile uyum\n";
foreach (RdPanel::MENU as $k => $m) {
	ok(RdPerm::can('dealer', "panel.$k"), "ana bayi '$k' bölümünü görür");
	ok(RdPerm::can('subdealer', "panel.$k") === !$m[2], "alt bayi '$k': yalnızca-ana-bayi bayrağıyla tutarlı");
}
foreach (RdPerm::PERMISSIONS as $p => $_) { if (str_starts_with($p, 'panel.')) { ok(isset(RdPanel::MENU[substr($p, 6)]), "panel yetkisi gerçek bir menü bölümüne bağlı: $p"); } }
echo "\n== Yönetim işlemleri\n";
$src = (string) file_get_contents(dirname(__DIR__) . '/rafimda-dealer.php');
preg_match_all("/case '([a-z_]+)':/", substr($src, (int) strpos($src, 'function handleAdminPost')), $m);
$cases = array_values(array_unique($m[1]));
ok($cases !== [] && !array_diff($cases, array_keys(RdPerm::ADMIN_ACTIONS)) && !array_diff(array_keys(RdPerm::ADMIN_ACTIONS), $cases), 'handleAdminPost işlemleri ile ADMIN_ACTIONS birebir aynı: ' . implode(',', $cases));
foreach (RdPerm::ADMIN_ACTIONS as $a => $p) { ok(RdPerm::can('admin', $p), "yönetici '$a' işlemini yapabilir ($p)"); ok(!RdPerm::can('dealer', $p) && !RdPerm::can('subdealer', $p), "bayi '$a' işlemini yapamaz"); }
$panelSrc = (string) file_get_contents(dirname(__DIR__) . '/lib/RdPanel.php');
ok(str_contains($panelSrc, "RdPerm::can(\$role, 'subdealers.manage')") && str_contains($panelSrc, "RdPerm::can(\$role, 'subscription.request')") && str_contains($panelSrc, "RdPerm::can(\$role, 'earnings.export')"), 'panel işlemleri sunucu tarafında RdPerm ile kapılanır');
echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
