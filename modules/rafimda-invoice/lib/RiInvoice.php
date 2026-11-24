<?php

/**
 * Rafımda fatura ve e-belge altyapısı (pazaryeri modeli).
 * - SATIŞ faturasını ürünün SATICISI düzenler (issuer=vendor); Rafımda'nın kendi sattığı ürünlerde Rafımda düzenler (kind=direct).
 * - Rafımda'nın satıcıya kestiği KOMİSYON/HİZMET faturası ayrıdır (kind=service). Her siparişin faturası Rafımda adına kesilmez.
 * - Çok satıcılı siparişte satıcı başına ayrı fatura. Mükerrer engeli: benzersiz idem_key (+ gönderilmiş belge yeniden gönderilmez).
 * - Fatura kesim zamanı yapılandırılabilir (manuel / ödeme / kargo / teslim); varsayılan MANUEL — sipariş oluşması tek başına kesim zamanı sayılmaz.
 * - KDV oranları kodda sabit değildir: ürünün KDV alanı, yoksa yönetici ayarı; tanımsızsa fatura hazırlanmaz.
 * - CANLI gönderim varsayılan KAPALI: yalnızca RAFIMDA_INVOICE_LIVE=1 + gerçekten uygulanmış bağdaştırıcı + canlı hesap ile. Test ortamında mali değeri olmayan belge üretilir.
 * - İptal/iade belgeyi SİLMEZ; durum ve ilişkili kayıtla izlenir. Token/şifre loglanmaz.
 * Vergisel varsayımlar mali müşavir onayı olmadan kesin kural değildir; bu yüzden belirsiz durumlar "hazır değil" olarak bırakılır.
 */
final class RiInvoice
{
	public const STATUSES = ['draft' => 'Taslak (eksik bilgi)', 'ready' => 'Gönderime hazır', 'sent' => 'Gönderildi', 'accepted' => 'Kabul edildi', 'rejected' => 'Reddedildi',
		'error' => 'Hata', 'cancelled' => 'İptal edildi'];
	public const KINDS = ['sale' => 'Satıcı satış faturası', 'direct' => 'Rafımda satış faturası', 'service' => 'Rafımda hizmet/komisyon faturası', 'manual' => 'Manuel fatura', 'return' => 'İade belgesi kaydı'];
	public const DOC_TYPES = ['efatura' => 'e-Fatura', 'earsiv' => 'e-Arşiv', 'paper' => 'Manuel (kâğıt/harici)'];
	public const TRIGGERS = ['manual' => 'Yalnızca elle', 'paid' => 'Ödeme alındığında', 'shipped' => 'Kargoya verildiğinde', 'delivered' => 'Teslim edildiğinde'];
	/** FriSay sipariş durumları: 1 bekliyor, 2 hazırlanıyor, 3 kargoda, 4 teslim, 5 iptal, 6 iade, 7 iade bekliyor, 8 ödendi */
	private const TRIGGER_STATES = ['paid' => [2, 3, 4, 8], 'shipped' => [3, 4], 'delivered' => [4]];
	public const ANON_TCKN = '11111111111'; // T.C. kimlik no bildirmeyen nihai tüketici için yaygın uygulama; mali müşavirle doğrulayın

	public static ?string $storageOverride = null;

	/** @return array<string,RiAdapter> */
	public static function adapters(): array
	{
		return ['test' => new RiTestAdapter(), 'qnb' => new RiQnbAdapter()];
	}

	public static function setting(string $k, string $default = ''): string
	{
		$v = (string) Settings::get('RAFIMDA_INVOICE_' . $k);

		return $v === '' ? $default : $v;
	}

	public static function liveEnabled(): bool
	{
		return self::setting('LIVE') === '1';
	}

	// ---------- Entegratör hesapları ----------

	public static function account(string $ownerType, int $idVendor = 0): ?array
	{
		return DB::getRowSafe('ri_accounts', 'owner_type = ? AND id_vendor = ?', [$ownerType === 'vendor' ? 'vendor' : 'platform', $ownerType === 'vendor' ? $idVendor : 0]) ?: null;
	}

