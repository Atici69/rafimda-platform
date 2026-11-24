<?php

/** Satıcı tercihleri: düşük stok eşiği ve varsayılan kargo firması. Tablo yoksa/hata olursa varsayılanlar döner. */
class RvPrefs
{
	public const DEFAULT_LOW_STOCK = 5;

	/** @return array{low_stock:int,default_cargo:string} */
	public static function get(int $idVendor): array
	{
		$d = ['low_stock' => self::DEFAULT_LOW_STOCK, 'default_cargo' => ''];

		try {
			$r = $idVendor > 0 ? DB::getRowSafe('rafimda_vendor_prefs', 'id_vendor = ?', [$idVendor]) : null;

			if ($r) {
				$d = ['low_stock' => max(0, min(1000, (int) $r['low_stock'])), 'default_cargo' => (string) $r['default_cargo']];
			}
		} catch (Throwable $e) {
			error_log('RvPrefs::get: ' . $e->getMessage());
		}

		return $d;
	}

	public static function lowStock(int $idVendor): int
	{
		return self::get($idVendor)['low_stock'];
	}

	/** @param array<string,mixed> $in @return array{success:bool,message:string} */
	public static function save(int $idVendor, array $in): array
	{
		$low = trim((string) ($in['low_stock'] ?? ''));

		if ($low === '' || !ctype_digit($low) || (int) $low > 1000) {
			return ['success' => false, 'message' => 'Düşük stok eşiği 0-1000 arasında tam sayı olmalı.'];
		}

		$cargo = mb_substr(trim(strip_tags((string) ($in['default_cargo'] ?? ''))), 0, 60);
		$row = ['low_stock' => (int) $low, 'default_cargo' => $cargo, 'date_upd' => date('Y-m-d H:i:s')];

		try {
			$ok = DB::getRowSafe('rafimda_vendor_prefs', 'id_vendor = ?', [$idVendor])
				? DB::update('rafimda_vendor_prefs', $row, 'id_vendor = :v', ['v' => $idVendor]) !== false
				: (bool) DB::insert('rafimda_vendor_prefs', ['id_vendor' => $idVendor] + $row);
		} catch (Throwable $e) {
			error_log('RvPrefs::save: ' . $e->getMessage());
			$ok = false;
		}

		if ($ok) {
			RvAudit::log($idVendor, 'prefs.save', 'vendor', $idVendor, ['low_stock' => (int) $low]);
		}

		return $ok ? ['success' => true, 'message' => 'Ayarlar kaydedildi.'] : ['success' => false, 'message' => 'Kaydedilemedi (veritabanı güncellemesi uygulanmamış olabilir).'];
	}
}
