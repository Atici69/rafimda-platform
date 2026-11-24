<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

foreach (['RvMigrator', 'RvPerm', 'RvAudit', 'RvVendor', 'RvContext', 'RvProducts', 'RvView', 'RvAuthFlow', 'RvPanel'] as $rvClass) {
	require_once __DIR__ . '/lib/' . $rvClass . '.php';
}

/**
 * Rafımda çok satıcılı (multi-vendor) altyapı — Aşama 1.
 * Çekirdek (core/, container/, config/) dosyalarına dokunmaz; tüm sayfalar modül rotalarıyla çalışır.
 */
class RafimdaVendorModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'rafimda-vendor';
	public string $title = 'Rafımda Satıcı Sistemi';
	public string $version = self::VERSION;
	public string $description = 'Çok satıcılı altyapı: satıcı kaydı, mağaza, personel/yetki, veri izolasyonu ve satıcı paneli';
	public string $author = 'Rafımda';

	public array $routes = [
		'satici-giris' => 'front/login.php',
		'satici-kayit' => 'front/register.php',
		'satici-cikis' => 'front/logout.php',
		'satici-panel' => 'front/panel.php',
	];

	public function install(): bool
	{
		if (!$this->runSqlFile('install.sql')) {
			return false;
		}

		$res = RvMigrator::run();

		if (!$res['success']) {
			error_log('rafimda-vendor: ' . $res['error']);

			return false;
		}

		if (Settings::get('RAFIMDA_VENDOR_AUTO_APPROVE') === '') {
			Settings::set('RAFIMDA_VENDOR_AUTO_APPROVE', '0');
		}

		return true;
	}

	/** Veri silinmez (geri dönüş planı); bkz. uninstall.destructive.sql.manual */
	public function uninstall(): bool
	{
		return true;
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Satıcılar (Rafımda)', 'sales', 60);
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken;

		$flash = '';
		$flashType = 'info';

		if (RvMigrator::pending() !== [] && Tools::isSubmit('rvRunMigrations')) {
			if (hash_equals((string) $adminToken, (string) Tools::getValue('token'))) {
				$r = RvMigrator::run();
				$flash = $r['success'] ? 'Migration çalıştırıldı: ' . implode(', ', $r['applied']) : $r['error'];
				$flashType = $r['success'] ? 'success' : 'danger';
			}
		}

		if (Tools::isSubmit('rvVendorAction')) {
			if (!hash_equals((string) $adminToken, (string) Tools::getValue('token'))) {
				$flash = 'Geçersiz istek';
				$flashType = 'danger';
			} elseif (Admin::isDemoMode()) {
				$flash = 'Demo modunda bu işlem yapılamaz.';
				$flashType = 'warning';
			} else {
				$idVendor = (int) Tools::getValue('id_vendor');
				$to = (string) Tools::getValue('to_status');
				$ok = RvVendor::setStatus($idVendor, $to, Admin::getId(), (string) Tools::getValue('reason'));
				$flash = $ok ? 'Satıcı durumu güncellendi.' : 'Durum güncellenemedi.';
				$flashType = $ok ? 'success' : 'danger';
			}
		}

		if (Tools::isSubmit('rvSaveSettings') && hash_equals((string) $adminToken, (string) Tools::getValue('token')) && !Admin::isDemoMode()) {
			Settings::set('RAFIMDA_VENDOR_AUTO_APPROVE', Tools::getValue('auto_approve') === '1' ? '1' : '0');
			$flash = 'Ayarlar kaydedildi.';
			$flashType = 'success';
		}

		$status = (string) Tools::getValue('status', '');
		$q = trim((string) Tools::getValue('q', ''));

		$smarty->assign([
			'pageTitle' => $this->title,
			'flash' => $flash,
			'flashType' => $flashType,
			'rvVendors' => RvVendor::adminList($status, $q, 100, 0),
			'rvCounts' => RvVendor::countByStatus(),
			'rvStatuses' => RvVendor::STATUSES,
			'rvFilterStatus' => $status,
			'rvQuery' => $q,
			'rvPending' => RvMigrator::pending(),
			'rvAutoApprove' => Settings::get('RAFIMDA_VENDOR_AUTO_APPROVE') === '1',
			'rvPanelUrl' => rtrim((string) Settings::get('DOMAIN'), '/') . '/satici-giris',
		]);
	}
}