	/** Şifre boş bırakılırsa mevcut şifre korunur. Canlı ortam yalnızca uygulanmış bağdaştırıcıda seçilebilir. */
	public static function saveAccount(string $ownerType, int $idVendor, array $in): array
	{
		$ownerType = $ownerType === 'vendor' ? 'vendor' : 'platform';
		$idVendor = $ownerType === 'vendor' ? $idVendor : 0;
		$code = (string) ($in['integrator'] ?? 'test');
		$adapters = self::adapters();

		if (!isset($adapters[$code])) {
			return ['success' => false, 'message' => 'Geçersiz entegratör.'];
		}

		$env = ($in['environment'] ?? 'test') === 'live' ? 'live' : 'test';

		if ($env === 'live' && (!$adapters[$code]->isImplemented() || $code === 'test')) {
			return ['success' => false, 'message' => 'Bu entegratör için canlı ortam seçilemez (bağdaştırıcı hazır değil veya test bağdaştırıcısı).'];
		}

		$data = ['integrator' => $code, 'environment' => $env, 'username' => mb_substr(trim(strip_tags((string) ($in['username'] ?? ''))), 0, 190),
			'efatura_user' => in_array($in['efatura_user'] ?? 'unknown', ['yes', 'no', 'unknown'], true) ? (string) ($in['efatura_user'] ?? 'unknown') : 'unknown',
			'status' => 'untested', 'last_error' => '', 'date_upd' => date('Y-m-d H:i:s')];
		$secret = (string) ($in['secret'] ?? '');

		if ($secret !== '') {
			$enc = RiCrypt::encrypt($secret);

			if ($enc === '') {
				return ['success' => false, 'message' => 'Şifreleme kullanılamıyor (libsodium).'];
			}

			$data['secret_enc'] = $enc;
		}

		if (self::account($ownerType, $idVendor)) {
			DB::update('ri_accounts', $data, 'owner_type = :o AND id_vendor = :v', ['o' => $ownerType, 'v' => $idVendor]);
		} else {
			DB::insert('ri_accounts', ['owner_type' => $ownerType, 'id_vendor' => $idVendor, 'secret_enc' => $data['secret_enc'] ?? ''] + $data);
		}

		self::event(0, 'account_saved', $ownerType . ($idVendor ? ' #' . $idVendor : '') . ' · ' . $code . ' · ' . $env, false, $ownerType);

		return ['success' => true, 'message' => 'Entegratör bilgileri kaydedildi (şifre şifrelenmiş saklanır).'];
	}

	public static function testAccount(string $ownerType, int $idVendor = 0): array
	{
		$acc = self::account($ownerType, $idVendor);

		if (!$acc) {
			return ['success' => false, 'message' => 'Önce entegratör bilgilerini kaydedin.'];
		}

		$adapter = self::adapters()[$acc['integrator']] ?? null;
		$r = $adapter ? $adapter->testConnection(self::withSecret($acc)) : ['success' => false, 'message' => 'Bağdaştırıcı yok.'];
		DB::update('ri_accounts', ['status' => $r['success'] ? 'ok' : 'failed', 'last_error' => $r['success'] ? '' : mb_substr($r['message'], 0, 500), 'last_test_at' => date('Y-m-d H:i:s')],
			'id_account = :a', ['a' => (int) $acc['id_account']]);
		self::event(0, 'account_test', ($r['success'] ? 'Başarılı: ' : 'Başarısız: ') . $r['message'], !$r['success'], $ownerType);

		return $r;
	}

	/** Şifre yalnızca bağdaştırıcıya verilir; dönüş değerlerine/loglara girmez. */
	private static function withSecret(array $acc): array
	{
		$acc['secret'] = RiCrypt::decrypt((string) ($acc['secret_enc'] ?? ''));
		unset($acc['secret_enc']);

		return $acc;
	}

	/** Arayüz için güvenli görünüm (şifre yok, yalnızca var/yok). */
	public static function accountView(?array $acc): ?array
	{
		if (!$acc) {
			return null;
		}

		$acc['has_secret'] = (string) ($acc['secret_enc'] ?? '') !== '';
		unset($acc['secret_enc']);

		return $acc;
	}

	// ---------- Hazırlama ----------

	/** @return array{0:float|null,1:string} KDV oranı ve kaynağı; null = tanımsız */
	private static function vatRate(array $line): array
	{
		if (isset($line['vat']) && $line['vat'] !== null && $line['vat'] !== '' && (float) $line['vat'] > 0) {
			return [(float) $line['vat'], 'product'];
		}

		$d = self::setting('DEFAULT_VAT');

		return is_numeric($d) && (float) $d > 0 ? [(float) $d, 'setting'] : [null, ''];
	}

	private static function buyerFromOrder(array $o): array
	{
		$corporate = in_array((string) ($o['invoice_type'] ?? ''), ['corporate', 'kurumsal', 'company'], true) || trim((string) ($o['company_name'] ?? '')) !== '';
		$tax = preg_replace('/\D/', '', (string) ($o['tax_number'] ?? '')) ?? '';

		return [
			'buyer_type' => $corporate ? 'company' : 'individual',
			'buyer_name' => mb_substr(trim($corporate && trim((string) $o['company_name']) !== '' ? (string) $o['company_name'] : (string) ($o['customer_name'] ?? '')), 0, 190),
			'buyer_tax_no' => $tax,
			'buyer_tax_office' => mb_substr(trim((string) ($o['tax_office'] ?? '')), 0, 120),
			'buyer_address' => mb_substr(trim(implode(' ', array_filter([(string) ($o['address_text'] ?? ''), (string) ($o['address_district'] ?? ''), (string) ($o['address_city'] ?? '')]))), 0, 500),
			'buyer_email' => mb_substr(trim((string) ($o['customer_email'] ?? '')), 0, 190),
		];
	}

