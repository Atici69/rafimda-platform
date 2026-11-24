<?php

/**
 * Ticari kampanyalar: "ilk ay ücretsiz vitrin" ve yapılandırılabilir komisyon kampanyaları.
 *
 * ÖDEME İŞLEMİ YOKTUR. Vitrin ücreti yalnızca "borç kaydı"dır (rc_charges); tahsilat yönetici tarafından elle (havale/EFT vb.)
 * "ödendi" işaretlenir. Komisyon kampanyası yalnızca YENİ siparişlerin komisyon oranını etkiler; geçmiş kayıtlar değişmez.
 * Her hata durumunda varsayılan (kampanyasız) davranışa dönülür; sipariş akışı asla bozulmaz.
 */
class RcCampaign
{
	public const TYPE_SHOWCASE = 'free_showcase';
	public const TYPE_COMMISSION = 'commission_discount';

	public const TYPES = [
		self::TYPE_SHOWCASE => 'İlk ay ücretsiz vitrin',
		self::TYPE_COMMISSION => 'Komisyon kampanyası',
	];

	public const MODES = [
		'fixed_rate' => 'Sabit komisyon oranı (%)',
		'percent_off' => 'Mevcut orandan yüzde indirim',
	];

	public const FEE_SETTING = 'RAFIMDA_SHOWCASE_FEE';

	/** @var array<int,int> satıcı => son hesaplamada uygulanan katılım (komisyon sipariş kaydıyla birlikte işlenir) */
	private static array $pendingUse = [];

	private static function now(?int $ts = null): string
	{
		return date('Y-m-d H:i:s', $ts ?? time());
	}

	private static function money(float $v): float
	{
		return round($v, 2);
	}

	/** @return list<array<string,mixed>> */
	public static function all(): array
	{
		return DB::execute('SELECT * FROM rc_campaigns ORDER BY active DESC, id_campaign DESC') ?: [];
	}

	/** @return array<string,mixed>|null */
	public static function get(int $id): ?array
	{
		$r = DB::getRowSafe('rc_campaigns', 'id_campaign = ?', [$id]);

		return $r ?: null;
	}

	private static function date(?string $s): ?string
	{
		$s = trim((string) $s);

		if ($s === '') {
			return null;
		}

		$t = strtotime($s);

		return $t === false ? '' : date('Y-m-d H:i:s', $t);
	}

