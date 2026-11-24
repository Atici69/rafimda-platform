<?php

/**
 * Sahiplik köprüsü. Pazaryeri Hub bağımsızdır; satıcı kavramı yalnızca rafimda-vendor kuruluysa kullanılır.
 * id_vendor = 0 → platform (yönetici) bağlantısı; > 0 → o satıcıya ait.
 */
class MhOwner
{
	public static function vendorModuleAvailable(): bool
	{
		return class_exists('RvContext', false) && class_exists('RvProducts', false);
	}

	/** Oturumdaki aktif satıcı ID'si; satıcı değilse 0. */
	public static function currentVendorId(): int
	{
		if (!self::vendorModuleAvailable() || !RvContext::isActiveVendor()) {
			return 0;
		}

		return RvContext::vendorId();
	}

	public static function can(string $perm): bool
	{
		return self::vendorModuleAvailable() && RvContext::can($perm);
	}

	/** Ürünün bu sahibe ait olup olmadığı (sunucu tarafı IDOR kapısı). */
	public static function owns(int $idVendor, int $idProduct): bool
	{
		if ($idProduct <= 0) {
			return false;
		}

		if ($idVendor > 0) {
			return self::vendorModuleAvailable() && RvProducts::owns($idVendor, $idProduct);
		}

		// platform: yalnızca hiçbir satıcıya ait OLMAYAN ürünler
		if (!self::vendorTableExists()) {
			return (bool) DB::getRowSafe('products', 'id_product = ?', [$idProduct]);
		}

		return (bool) DB::getRowSafe('products', 'id_product = ?', [$idProduct]) && !DB::getRowSafe('rafimda_vendor_products', 'id_product = ?', [$idProduct]);
	}

	public static function vendorTableExists(): bool
	{
		static $v = null;

		if ($v === null || defined('MH_TEST')) {
			$v = DB::execute('SELECT 1 FROM rafimda_vendor_products LIMIT 1') !== false;
		}

		return $v;
	}
}
