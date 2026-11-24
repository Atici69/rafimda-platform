<?php

/**
 * Sosyal medya içerik takvimi, kuyruk, onay, yayın öncesi kontrol ve (izinliyse) yayın.
 * - Saatler Türkiye saatidir (Europe/Istanbul), sunucu saat diliminden bağımsız hesaplanır.
 * - Durumlar: draft (taslak) → pending_approval (onay bekliyor) → ready (yayına hazır) → published / failed; cancelled; on_hold (beklemede: fiyat/stok değişti).
 * - Tekrar engeli: aynı ürün + aynı platform, ayarlanan gün sayısı içinde ikinci kez planlanamaz; aynı hesapta aynı dakikaya iki yayın konmaz.
 * - Yayın anında ürün yeniden kontrol edilir: pasif/stoksuz → yayınlanmaz; fiyat değiştiyse beklemeye alınır (eski fiyatlı içerik yayınlanmaz).
 * - OTOMATİK YAYIN VARSAYILAN KAPALI: RAFIMDA_SOCIAL_PUBLISH=1 (onay ifadesiyle) + bağlı hesap + uygulanmış resmî API gerekir. Şu an hiçbir yayıncı uygulanmadı.
 * - Satıcı yalnızca kendi ürünleri ve hesapları; yönetici Rafımda genel hesapları ve tüm içerikler.
 */
final class RsoPlanner
{
	public const TZ = 'Europe/Istanbul';
	public const STATUSES = ['draft' => 'Taslak', 'pending_approval' => 'Onay bekliyor', 'ready' => 'Yayına hazır', 'published' => 'Yayınlandı',
		'failed' => 'Başarısız', 'cancelled' => 'İptal edildi', 'on_hold' => 'Beklemede (güncelleme gerekli)'];
	public const PLATFORMS = ['instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'];
	public const SELECTIONS = ['new' => 'Yeni ürünler', 'best' => 'Çok satanlar', 'campaign' => 'Kampanyalı (gerçek eski fiyatı olan)', 'featured' => 'Öne çıkan (vitrin)', 'in_stock' => 'Stokta olanlar'];

	/** Testlerde saat */
	public static ?int $now = null;

	public static function tz(): DateTimeZone
	{
		return new DateTimeZone(self::TZ);
	}

	public static function now(): DateTimeImmutable
	{
		return (new DateTimeImmutable('@' . (self::$now ?? time())))->setTimezone(self::tz());
	}

	public static function setting(string $k, string $default = ''): string
	{
		$v = (string) Settings::get('RAFIMDA_SOCIAL_' . $k);

		return $v === '' ? $default : $v;
	}

	public static function publishEnabled(): bool
	{
		return self::setting('PUBLISH') === '1';
	}

	/** @return array<string,RsoPublisher> */
	public static function publishers(): array
	{
		return ['instagram' => new RsoOfficialPublisher('instagram'), 'tiktok' => new RsoOfficialPublisher('tiktok'), 'youtube' => new RsoOfficialPublisher('youtube')];
	}

	public static function event(int $id, string $ev, string $detail, bool $err, string $actor): void
	{
		$detail = (string) preg_replace('/(access_token|token|secret|password|code)\s*[:=]\s*\S+/iu', '$1=[gizlendi]', $detail);
		DB::insert('rso_events', ['id_post' => $id, 'event' => $ev, 'detail' => mb_substr($detail, 0, 1000), 'is_error' => $err ? 1 : 0, 'actor' => mb_substr($actor, 0, 24), 'date_add' => date('Y-m-d H:i:s')]);
	}

	/** "Y-m-d H:i" (TR) doğrular; geçmiş zaman kabul edilmez. */
	public static function parseTime(string $s): ?string
	{
		$d = DateTimeImmutable::createFromFormat('Y-m-d H:i', trim(str_replace('T', ' ', $s)), self::tz());

		if (!$d || $d <= self::now()) {
			return null;
		}

		return $d->format('Y-m-d H:i:00');
	}

