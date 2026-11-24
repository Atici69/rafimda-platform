<?php

/**
 * Satıcı finans defteri: YALNIZCA EKLEME (append-only). Güncelleme/silme metodu bilinçli olarak yoktur;
 * düzeltmeler ters kayıt (refund/adjustment) olarak eklenir. ref_key UNIQUE olduğundan aynı olay iki kez yazılamaz.
 *
 * Bakiye: pending = available_at NULL veya gelecekte; available = available_at <= şimdi. Tutarlar işaretlidir.
 */
class RvLedger
{
	public const TYPES = ['sale' => 'Satış kazancı', 'refund' => 'İptal/iade düzeltmesi', 'payout' => 'Ödeme', 'adjustment' => 'Manuel düzeltme'];

	public static function money($v): float
	{
		return round((float) $v, 2);
	}

	/**
	 * Tek transaction; callable içinde başarısız DB sonucu RuntimeException ile geri alınır.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function tx(callable $fn)
	{
		global $db;

		$db->beginTransaction();

		try {
			$out = $fn();
			$db->commit();

			return $out;
		} catch (Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}

			throw $e;
		}
	}

	/** DB::execute/insert hatalarını (false) istisnaya çevirir. */
	public static function must($result, string $what = 'DB')
	{
		if ($result === false) {
			throw new RuntimeException($what . ' işlemi başarısız');
		}

		return $result;
	}

	/** @return int|false eklenen kayıt ID'si; ref_key daha önce kullanıldıysa false (idempotent). */
	public static function add(int $idVendor, ?int $idVendorOrder, string $type, float $amount, ?string $availableAt, string $refKey, string $note = '', int $idAdmin = 0)
	{
		if (!isset(self::TYPES[$type]) || $idVendor <= 0 || $refKey === '') {
			return false;
		}

		if (DB::getRowSafe('rafimda_ledger', 'ref_key = ?', [$refKey])) {
			return false;
		}

		return DB::insert('rafimda_ledger', [
			'id_vendor' => $idVendor,
			'id_vendor_order' => $idVendorOrder,
			'entry_type' => $type,
			'amount' => self::money($amount),
			'available_at' => $availableAt,
			'ref_key' => mb_substr($refKey, 0, 64),
			'note' => mb_substr(trim(strip_tags($note)), 0, 255),
			'id_admin' => $idAdmin,
		]);
	}

