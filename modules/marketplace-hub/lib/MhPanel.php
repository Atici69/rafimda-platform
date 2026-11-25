<?php

/**
 * Pazaryeri Hub ekran denetleyicisi (satıcı ve yönetici). Kapsam: $vendor > 0 → yalnızca o satıcı; yönetici → tüm kayıtlar.
 * CSRF/oturum denetimi çağıran katmanda; HER işlem burada kapsam filtresiyle sunucu tarafında yetkilendirilir.
 */
class MhPanel
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

	private function scope(): ?int
	{
		return $this->admin ? null : $this->vendor;
	}

	/** Yeni kayıtların sahibi: satıcı kendisi, yönetici platform (0). */
	private function owner(): int
	{
		return $this->admin ? 0 : $this->vendor;
	}

	public function url(array $q = []): string
	{
		return $this->base . ($q === [] ? '' : '?' . http_build_query($q));
	}

	private function go(array $q, string $type, string $msg): void
	{
		$_SESSION['mh_flash'] = ['type' => $type, 'message' => $msg];

		if (defined('MH_TEST')) {
			throw new RuntimeException('REDIRECT ' . $this->url($q));
		}

		header('Location: ' . $this->url($q));
		exit;
	}

	/** @return array{type:string,message:string}|null */
	public static function takeFlash(): ?array
	{
		$f = $_SESSION['mh_flash'] ?? null;
		unset($_SESSION['mh_flash']);

		return is_array($f) ? $f : null;
	}

	private static function post(string $k): string
	{
		return isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim((string) $_POST[$k]) : '';
	}

	/** @return array<string,array<string,mixed>> */
	private function marketplaces(): array
	{
		$out = [];

		foreach (MhRegistry::all() as $k => $a) {
			$out[$k] = ['key' => $k, 'title' => $a->title(), 'implemented' => $a->implemented(), 'stock_price' => $a instanceof MhCoreAdapter, 'fields' => $a->credentialFields()];
		}

		return $out;
	}

	/** @return array{0:string,1:array<string,mixed>} */
	public function handle(bool $allowPost = true): array
	{
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && $allowPost) {
			$this->postAction();
		}

		$view = (string) ($_GET['view'] ?? 'list');
		$id = (int) ($_GET['id'] ?? 0);

		if ($view === 'account' && $id > 0) {
			$acc = MhRepo::account($id, $this->scope());

			if (!$acc) {
				$this->go([], 'danger', 'Hesap bulunamadı.');
			}

			$tab = in_array($_GET['tab'] ?? '', ['general', 'categories', 'attributes', 'products', 'listings', 'errors'], true) ? (string) $_GET['tab'] : 'general';
			$a = MhRegistry::get((string) $acc['marketplace']);
			$vars = ['acc' => $acc, 'tab' => $tab, 'adapter' => ['title' => $a ? $a->title() : '', 'implemented' => $a ? $a->implemented() : false, 'stock_price' => $a instanceof MhCoreAdapter, 'fields' => $a ? $a->credentialFields() : []], 'credStatus' => MhRepo::credentialStatus($id), 'counts' => MhRepo::listingCounts($id), 'catMap' => [], 'categories' => [], 'products' => [], 'listings' => [], 'errors' => [], 'catalog' => MhCatalog::supported((string) $acc['marketplace']), 'liveSubmit' => MhCoreAdapter::liveSubmitEnabled(), 'search' => null, 'q' => '', 'attrCats' => [], 'rc' => '', 'attrs' => null, 'attrMap' => [], 'brands' => [], 'brandMap' => []];

			if ($tab === 'categories') {
				$vars['categories'] = [];

				foreach (CategoryMatchStore::siteTargets() as $t) {
					$vars['categories'][] = ['id_category' => (int) $t['id'], 'category_name' => implode(' › ', $t['path'])];
				}

				usort($vars['categories'], static fn(array $a, array $b): int => strcmp($a['category_name'], $b['category_name']));
				$vars['catMap'] = MhRepo::categoryMap($id);
				$vars['q'] = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);

				if ($vars['q'] !== '' && $vars['catalog']) {
					$vars['search'] = MhCatalog::searchCategories($id, $this->scope(), $vars['q']);
				}
			} elseif ($tab === 'attributes') {
				$cm = MhRepo::categoryMap($id);
				$seen = [];

				foreach ($cm as $m) {
					$seen[$m['remote_category_id']] = $m['remote_category_name'];
				}

				$vars['attrCats'] = $seen;
				$vars['rc'] = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($_GET['rc'] ?? '')) ?? '';

				if ($vars['rc'] !== '' && $vars['catalog']) {
					$vars['attrs'] = MhCatalog::attributes($id, $this->scope(), $vars['rc']);
					$vars['attrMap'] = MhCatalog::attributeMap($id, $vars['rc']);
				}

				$owner = (int) $acc['id_vendor'];
				$ids = array_values(array_unique(array_map(static fn(array $p): int => (int) ($p['id_brand'] ?? 0), $this->ownProducts($owner))));
				$vars['brands'] = $ids === [] ? [] : (DB::execute('SELECT id_brand, brand_name FROM brands WHERE id_brand IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY brand_name ASC') ?: []);
				$vars['brandMap'] = MhCatalog::brandMap($id);
			} elseif ($tab === 'products') {
				$vars['products'] = $this->ownProducts((int) $acc['id_vendor']);
				$vars['catMap'] = MhRepo::categoryMap($id);
			} elseif ($tab === 'listings') {
				$vars['listings'] = MhRepo::listings($this->scope(), $id, 200);
			} elseif ($tab === 'errors') {
				$vars['errors'] = MhRepo::errors($this->scope(), $id, 200);
			}

			return ['account', $vars];
		}

		if ($view === 'errors') {
			return ['errors', ['errors' => MhRepo::errors($this->scope(), 0, 200)]];
		}

		if ($view === 'listings') {
			return ['listings', ['listings' => MhRepo::listings($this->scope(), 0, 200)]];
		}

		return ['list', ['accounts' => MhRepo::accounts($this->scope()), 'marketplaces' => $this->marketplaces()]];
	}

	/** @return list<array<string,mixed>> */
	private function ownProducts(int $owner): array
	{
		if ($owner > 0) {
			return DB::execute('SELECT p.id_product, p.product_name, p.stock_code, p.price, p.stock, p.id_category, p.id_brand FROM products p INNER JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE vp.id_vendor = ? ORDER BY p.id_product DESC LIMIT 200', [$owner]) ?: [];
		}

		return DB::execute('SELECT p.id_product, p.product_name, p.stock_code, p.price, p.stock, p.id_category, p.id_brand FROM products p' . (MhOwner::vendorTableExists() ? ' WHERE NOT EXISTS (SELECT 1 FROM rafimda_vendor_products vp WHERE vp.id_product = p.id_product)' : '') . ' ORDER BY p.id_product DESC LIMIT 200') ?: [];
	}

	private function postAction(): void
	{
		$a = self::post('mhAction');
		$id = (int) self::post('id_account');
		$acc = $id > 0 ? MhRepo::account($id, $this->scope()) : null;

		switch ($a) {
			case 'account_create':
				$r = MhRepo::createAccount($this->owner(), self::post('marketplace'), self::post('label'), (array) ($_POST['cred'] ?? []));
				$this->go($r['success'] ? ['view' => 'account', 'id' => $r['id']] : [], $r['success'] ? 'success' : 'danger', $r['message']);
				break;

			case 'account_cred_save':
				$r = $acc ? MhRepo::updateCredentials($id, $this->scope(), (array) ($_POST['cred'] ?? [])) : ['success' => false, 'message' => 'Hesap bulunamadı.'];
				$this->go($acc ? ['view' => 'account', 'id' => $id] : [], $r['success'] ? 'success' : 'danger', $r['message']);
				break;

			case 'account_test':
				if (!$acc) {
					$this->go([], 'danger', 'Hesap bulunamadı.');
				}

				$ad = MhRegistry::get((string) $acc['marketplace']);
				$r = $ad ? $ad->testConnection(MhRepo::credentials($id)) : ['success' => false, 'message' => 'Bağdaştırıcı yok.'];
				DB::update('mh_accounts', ['last_test_at' => MhRepo::now(), 'last_test_ok' => !empty($r['success']) ? 1 : 0, 'last_error' => mb_substr((string) $r['message'], 0, 255)], 'id_account = :i', ['i' => $id]);

				if (!empty($r['success']) && $ad && $ad->implemented()) {
					MhRepo::setStatus($id, 'ready');
				}

				$this->go(['view' => 'account', 'id' => $id], !empty($r['success']) ? 'success' : 'warning', (string) $r['message']);
				break;

			case 'account_delete':
				$ok = MhRepo::deleteAccount($id, $this->scope());
				$this->go([], $ok ? 'success' : 'danger', $ok ? 'Hesap ve pazaryeri kayıtları silindi (ürünler korundu).' : 'Hesap bulunamadı.');
				break;

			case 'category_map_save':
				if (!$acc) {
					$this->go([], 'danger', 'Hesap bulunamadı.');
				}

				$n = 0;
				$err = '';

				$before = MhRepo::categoryMap($id);

				foreach ((array) ($_POST['remote'] ?? []) as $cid => $remote) {
					$remote = trim((string) $remote);
					$name = (string) (($_POST['remote_name'] ?? [])[$cid] ?? '');
					$old = $before[(int) $cid]['remote_category_id'] ?? '';

					if ($remote !== '' && $remote !== $old && MhCatalog::supported((string) $acc['marketplace'])) {
						$path = MhCatalog::categoryPath($id, $this->scope(), $remote);

						if ($path === '' && MhCatalog::categories($id, $this->scope())['ok']) {
							$err = 'Kategori kodu ' . mb_substr($remote, 0, 20) . ' pazaryeri listesinde yok.';
							continue;
						}

						$name = $path !== '' ? $path : $name;
					}

					$r = MhRepo::setCategoryMap($id, $this->scope(), (int) $cid, $remote, $name);
					$n += $r['success'] ? 1 : 0;
					$err = $r['success'] ? $err : $r['message'];

					if ($r['success'] && $remote !== '' && $remote !== $old) {
						MhCatalog::rememberManual($id, $this->scope(), (int) $cid, $remote, $name);
					}
				}

				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'categories'], $err === '' ? 'success' : 'danger', $err === '' ? 'Kategori eşlemeleri kaydedildi.' : $err);
				break;

			case 'category_auto_map':
				if (!$acc) {
					$this->go([], 'danger', 'Hesap bulunamadı.');
				}

				$r = MhCatalog::autoMapCategories($id, $this->scope(), self::post('overwrite') === '1');
				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'categories'], $r['ok'] ? 'success' : 'danger', $r['message']);
				break;

			case 'catalog_refresh':
				if ($acc) {
					MhCatalog::clearCache((string) $acc['marketplace']);
					MhCatalog::resetPrepared();
					$r = MhCatalog::categories($id, $this->scope(), true);
					$this->go(['view' => 'account', 'id' => $id, 'tab' => 'categories'], $r['ok'] ? 'success' : 'danger', $r['ok'] ? count($r['items']) . ' pazaryeri kategorisi yenilendi.' : $r['message']);
				}

				$this->go([], 'danger', 'Hesap bulunamadı.');
				break;

			case 'attribute_map_save':
				if (!$acc) {
					$this->go([], 'danger', 'Hesap bulunamadı.');
				}

				$rc = preg_replace('/[^0-9A-Za-z_-]/', '', self::post('rc')) ?? '';
				$r = MhCatalog::saveAttributeMap($id, $this->scope(), $rc, array_map('strval', (array) ($_POST['val'] ?? [])), array_map('strval', (array) ($_POST['custom'] ?? [])));
				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'attributes', 'rc' => $rc], $r['success'] ? 'success' : 'warning', $r['message']);
				break;

			case 'brand_map_save':
				if (!$acc) {
					$this->go([], 'danger', 'Hesap bulunamadı.');
				}

				$err = '';

				foreach ((array) ($_POST['brand_remote'] ?? []) as $bid => $remote) {
					$r = MhCatalog::setBrandMap($id, $this->scope(), (int) $bid, (string) $remote, (string) (($_POST['brand_remote_name'] ?? [])[$bid] ?? ''));
					$err = $r['success'] ? $err : $r['message'];
				}

				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'attributes'], $err === '' ? 'success' : 'danger', $err === '' ? 'Marka eşlemeleri kaydedildi.' : $err);
				break;

			case 'brand_auto':
				if (!$acc) {
					$this->go([], 'danger', 'Hesap bulunamadı.');
				}

				$ad = MhRegistry::get((string) $acc['marketplace']);
				$found = 0;
				$miss = [];

				if ($ad instanceof MhCoreAdapter && $ad->supportsCatalog()) {
					$creds = MhRepo::credentials($id);
					$map = MhCatalog::brandMap($id);
					$ids = array_values(array_unique(array_map(static fn(array $p): int => (int) ($p['id_brand'] ?? 0), $this->ownProducts((int) $acc['id_vendor']))));

					foreach (array_slice($ids, 0, 100) as $bid) {
						if ($bid <= 0 || isset($map[$bid])) {
							continue;
						}

						$name = (string) DB::getValue('SELECT brand_name FROM brands WHERE id_brand = ?', [$bid]);
						$r = MhCatalog::resolveBrand($id, $bid, $name, $ad, $creds);
						$found += $r['ok'] ? 1 : 0;

						if (!$r['ok']) {
							$miss[] = $name;
						}
					}
				}

				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'attributes'], $found > 0 ? 'success' : 'warning', $found . ' marka otomatik eşlendi.' . ($miss ? ' Bulunamayan: ' . implode(', ', array_slice($miss, 0, 10)) . ' (kodu elle girin).' : ''));
				break;

			case 'queue_products':
				if (!$acc) {
					$this->go([], 'danger', 'Hesap bulunamadı.');
				}

				$ids = array_map('intval', (array) ($_POST['ids'] ?? []));
				$r = MhDispatcher::queue($id, $this->scope(), $ids);
				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'listings'], $r['queued'] > 0 ? 'success' : 'warning', 'Kuyruğa alınan: ' . $r['queued'] . ', engellenen: ' . $r['blocked'] . ', reddedilen (size ait değil): ' . $r['rejected'] . '. Engellenenlerin nedeni Gönderimler sekmesindedir.');
				break;

			case 'push_stock_price':
				if (!$acc) {
					$this->go([], 'danger', 'Hesap bulunamadı.');
				}

				$r = MhDispatcher::pushStockPrice($id, $this->scope(), array_map('intval', (array) ($_POST['ids'] ?? [])));
				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'listings'], $r['ok'] ? 'success' : 'warning', $r['message']);
				break;

			case 'retry_failed':
				$n = MhDispatcher::retryFailed($id, $this->scope());
				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'listings'], $n > 0 ? 'success' : 'warning', $n . ' başarısız kayıt yeniden kuyruğa alındı.');
				break;

			case 'queue_tick':
				if ($this->admin) {
					$r = MhDispatcher::tick(20);
					$this->go([], 'success', 'İşlenen: ' . $r['processed'] . ', gönderilen: ' . $r['sent'] . ', başarısız: ' . $r['failed']);
				}

				break;
		}

		$this->go([], 'danger', 'Geçersiz işlem.');
	}
}