	/** @return list<string> alıcı bilgisi hataları */
	public static function validateBuyer(array $b): array
	{
		$e = [];

		if (mb_strlen($b['buyer_name']) < 3) {
			$e[] = 'Alıcı adı/unvanı eksik.';
		}

		if (mb_strlen($b['buyer_address']) < 5) {
			$e[] = 'Alıcı adresi eksik.';
		}

		if ($b['buyer_type'] === 'company') {
			if (strlen($b['buyer_tax_no']) !== 10 && strlen($b['buyer_tax_no']) !== 11) {
				$e[] = 'Kurumsal alıcı için VKN (10) veya şahıs şirketi için TCKN (11) gerekli.';
			}

			if ($b['buyer_tax_office'] === '') {
				$e[] = 'Kurumsal alıcı için vergi dairesi gerekli.';
			}
		}

		return $e;
	}

	/**
	 * Siparişi düzenleyene göre böler ve her parça için taslak/hazır fatura oluşturur (mükerrer oluşturmaz).
	 * @return array{success:bool,message:string,created:int,skipped:int}
	 */
	public static function prepareForOrder(int $idOrder, string $actor = 'admin', ?int $onlyVendor = null): array
	{
		$o = DB::getRowSafe('orders', 'id_order = ?', [$idOrder]);

		if (!$o) {
			return ['success' => false, 'message' => 'Sipariş bulunamadı.', 'created' => 0, 'skipped' => 0];
		}

		if (in_array((int) $o['status'], [5, 6], true)) {
			return ['success' => false, 'message' => 'İptal/iade edilmiş siparişe satış faturası hazırlanmaz.', 'created' => 0, 'skipped' => 0];
		}

		$items = DB::execute('SELECT od.id_product, od.product_name, od.qty, od.price, od.total, p.vat, vp.id_vendor FROM order_detail od LEFT JOIN products p ON p.id_product = od.id_product
			LEFT JOIN rafimda_vendor_products vp ON vp.id_product = od.id_product WHERE od.id_order = ? ORDER BY od.id_order_detail', [$idOrder]) ?: [];

		if ($items === []) {
			return ['success' => false, 'message' => 'Siparişte kalem yok.', 'created' => 0, 'skipped' => 0];
		}

		$groups = [];

		foreach ($items as $it) {
			$groups[(int) ($it['id_vendor'] ?? 0)][] = $it;
		}

		$buyer = self::buyerFromOrder($o);
		$created = 0;
		$skipped = 0;

		foreach ($groups as $idVendor => $lines) {
			if ($onlyVendor !== null && $idVendor !== $onlyVendor) {
				continue; // satıcı yalnızca kendi kalemlerinin faturasını hazırlar
			}

			$key = $idVendor > 0 ? "sale:$idOrder:$idVendor" : "direct:$idOrder";

			if (DB::getRowSafe('ri_invoices', 'idem_key = ?', [$key])) {
				$skipped++;
				continue; // mükerrer fatura oluşturulmaz
			}

			[$rows, $net, $vat, $gross, $errors] = self::buildLines(array_map(static fn($l) => ['name' => (string) $l['product_name'], 'qty' => (float) $l['qty'], 'gross_total' => (float) $l['total'], 'vat' => $l['vat'] ?? null], $lines));
			$errors = array_merge($errors, self::validateBuyer($buyer));
			$id = (int) DB::insert('ri_invoices', [
				'idem_key' => $key, 'kind' => $idVendor > 0 ? 'sale' : 'direct', 'doc_type' => '', 'issuer_type' => $idVendor > 0 ? 'vendor' : 'platform', 'id_vendor' => $idVendor,
				'id_order' => $idOrder, 'id_user' => (int) $o['id_user'], 'lines' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'net_total' => $net, 'vat_total' => $vat, 'gross_total' => $gross,
				'status' => $errors ? 'draft' : 'ready', 'environment' => 'test', 'last_error' => mb_substr(implode(' ', $errors), 0, 500), 'created_by' => $actor,
				'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s'),
			] + $buyer);

			if ($id > 0) {
				$created++;
				self::event($id, 'prepared', ($errors ? 'Taslak — eksik: ' . implode(' ', $errors) : 'Gönderime hazır') . ' (' . self::KINDS[$idVendor > 0 ? 'sale' : 'direct'] . ')', $errors !== [], $actor);
			}
		}

		if ((float) ($o['shipping'] ?? 0) > 0 && $created > 0) {
			self::event(0, 'shipping_note', "Sipariş #$idOrder kargo ücreti faturaya eklenmedi: kargo bedelinin hangi tarafça faturalanacağı tanımlanmalı (mali müşavir).", true, $actor);
		}

		return ['success' => true, 'message' => "$created fatura hazırlandı" . ($skipped ? ", $skipped zaten vardı (mükerrer oluşturulmadı)" : '') . '.', 'created' => $created, 'skipped' => $skipped];
	}

