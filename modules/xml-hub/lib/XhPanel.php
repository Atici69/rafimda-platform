<?php

/**
 * XML Hub ekran denetleyicisi. Satıcı (front) ve yönetici (admin) aynı mantığı kullanır; fark yalnızca kapsamdır:
 *   $vendor > 0  → yalnızca o satıcının kayıtları, ürünleri satıcıya atanır
 *   $vendor = 0  → platform bağlantıları (yönetici), tüm kayıtlar görünür/yönetilir
 * CSRF ve oturum denetimi çağıran katmanda yapılır; burada HER işlem kapsam (id_vendor) filtresiyle sunucu tarafında yetkilendirilir.
 */
class XhPanel
{
	private int $vendor;
	private bool $admin;
	private string $base;

	public function __construct(int $vendor, bool $admin, string $base)
	{
		$this->vendor = $vendor;
		$this->admin = $admin;
		$this->base = $base;
	}

	/** Depo filtresi: yönetici hepsini (null), satıcı yalnızca kendisini görür. */
	private function scope(): ?int
	{
		return $this->admin ? null : $this->vendor;
	}

	public function url(array $q = []): string
	{
		return $this->base . ($q === [] ? '' : '?' . http_build_query($q));
	}

	private function go(array $q, string $type, string $msg): void
	{
		$_SESSION['xh_flash'] = ['type' => $type, 'message' => $msg];

		if (defined('XH_TEST')) {
			throw new RuntimeException('REDIRECT ' . $this->url($q));
		}

		header('Location: ' . $this->url($q));
		exit;
	}

	/** @return array{type:string,message:string}|null */
	public static function takeFlash(): ?array
	{
		$f = $_SESSION['xh_flash'] ?? null;
		unset($_SESSION['xh_flash']);

		return is_array($f) ? $f : null;
	}

	private static function post(string $k, string $d = ''): string
	{
		return isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim((string) $_POST[$k]) : $d;
	}

	private function ownerFor(): int
	{
		return $this->admin ? 0 : $this->vendor; // yönetici bağlantıları platforma ait (id_vendor = 0)
	}

	/** @return array{0:string, 1:array<string,mixed>} [görünüm, değişkenler] — POST işlemleri yönlendirir ve çıkar. */
	public function handle(bool $allowPost = true): array
	{
		if ($allowPost && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
			$this->post_action();
		}

		$view = (string) ($_GET['view'] ?? 'list');
		$common = ['xhAdmin' => $this->admin, 'xhBase' => $this->base, 'xhTitles' => ['list' => 'Bağlantılarım', 'new' => 'Yeni XML', 'suppliers' => 'Tedarikçiler', 'runs' => 'İşlem geçmişi', 'changes' => 'Fiyat/stok değişimleri', 'errors' => 'Hata raporları'] + ($this->admin ? [] : ['export' => 'XML dışa aktarma'])];

		switch ($view) {
			case 'new':
				return ['new', $common + ['suppliers' => XhRepo::suppliers($this->scope() ?? 0), 'categories' => $this->categories(), 'brands' => $this->brands(), 'intervals' => $this->intervals(), 'opt' => XhRepo::DEFAULT_OPTIONS]];
			case 'suppliers':
				return ['suppliers', $common + ['suppliers' => XhRepo::suppliers($this->scope() ?? 0), 'edit' => XhRepo::supplier((int) ($_GET['id'] ?? 0), $this->scope() ?? 0)]];
			case 'export':
				if ($this->admin) {
					break;
				}

				$nt = (string) ($_SESSION['xh_new_token'] ?? '');
				unset($_SESSION['xh_new_token']);

				return ['export', $common + ['feeds' => XhExport::feeds($this->vendor), 'newUrl' => $nt !== '' ? XhExport::feedUrl(str_replace('/xml-merkezi', '', $this->base), $nt) : '']];
			case 'changes':
				return ['changes', $common + ['changes' => XhRepo::changeHistory($this->scope(), 150)]];

			case 'runs':
				return ['runs', $common + ['runs' => XhRepo::runs($this->scope(), 0, 100)]];
			case 'errors':
				return ['errors', $common + ['errors' => XhRepo::errors($this->scope(), (int) ($_GET['id'] ?? 0), 200)]];
			case 'conn':
				return $this->connView($common);
		}

		$conns = XhRepo::connections($this->scope());

		return ['list', $common + ['conns' => $conns, 'queue' => count(array_filter(XhRepo::runs($this->scope(), 0, 50), static fn(array $r): bool => in_array($r['status'], ['queued', 'running'], true)))]];
	}

