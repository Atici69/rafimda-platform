<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

foreach (['RvMigrator', 'RvPerm', 'RvAudit', 'RvVendor', 'RvContext', 'RvProducts', 'RvInventory', 'RvCustomers', 'RvPlans', 'RvLedger', 'RvOrders', 'RvCommission', 'RvView', 'RvAuthFlow', 'RvPrefs', 'RvReports', 'RvNotify', 'RvApplication', 'RvPrivacy', 'RvStorefront', 'RvQA', 'RvReviews', 'RvTickets', 'RvEngageAdmin', 'RvPanel'] as $rvClass) {
	require_once __DIR__ . '/lib/' . $rvClass . '.php';
}

/**
 * Rafımda çok satıcılı (multi-vendor) altyapı — Aşama 1.
 * Çekirdek (core/, container/, config/) dosyalarına dokunmaz; tüm sayfalar modül rotalarıyla çalışır.
 */
class RafimdaVendorModule extends ModuleBase
{
	public const VERSION = '0.4.0';

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
		'magaza' => 'front/store.php',
		'magaza-islem' => 'front/store_action.php',
		'degerlendir' => 'front/review.php',
		'hesap-merkezi' => 'front/account.php',
	];

	public array $displayHooks = [
		'product_detail' => 'Ürün sayfası: satıcı mağaza bilgisi, kargo/iade notu, mağaza bağlantısı',
	];

	public array $defaultDisplayHooks = ['product_detail'];

	public array $frontStylesheets = ['store.css'];

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

		// Çekirdek sipariş akışını ASLA bozmamak için dinleyiciler hataları yutar (RvOrders::safeHook).
		Module::registerHook('order.placed', static function (array $order): void {
			RvOrders::safeHook(static fn() => RvOrders::splitOrder($order));
		});
		Module::registerHook('order.updated', static function (array $order, $oldStatus = 0): void {
			RvOrders::safeHook(static fn() => RvOrders::onCoreOrderUpdated($order, (int) $oldStatus));
		});
	}

	public function renderDisplayHook(string $hook, array $context = []): ?string
	{
		if ($hook !== 'product_detail') {
			return null;
		}

		$idProduct = (int) ($context['id_product'] ?? ($context['product']['id_product'] ?? Tools::getValue('id_product', 0)));
		$vendor = $idProduct > 0 ? RvStorefront::vendorOfProduct($idProduct) : null;

		if (!$vendor) {
			return null; // Rafımda'nın kendi ürünü veya satıcısı olmayan ürün: satıcı kutusu gösterilmez
		}

		$html = $this->renderFrontTemplate('product_seller', [
			'sv' => $vendor, 'svProfile' => RvStorefront::profile((int) $vendor['id_vendor']), 'svRating' => RvStorefront::rating((int) $vendor['id_vendor']),
			'svUrl' => RvStorefront::url($vendor), 'svFollowers' => RvStorefront::followers((int) $vendor['id_vendor']),
		]);

		return $html !== '' ? $html : null;
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken;

		$flash = '';
		$flashType = 'info';
		$post = Tools::isSubmit('rvAction');
		$tokenOk = $post && hash_equals((string) $adminToken, (string) Tools::getValue('token'));
		$demo = Admin::isDemoMode();
		$adminId = Admin::getId();
		$tab = (string) Tools::getValue('tab', 'applications');

		if (!in_array($tab, ['applications', 'vendors', 'products', 'orders', 'finance', 'commission', 'plans', 'engage', 'settings'], true)) {
			$tab = 'applications';
		}

		// Başvuru belgesi indirme: yalnızca yönetici oturumunda (adminPage zaten yönetici doğrulamasından sonra çağrılır)
		if ($tab === 'applications' && (int) Tools::getValue('doc') > 0) {
			$doc = RvApplication::documentPath((int) Tools::getValue('doc'), 0, true);

			if (!$doc) {
				http_response_code(404);
				exit;
			}

			while (ob_get_level() > 0) {
				ob_end_clean();
			}

			header('Content-Type: ' . $doc['mime']);
			header('Content-Disposition: attachment; filename="' . rawurlencode($doc['name']) . '"');
			header('X-Content-Type-Options: nosniff');
			readfile($doc['path']);
			exit;
		}

		if ($post && !$tokenOk) {
			$flash = 'Geçersiz istek';
			$flashType = 'danger';
		} elseif ($post && $demo) {
			$flash = 'Demo modunda bu işlem yapılamaz.';
			$flashType = 'warning';
		} elseif ($post) {
			$a = (string) Tools::getValue('rvAction');
			$ok = false;
			$msg = 'İşlem başarısız.';

			switch ($a) {
				case 'migrate':
					$r = RvMigrator::run();
					$ok = $r['success'];
					$msg = $ok ? 'Migration çalıştırıldı: ' . implode(', ', $r['applied']) : $r['error'];
					break;
				case 'vendor_status':
					$ok = RvVendor::setStatus((int) Tools::getValue('id_vendor'), (string) Tools::getValue('to_status'), $adminId, (string) Tools::getValue('reason'));
					$msg = $ok ? 'Satıcı durumu güncellendi.' : 'Durum güncellenemedi.';
					if ($ok) {
						RvApplication::syncFromVendor((int) Tools::getValue('id_vendor'), (string) Tools::getValue('to_status'), $adminId, (string) Tools::getValue('reason'));
					}
					break;
				case 'eng_question':
				case 'eng_review':
				case 'eng_ticket_reply':
				case 'eng_ticket_close':
				case 'eng_settings':
					[$ok, $msg] = RvEngageAdmin::handle($a, $_POST, $adminId);
					break;
				case 'app_transition':
					$r = RvApplication::transition((int) Tools::getValue('id_application'), (string) Tools::getValue('to'), $adminId, (string) Tools::getValue('note'));
					$ok = $r['success'];
					$msg = $r['message'];
					break;
				case 'product_approve':
					$ok = RvProducts::approve((int) Tools::getValue('id_product'), $adminId);
					$msg = $ok ? 'Ürün onaylandı ve yayına alındı.' : 'Ürün bulunamadı.';
					break;
				case 'product_reject':
					$ok = RvProducts::reject((int) Tools::getValue('id_product'), (string) Tools::getValue('note'), $adminId);
					$msg = $ok ? 'Ürün reddedildi.' : 'Ürün bulunamadı.';
					break;
				case 'payout':
					$r = RvLedger::recordPayout((int) Tools::getValue('id_vendor'), (float) str_replace(',', '.', (string) Tools::getValue('amount')), (string) Tools::getValue('reference'), (string) Tools::getValue('note'), $adminId);
					$ok = $r['success'];
					$msg = $r['message'];
					break;
				case 'adjustment':
					$r = RvLedger::adjustment((int) Tools::getValue('id_vendor'), (float) str_replace(',', '.', (string) Tools::getValue('amount')), (string) Tools::getValue('note'), $adminId);
					$ok = $r['success'];
					$msg = $r['message'];
					break;
				case 'vendor_commission':
					$rate = (float) str_replace(',', '.', (string) Tools::getValue('commission_rate'));
					$ok = $rate >= 0 && $rate <= 100 && DB::update('rafimda_vendors', ['commission_rate' => $rate], 'id_vendor = :vid', ['vid' => (int) Tools::getValue('id_vendor')]) !== false;
					$msg = $ok ? 'Komisyon oranı güncellendi (yalnızca yeni siparişlere uygulanır).' : 'Geçersiz oran (0-100).';
					if ($ok) {
						RvAudit::log((int) Tools::getValue('id_vendor'), 'vendor.commission', 'vendor', (int) Tools::getValue('id_vendor'), ['rate' => $rate], $adminId);
					}
					break;
				case 'commission_rule':
					$r = RvCommission::saveRule($_POST, $adminId);
					$ok = $r['success'];
					$msg = $r['message'];
					break;
				case 'commission_rule_toggle':
					$ok = RvCommission::setActive((int) Tools::getValue('id_rule'), Tools::getValue('to') === '1', $adminId);
					$msg = $ok ? 'Kural durumu güncellendi (yalnızca yeni siparişlere uygulanır).' : 'Kural bulunamadı.';
					break;
				case 'plan_save':
					$r = RvPlans::save($_POST);
					$ok = $r['success'];
					$msg = $r['message'];
					break;
				case 'plan_assign':
					$r = RvPlans::assign((int) Tools::getValue('id_vendor'), (string) Tools::getValue('plan_code'), (string) Tools::getValue('expires'), $adminId, (string) Tools::getValue('note'));
					$ok = $r['success'];
					$msg = $r['message'];
					break;
				case 'settings':
					$c = (float) str_replace(',', '.', (string) Tools::getValue('default_commission'));
					$h = (int) Tools::getValue('hold_days');
					$ok = $c >= 0 && $c <= 100 && $h >= 0 && $h <= 90;
					if ($ok) {
						Settings::set('RAFIMDA_DEFAULT_COMMISSION', (string) $c);
						Settings::set('RAFIMDA_PAYOUT_HOLD_DAYS', (string) $h);
						Settings::set('RAFIMDA_PRODUCT_MODERATION', Tools::getValue('moderation') === '1' ? '1' : '0');
						Settings::set('RAFIMDA_VENDOR_AUTO_APPROVE', Tools::getValue('auto_approve') === '1' ? '1' : '0');
					}
					$msg = $ok ? 'Ayarlar kaydedildi.' : 'Geçersiz değer.';
					break;
			}

			$flash = $msg;
			$flashType = $ok ? 'success' : 'danger';
		}

		$commDays = (int) Tools::getValue('days', 30);
		$commDays = in_array($commDays, [7, 30, 90, 365], true) ? $commDays : 30;
		$status = (string) Tools::getValue('status', '');
		$q = trim((string) Tools::getValue('q', ''));
		$vendors = RvVendor::adminList($status, $q, 100, 0);
		$balances = [];

		if ($tab === 'finance') {
			foreach ($vendors as $v) {
				$balances[(int) $v['id_vendor']] = RvLedger::balances((int) $v['id_vendor']);
			}
		}

		$smarty->assign(RvApplication::adminVars($tab === 'applications', $_GET));
		$smarty->assign(RvEngageAdmin::vars($tab === 'engage', $_GET));
		$smarty->assign([
			'pageTitle' => $this->title,
			'flash' => $flash,
			'flashType' => $flashType,
			'rvTab' => $tab,
			'rvVendors' => $vendors,
			'rvCounts' => RvVendor::countByStatus(),
			'rvStatuses' => RvVendor::STATUSES,
			'rvFilterStatus' => $status,
			'rvQuery' => $q,
			'rvPlans' => $tab === 'plans' ? RvPlans::all() : [],
			'rvFreeMode' => RvPlans::freeMode(),
			'rvCommDays' => $commDays,
			'rvCommReport' => $tab === 'commission' ? RvCommission::platformReport($commDays) : null,
			'rvCommRules' => $tab === 'commission' ? RvCommission::rules() : [],
			'rvCommReady' => RvCommission::tableReady(),
			'rvCommSources' => RvCommission::SOURCES,
			'rvCategories' => $tab === 'commission' ? (DB::execute('SELECT id_category, category_name FROM categories ORDER BY category_name ASC LIMIT 1000') ?: []) : [],
			'rvPending' => RvMigrator::pending(),
			'rvModerationQueue' => $tab === 'products' ? RvProducts::moderationQueue((string) Tools::getValue('mod', 'pending')) : [],
			'rvModState' => (string) Tools::getValue('mod', 'pending'),
			'rvVendorOrders' => $tab === 'orders' ? RvOrders::adminList($status) : [],
			'rvOrderStatuses' => RvOrders::STATUSES,
			'rvTotals' => RvOrders::platformTotals(),
			'rvBalances' => $balances,
			'rvPayouts' => $tab === 'finance' ? RvLedger::payouts(0, 30) : [],
			'rvAutoApprove' => Settings::get('RAFIMDA_VENDOR_AUTO_APPROVE') === '1',
			'rvModeration' => RvProducts::moderationEnabled(),
			'rvDefaultCommission' => RvOrders::defaultCommission(),
			'rvHoldDays' => RvOrders::holdDays(),
			'rvPanelUrl' => rtrim((string) Settings::get('DOMAIN'), '/') . '/satici-giris',
		]);
	}
}
