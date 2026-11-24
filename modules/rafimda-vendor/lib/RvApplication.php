<?php

/**
 * Satıcı başvurusu (ilk lansmanda AKTİF): başvuru numarası, şahıs/tüzel kişi, vergi ve adres bilgileri, kategoriler, belge yükleme,
 * durum akışı (Yeni → İnceleniyor → Belge Bekleniyor → Onaylandı / Reddedildi), yönetici incelemesi ve başvuru sahibine bildirim.
 *
 * Mağaza kaydı başvuru anında PASİF (pending) olarak açılır ve yalnızca başvuru ONAYLANINCA etkinleşir (vitrin/panel o zaman açılır).
 * Belgeler web kökünden erişilemeyen storage/ altında, rastgele adla saklanır; yalnızca başvuru sahibi ve yönetici PHP üzerinden indirebilir.
 */
final class RvApplication
{
	public const STATUSES = [
		'new' => 'Yeni',
		'reviewing' => 'İnceleniyor',
		'docs_required' => 'Belge Bekleniyor',
		'approved' => 'Onaylandı',
		'rejected' => 'Reddedildi',
	];

	/** İzin verilen durum geçişleri (yönetici). Onaylanan başvuru kapanır. */
	public const TRANSITIONS = [
		'new' => ['reviewing', 'docs_required', 'approved', 'rejected'],
		'reviewing' => ['docs_required', 'approved', 'rejected'],
		'docs_required' => ['reviewing', 'approved', 'rejected'],
		'rejected' => ['reviewing'],
		'approved' => [],
	];

	public const PERSON_TYPES = ['individual' => 'Şahıs (gerçek kişi)', 'company' => 'Tüzel kişi (şirket)'];

	public const DOC_TYPES = [
		'tax_plate' => 'Vergi levhası',
		'signature' => 'İmza sirküleri / beyannamesi',
		'trade_registry' => 'Ticaret sicil gazetesi',
		'id_card' => 'Kimlik (yetkili)',
		'iban' => 'IBAN / banka belgesi',
		'other' => 'Diğer',
	];

	public const MAX_DOC_BYTES = 5242880; // 5 MB
	public const MAX_DOCS = 10;
	public const ALLOWED = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
	public const TERMS_VERSION_SETTING = 'RAFIMDA_VENDOR_TERMS_VERSION';

	/** Testlerde move_uploaded_file yerine: fn(string $from, string $to): bool */
	public static $mover = null;

	/** Testlerde geçici dizin kullanmak için */
	public static ?string $storageOverride = null;

	public static function storageDir(): string
	{
		return self::$storageOverride ?? dirname(__DIR__, 3) . '/storage/rafimda/vendor-docs';
	}

	/** T.C. kimlik numarası algoritma kontrolü (yalnız biçim; kimlik doğrulaması DEĞİLDİR). */
	public static function validTckn(string $n): bool
	{
		if (!preg_match('/^[1-9]\d{10}$/', $n)) {
			return false;
		}

		$d = array_map('intval', str_split($n));
		$odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
		$even = $d[1] + $d[3] + $d[5] + $d[7];

		if (((($odd * 7) - $even) % 10 + 10) % 10 !== $d[9]) {
			return false;
		}

		return array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
	}