	/** @return list<array<string,mixed>> */
	private function categories(): array
	{
		if (class_exists('Category') && method_exists('Category', 'getProductSelectOptions')) {
			return Category::getProductSelectOptions();
		}
		return DB::execute('SELECT id_category, category_name FROM categories WHERE active = 1 ORDER BY category_name ASC LIMIT 2000') ?: [];
	}

	/** @return list<array<string,mixed>> */
	private function brands(): array
	{
		return DB::execute('SELECT id_brand, brand_name FROM brands WHERE active = 1 ORDER BY brand_name ASC LIMIT 2000') ?: [];
	}

	/** @return array<int,string> */
	private function intervals(): array
	{
		return [0 => 'Elle (otomatik değil)', 60 => 'Saatte bir', 180 => '3 saatte bir', 360 => '6 saatte bir', 720 => '12 saatte bir', 1440 => 'Günde bir'];
	}

	private function myConn(int $id): ?array
	{
		return XhRepo::conn($id, $this->scope());
	}

	private function sampleFile(array $conn): string
	{
		$p = (string) $conn['file_path'];

		return $p !== '' && XhStorage::isInside($p) && is_file($p) ? $p : '';
	}

	private function connView(array $common): array
	{
		$id = (int) ($_GET['id'] ?? 0);
		$conn = $this->myConn($id);

		if (!$conn) {
			$this->go([], 'danger', 'Bağlantı bulunamadı.');
		}

		$tab = in_array($_GET['tab'] ?? '', ['general', 'mapping', 'maps', 'preview', 'history', 'errors'], true) ? (string) $_GET['tab'] : 'general';
		$mapping = XhRepo::mapping($conn);
		$opt = XhRepo::options($conn);
		$vars = $common + ['conn' => $conn, 'tab' => $tab, 'opt' => $opt, 'mapping' => $mapping, 'intervals' => $this->intervals(), 'targets' => XhMapper::TARGETS, 'vtargets' => XhMapper::VARIANT_TARGETS, 'categories' => $this->categories(), 'brands' => $this->brands(), 'counts' => XhRepo::itemCounts($id), 'sampleOk' => false, 'keys' => [], 'rows' => [], 'distinctCats' => [], 'distinctBrands' => [], 'suppliers' => XhRepo::suppliers((int) $conn['id_vendor']), 'hasAuth' => XhRepo::hasAuth($id), 'unmapped' => ['brand' => [], 'category' => []], 'mapBrand' => (array) ($mapping['brand_map'] ?? []), 'mapCategory' => (array) ($mapping['category_map'] ?? [])];
		$sample = $this->sampleFile($conn);
		$vars['sampleOk'] = $sample !== '';

		try {
			if (($tab === 'mapping' || $tab === 'preview') && $sample !== '' && (string) $conn['item_path'] !== '') {
				$items = XhXml::sample($sample, (string) $conn['item_path'], 20);
				$keys = [];

				foreach ($items as $it) {
					foreach ($it as $k => $v) {
						$keys[$k] = true;
					}
				}

				$vars['keys'] = array_keys($keys);

				if ($tab === 'mapping') {
					$vars['suggest'] = $items !== [] ? XhMapper::suggest($items[0]) : ['fields' => [], 'variant_source' => '', 'variant_fields' => []];
					$vars['recordKeys'] = array_values(array_filter(array_keys($keys), static function ($k) use ($items): bool {
						foreach ($items as $it) {
							if (isset($it[$k]) && is_array($it[$k]) && is_array(reset($it[$k]))) {
								return true;
							}
						}

						return false;
					}));

					foreach (['category' => 'distinctCats', 'brand' => 'distinctBrands'] as $t => $var) {
						$spec = (string) ($mapping['fields'][$t] ?? '');

						if ($spec !== '' && $spec[0] !== '=') {
							$d = [];
							$n = 0;

							foreach (XhXml::stream($sample, (string) $conn['item_path'], 0) as $it) {
								$v = XhMapper::pick($it, $spec);

								if (is_string($v) && trim($v) !== '') {
									$d[mb_substr(trim(strip_tags($v)), 0, 255)] = true;
								}

								if (++$n >= 3000 || count($d) >= 60) {
									break;
								}
							}

							$vars[$var] = array_keys($d);
						}
					}
				} else {
					$rows = [];

					foreach ($items as $it) {
						$rows[] = XhImporter::preview($conn, $it);
					}

					$vars['rows'] = $rows;
				}
			}
		} catch (Throwable $e) {
			$vars['sampleError'] = mb_substr($e->getMessage(), 0, 200);
		}

		if ($tab === 'maps') {
			$um = XhImporter::unmapped($conn);

			foreach (['brand', 'category'] as $k) {
				$vars['unmapped'][$k] = [];

				foreach ($um[$k] as $text => $n) {
					$vars['unmapped'][$k][] = ['text' => (string) $text, 'enc' => base64_encode((string) $text), 'n' => $n];
				}
			}
		}

		if ($tab === 'history') {
			$vars['runs'] = XhRepo::runs($this->scope(), $id, 50);
		}

		if ($tab === 'errors') {
			$vars['errors'] = XhRepo::errors($this->scope(), $id, 200);
		}

		return ['conn', $vars];
	}

