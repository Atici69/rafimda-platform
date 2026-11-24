<?php
/**
 * ÖNİZLEME (geliştirme aracı, canlıda gerekmez): bir HTML parçasını GERÇEK yönetici düzeni (templates/admin/layout/header.tpl + footer.tpl)
 * içine koyar. Veritabanına bağlanmaz. Menü öğeleri modüllerin gerçek registerAdminMenuLink çağrılarından okunur.
 * Kullanım: php admin_shell.php <manifest.json>   manifest: [{"src":"parça.html","out":"sayfa.html","page":"xml-hub","title":"XML Hub"}]
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/libs/Smarty.class.php';
$manifest = json_decode((string) file_get_contents($argv[1] ?? ''), true);

if (!is_array($manifest)) {
	fwrite(STDERR, "manifest okunamadı\n");
	exit(2);
}

$menu = ['general' => [], 'sales' => [], 'catalog' => [], 'marketplace' => [], 'system' => []];

foreach (glob($root . '/modules/*/*.php') as $f) {
	$mod = basename(dirname($f));

	if (basename($f, '.php') !== $mod || !preg_match("/registerAdminMenuLink\\('([^']+)',\\s*'([a-z]+)',\\s*(\\d+)\\)/", (string) file_get_contents($f), $m) || !isset($menu[$m[2]])) {
		continue;
	}

	if (strpos($mod, 'rafimda-') === 0 || in_array($mod, ['xml-hub', 'marketplace-hub'], true)) {
		$menu[$m[2]][(int) $m[3]] = ['url' => 'file:///x/admin/' . $mod, 'label' => $m[1], 'slug' => $mod, 'badge' => 0, 'target' => ''];
	}
}

foreach ($menu as $g => $items) {
	ksort($items);
	$menu[$g] = array_values($items);
}

$smarty = new Smarty\Smarty();
$smarty->setCompileDir(sys_get_temp_dir() . '/pv_admin_' . getmypid());
@mkdir($smarty->getCompileDir(), 0777, true);
$smarty->setTemplateDir($root . '/templates');
$smarty->registerPlugin('modifier', 'adminT', static fn($s) => $s);
$css = 'file://' . $root . '/templates/admin/css/';

foreach ($manifest as $job) {
	$smarty->clearAllAssign();
	$smarty->assign([
		'adminLang' => 'tr', 'adminCssDir' => $css, 'siteName' => 'Rafımda', 'adminLogoUrl' => '', 'adminUrl' => 'file:///x/admin/', 'domain' => 'https://test.local/',
		'pageName' => $job['page'], 'pageTitle' => $job['title'], 'adminMenuItems' => $menu, 'adminFavoriteItems' => [], 'adminNavBadges' => ['notifications' => 0, 'messages' => 0, 'orders' => 0, 'returns' => 0, 'cancellations' => 0, 'upgrade' => 0], 'adminUser' => ['full_name' => 'Yönetici (ÖRNEK)', 'email' => 'admin@ornek.test'],
		'adminToken' => 'x', 'adminInitial' => 'Y', 'adminHooks' => [], 'adminLangSwitcher' => [], 'adminUriIsDefault' => false, 'adminBodyClass' => '', 'moduleAdminAssets' => ['css' => $job['css'] ?? [], 'js' => []], 'marketplaceAdminAssets' => ['css' => []], 'moduleNavActive' => $job['page'],
	]);

	try {
		$head = $smarty->fetch('file:' . $root . '/templates/admin/layout/header.tpl');
		$foot = $smarty->fetch('file:' . $root . '/templates/admin/layout/footer.tpl');
	} catch (Throwable $e) {
		fwrite(STDERR, $job['out'] . ': ' . $e->getMessage() . "\n");
		exit(1);
	}

	$body = (string) file_get_contents($job['src']);
	file_put_contents($job['out'], $head . '<main class="admin-main"><div class="admin-content" style="padding:20px">' . $body . '</div></main>' . $foot);
	echo 'ok ' . basename($job['out']) . "\n";
}
