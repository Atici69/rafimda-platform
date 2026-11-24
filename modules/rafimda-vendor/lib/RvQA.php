<?php

/**
 * Soru-cevap (satıcı tarafı). Ürün soruları FriSay `question` modülünün `product_questions` tablosundadır; Rafımda yalnızca
 * satıcı ilişkisini (rafimda_question_meta) ve mağaza sorularını (rafimda_store_questions) ekler.
 * - Satıcı yalnızca KENDİ ürünlerinin / mağazasının sorularını görür ve yanıtlar.
 * - Yanıt yayın kuralı: RAFIMDA_QA_MODERATION = 1 ise yönetici onayına düşer; aksi halde yayınlanır.
 * - Herkese açık gösterimde e-posta/telefon/IBAN/kimlik no/bağlantı maskelenir.
 * - Soru sahibine bildirim gider (RvNotify).
 */
final class RvQA
{
	public const STORE_STATUSES = ['pending' => 'Yanıt bekliyor', 'answered' => 'Yanıtlandı (onay bekliyor)', 'published' => 'Yayında', 'rejected' => 'Reddedildi'];

	public static function available(): bool
	{
		return RvStorefront::tableExists('product_questions');
	}

	public static function moderation(): bool
	{
		return (string) Settings::get('RAFIMDA_QA_MODERATION') === '1';
	}