	// ---------------------------------------------------------------- POST işlemleri

	private function post_action(): void
	{
		$a = self::post('xhAction');
		$id = (int) self::post('id_connection');

		switch ($a) {
			case 'conn_status':
				$c = $this->myConn($id);
				$status = self::post('status');
				if (!$c || !in_array($status, ['active', 'paused'], true)) {
					$this->go(['view' => 'conn', 'id' => $id], 'danger', 'Bağlantı durumu değiştirilemedi.');
				}
				$fields = ['status' => $status];
				if ($status === 'active' && (int) $c['interval_minutes'] > 0 && empty($c['next_run_at'])) {
					$fields['next_run_at'] = XhRepo::now();
				}
				XhRepo::setConn($id, $fields);
				$this->go(['view' => 'conn', 'id' => $id, 'tab' => 'general'], 'success', $status === 'active' ? 'Bağlantı aktifleştirildi. Bekleyen aktarım kaldığı yerden devam edebilir.' : 'Bağlantı duraklatıldı. Çalışan aktarım ürün satırının sonunda durur.');
				break;

			case 'supplier_save':
				$r = XhRepo::saveSupplier($this->ownerFor(), (int) self::post('id_supplier'), self::post('name'), self::post('website'), self::post('notes'));
				$this->go(['view' => 'suppliers'], $r['success'] ? 'success' : 'danger', $r['message']);
				break;

			case 'export_create':
				if ($this->admin || $this->vendor <= 0) {
					$this->go(['view' => 'list'], 'danger', 'Dışa aktarma yalnızca satıcı hesapları içindir.');
				}

				$r = XhExport::create($this->vendor, self::post('name'), self::post('only_in_stock') === '1');

				if ($r['success']) {
					$_SESSION['xh_new_token'] = $r['token'];
				}

				$this->go(['view' => 'export'], $r['success'] ? 'success' : 'danger', $r['message'] . ($r['success'] ? ' Adres yalnızca şimdi gösterilir; kopyalayıp saklayın.' : ''));
				break;

			case 'export_revoke':
				$ok = !$this->admin && XhExport::revoke($this->vendor, (int) self::post('id_feed'));
				$this->go(['view' => 'export'], $ok ? 'success' : 'danger', $ok ? 'Besleme iptal edildi.' : 'Besleme bulunamadı.');
				break;

			case 'conn_create':
				$this->createConnection();
				break;

			case 'conn_test':
				$c = $this->myConn($id);
				$c ? $this->testConnection($c) : $this->go([], 'danger', 'Bağlantı bulunamadı.');
				break;

			case 'conn_mapping_save':
				$c = $this->myConn($id);

				if (!$c) {
					$this->go([], 'danger', 'Bağlantı bulunamadı.');
				}

				$previous = XhRepo::mapping($c);
				$m = XhMapper::sanitizeMapping([
					'fields' => $_POST['fields'] ?? [], 'variant_source' => self::post('variant_source'), 'variant_fields' => $_POST['variant_fields'] ?? [],
					'category_map' => array_replace((array) ($previous['category_map'] ?? []), $this->pairs('cat_text', 'cat_id')),
					'brand_map' => array_replace((array) ($previous['brand_map'] ?? []), $this->pairs('brand_text', 'brand_id')),
				]);
				$itemPath = mb_substr(preg_replace('/[^A-Za-z0-9_.:\-\/]/', '', self::post('item_path')) ?? '', 0, 190);
				XhRepo::setConn($id, ['mapping' => json_encode($m, JSON_UNESCAPED_UNICODE), 'item_path' => $itemPath !== '' ? $itemPath : (string) $c['item_path']]);
				$saved = XhRepo::mapping(XhRepo::conn($id, $this->scope()) ?: []);
				if (($saved['fields'] ?? []) !== ($m['fields'] ?? []) || ($saved['category_map'] ?? []) !== ($m['category_map'] ?? []) || ($saved['brand_map'] ?? []) !== ($m['brand_map'] ?? []) ) {
					$this->go(['view' => 'conn', 'id' => $id, 'tab' => 'mapping'], 'danger', 'Eşleştirmeler veritabanına doğrulanarak kaydedilemedi. Lütfen tekrar deneyin.');
				}
				$this->go(['view' => 'conn', 'id' => $id, 'tab' => 'mapping'], 'success', 'Eşleştirme kaydedildi. Ürünler SKU/barkod ve tekil ürün adına göre otomatik eşleştirilir.' . (($m['fields']['name'] ?? '') === '' || ($m['fields']['price'] ?? '') === '' ? ' Uyarı: ürün adı ve fiyat eşleşmeden içe aktarma başlatılamaz.' : ''));
				break;

			case 'conn_options_save':
				$c = $this->myConn($id);

				if (!$c) {
					$this->go([], 'danger', 'Bağlantı bulunamadı.');
				}

				$status = self::post('status');
				$upd = [
					'name' => mb_substr(trim(strip_tags(self::post('name'))), 0, 120) ?: (string) $c['name'],
					'options' => json_encode(XhRepo::sanitizeOptions($_POST['opt'] ?? [])),
					'interval_minutes' => XhRepo::sanitizeInterval(self::post('interval_minutes')),
				];

				if (in_array($status, ['active', 'paused'], true)) {
					$upd['status'] = $status;
				}

				$sup = (int) self::post('id_supplier');

				if ($sup === 0 || XhRepo::supplier($sup, (int) $c['id_vendor'])) {
					$upd['id_supplier'] = $sup;
				}

				if ((int) $upd['interval_minutes'] > 0 && $c['next_run_at'] === null) {
					$upd['next_run_at'] = XhRepo::now();
				}

				XhRepo::setConn($id, $upd);
				$this->go(['view' => 'conn', 'id' => $id], 'success', 'Ayarlar kaydedildi.');
				break;

			case 'conn_auth_save':
				$c = $this->myConn($id);

				if (!$c || $c['source_type'] !== 'url') {
					$this->go([], 'danger', 'URL bağlantısı bulunamadı.');
				}

				$r = XhRepo::saveAuth($id, self::post('auth_user'), (string) ($_POST['auth_pass'] ?? ''));
				$this->go(['view' => 'conn', 'id' => $id], $r['success'] ? 'success' : 'danger', $r['message']);
				break;

			case 'conn_map_save':
				$c = $this->myConn($id);

				if (!$c) {
					$this->go([], 'danger', 'Bağlantı bulunamadı.');
				}

				$n = 0;
				$msg = '';

				foreach (['brand', 'category'] as $kind) {
					foreach ((array) ($_POST['map_' . $kind] ?? []) as $enc => $target) {
						$text = (string) base64_decode((string) $enc, true);
						$target = (int) $target;

						if ($text === '') {
							continue;
						}

						$r = XhRepo::setMapEntry(XhRepo::conn($id, $this->scope()) ?: $c, $kind, $text, $target);
						$n += $r['success'] ? 1 : 0;
						$msg = $r['success'] ? $msg : $r['message'];
					}
				}

				$this->go(['view' => 'conn', 'id' => $id, 'tab' => 'maps'], $n > 0 ? 'success' : 'danger', $n > 0 ? $n . ' eşleştirme kaydedildi. Hatalı satırlar için "Hatalıları yeniden dene" kullanabilirsiniz.' : ($msg ?: 'Eşleştirilecek bir seçim yapılmadı.'));
				break;

			case 'conn_delete':
				$ok = XhRepo::deleteConnection($id, $this->scope());
				$this->go($ok ? [] : ['view' => 'conn', 'id' => $id], $ok ? 'success' : 'warning', $ok ? 'Bağlantı ve XML Hub kayıtları silindi; mağaza ürünleri korundu.' : 'Aktif cron işlemi durduruldu. Birkaç saniye sonra bağlantıyı sil düğmesine tekrar basın.');
				break;

			case 'run_enqueue':
				$kind = self::post('kind');
				$sel = null;

				if (self::post('selected_only') === '1') {
					$sel = array_values(array_filter(array_map('strval', (array) ($_POST['sel'] ?? [])), static fn(string $s): bool => $s !== ''));

					if ($sel === []) {
						$this->go(['view' => 'conn', 'id' => $id, 'tab' => 'preview'], 'danger', 'Önce ürün seçin.');
					}
				}

				$r = XhRunner::enqueue($id, $kind, $sel, $this->scope());
				$this->go(['view' => 'conn', 'id' => $id, 'tab' => $r['success'] ? 'history' : 'general'], $r['success'] ? 'success' : 'danger', $r['message'] . ($r['success'] ? ' Zamanlanmış görev (cron) çalışmıyorsa "Kuyruğu şimdi işle" düğmesini kullanın.' : ''));
				break;

			case 'run_cancel':
				$ok = XhRunner::cancel((int) self::post('id_run'), $this->scope());
				$this->go(['view' => 'conn', 'id' => $id, 'tab' => 'history'], $ok ? 'success' : 'danger', $ok ? 'Aktarım durduruldu; mevcut ürün satırı tamamlandıktan sonra kuyruk bırakılacak.' : 'İşlem iptal edilemedi.');
				break;

			case 'queue_tick':
				$r = XhRunner::tick(15, 300, $this->scope() === null ? null : $this->vendor);
				$this->go(['view' => 'runs'], 'success', $r['items'] . ' ürün işlendi (' . $r['runs'] . ' işlem).');
				break;
		}
	}

