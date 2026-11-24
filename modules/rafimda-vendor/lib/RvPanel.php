<?php

/**
 * Satıcı paneli denetleyicisi. Her bölüm için: oturum → mağaza durumu → izin → (POST: CSRF + sahiplik) → görünüm.
 */
class RvPanel
{
	/** key => [etiket, simge, hazır mı, planlanan aşama notu] */
	public const MENU = [
		'dashboard'    => ['Genel Bakış', '◧', true, ''],
		'products'     => ['Ürün Yönetimi', '▦', true, ''],
		'xml'          => ['XML Merkezi', '⇄', false, 'Aşama 7: XMLReader tabanlı çoklu kaynak, alan eşleme, kuyruk ve geçmiş.'],
		'suppliers'    => ['Tedarikçiler', '⚙', false, 'XML Merkezi ile birlikte gelecek: tedarikçi kartları ve besleme bağları.'],
		'orders'       => ['Siparişler', '▤', true, ''],
		'marketplaces' => ['Pazaryerleri', '⌂', false, 'Aşama 9: Trendyol, Hepsiburada, N11 hesap yönetimi (mevcut core/marketplace kodu satıcı bazlı hale getirilecek).'],
		'stock'        => ['Stok ve Depo', '▥', false, 'Hızlı stok güncelleme şu an Ürün Yönetimi ekranında; depo/çoklu konum sonraki aşamada.'],
		'pricing'      => ['Fiyat Yönetimi', '₺', false, 'Hızlı fiyat güncelleme şu an Ürün Yönetimi ekranında; kural tabanlı fiyatlama sonraki aşamada.'],
		'customers'    => ['Müşteriler', '☺', false, 'Satıcıya özel müşteri görünümü sonraki aşamada.'],
		'campaigns'    => ['Kampanyalar', '✦', false, 'Satıcı kampanyaları sonraki aşamada.'],
		'reports'      => ['Raporlar', '▨', false, 'Satış ve stok raporları sonraki aşamada.'],
		'finance'      => ['Finans', '₿', true, ''],
		'invoices'     => ['Faturalar', '☰', false, 'Fatura entegrasyonu sonraki aşamada.'],
		'staff'        => ['Personel', '☻', true, ''],
		'subscription' => ['Abonelik', '★', false, 'Aşama 10: paket ve abonelik sistemi.'],
		'store'        => ['Mağaza Ayarları', '⚑', true, ''],
		'support'      => ['Destek', '?', false, 'Destek talepleri sonraki aşamada.'],
	];

	public static function handle(): void
	{
		App::sendNoStoreHeaders();

		if (!Customer::isLoggedIn()) {
			RvView::redirect(RvView::url('satici-giris'));
		}

		$ctx = RvContext::current();

		if (!$ctx) {
			RvView::redirect(RvView::url('satici-kayit'));
		}

		if (RvView::isPost() && RvView::post('action') === 'switch_vendor') {
			Security::requireCsrf('front');
			RvContext::switchVendor((int) RvView::post('id_vendor'));
			RvView::redirect(RvView::panelUrl());
		}

		$vendor = $ctx['vendor'];

		if (($vendor['status'] ?? '') !== RvVendor::STATUS_ACTIVE) {
			RvView::display('panel_status', [
				'rvTitle' => 'Mağaza Durumu',
				'vendor' => $vendor,
				'statusLabel' => RvVendor::STATUSES[$vendor['status']] ?? (string) $vendor['status'],
				'logoutUrl' => RvView::url('satici-cikis'),
			]);
			exit;
		}

		$section = (string) Tools::getValue('s', 'dashboard');

		if (!isset(self::MENU[$section])) {
			$section = 'dashboard';
		}

		if (!RvContext::can(RvPerm::SECTION_PERMS[$section])) {
			http_response_code(403);
			self::render($ctx, $section, 'panel_denied', ['denied' => true]);
		}

		switch ($section) {
			case 'dashboard':
				[$tpl, $vars] = self::dashboard($vendor);
				break;
			case 'products':
				[$tpl, $vars] = self::products($vendor);
				break;
			case 'orders':
				[$tpl, $vars] = self::orders($vendor);
				break;
			case 'finance':
				[$tpl, $vars] = self::finance($vendor);
				break;
			case 'staff':
				[$tpl, $vars] = self::staff($vendor, $ctx);
				break;
			case 'store':
				[$tpl, $vars] = self::store($vendor);
				break;
			default:
				[$tpl, $vars] = ['panel_planned', ['planned' => self::MENU[$section]]];
		}

		self::render($ctx, $section, $tpl, $vars);
	}