	/**
	 * KDV dahil satır tutarlarından KDV hariç birim/KDV hesaplar. KDV oranı tanımsız satır varsa hata döner (sabit oran varsayılmaz).
	 * @param list<array{name:string,qty:float,gross_total:float,vat:mixed}> $in
	 */
	private static function buildLines(array $in): array
	{
		$rows = [];
		$errors = [];
		$net = $vat = $gross = 0.0;

		foreach ($in as $l) {
			[$rate, $src] = self::vatRate($l);

			if ($rate === null) {
				$errors[] = 'KDV oranı tanımsız: ' . $l['name'] . '.';
				$rate = 0.0;
			}

			$g = round((float) $l['gross_total'], 2);
			$n = round($g / (1 + $rate / 100), 2);
			$v = round($g - $n, 2);
			$q = max(0.001, (float) $l['qty']);
			$rows[] = ['name' => $l['name'], 'qty' => $q, 'unit_net' => round($n / $q, 4), 'net' => $n, 'vat_rate' => $rate, 'vat' => $v, 'gross' => $g, 'vat_source' => $src];
			$net += $n;
			$vat += $v;
			$gross += $g;
		}

		return [$rows, round($net, 2), round($vat, 2), round($gross, 2), array_values(array_unique($errors))];
	}

	/** Dönem (YYYY-MM) için satıcı başına komisyon/hizmet faturası (Rafımda → satıcı). Teslim edilmiş satıcı siparişlerinden. */
	public static function prepareServiceInvoices(string $period, string $actor = 'admin'): array
	{
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
			return ['success' => false, 'message' => 'Dönem YYYY-AA biçiminde olmalı.', 'created' => 0];
		}

		$rate = self::setting('SERVICE_VAT');

		if (!is_numeric($rate) || (float) $rate <= 0) {
			return ['success' => false, 'message' => 'Hizmet/komisyon KDV oranı ayarlanmadı (Ayarlar). Oran kodda sabit değildir.', 'created' => 0];
		}

		$from = $period . '-01 00:00:00';
		$to = date('Y-m-d 00:00:00', strtotime($from . ' +1 month'));
		$rows = DB::execute("SELECT vo.id_vendor, SUM(vo.commission_amount) AS c, COUNT(*) AS n FROM rafimda_vendor_orders vo WHERE vo.status = 'delivered' AND vo.date_add >= ? AND vo.date_add < ? GROUP BY vo.id_vendor", [$from, $to]) ?: [];
		$created = 0;

		foreach ($rows as $r) {
			$idVendor = (int) $r['id_vendor'];
			$amount = round((float) $r['c'], 2); // komisyon tutarı KDV hariç kabul edilir; KDV üzerine eklenir

			if ($amount <= 0 || DB::getRowSafe('ri_invoices', 'idem_key = ?', ["service:$idVendor:$period"])) {
				continue;
			}

			$v = DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [$idVendor]);
			$vat = round($amount * (float) $rate / 100, 2);
			$buyer = ['buyer_type' => strlen((string) $v['tax_number']) === 10 ? 'company' : 'individual', 'buyer_name' => (string) ($v['legal_name'] ?: $v['store_name']),
				'buyer_tax_no' => (string) $v['tax_number'], 'buyer_tax_office' => (string) $v['tax_office'], 'buyer_address' => (string) ($v['city'] ?? ''), 'buyer_email' => (string) $v['email']];
			$app = class_exists('RvApplication', false) ? RvApplication::forVendor($idVendor) : null;

			if ($app && trim((string) $app['address']) !== '') {
				$buyer['buyer_address'] = trim($app['address'] . ' ' . $app['district'] . ' ' . $v['city']);
			}

