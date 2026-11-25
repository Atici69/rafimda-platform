<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';
require_once __DIR__ . '/lib/RcatTaxonomy.php';
require_once __DIR__ . '/lib/RcatMatcher.php';
require_once __DIR__ . '/lib/RcatInstaller.php';

/**
 * Rafımda Katalog: pazaryeri düzeninde tam kategori ağacı (yalnızca eksikleri ekler) ve otomatik kategori eşleştirme.
 * XML Hub ve satıcı Excel/CSV içe aktarması bu modül etkinse kategori adı/yolunu otomatik eşler.
 * Bağımsız modül; çekirdeğe ve temaya dokunmaz; kaldırıldığında veri silinmez.
 */
class RafimdaCatalogModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'rafimda-catalog';
	public string $title = 'Rafımda Kategori & Eşleştirme';
	public string $version = self::VERSION;
	public string $description = 'Pazaryeri kategori ağacı (eksikleri ekler) ve XML/Excel/pazaryeri için otomatik kategori eşleştirme';
	public string $author = 'Rafımda';

	public function install(): bool
	{
		return $this->runSqlFile('install.sql');
	}

	public function uninstall(): bool
	{
		return $this->runSqlFile('uninstall.sql'); // veri silinmez
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Kategori Eşleştirme', 'catalog', 41);
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken;

		$flash = null;
		$tab = (string) ($_GET['tab'] ?? 'tree');
		$tab = in_array($tab, ['tree', 'map', 'test'], true) ? $tab : 'tree';
		$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
		$test = null;

		if ($isPost) {
			if (!hash_equals((string) $adminToken, (string) ($_POST['token'] ?? ''))) {
				$flash = ['type' => 'danger', 'message' => 'Geçersiz istek.'];
			} elseif (class_exists('Admin') && method_exists('Admin', 'isDemoMode') && Admin::isDemoMode()) {
				$flash = ['type' => 'warning', 'message' => 'Demo modunda bu işlem yapılamaz.'];
			} else {
				[$flash, $test] = self::handlePost();
			}
		}

		$origin = (string) ($_GET['origin'] ?? 'pending');
		$smarty->assign([
			'pageTitle' => $this->title,
			'rcFlash' => $flash,
			'rcToken' => $adminToken,
			'rcTab' => $tab,
			'rcPlan' => $tab === 'tree' ? RcatInstaller::plan() : null,
			'rcOrigin' => $origin,
			'rcMaps' => $tab === 'map' ? RcatMatcher::listMappings($origin) : [],
			'rcCats' => $tab !== 'tree' ? RcatMatcher::candidates() : [],
			'rcTest' => $test,
			'rcThreshold' => RcatMatcher::AUTO_THRESHOLD,
		]);
	}

	/** @return array{0: array{type:string,message:string}|null, 1: array<string,mixed>|null} */
	public static function handlePost(): array
	{
		switch ((string) ($_POST['rcAction'] ?? '')) {
			case 'tree_apply':
				if (($_POST['confirm'] ?? '') !== 'EKLE') {
					return [['type' => 'warning', 'message' => 'Onay için kutuya EKLE yazın.'], null];
				}

				$r = RcatInstaller::apply();
				$msg = $r['created'] . ' kategori eklendi, ' . $r['existing'] . ' kategori zaten vardı (dokunulmadı).';

				if ($r['failed'] !== []) {
					$msg .= ' Eklenemeyen: ' . implode(', ', array_slice($r['failed'], 0, 10));
				}

				return [['type' => $r['failed'] === [] ? 'success' : 'warning', 'message' => $msg], null];
			case 'map_save':
				$id = self::parseCategoryInput((string) ($_POST['category'] ?? ''));
				$r = $id === null ? ['success' => false, 'message' => 'Listeden bir kategori seçin (veya boş bırakıp "Yok say" kullanın).'] : RcatMatcher::setManual((string) ($_POST['source'] ?? ''), $id);

				return [['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['message']], null];
			case 'map_ignore':
				$r = RcatMatcher::setManual((string) ($_POST['source'] ?? ''), 0);

				return [['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['message']], null];
			case 'accept_suggestions':
				$n = RcatMatcher::acceptSuggestions(is_array($_POST['keys'] ?? null) ? $_POST['keys'] : []);

				return [['type' => $n > 0 ? 'success' : 'warning', 'message' => $n . ' öneri onaylandı.'], null];
			case 'test':
				$src = mb_substr(trim((string) ($_POST['source'] ?? '')), 0, 255);
				$m = RcatMatcher::match($src);

				return [null, ['source' => $src, 'match' => $m, 'auto' => $m['score'] >= RcatMatcher::AUTO_THRESHOLD]];
			default:
				return [['type' => 'danger', 'message' => 'Bilinmeyen işlem.'], null];
		}
	}

	/** "Elektronik > Telefon > Kılıf #123" veya "123" → 123; boş → null */
	public static function parseCategoryInput(string $v): ?int
	{
		$v = trim($v);

		if (preg_match('/#(\d+)\s*$/', $v, $m) || preg_match('/^(\d+)$/', $v, $m)) {
			return (int) $m[1] > 0 ? (int) $m[1] : null;
		}

		return null;
	}
}
