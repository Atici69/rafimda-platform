<?php

if (!defined('IN_SCRIPT') && !defined('IN_ADMIN')) {
	exit;
}

require_once dirname(__DIR__, 2) . '/core/ModuleBase.php';

foreach (['RiCrypt', 'adapters/RiAdapter', 'adapters/RiTestAdapter', 'adapters/RiQnbAdapter', 'RiPdf', 'RiInvoice', 'RiPanel'] as $rf) {
	require_once __DIR__ . '/lib/' . $rf . '.php';
}

/**
 * Rafımda Fatura ve E-Belge Yönetimi. Pazaryeri modeline göre satıcı / Rafımda faturalarını ayırır; entegratör bağımsız bağdaştırıcı yapısı.
 * CANLI GÖNDERİM VARSAYILAN KAPALI. Yalnızca test bağdaştırıcısı çalışır; gerçek entegratör sözleşme + test hesabı + yazılı onay ister.
 */
class RafimdaInvoiceModule extends ModuleBase
{
	public const VERSION = '0.1.0';

	public string $name = 'rafimda-invoice';
	public string $title = 'Fatura ve E-Belge (Rafımda)';
	public string $version = self::VERSION;
	public string $description = 'e-Fatura / e-Arşiv / manuel fatura altyapısı: satıcı ve Rafımda faturaları ayrı, mükerrer engeli, entegratör bağdaştırıcıları (canlı gönderim kapalı)';
	public string $author = 'Rafımda';

	public array $routes = ['fatura-pdf' => 'front/pdf.php'];

	public function install(): bool
	{
		if (!$this->runSqlFile('install.sql')) {
			return false;
		}

		foreach (['TRIGGER' => 'manual', 'LIVE' => '0', 'AUTO_SEND' => '0'] as $k => $v) {
			if ((string) Settings::get('RAFIMDA_INVOICE_' . $k) === '') {
				Settings::set('RAFIMDA_INVOICE_' . $k, $v);
			}
		}

		if (!RiInvoice::account('platform')) {
			RiInvoice::saveAccount('platform', 0, ['integrator' => 'test', 'environment' => 'test']);
		}

		return true;
	}

	public function uninstall(): bool
	{
		return $this->runSqlFile('uninstall.sql'); // faturalar yasal saklama süresine tabidir; silinmez
	}

	public function boot(): void
	{
		$this->registerAdminMenuLink('Fatura ve E-Belge', 'sales', 64);
		Module::registerHook('order.placed', static fn(array $o) => RiInvoice::onOrderEvent($o));
		Module::registerHook('order.updated', static fn(array $o, $old = 0) => RiInvoice::onOrderEvent($o));
	}

	public function adminPage(): void
	{
		global $smarty, $adminToken;

		if ((int) Tools::getValue('pdf', 0) > 0) {
			RiPanel::stream(RiInvoice::pdfFor((int) Tools::getValue('pdf'), 'admin'), 'fatura-' . (int) Tools::getValue('pdf') . '.pdf');
		}

		$flash = null;

		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
			if (!hash_equals((string) $adminToken, (string) ($_POST['token'] ?? ''))) {
				$flash = ['type' => 'danger', 'message' => 'Geçersiz istek.'];
			} elseif (Admin::isDemoMode()) {
				$flash = ['type' => 'warning', 'message' => 'Demo modunda bu işlem yapılamaz.'];
			} else {
				$flash = self::handlePost($_POST);
			}
		}