	/** Ürün sahipliği: satıcı yalnızca kendi ürünü; yönetici (idVendor=0) her aktif ürün. */
	public static function productAllowed(int $idProduct, string $owner, int $idVendor): bool
	{
		if ($owner === 'vendor') {
			return (bool) DB::getRowSafe('rafimda_vendor_products', 'id_product = ? AND id_vendor = ?', [$idProduct, $idVendor]);
		}

		return (bool) DB::getRowSafe('products', 'id_product = ?', [$idProduct]);
	}

	public static function monthlyCount(int $idVendor): int
	{
		$m = self::now()->format('Y-m');

		return (int) DB::getValue("SELECT COUNT(*) FROM rso_posts WHERE owner_type = 'vendor' AND id_vendor = ? AND date_add >= ? AND status <> 'cancelled'", [$idVendor, $m . '-01 00:00:00']);
	}

	public static function vendorLimit(): int
	{
		return max(0, (int) self::setting('VENDOR_MONTHLY_LIMIT', '30'));
	}

	/**
	 * İçerik oluşturur (metin + medya). $when boşsa taslak (takvimsiz).
	 * @return array{success:bool,message:string,id_post?:int}
	 */
	public static function create(string $owner, int $idVendor, int $idProduct, string $format, string $when, string $actor, string $source = 'manual'): array
	{
		$owner = $owner === 'vendor' ? 'vendor' : 'platform';
		$idVendor = $owner === 'vendor' ? $idVendor : 0;

		if (!isset(RsoContent::FORMATS[$format])) {
			return ['success' => false, 'message' => 'Geçersiz içerik biçimi.'];
		}

		if (!self::productAllowed($idProduct, $owner, $idVendor)) {
			return ['success' => false, 'message' => 'Ürün bulunamadı veya size ait değil.'];
		}

		$p = RsoContent::product($idProduct);

		if (!$p || (int) $p['active'] !== 1) {
			return ['success' => false, 'message' => 'Pasif ürün için içerik oluşturulmaz.'];
		}

		if ((float) $p['stock'] <= 0) {
			return ['success' => false, 'message' => 'Stokta olmayan ürün tanıtılmaz.'];
		}

		if ($owner === 'vendor' && self::vendorLimit() > 0 && self::monthlyCount($idVendor) >= self::vendorLimit()) {
			return ['success' => false, 'message' => 'Aylık içerik limitinize ulaştınız (' . self::vendorLimit() . ').'];
		}

		$platform = RsoContent::FORMATS[$format][1];
		$at = null;

		if ($when !== '') {
			$at = self::parseTime($when);

			if ($at === null) {
				return ['success' => false, 'message' => 'Yayın zamanı gelecekte olmalı (Türkiye saati, YYYY-AA-GG SS:DD).'];
			}

			$dup = self::duplicateReason($idProduct, $platform, $owner, $idVendor, $at, 0);

			if ($dup !== '') {
				return ['success' => false, 'message' => $dup];
			}
		}

		$t = RsoContent::text($p, $format);
		$media = [];
		$note = '';

		if (RsoContent::FORMATS[$format][4] === 'image') {
			$f = RsoContent::renderImage($p, RsoContent::FORMATS[$format][2], RsoContent::FORMATS[$format][3]);
			$media = $f !== '' ? ['type' => 'image', 'files' => [$f]] : [];
		} else {
			$v = RsoContent::renderVideo($p);
			$media = ['type' => $v['video'] !== '' ? 'video' : 'storyboard', 'files' => $v['video'] !== '' ? [$v['video']] : $v['frames'], 'frames' => $v['frames']];
			$note = $v['message'];
		}

		$status = $at === null ? 'draft' : ($owner === 'vendor' && self::setting('VENDOR_APPROVAL', '1') === '1' ? 'pending_approval' : 'ready');
		$id = (int) DB::insert('rso_posts', ['owner_type' => $owner, 'id_vendor' => $idVendor, 'id_product' => $idProduct, 'platform' => $platform, 'format' => $format,
			'title' => $t['title'], 'caption' => $t['caption'], 'hashtags' => $t['hashtags'], 'media' => json_encode($media), 'price_snapshot' => (float) $p['price'],
			'status' => $status, 'scheduled_at' => $at, 'source' => $source, 'created_by' => $actor, 'date_add' => self::now()->format('Y-m-d H:i:s'), 'date_upd' => self::now()->format('Y-m-d H:i:s')]);
		self::event($id, 'created', RsoContent::FORMATS[$format][0] . ' · ' . self::STATUSES[$status] . ($note !== '' ? ' · ' . $note : ''), false, $actor);

		return ['success' => $id > 0, 'message' => 'İçerik oluşturuldu (' . self::STATUSES[$status] . ').' . ($note !== '' ? ' ' . $note : ''), 'id_post' => $id];
	}