	/** @return array<string,int> */
	private function pairs(string $textKey, string $idKey): array
	{
		$t = (array) ($_POST[$textKey] ?? []);
		$i = (array) ($_POST[$idKey] ?? []);
		$out = [];

		foreach ($t as $n => $txt) {
			if (is_scalar($txt) && (int) ($i[$n] ?? 0) > 0) {
				$out[(string) $txt] = (int) $i[$n];
			}
		}

		return $out;
	}

	private function createConnection(): void
	{
		$type = self::post('source_type') === 'file' ? 'file' : 'url';
		$d = ['name' => self::post('name'), 'source_type' => $type, 'url' => self::post('url'), 'id_supplier' => (int) self::post('id_supplier'), 'interval_minutes' => self::post('interval_minutes'), 'options' => $_POST['opt'] ?? []];
		// Tedarikçi seçilmediyse bağlantı adından otomatik bul/oluştur; ürün meta kayıtları bu tedarikçiye bağlanır.
		if ((int) $d['id_supplier'] <= 0) {
			$supplierKey = XhMapper::normText((string) $d['name']);
			foreach (XhRepo::suppliers($this->ownerFor()) as $existingSupplier) {
				if ($supplierKey !== '' && XhMapper::normText((string) $existingSupplier['name']) === $supplierKey) {
					$d['id_supplier'] = (int) $existingSupplier['id_supplier'];
					break;
				}
			}
			if ((int) $d['id_supplier'] <= 0) {
				$createdSupplier = XhRepo::saveSupplier($this->ownerFor(), 0, (string) $d['name'], '', 'XML bağlantısı oluşturulurken otomatik tanımlandı.');
				if (!$createdSupplier['success']) {
					$this->go(['view' => 'new'], 'danger', 'Tedarikçi otomatik oluşturulamadı: ' . $createdSupplier['message']);
				}
				$d['id_supplier'] = (int) $createdSupplier['id'];
			}
		}
		$path = '';

		if ($type === 'file') {
			$f = $_FILES['xml_file'] ?? null;

			if (!$f || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
				$this->go(['view' => 'new'], 'danger', 'Geçerli bir XML dosyası seçin.');
			}

			if ((int) $f['size'] > XhXml::MAX_BYTES) {
				$this->go(['view' => 'new'], 'danger', 'Dosya 50 MB sınırını aşıyor.');
			}

			$path = XhStorage::newPath('up');

			if (!move_uploaded_file((string) $f['tmp_name'], $path)) {
				$this->go(['view' => 'new'], 'danger', 'Dosya kaydedilemedi.');
			}

			$v = XhXml::validate($path);

			if (!$v['ok']) {
				XhStorage::remove($path);
				$this->go(['view' => 'new'], 'danger', 'XML reddedildi: ' . $v['error']);
			}

			$d['file_path'] = $path;
		}

		$r = XhRepo::createConnection($this->ownerFor(), $d);

		if (!$r['success']) {
			XhStorage::remove($path);
			$this->go(['view' => 'new'], 'danger', $r['message']);
		}

		$c = XhRepo::conn($r['id'], null);

		if ($c) {
			$this->testConnection($c, true);
		}

		$this->go(['view' => 'conn', 'id' => $r['id'], 'tab' => 'mapping'], 'success', 'Bağlantı oluşturuldu. Şimdi alanları eşleştirin.');
	}