	/** @param array<string,mixed> $vars */
	private static function render(array $ctx, string $section, string $tpl, array $vars): void
	{
		$menu = [];

		foreach (self::MENU as $key => $m) {
			if (RvContext::can(RvPerm::SECTION_PERMS[$key])) {
				// XML Hub / Pazaryeri Hub modülleri kuruluysa kendi sayfalarına bağlanır (Pazaryeri Hub'da gerçek API yok; sayfa bunu belirtir)
				$slug = $key === 'xml' && class_exists('XhPanel', false) ? 'xml-merkezi' : ($key === 'marketplaces' && class_exists('MhPanel', false) ? 'pazaryeri' : '');
				$menu[] = ['key' => $key, 'label' => $m[0], 'icon' => $m[1], 'ready' => $slug !== '' ? true : $m[2], 'url' => $slug !== '' ? RvView::url($slug) : RvView::panelUrl($key)];
			}
		}

		RvView::display('panel_layout', $vars + [
			'rvTitle' => self::MENU[$section][0] . ' · ' . $ctx['vendor']['store_name'],
			'pageTitle' => self::MENU[$section][0],
			'section' => $section,
			'menu' => $menu,
			'vendor' => $ctx['vendor'],
			'roleLabel' => RvPerm::ROLES[$ctx['role']] ?? $ctx['role'],
			'memberships' => RvContext::memberships($ctx['id_user']),
			'flash' => RvView::takeFlash(),
			'logoutUrl' => RvView::url('satici-cikis'),
			'storefrontUrl' => RvView::url(''),
			'contentFile' => 'file:' . RvView::tplDir() . $tpl . '.tpl',
			'can' => [
				'create' => RvContext::can('products.create'),
				'edit' => RvContext::can('products.edit'),
				'delete' => RvContext::can('products.delete'),
				'staff' => RvContext::can('staff.manage'),
				'store' => RvContext::can('store.manage'),
			],
		]);
		exit;
	}

	private static function deny(string $perm): void
	{
		if (!RvContext::can($perm)) {
			RvView::flash('danger', 'Bu işlem için yetkiniz yok.');
			RvView::redirect(RvView::panelUrl());
		}
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function dashboard(array $vendor): array
	{
		$id = (int) $vendor['id_vendor'];

		return ['panel_dashboard', [
			'stats' => RvProducts::stats($id),
			'balances' => RvLedger::balances($id),
			'recentOrders' => RvOrders::listFor($id, '', 5),
			'audit' => RvAudit::recent($id, 8),
		]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function products(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];
		$back = RvView::panelUrl('products');

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			$action = RvView::post('action');
			$idProduct = (int) RvView::post('id_product');

			switch ($action) {
				case 'create':
					self::deny('products.create');
					$res = RvProducts::create($idVendor, $_POST);

					if (!empty($res['success'])) {
						$up = RvProducts::uploadImages($idVendor, (int) $res['id'], $_FILES['images'] ?? []);
						RvView::flash($up['errors'] === [] ? 'success' : 'warning', $res['message'] . ($up['errors'] ? ' Görsel uyarıları: ' . implode(' ', $up['errors']) : ''));
						RvView::redirect(RvView::panelUrl('products', ['a' => 'edit', 'id' => $res['id']]));
					}

					return self::productForm($vendor, null, $_POST, (array) ($res['errors'] ?? []), (string) $res['message']);

				case 'update':
					self::deny('products.edit');
					$res = RvProducts::update($idVendor, $idProduct, $_POST);

					if (!empty($res['success'])) {
						$up = RvProducts::uploadImages($idVendor, $idProduct, $_FILES['images'] ?? []);
						RvView::flash($up['errors'] === [] ? 'success' : 'warning', $res['message'] . ($up['errors'] ? ' Görsel uyarıları: ' . implode(' ', $up['errors']) : ''));
						RvView::redirect(RvView::panelUrl('products', ['a' => 'edit', 'id' => $idProduct]));
					}

					return self::productForm($vendor, RvProducts::getOwned($idVendor, $idProduct), $_POST, (array) ($res['errors'] ?? []), (string) $res['message']);

				case 'delete':
					self::deny('products.delete');
					$res = RvProducts::delete($idVendor, $idProduct);
					RvView::flash(!empty($res['success']) ? 'success' : 'danger', $res['message']);
					RvView::redirect($back);
					break;

				case 'quick':
					self::deny('products.edit');
					$res = RvProducts::quickUpdate($idVendor, $idProduct, $_POST['price'] ?? 0, $_POST['stock'] ?? 0);
					RvView::flash(!empty($res['success']) ? 'success' : 'danger', $res['message']);
					RvView::redirect($back);
					break;

				case 'bulk':
					$bulk = RvView::post('bulk_action');
					self::deny($bulk === 'delete' ? 'products.delete' : 'products.edit');
					$ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
					$res = RvProducts::bulk($idVendor, $ids, $bulk);
					RvView::flash(!empty($res['success']) ? 'success' : 'danger', $res['message']);
					RvView::redirect($back);
					break;

				case 'import_csv':
					self::deny('products.create');
					$f = $_FILES['csv'] ?? null;
					$ext = $f ? strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) : '';

					if (!$f || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) || !in_array($ext, ['csv', 'txt'], true)) {
						RvView::flash('danger', 'Geçerli bir .csv dosyası seçin.');
						RvView::redirect(RvView::panelUrl('products', ['a' => 'import']));
					}

					$r = RvProducts::importCsv($idVendor, (string) $f['tmp_name'], (string) $f['name']);
					$msg = $r['message'] . ($r['errors'] ? ' ' . implode(' | ', array_slice($r['errors'], 0, 8)) : '');
					RvView::flash(!$r['success'] ? 'danger' : ($r['failed'] > 0 ? 'warning' : 'success'), $msg);
					RvView::redirect(RvView::panelUrl('products', ['a' => 'import']));
					break;

				case 'delete_image':
				case 'set_cover':
					self::deny('products.edit');
					$idImage = (int) RvView::post('id_image');
					$res = $action === 'delete_image' ? RvProducts::deleteImage($idVendor, $idImage) : RvProducts::setCover($idVendor, $idImage);
					RvView::flash(!empty($res['success']) ? 'success' : 'danger', (string) ($res['message'] ?? ''));
					RvView::redirect(RvView::panelUrl('products', ['a' => 'edit', 'id' => $idProduct]));
					break;
			}

			RvView::redirect($back);
		}

