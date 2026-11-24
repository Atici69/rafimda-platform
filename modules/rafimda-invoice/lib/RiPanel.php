<?php

/** Satıcı paneli "Faturalarım" bölümü ve müşteri "Faturalarım" verisi. Satıcı yalnızca kendi faturalarını görür/yönetir. */
final class RiPanel
{
	/** @return array{0:string,1:array<string,mixed>} */
	public static function vendor(array $vendor, array $ctx): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (Tools::getValue('a') === 'pdf') {
			$path = RiInvoice::pdfFor((int) Tools::getValue('id'), 'vendor', 0, $idVendor);
			self::stream($path, 'fatura-' . (int) Tools::getValue('id') . '.pdf');
		}

		if (RvView::isPost()) {
			Security::requireCsrf('front');

			if (!RvContext::can('invoices.manage')) {
				RvView::flash('danger', 'Bu işlem için yetkiniz yok.');
				RvView::redirect(RvView::panelUrl('invoices'));
			}

			$id = (int) RvView::post('id_invoice');
			$r = match (RvView::post('action')) {
				'account' => RiInvoice::saveAccount('vendor', $idVendor, $_POST),
				'test' => RiInvoice::testAccount('vendor', $idVendor),
				'prepare' => self::prepareOwn($idVendor, (int) RvView::post('id_order')),
				'send' => RiInvoice::send($id, 'vendor', $idVendor),
				'doctype' => ['success' => RiInvoice::setDocType($id, RvView::post('doc_type'), $idVendor), 'message' => 'Belge türü güncellendi.'],
				'cancel' => RiInvoice::cancel($id, RvView::post('reason'), 'vendor', $idVendor),
				'manual' => RiInvoice::createManual('vendor', $idVendor, $_POST, 'vendor'),
				default => ['success' => false, 'message' => 'Bilinmeyen işlem.'],
			};
			RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
			RvView::redirect(RvView::panelUrl('invoices'));
		}

		$have = array_flip(array_map(static fn($r) => (int) $r['id_order'], DB::execute("SELECT id_order FROM ri_invoices WHERE id_vendor = ? AND kind = 'sale'", [$idVendor]) ?: []));
		$pending = array_values(array_filter(DB::execute("SELECT vo.id_order, vo.status, vo.subtotal, o.reference FROM rafimda_vendor_orders vo INNER JOIN orders o ON o.id_order = vo.id_order
			WHERE vo.id_vendor = ? AND vo.status IN ('shipped','delivered') ORDER BY vo.id_order DESC LIMIT 100", [$idVendor]) ?: [], static fn($r) => !isset($have[(int) $r['id_order']])));

		return ['panel_invoices', [
			'riInvoices' => RiInvoice::forVendor($idVendor),
			'riAccount' => RiInvoice::accountView(RiInvoice::account('vendor', $idVendor)),
			'riAdapters' => array_map(static fn($a) => ['label' => $a->label(), 'ready' => $a->isImplemented()], RiInvoice::adapters()),
			'riPending' => $pending,
			'riStatuses' => RiInvoice::STATUSES, 'riKinds' => RiInvoice::KINDS, 'riDocTypes' => RiInvoice::DOC_TYPES,
			'riCanManage' => RvContext::can('invoices.manage'),
			'riLive' => RiInvoice::liveEnabled(),
			'riTax' => ['legal_name' => (string) $vendor['legal_name'], 'tax_office' => (string) $vendor['tax_office'], 'tax_number' => (string) $vendor['tax_number']],
		]];
	}

	private static function prepareOwn(int $idVendor, int $idOrder): array
	{
		if (!DB::getRowSafe('rafimda_vendor_orders', 'id_order = ? AND id_vendor = ?', [$idOrder, $idVendor])) {
			return ['success' => false, 'message' => 'Sipariş bulunamadı.'];
		}

		return RiInvoice::prepareForOrder($idOrder, 'vendor', $idVendor);
	}

	public static function stream(?string $path, string $name): void
	{
		if ($path === null) {
			http_response_code(404);
			exit;
		}

		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		header('Content-Type: application/pdf');
		header('Content-Disposition: inline; filename="' . $name . '"');
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');
		readfile($path);
		exit;
	}
}