	/** Bağlantı testi: kaynağı güvenle alır, XML'i doğrular, ürün düğümünü bulur ve örnek dosyayı saklar. */
	private function testConnection(array $conn, bool $quiet = false): void
	{
		$id = (int) $conn['id_connection'];
		$path = (string) $conn['file_path'];
		$msg = '';

		if ($conn['source_type'] === 'url') {
			XhStorage::remove($path);
			$path = XhStorage::newPath('sample');
			$f = XhHttp::fetchToFile((string) $conn['url'], $path, XhHttp::MAX_BYTES, XhHttp::TIMEOUT, XhRepo::auth((int) $conn['id_connection']));

			if (!$f['ok']) {
				XhRepo::setConn($id, ['last_error' => mb_substr('Test: ' . $f['error'], 0, 255), 'file_path' => '']);
				XhRepo::logError($id, 0, '', 'Bağlantı testi başarısız: ' . $f['error']);
				$quiet ? null : $this->go(['view' => 'conn', 'id' => $id], 'danger', 'Bağlantı testi başarısız: ' . $f['error']);

				return;
			}
		}

		$v = $path !== '' && XhStorage::isInside($path) ? XhXml::validate($path) : ['ok' => false, 'error' => 'Kaynak dosya yok.'];

		if (!$v['ok']) {
			XhRepo::setConn($id, ['last_error' => mb_substr('Test: ' . $v['error'], 0, 255)]);
			XhRepo::logError($id, 0, '', 'Bağlantı testi: XML reddedildi: ' . $v['error']);
			$quiet ? null : $this->go(['view' => 'conn', 'id' => $id], 'danger', 'XML reddedildi: ' . $v['error']);

			return;
		}

		$ip = (string) $conn['item_path'] !== '' ? (string) $conn['item_path'] : (XhXml::detectItemPath($path) ?? '');
		$n = $ip !== '' ? XhXml::countItems($path, $ip) : 0;
		$upd = ['file_path' => $path, 'last_error' => ''];

		if ($ip !== '') {
			$upd['item_path'] = $ip;
		}

		XhRepo::setConn($id, $upd);

		if (!$quiet) {
			$this->go(['view' => 'conn', 'id' => $id], $ip !== '' ? 'success' : 'warning', $ip !== '' ? 'Bağlantı başarılı: ' . $n . ' ürün bulundu (düğüm: ' . $ip . ').' : 'XML geçerli ancak ürün düğümü otomatik bulunamadı; eşleştirme sekmesinde yolu girin.');
		}
	}
}
