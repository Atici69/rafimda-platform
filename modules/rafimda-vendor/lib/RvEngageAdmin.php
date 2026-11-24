<?php

/** Yönetici: mağaza soruları ve değerlendirmeleri moderasyonu, destek talepleri, bildirim günlüğü, etkileşim ayarları. */
final class RvEngageAdmin
{
	public static function vars(bool $active, array $get): array
	{
		if (!$active) {
			return ['rvEngTicketOpen' => (int) DB::getValue("SELECT COUNT(*) FROM rafimda_tickets WHERE status IN ('open','waiting')")];
		}

		$t = (int) ($get['ticket'] ?? 0);

		return [
			'rvEngTicketOpen' => (int) DB::getValue("SELECT COUNT(*) FROM rafimda_tickets WHERE status IN ('open','waiting')"),
			'rvEngStoreQ' => DB::execute("SELECT q.*, v.store_name FROM rafimda_store_questions q INNER JOIN rafimda_vendors v ON v.id_vendor = q.id_vendor WHERE q.status IN ('pending','answered') ORDER BY q.id_store_question DESC LIMIT 100") ?: [],
			'rvEngStoreR' => DB::execute("SELECT r.*, v.store_name FROM rafimda_store_reviews r INNER JOIN rafimda_vendors v ON v.id_vendor = r.id_vendor WHERE r.status = 'pending' ORDER BY r.id_store_review DESC LIMIT 100") ?: [],
			'rvEngTickets' => RvTickets::listFor('admin', 0, 0, (string) ($get['ts'] ?? '')),
			'rvEngTicket' => $t > 0 ? RvTickets::get($t, 'admin') : null,
			'rvEngTicketStatuses' => RvTickets::STATUSES,
			'rvEngCategories' => RvTickets::CATEGORIES,
			'rvEngNotifications' => RvNotify::forAdmin(50),
			'rvEngChannels' => RvNotify::channelStatus(),
			'rvEngQaMod' => RvQA::moderation(),
			'rvEngPurchaseOnly' => (string) Settings::get('RAFIMDA_REVIEW_PURCHASE_ONLY') === '1',
			'rvEngQaAvailable' => RvQA::available(),
			'rvEngReviewsAvailable' => RvReviews::available(),
		];
	}

	/** @return array{0:bool,1:string}|null null = bu sınıfın işlemi değil */
	public static function handle(string $action, array $post, int $adminId): ?array
	{
		switch ($action) {
			case 'eng_question':
				$ok = RvQA::moderateStore((int) ($post['id'] ?? 0), (string) ($post['to'] ?? ''));

				return [$ok, $ok ? 'Soru güncellendi.' : 'Yanıtsız soru yayınlanamaz veya kayıt bulunamadı.'];
			case 'eng_review':
				$ok = RvReviews::moderateStore((int) ($post['id'] ?? 0), (string) ($post['to'] ?? ''));

				return [$ok, $ok ? 'Değerlendirme güncellendi.' : 'Kayıt bulunamadı.'];
			case 'eng_ticket_reply':
				$r = RvTickets::reply((int) ($post['id_ticket'] ?? 0), 'admin', $adminId, (string) ($post['body'] ?? ''), 0, 0, (string) ($post['status'] ?? 'answered'));

				return [$r['success'], $r['message']];
			case 'eng_ticket_close':
				$ok = RvTickets::close((int) ($post['id_ticket'] ?? 0), 'admin');

				return [$ok, $ok ? 'Talep kapatıldı.' : 'Talep bulunamadı.'];
			case 'eng_settings':
				Settings::set('RAFIMDA_QA_MODERATION', ($post['qa_moderation'] ?? '') === '1' ? '1' : '0');
				Settings::set('RAFIMDA_REVIEW_PURCHASE_ONLY', ($post['purchase_only'] ?? '') === '1' ? '1' : '0');

				return [true, 'Etkileşim ayarları kaydedildi.'];
		}

		return null;
	}
}
