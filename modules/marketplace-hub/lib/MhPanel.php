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

			$tab = in_array($_GET['tab'] ?? '', ['general', 'categories', 'products', 'listings', 'errors'], true) ? (string) $_GET['tab'] : 'general';
			$a = MhRegistry::get((string) $acc['marketplace']);
			$vars = ['acc' => $acc, 'tab' => $tab, 'adapter' => ['title' => $a ? $a->title() : '', 'implemented' => $a ? $a->implemented() : false, 'stock_price' => $a instanceof MhCoreAdapter, 'fields' => $a ? $a->credentialFields() : []], 'credStatus' => MhRepo::credentialStatus($id), 'counts' => MhRepo::listingCounts($id), 'catMap' => [], 'categories' => [], 'products' => [], 'listings' => [], 'errors' => []];

			if ($tab === 'categories') {
				$vars['categories'] = $this->usedCategories((int) $acc['id_vendor']);
				$vars['catMap'] = MhRepo::categoryMap($id);
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
	/**
	 * Eşleme için yalnızca bu hesabın ürünlerinin bulunduğu kategoriler (yüzlerce kategoriyi listelemek yerine),
	 * aynı adlı kategoriler ayırt edilsin diye tam yol ile. Ürün yoksa tüm aktif kategoriler (en çok 500).
	 * @return list<array{id_category:int, category_name:string, products:int}>
	 */
	private function usedCategories(int $owner): array
	{
		$counts = [];

		foreach ($this->ownProducts($owner) as $p) {
			$counts[(int) $p['id_category']] = ($counts[(int) $p['id_category']] ?? 0) + 1;
		}

		$rows = DB::execute('SELECT id_category, id_parent, category_name FROM categories WHERE active = 1') ?: DB::execute('SELECT id_category, category_name FROM categories WHERE active = 1') ?: [];
		$byId = [];

		foreach ($rows as $r) {
			$byId[(int) $r['id_category']] = $r;
		}

		$path = static function (int $id) use ($byId): string {
			$parts = [];
			$guard = 0;

			while (isset($byId[$id]) && $guard++ < 10) {
				if ((int) ($byId[$id]['id_parent'] ?? 0) === 0 && $parts !== []) {
					break; // kök (Katalog) yola katılmaz
				}

				array_unshift($parts, (string) $byId[$id]['category_name']);
				$id = (int) ($byId[$id]['id_parent'] ?? 0);
			}

			return implode(' > ', $parts);
		};
		$out = [];

		foreach ($counts !== [] ? array_keys($counts) : array_slice(array_keys($byId), 0, 500) as $id) {
			if (isset($byId[$id])) {
				$out[] = ['id_category' => $id, 'category_name' => $path($id), 'products' => $counts[$id] ?? 0];
			}
		}

		usort($out, static fn(array $a, array $b): int => strcmp($a['category_name'], $b['category_name']));

		return $out;
	}

	private function ownProducts(int $owner): array
	{
		if ($owner > 0) {
			return DB::execute('SELECT p.id_product, p.product_name, p.stock_code, p.price, p.stock, p.id_category FROM products p INNER JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE vp.id_vendor = ? ORDER BY p.id_product DESC LIMIT 200', [$owner]) ?: [];
		}

		return DB::execute('SELECT p.id_product, p.product_name, p.stock_code, p.price, p.stock, p.id_category FROM products p' . (MhOwner::vendorTableExists() ? ' WHERE NOT EXISTS (SELECT 1 FROM rafimda_vendor_products vp WHERE vp.id_product = p.id_product)' : '') . ' ORDER BY p.id_product DESC LIMIT 200') ?: [];
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

				foreach ((array) ($_POST['remote'] ?? []) as $cid => $remote) {
					$r = MhRepo::setCategoryMap($id, $this->scope(), (int) $cid, (string) $remote, (string) (($_POST['remote_name'] ?? [])[$cid] ?? ''));
					$n += $r['success'] ? 1 : 0;
					$err = $r['success'] ? $err : $r['message'];
				}

				$this->go(['view' => 'account', 'id' => $id, 'tab' => 'categories'], $err === '' ? 'success' : 'danger', $err === '' ? 'Kategori eşlemeleri kaydedildi.' : $err);
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
