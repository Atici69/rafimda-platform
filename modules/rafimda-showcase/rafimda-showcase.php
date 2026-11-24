<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';
require_once __DIR__ . '/lib/RsShowcase.php';

/**
 * Rafımda Vitrin: ana sayfa vitrin alanları (öne çıkan, kampanyalı ürünler, reklam bannerı). Bağımsız modül; çekirdeğe dokunmaz.
 * Reklam faturalaması/ödeme YOKTUR; yerleşim yönetici onayıyla yapılır.
 */
class RafimdaShowcaseModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'rafimda-showcase';
	public string $title = 'Rafımda Vitrin';
	public string $version = self::VERSION;
	public string $description = 'Ana sayfa vitrin alanları: öne çıkan ürünler, kampanyalı ürünler, reklam bannerı; satıcı talebi + yönetici onayı';
	public string $author = 'Rafımda';

	public array $displayHooks = [
		'home_showcase' => 'Rafımda ana sayfa vitrini',
		'home_bottom' => 'Ana sayfa altı (yedek konum)',
	];

	public array $defaultDisplayHooks = ['home_showcase'];

	public array $frontStylesheets = ['showcase.css'];

	public function install(): bool
	{
		if (!$this->runSqlFile('install.sql')) {
			return false;
		}

		RsShowcase::ensureDefaults();

		return true;
	}

	public function uninstall(): bool
	{
		return $this->runSqlFile('uninstall.sql'); // veri silinmez
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Rafımda Vitrin', 'catalog', 42);
	}

	public function renderDisplayHook(string $hook, array $context = []): ?string
	{
		if ($hook !== 'home_showcase' && $hook !== 'home_bottom') {
			return null;
		}

		$blocks = [];

		foreach (RsShowcase::slots(true) as $s) {
			$items = RsShowcase::active((string) $s['code']);

			if ($items !== []) {
				$blocks[] = ['code' => $s['code'], 'title' => $s['title'], 'kind' => $s['kind'], 'items' => $items];
			}
		}

		if ($blocks === []) {
			return null;
		}

		$html = $this->renderFrontTemplate('showcase', ['rsBlocks' => $blocks]);

		return $html !== '' ? $html : null;
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken;

		$flash = null;
		$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';

		if ($isPost) {
			if (!hash_equals((string) $adminToken, (string) ($_POST['token'] ?? ''))) {
				$flash = ['type' => 'danger', 'message' => 'Geçersiz istek.'];
			} elseif (Admin::isDemoMode()) {
				$flash = ['type' => 'warning', 'message' => 'Demo modunda bu işlem yapılamaz.'];
			} else {
				$flash = self::handlePost();
			}
		}

		$status = (string) ($_GET['status'] ?? 'pending');
		$smarty->assign([
			'pageTitle' => $this->title, 'rsFlash' => $flash, 'rsToken' => $adminToken, 'rsSlots' => RsShowcase::slots(), 'rsItems' => RsShowcase::adminList($status), 'rsStatus' => $status,
		]);
	}

	/** @return array{type:string,message:string} */
	public static function handlePost(): array
	{
		switch ((string) ($_POST['rsAction'] ?? '')) {
			case 'slot_save':
				$r = RsShowcase::saveSlot((string) ($_POST['code'] ?? ''), $_POST);
				break;
			case 'item_add':
				$r = RsShowcase::addByAdmin($_POST);
				break;
			case 'item_moderate':
				$ok = RsShowcase::moderate((int) ($_POST['id_item'] ?? 0), (string) ($_POST['to'] ?? ''), (string) ($_POST['note'] ?? ''));
				$r = ['success' => $ok, 'message' => $ok ? 'Güncellendi.' : 'İşlem başarısız.'];
				break;
			default:
				$r = ['success' => false, 'message' => 'Bilinmeyen işlem.'];
		}

		return ['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['message']];
	}
}
