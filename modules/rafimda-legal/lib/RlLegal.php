<?php

/**
 * Rafımda hukuki belgeler, sürümler, kabul kayıtları ve izinler (KVKK / ticari ileti / çerez).
 * - Belgeler sürümlüdür; yayınlanmış sürüm DEĞİŞTİRİLMEZ, yeni sürüm oluşturulur (kabul kaydı hangi metnin kabul edildiğini ispatlar).
 * - Aydınlatma, açık rıza ve ticari ileti izni AYRI tutulur; zorunlu olmayan izinler varsayılan olarak KAPALIDIR.
 * - Şirket bilgileri uydurulmaz: yönetim panelinde doldurulur; boş alanlar sayfada "[DOLDURULMADI: …]" olarak görünür.
 * - IP ve tarayıcı bilgisi düz metin değil, SHA-256 özetle saklanır.
 */
final class RlLegal
{
	public const STATUSES = ['draft' => 'Taslak', 'published' => 'Yayında', 'passive' => 'Pasif (gösterilmez)'];
	public const GROUPS = ['kvkk' => 'KVKK ve gizlilik', 'customer' => 'Üyelik ve kullanım', 'order' => 'Sipariş ve iade', 'vendor' => 'Satıcı', 'dealer' => 'Bayilik (pasif)'];

	/** Şirket ve sözleşme yer tutucuları → ayar anahtarı ve etiket. Değerler UYDURULMAZ. */
	public const FIELDS = [
		'SIRKET_UNVANI' => ['RAFIMDA_LEGAL_COMPANY', 'Şirket unvanı'],
		'MERSIS_NO' => ['RAFIMDA_LEGAL_MERSIS', 'MERSİS numarası'],
		'VERGI_DAIRESI' => ['RAFIMDA_LEGAL_TAX_OFFICE', 'Vergi dairesi'],
		'VERGI_NO' => ['RAFIMDA_LEGAL_TAX_NO', 'Vergi numarası'],
		'TICARET_SICIL_NO' => ['RAFIMDA_LEGAL_TRADE_REG', 'Ticaret sicil numarası'],
		'ADRES' => ['RAFIMDA_LEGAL_ADDRESS', 'Merkez adresi'],
		'TELEFON' => ['RAFIMDA_LEGAL_PHONE', 'Telefon'],
		'EPOSTA' => ['RAFIMDA_LEGAL_EMAIL', 'İletişim e-postası'],
		'KEP' => ['RAFIMDA_LEGAL_KEP', 'KEP adresi'],
		'ETBIS_NO' => ['RAFIMDA_LEGAL_ETBIS', 'ETBİS kayıt bilgisi'],
		'KVKK_BASVURU_EPOSTA' => ['RAFIMDA_LEGAL_KVKK_EMAIL', 'KVKK başvuru e-postası'],
		'YURT_DISI_AKTARIM' => ['RAFIMDA_LEGAL_TRANSFER', 'Yurt dışı aktarım açıklaması'],
		'CEREZ_LISTESI' => ['RAFIMDA_LEGAL_COOKIES', 'Kullanılan çerezler listesi'],
		'IYS_DURUMU' => ['RAFIMDA_LEGAL_IYS', 'İYS kayıt durumu'],
		'ODEME_VADESI' => ['RAFIMDA_LEGAL_PAYOUT_TERM', 'Satıcı ödeme vadesi'],
		'FESIH_SURESI' => ['RAFIMDA_LEGAL_TERMINATION', 'Sözleşme fesih bildirim süresi'],
		'IADE_KARGO' => ['RAFIMDA_LEGAL_RETURN_SHIPPING', 'İade kargo ücreti kuralı'],
	];

	/** Zorunlu olmayan izin türleri (hepsi varsayılan KAPALI). */
	public const CONSENTS = [
		'commercial_email' => 'Ticari ileti: e-posta',
		'commercial_sms' => 'Ticari ileti: SMS',
		'commercial_phone' => 'Ticari ileti: telefon',
		'explicit_consent' => 'Açık rıza',
		'cookie_analytics' => 'Analitik çerezler',
		'cookie_marketing' => 'Pazarlama çerezleri',
	];

