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
		'stock'        => ['Stok ve Depo', '▥', true, ''],
		'pricing'      => ['Fiyat Yönetimi', '₺', true, ''],
		'customers'    => ['Müşteriler', '☺', true, ''],
		'campaigns'    => ['Kampanyalar ve Vitrin', '✦', false, 'Vitrin modülü (rafimda-showcase) kurulu değil. Kurulduğunda ana sayfada öne çıkan/kampanyalı ürün yerleşimi talep edebilirsiniz.'],
		'reports'      => ['Raporlar', '▨', true, ''],
		'finance'      => ['Finans', '▣', true, ''],
		'invoices'     => ['Faturalar', '☰', false, 'Fatura entegrasyonu sonraki aşamada.'],
		'staff'        => ['Personel', '☻', true, ''],
		'subscription' => ['Abonelik', '★', true, ''],
		'store'        => ['Mağaza Yönetimi', '⚑', true, ''],
		'storefront'   => ['Mağaza Vitrini', '❖', true, ''],
		'questions'    => ['Soru-Cevap', '❓', true, ''],
		'reviews'      => ['Değerlendirmeler', '☆', true, ''],
		'notifications' => ['Bildirimler', '✉', true, ''],
		'social'       => ['Sosyal Medya', '◎', false, 'Sosyal medya modülü kurulu değil.'],
		'settings'     => ['Ayarlar', '⚙', true, ''],
		'support'      => ['Destek', '?', true, ''],
	];

	/** Onaylanmamış mağaza: başvuru durumu, eksik belge bildirimi, belge yükleme ve geçmiş. Yalnızca mağaza SAHİBİ belge yükleyebilir. */
	private static function applicationStatus(array $vendor): void
	{
		$app = RvApplication::forVendor((int) $vendor['id_vendor']);
		$uid = (int) Customer::getId();
		$isApplicant = $app && (int) $app['id_user'] === $uid;

		if ($app && Tools::getValue('a') === 'doc') {
			$doc = RvApplication::documentPath((int) Tools::getValue('id'), $uid, false);

			if (!$doc) {
				http_response_code(404);
				exit;
			}

			header('Content-Type: ' . $doc['mime']);
			header('Content-Disposition: attachment; filename="' . rawurlencode($doc['name']) . '"');
			header('X-Content-Type-Options: nosniff');
			readfile($doc['path']);
			exit;
		}

		if ($app && RvView::isPost() && RvView::post('action') === 'upload_doc') {
			Security::requireCsrf('front');
			$r = $isApplicant ? RvApplication::addDocument((int) $app['id_application'], $uid, (array) ($_FILES['document'] ?? []), RvView::post('doc_type'))
				: ['success' => false, 'message' => 'Belgeyi yalnızca başvuru sahibi yükleyebilir.'];
			RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
			RvView::redirect(RvView::panelUrl());
		}

		RvView::display('panel_status', [
			'rvTitle' => 'Başvuru Durumu',
			'vendor' => $vendor,
			'statusLabel' => RvVendor::STATUSES[$vendor['status']] ?? (string) $vendor['status'],
			'logoutUrl' => RvView::url('satici-cikis'),
			'app' => $app,
			'appStatusLabel' => $app ? (RvApplication::STATUSES[$app['status']] ?? $app['status']) : '',
			'appDocs' => $app ? RvApplication::docs((int) $app['id_application']) : [],
			'appHistory' => $app ? RvApplication::history((int) $app['id_application']) : [],
			'appStatuses' => RvApplication::STATUSES,
			'docTypes' => RvApplication::DOC_TYPES,
			'canUpload' => $isApplicant && $app['status'] !== 'approved',
			'flash' => RvView::takeFlash(),
			'panelUrl' => RvView::panelUrl(),
		]);
		exit;
	}

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
			self::applicationStatus($vendor);
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
			case 'stock':
			case 'pricing':
				[$tpl, $vars] = self::inventory($vendor, $section);
				break;
			case 'customers':
				[$tpl, $vars] = self::customers($vendor);
				break;
			case 'subscription':
				[$tpl, $vars] = self::subscription($vendor);
				break;
			case 'campaigns':
				[$tpl, $vars] = class_exists('RsShowcase', false) ? self::campaigns($vendor) : ['panel_planned', ['planned' => self::MENU[$section]]];
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
			case 'reports':
				[$tpl, $vars] = self::reports($vendor);
				break;
			case 'settings':
				[$tpl, $vars] = self::settings($vendor);
				break;
			case 'storefront':
				[$tpl, $vars] = self::storefront($vendor);
				break;
			case 'questions':
				[$tpl, $vars] = self::questions($vendor, $ctx);
				break;
			case 'reviews':
				[$tpl, $vars] = self::reviews($vendor);
				break;
			case 'notifications':
				[$tpl, $vars] = self::notifications($vendor);
				break;
			case 'support':
				[$tpl, $vars] = self::support($vendor, $ctx);
				break;
			case 'social':
				[$tpl, $vars] = class_exists('RsoPanel', false) ? RsoPanel::vendor($vendor, $ctx) : ['panel_planned', ['planned' => self::MENU[$section]]];
				break;
			case 'invoices':
				// Fatura modülü (rafimda-invoice) kuruluysa gerçek ekran; yoksa "planlandı" bilgisi
				[$tpl, $vars] = class_exists('RiPanel', false) ? RiPanel::vendor($vendor, $ctx) : ['panel_planned', ['planned' => self::MENU[$section]]];
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
				$slug = $key === 'xml' && class_exists('XhPanel', false) ? 'xml-merkezi' : ($key === 'suppliers' && class_exists('XhPanel', false) ? 'xml-merkezi?view=suppliers' : ($key === 'marketplaces' && class_exists('MhPanel', false) ? 'pazaryeri' : ''));
				$ready = $slug !== '' ? true : ($key === 'campaigns' && class_exists('RsShowcase', false) ? true : ($key === 'invoices' && class_exists('RiPanel', false) ? true : ($key === 'social' && class_exists('RsoPanel', false) ? true : $m[2])));

				if (!$ready) {
					continue; // henüz geliştirilmemiş bölümler menüde gösterilmez (ölü bağlantı yok)
				}

				$menu[] = ['key' => $key, 'label' => $m[0], 'icon' => $m[1], 'ready' => true, 'url' => $slug !== '' ? RvView::url($slug) : RvView::panelUrl($key)];
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

	/** @return array{0:string,1:array<string,mixed>} */
	private static function inventory(array $vendor, string $section): array
	{
		$idVendor = (int) $vendor['id_vendor'];
		$back = RvView::panelUrl($section);

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('products.edit');
			$action = RvView::post('action');
			$rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
			$r = null;

			if ($action === 'stock_save' && $section === 'stock') {
				$r = RvInventory::bulkSet($idVendor, array_map(static fn($x): array => ['stock' => is_array($x) ? ($x['stock'] ?? '') : ''], $rows));
			} elseif ($action === 'price_save' && $section === 'pricing') {
				$r = RvInventory::bulkSet($idVendor, array_map(static fn($x): array => ['price' => is_array($x) ? ($x['price'] ?? '') : ''], $rows));
			} elseif ($action === 'price_adjust' && $section === 'pricing') {
				$r = RvInventory::adjustPrices($idVendor, is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [], RvView::post('percent'));
			}

			if ($r === null) {
				RvView::flash('danger', 'Geçersiz işlem.');
			} else {
				RvView::flash($r['failed'] === 0 && $r['ok'] > 0 ? 'success' : ($r['ok'] > 0 ? 'warning' : 'danger'), $r['ok'] . ' ürün güncellendi' . ($r['failed'] > 0 ? ', ' . $r['failed'] . ' başarısız: ' . implode(' ', array_slice($r['errors'], 0, 3)) : '.') . ($r['ok'] === 0 && $r['failed'] === 0 ? ' (değişiklik yok)' : ''));
			}

			RvView::redirect($back);
		}

		$filter = (string) Tools::getValue('f', '');
		$filter = in_array($filter, ['out', 'low', 'inactive'], true) ? $filter : '';
		$q = trim((string) Tools::getValue('q', ''));
		$perPage = 25;
		$page = max(1, (int) Tools::getValue('page', 1));
		$list = RvInventory::listProducts($idVendor, $filter, $q, $perPage, ($page - 1) * $perPage);

		return [$section === 'stock' ? 'panel_stock' : 'panel_pricing', [
			'rows' => $list['rows'], 'total' => $list['total'], 'filter' => $filter, 'q' => $q, 'page' => $page,
			'pages' => max(1, (int) ceil($list['total'] / $perPage)), 'sum' => RvInventory::summary($idVendor), 'lowLimit' => RvPrefs::lowStock($idVendor),
			'sec' => $section,
		]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function campaigns(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('products.edit');

			if (RvView::post('action') === 'showcase_request') {
				$r = RsShowcase::request($idVendor, ['slot_code' => RvView::post('slot_code'), 'id_product' => RvView::post('id_product'), 'starts_at' => RvView::post('starts_at'), 'ends_at' => RvView::post('ends_at')]);
			} elseif (RvView::post('action') === 'showcase_withdraw') {
				$ok = RsShowcase::withdraw($idVendor, (int) RvView::post('id_item'));
				$r = ['success' => $ok, 'message' => $ok ? 'Talep geri çekildi.' : 'Kayıt bulunamadı.'];
			} else {
				$r = ['success' => false, 'message' => 'Geçersiz işlem.'];
			}

			RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
			RvView::redirect(RvView::panelUrl('campaigns'));
		}

		$products = DB::execute("SELECT p.id_product, p.product_name FROM products p JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE vp.id_vendor = ? AND vp.moderation = 'approved' AND p.active = 1 ORDER BY p.product_name ASC LIMIT 300", [$idVendor]) ?: [];

		return ['panel_campaigns', ['terms' => class_exists('RcCampaign', false) ? RcCampaign::showcaseTerms($idVendor) : null, 'slots' => array_values(array_filter(RsShowcase::slots(true), static fn(array $s): bool => $s['kind'] === 'product' && (int) $s['vendor_requestable'] === 1)), 'products' => $products, 'items' => RsShowcase::forVendor($idVendor)]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function subscription(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost() && class_exists('RcCampaign', false)) {
			Security::requireCsrf('front');
			self::deny('store.manage');

			if (RvView::post('action') === 'campaign_join') {
				$r = RcCampaign::enroll((int) RvView::post('id_campaign'), $idVendor, 'vendor');
			} else {
				$r = ['success' => false, 'message' => 'Geçersiz işlem.'];
			}

			RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
			RvView::redirect(RvView::panelUrl('subscription'));
		}

		$eff = RvPlans::effective($idVendor);

		return ['panel_subscription', ['eff' => $eff, 'usage' => RvPlans::usage($idVendor), 'plans' => RvPlans::all(true), 'camp' => class_exists('RcCampaign', false) ? RcCampaign::forVendor($idVendor) : null]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function customers(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];
		$q = trim((string) Tools::getValue('q', ''));
		$perPage = 25;
		$page = max(1, (int) Tools::getValue('page', 1));
		$list = RvCustomers::listFor($idVendor, $q, $perPage, ($page - 1) * $perPage);

		return ['panel_customers', ['rows' => $list['rows'], 'total' => $list['total'], 'q' => $q, 'page' => $page, 'pages' => max(1, (int) ceil($list['total'] / $perPage)), 'sum' => RvCustomers::summary($idVendor)]];
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
			'lowLimit' => RvPrefs::lowStock($id),
			'links' => self::quickLinks(),
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

			return ['panel_order_view', ['vo' => $vo, 'statuses' => RvOrders::STATUSES, 'listUrl' => RvView::panelUrl('orders'), 'canManage' => RvContext::can('orders.manage'), 'defaultCargo' => RvPrefs::get($idVendor)['default_cargo']]];
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

	/** Genel Bakış hızlı bağlantıları: yalnızca kullanıcının izinli olduğu ve hazır bölümlere. @return list<array{label:string,url:string}> */
	private static function quickLinks(): array
	{
		$out = [];

		if (RvContext::can('products.create')) {
			$out[] = ['label' => 'Yeni ürün ekle', 'url' => RvView::panelUrl('products', ['a' => 'new'])];
		}

		foreach (['orders' => 'Siparişleri gör', 'stock' => 'Stok güncelle', 'pricing' => 'Fiyat güncelle', 'finance' => 'Kazançlarım', 'reports' => 'Satış raporu', 'subscription' => 'Paketim ve kampanyalar'] as $k => $l) {
			if (RvContext::can(RvPerm::SECTION_PERMS[$k])) {
				$out[] = ['label' => $l, 'url' => RvView::panelUrl($k)];
			}
		}

		return $out;
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function reports(array $vendor): array
	{
		$days = (int) Tools::getValue('days', 30);
		$days = in_array($days, [7, 30, 90], true) ? $days : 30;
		$r = RvReports::summary((int) $vendor['id_vendor'], $days);
		$max = 0.0;

		foreach ($r['daily'] as $d) {
			$max = max($max, (float) $d['sales']);
		}

		return ['panel_reports', ['rep' => $r, 'maxDaily' => $max, 'days' => $days, 'statuses' => RvOrders::STATUSES]];
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private static function settings(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('store.manage');
			$res = RvPrefs::save($idVendor, ['low_stock' => RvView::post('low_stock'), 'default_cargo' => RvView::post('default_cargo')]);
			RvView::flash($res['success'] ? 'success' : 'danger', $res['message']);
			RvView::redirect(RvView::panelUrl('settings'));
		}

		return ['panel_settings', ['prefs' => RvPrefs::get($idVendor)]];
	}

	private static function storefront(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('store.manage');
			$kind = RvView::post('action');

			if (in_array($kind, ['logo', 'cover'], true)) {
				$r = RvStorefront::uploadImage($idVendor, $kind, (array) ($_FILES['image'] ?? []));
			} else {
				$ok = RvStorefront::saveProfile($idVendor, $_POST);
				$r = ['success' => $ok, 'message' => $ok ? 'Vitrin bilgileri kaydedildi.' : 'Kaydedilemedi.'];
			}

			RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
			RvView::redirect(RvView::panelUrl('storefront'));
		}

		return ['panel_storefront', ['profile' => RvStorefront::profile($idVendor), 'publicUrl' => RvStorefront::url($vendor), 'rating' => RvStorefront::rating($idVendor), 'followers' => RvStorefront::followers($idVendor)]];
	}

	private static function questions(array $vendor, array $ctx): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('questions.answer');
			$r = RvView::post('kind') === 'store'
				? RvQA::answerStore($idVendor, (int) $ctx['id_user'], (int) RvView::post('id'), RvView::post('answer'))
				: RvQA::answerProduct($idVendor, (int) $ctx['id_user'], (int) RvView::post('id'), RvView::post('answer'));
			RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
			RvView::redirect(RvView::panelUrl('questions', ['f' => (string) Tools::getValue('f', 'open')]));
		}

		$f = (string) Tools::getValue('f', 'open');

		return ['panel_questions', ['qaAvailable' => RvQA::available(), 'productQuestions' => RvQA::productQuestions($idVendor, $f), 'storeQuestions' => RvQA::storeQuestions($idVendor),
			'filter' => $f, 'storeStatuses' => RvQA::STORE_STATUSES, 'moderation' => RvQA::moderation(), 'canAnswer' => RvContext::can('questions.answer')]];
	}

	private static function reviews(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('reviews.reply');
			$r = RvReviews::reply($idVendor, RvView::post('kind') === 'store' ? 'store' : 'product', (int) RvView::post('id'), RvView::post('reply'));
			RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
			RvView::redirect(RvView::panelUrl('reviews'));
		}

		return ['panel_reviews', ['reviewsAvailable' => RvReviews::available(), 'reviews' => RvReviews::forVendor($idVendor), 'rating' => RvStorefront::rating($idVendor), 'canReply' => RvContext::can('reviews.reply')]];
	}

	private static function notifications(array $vendor): array
	{
		$idVendor = (int) $vendor['id_vendor'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			RvNotify::markReadForVendor($idVendor, (int) RvView::post('id'));
			RvView::redirect(RvView::panelUrl('notifications'));
		}

		return ['panel_notifications', ['items' => RvNotify::forVendor($idVendor, 100), 'types' => RvNotify::TYPES, 'channels' => RvNotify::channelStatus()]];
	}

	private static function support(array $vendor, array $ctx): array
	{
		$idVendor = (int) $vendor['id_vendor'];
		$idUser = (int) $ctx['id_user'];

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			self::deny('support.manage');
			$a = RvView::post('action');

			if ($a === 'create') {
				$r = RvTickets::create(['opener' => 'vendor', 'id_user' => $idUser, 'id_vendor' => $idVendor, 'category' => RvView::post('category'), 'subject' => RvView::post('subject'), 'body' => RvView::post('body')]);
				RvView::flash($r['success'] ? 'success' : 'danger', $r['message']);
				RvView::redirect($r['success'] ? RvView::panelUrl('support', ['t' => (int) $r['id_ticket']]) : RvView::panelUrl('support'));
			}

			$t = (int) RvView::post('id_ticket');
			$r = $a === 'close' ? ['success' => RvTickets::close($t, 'vendor', $idUser, $idVendor), 'message' => 'Talep kapatıldı.'] : RvTickets::reply($t, 'vendor', $idUser, RvView::post('body'), $idUser, $idVendor);
			RvView::flash($r['success'] ? 'success' : 'danger', $r['success'] ? $r['message'] : ($r['message'] ?? 'İşlem başarısız.'));
			RvView::redirect(RvView::panelUrl('support', ['t' => $t]));
		}

		$t = (int) Tools::getValue('t', 0);
		$ticket = $t > 0 ? RvTickets::get($t, 'vendor', $idUser, $idVendor) : null;

		return ['panel_support', ['tickets' => RvTickets::listFor('vendor', $idUser, $idVendor), 'ticket' => $ticket, 'statuses' => RvTickets::STATUSES, 'categories' => RvTickets::CATEGORIES, 'canManage' => RvContext::can('support.manage')]];
	}
}
