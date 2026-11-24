<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

foreach (['RsoCrypt', 'publishers/RsoPublisher', 'publishers/RsoOfficialPublisher', 'RsoContent', 'RsoPlanner', 'RsoPanel'] as $rf) {
	require_once __DIR__ . '/lib/' . $rf . '.php';
}

/**
 * Rafımda Sosyal Medya: ürünlerden içerik üretimi (görsel/video/metin/hashtag), önizleme, onay, içerik takvimi ve yayın öncesi kontrol.
 * Resmî API'ler (Meta Graph, TikTok Content Posting, YouTube Data) için OAuth altyapısı; GERÇEK OTOMATİK YAYIN KAPALI ve henüz uygulanmadı.
 * Bot girişi, otomatik beğeni/takip/yorum/mesaj YOKTUR.
 */
class RafimdaSocialModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'rafimda-social';
	public string $title = 'Sosyal Medya (Rafımda)';
	public string $version = self::VERSION;
	public string $description = 'Instagram / TikTok / YouTube için ürün içeriği üretimi, önizleme, onay ve içerik takvimi (otomatik yayın kapalı)';
	public string $author = 'Rafımda';

	public array $routes = ['sosyal-geri-donus' => 'front/oauth_callback.php'];

	public function install(): bool
	{
		if (!$this->runSqlFile('install.sql')) {
			return false;
		}

		foreach (['PUBLISH' => '0', 'REPEAT_DAYS' => '14', 'VENDOR_MONTHLY_LIMIT' => '30', 'VENDOR_APPROVAL' => '1'] as $k => $v) {
			if ((string) Settings::get('RAFIMDA_SOCIAL_' . $k) === '') {
				Settings::set('RAFIMDA_SOCIAL_' . $k, $v);
			}
		}

		return true;
	}

	public function uninstall(): bool
	{
		return $this->runSqlFile('uninstall.sql');
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Sosyal Medya', 'marketing', 40);
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken, $domain;

		$flash = null;

		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
			if (!hash_equals((string) $adminToken, (string) ($_POST['token'] ?? ''))) {
				$flash = ['type' => 'danger', 'message' => 'Geçersiz istek.'];
			} elseif (Admin::isDemoMode()) {
				$flash = ['type' => 'warning', 'message' => 'Demo modunda bu işlem yapılamaz.'];
			} else {
				$r = ($_POST['rsoAction'] ?? '') === 'settings' ? self::saveSettings($_POST)
					: RsoPanel::action('platform', 0, $_POST + ['admin_id' => (int) Admin::getId()], 'admin', rtrim((string) $domain, '/') . '/sosyal-geri-donus');

				if (!empty($r['url'])) {
					header('Location: ' . $r['url'], true, 302);
					exit;
				}

				$flash = ['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['message']];
			}
		}

		$tab = in_array($_GET['tab'] ?? 'calendar', ['calendar', 'create', 'queue', 'accounts', 'reports', 'settings'], true) ? (string) ($_GET['tab'] ?? 'calendar') : 'calendar';
		$smarty->assign(['pageTitle' => $this->title, 'rsoFlash' => $flash, 'rsoToken' => $adminToken, 'rsoTab' => $tab] + RsoPanel::vars('platform', 0, $_GET) + self::settingsVars());
	}

	public static function settingsVars(): array
	{
		return ['rsoSettings' => ['publish' => RsoPlanner::publishEnabled(), 'repeat_days' => RsoPlanner::setting('REPEAT_DAYS', '14'), 'vendor_limit' => RsoPlanner::setting('VENDOR_MONTHLY_LIMIT', '30'),
			'vendor_approval' => RsoPlanner::setting('VENDOR_APPROVAL', '1') === '1',
			'client_ids' => array_map(static fn($p) => RsoPlanner::clientId($p), array_combine(array_keys(RsoPlanner::PLATFORMS), array_keys(RsoPlanner::PLATFORMS)))],
			'rsoLogs' => DB::execute('SELECT * FROM rso_events ORDER BY id_event DESC LIMIT 100') ?: []];
	}

	public static function saveSettings(array $p): array
	{
		Settings::set('RAFIMDA_SOCIAL_REPEAT_DAYS', (string) max(0, min(365, (int) ($p['repeat_days'] ?? 14))));
		Settings::set('RAFIMDA_SOCIAL_VENDOR_MONTHLY_LIMIT', (string) max(0, min(1000, (int) ($p['vendor_limit'] ?? 30))));
		Settings::set('RAFIMDA_SOCIAL_VENDOR_APPROVAL', ($p['vendor_approval'] ?? '') === '1' ? '1' : '0');

		foreach (array_keys(RsoPlanner::PLATFORMS) as $pl) {
			Settings::set('RAFIMDA_SOCIAL_' . strtoupper($pl) . '_CLIENT_ID', mb_substr(trim(strip_tags((string) ($p['client_' . $pl] ?? ''))), 0, 190));
		}

		if (($p['publish'] ?? '') === '1') {
			if (trim((string) ($p['publish_confirm'] ?? '')) !== 'OTOMATIK YAYINI ACIYORUM') {
				return ['success' => false, 'message' => 'Diğer ayarlar kaydedildi; otomatik yayın için onay ifadesi eksik veya hatalı.'];
			}

			Settings::set('RAFIMDA_SOCIAL_PUBLISH', '1');
			RsoPlanner::event(0, 'publish_enabled', 'Otomatik yayın açıldı (yalnızca bağlı hesap + uygulanmış resmî API ile çalışır)', false, 'admin');
		} else {
			Settings::set('RAFIMDA_SOCIAL_PUBLISH', '0');
		}

		return ['success' => true, 'message' => 'Sosyal medya ayarları kaydedildi.'];
	}
}