	public const COOKIE = 'rl_cookie_prefs';

	/** Testler için çerez yazıcı: fn(string $name, string $value, int $expires): void */
	public static $cookieWriter = null;

	public static function installDefaults(): void
	{
		$pos = 0;

		foreach (RlSeed::documents() as $d) {
			$pos += 10;

			if (DB::getRowSafe('rl_documents', 'slug = ?', [$d['slug']])) {
				continue; // mevcut belge ve sürümler KORUNUR
			}

			$id = (int) DB::insert('rl_documents', [
				'slug' => $d['slug'], 'title' => $d['title'], 'grp' => $d['grp'], 'status' => $d['status'], 'needs_review' => 1,
				'review_note' => mb_substr($d['review'], 0, 255), 'position' => $pos, 'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s'),
			]);

			if ($id > 0) {
				self::saveVersion($id, $d['body'], 'İlk taslak (hukuki inceleme bekliyor)', 0, $d['status'] === 'published');
			}
		}
	}

	public static function bySlug(string $slug): ?array
	{
		return DB::getRowSafe('rl_documents', 'slug = ?', [$slug]) ?: null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function all(bool $publicOnly = false): array
	{
		$sql = 'SELECT d.*, v.version AS version_label, v.published_at FROM rl_documents d LEFT JOIN rl_versions v ON v.id_version = d.current_version';

		if ($publicOnly) {
			$sql .= " WHERE d.status = 'published' AND d.current_version > 0";
		}

		return DB::execute($sql . ' ORDER BY d.position, d.id_document') ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function versions(int $idDoc): array
	{
		return DB::execute('SELECT id_version, version, change_note, body_sha256, created_by, published_at, date_add FROM rl_versions WHERE id_document = ? ORDER BY id_version DESC', [$idDoc]) ?: [];
	}

	public static function version(int $idVersion): ?array
	{
		return DB::getRowSafe('rl_versions', 'id_version = ?', [$idVersion]) ?: null;
	}

	/**
	 * Yeni sürüm kaydeder (eski sürümler değişmez). $publish = true ise belgenin geçerli sürümü olur.
	 * @return array{success:bool,message:string,id_version:int}
	 */
	public static function saveVersion(int $idDoc, string $body, string $note, int $adminId, bool $publish): array
	{
		$doc = DB::getRowSafe('rl_documents', 'id_document = ?', [$idDoc]);
		$body = str_replace("\r\n", "\n", trim($body));

		if (!$doc || mb_strlen($body) < 20) {
			return ['success' => false, 'message' => 'Belge bulunamadı veya metin çok kısa.', 'id_version' => 0];
		}

		if (mb_strlen($body) > 200000) {
			return ['success' => false, 'message' => 'Metin çok uzun.', 'id_version' => 0];
		}

		$count = (int) DB::getValue('SELECT COUNT(*) FROM rl_versions WHERE id_document = ?', [$idDoc]);
		$label = '1.' . $count;
		$id = (int) DB::insert('rl_versions', [
			'id_document' => $idDoc, 'version' => $label, 'body' => $body, 'change_note' => mb_substr(strip_tags($note), 0, 255),
			'body_sha256' => hash('sha256', $body), 'created_by' => $adminId, 'published_at' => $publish ? date('Y-m-d H:i:s') : null, 'date_add' => date('Y-m-d H:i:s'),
		]);

		if ($id > 0 && $publish) {
			DB::update('rl_documents', ['current_version' => $id, 'date_upd' => date('Y-m-d H:i:s')], 'id_document = :i', ['i' => $idDoc]);
		}

		return ['success' => $id > 0, 'message' => $publish ? "Sürüm $label yayınlandı." : "Sürüm $label taslak olarak kaydedildi.", 'id_version' => $id];
	}

	/** Taslak sürümü yayınlar (metni değiştirmeden). */
	public static function publishVersion(int $idDoc, int $idVersion): bool
	{
		$v = DB::getRowSafe('rl_versions', 'id_version = ? AND id_document = ?', [$idVersion, $idDoc]);

		if (!$v) {
			return false;
		}

		if (empty($v['published_at'])) {
			DB::update('rl_versions', ['published_at' => date('Y-m-d H:i:s')], 'id_version = :v', ['v' => $idVersion]);
		}

		return DB::update('rl_documents', ['current_version' => $idVersion, 'date_upd' => date('Y-m-d H:i:s')], 'id_document = :i', ['i' => $idDoc]) !== false;
	}

	public static function setMeta(int $idDoc, string $status, bool $needsReview, string $note): bool
	{
		$doc = DB::getRowSafe('rl_documents', 'id_document = ?', [$idDoc]);

		if (!$doc || !isset(self::STATUSES[$status])) {
			return false;
		}

		// Bayilik belgeleri, bayilik sistemi etkinleştirilmeden yayınlanamaz
		if ($doc['grp'] === 'dealer' && $status === 'published' && !(class_exists('RdSwitch') && RdSwitch::enabled())) {
			return false;
		}

		return DB::update('rl_documents', ['status' => $status, 'needs_review' => $needsReview ? 1 : 0, 'review_note' => mb_substr(strip_tags($note), 0, 255), 'date_upd' => date('Y-m-d H:i:s')], 'id_document = :i', ['i' => $idDoc]) !== false;
	}

	/** @return array<string,array{key:string,label:string,value:string}> */
	public static function company(): array
	{
		$out = [];

		foreach (self::FIELDS as $ph => [$key, $label]) {
			$out[$ph] = ['key' => $key, 'label' => $label, 'value' => (string) Settings::get($key)];
		}

		return $out;
	}

	/** @param array<string,mixed> $in */
	public static function saveCompany(array $in): int
	{
		$n = 0;

		foreach (self::FIELDS as $ph => [$key]) {
			if (array_key_exists($key, $in)) {
				Settings::set($key, mb_substr(trim(strip_tags((string) $in[$key])), 0, 1000));
				$n++;
			}
		}

		return $n;
	}

	/** @return list<string> doldurulmamış şirket alanı etiketleri */
	public static function missingFields(): array
	{
		return array_values(array_map(static fn($f) => $f['label'], array_filter(self::company(), static fn($f) => trim($f['value']) === '')));
	}

	/**
	 * Güvenli işleme: metnin tamamı kaçışlanır, ardından sınırlı biçim (başlık, madde, kalın) uygulanır. HTML enjekte edilemez.
	 */
	public static function render(string $body, array $version = []): string
	{
		global $domain;
		$vals = [];

		foreach (self::company() as $ph => $f) {
			$vals[$ph] = $f['value'];
		}

		$vals['SITE_ADRESI'] = rtrim((string) ($domain ?? Settings::get('DOMAIN')), '/');
		$vals['SURUM'] = (string) ($version['version'] ?? '');
		$vals['YURURLUK_TARIHI'] = !empty($version['published_at']) ? date('d.m.Y', strtotime((string) $version['published_at'])) : '';
		$labels = array_map(static fn($f) => $f[1], self::FIELDS) + ['SITE_ADRESI' => 'Site adresi', 'SURUM' => 'Sürüm', 'YURURLUK_TARIHI' => 'Yürürlük tarihi'];

		$html = '';
		$list = false;
		$para = [];
		$flush = static function () use (&$para, &$html): void {
			if ($para !== []) {
				$html .= '<p>' . implode('<br>', $para) . "</p>\n";
				$para = [];
			}
		};
		$fmt = static function (string $line) use ($vals, $labels): string {
			$s = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
			$s = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);

			return (string) preg_replace_callback('/\{\{([A-Z_]+)\}\}/', static function ($m) use ($vals, $labels) {
				$v = trim((string) ($vals[$m[1]] ?? ''));

				return $v !== '' ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : '<mark class="rl-missing">[DOLDURULMADI: ' . htmlspecialchars($labels[$m[1]] ?? $m[1], ENT_QUOTES, 'UTF-8') . ']</mark>';
			}, $s);
		};

		foreach (explode("\n", $body) as $raw) {
			$line = rtrim($raw);

			if (str_starts_with($line, '- ')) {
				$flush();
				$html .= ($list ? '' : "<ul>\n") . '<li>' . $fmt(substr($line, 2)) . "</li>\n";
				$list = true;
				continue;
			}

			if ($list) {
				$html .= "</ul>\n";
				$list = false;
			}

			if (str_starts_with($line, '### ')) {
				$flush();
				$html .= '<h3>' . $fmt(substr($line, 4)) . "</h3>\n";
			} elseif (str_starts_with($line, '## ')) {
				$flush();
				$html .= '<h2>' . $fmt(substr($line, 3)) . "</h2>\n";
			} elseif (trim($line) === '') {
				$flush();
			} else {
				$para[] = $fmt($line);
			}
		}

		if ($list) {
			$html .= "</ul>\n";
		}

		$flush();

		return $html;
	}

	/** Yayındaki belge + geçerli sürüm (pasif/taslak belge ziyaretçiye gösterilmez). */
	public static function published(string $slug): ?array
	{
		$doc = self::bySlug($slug);

		if (!$doc || $doc['status'] !== 'published' || (int) $doc['current_version'] <= 0) {
			return null;
		}

		$v = self::version((int) $doc['current_version']);

		return $v ? ['doc' => $doc, 'version' => $v, 'html' => self::render((string) $v['body'], $v)] : null;
	}

	public static function url(string $slug): string
	{
		global $domain;

		return rtrim((string) ($domain ?? ''), '/') . '/hukuki?belge=' . rawurlencode($slug);
	}

	/** @param list<string> $slugs @return array<string,string> yalnızca YAYINDAKİ belgelerin bağlantıları */
	public static function linksFor(array $slugs): array
	{
		$out = [];

		foreach ($slugs as $s) {
			$d = self::bySlug($s);

			if ($d && $d['status'] === 'published' && (int) $d['current_version'] > 0) {
				$out[$s] = self::url($s);
			}
		}

		return $out;
	}

	private static function hashReq(string $v): string
	{
		return $v === '' ? '' : hash('sha256', $v . '|' . (string) Settings::get('RAFIMDA_LEGAL_SALT'));
	}

	/** Kabul kaydı: belgenin O ANDA yayında olan sürümüne bağlanır. */
	public static function accept(string $slug, int $idUser, string $context, string $ref = ''): bool
	{
		$doc = self::bySlug($slug);

		if (!$doc || $doc['status'] !== 'published' || (int) $doc['current_version'] <= 0) {
			return false;
		}

		$v = self::version((int) $doc['current_version']);

		return (bool) DB::insert('rl_acceptances', [
			'id_document' => (int) $doc['id_document'], 'id_version' => (int) $doc['current_version'], 'slug' => $slug, 'version' => (string) ($v['version'] ?? ''),
			'id_user' => $idUser, 'context' => mb_substr($context, 0, 24), 'ref' => mb_substr($ref, 0, 64),
			'ip_hash' => self::hashReq((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 'ua_hash' => self::hashReq((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
			'accepted_at' => date('Y-m-d H:i:s'),
		]);
	}

	/** @return array<int,array<string,mixed>> */
	public static function acceptances(int $idUser = 0, string $slug = '', int $limit = 200): array
	{
		$sql = 'SELECT * FROM rl_acceptances WHERE 1=1';
		$args = [];

		if ($idUser > 0) {
			$sql .= ' AND id_user = ?';
			$args[] = $idUser;
		}

		if ($slug !== '') {
			$sql .= ' AND slug = ?';
			$args[] = $slug;
		}

		return DB::execute($sql . ' ORDER BY id_acceptance DESC LIMIT ' . max(1, min(1000, $limit)), $args) ?: [];
	}

	/** İzin kaydı (ekleme-yalnızca günlük; son kayıt geçerli durumdur). */
	public static function setConsent(int $idUser, string $visitor, string $type, bool $granted, string $source): bool
	{
		if (!isset(self::CONSENTS[$type]) || ($idUser <= 0 && $visitor === '')) {
			return false;
		}

		$slug = str_starts_with($type, 'commercial_') ? 'ticari-ileti-izni' : (str_starts_with($type, 'cookie_') ? 'cerez-politikasi' : 'acik-riza');
		$doc = self::bySlug($slug);

		return (bool) DB::insert('rl_consents', [
			'id_user' => $idUser, 'visitor' => mb_substr(preg_replace('/[^a-f0-9]/', '', $visitor) ?? '', 0, 32), 'consent_type' => $type, 'granted' => $granted ? 1 : 0,
			'source' => mb_substr($source, 0, 24), 'id_version' => (int) ($doc['current_version'] ?? 0),
			'ip_hash' => self::hashReq((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 'date_add' => date('Y-m-d H:i:s'),
		]);
	}

	/** @return array<string,bool> kullanıcının güncel izinleri (kayıt yoksa false) */
	public static function consentsFor(int $idUser): array
	{
		$out = array_fill_keys(array_keys(self::CONSENTS), false);

		if ($idUser <= 0) {
			return $out;
		}

		foreach (array_keys(self::CONSENTS) as $t) {
			$g = DB::getValue('SELECT granted FROM rl_consents WHERE id_user = ? AND consent_type = ? ORDER BY id_consent DESC LIMIT 1', [$idUser, $t]);
			$out[$t] = (string) $g === '1';
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public static function consentLog(string $type = '', int $limit = 300): array
	{
		$sql = 'SELECT * FROM rl_consents';
		$args = [];

		if (isset(self::CONSENTS[$type])) {
			$sql .= ' WHERE consent_type = ?';
			$args[] = $type;
		}

		return DB::execute($sql . ' ORDER BY id_consent DESC LIMIT ' . max(1, min(2000, $limit)), $args) ?: [];
	}

	/** @return array{analytics:bool,marketing:bool,set:bool} çerez tercihi (yoksa hepsi kapalı) */
	public static function cookiePrefs(): array
	{
		$raw = (string) ($_COOKIE[self::COOKIE] ?? '');
		$d = json_decode($raw, true);

		if (!is_array($d)) {
			return ['analytics' => false, 'marketing' => false, 'set' => false];
		}

		return ['analytics' => !empty($d['a']), 'marketing' => !empty($d['m']), 'set' => true];
	}

	/** Diğer modüller (Google Analytics, Meta Pixel) yalnızca izin varsa betik basar. */
	public static function cookieAllowed(string $category): bool
	{
		$p = self::cookiePrefs();

		return $category === 'necessary' || !empty($p[$category]);
	}

	public static function saveCookiePrefs(bool $analytics, bool $marketing, int $idUser): void
	{
		$visitor = (string) ($_COOKIE['rl_vid'] ?? '');

		if (!preg_match('/^[a-f0-9]{32}$/', $visitor)) {
			$visitor = bin2hex(random_bytes(16));
			self::writeCookie('rl_vid', $visitor, time() + 31536000);
		}

		$value = (string) json_encode(['a' => $analytics ? 1 : 0, 'm' => $marketing ? 1 : 0, 't' => time()]);
		self::writeCookie(self::COOKIE, $value, time() + 15552000); // 6 ay
		$_COOKIE[self::COOKIE] = $value;
		self::setConsent($idUser, $visitor, 'cookie_analytics', $analytics, 'cookie_banner');
		self::setConsent($idUser, $visitor, 'cookie_marketing', $marketing, 'cookie_banner');
	}

	private static function writeCookie(string $name, string $value, int $expires): void
	{
		if (is_callable(self::$cookieWriter)) {
			(self::$cookieWriter)($name, $value, $expires);

			return;
		}

		if (!headers_sent()) {
			setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => false, 'samesite' => 'Lax']);
		}
	}

	/** Kayıt formundan gelen seçimleri (form.captcha.validate sırasında) oturumda bekletir; hesap oluşunca bağlanır. */
	public static function stashRegistration(array $post): void
	{
		$_SESSION['rl_pending_reg'] = [
			'email' => strtolower(trim((string) ($post['email'] ?? ''))),
			'at' => time(),
			'membership' => !empty($post['rl_membership']),
			'commercial' => !empty($post['rl_commercial']),
			'explicit' => !empty($post['rl_explicit']),
		];
	}

	/** Oturumdaki bekleyen kayıt onaylarını, e-postası eşleşen ve yeni oluşan hesaba bağlar (30 dk içinde). */
	public static function attachPendingRegistration(int $idUser, string $email): bool
	{
		$p = $_SESSION['rl_pending_reg'] ?? null;

		if (!is_array($p) || $idUser <= 0) {
			return false;
		}

		if (strtolower(trim($email)) !== $p['email'] || time() - (int) $p['at'] > 1800) {
			unset($_SESSION['rl_pending_reg']);

			return false;
		}

		unset($_SESSION['rl_pending_reg']);

		if ($p['membership']) {
			self::accept('uyelik-sozlesmesi', $idUser, 'register');
			self::accept('kvkk-aydinlatma', $idUser, 'register');
		}

		// Ticari ileti onayı yalnızca kullanıcı işaretlediyse kaydedilir (varsayılan kapalı)
		if ($p['commercial']) {
			self::setConsent($idUser, '', 'commercial_email', true, 'register');
		}

		if ($p['explicit']) {
			self::setConsent($idUser, '', 'explicit_consent', true, 'register');
		}

		return true;
	}

	/** Sipariş anında: mesafeli satış + ön bilgilendirme kabulünü siparişe bağlar (checkout'ta onay kutusu zorunludur). */
	public static function onOrderPlaced(array $order): void
	{
		$id = (int) ($order['id_order'] ?? 0);
		$user = (int) ($order['id_user'] ?? ($order['id_customer'] ?? 0));

		if ($id <= 0 || DB::getValue('SELECT COUNT(*) FROM rl_acceptances WHERE ref = ? AND context = ?', ['order:' . $id, 'checkout'])) {
			return;
		}

		foreach (['on-bilgilendirme-formu', 'mesafeli-satis-sozlesmesi'] as $slug) {
			self::accept($slug, $user, 'checkout', 'order:' . $id);
		}
	}

	/**
	 * FriSay çekirdeği ödeme sayfasında CMS sayfalarını (mesafeli-satis, gizlilik) gösterir. Yönetici isterse yayındaki sürüm CMS'e kopyalanır.
	 * Yalnızca yöneticinin açık işlemiyle çalışır; otomatik değildir.
	 */
	public static function syncToCms(): array
	{
		if (!class_exists('Cms') || !method_exists('Cms', 'getBySlug')) {
			return ['success' => false, 'message' => 'CMS bulunamadı.'];
		}

		$map = ['mesafeli-satis' => 'mesafeli-satis-sozlesmesi', 'gizlilik' => 'gizlilik-politikasi'];
		$done = [];

		foreach ($map as $cmsSlug => $slug) {
			$p = self::published($slug);
			$cms = Cms::getBySlug($cmsSlug);

			if (!$p || !$cms || empty($cms['id_cms'])) {
				continue;
			}

			$ok = DB::update('cms_lang', ['content' => $p['html']], 'id_cms = :c AND slug = :s', ['c' => (int) $cms['id_cms'], 's' => $cmsSlug]);

			if ($ok !== false) {
				$done[] = $cmsSlug;
			}
		}

		return ['success' => $done !== [], 'message' => $done ? 'CMS güncellendi: ' . implode(', ', $done) : 'Güncellenecek CMS sayfası bulunamadı (mesafeli-satis / gizlilik).'];
	}
}