			$errors = self::validateBuyer($buyer);
			$lines = [['name' => "Pazaryeri hizmet/komisyon bedeli ($period, {$r['n']} sipariş)", 'qty' => 1, 'unit_net' => $amount, 'net' => $amount, 'vat_rate' => (float) $rate, 'vat' => $vat, 'gross' => $amount + $vat, 'vat_source' => 'setting']];
			$id = (int) DB::insert('ri_invoices', ['idem_key' => "service:$idVendor:$period", 'kind' => 'service', 'issuer_type' => 'platform', 'id_vendor' => $idVendor, 'period' => $period,
				'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'net_total' => $amount, 'vat_total' => $vat, 'gross_total' => $amount + $vat, 'status' => $errors ? 'draft' : 'ready',
				'environment' => 'test', 'last_error' => mb_substr(implode(' ', $errors), 0, 500), 'created_by' => $actor, 'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s')] + $buyer);

			if ($id > 0) {
				$created++;
				self::event($id, 'prepared', 'Hizmet/komisyon faturası ' . ($errors ? 'taslak: ' . implode(' ', $errors) : 'hazır'), $errors !== [], $actor);
			}
		}

		return ['success' => true, 'message' => "$period dönemi için $created hizmet faturası hazırlandı.", 'created' => $created];
	}

	/** Manuel fatura (yönetici veya satıcı). Satıcı yalnızca kendi adına düzenler. */
	public static function createManual(string $issuerType, int $idVendor, array $in, string $actor): array
	{
		$buyer = ['buyer_type' => ($in['buyer_type'] ?? '') === 'company' ? 'company' : 'individual', 'buyer_name' => mb_substr(trim(strip_tags((string) ($in['buyer_name'] ?? ''))), 0, 190),
			'buyer_tax_no' => preg_replace('/\D/', '', (string) ($in['buyer_tax_no'] ?? '')) ?? '', 'buyer_tax_office' => mb_substr(trim(strip_tags((string) ($in['buyer_tax_office'] ?? ''))), 0, 120),
			'buyer_address' => mb_substr(trim(strip_tags((string) ($in['buyer_address'] ?? ''))), 0, 500), 'buyer_email' => mb_substr(trim((string) ($in['buyer_email'] ?? '')), 0, 190)];
		$desc = mb_substr(trim(strip_tags((string) ($in['description'] ?? ''))), 0, 190);
		$gross = round((float) str_replace(',', '.', (string) ($in['gross'] ?? '0')), 2);
		$rate = (string) ($in['vat_rate'] ?? '');

		if ($desc === '' || $gross <= 0 || !is_numeric($rate) || (float) $rate <= 0) {
			return ['success' => false, 'message' => 'Açıklama, KDV dahil tutar ve KDV oranı gerekli.'];
		}

		[$rows, $net, $vat, $g, $errors] = self::buildLines([['name' => $desc, 'qty' => 1, 'gross_total' => $gross, 'vat' => (float) $rate]]);
		$errors = array_merge($errors, self::validateBuyer($buyer));

		if ($errors) {
			return ['success' => false, 'message' => implode(' ', $errors)];
		}

		$id = (int) DB::insert('ri_invoices', ['idem_key' => 'manual:' . bin2hex(random_bytes(10)), 'kind' => 'manual', 'issuer_type' => $issuerType === 'vendor' ? 'vendor' : 'platform', 'id_vendor' => $issuerType === 'vendor' ? $idVendor : 0,
			'lines' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'net_total' => $net, 'vat_total' => $vat, 'gross_total' => $g, 'status' => 'ready', 'environment' => 'test', 'created_by' => $actor,
			'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s')] + $buyer);
		self::event($id, 'prepared', 'Manuel fatura hazır', false, $actor);

		return ['success' => $id > 0, 'message' => 'Manuel fatura hazırlandı; gönderim için "Gönder" kullanın.', 'id_invoice' => $id];
	}

	// ---------- Gönderim ----------

	public static function get(int $id): ?array
	{
		return DB::getRowSafe('ri_invoices', 'id_invoice = ?', [$id]) ?: null;
	}

	/** Düzenleyenin entegratör hesabı: satıcı faturası → satıcının hesabı; Rafımda faturaları → platform hesabı. */
	private static function issuerAccount(array $inv): ?array
	{
		return in_array($inv['kind'], ['sale'], true) || ($inv['kind'] === 'manual' && $inv['issuer_type'] === 'vendor')
			? self::account('vendor', (int) $inv['id_vendor']) : self::account('platform');
	}

	public static function send(int $id, string $actor = 'admin', int $actorVendor = 0): array
	{
		$inv = self::get($id);

		if (!$inv) {
			return ['success' => false, 'message' => 'Fatura bulunamadı.'];
		}

		if ($actorVendor > 0 && !($inv['issuer_type'] === 'vendor' && (int) $inv['id_vendor'] === $actorVendor)) {
			return ['success' => false, 'message' => 'Bu faturayı gönderme yetkiniz yok.'];
		}

		if ($inv['ettn'] !== '' || in_array($inv['status'], ['sent', 'accepted', 'cancelled'], true)) {
			return ['success' => false, 'message' => 'Bu fatura zaten gönderilmiş; tekrar gönderilmez (mükerrer engeli).'];
		}

		if (!in_array($inv['status'], ['ready', 'error', 'rejected'], true)) {
			return ['success' => false, 'message' => 'Fatura gönderime hazır değil: ' . $inv['last_error']];
		}

		$acc = self::issuerAccount($inv);

		if (!$acc) {
			return self::fail($id, 'Düzenleyen için entegratör hesabı tanımlı değil.', $actor);
		}

		$adapter = self::adapters()[$acc['integrator']] ?? null;

		if (!$adapter || !$adapter->isImplemented()) {
			return self::fail($id, $adapter ? $adapter->testConnection($acc)['message'] : 'Bağdaştırıcı yok.', $actor);
		}

		if ($acc['environment'] === 'live' && !self::liveEnabled()) {
			return self::fail($id, 'Canlı fatura gönderimi kapalı (RAFIMDA_INVOICE_LIVE). İşletme sahibinin yazılı onayı ve mali müşavir doğrulaması gerekir.', $actor);
		}

		// Belge türü: kurumsal alıcı GİB e-Fatura kayıtlı kullanıcısıysa e-Fatura, değilse e-Arşiv. Sorgulanamazsa varsayım YAPILMAZ.
		$doc = $inv['doc_type'];

		if ($doc === '') {
			if ($inv['buyer_type'] === 'company' && strlen($inv['buyer_tax_no']) === 10) {
				$u = $adapter->isEInvoiceUser(self::withSecret($acc), $inv['buyer_tax_no']);

				if ($u === null) {
					return self::fail($id, 'Alıcının e-Fatura mükellefiyeti sorgulanamadı; belge türünü elle seçin.', $actor);
				}

				$doc = $u ? 'efatura' : 'earsiv';
			} else {
				$doc = 'earsiv';
			}
		}

		$payload = $inv + ['doc_type' => $doc];

		if ($payload['buyer_type'] === 'individual' && $payload['buyer_tax_no'] === '') {
			$payload['buyer_tax_no'] = self::ANON_TCKN;
		}

		$r = $adapter->send(self::withSecret($acc), $payload);
		$upd = ['doc_type' => $doc, 'environment' => $acc['environment'], 'integrator' => $acc['integrator'], 'date_upd' => date('Y-m-d H:i:s')];

		if (!$r['success']) {
			DB::update('ri_invoices', $upd + ['status' => $r['status'] === 'rejected' ? 'rejected' : 'error', 'last_error' => mb_substr($r['message'], 0, 500)], 'id_invoice = :i', ['i' => $id]);
			self::event($id, 'send_failed', $r['message'], true, $actor);

			return ['success' => false, 'message' => $r['message']];
		}

		DB::update('ri_invoices', $upd + ['status' => $r['status'] === 'accepted' ? 'accepted' : 'sent', 'ettn' => (string) ($r['ettn'] ?? ''), 'invoice_no' => (string) ($r['invoice_no'] ?? ''),
			'sent_at' => date('Y-m-d H:i:s'), 'last_error' => ''], 'id_invoice = :i', ['i' => $id]);
		self::event($id, 'sent', $r['message'] . ' No: ' . ($r['invoice_no'] ?? ''), false, $actor);
		self::storePdf($id);
		self::notifyRecipient(self::get($id));

		return ['success' => true, 'message' => 'Fatura gönderildi: ' . ($r['invoice_no'] ?? '') . ($acc['environment'] === 'test' ? ' (TEST — mali değeri yoktur)' : '')];
	}

	private static function fail(int $id, string $msg, string $actor): array
	{
		DB::update('ri_invoices', ['last_error' => mb_substr($msg, 0, 500), 'date_upd' => date('Y-m-d H:i:s')], 'id_invoice = :i', ['i' => $id]);
		self::event($id, 'send_blocked', $msg, true, $actor);

		return ['success' => false, 'message' => $msg];
	}

	/** Kurumsal alıcıda mükellefiyet sorgulanamazsa yönetici/satıcı belge türünü elle seçer. */
	public static function setDocType(int $id, string $doc, int $actorVendor = 0): bool
	{
		$inv = self::get($id);

		if (!$inv || !isset(self::DOC_TYPES[$doc]) || $doc === 'paper' || $inv['ettn'] !== '' || ($actorVendor > 0 && (int) $inv['id_vendor'] !== $actorVendor)) {
			return false;
		}

		DB::update('ri_invoices', ['doc_type' => $doc, 'date_upd' => date('Y-m-d H:i:s')], 'id_invoice = :i', ['i' => $id]);
		self::event($id, 'doc_type', 'Belge türü elle seçildi: ' . self::DOC_TYPES[$doc], false, $actorVendor ? 'vendor' : 'admin');

		return true;
	}

	/** İptal: belge silinmez; entegratöre iptal bildirilir ve durum "iptal edildi" olur. Gönderilmemiş taslak doğrudan iptal edilir. */
	public static function cancel(int $id, string $reason, string $actor = 'admin', int $actorVendor = 0): array
	{
		$inv = self::get($id);
		$reason = mb_substr(trim(strip_tags($reason)), 0, 300);

		if (!$inv || ($actorVendor > 0 && !($inv['issuer_type'] === 'vendor' && (int) $inv['id_vendor'] === $actorVendor))) {
			return ['success' => false, 'message' => 'Fatura bulunamadı.'];
		}

		if ($inv['status'] === 'cancelled') {
			return ['success' => false, 'message' => 'Zaten iptal edilmiş.'];
		}

		if (mb_strlen($reason) < 5) {
			return ['success' => false, 'message' => 'İptal gerekçesi yazın.'];
		}

		if ($inv['ettn'] !== '') {
			$acc = self::issuerAccount($inv);
			$adapter = $acc ? (self::adapters()[$acc['integrator']] ?? null) : null;
			$r = $adapter ? $adapter->cancel(self::withSecret($acc), $inv, $reason) : ['success' => false, 'message' => 'Bağdaştırıcı yok.'];

			if (!$r['success']) {
				self::event($id, 'cancel_failed', $r['message'], true, $actor);

				return $r;
			}
		}

		DB::update('ri_invoices', ['status' => 'cancelled', 'date_upd' => date('Y-m-d H:i:s')], 'id_invoice = :i', ['i' => $id]);
		self::event($id, 'cancelled', 'İptal: ' . $reason, false, $actor);

		return ['success' => true, 'message' => 'Fatura iptal edildi (kayıt silinmedi).'];
	}

	/** İade: orijinal belge korunur; ilişkili "iade belgesi kaydı" açılır. Hangi belgenin (iade faturası / gider pusulası) düzenleneceği mali müşavir kararıdır. */
	public static function registerReturn(int $id, string $note, string $actor = 'admin'): array
	{
		$inv = self::get($id);

		if (!$inv || !in_array($inv['status'], ['sent', 'accepted'], true)) {
			return ['success' => false, 'message' => 'Yalnızca gönderilmiş faturaya iade kaydı açılır.'];
		}

		$key = 'return:' . $id;

		if (DB::getRowSafe('ri_invoices', 'idem_key = ?', [$key])) {
			return ['success' => false, 'message' => 'Bu fatura için iade kaydı zaten var.'];
		}

		$rid = (int) DB::insert('ri_invoices', ['idem_key' => $key, 'kind' => 'return', 'issuer_type' => $inv['issuer_type'], 'id_vendor' => (int) $inv['id_vendor'], 'id_order' => (int) $inv['id_order'],
			'id_user' => (int) $inv['id_user'], 'buyer_type' => $inv['buyer_type'], 'buyer_name' => $inv['buyer_name'], 'buyer_tax_no' => $inv['buyer_tax_no'], 'buyer_tax_office' => $inv['buyer_tax_office'],
			'buyer_address' => $inv['buyer_address'], 'buyer_email' => $inv['buyer_email'], 'lines' => $inv['lines'], 'net_total' => $inv['net_total'], 'vat_total' => $inv['vat_total'],
			'gross_total' => $inv['gross_total'], 'status' => 'draft', 'related_invoice' => $id, 'environment' => $inv['environment'],
			'last_error' => 'İade belgesi türü (iade faturası / gider pusulası / e-Arşiv iptal) mali müşavirle belirlenmeli: ' . mb_substr(strip_tags($note), 0, 200),
			'created_by' => $actor, 'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s')]);
		self::event($id, 'return_registered', 'İade kaydı #' . $rid, false, $actor);

		return ['success' => true, 'message' => 'İade kaydı açıldı (orijinal fatura korunur).'];
	}

	// ---------- Belge, erişim, bildirim ----------

	public static function storageDir(): string
	{
		return self::$storageOverride ?? dirname(__DIR__, 3) . '/storage/rafimda/invoices';
	}

	public static function issuerInfo(array $inv): array
	{
		if ($inv['issuer_type'] === 'vendor') {
			$v = DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [(int) $inv['id_vendor']]) ?: [];

			return ['name' => (string) ($v['legal_name'] ?? '') ?: (string) ($v['store_name'] ?? ''), 'tax_office' => (string) ($v['tax_office'] ?? ''), 'tax_no' => (string) ($v['tax_number'] ?? ''), 'address' => (string) ($v['city'] ?? '')];
		}

		return ['name' => (string) Settings::get('RAFIMDA_LEGAL_COMPANY') ?: '[Şirket unvanı tanımlı değil]', 'tax_office' => (string) Settings::get('RAFIMDA_LEGAL_TAX_OFFICE'),
			'tax_no' => (string) Settings::get('RAFIMDA_LEGAL_TAX_NO'), 'address' => (string) Settings::get('RAFIMDA_LEGAL_ADDRESS')];
	}

	public static function storePdf(int $id): bool
	{
		$inv = self::get($id);

		if (!$inv) {
			return false;
		}

		$dir = self::storageDir();

		if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
			return false;
		}

		if (!is_file($dir . '/.htaccess')) {
			@file_put_contents($dir . '/.htaccess', "Require all denied\nOptions -Indexes\n");
		}

		$name = 'inv-' . $id . '-' . bin2hex(random_bytes(6)) . '.pdf';

		if (file_put_contents($dir . '/' . $name, RiPdf::invoice($inv, self::issuerInfo($inv))) === false) {
			return false;
		}

		DB::update('ri_invoices', ['pdf_file' => $name], 'id_invoice = :i', ['i' => $id]);

		return true;
	}

	/** Yetkili erişim: müşteri kendi sipariş faturası, satıcı kendi düzenlediği/kendisine kesilen, yönetici hepsi. */
	public static function pdfFor(int $id, string $who, int $idUser = 0, int $idVendor = 0): ?string
	{
		$inv = self::get($id);

		if (!$inv || $inv['pdf_file'] === '') {
			return null;
		}

		$ok = $who === 'admin'
			|| ($who === 'customer' && $idUser > 0 && (int) $inv['id_user'] === $idUser && in_array($inv['kind'], ['sale', 'direct'], true))
			|| ($who === 'vendor' && $idVendor > 0 && (int) $inv['id_vendor'] === $idVendor && ($inv['issuer_type'] === 'vendor' || $inv['kind'] === 'service'));
		$path = self::storageDir() . '/' . basename((string) $inv['pdf_file']);

		return $ok && is_file($path) ? $path : null;
	}

	private static function notifyRecipient(?array $inv): void
	{
		if (!$inv || !class_exists('RvNotify', false)) {
			return;
		}

		$title = 'Faturanız hazır: ' . $inv['invoice_no'] . ($inv['environment'] === 'test' ? ' (TEST)' : '');

		if ($inv['kind'] === 'service') {
			RvNotify::send(['id_vendor' => (int) $inv['id_vendor'], 'audience' => 'vendor'], 'invoice', $title, 'Rafımda hizmet/komisyon faturanız düzenlendi.', '');
		} elseif ((int) $inv['id_user'] > 0) {
			RvNotify::send(['id_user' => (int) $inv['id_user'], 'email' => (string) $inv['buyer_email']], 'invoice', $title, 'Sipariş #' . $inv['id_order'] . ' faturanız hesabınızda görüntülenebilir.', '');
		}
	}

	public static function event(int $id, string $event, string $detail, bool $error, string $actor): void
	{
		// Güvenlik: olası gizli bilgi kalıplarını loga yazma
		$detail = (string) preg_replace('/(password|secret|token|şifre)\s*[:=]\s*\S+/iu', '$1=[gizlendi]', $detail);
		DB::insert('ri_events', ['id_invoice' => $id, 'event' => $event, 'detail' => mb_substr($detail, 0, 1000), 'is_error' => $error ? 1 : 0, 'actor' => mb_substr($actor, 0, 24), 'date_add' => date('Y-m-d H:i:s')]);
	}

	// ---------- Listeler ----------

	/** @return array<int,array<string,mixed>> */
	public static function adminList(array $f = []): array
	{
		$sql = 'SELECT i.*, v.store_name FROM ri_invoices i LEFT JOIN rafimda_vendors v ON v.id_vendor = i.id_vendor WHERE 1=1';
		$args = [];

		foreach (['status', 'kind'] as $k) {
			if (!empty($f[$k])) {
				$sql .= " AND i.$k = ?";
				$args[] = (string) $f[$k];
			}
		}

		if (!empty($f['order'])) {
			$sql .= ' AND i.id_order = ?';
			$args[] = (int) $f['order'];
		}

		return DB::execute($sql . ' ORDER BY i.id_invoice DESC LIMIT 300', $args) ?: [];
	}

	/** @return array<int,array<string,mixed>> satıcı: düzenlediği satış faturaları + kendisine kesilen hizmet faturaları */
	public static function forVendor(int $idVendor): array
	{
		return DB::execute("SELECT * FROM ri_invoices WHERE id_vendor = ? AND (issuer_type = 'vendor' OR kind = 'service') ORDER BY id_invoice DESC LIMIT 300", [$idVendor]) ?: [];
	}

	/** @return array<int,array<string,mixed>> müşteri: kendi siparişlerinin gönderilmiş/iptal satış faturaları */
	public static function forUser(int $idUser): array
	{
		return DB::execute("SELECT id_invoice, id_order, invoice_no, doc_type, status, environment, gross_total, sent_at, pdf_file, kind FROM ri_invoices WHERE id_user = ? AND kind IN ('sale','direct','return') AND status IN ('sent','accepted','cancelled') ORDER BY id_invoice DESC", [$idUser]) ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function events(int $id = 0, bool $errorsOnly = false, int $limit = 200): array
	{
		$sql = 'SELECT * FROM ri_events WHERE 1=1';
		$args = [];

		if ($id > 0) {
			$sql .= ' AND id_invoice = ?';
			$args[] = $id;
		}

		if ($errorsOnly) {
			$sql .= ' AND is_error = 1';
		}

		return DB::execute($sql . ' ORDER BY id_event DESC LIMIT ' . max(1, min(1000, $limit)), $args) ?: [];
	}

	/** Sipariş olaylarına bağlı otomatik akış: yalnızca yapılandırılmış tetikleyicide HAZIRLAR; otomatik gönderim ayrı ayardır. Hata sipariş akışını bozmaz. */
	public static function onOrderEvent(array $order): void
	{
		try {
			$trigger = self::setting('TRIGGER', 'manual');
			$status = (int) ($order['status'] ?? ($order['current_state'] ?? 0));
			$id = (int) ($order['id_order'] ?? 0);

			if ($trigger === 'manual' || $id <= 0 || !in_array($status, self::TRIGGER_STATES[$trigger] ?? [], true)) {
				return;
			}

			self::prepareForOrder($id, 'auto');

			if (self::setting('AUTO_SEND') === '1') {
				foreach (DB::execute("SELECT id_invoice FROM ri_invoices WHERE id_order = ? AND status = 'ready'", [$id]) ?: [] as $r) {
					self::send((int) $r['id_invoice'], 'auto');
				}
			}
		} catch (Throwable $e) {
			error_log('rafimda-invoice: ' . $e->getMessage());
		}
	}
}