	/** @param array<string,mixed> $d @return array{success:bool,message:string,id:int} */
	public static function save(array $d, int $idCampaign = 0): array
	{
		$fail = static fn(string $m): array => ['success' => false, 'message' => $m, 'id' => 0];
		$code = strtolower(trim((string) ($d['code'] ?? '')));
		$title = mb_substr(trim(strip_tags((string) ($d['title'] ?? ''))), 0, 120);
		$type = (string) ($d['type'] ?? '');

		if (!preg_match('/^[a-z0-9_-]{2,32}$/', $code)) {
			return $fail('Kod 2-32 karakter: küçük harf, rakam, - veya _.');
		}

		if (mb_strlen($title) < 3) {
			return $fail('Başlık en az 3 karakter olmalı.');
		}

		if (!isset(self::TYPES[$type])) {
			return $fail('Geçersiz kampanya türü.');
		}

		$start = self::date($d['starts_at'] ?? '');
		$end = self::date($d['ends_at'] ?? '');

		if ($start === '' || $end === '') {
			return $fail('Tarih biçimi geçersiz.');
		}

		if ($start !== null && $end !== null && $end < $start) {
			return $fail('Bitiş tarihi başlangıçtan önce olamaz.');
		}

		$days = (int) ($d['benefit_days'] ?? 30);

		if ($days < 0 || $days > 730) {
			return $fail('Avantaj süresi 0-730 gün olmalı (0: kampanya bitişine kadar).');
		}

		if ($days === 0 && $end === null) {
			return $fail('Avantaj süresi 0 ise kampanya bitiş tarihi zorunludur.');
		}

		$mode = (string) ($d['calc_mode'] ?? 'fixed_rate');
		$value = (float) str_replace(',', '.', (string) ($d['value'] ?? '0'));
		$min = (float) str_replace(',', '.', (string) ($d['min_rate'] ?? '0'));

		if ($type === self::TYPE_COMMISSION) {
			if (!isset(self::MODES[$mode])) {
				return $fail('Geçersiz hesaplama kuralı.');
			}

			if ($value < 0 || $value > 100 || $min < 0 || $min > 100) {
				return $fail('Oran 0-100 arasında olmalı.');
			}

			if ($mode === 'percent_off' && $value <= 0) {
				return $fail('İndirim yüzdesi 0\'dan büyük olmalı.');
			}
		} else {
			$mode = 'fixed_rate';
			$value = 0.0;
			$min = 0.0;
		}

		$maxOrders = trim((string) ($d['max_orders'] ?? ''));
		$maxOrders = $maxOrders === '' ? null : (int) $maxOrders;

		if ($maxOrders !== null && ($maxOrders < 1 || $maxOrders > 1000000)) {
			return $fail('Sipariş sınırı 1 veya daha büyük olmalı (boş: sınırsız).');
		}

		$nv = trim((string) ($d['new_vendor_days'] ?? ''));
		$nv = $nv === '' ? null : (int) $nv;

		if ($nv !== null && ($nv < 1 || $nv > 3650)) {
			return $fail('Yeni satıcı süresi 1-3650 gün olmalı (boş: herkes).');
		}

		$plans = [];

		foreach (preg_split('/[\s,;]+/', strtolower((string) ($d['plan_codes'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
			if (!preg_match('/^[a-z0-9_-]{1,32}$/', $p)) {
				return $fail('Paket kodu geçersiz: ' . mb_substr($p, 0, 20));
			}

			$plans[] = $p;
		}

		$ids = [];

		foreach (preg_split('/[\s,;]+/', (string) ($d['vendor_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i) {
			if (!ctype_digit($i) || (int) $i <= 0) {
				return $fail('Satıcı numaraları yalnızca pozitif tam sayı olmalı.');
			}

			$ids[] = (int) $i;
		}

		$row = [
			'code' => $code, 'type' => $type, 'title' => $title, 'description' => mb_substr(trim(strip_tags((string) ($d['description'] ?? ''))), 0, 255),
			'active' => !empty($d['active']) ? 1 : 0, 'starts_at' => $start, 'ends_at' => $end, 'benefit_days' => $days,
			'calc_mode' => $mode, 'value' => self::money($value), 'min_rate' => self::money($min), 'max_orders' => $maxOrders, 'new_vendor_days' => $nv,
			'plan_codes' => implode(',', array_values(array_unique($plans))), 'vendor_ids' => mb_substr(implode(',', array_values(array_unique($ids))), 0, 500),
			'auto_enroll' => !empty($d['auto_enroll']) ? 1 : 0, 'vendor_can_join' => !empty($d['vendor_can_join']) ? 1 : 0,
		];

		$dup = DB::getRowSafe('rc_campaigns', 'code = ?', [$code]);

		if ($dup && (int) $dup['id_campaign'] !== $idCampaign) {
			return $fail('Bu kod başka bir kampanyada kullanılıyor.');
		}

		if ($idCampaign > 0) {
			if (!self::get($idCampaign)) {
				return $fail('Kampanya bulunamadı.');
			}

			if (DB::update('rc_campaigns', $row, 'id_campaign = :i', ['i' => $idCampaign]) === false) {
				return $fail('Kaydedilemedi.');
			}

			return ['success' => true, 'message' => 'Kampanya güncellendi (mevcut katılımların süresi değişmez).', 'id' => $idCampaign];
		}

		$id = (int) DB::insert('rc_campaigns', $row);

		return $id > 0 ? ['success' => true, 'message' => 'Kampanya oluşturuldu.', 'id' => $id] : $fail('Kaydedilemedi.');
	}

	public static function setActive(int $id, bool $on): bool
	{
		return self::get($id) !== null && DB::update('rc_campaigns', ['active' => $on ? 1 : 0], 'id_campaign = :i', ['i' => $id]) !== false;
	}

	/**
	 * Satıcı bu kampanyaya katılabilir mi? (kampanya penceresi + uygunluk kuralları)
	 *
	 * @param array<string,mixed> $c
	 * @return array{ok:bool,reason:string}
	 */
	public static function eligibility(array $c, int $idVendor, ?int $ts = null): array
	{
		$now = self::now($ts);
		$v = DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$idVendor]);

		if (!$v) {
			return ['ok' => false, 'reason' => 'Satıcı bulunamadı.'];
		}

		if ((string) $v['status'] !== 'active') {
			return ['ok' => false, 'reason' => 'Mağaza aktif değil.'];
		}

		if (!(int) $c['active']) {
			return ['ok' => false, 'reason' => 'Kampanya kapalı.'];
		}

		if (!empty($c['starts_at']) && $now < (string) $c['starts_at']) {
			return ['ok' => false, 'reason' => 'Kampanya henüz başlamadı.'];
		}

		if (!empty($c['ends_at']) && $now > (string) $c['ends_at']) {
			return ['ok' => false, 'reason' => 'Kampanya sona erdi.'];
		}

		if ($c['new_vendor_days'] !== null && $c['new_vendor_days'] !== '') {
			$since = (string) ($v['approved_at'] ?: $v['date_add']);
			$t = strtotime($since);

			if ($t === false || ($ts ?? time()) - $t > (int) $c['new_vendor_days'] * 86400) {
				return ['ok' => false, 'reason' => 'Yalnızca ilk ' . (int) $c['new_vendor_days'] . ' gün içindeki yeni satıcılar için geçerli.'];
			}
		}

		if ((string) $c['plan_codes'] !== '') {
			$plan = class_exists('RvPlans') ? (string) (RvPlans::effective($idVendor)['plan']['code'] ?? 'free') : (string) $v['plan_code'];

			if (!in_array($plan, explode(',', (string) $c['plan_codes']), true)) {
				return ['ok' => false, 'reason' => 'Paketiniz bu kampanya için uygun değil.'];
			}
		}

		if ((string) $c['vendor_ids'] !== '' && !in_array((string) $idVendor, explode(',', (string) $c['vendor_ids']), true)) {
			return ['ok' => false, 'reason' => 'Bu kampanya size tanımlı değil.'];
		}

		return ['ok' => true, 'reason' => ''];
	}

	/**
	 * Katılım oluşturur. $source: auto|vendor (uygunluk kuralları uygulanır) | admin (yönetici; kural/pencere atlanır ama kampanya var olmalı).
	 *
	 * @return array{success:bool,message:string,id:int}
	 */
	public static function enroll(int $idCampaign, int $idVendor, string $source = 'auto', ?int $ts = null): array
	{
		$c = self::get($idCampaign);

		if (!$c || !DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$idVendor])) {
			return ['success' => false, 'message' => 'Kampanya veya satıcı bulunamadı.', 'id' => 0];
		}

		if (!in_array($source, ['auto', 'vendor', 'admin'], true)) {
			return ['success' => false, 'message' => 'Geçersiz kaynak.', 'id' => 0];
		}

		$old = DB::getRowSafe('rc_enrollments', 'id_campaign = ? AND id_vendor = ?', [$idCampaign, $idVendor]);

		if ($old) {
			return ['success' => false, 'message' => 'Bu satıcı kampanyaya zaten katıldı (tek kullanımlık).', 'id' => (int) $old['id_enrollment']];
		}

		if ($source !== 'admin') {
			if ($source === 'vendor' && !(int) $c['vendor_can_join']) {
				return ['success' => false, 'message' => 'Bu kampanyaya satıcı başvurusu kapalı.', 'id' => 0];
			}

			$e = self::eligibility($c, $idVendor, $ts);

			if (!$e['ok']) {
				return ['success' => false, 'message' => $e['reason'], 'id' => 0];
			}
		}

		$t = $ts ?? time();
		$days = (int) $c['benefit_days'];
		$expires = $days > 0 ? date('Y-m-d H:i:s', $t + $days * 86400) : (!empty($c['ends_at']) ? (string) $c['ends_at'] : null);
		$id = (int) DB::insert('rc_enrollments', ['id_campaign' => $idCampaign, 'id_vendor' => $idVendor, 'started_at' => self::now($t), 'expires_at' => $expires, 'orders_used' => 0, 'source' => $source, 'status' => 'active']);

		if ($id <= 0) {
			return ['success' => false, 'message' => 'Katılım kaydedilemedi.', 'id' => 0];
		}

		if (class_exists('RvAudit')) {
			RvAudit::log($idVendor, 'campaign.enroll', 'campaign', $idCampaign, ['source' => $source, 'expires' => $expires]);
		}

		return ['success' => true, 'message' => 'Kampanyaya katılım başladı.', 'id' => $id];
	}

	public static function endEnrollment(int $idEnrollment, int $idAdmin = 0): bool
	{
		$e = DB::getRowSafe('rc_enrollments', 'id_enrollment = ?', [$idEnrollment]);

		if (!$e || DB::update('rc_enrollments', ['status' => 'cancelled'], 'id_enrollment = :i', ['i' => $idEnrollment]) === false) {
			return false;
		}

		if (class_exists('RvAudit')) {
			RvAudit::log((int) $e['id_vendor'], 'campaign.cancel', 'campaign', (int) $e['id_campaign'], [], $idAdmin);
		}

		return true;
	}

	/** Süresi/limiti dolan katılımın etkin olup olmadığı. @param array<string,mixed> $e */
	public static function isLive(array $e, ?int $ts = null): bool
	{
		if ((string) $e['status'] !== 'active') {
			return false;
		}

		$now = self::now($ts);

		if ((string) $e['started_at'] > $now || (!empty($e['expires_at']) && (string) $e['expires_at'] <= $now)) {
			return false;
		}

		if (isset($e['max_orders']) && $e['max_orders'] !== null && $e['max_orders'] !== '' && (int) $e['orders_used'] >= (int) $e['max_orders']) {
			return false;
		}

		return true;
	}

	/** @return list<array<string,mixed>> etkin (süresi dolmamış, limiti dolmamış) katılımlar; kampanya alanları dahil */
	public static function liveEnrollments(int $idVendor, string $type, ?int $ts = null): array
	{
		$rows = DB::execute(
			'SELECT e.*, c.code, c.type, c.title, c.calc_mode, c.value, c.min_rate, c.max_orders, c.benefit_days FROM rc_enrollments e INNER JOIN rc_campaigns c ON c.id_campaign = e.id_campaign WHERE e.id_vendor = ? AND c.type = ? ORDER BY e.id_enrollment ASC',
			[$idVendor, $type]
		) ?: [];

		return array_values(array_filter($rows, static fn(array $e): bool => self::isLive($e, $ts)));
	}

	/** Otomatik katılımlı kampanyalara (uygunsa) satıcıyı alır. */
	public static function autoEnroll(int $idVendor, string $type, ?int $ts = null): void
	{
		$cs = DB::execute('SELECT * FROM rc_campaigns WHERE type = ? AND active = 1 AND auto_enroll = 1 ORDER BY id_campaign ASC', [$type]) ?: [];

		foreach ($cs as $c) {
			if (!DB::getRowSafe('rc_enrollments', 'id_campaign = ? AND id_vendor = ?', [(int) $c['id_campaign'], $idVendor])) {
				self::enroll((int) $c['id_campaign'], $idVendor, 'auto', $ts);
			}
		}
	}

	/**
	 * Komisyon hesabı. Birden çok etkin kampanya varsa en düşük sonuç oran kazanır; oran asla taban orandan yüksek olamaz.
	 *
	 * @return array{rate:float,base:float,id_campaign:int,id_enrollment:int,explain:string}
	 */
	public static function commissionDetail(int $idVendor, float $base, ?int $ts = null, bool $enroll = true): array
	{
		$out = ['rate' => $base, 'base' => $base, 'id_campaign' => 0, 'id_enrollment' => 0, 'explain' => 'Kampanya yok; standart oran %' . self::fmt($base)];

		if ($enroll) {
			self::autoEnroll($idVendor, self::TYPE_COMMISSION, $ts);
		}

		foreach (self::liveEnrollments($idVendor, self::TYPE_COMMISSION, $ts) as $e) {
			$r = (string) $e['calc_mode'] === 'percent_off' ? $base * (1 - (float) $e['value'] / 100) : (float) $e['value'];
			$r = max((float) $e['min_rate'], $r);
			$r = round(min($base, max(0.0, $r)), 2);

			if ($r < $out['rate']) {
				$out = ['rate' => $r, 'base' => $base, 'id_campaign' => (int) $e['id_campaign'], 'id_enrollment' => (int) $e['id_enrollment'],
					'explain' => $e['title'] . ': ' . ((string) $e['calc_mode'] === 'percent_off' ? '%' . self::fmt((float) $e['value']) . ' indirim' : 'sabit %' . self::fmt((float) $e['value'])) . ((float) $e['min_rate'] > 0 ? ' (alt sınır %' . self::fmt((float) $e['min_rate']) . ')' : '') . ' → %' . self::fmt($r)];
			}
		}

		return $out;
	}

	private static function fmt(float $v): string
	{
		return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
	}

	/** RvOrders::commissionRateFor tarafından çağrılır. Hata olursa taban oran döner. @param array<string,mixed> $vendor */
	public static function rateFor(array $vendor, float $base, bool $forOrder = true): float
	{
		try {
			$idVendor = (int) ($vendor['id_vendor'] ?? 0);

			if ($idVendor <= 0) {
				return $base;
			}

			$d = self::commissionDetail($idVendor, $base, null, $forOrder);

			if ($forOrder) {
				self::$pendingUse[$idVendor] = $d['id_enrollment'];
			}

			return $d['rate'];
		} catch (Throwable $e) {
			error_log('rafimda-campaign rateFor: ' . $e->getMessage());

			return $base;
		}
	}

	/** Sipariş kaydı BAŞARIYLA oluştuktan sonra: katılımın sipariş sayacını artırır; limit dolduysa sonlandırır. */
	public static function commitUse(int $idVendor): void
	{
		$idEnrollment = self::$pendingUse[$idVendor] ?? 0;
		unset(self::$pendingUse[$idVendor]);

		if ($idEnrollment <= 0) {
			return;
		}

		try {
			DB::execute('UPDATE rc_enrollments SET orders_used = orders_used + 1 WHERE id_enrollment = ?', [$idEnrollment]);
		} catch (Throwable $e) {
			error_log('rafimda-campaign commitUse: ' . $e->getMessage());
		}
	}

	/** Sipariş kaydı başarısız olursa bekleyen kullanımı temizler. */
	public static function discardUse(int $idVendor): void
	{
		unset(self::$pendingUse[$idVendor]);
	}

	// ------------------------------------------------------------------ Vitrin

	public static function showcaseFee(): float
	{
		$v = class_exists('Settings') ? (string) Settings::get(self::FEE_SETTING) : '';

		return max(0.0, self::money((float) str_replace(',', '.', $v)));
	}

	public static function setShowcaseFee(float $fee): bool
	{
		if ($fee < 0 || $fee > 1000000) {
			return false;
		}

		Settings::set(self::FEE_SETTING, (string) self::money($fee));

		return true;
	}

	/**
	 * Satıcının vitrin şartları. Ücret 0 ise herkese ücretsiz. Ücret > 0 ise yalnızca etkin "ilk ay ücretsiz vitrin" katılımı ücreti kaldırır.
	 *
	 * @return array{free:bool,fee:float,id_campaign:int,expires_at:string,reason:string}
	 */
	public static function showcaseTerms(int $idVendor, ?int $ts = null): array
	{
		$fee = self::showcaseFee();

		if ($fee <= 0) {
			return ['free' => true, 'fee' => 0.0, 'id_campaign' => 0, 'expires_at' => '', 'reason' => 'Vitrin ücreti tanımlı değil.'];
		}

		self::autoEnroll($idVendor, self::TYPE_SHOWCASE, $ts);
		$live = self::liveEnrollments($idVendor, self::TYPE_SHOWCASE, $ts);

		if ($live !== []) {
			return ['free' => true, 'fee' => 0.0, 'id_campaign' => (int) $live[0]['id_campaign'], 'expires_at' => (string) ($live[0]['expires_at'] ?? ''), 'reason' => $live[0]['title']];
		}

		return ['free' => false, 'fee' => $fee, 'id_campaign' => 0, 'expires_at' => '', 'reason' => 'Ücretsiz dönem yok veya sona erdi.'];
	}

	/** Vitrin talebi açıldığında çağrılır: ücret borcu (due) veya kampanya muafiyeti (waived) kaydı. */
	public static function onShowcaseRequest(int $idVendor, int $idItem, ?int $ts = null): void
	{
		try {
			$t = self::showcaseTerms($idVendor, $ts);
			$fee = self::showcaseFee();

			if ($fee <= 0 || $idItem <= 0 || DB::getRowSafe('rc_charges', 'id_item = ?', [$idItem])) {
				return;
			}

			DB::insert('rc_charges', [
				'id_vendor' => $idVendor, 'id_item' => $idItem, 'amount' => $t['free'] ? 0 : $t['fee'], 'status' => $t['free'] ? 'waived' : 'due',
				'id_campaign' => $t['id_campaign'], 'note' => $t['free'] ? 'Kampanya: ' . $t['reason'] : 'Vitrin ücreti', 'date_add' => self::now($ts), 'date_upd' => self::now($ts),
			]);
		} catch (Throwable $e) {
			error_log('rafimda-campaign onShowcaseRequest: ' . $e->getMessage());
		}
	}

	/** Ücreti ödenmemiş (due) bir vitrin kaydı onaylanamaz. */
	public static function canApprove(int $idItem): bool
	{
		$c = DB::getRowSafe('rc_charges', 'id_item = ?', [$idItem]);

		return !$c || (string) $c['status'] !== 'due';
	}

	/** Talep reddedilir/geri çekilirse bekleyen borç iptal edilir (yalnızca due). */
	public static function voidForItem(int $idItem): void
	{
		try {
			DB::execute("UPDATE rc_charges SET status = 'void', date_upd = ? WHERE id_item = ? AND status = 'due'", [self::now(), $idItem]);
		} catch (Throwable $e) {
			error_log('rafimda-campaign voidForItem: ' . $e->getMessage());
		}
	}

	/** Yönetici: tahsilatı elle "ödendi" işaretler (ödeme işlemi YAPILMAZ). */
	public static function markSettled(int $idCharge, string $note = '', int $idAdmin = 0): bool
	{
		$c = DB::getRowSafe('rc_charges', 'id_charge = ?', [$idCharge]);

		if (!$c || (string) $c['status'] !== 'due') {
			return false;
		}

		$ok = DB::update('rc_charges', ['status' => 'settled', 'note' => mb_substr(trim(strip_tags($note)) ?: (string) $c['note'], 0, 255), 'date_upd' => self::now()], 'id_charge = :i', ['i' => $idCharge]) !== false;

		if ($ok && class_exists('RvAudit')) {
			RvAudit::log((int) $c['id_vendor'], 'campaign.charge_settled', 'charge', $idCharge, ['amount' => $c['amount']], $idAdmin);
		}

		return $ok;
	}

	/** @return list<array<string,mixed>> */
	public static function charges(string $status = '', int $limit = 100): array
	{
		$limit = max(1, min(500, $limit));
		$sql = 'SELECT ch.*, v.store_name FROM rc_charges ch LEFT JOIN rafimda_vendors v ON v.id_vendor = ch.id_vendor';
		$args = [];

		if (in_array($status, ['due', 'waived', 'settled', 'void'], true)) {
			$sql .= ' WHERE ch.status = ?';
			$args[] = $status;
		}

		return DB::execute($sql . ' ORDER BY ch.id_charge DESC LIMIT ' . $limit, $args) ?: [];
	}

	/** @return list<array<string,mixed>> */
	public static function enrollments(int $limit = 200): array
	{
		$limit = max(1, min(500, $limit));
		$rows = DB::execute('SELECT e.*, c.title, c.type, c.max_orders, v.store_name FROM rc_enrollments e INNER JOIN rc_campaigns c ON c.id_campaign = e.id_campaign LEFT JOIN rafimda_vendors v ON v.id_vendor = e.id_vendor ORDER BY e.id_enrollment DESC LIMIT ' . $limit) ?: [];

		foreach ($rows as &$r) {
			$r['live'] = self::isLive($r);
		}

		return $rows;
	}

	/**
	 * Satıcı paneli için: katıldığı kampanyalar + katılabileceği (satıcı başvurusuna açık ve uygun) kampanyalar.
	 *
	 * @return array{mine:list<array<string,mixed>>,joinable:list<array<string,mixed>>,showcase:array<string,mixed>}
	 */
	public static function forVendor(int $idVendor, ?int $ts = null): array
	{
		$mine = DB::execute('SELECT e.*, c.title, c.type, c.max_orders, c.calc_mode, c.value, c.min_rate FROM rc_enrollments e INNER JOIN rc_campaigns c ON c.id_campaign = e.id_campaign WHERE e.id_vendor = ? ORDER BY e.id_enrollment DESC', [$idVendor]) ?: [];

		foreach ($mine as &$m) {
			$m['live'] = self::isLive($m, $ts);
		}

		unset($m);
		$joined = array_map('intval', array_column($mine, 'id_campaign'));
		$joinable = [];

		foreach (DB::execute('SELECT * FROM rc_campaigns WHERE active = 1 AND vendor_can_join = 1 ORDER BY id_campaign ASC') ?: [] as $c) {
			if (!in_array((int) $c['id_campaign'], $joined, true) && self::eligibility($c, $idVendor, $ts)['ok']) {
				$joinable[] = $c;
			}
		}

		return ['mine' => $mine, 'joinable' => $joinable, 'showcase' => self::showcaseTerms($idVendor, $ts)];
	}

	/**
	 * Yönetici simülatörü: seçili satıcı ve sipariş tutarı için komisyon sonucu (hiçbir kayıt yazmaz; otomatik katılım yapmaz).
	 *
	 * @return array{ok:bool,message:string,rows:list<array<string,mixed>>}
	 */
	public static function simulate(int $idVendor, float $amount, ?int $ts = null): array
	{
		$v = DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$idVendor]);

		if (!$v || $amount < 0 || $amount > 100000000) {
			return ['ok' => false, 'message' => 'Satıcı numarası veya tutar geçersiz.', 'rows' => []];
		}

		$base = class_exists('RvOrders') ? RvOrders::baseCommissionFor($v) : 10.0;
		$live = self::liveEnrollments($idVendor, self::TYPE_COMMISSION, $ts);
		$rows = [['label' => 'Standart (kampanyasız)', 'rate' => $base, 'commission' => self::money($amount * $base / 100), 'earning' => self::money($amount - self::money($amount * $base / 100))]];
		$best = null;

		foreach ($live as $e) {
			$r = (string) $e['calc_mode'] === 'percent_off' ? $base * (1 - (float) $e['value'] / 100) : (float) $e['value'];
			$r = round(min($base, max((float) $e['min_rate'], max(0.0, $r))), 2);
			$c = self::money($amount * $r / 100);
			$rows[] = ['label' => $e['title'] . ' (katılım #' . $e['id_enrollment'] . ')', 'rate' => $r, 'commission' => $c, 'earning' => self::money($amount - $c)];

			if ($best === null || $r < $best) {
				$best = $r;
			}
		}

		$applied = $best !== null && $best < $base ? $best : $base;
		$c = self::money($amount * $applied / 100);
		$rows[] = ['label' => 'UYGULANAN (en düşük oran kazanır)', 'rate' => $applied, 'commission' => $c, 'earning' => self::money($amount - $c)];

		return ['ok' => true, 'message' => '', 'rows' => $rows];
	}
}