	/**
	 * Başvuruya özgü alanlar (mağaza alanları RvVendor::validateStore ile ayrıca doğrulanır).
	 *
	 * @param array<string,mixed> $in
	 * @return array{ok:bool, errors:array<string,string>, clean:array<string,mixed>}
	 */
	public static function validate(array $in, array $storeClean): array
	{
		$errors = [];
		$type = (string) ($in['person_type'] ?? '');
		$clean = [
			'person_type' => isset(self::PERSON_TYPES[$type]) ? $type : '',
			'contact_name' => mb_substr(trim(strip_tags((string) ($in['contact_name'] ?? ''))), 0, 120),
			'address' => mb_substr(trim(strip_tags((string) ($in['address'] ?? ''))), 0, 255),
			'district' => mb_substr(trim(strip_tags((string) ($in['district'] ?? ''))), 0, 80),
			'categories' => [],
		];

		if ($clean['person_type'] === '') {
			$errors['person_type'] = 'Şahıs veya tüzel kişi seçin.';
		}

		if (mb_strlen($clean['contact_name']) < 3) {
			$errors['contact_name'] = 'Yetkili kişinin adını girin.';
		}

		if (mb_strlen($clean['address']) < 10) {
			$errors['address'] = 'Açık adres girin (en az 10 karakter).';
		}

		if (trim((string) ($storeClean['city'] ?? '')) === '') {
			$errors['city'] = 'İl girin.';
		}

		if (trim((string) ($storeClean['phone'] ?? '')) === '') {
			$errors['phone'] = 'İletişim telefonu girin.';
		}

		if (trim((string) ($storeClean['email'] ?? '')) === '') {
			$errors['email'] = 'İletişim e-postası girin.';
		}

		$tax = (string) ($storeClean['tax_number'] ?? '');

		if ($clean['person_type'] === 'company') {
			if (strlen($tax) !== 10) {
				$errors['tax_number'] = 'Tüzel kişi için 10 haneli vergi kimlik numarası girin.';
			}

			if (trim((string) ($storeClean['legal_name'] ?? '')) === '') {
				$errors['legal_name'] = 'Şirket unvanını girin.';
			}
		} elseif ($clean['person_type'] === 'individual' && !self::validTckn($tax)) {
			$errors['tax_number'] = 'Şahıs için geçerli 11 haneli T.C. kimlik numarası girin.';
		}

		if (trim((string) ($storeClean['tax_office'] ?? '')) === '') {
			$errors['tax_office'] = 'Vergi dairesini girin.';
		}

		$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['categories'] ?? [])))));

		if ($ids !== []) {
			$ph = implode(',', array_fill(0, count($ids), '?'));
			$rows = DB::execute('SELECT id_category, category_name FROM categories WHERE active = 1 AND id_category IN (' . $ph . ')', $ids) ?: [];

			foreach ($rows as $r) {
				$clean['categories'][(int) $r['id_category']] = (string) $r['category_name'];
			}
		}

		if ($clean['categories'] === []) {
			$errors['categories'] = 'Satış yapacağınız en az bir kategori seçin.';
		} elseif (count($clean['categories']) > 10) {
			$errors['categories'] = 'En fazla 10 kategori seçebilirsiniz.';
		}

		if (mb_strlen((string) ($storeClean['description'] ?? '')) < 20) {
			$errors['description'] = 'Mağaza açıklaması en az 20 karakter olmalı.';
		}

		return ['ok' => $errors === [], 'errors' => $errors, 'clean' => $clean];
	}

	/** @return array<int,array{id:int,name:string}> */
	public static function categoryOptions(): array
	{
		// Alt kategoriler "Ana › Alt" olarak gösterilir (aynı adlı alt kategoriler karışmasın); id_parent sütunu yoksa düz liste
		$rows = DB::execute('SELECT c.id_category, c.category_name, p.category_name AS parent_name FROM categories c LEFT JOIN categories p ON p.id_category = c.id_parent AND c.id_parent > 0
			WHERE c.active = 1 ORDER BY COALESCE(p.category_name, c.category_name), c.id_parent, c.category_name LIMIT 300');

		if ($rows === false) {
			$rows = DB::execute('SELECT id_category, category_name FROM categories WHERE active = 1 ORDER BY category_name LIMIT 300') ?: [];
		}

		return array_map(static fn($r) => ['id' => (int) $r['id_category'], 'name' => (!empty($r['parent_name']) ? $r['parent_name'] . ' › ' : '') . (string) $r['category_name']], $rows ?: []);
	}

	/** Başvuru kaydı (mağaza kaydı RvVendor::create ile pending açılmış olmalıdır). */
	public static function submit(int $idUser, int $idVendor, array $clean, bool $kvkkAck): array
	{
		if ($idUser <= 0 || $idVendor <= 0) {
			return ['success' => false, 'message' => 'Geçersiz başvuru.', 'id_application' => 0, 'app_no' => ''];
		}

		$open = DB::getRowSafe('rafimda_vendor_applications', 'id_user = ? AND status <> ?', [$idUser, 'rejected']);

		if ($open) {
			return ['success' => false, 'message' => 'Açık bir başvurunuz var: ' . $open['app_no'], 'id_application' => (int) $open['id_application'], 'app_no' => (string) $open['app_no']];
		}

		$now = date('Y-m-d H:i:s');
		$id = (int) DB::insert('rafimda_vendor_applications', [
			'app_no' => 'TMP-' . bin2hex(random_bytes(6)),
			'id_user' => $idUser,
			'id_vendor' => $idVendor,
			'person_type' => $clean['person_type'],
			'contact_name' => $clean['contact_name'],
			'address' => $clean['address'],
			'district' => $clean['district'],
			'categories' => json_encode($clean['categories'], JSON_UNESCAPED_UNICODE),
			'status' => 'new',
			'terms_version' => (string) (Settings::get(self::TERMS_VERSION_SETTING) ?: 'taslak'),
			'kvkk_ack_at' => $kvkkAck ? $now : null,
			'date_add' => $now,
			'date_upd' => $now,
		]);

		if ($id <= 0) {
			return ['success' => false, 'message' => 'Başvuru kaydedilemedi.', 'id_application' => 0, 'app_no' => ''];
		}

		$no = 'SB' . date('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
		DB::update('rafimda_vendor_applications', ['app_no' => $no], 'id_application = :i', ['i' => $id]);
		self::log($id, '', 'new', 'Başvuru alındı', 'user', $idUser);
		$app = self::get($id);
		RvNotify::send(['id_user' => $idUser, 'email' => self::applicantEmail($app)], 'application', 'Satıcı başvurunuz alındı: ' . $no,
			"Başvuru numaranız: $no\nDurum: Yeni. İnceleme sonucu size bildirilecektir.", RvView::url('satici-panel'));
		RvNotify::send(['audience' => 'admin'], 'application', 'Yeni satıcı başvurusu: ' . $no, (string) ($app['store_name'] ?? ''), '');

		return ['success' => true, 'message' => 'Başvurunuz alındı. Başvuru numaranız: ' . $no . '.', 'id_application' => $id, 'app_no' => $no];
	}

	public static function get(int $id): ?array
	{
		$rows = DB::execute('SELECT a.*, v.store_name, v.store_slug, v.legal_name, v.tax_office, v.tax_number, v.phone, v.email, v.city, v.description, v.status AS vendor_status
			FROM rafimda_vendor_applications a LEFT JOIN rafimda_vendors v ON v.id_vendor = a.id_vendor WHERE a.id_application = ?', [$id]) ?: [];

		if (!isset($rows[0])) {
			return null;
		}

		$cats = json_decode((string) ($rows[0]['categories'] ?? ''), true);
		$rows[0]['category_list'] = is_array($cats) ? array_values(array_map('strval', $cats)) : [];

		return $rows[0];
	}

	public static function forVendor(int $idVendor): ?array
	{
		$id = (int) DB::getValue('SELECT id_application FROM rafimda_vendor_applications WHERE id_vendor = ? ORDER BY id_application DESC LIMIT 1', [$idVendor]);

		return $id > 0 ? self::get($id) : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function adminList(string $status = '', string $q = ''): array
	{
		$sql = 'SELECT a.id_application, a.app_no, a.person_type, a.contact_name, a.status, a.date_add, v.store_name, v.city,
			(SELECT COUNT(*) FROM rafimda_vendor_app_docs d WHERE d.id_application = a.id_application) AS doc_count
			FROM rafimda_vendor_applications a LEFT JOIN rafimda_vendors v ON v.id_vendor = a.id_vendor WHERE 1=1';
		$args = [];

		if (isset(self::STATUSES[$status])) {
			$sql .= ' AND a.status = ?';
			$args[] = $status;
		}

		if ($q !== '') {
			$sql .= ' AND (a.app_no LIKE ? OR v.store_name LIKE ? OR a.contact_name LIKE ?)';
			array_push($args, "%$q%", "%$q%", "%$q%");
		}

		return DB::execute($sql . ' ORDER BY a.id_application DESC LIMIT 200', $args) ?: [];
	}

	/** @return array<string,int> */
	public static function counts(): array
	{
		$out = array_fill_keys(array_keys(self::STATUSES), 0);

		foreach (DB::execute('SELECT status, COUNT(*) AS n FROM rafimda_vendor_applications GROUP BY status') ?: [] as $r) {
			$out[(string) $r['status']] = (int) $r['n'];
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public static function docs(int $idApp): array
	{
		return DB::execute('SELECT id_doc, doc_type, original_name, mime, size, date_add FROM rafimda_vendor_app_docs WHERE id_application = ? ORDER BY id_doc', [$idApp]) ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function history(int $idApp): array
	{
		return DB::execute('SELECT * FROM rafimda_vendor_app_log WHERE id_application = ? ORDER BY id_app_log', [$idApp]) ?: [];
	}

	/**
	 * Belge yükleme. Yalnızca başvuru sahibi, onaylanmamış başvuruya yükleyebilir. Uzantı + gerçek içerik (MIME/imza) kontrolü yapılır.
	 *
	 * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file
	 */
	public static function addDocument(int $idApp, int $idUser, array $file, string $docType): array
	{
		$app = self::get($idApp);

		if (!$app || (int) $app['id_user'] !== $idUser) {
			return ['success' => false, 'message' => 'Başvuru bulunamadı.'];
		}

		if (in_array($app['status'], ['approved'], true)) {
			return ['success' => false, 'message' => 'Onaylanmış başvuruya belge eklenemez.'];
		}

		if (!isset(self::DOC_TYPES[$docType])) {
			$docType = 'other';
		}

		if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
			return ['success' => false, 'message' => 'Dosya yüklenemedi.'];
		}

		$size = (int) ($file['size'] ?? 0);

		if ($size <= 0 || $size > self::MAX_DOC_BYTES || filesize((string) $file['tmp_name']) > self::MAX_DOC_BYTES) {
			return ['success' => false, 'message' => 'Dosya boyutu en fazla 5 MB olabilir.'];
		}

		if ((int) DB::getValue('SELECT COUNT(*) FROM rafimda_vendor_app_docs WHERE id_application = ?', [$idApp]) >= self::MAX_DOCS) {
			return ['success' => false, 'message' => 'En fazla ' . self::MAX_DOCS . ' belge yüklenebilir.'];
		}

		$orig = (string) ($file['name'] ?? '');
		$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

		if (!isset(self::ALLOWED[$ext])) {
			return ['success' => false, 'message' => 'Yalnızca PDF, JPG veya PNG yüklenebilir.'];
		}

		$head = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 8);
		$real = str_starts_with($head, '%PDF-') ? 'application/pdf' : (str_starts_with($head, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($head, "\x89PNG\r\n\x1a\n") ? 'image/png' : ''));

		if ($real === '' || $real !== self::ALLOWED[$ext]) {
			return ['success' => false, 'message' => 'Dosya içeriği uzantısıyla uyuşmuyor.'];
		}

		$dir = self::storageDir() . '/' . $idApp;

		if (!self::ensureDir($dir)) {
			return ['success' => false, 'message' => 'Belge dizini hazırlanamadı.'];
		}

		$stored = bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
		$dest = $dir . '/' . $stored;
		$moved = is_callable(self::$mover) ? (bool) (self::$mover)((string) $file['tmp_name'], $dest) : move_uploaded_file((string) $file['tmp_name'], $dest);

		if (!$moved) {
			return ['success' => false, 'message' => 'Dosya kaydedilemedi.'];
		}

		@chmod($dest, 0640);
		DB::insert('rafimda_vendor_app_docs', [
			'id_application' => $idApp,
			'doc_type' => $docType,
			'stored_name' => $stored,
			'original_name' => mb_substr(preg_replace('/[^\pL\pN ._-]+/u', '_', basename($orig)) ?? 'belge', 0, 190),
			'mime' => $real,
			'size' => $size,
			'sha256' => (string) hash_file('sha256', $dest),
			'date_add' => date('Y-m-d H:i:s'),
		]);

		// Eksik belge istenmişse yükleme sonrası başvuru otomatik incelemeye döner
		if ($app['status'] === 'docs_required') {
			self::setStatus($idApp, 'reviewing', 'Başvuru sahibi belge yükledi', 'user', $idUser);
		} else {
			self::log($idApp, (string) $app['status'], (string) $app['status'], 'Belge yüklendi: ' . self::DOC_TYPES[$docType], 'user', $idUser);
		}

		RvNotify::send(['audience' => 'admin'], 'documents', 'Başvuruya belge yüklendi: ' . $app['app_no'], self::DOC_TYPES[$docType], '');

		return ['success' => true, 'message' => 'Belge yüklendi.'];
	}

	/** Belge yolunu yalnızca yetkili kişiye verir: yönetici ($isAdmin) veya başvuru sahibi. */
	public static function documentPath(int $idDoc, int $idUser, bool $isAdmin): ?array
	{
		$rows = DB::execute('SELECT d.*, a.id_user FROM rafimda_vendor_app_docs d INNER JOIN rafimda_vendor_applications a ON a.id_application = d.id_application WHERE d.id_doc = ?', [$idDoc]) ?: [];
		$d = $rows[0] ?? null;

		if (!$d || (!$isAdmin && (int) $d['id_user'] !== $idUser)) {
			return null;
		}

		$path = self::storageDir() . '/' . (int) $d['id_application'] . '/' . basename((string) $d['stored_name']);

		return is_file($path) ? ['path' => $path, 'mime' => (string) $d['mime'], 'name' => (string) $d['original_name']] : null;
	}

	/** Yönetici durum değişikliği. Belge istemek ve reddetmek açıklama gerektirir. Onay, mağazayı etkinleştirir. */
	public static function transition(int $idApp, string $to, int $adminId, string $note = ''): array
	{
		$app = self::get($idApp);

		if (!$app) {
			return ['success' => false, 'message' => 'Başvuru bulunamadı.'];
		}

		$from = (string) $app['status'];

		if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
			return ['success' => false, 'message' => 'Bu durum geçişine izin yok: ' . (self::STATUSES[$from] ?? $from) . ' → ' . (self::STATUSES[$to] ?? $to)];
		}

		$note = mb_substr(trim(strip_tags($note)), 0, 2000);

		if (in_array($to, ['docs_required', 'rejected'], true) && mb_strlen($note) < 5) {
			return ['success' => false, 'message' => $to === 'rejected' ? 'Ret gerekçesi yazın.' : 'Eksik belgeleri yazın.'];
		}

		if ($to === 'approved') {
			$ok = RvVendor::setStatus((int) $app['id_vendor'], RvVendor::STATUS_ACTIVE, $adminId);

			if (!$ok && (string) (RvVendor::get((int) $app['id_vendor'])['status'] ?? '') !== RvVendor::STATUS_ACTIVE) {
				return ['success' => false, 'message' => 'Mağaza etkinleştirilemedi; başvuru onaylanmadı.'];
			}
		} elseif ($to === 'rejected') {
			RvVendor::setStatus((int) $app['id_vendor'], RvVendor::STATUS_REJECTED, $adminId, $note);
		} elseif ($from === 'rejected') {
			RvVendor::setStatus((int) $app['id_vendor'], RvVendor::STATUS_PENDING, $adminId);
		}

		$extra = ['reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s')];

		if ($to === 'docs_required') {
			$extra['missing_docs'] = $note;
		} elseif ($note !== '') {
			$extra['admin_note'] = $note;
		}

		self::setStatus($idApp, $to, $note, 'admin', $adminId, $extra);

		$msg = [
			'reviewing' => 'Başvurunuz incelemeye alındı.',
			'docs_required' => "Başvurunuz için eksik belge var:\n" . $note . "\nSatıcı panelinden belgeleri yükleyebilirsiniz.",
			'approved' => 'Tebrikler! Başvurunuz onaylandı; mağazanız açıldı. Satıcı panelinden ürünlerinizi ekleyebilirsiniz.',
			'rejected' => "Başvurunuz reddedildi.\nGerekçe: " . $note,
		][$to];
		RvNotify::send(['id_user' => (int) $app['id_user'], 'email' => self::applicantEmail($app)], $to === 'docs_required' ? 'documents' : 'application',
			'Satıcı başvurusu ' . $app['app_no'] . ': ' . self::STATUSES[$to], $msg, RvView::url('satici-panel'));

		return ['success' => true, 'message' => 'Başvuru durumu: ' . self::STATUSES[$to]];
	}

	/** Yönetici "Başvurular" sekmesi şablon değişkenleri (adminPage ve testler aynı kodu kullanır). */
	public static function adminVars(bool $active, array $get): array
	{
		$idApp = $active ? (int) ($get['app'] ?? 0) : 0;
		$filter = (string) ($get['astatus'] ?? '');

		$counts = self::counts();

		return [
			'rvAppOpen' => $counts['new'] + $counts['reviewing'],
			'rvApps' => $active && $idApp === 0 ? self::adminList($filter, trim((string) ($get['q'] ?? ''))) : [],
			'rvAppCounts' => $counts,
			'rvAppStatuses' => self::STATUSES,
			'rvAppTransitions' => self::TRANSITIONS,
			'rvAppFilter' => $filter,
			'rvApp' => $idApp > 0 ? self::get($idApp) : null,
			'rvAppDocs' => $idApp > 0 ? self::docs($idApp) : [],
			'rvAppHistory' => $idApp > 0 ? self::history($idApp) : [],
			'rvDocTypes' => self::DOC_TYPES,
			'rvPersonTypes' => self::PERSON_TYPES,
		];
	}

	/** Satıcı listesinden doğrudan durum değiştirilirse açık başvuru da tutarlı tutulur (onay/ret). */
	public static function syncFromVendor(int $idVendor, string $vendorStatus, int $adminId, string $reason = ''): void
	{
		$app = self::forVendor($idVendor);

		if (!$app || in_array($app['status'], ['approved'], true)) {
			return;
		}

		if ($vendorStatus === RvVendor::STATUS_ACTIVE) {
			self::setStatus((int) $app['id_application'], 'approved', 'Satıcılar listesinden onaylandı', 'admin', $adminId, ['reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s')]);
		} elseif ($vendorStatus === RvVendor::STATUS_REJECTED && $app['status'] !== 'rejected') {
			self::setStatus((int) $app['id_application'], 'rejected', $reason !== '' ? $reason : 'Satıcılar listesinden reddedildi', 'admin', $adminId, ['admin_note' => $reason, 'reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s')]);
		}
	}

	/** RAFIMDA_VENDOR_AUTO_APPROVE açıkken: mağaza zaten aktif açılır, başvuru da kayıtlı olarak onaylanır. */
	public static function autoApprove(int $idApp): void
	{
		self::setStatus($idApp, 'approved', 'Otomatik onay (RAFIMDA_VENDOR_AUTO_APPROVE)', 'system', 0, ['reviewed_at' => date('Y-m-d H:i:s')]);
	}

	private static function setStatus(int $idApp, string $to, string $note, string $actor, int $actorId, array $extra = []): void
	{
		$from = (string) DB::getValue('SELECT status FROM rafimda_vendor_applications WHERE id_application = ?', [$idApp]);
		DB::update('rafimda_vendor_applications', ['status' => $to, 'date_upd' => date('Y-m-d H:i:s')] + $extra, 'id_application = :i', ['i' => $idApp]);
		self::log($idApp, $from, $to, $note, $actor, $actorId);
	}

	private static function log(int $idApp, string $from, string $to, string $note, string $actor, int $actorId): void
	{
		DB::insert('rafimda_vendor_app_log', ['id_application' => $idApp, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'actor' => $actor, 'actor_id' => $actorId, 'date_add' => date('Y-m-d H:i:s')]);
	}

	private static function applicantEmail(?array $app): string
	{
		if (!$app) {
			return '';
		}

		$mail = (string) ($app['email'] ?? '');

		if ($mail === '') {
			$mail = (string) DB::getValue('SELECT email FROM users WHERE id_user = ?', [(int) $app['id_user']]);
		}

		return $mail;
	}

	private static function ensureDir(string $dir): bool
	{
		$base = dirname($dir);

		if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
			return false;
		}

		foreach ([$base, $dir] as $d) {
			if (!is_file($d . '/.htaccess')) {
				@file_put_contents($d . '/.htaccess', "Require all denied\nOptions -Indexes\n");
			}

			if (!is_file($d . '/index.html')) {
				@file_put_contents($d . '/index.html', '');
			}
		}

		return true;
	}
}
