<?php

/**
 * Paketler ve abonelik. ÖDEME OTOMASYONU YOKTUR: paketi yönetici elle atar (süreli ya da süresiz).
 * Süresi dolan paket otomatik olarak 'free' paketine düşer (okuma anında; veri silinmez, yalnızca yeni kayıt limitleri etkilenir).
 * Limitler NULL ise sınırsızdır; varsayılan 'free' paketi sınırsız tohumlanır (mevcut davranış değişmez).
 */
class RvPlans
{
	public const DEFAULT_CODE = 'free';

	/**
	 * Ücretsiz mağaza modu (varsayılan AÇIK): satıcılardan mağaza/abonelik/paket ücreti alınmaz; tek gelir satış komisyonudur.
	 * Paketler yalnızca yöneticinin atadığı ücretsiz LİMİT gruplarıdır. Eski ücretli kayıtlar silinmez, 0 ₺ gösterilir.
	 * Ücretli paketler yalnızca RAFIMDA_PAID_PLANS = '1' ile (yazılı onayla) yeniden açılabilir.
	 */
	public static function freeMode(): bool
	{
		return Settings::get('RAFIMDA_PAID_PLANS') !== '1';
	}

	/** Varsayılan paketleri (yoksa) oluşturur. Taşınabilir SQL için PHP'de yapılır. */
	public static function ensureDefaults(): void
	{
		if (!self::get(self::DEFAULT_CODE)) {
			DB::insert('rafimda_plans', ['code' => self::DEFAULT_CODE, 'name' => 'Başlangıç', 'price_monthly' => 0, 'sort_order' => 0]);
		}
	}

	/** @return list<array<string,mixed>> */
	public static function all(bool $onlyActive = false): array
	{
		self::ensureDefaults();

		$rows = DB::execute('SELECT * FROM rafimda_plans' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort_order ASC, id_plan ASC') ?: [];

		return self::freeMode() ? array_map([self::class, 'asFree'], $rows) : $rows;
	}

	/** @param array<string,mixed> $p @return array<string,mixed> */
	private static function asFree(array $p): array
	{
		$p['price_monthly'] = 0;

		return $p;
	}

	/** @return array<string,mixed>|null */
	public static function get(string $code): ?array
	{
		$r = DB::getRowSafe('rafimda_plans', 'code = ?', [$code]);

		return $r ? (self::freeMode() ? self::asFree($r) : $r) : null;
	}

	/** @param array<string,mixed> $d @return array{success:bool, message:string} */
	public static function save(array $d): array
	{
		$code = strtolower(trim((string) ($d['code'] ?? '')));
		$name = mb_substr(trim(strip_tags((string) ($d['name'] ?? ''))), 0, 80);

		if (!preg_match('/^[a-z0-9_-]{2,32}$/', $code)) {
			return ['success' => false, 'message' => 'Kod 2-32 karakter: küçük harf, rakam, - veya _.'];
		}

		if (mb_strlen($name) < 2) {
			return ['success' => false, 'message' => 'Paket adı en az 2 karakter olmalı.'];
		}

		$price = self::num($d['price_monthly'] ?? 0);

		if ($price === null || $price < 0 || $price > 1000000) {
			return ['success' => false, 'message' => 'Geçersiz aylık ücret.'];
		}

		if ($price > 0 && self::freeMode()) {
			return ['success' => false, 'message' => 'Ücretsiz mağaza modu açık: satıcılardan paket/abonelik ücreti alınmaz, aylık ücret 0 olmalı. Satıcılar yalnızca gerçekleşen satışlardan komisyon öder.'];
		}

		$row = ['name' => $name, 'price_monthly' => round($price, 2), 'active' => !empty($d['active']) ? 1 : 0, 'sort_order' => max(0, min(999, (int) ($d['sort_order'] ?? 0)))];

		foreach (['max_products', 'max_xml_connections', 'max_marketplace_accounts'] as $k) {
			$v = trim((string) ($d[$k] ?? ''));

			if ($v === '') {
				$row[$k] = null;
			} elseif (ctype_digit($v) && (int) $v <= 10000000) {
				$row[$k] = (int) $v;
			} else {
				return ['success' => false, 'message' => 'Limitler boş (sınırsız) ya da pozitif tam sayı olmalı.'];
			}
		}

		if (self::get($code)) {
			if ($code === self::DEFAULT_CODE && $row['active'] === 0) {
				return ['success' => false, 'message' => 'Varsayılan paket pasifleştirilemez.'];
			}

			DB::update('rafimda_plans', $row, 'code = :c', ['c' => $code]);
		} else {
			DB::insert('rafimda_plans', $row + ['code' => $code]);
		}

		return ['success' => true, 'message' => 'Paket kaydedildi.'];
	}

	/** @param mixed $v */
	private static function num($v): ?float
	{
		$s = str_replace(',', '.', trim((string) $v));

		return $s !== '' && is_numeric($s) ? (float) $s : null;
	}

	/** Satıcıya paket atar (yönetici). Ödeme alınmaz. @return array{success:bool, message:string} */
	public static function assign(int $idVendor, string $code, ?string $expiresAt, int $idAdmin, string $note = ''): array
	{
		$v = DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$idVendor]);
		$plan = self::get($code);