		$a = (string) Tools::getValue('a', 'list');

		if ($a === 'template') {
			self::deny('products.create');
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="rafimda-urun-sablonu.csv"');
			echo RvProducts::csvTemplate();
			exit;
		}

		if ($a === 'import') {
			self::deny('products.create');

			return ['panel_import', [
				'templateUrl' => RvView::panelUrl('products', ['a' => 'template']),
				'listUrl' => RvView::panelUrl('products'),
				'maxRows' => RvProducts::CSV_MAX_ROWS,
				'categories' => Category::getProductSelectOptions(),
				'brands' => DB::execute('SELECT id_brand, brand_name FROM brands WHERE active = 1 ORDER BY brand_name ASC') ?: [],
				'history' => DB::execute('SELECT * FROM rafimda_import_log WHERE id_vendor = ? ORDER BY id_import DESC LIMIT 10', [$idVendor]) ?: [],
			]];
		}

		if ($a === 'new') {
			self::deny('products.create');

			return self::productForm($vendor, null, [], [], '');
		}

		if ($a === 'edit') {
			$p = RvProducts::getOwned($idVendor, (int) Tools::getValue('id'));

			if (!$p) {
				http_response_code(404);
				RvView::flash('danger', 'Ürün bulunamadı.');
				RvView::redirect($back);
			}

			return self::productForm($vendor, $p, [], [], '');
		}

		$page = max(1, (int) Tools::getValue('page', 1));
		$per = 20;
		$q = trim((string) Tools::getValue('q', ''));
		$state = (string) Tools::getValue('state', '');
		$list = RvProducts::listFor($idVendor, $q, $state, $per, ($page - 1) * $per);

