<?php

/**
 * Rafımda bildirimleri: her bildirim önce VERİTABANINA yazılır (panelde görünür); e-posta yalnızca FriSay Mail yapılandırılmışsa denenir.
 * E-posta/SMS sağlayıcısı yoksa bu durum `email_status = not_configured` olarak KAYDEDİLİR (sessizce başarılı sayılmaz). SMS altyapısı yoktur.
 */
final class RvNotify
{
	public const TYPES = [
		'application' => 'Başvuru',
		'documents' => 'Belge',
		'order' => 'Sipariş',
		'question' => 'Soru-cevap',
		'review' => 'Değerlendirme',
		'subscription' => 'Mağaza planı',
		'social' => 'Sosyal medya',
		'invoice' => 'Fatura',
		'support' => 'Destek',
		'system' => 'Sistem',
	];

	/** Testlerde e-posta gönderimini taklit etmek için: fn(string $to, string $subject, string $html): bool */
	public static $mailer = null;

	/**
	 * @param array{id_user?:int,id_vendor?:int,audience?:string,email?:string} $to
	 */
	public static function send(array $to, string $type, string $title, string $body, string $link = ''): int
	{
		$type = isset(self::TYPES[$type]) ? $type : 'system';
		$email = strtolower(trim((string) ($to['email'] ?? '')));
		$status = 'skipped';

		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$status = self::mail($email, $title, $body, $link);
		}

		$id = (int) DB::insert('rafimda_notifications', [
			'id_user' => (int) ($to['id_user'] ?? 0),
			'id_vendor' => (int) ($to['id_vendor'] ?? 0),
			'audience' => in_array($to['audience'] ?? 'user', ['user', 'vendor', 'admin'], true) ? (string) ($to['audience'] ?? 'user') : 'user',
			'type' => $type,
			'title' => mb_substr(strip_tags($title), 0, 190),
			'body' => mb_substr(strip_tags($body), 0, 4000),
			'link' => mb_substr($link, 0, 255),
			'email_status' => $status,
			'is_read' => 0,
			'date_add' => date('Y-m-d H:i:s'),
		]);

		return $id;
	}

	private static function mail(string $to, string $title, string $body, string $link): string
	{
		try {
			$html = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>'
				. ($link !== '' ? '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>' : '');

			if (is_callable(self::$mailer)) {
				return (self::$mailer)($to, $title, $html) ? 'sent' : 'failed';
			}

			if (!class_exists('Mail') || !method_exists('Mail', 'isConfigured') || !Mail::isConfigured()) {
				return 'not_configured';
			}

			return Mail::send($to, $title, $html) ? 'sent' : 'failed';
		} catch (Throwable $e) {
			error_log('RvNotify: ' . $e->getMessage());

			return 'failed';
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function forUser(int $idUser, int $limit = 50): array
	{
		return DB::execute('SELECT * FROM rafimda_notifications WHERE id_user = ? AND audience = ? ORDER BY id_notification DESC LIMIT ' . max(1, min(200, $limit)), [$idUser, 'user']) ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function forVendor(int $idVendor, int $limit = 50): array
	{
		return DB::execute('SELECT * FROM rafimda_notifications WHERE id_vendor = ? AND audience = ? ORDER BY id_notification DESC LIMIT ' . max(1, min(200, $limit)), [$idVendor, 'vendor']) ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function forAdmin(int $limit = 100): array
	{
		return DB::execute('SELECT * FROM rafimda_notifications WHERE audience = ? ORDER BY id_notification DESC LIMIT ' . max(1, min(500, $limit)), ['admin']) ?: [];
	}

	public static function unreadForVendor(int $idVendor): int
	{
		return (int) DB::getValue('SELECT COUNT(*) FROM rafimda_notifications WHERE id_vendor = ? AND audience = ? AND is_read = 0', [$idVendor, 'vendor']);
	}

	/** Yalnızca kendi bildirimini okundu yapar (IDOR korumalı). */
	public static function markReadForVendor(int $idVendor, int $id = 0): void
	{
		if ($id > 0) {
			DB::execute('UPDATE rafimda_notifications SET is_read = 1 WHERE id_notification = ? AND id_vendor = ? AND audience = ?', [$id, $idVendor, 'vendor']);

			return;
		}

		DB::execute('UPDATE rafimda_notifications SET is_read = 1 WHERE id_vendor = ? AND audience = ?', [$idVendor, 'vendor']);
	}

	public static function markReadForUser(int $idUser, int $id = 0): void
	{
		$sql = 'UPDATE rafimda_notifications SET is_read = 1 WHERE id_user = ? AND audience = ?';
		$args = [$idUser, 'user'];

		if ($id > 0) {
			$sql .= ' AND id_notification = ?';
			$args[] = $id;
		}

		DB::execute($sql, $args);
	}

	/** E-posta altyapı durumu (rapor/kontrol listesi için). */
	public static function channelStatus(): array
	{
		$mail = class_exists('Mail') && method_exists('Mail', 'isConfigured') && Mail::isConfigured();

		return ['email' => $mail ? 'configured' : 'not_configured', 'sms' => 'not_available'];
	}
}