	/** FriSay soru kaydı oluşunca: satıcıyı bağla, satıcıya bildirim gönder. */
	public static function onQuestionCreated(int $idQuestion): void
	{
		try {
			$q = DB::getRowSafe('product_questions', 'id_question = ?', [$idQuestion]);
			$vendor = $q ? RvStorefront::vendorOfProduct((int) $q['id_product']) : null;

			if (!$vendor) {
				return;
			}

			if (!DB::getRowSafe('rafimda_question_meta', 'id_question = ?', [$idQuestion])) {
				DB::insert('rafimda_question_meta', ['id_question' => $idQuestion, 'id_vendor' => (int) $vendor['id_vendor'], 'date_upd' => date('Y-m-d H:i:s')]);
			}

			RvNotify::send(['id_vendor' => (int) $vendor['id_vendor'], 'audience' => 'vendor'], 'question', 'Yeni ürün sorusu', mb_substr((string) $q['question'], 0, 200), RvView::panelUrl('questions'));
		} catch (Throwable $e) {
			error_log('RvQA: ' . $e->getMessage());
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function productQuestions(int $idVendor, string $filter = 'open'): array
	{
		if (!self::available()) {
			return [];
		}

		$sql = 'SELECT q.id_question, q.id_product, q.question, q.answer, q.active, q.date_add, q.date_answer, p.product_name
			FROM product_questions q INNER JOIN rafimda_vendor_products vp ON vp.id_product = q.id_product INNER JOIN products p ON p.id_product = q.id_product
			WHERE vp.id_vendor = ?';
		$sql .= $filter === 'open' ? " AND (q.answer IS NULL OR q.answer = '')" : ($filter === 'answered' ? " AND q.answer <> ''" : '');

		return DB::execute($sql . ' ORDER BY q.id_question DESC LIMIT 200', [$idVendor]) ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function storeQuestions(int $idVendor, string $status = ''): array
	{
		$sql = 'SELECT * FROM rafimda_store_questions WHERE id_vendor = ?';
		$args = [$idVendor];

		if (isset(self::STORE_STATUSES[$status])) {
			$sql .= ' AND status = ?';
			$args[] = $status;
		}

		return DB::execute($sql . ' ORDER BY id_store_question DESC LIMIT 200', $args) ?: [];
	}

	/** Satıcı yanıtı: sahiplik doğrulanır (başka satıcının ürününe yanıt yazılamaz). */
	public static function answerProduct(int $idVendor, int $idUser, int $idQuestion, string $answer): array
	{
		if (!self::available()) {
			return ['success' => false, 'message' => 'Soru-cevap modülü kurulu değil.'];
		}

		$rows = DB::execute('SELECT q.* FROM product_questions q INNER JOIN rafimda_vendor_products vp ON vp.id_product = q.id_product WHERE q.id_question = ? AND vp.id_vendor = ?', [$idQuestion, $idVendor]) ?: [];
		$q = $rows[0] ?? null;

		if (!$q) {
			return ['success' => false, 'message' => 'Soru bulunamadı.'];
		}

		$answer = trim(strip_tags($answer));

		if (mb_strlen($answer) < 3 || mb_strlen($answer) > 2000) {
			return ['success' => false, 'message' => 'Yanıt 3-2000 karakter olmalı.'];
		}

		$publish = !self::moderation();
		DB::update('product_questions', ['answer' => $answer, 'active' => $publish ? 1 : 0, 'date_answer' => date('Y-m-d H:i:s')], 'id_question = :q', ['q' => $idQuestion]);
		$meta = ['id_vendor' => $idVendor, 'answered_by' => 'vendor', 'answered_user' => $idUser, 'masked' => RvPrivacy::containsPrivate($answer . ' ' . $q['question']) ? 1 : 0, 'date_upd' => date('Y-m-d H:i:s')];

		if (DB::getRowSafe('rafimda_question_meta', 'id_question = ?', [$idQuestion])) {
			DB::update('rafimda_question_meta', $meta, 'id_question = :q', ['q' => $idQuestion]);
		} else {
			DB::insert('rafimda_question_meta', ['id_question' => $idQuestion] + $meta);
		}

		if ($publish && (int) $q['id_user'] > 0) {
			RvNotify::send(['id_user' => (int) $q['id_user'], 'email' => self::email((int) $q['id_user'])], 'question', 'Sorunuz yanıtlandı', 'Ürün sorunuza satıcı yanıt verdi.', '');
		}

		return ['success' => true, 'message' => $publish ? 'Yanıt yayınlandı.' : 'Yanıt kaydedildi; yönetici onayından sonra yayınlanacak.'];
	}

	/** Müşteri: mağaza hakkında soru (giriş + hız sınırı + spam). */
	public static function askStore(int $idVendor, int $idUser, string $authorName, string $question): array
	{
		$question = trim(strip_tags($question));

		if ($idUser <= 0) {
			return ['success' => false, 'message' => 'Soru sormak için giriş yapın.'];
		}

		if (!DB::getRowSafe('rafimda_vendors', 'id_vendor = ? AND status = ?', [$idVendor, RvVendor::STATUS_ACTIVE])) {
			return ['success' => false, 'message' => 'Mağaza bulunamadı.'];
		}

		if (DB::getRowSafe('rafimda_vendor_staff', 'id_vendor = ? AND id_user = ?', [$idVendor, $idUser])) {
			return ['success' => false, 'message' => 'Kendi mağazanıza soru soramazsınız.'];
		}

		if (mb_strlen($question) < 10 || mb_strlen($question) > 1000) {
			return ['success' => false, 'message' => 'Soru 10-1000 karakter olmalı.'];
		}

		if (preg_match_all('/https?:\/\//i', $question) >= 2 || preg_match('/\b(viagra|casino|porn|xxx|bahis)\b/i', $question)) {
			return ['success' => true, 'message' => 'Sorunuz alındı.']; // spam sessizce kaydedilmez
		}

		$recent = (int) DB::getValue('SELECT COUNT(*) FROM rafimda_store_questions WHERE id_user = ? AND date_add > ?', [$idUser, date('Y-m-d H:i:s', time() - 3600)]);

		if ($recent >= 5) {
			return ['success' => false, 'message' => 'Saatte en fazla 5 soru gönderebilirsiniz.'];
		}

		DB::insert('rafimda_store_questions', ['id_vendor' => $idVendor, 'id_user' => $idUser, 'author_name' => mb_substr($authorName !== '' ? $authorName : 'Müşteri', 0, 128),
			'question' => $question, 'answer' => '', 'status' => 'pending', 'date_add' => date('Y-m-d H:i:s')]);
		RvNotify::send(['id_vendor' => $idVendor, 'audience' => 'vendor'], 'question', 'Yeni mağaza sorusu', mb_substr($question, 0, 200), RvView::panelUrl('questions'));

		return ['success' => true, 'message' => 'Sorunuz satıcıya iletildi. Yanıtlandığında bildirim alacaksınız.'];
	}

	public static function answerStore(int $idVendor, int $idUser, int $id, string $answer): array
	{
		$q = DB::getRowSafe('rafimda_store_questions', 'id_store_question = ? AND id_vendor = ?', [$id, $idVendor]);
		$answer = trim(strip_tags($answer));

		if (!$q) {
			return ['success' => false, 'message' => 'Soru bulunamadı.'];
		}

		if (mb_strlen($answer) < 3 || mb_strlen($answer) > 2000) {
			return ['success' => false, 'message' => 'Yanıt 3-2000 karakter olmalı.'];
		}

		$status = self::moderation() ? 'answered' : 'published';
		DB::update('rafimda_store_questions', ['answer' => $answer, 'status' => $status, 'answered_user' => $idUser, 'date_answer' => date('Y-m-d H:i:s')], 'id_store_question = :i', ['i' => $id]);

		if ($status === 'published') {
			RvNotify::send(['id_user' => (int) $q['id_user'], 'email' => self::email((int) $q['id_user'])], 'question', 'Mağaza sorunuz yanıtlandı', mb_substr($answer, 0, 300), '');
		}

		return ['success' => true, 'message' => $status === 'published' ? 'Yanıt yayınlandı.' : 'Yanıt kaydedildi; yönetici onayından sonra yayınlanacak.'];
	}

	/** Yönetici moderasyonu (mağaza soruları): yayınla / reddet. */
	public static function moderateStore(int $id, string $to): bool
	{
		$q = DB::getRowSafe('rafimda_store_questions', 'id_store_question = ?', [$id]);

		if (!$q || !in_array($to, ['published', 'rejected'], true) || ($to === 'published' && trim((string) $q['answer']) === '')) {
			return false;
		}

		DB::update('rafimda_store_questions', ['status' => $to], 'id_store_question = :i', ['i' => $id]);

		if ($to === 'published') {
			RvNotify::send(['id_user' => (int) $q['id_user'], 'email' => self::email((int) $q['id_user'])], 'question', 'Mağaza sorunuz yanıtlandı', mb_substr((string) $q['answer'], 0, 300), '');
		}

		return true;
	}

	/** @return array<int,array<string,mixed>> mağaza sayfasında yayınlanmış sorular (maskeli) */
	public static function publishedStore(int $idVendor, int $limit = 20): array
	{
		$rows = DB::execute("SELECT id_store_question, author_name, question, answer, date_add, date_answer FROM rafimda_store_questions WHERE id_vendor = ? AND status = 'published' ORDER BY id_store_question DESC LIMIT " . max(1, min(100, $limit)), [$idVendor]) ?: [];

		return array_map(static fn($r) => ['question' => RvPrivacy::mask((string) $r['question']), 'answer' => RvPrivacy::mask((string) $r['answer']), 'author' => self::maskName((string) $r['author_name'])] + $r, $rows);
	}

	/** FriSay soru satırını süsler: maskeleme ve "Satıcı yanıtı" etiketi (question modülü formatRow içinden çağrılır). */
	public static function decorate(array $row): array
	{
		$row['question'] = RvPrivacy::mask((string) ($row['question'] ?? ''));
		$row['answer'] = RvPrivacy::mask((string) ($row['answer'] ?? ''));
		$meta = isset($row['id_question']) ? DB::getRowSafe('rafimda_question_meta', 'id_question = ?', [(int) $row['id_question']]) : null;
		$row['answered_by_vendor'] = $meta && $meta['answered_by'] === 'vendor';

		if ($row['answered_by_vendor']) {
			$v = DB::getRowSafe('rafimda_vendors', 'id_vendor = ?', [(int) $meta['id_vendor']]);
			$row['answer_store'] = (string) ($v['store_name'] ?? '');
		}

		return $row;
	}

	public static function maskName(string $name): string
	{
		$parts = preg_split('/\s+/u', trim($name)) ?: [];

		return implode(' ', array_map(static fn($p) => mb_substr($p, 0, 1) . str_repeat('*', max(1, mb_strlen($p) - 1)), array_filter($parts))) ?: 'Müşteri';
	}

	private static function email(int $idUser): string
	{
		return (string) DB::getValue('SELECT email FROM users WHERE id_user = ?', [$idUser]);
	}
}