		return ['panel_products', [
			'products' => $list['rows'],
			'total' => $list['total'],
			'page' => $page,
			'pages' => max(1, (int) ceil($list['total'] / $per)),
			'q' => $q,
			'state' => $state,
			'newUrl' => RvView::panelUrl('products', ['a' => 'new']),
			'listUrl' => RvView::panelUrl('products'),
		]];
	}

	/**
	 * @param array<string,mixed>|null $product
	 * @param array<string,mixed> $posted
	 * @param array<string,string> $errors
	 * @return array{0:string,1:array<string,mixed>}
	 */
	private static function productForm(array $vendor, ?array $product, array $posted, array $errors, string $message): array
	{
		$categories = Category::getProductSelectOptions();
		$brands = DB::execute('SELECT id_brand, brand_name FROM brands WHERE active = 1 ORDER BY brand_name ASC') ?: [];
		$form = $product ?? [];

		if ($posted !== []) {
			$form = array_merge($form, array_map(static fn($v) => is_scalar($v) ? $v : '', array_diff_key($posted, ['variations' => 1])));
			$form['active'] = !empty($posted['active']) ? 1 : 0;
		}

		if (!$product && $posted === []) {
			$form = ['active' => 1, 'vat' => Tax::getDefaultRate(), 'stock' => 0];
		}

		return ['panel_product_form', [
			'form' => $form,
			'isEdit' => $product !== null,
			'idProduct' => (int) ($product['id_product'] ?? 0),
			'images' => $product['images'] ?? [],
			'variations' => $product['variations'] ?? [],
			'categories' => $categories,
			'brands' => $brands,
			'errors' => $errors,
			'formMessage' => $message,
			'listUrl' => RvView::panelUrl('products'),
		]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function orders(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('orders.manage');
			$id = (int) RvView::post('id_vendor_order');
			$res = RvOrders::updateFulfillment($idVendor, $id, RvView::post('status'), RvView::post('cargo_company'), RvView::post('tracking_number'));
			RvView::flash(!empty($res['success']) ? 'success' : 'danger', $res['message']);
			RvView::redirect(RvView::panelUrl('orders', ['a' => 'view', 'id' => $id]));
		}

		if ((string) Tools::getValue('a') === 'view') {
			$vo = RvOrders::getFor($idVendor, (int) Tools::getValue('id'));

			if (!$vo) {
				RvView::flash('danger', 'Sipariş bulunamadı.');
				RvView::redirect(RvView::panelUrl('orders'));
			}

			return ['panel_order_view', ['vo' => $vo, 'statuses' => RvOrders::STATUSES, 'listUrl' => RvView::panelUrl('orders'), 'canManage' => RvContext::can('orders.manage')]];
		}

		$page = max(1, (int) Tools::getValue('page', 1));
		$status = (string) Tools::getValue('state', '');

		return ['panel_orders', [
			'orders' => RvOrders::listFor($idVendor, $status, 30, ($page - 1) * 30),
			'statuses' => RvOrders::STATUSES,
			'state' => $status,
			'page' => $page,
		]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function finance(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];
		$page = max(1, (int) Tools::getValue('page', 1));

		return ['panel_finance', [
			'balances' => RvLedger::balances($idVendor),
			'entries' => RvLedger::entries($idVendor, 30, ($page - 1) * 30),
			'payouts' => RvLedger::payouts($idVendor, 10),
			'types' => RvLedger::TYPES,
			'page' => $page,
			'commission' => RvOrders::commissionRateFor($vendor),
			'holdDays' => RvOrders::holdDays(),
		]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function staff(array $vendor, array $ctx): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('staff.manage');
			$action = RvView::post('action');

			if ($action === 'add_staff') {
				$res = RvVendor::addStaffByEmail($idVendor, RvView::post('email'), RvView::post('role'));
			} elseif ($action === 'update_staff') {
				$res = RvVendor::updateStaff($idVendor, (int) RvView::post('id_staff'), RvView::post('role'), RvView::post('status'), (int) $ctx['id_user']);
			} else {
				$res = ['success' => false, 'message' => 'Geçersiz işlem.'];
			}

			RvView::flash(!empty($res['success']) ? 'success' : 'danger', $res['message']);
			RvView::redirect(RvView::panelUrl('staff'));
		}

		return ['panel_staff', [
			'staff' => RvVendor::staffList($idVendor),
			'roles' => array_diff_key(RvPerm::ROLES, ['owner' => 1]),
			'allRoles' => RvPerm::ROLES,
			'myUserId' => (int) $ctx['id_user'],
		]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function store(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];
		$errors = [];
		$form = $vendor;

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('store.manage');
			$in = [];

			foreach (['store_name', 'legal_name', 'tax_office', 'tax_number', 'phone', 'email', 'city', 'description'] as $k) {
				$in[$k] = RvView::post($k);
			}

			$v = RvVendor::validateStore($in);

			if ($v['ok'] && RvVendor::updateStore($idVendor, $v['clean'])) {
				RvView::flash('success', 'Mağaza bilgileri güncellendi.');
				RvView::redirect(RvView::panelUrl('store'));
			}

			$errors = $v['errors'] ?: ['store_name' => 'Kaydedilemedi.'];
			$form = array_merge($vendor, $in);
		}

		return ['panel_store', ['form' => $form, 'errors' => $errors]];
	}
}