		if (!$v || !$plan || !(int) $plan['active']) {
			return ['success' => false, 'message' => 'Satıcı veya etkin paket bulunamadı.'];
		}

		$exp = null;

		if ($expiresAt !== null && trim($expiresAt) !== '') {
			$t = strtotime(trim($expiresAt) . ' 23:59:59');

			if ($t === false || $t < time()) {
				return ['success' => false, 'message' => 'Bitiş tarihi geçerli ve gelecekte olmalı.'];
			}

			$exp = date('Y-m-d H:i:s', $t);
		}

		if ($code === self::DEFAULT_CODE) {
			$exp = null;
		}

		DB::update('rafimda_vendors', ['plan_code' => $code, 'plan_expires_at' => $exp], 'id_vendor = :v', ['v' => $idVendor]);
		DB::insert('rafimda_plan_log', ['id_vendor' => $idVendor, 'old_plan' => (string) $v['plan_code'], 'new_plan' => $code, 'expires_at' => $exp, 'id_admin' => $idAdmin, 'note' => mb_substr(trim(strip_tags($note)), 0, 255)]);
		RvAudit::log($idVendor, 'vendor.plan', 'vendor', $idVendor, ['from' => $v['plan_code'], 'to' => $code, 'expires' => $exp], $idAdmin);

		return ['success' => true, 'message' => 'Paket atandı.'];
	}

	/** Geçerli paket (süresi dolmuşsa 'free'). @return array<string,mixed> */
	public static function effective(int $idVendor): array
	{
		self::ensureDefaults();
		$v = DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$idVendor]);
		$code = $v ? (string) ($v['plan_code'] ?: self::DEFAULT_CODE) : self::DEFAULT_CODE;
		$exp = $v ? (string) ($v['plan_expires_at'] ?? '') : '';
		$expired = $exp !== '' && strtotime($exp) !== false && strtotime($exp) < time();
		$plan = (!$expired ? self::get($code) : null) ?: self::get(self::DEFAULT_CODE);

		return ['plan' => $plan, 'expired' => $expired, 'expires_at' => $expired ? '' : $exp, 'assigned' => $code];
	}

	/** @return array{products:int, xml:int, marketplaces:int} */
	public static function usage(int $idVendor): array
	{
		// XML Hub / Pazaryeri Hub kurulu değilse tablolar yoktur: sorgulayıp hata günlüğü üretme
		$x = class_exists('XhRepo', false) ? DB::getValue('SELECT COUNT(*) FROM xh_connections WHERE id_vendor = ?', [$idVendor]) : 0;
		$m = class_exists('MhRepo', false) ? DB::getValue('SELECT COUNT(*) FROM mh_accounts WHERE id_vendor = ?', [$idVendor]) : 0;

		return ['products' => (int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_products WHERE id_vendor = ?', [$idVendor]), 'xml' => $x === false ? 0 : (int) $x, 'marketplaces' => $m === false ? 0 : (int) $m];
	}

	/** Yeni ürün kotası. @return array{ok:bool, message:string} */
	public static function checkProductQuota(int $idVendor, int $adding = 1): array
	{
		$p = self::effective($idVendor)['plan'];
		$max = $p['max_products'] ?? null;

		if ($max === null || $max === '') {
			return ['ok' => true, 'message' => ''];
		}

		$u = self::usage($idVendor)['products'];

		return $u + $adding <= (int) $max ? ['ok' => true, 'message' => ''] : ['ok' => false, 'message' => (self::freeMode()
			? 'Mağazanızın ürün limitine ulaştınız (' . (int) $max . '). Limit artırımı ücretsizdir: Destek bölümünden talep oluşturun.'
			: 'Paketinizin ürün limitine ulaştınız (' . (int) $max . '). Daha yüksek paket için mağaza yönetimiyle iletişime geçin.')];
	}

	/** XML bağlantısı / pazaryeri hesabı kotası. $kind: xml | marketplaces. @return array{ok:bool, message:string} */
	public static function checkQuota(int $idVendor, string $kind): array
	{
		$col = $kind === 'xml' ? 'max_xml_connections' : 'max_marketplace_accounts';
		$max = self::effective($idVendor)['plan'][$col] ?? null;

		if ($max === null || $max === '') {
			return ['ok' => true, 'message' => ''];
		}

		return self::usage($idVendor)[$kind] < (int) $max ? ['ok' => true, 'message' => ''] : ['ok' => false, 'message' => (self::freeMode() ? 'Mağazanızın ' : 'Paketinizin ') . ($kind === 'xml' ? 'XML bağlantı' : 'pazaryeri hesabı') . ' limitine ulaştınız (' . (int) $max . ').' . (self::freeMode() ? ' Limit artırımı ücretsizdir: Destek bölümünden talep oluşturun.' : '')];
	}
}
