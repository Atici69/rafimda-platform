<?php

/**
 * Destek talepleri: müşteri → Rafımda, satıcı → Rafımda. Talep numarası, kategori, durum takibi, yönetici yanıtı, geçmiş, bildirim.
 * Veri izolasyonu: müşteri yalnızca kendi taleplerini, satıcı yalnızca kendi mağazasının taleplerini görür.
 */
final class RvTickets
{
	public const STATUSES = ['open' => 'Açık', 'answered' => 'Yanıtlandı', 'waiting' => 'Yanıt bekleniyor', 'closed' => 'Kapandı'];
	public const CATEGORIES = [
		'order' => 'Sipariş', 'return' => 'İade / iptal', 'payment' => 'Ödeme', 'product' => 'Ürün', 'account' => 'Hesap',
		'store' => 'Mağaza / satıcı paneli', 'invoice' => 'Fatura', 'application' => 'Satıcı başvurusu', 'other' => 'Diğer',
	];

	/** @param array{opener:string,id_user:int,id_vendor?:int,category:string,subject:string,body:string,id_order?:int} $in */
	public static function create(array $in): array
	{
		$opener = in_array($in['opener'] ?? '', ['customer', 'vendor'], true) ? $in['opener'] : '';
		$subject = mb_substr(trim(strip_tags((string) ($in['subject'] ?? ''))), 0, 190);
		$body = trim(strip_tags((string) ($in['body'] ?? '')));
		$cat = isset(self::CATEGORIES[$in['category'] ?? '']) ? (string) $in['category'] : 'other';
		$idUser = (int) ($in['id_user'] ?? 0);
		$idVendor = $opener === 'vendor' ? (int) ($in['id_vendor'] ?? 0) : 0;

		if ($opener === '' || $idUser <= 0 || ($opener === 'vendor' && $idVendor <= 0)) {
			return ['success' => false, 'message' => 'Geçersiz talep.'];
		}

		if (mb_strlen($subject) < 5 || mb_strlen($body) < 10 || mb_strlen($body) > 5000) {
			return ['success' => false, 'message' => 'Konu (en az 5) ve açıklama (10-5000 karakter) girin.'];
		}

		$idOrder = (int) ($in['id_order'] ?? 0);

		if ($idOrder > 0 && $opener === 'customer' && !DB::getValue('SELECT id_order FROM orders WHERE id_order = ? AND id_user = ?', [$idOrder, $idUser])) {
			$idOrder = 0; // başkasının siparişi bağlanamaz
		}

		if ((int) DB::getValue('SELECT COUNT(*) FROM rafimda_tickets WHERE id_user = ? AND date_add > ?', [$idUser, date('Y-m-d H:i:s', time() - 3600)]) >= 10) {
			return ['success' => false, 'message' => 'Saatte en fazla 10 talep açabilirsiniz.'];
		}

		$now = date('Y-m-d H:i:s');
		$id = (int) DB::insert('rafimda_tickets', ['ticket_no' => 'TMP-' . bin2hex(random_bytes(6)), 'opener' => $opener, 'id_user' => $idUser, 'id_vendor' => $idVendor, 'category' => $cat,
			'subject' => $subject, 'status' => 'open', 'id_order' => $idOrder, 'date_add' => $now, 'date_upd' => $now]);

		if ($id <= 0) {
			return ['success' => false, 'message' => 'Talep kaydedilemedi.'];
		}

		$no = 'DT' . date('y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
		DB::update('rafimda_tickets', ['ticket_no' => $no], 'id_ticket = :i', ['i' => $id]);
		DB::insert('rafimda_ticket_messages', ['id_ticket' => $id, 'author' => $opener, 'author_id' => $idUser, 'body' => $body, 'date_add' => $now]);
		RvNotify::send(['audience' => 'admin'], 'support', 'Yeni destek talebi ' . $no, $subject, '');

		return ['success' => true, 'message' => 'Talebiniz alındı: ' . $no, 'id_ticket' => $id, 'ticket_no' => $no];
	}

	/** Erişim kontrollü talep getir: $who = customer|vendor|admin */
	public static function get(int $idTicket, string $who, int $idUser = 0, int $idVendor = 0): ?array
	{
		$t = DB::getRowSafe('rafimda_tickets', 'id_ticket = ?', [$idTicket]);

		if (!$t) {
			return null;
		}

		$allowed = $who === 'admin'
			|| ($who === 'customer' && $t['opener'] === 'customer' && (int) $t['id_user'] === $idUser)
			|| ($who === 'vendor' && $t['opener'] === 'vendor' && (int) $t['id_vendor'] === $idVendor && $idVendor > 0);

		if (!$allowed) {
			return null;
		}

		$t['messages'] = DB::execute('SELECT author, body, date_add FROM rafimda_ticket_messages WHERE id_ticket = ? ORDER BY id_ticket_message', [$idTicket]) ?: [];

		return $t;
	}

	public static function reply(int $idTicket, string $who, int $authorId, string $body, int $idUser = 0, int $idVendor = 0, string $newStatus = ''): array
	{
		$t = self::get($idTicket, $who, $idUser, $idVendor);
		$body = trim(strip_tags($body));

		if (!$t) {
			return ['success' => false, 'message' => 'Talep bulunamadı.'];
		}

		if ($t['status'] === 'closed' && $who !== 'admin') {
			return ['success' => false, 'message' => 'Kapanmış talebe yanıt yazılamaz; yeni talep açın.'];
		}

		if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
			return ['success' => false, 'message' => 'Mesaj 2-5000 karakter olmalı.'];
		}

		DB::insert('rafimda_ticket_messages', ['id_ticket' => $idTicket, 'author' => $who, 'author_id' => $authorId, 'body' => $body, 'date_add' => date('Y-m-d H:i:s')]);
		$status = $who === 'admin' ? (isset(self::STATUSES[$newStatus]) ? $newStatus : 'answered') : 'waiting';
		DB::update('rafimda_tickets', ['status' => $status, 'date_upd' => date('Y-m-d H:i:s')], 'id_ticket = :i', ['i' => $idTicket]);

		if ($who === 'admin') {
			$to = $t['opener'] === 'vendor' ? ['id_vendor' => (int) $t['id_vendor'], 'audience' => 'vendor'] : ['id_user' => (int) $t['id_user'], 'email' => (string) DB::getValue('SELECT email FROM users WHERE id_user = ?', [(int) $t['id_user']])];
			RvNotify::send($to, 'support', 'Destek talebiniz yanıtlandı: ' . $t['ticket_no'], mb_substr($body, 0, 300), '');
		} else {
			RvNotify::send(['audience' => 'admin'], 'support', 'Destek talebine yanıt: ' . $t['ticket_no'], mb_substr($body, 0, 300), '');
		}

		return ['success' => true, 'message' => 'Mesaj gönderildi.'];
	}