		$smarty->assign(['pageTitle' => $this->title, 'riFlash' => $flash, 'riToken' => $adminToken] + self::adminVars($_GET));
	}

	public static function adminVars(array $get): array
	{
		$tab = in_array($get['tab'] ?? 'invoices', ['invoices', 'prepare', 'integrator', 'settings', 'errors'], true) ? (string) ($get['tab'] ?? 'invoices') : 'invoices';
		$id = (int) ($get['id'] ?? 0);
		$have = array_flip(array_map(static fn($r) => (int) $r['id_order'], DB::execute("SELECT DISTINCT id_order FROM ri_invoices WHERE id_order > 0") ?: []));

		return [
			'riTab' => $tab,
			'riList' => $tab === 'invoices' ? RiInvoice::adminList(['status' => (string) ($get['status'] ?? ''), 'kind' => (string) ($get['kind'] ?? ''), 'order' => (int) ($get['order'] ?? 0)]) : [],
			'riOne' => $id > 0 ? RiInvoice::get($id) : null,
			'riOneLines' => $id > 0 ? (json_decode((string) (RiInvoice::get($id)['lines'] ?? '[]'), true) ?: []) : [],
			'riOneEvents' => $id > 0 ? RiInvoice::events($id) : [],
			'riOrders' => $tab === 'prepare' ? array_values(array_filter(DB::execute('SELECT id_order, reference, status, total, customer_name, date_add FROM orders WHERE status NOT IN (5,6) ORDER BY id_order DESC LIMIT 100') ?: [], static fn($o) => !isset($have[(int) $o['id_order']]))) : [],
			'riAccount' => RiInvoice::accountView(RiInvoice::account('platform')),
			'riAdapters' => array_map(static fn($a) => ['label' => $a->label(), 'ready' => $a->isImplemented()], RiInvoice::adapters()),
			'riSettings' => ['trigger' => RiInvoice::setting('TRIGGER', 'manual'), 'live' => RiInvoice::liveEnabled(), 'auto_send' => RiInvoice::setting('AUTO_SEND') === '1',
				'default_vat' => RiInvoice::setting('DEFAULT_VAT'), 'service_vat' => RiInvoice::setting('SERVICE_VAT')],
			'riErrors' => $tab === 'errors' ? RiInvoice::events(0, true) : [],
			'riStatuses' => RiInvoice::STATUSES, 'riKinds' => RiInvoice::KINDS, 'riDocTypes' => RiInvoice::DOC_TYPES, 'riTriggers' => RiInvoice::TRIGGERS,
			'riCounts' => array_column(DB::execute('SELECT status, COUNT(*) AS n FROM ri_invoices GROUP BY status') ?: [], 'n', 'status'),
			'riPeriod' => date('Y-m', strtotime('first day of last month')),
		];
	}

	/** @return array{type:string,message:string} */
	public static function handlePost(array $p): array
	{
		$id = (int) ($p['id_invoice'] ?? 0);
		$r = match ((string) ($p['riAction'] ?? '')) {
			'prepare' => RiInvoice::prepareForOrder((int) ($p['id_order'] ?? 0), 'admin'),
			'service' => RiInvoice::prepareServiceInvoices((string) ($p['period'] ?? ''), 'admin'),
			'send' => RiInvoice::send($id, 'admin'),
			'doctype' => ['success' => RiInvoice::setDocType($id, (string) ($p['doc_type'] ?? '')), 'message' => 'Belge türü güncellendi.'],
			'cancel' => RiInvoice::cancel($id, (string) ($p['reason'] ?? ''), 'admin'),
			'return' => RiInvoice::registerReturn($id, (string) ($p['note'] ?? ''), 'admin'),
			'manual' => RiInvoice::createManual('platform', 0, $p, 'admin'),
			'account' => RiInvoice::saveAccount('platform', 0, $p),
			'test' => RiInvoice::testAccount('platform'),
			'settings' => self::saveSettings($p),
			default => ['success' => false, 'message' => 'Bilinmeyen işlem.'],
		};

		return ['type' => $r['success'] ? 'success' : 'danger', 'message' => $r['message']];
	}

	private static function saveSettings(array $p): array
	{
		$trigger = isset(RiInvoice::TRIGGERS[$p['trigger'] ?? '']) ? (string) $p['trigger'] : 'manual';
		Settings::set('RAFIMDA_INVOICE_TRIGGER', $trigger);
		Settings::set('RAFIMDA_INVOICE_AUTO_SEND', ($p['auto_send'] ?? '') === '1' ? '1' : '0');

		foreach (['DEFAULT_VAT' => 'default_vat', 'SERVICE_VAT' => 'service_vat'] as $k => $f) {
			$v = trim(str_replace(',', '.', (string) ($p[$f] ?? '')));
			Settings::set('RAFIMDA_INVOICE_' . $k, is_numeric($v) && (float) $v > 0 && (float) $v < 100 ? $v : '');
		}

		// Canlı gönderim yalnızca onay ifadesiyle açılır (işletme sahibinin yazılı onayı + mali müşavir doğrulaması sonrası)
		if (($p['live'] ?? '') === '1') {
			if (trim((string) ($p['live_confirm'] ?? '')) !== 'CANLI FATURA GONDERIMINI ACIYORUM') {
				return ['success' => false, 'message' => 'Diğer ayarlar kaydedildi; canlı gönderim için onay ifadesi eksik veya hatalı.'];
			}

			Settings::set('RAFIMDA_INVOICE_LIVE', '1');
			RiInvoice::event(0, 'live_enabled', 'Canlı fatura gönderimi açıldı', false, 'admin');
		} else {
			Settings::set('RAFIMDA_INVOICE_LIVE', '0');
		}

		return ['success' => true, 'message' => 'Fatura ayarları kaydedildi.'];
	}
}