	/** Tekrar engeli: aynı ürün+platform son/sonraki N gün içinde; aynı sahibin aynı platformunda aynı dakika. */
	public static function duplicateReason(int $idProduct, string $platform, string $owner, int $idVendor, string $at, int $exceptId): string
	{
		$days = max(0, (int) self::setting('REPEAT_DAYS', '14'));
		$t = new DateTimeImmutable($at, self::tz());
		$from = $t->modify("-$days days")->format('Y-m-d H:i:s');
		$to = $t->modify("+$days days")->format('Y-m-d H:i:s');

		if ($days > 0 && DB::getValue("SELECT id_post FROM rso_posts WHERE id_product = ? AND platform = ? AND id_post <> ? AND status IN ('pending_approval','ready','published','on_hold')
			AND COALESCE(published_at, scheduled_at) BETWEEN ? AND ? LIMIT 1", [$idProduct, $platform, $exceptId, $from, $to])) {
			return "Bu ürün $days gün içinde bu platformda zaten planlı/yayınlanmış (tekrar paylaşım engeli).";
		}

		if (DB::getValue("SELECT id_post FROM rso_posts WHERE owner_type = ? AND id_vendor = ? AND platform = ? AND scheduled_at = ? AND id_post <> ? AND status IN ('pending_approval','ready') LIMIT 1",
			[$owner, $idVendor, $platform, $at, $exceptId])) {
			return 'Aynı saatte bu hesap için başka bir yayın planlı.';
		}

		return '';
	}

	public static function get(int $id): ?array
	{
		$r = DB::getRowSafe('rso_posts', 'id_post = ?', [$id]);

		if (!$r) {
			return null;
		}

		$r['media_list'] = json_decode((string) $r['media'], true) ?: [];
		$r['media_urls'] = array_map([RsoContent::class, 'mediaUrl'], $r['media_list']['files'] ?? []);

		return $r;
	}

	private static function owned(int $id, string $owner, int $idVendor): ?array
	{
		$p = self::get($id);

		if (!$p || ($owner === 'vendor' && !($p['owner_type'] === 'vendor' && (int) $p['id_vendor'] === $idVendor))) {
			return null;
		}

		return $p;
	}

	/** Metin düzenleme (yayınlanmış/iptal içerik düzenlenmez). */
	public static function edit(int $id, string $owner, int $idVendor, string $caption, string $hashtags, string $actor): array
	{
		$p = self::owned($id, $owner, $idVendor);

		if (!$p || in_array($p['status'], ['published', 'cancelled'], true)) {
			return ['success' => false, 'message' => 'İçerik düzenlenemez.'];
		}

		$caption = trim(strip_tags($caption));
		$tags = implode(' ', array_slice(array_values(array_filter(array_map(static fn($t) => '#' . preg_replace('/[^\p{L}\p{N}_]/u', '', ltrim($t, '#')), preg_split('/[\s,]+/u', $hashtags) ?: []), static fn($t) => mb_strlen($t) > 1)), 0, 30));

		if (mb_strlen($caption) < 10 || mb_strlen($caption) > 2200) {
			return ['success' => false, 'message' => 'Metin 10-2200 karakter olmalı.'];
		}

		$status = $p['status'];

		// Satıcının onaylanmış içeriği değişirse yeniden onaya düşer
		if ($owner === 'vendor' && $status === 'ready' && self::setting('VENDOR_APPROVAL', '1') === '1') {
			$status = 'pending_approval';
		}

		DB::update('rso_posts', ['caption' => $caption, 'hashtags' => $tags, 'status' => $status, 'date_upd' => date('Y-m-d H:i:s')], 'id_post = :i', ['i' => $id]);
		self::event($id, 'edited', 'Metin düzenlendi', false, $actor);

		return ['success' => true, 'message' => 'İçerik güncellendi.' . ($status !== $p['status'] ? ' Yeniden onaya gönderildi.' : '')];
	}

	public static function reschedule(int $id, string $owner, int $idVendor, string $when, string $actor): array
	{
		$p = self::owned($id, $owner, $idVendor);
		$at = self::parseTime($when);

		if (!$p || in_array($p['status'], ['published', 'cancelled'], true)) {
			return ['success' => false, 'message' => 'İçerik bulunamadı veya yayın saati değiştirilemez.'];
		}

		if ($at === null) {
			return ['success' => false, 'message' => 'Yayın zamanı gelecekte olmalı (Türkiye saati).'];
		}

		$dup = self::duplicateReason((int) $p['id_product'], $p['platform'], $p['owner_type'], (int) $p['id_vendor'], $at, $id);

		if ($dup !== '') {
			return ['success' => false, 'message' => $dup];
		}

		$status = $p['status'] === 'draft' ? ($p['owner_type'] === 'vendor' && self::setting('VENDOR_APPROVAL', '1') === '1' ? 'pending_approval' : 'ready') : ($p['status'] === 'failed' ? 'ready' : $p['status']);
		DB::update('rso_posts', ['scheduled_at' => $at, 'status' => $status, 'last_error' => '', 'date_upd' => date('Y-m-d H:i:s')], 'id_post = :i', ['i' => $id]);
		self::event($id, 'scheduled', 'Yayın zamanı: ' . substr($at, 0, 16) . ' (TR)', false, $actor);

		return ['success' => true, 'message' => 'Yayın zamanı ayarlandı: ' . substr($at, 0, 16) . ' (Türkiye saati).'];
	}

	public static function cancel(int $id, string $owner, int $idVendor, string $actor): array
	{
		$p = self::owned($id, $owner, $idVendor);

		if (!$p || in_array($p['status'], ['published', 'cancelled'], true)) {
			return ['success' => false, 'message' => 'İptal edilemez.'];
		}

		DB::update('rso_posts', ['status' => 'cancelled', 'date_upd' => date('Y-m-d H:i:s')], 'id_post = :i', ['i' => $id]);
		self::event($id, 'cancelled', 'Yayın iptal edildi', false, $actor);

		return ['success' => true, 'message' => 'Yayın iptal edildi.'];
	}

	/** Yönetici onayı (satıcı içerikleri). */
	public static function approve(int $id, bool $ok, int $adminId, string $note = ''): array
	{
		$p = self::get($id);

		if (!$p || $p['status'] !== 'pending_approval') {
			return ['success' => false, 'message' => 'Onay bekleyen içerik bulunamadı.'];
		}

		DB::update('rso_posts', ['status' => $ok ? 'ready' : 'draft', 'approved_by' => $ok ? $adminId : 0, 'last_error' => $ok ? '' : mb_substr('Reddedildi: ' . strip_tags($note), 0, 500), 'date_upd' => date('Y-m-d H:i:s')], 'id_post = :i', ['i' => $id]);
		self::event($id, $ok ? 'approved' : 'rejected', $ok ? 'Yönetici onayladı' : 'Reddedildi: ' . $note, !$ok, 'admin');

		if (class_exists('RvNotify', false) && $p['owner_type'] === 'vendor') {
			RvNotify::send(['id_vendor' => (int) $p['id_vendor'], 'audience' => 'vendor'], 'social', $ok ? 'Sosyal medya içeriğiniz onaylandı' : 'Sosyal medya içeriğiniz reddedildi', $ok ? 'İçerik yayına hazır.' : $note, '');
		}

		return ['success' => true, 'message' => $ok ? 'İçerik onaylandı.' : 'İçerik reddedildi (taslağa döndü).'];
	}

	/** Yayın öncesi kontrol: '' = uygun; aksi halde neden ve hedef durum. @return array{0:string,1:string} */
	public static function precheck(array $post): array
	{
		$p = RsoContent::product((int) $post['id_product']);

		if (!$p || (int) $p['active'] !== 1) {
			return ['Ürün satıştan kaldırılmış.', 'cancelled'];
		}

		if ((float) $p['stock'] <= 0) {
			return ['Ürün stokta yok.', 'on_hold'];
		}

		if (abs((float) $p['price'] - (float) $post['price_snapshot']) >= 0.01) {
			return ['Fiyat değişti (' . RsoContent::money((float) $post['price_snapshot']) . ' → ' . RsoContent::money((float) $p['price']) . '); eski fiyatlı içerik yayınlanmaz, yeniden oluşturun.', 'on_hold'];
		}

		$m = json_decode((string) $post['media'], true) ?: [];

		foreach ($m['files'] ?? [] as $f) {
			if (!is_file(RsoContent::mediaDir() . '/' . basename((string) $f))) {
				return ['Medya dosyası bulunamadı.', 'on_hold'];
			}
		}

		return ['', ''];
	}

	/** Zamanı gelen hazır içerikleri işler (cron). Otomatik yayın kapalıyken yalnızca kontrol eder ve durumu bildirir. */
	public static function runDue(int $limit = 20): array
	{
		$now = self::now()->format('Y-m-d H:i:s');
		$rows = DB::execute("SELECT id_post FROM rso_posts WHERE status = 'ready' AND scheduled_at IS NOT NULL AND scheduled_at <= ? ORDER BY scheduled_at LIMIT " . max(1, min(100, $limit)), [$now]) ?: [];
		$stats = ['checked' => 0, 'published' => 0, 'held' => 0, 'waiting' => 0, 'failed' => 0];

		foreach ($rows as $r) {
			$id = (int) $r['id_post'];
			$post = self::get($id);
			$stats['checked']++;
			[$why, $to] = self::precheck($post);

			if ($why !== '') {
				DB::update('rso_posts', ['status' => $to, 'last_error' => mb_substr($why, 0, 500), 'date_upd' => date('Y-m-d H:i:s')], 'id_post = :i', ['i' => $id]);
				self::event($id, 'precheck_failed', $why, true, 'cron');
				$stats['held']++;
				continue;
			}

			if (!self::publishEnabled()) {
				if ($post['last_error'] === '') {
					DB::update('rso_posts', ['last_error' => 'Otomatik yayın kapalı: içerik hazır, elle paylaşılabilir.', 'date_upd' => date('Y-m-d H:i:s')], 'id_post = :i', ['i' => $id]);
					self::event($id, 'publish_disabled', 'Yayın zamanı geldi; otomatik yayın kapalı olduğu için paylaşılmadı', false, 'cron');
				}

				$stats['waiting']++;
				continue;
			}

			$acc = self::account($post['owner_type'], (int) $post['id_vendor'], $post['platform']);
			$pub = self::publishers()[$post['platform']] ?? null;

			if (!$acc || $acc['status'] !== 'connected' || !$pub || !$pub->isImplemented()) {
				$msg = !$pub || !$pub->isImplemented() ? RsoOfficialPublisher::NOT_READY : 'Hesap bağlı değil.';
				DB::update('rso_posts', ['status' => 'failed', 'last_error' => mb_substr($msg, 0, 500), 'date_upd' => date('Y-m-d H:i:s')], 'id_post = :i', ['i' => $id]);
				self::event($id, 'publish_failed', $msg, true, 'cron');
				$stats['failed']++;
				continue;
			}

			$res = $pub->publish($acc + ['token' => RsoCrypt::decrypt((string) $acc['token_enc'])], $post);
			DB::update('rso_posts', $res['success']
				? ['status' => 'published', 'published_at' => $now, 'external_id' => (string) ($res['external_id'] ?? ''), 'last_error' => '', 'date_upd' => date('Y-m-d H:i:s')]
				: ['status' => 'failed', 'last_error' => mb_substr($res['message'], 0, 500), 'date_upd' => date('Y-m-d H:i:s')], 'id_post = :i', ['i' => $id]);
			self::event($id, $res['success'] ? 'published' : 'publish_failed', $res['message'], !$res['success'], 'cron');
			$stats[$res['success'] ? 'published' : 'failed']++;
		}

		return $stats;
	}

	/**
	 * Otomatik ürün seçimi: kurala göre uygun (aktif, stokta, tekrar engeline takılmayan) ürünler için ileri tarihli içerik önerir.
	 * @return array{success:bool,message:string,created:int}
	 */
	public static function autoPlan(string $owner, int $idVendor, string $rule, string $format, string $startDay, string $time, int $count, string $actor): array
	{
		if (!isset(self::SELECTIONS[$rule]) || !isset(RsoContent::FORMATS[$format])) {
			return ['success' => false, 'message' => 'Geçersiz seçim kuralı veya biçim.', 'created' => 0];
		}

		$count = max(1, min(14, $count));
		$where = 'p.active = 1 AND p.stock > 0';
		$args = [];

		if ($owner === 'vendor') {
			$where .= ' AND vp.id_vendor = ?';
			$args[] = $idVendor;
		}

		$order = match ($rule) {
			'best' => '(SELECT COALESCE(SUM(od.qty),0) FROM order_detail od INNER JOIN orders o ON o.id_order = od.id_order WHERE od.id_product = p.id_product AND o.status NOT IN (5,6)) DESC',
			'in_stock' => 'p.stock DESC',
			default => 'p.id_product DESC',
		};

		if ($rule === 'campaign') {
			$where .= ' AND p.old_price > p.price';
		}

		if ($rule === 'featured') {
			$where .= RsoContent::tableExists('rs_items') ? " AND p.id_product IN (SELECT id_product FROM rs_items WHERE status = 'approved')" : ' AND 1=0';
		}

		$rows = DB::execute('SELECT p.id_product FROM products p ' . ($owner === 'vendor' ? 'INNER' : 'LEFT') . ' JOIN rafimda_vendor_products vp ON vp.id_product = p.id_product WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT 60', $args) ?: [];
		$day = DateTimeImmutable::createFromFormat('Y-m-d H:i', $startDay . ' ' . $time, self::tz());

		if (!$day) {
			return ['success' => false, 'message' => 'Başlangıç günü/saati geçersiz.', 'created' => 0];
		}

		$created = 0;
		$skipped = [];
		$slot = 0;

		foreach ($rows as $r) {
			if ($created >= $count) {
				break;
			}

			// Dolu saat varsa bir sonraki güne kaydır (en fazla 14 gün ileri)
			for ($try = 0; $try < 14; $try++) {
				$at = $day->modify('+' . $slot . ' day')->format('Y-m-d H:i');
				$res = self::create($owner, $idVendor, (int) $r['id_product'], $format, $at, $actor, 'auto:' . $rule);

				if ($res['success']) {
					$created++;
					$slot++;
					break;
				}

				if (str_contains($res['message'], 'Aynı saatte')) {
					$slot++;
					continue;
				}

				$skipped[] = $res['message'];
				break;
			}
		}

		return ['success' => $created > 0, 'message' => "$created içerik planlandı (" . self::SELECTIONS[$rule] . ').' . ($skipped ? ' Atlanan: ' . count($skipped) . ' (tekrar engeli/uygunsuz).' : ''), 'created' => $created];
	}

	/** @return array<int,array<string,mixed>> takvim: gün/hafta/ay (TR) */
	public static function calendar(string $owner, int $idVendor, string $view, string $anchor): array
	{
		$a = DateTimeImmutable::createFromFormat('Y-m-d', $anchor, self::tz()) ?: self::now();
		[$from, $to] = match ($view) {
			'day' => [$a->format('Y-m-d 00:00:00'), $a->format('Y-m-d 23:59:59')],
			'month' => [$a->format('Y-m-01 00:00:00'), $a->modify('last day of this month')->format('Y-m-d 23:59:59')],
			default => [$a->modify('monday this week')->format('Y-m-d 00:00:00'), $a->modify('sunday this week')->format('Y-m-d 23:59:59')],
		};
		$sql = 'SELECT s.*, p.product_name FROM rso_posts s LEFT JOIN products p ON p.id_product = s.id_product WHERE s.scheduled_at BETWEEN ? AND ?';
		$args = [$from, $to];

		if ($owner === 'vendor') {
			$sql .= " AND s.owner_type = 'vendor' AND s.id_vendor = ?";
			$args[] = $idVendor;
		}

		return DB::execute($sql . ' ORDER BY s.scheduled_at', $args) ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function listFor(string $owner, int $idVendor, string $status = ''): array
	{
		$sql = 'SELECT s.*, p.product_name, v.store_name FROM rso_posts s LEFT JOIN products p ON p.id_product = s.id_product LEFT JOIN rafimda_vendors v ON v.id_vendor = s.id_vendor WHERE 1=1';
		$args = [];

		if ($owner === 'vendor') {
			$sql .= " AND s.owner_type = 'vendor' AND s.id_vendor = ?";
			$args[] = $idVendor;
		}

		if (isset(self::STATUSES[$status])) {
			$sql .= ' AND s.status = ?';
			$args[] = $status;
		}

		return DB::execute($sql . ' ORDER BY COALESCE(s.scheduled_at, s.date_add) DESC LIMIT 200', $args) ?: [];
	}

	// ---------- Hesaplar ve OAuth ----------

	public static function account(string $owner, int $idVendor, string $platform): ?array
	{
		return DB::getRowSafe('rso_accounts', 'owner_type = ? AND id_vendor = ? AND platform = ?', [$owner === 'vendor' ? 'vendor' : 'platform', $owner === 'vendor' ? $idVendor : 0, $platform]) ?: null;
	}

	/** @return array<string,array<string,mixed>> platform → güvenli görünüm (token yok) */
	public static function accounts(string $owner, int $idVendor): array
	{
		$out = [];

		foreach (self::PLATFORMS as $k => $l) {
			$a = self::account($owner, $idVendor, $k);
			$out[$k] = ['label' => $l, 'status' => $a['status'] ?? 'not_connected', 'display_name' => $a['display_name'] ?? '', 'last_error' => $a['last_error'] ?? '',
				'has_token' => (string) ($a['token_enc'] ?? '') !== '', 'app_configured' => self::clientId($k) !== '', 'publisher' => self::publishers()[$k]->label(), 'ready' => self::publishers()[$k]->isImplemented()];
		}

		return $out;
	}

	public static function clientId(string $platform): string
	{
		return trim((string) Settings::get('RAFIMDA_SOCIAL_' . strtoupper($platform) . '_CLIENT_ID'));
	}

	/** OAuth başlat: durum (state) CSRF koruması için hesap kaydına yazılır. */
	public static function connectUrl(string $owner, int $idVendor, string $platform, string $redirectUri): array
	{
		if (!isset(self::PLATFORMS[$platform])) {
			return ['success' => false, 'message' => 'Geçersiz platform.'];
		}

		$cid = self::clientId($platform);

		if ($cid === '') {
			return ['success' => false, 'message' => self::PLATFORMS[$platform] . ' uygulama kimliği (client ID) yapılandırılmadı. Resmî geliştirici hesabında uygulama oluşturulup incelemeden geçmesi gerekir.'];
		}

		$state = bin2hex(random_bytes(16));
		$owner = $owner === 'vendor' ? 'vendor' : 'platform';
		$idVendor = $owner === 'vendor' ? $idVendor : 0;

		if (self::account($owner, $idVendor, $platform)) {
			DB::update('rso_accounts', ['oauth_state' => $state, 'date_upd' => date('Y-m-d H:i:s')], 'owner_type = :o AND id_vendor = :v AND platform = :p', ['o' => $owner, 'v' => $idVendor, 'p' => $platform]);
		} else {
			DB::insert('rso_accounts', ['owner_type' => $owner, 'id_vendor' => $idVendor, 'platform' => $platform, 'status' => 'not_connected', 'oauth_state' => $state, 'date_upd' => date('Y-m-d H:i:s')]);
		}

		return ['success' => true, 'url' => self::publishers()[$platform]->authorizeUrl($cid, $redirectUri, $platform . '.' . $state), 'message' => ''];
	}

	/** OAuth dönüşü: state doğrulanır; kod değişimi yayıncıya bırakılır (uygulanmadıysa açık hata). */
	public static function oauthCallback(string $stateParam, string $code, string $redirectUri): array
	{
		[$platform, $state] = array_pad(explode('.', $stateParam, 2), 2, '');

		if (!isset(self::PLATFORMS[$platform]) || !preg_match('/^[a-f0-9]{32}$/', $state)) {
			return ['success' => false, 'message' => 'Geçersiz yetkilendirme yanıtı.'];
		}

		$acc = DB::getRowSafe('rso_accounts', 'platform = ? AND oauth_state = ?', [$platform, $state]);

		if (!$acc) {
			return ['success' => false, 'message' => 'Yetkilendirme isteği bulunamadı veya süresi doldu (CSRF koruması).'];
		}

		DB::update('rso_accounts', ['oauth_state' => ''], 'id_account = :a', ['a' => (int) $acc['id_account']]); // tek kullanımlık
		$r = self::publishers()[$platform]->exchangeCode($code, $redirectUri);

		if (!$r['success'] || empty($r['token'])) {
			DB::update('rso_accounts', ['status' => 'error', 'last_error' => mb_substr($r['message'], 0, 500), 'date_upd' => date('Y-m-d H:i:s')], 'id_account = :a', ['a' => (int) $acc['id_account']]);
			self::event(0, 'oauth_failed', self::PLATFORMS[$platform] . ': ' . $r['message'], true, $acc['owner_type']);

			return ['success' => false, 'message' => $r['message']];
		}

		DB::update('rso_accounts', ['status' => 'connected', 'token_enc' => RsoCrypt::encrypt((string) $r['token']), 'token_expires' => isset($r['expires_in']) ? date('Y-m-d H:i:s', time() + (int) $r['expires_in']) : null,
			'last_error' => '', 'date_upd' => date('Y-m-d H:i:s')], 'id_account = :a', ['a' => (int) $acc['id_account']]);

		return ['success' => true, 'message' => self::PLATFORMS[$platform] . ' hesabı bağlandı.'];
	}

	/** Bağlantıyı kaldır: token silinir. */
	public static function disconnect(string $owner, int $idVendor, string $platform): bool
	{
		$a = self::account($owner, $idVendor, $platform);

		return $a !== null && DB::update('rso_accounts', ['status' => 'revoked', 'token_enc' => '', 'token_expires' => null, 'date_upd' => date('Y-m-d H:i:s')], 'id_account = :a', ['a' => (int) $a['id_account']]) !== false;
	}

	/** @return array<string,mixed> rapor: durum sayıları + API'den gelen ölçümler (yoksa boş) */
	public static function report(string $owner, int $idVendor): array
	{
		$sql = 'SELECT status, COUNT(*) AS n FROM rso_posts' . ($owner === 'vendor' ? " WHERE owner_type = 'vendor' AND id_vendor = ?" : '') . ' GROUP BY status';
		$m = DB::execute('SELECT COALESCE(SUM(m.views),0) AS views, COALESCE(SUM(m.reach),0) AS reach, COALESCE(SUM(m.likes),0) AS likes, COALESCE(SUM(m.comments),0) AS comments, COALESCE(SUM(m.shares),0) AS shares, COUNT(m.id_metric) AS n
			FROM rso_metrics m INNER JOIN rso_posts s ON s.id_post = m.id_post' . ($owner === 'vendor' ? " WHERE s.owner_type = 'vendor' AND s.id_vendor = ?" : ''), $owner === 'vendor' ? [$idVendor] : []) ?: [];

		return ['counts' => array_column(DB::execute($sql, $owner === 'vendor' ? [$idVendor] : []) ?: [], 'n', 'status'), 'metrics' => $m[0] ?? [], 'has_metrics' => (int) ($m[0]['n'] ?? 0) > 0];
	}
}