	public static function close(int $idTicket, string $who, int $idUser = 0, int $idVendor = 0): bool
	{
		return self::get($idTicket, $who, $idUser, $idVendor) !== null
			&& DB::update('rafimda_tickets', ['status' => 'closed', 'date_upd' => date('Y-m-d H:i:s')], 'id_ticket = :i', ['i' => $idTicket]) !== false;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listFor(string $who, int $idUser = 0, int $idVendor = 0, string $status = ''): array
	{
		$sql = 'SELECT t.*, (SELECT COUNT(*) FROM rafimda_ticket_messages m WHERE m.id_ticket = t.id_ticket) AS msg_count FROM rafimda_tickets t WHERE 1=1';
		$args = [];

		if ($who === 'customer') {
			$sql .= " AND t.opener = 'customer' AND t.id_user = ?";
			$args[] = $idUser;
		} elseif ($who === 'vendor') {
			$sql .= " AND t.opener = 'vendor' AND t.id_vendor = ?";
			$args[] = $idVendor;
		} elseif ($who !== 'admin') {
			return [];
		}

		if (isset(self::STATUSES[$status])) {
			$sql .= ' AND t.status = ?';
			$args[] = $status;
		}

		return DB::execute($sql . ' ORDER BY t.date_upd DESC, t.id_ticket DESC LIMIT 300', $args) ?: [];
	}
}