	/** @return array{pending:float, available:float, earned:float, paid_out:float} */
	public static function balances(int $idVendor): array
	{
		$now = date('Y-m-d H:i:s');
		$row = DB::execute(
			'SELECT
				COALESCE(SUM(CASE WHEN available_at IS NOT NULL AND available_at <= ? THEN amount ELSE 0 END), 0) AS available,
				COALESCE(SUM(CASE WHEN available_at IS NULL OR available_at > ? THEN amount ELSE 0 END), 0) AS pending,
				COALESCE(SUM(CASE WHEN entry_type = \'sale\' THEN amount ELSE 0 END), 0) AS earned,
				COALESCE(SUM(CASE WHEN entry_type = \'payout\' THEN -amount ELSE 0 END), 0) AS paid_out
			FROM rafimda_ledger WHERE id_vendor = ?',
			[$now, $now, $idVendor]
		)[0] ?? [];

		return [
			'pending' => self::money($row['pending'] ?? 0),
			'available' => self::money($row['available'] ?? 0),
			'earned' => self::money($row['earned'] ?? 0),
			'paid_out' => self::money($row['paid_out'] ?? 0),
		];
	}

	/** @return list<array<string,mixed>> */
	public static function entries(int $idVendor, int $limit = 50, int $offset = 0): array
	{
		return DB::execute(
			'SELECT * FROM rafimda_ledger WHERE id_vendor = ? ORDER BY id_entry DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
			[$idVendor]
		) ?: [];
	}

	/**
	 * Hakediş ödemesi kaydı. Satıcı satırı kilitlenerek (UPDATE) eşzamanlı iki ödemenin bakiyeyi aşması engellenir.
	 *
	 * @return array{success:bool, message:string}
	 */
	public static function recordPayout(int $idVendor, float $amount, string $reference, string $note, int $idAdmin): array
	{
		$amount = self::money($amount);

		if ($amount <= 0) {
			return ['success' => false, 'message' => 'Tutar 0\'dan büyük olmalı.'];
		}

		if (!RvVendor::get($idVendor)) {
			return ['success' => false, 'message' => 'Satıcı bulunamadı.'];
		}

		try {
			return self::tx(static function () use ($idVendor, $amount, $reference, $note, $idAdmin): array {
				self::must(DB::execute('UPDATE rafimda_vendors SET date_upd = date_upd WHERE id_vendor = ?', [$idVendor]), 'kilit');
				$bal = self::balances($idVendor);

				if ($amount > $bal['available'] + 0.0001) {
					return ['success' => false, 'message' => 'Ödenebilir bakiye yetersiz (' . number_format($bal['available'], 2, ',', '.') . ' ₺).'];
				}

				$idPayout = self::must(DB::insert('rafimda_payouts', [
					'id_vendor' => $idVendor,
					'amount' => $amount,
					'reference' => mb_substr(trim(strip_tags($reference)), 0, 64),
					'note' => mb_substr(trim(strip_tags($note)), 0, 255),
					'id_admin' => $idAdmin,
				]), 'ödeme kaydı');
				self::must(self::add($idVendor, null, 'payout', -$amount, date('Y-m-d H:i:s'), 'payout:' . $idPayout, $note, $idAdmin), 'defter');
				RvAudit::log($idVendor, 'finance.payout', 'payout', (int) $idPayout, ['amount' => $amount], $idAdmin);

				return ['success' => true, 'message' => 'Ödeme kaydedildi.'];
			});
		} catch (Throwable $e) {
			error_log('rafimda payout: ' . $e->getMessage());

			return ['success' => false, 'message' => 'Ödeme kaydedilemedi, işlem geri alındı.'];
		}
	}

	/** Not zorunlu manuel düzeltme (işaretli tutar). */
	public static function adjustment(int $idVendor, float $amount, string $note, int $idAdmin): array
	{
		$amount = self::money($amount);
		$note = trim(strip_tags($note));

		if ($amount == 0.0 || mb_strlen($note) < 5) {
			return ['success' => false, 'message' => 'Tutar ve en az 5 karakterlik açıklama gerekli.'];
		}

		if (!RvVendor::get($idVendor)) {
			return ['success' => false, 'message' => 'Satıcı bulunamadı.'];
		}

		$ref = 'adj:' . $idVendor . ':' . bin2hex(random_bytes(6));
		$id = self::add($idVendor, null, 'adjustment', $amount, date('Y-m-d H:i:s'), $ref, $note, $idAdmin);

		if (!$id) {
			return ['success' => false, 'message' => 'Kaydedilemedi.'];
		}

		RvAudit::log($idVendor, 'finance.adjustment', 'ledger', (int) $id, ['amount' => $amount], $idAdmin);

		return ['success' => true, 'message' => 'Düzeltme kaydedildi.'];
	}

	/** @return list<array<string,mixed>> */
	public static function payouts(int $idVendor = 0, int $limit = 50): array
	{
		$sql = 'SELECT p.*, v.store_name FROM rafimda_payouts p INNER JOIN rafimda_vendors v ON v.id_vendor = p.id_vendor';
		$params = [];

		if ($idVendor > 0) {
			$sql .= ' WHERE p.id_vendor = ?';
			$params[] = $idVendor;
		}

		return DB::execute($sql . ' ORDER BY p.id_payout DESC LIMIT ' . max(1, min(200, $limit)), $params) ?: [];
	}
}
